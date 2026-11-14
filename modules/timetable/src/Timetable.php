<?php

namespace Saan\Modules\Timetable;

use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use App\Support\Saan\VersionConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * M07 timetable rules (fixes OPS-002, OPS-005, OPS-007, OPS-011).
 * - every request names its term; an existing lesson keeps its own term_id (never moved into the current term)
 * - closed (past) terms are admin-only and need confirm=true, and the override is audited
 * - collisions (teacher / classroom / room) are found in code first and answered 409 with the clashing lesson named;
 *   unique violations that still slip through (concurrent writers) are converted to 409 too
 * - delete is a soft delete with an expected version; undo is restore. Because the legacy unique indexes on lessons stay,
 *   a soft-deleted row is purged (audited, with a snapshot) when another lesson takes its slot
 * - room identity is lessons.room_key (all whitespace removed + lower case), never database collation
 */
class Timetable
{
    public const DAYS = [1 => 'จันทร์', 2 => 'อังคาร', 3 => 'พุธ', 4 => 'พฤหัสบดี', 5 => 'ศุกร์', 6 => 'เสาร์', 7 => 'อาทิตย์'];
    /** Stored in the NOT NULL, uniquely indexed legacy `room` column when a lesson has no room. */
    public const NO_ROOM = '~none~';
    private const WRITE_ROLES = ['admin', 'principal'];

    public static function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        // identity key: ALL whitespace removed ('ห้อง 101' and 'ห้อง101' are one room), like Academic::key
        return mb_substr(mb_strtolower((string) preg_replace('/\s+/u', '', $v)), 0, 120);
    }

    private function school(): int { return (int) config('school.school_id'); }
    private function user(): object { return auth()->user() ?? abort(401); }
    public function canWrite(): bool { return in_array($this->user()->role, self::WRITE_ROLES, true); }
    private function isAdmin(): bool { return $this->user()->role === 'admin'; }

    private function fail(string $message, int $status = 409, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $message] + $extra, $status));
    }

    // ---------------------------------------------------------------- reference data
    public function term(int $id): object
    {
        return DB::table('academic_terms')->where('school_id', $this->school())->where('id', $id)->first() ?? abort(404, 'ไม่พบภาคเรียน');
    }

    public function isClosed(object $term): bool { return (string) $term->ends_on < now()->toDateString(); }

    private function ref(string $table, int $id, string $label, bool $activeRequired = true): object
    {
        $row = DB::table($table)->where('school_id', $this->school())->where('id', $id)->first();
        if (!$row) throw ValidationException::withMessages([$label => ['ไม่พบข้อมูลที่เลือก']]);
        if ($activeRequired && isset($row->is_active) && !$row->is_active) throw ValidationException::withMessages([$label => ['รายการนี้ถูกปิดใช้งานแล้ว']]);
        return $row;
    }

    private function periodRow(int $id): object
    {
        $p = $this->ref('bell_periods', $id, 'period_id');
        if (isset($p->kind) && $p->kind === 'break') throw ValidationException::withMessages(['period_id' => ['ช่วงพักไม่ใช้จัดคาบสอน']]);
        return $p;
    }

    public function meta(?int $termId): array
    {
        if ($termId !== null) $this->term($termId); // 404 for a term of another school
        $school = $this->school();
        $user = $this->user();
        $current = TermScope::currentId();
        $terms = DB::table('academic_terms')->where('school_id', $school)->orderByDesc('year')->orderByDesc('term')->get()
            ->map(fn ($t) => ['id' => (int) $t->id, 'year' => (int) $t->year, 'term' => (int) $t->term, 'label' => $t->label ?? null, 'starts_on' => $t->starts_on, 'ends_on' => $t->ends_on,
                'is_current' => (int) $t->id === $current, 'closed' => $this->isClosed($t)])->all();
        $termId ??= $current ?? ($terms[0]['id'] ?? null);
        $periods = DB::table('bell_periods')->where('school_id', $school)->orderBy('starts_at')->orderBy('number')->get()
            ->map(fn ($p) => ['id' => (int) $p->id, 'number' => (int) $p->number, 'starts_at' => $p->starts_at, 'ends_at' => $p->ends_at, 'kind' => $p->kind ?? 'class'])->all();
        $scope = $termId ? TeacherScope::classroomIds($user, $termId) : [];
        $classrooms = DB::table('classrooms')->where('school_id', $school)->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))
            ->when(Schema::hasColumn('classrooms', 'is_active'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all();
        $teachers = DB::table('teachers')->where('school_id', $school)
            ->when(!$this->canWrite(), fn ($q) => $q->where('id', (int) ($user->teacher_id ?: 0)))
            ->when(Schema::hasColumn('teachers', 'is_active'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get(['id', 'name'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->all();
        $rooms = [];
        if ($termId && $this->canWrite()) {
            $seen = [];
            foreach (DB::table('lessons')->where('school_id', $school)->where('term_id', $termId)->whereNull('deleted_at')->orderBy('id')->get(['room', 'room_key']) as $l) {
                $name = $this->roomName($l);
                if ($name !== '' && !isset($seen[$this->roomKeyOf($l)])) { $seen[$this->roomKeyOf($l)] = true; $rooms[] = $name; }
            }
            sort($rooms, SORT_NATURAL | SORT_FLAG_CASE);
        }
        return ['role' => $user->role, 'can_write' => $this->canWrite(), 'is_admin' => $this->isAdmin(), 'term_id' => $termId, 'my_teacher_id' => $user->teacher_id ? (int) $user->teacher_id : null,
            'days' => self::DAYS, 'terms' => $terms, 'periods' => $periods, 'classrooms' => $classrooms, 'teachers' => $teachers, 'rooms' => $rooms];
    }

    // ---------------------------------------------------------------- room helpers (legacy rows may have room_key NULL)
    /** Room name to show for a lessons row; never the internal placeholder. Other modules must use this, not lessons.room. */
    public static function roomLabel(object $lesson): string
    {
        if (($lesson->room_key ?? null) === null && str_starts_with((string) ($lesson->room ?? ''), self::NO_ROOM)) return '';
        return (string) ($lesson->room ?? '');
    }

    /** Restrict a lessons query to live (not soft-deleted) rows. Other modules must use this when reading lessons. */
    public static function liveLessons($query, string $alias = '')
    {
        return $query->whereNull(($alias === '' ? '' : $alias.'.').'deleted_at');
    }

    private function roomName(object $l): string
    {
        return self::roomLabel($l);
    }

    private function roomKeyOf(object $l): ?string
    {
        if (($l->room_key ?? null) !== null) return $l->room_key;
        $name = $this->roomName($l);
        return $name === '' ? null : (self::key($name) ?: null);
    }

    // ---------------------------------------------------------------- reads
    private function base(int $termId)
    {
        return DB::table('lessons as l')
            ->join('bell_periods as p', 'p.id', '=', 'l.period_id')
            ->join('teachers as t', 't.id', '=', 'l.teacher_id')
            ->join('classrooms as c', 'c.id', '=', 'l.classroom_id')
            ->where('l.school_id', $this->school())->where('l.term_id', $termId)
            ->select('l.id', 'l.term_id', 'l.weekday', 'l.period_id', 'p.number as period_number', 'p.starts_at', 'p.ends_at', 'l.teacher_id', 't.name as teacher_name',
                'l.classroom_id', 'c.name as classroom_name', 'l.room', 'l.room_key', 'l.subject', 'l.note', 'l.version', 'l.deleted_at')
            ->orderBy('l.weekday')->orderBy('p.number')->orderBy('l.id');
    }

    private function shape(object $r): array
    {
        return ['id' => (int) $r->id, 'term_id' => (int) $r->term_id, 'weekday' => (int) $r->weekday, 'period_id' => (int) $r->period_id, 'period_number' => (int) $r->period_number,
            'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at, 'teacher_id' => (int) $r->teacher_id, 'teacher_name' => $r->teacher_name,
            'classroom_id' => (int) $r->classroom_id, 'classroom_name' => $r->classroom_name, 'room' => $this->roomName($r), 'subject' => $r->subject,
            'note' => $r->note, 'version' => (int) $r->version, 'deleted_at' => $r->deleted_at];
    }

    /** Lessons of one view. Teacher view never looks at a classroom filter (OPS-007). */
    public function lessons(int $termId, string $view, ?int $id, ?string $room): array
    {
        $this->term($termId);
        $user = $this->user();
        if ($view === 'classroom') {
            abort_unless($id, 422, 'ระบุห้องเรียน');
            abort_unless(TeacherScope::canClassroom($id, $user, $termId), 403, 'ไม่มีสิทธิ์ดูตารางห้องนี้');
            $rows = $this->base($termId)->whereNull('l.deleted_at')->where('l.classroom_id', $id)->get();
        } elseif ($view === 'teacher') {
            abort_unless($id, 422, 'ระบุครู');
            DB::table('teachers')->where('school_id', $this->school())->where('id', $id)->exists() || abort(404, 'ไม่พบครู');
            abort_unless($this->canWrite() || (int) $user->teacher_id === $id, 403, 'ครูดูได้เฉพาะตารางของตนเอง');
            $rows = $this->base($termId)->whereNull('l.deleted_at')->where('l.teacher_id', $id)->get();
        } elseif ($view === 'room') {
            abort_unless($this->canWrite(), 403, 'ไม่มีสิทธิ์ดูตารางสถานที่');
            $key = self::key((string) $room);
            abort_if($key === '', 422, 'ระบุสถานที่');
            $rows = $this->base($termId)->whereNull('l.deleted_at')->get()->filter(fn ($r) => $this->roomKeyOf($r) === $key)->values();
        } else {
            abort(422, 'มุมมองไม่ถูกต้อง');
        }
        $lessons = $rows->map(fn ($r) => $this->shape($r))->all();
        return ['term_id' => $termId, 'view' => $view, 'lessons' => $lessons, 'periods_per_week' => count($lessons)];
    }

    /** Periods per week for every teacher. The number is the same count the teacher view returns. */
    public function load(int $termId): array
    {
        $this->term($termId);
        $user = $this->user();
        $counts = DB::table('lessons')->where('school_id', $this->school())->where('term_id', $termId)->whereNull('deleted_at')
            ->groupBy('teacher_id')->selectRaw('teacher_id, count(*) as periods, count(distinct weekday) as days, count(distinct classroom_id) as classrooms')->get()->keyBy('teacher_id');
        $teachers = DB::table('teachers')->where('school_id', $this->school())
            ->when(!$this->canWrite(), fn ($q) => $q->where('id', (int) ($user->teacher_id ?: 0)))->orderBy('name')->get(['id', 'name']);
        $inactive = Schema::hasColumn('teachers', 'is_active') ? DB::table('teachers')->where('school_id', $this->school())->where('is_active', false)->pluck('id')->all() : [];
        $rows = [];
        foreach ($teachers as $t) {
            $c = $counts->get($t->id);
            if (!$c && in_array($t->id, $inactive, true)) continue;
            $rows[] = ['teacher_id' => (int) $t->id, 'teacher_name' => $t->name, 'periods' => (int) ($c->periods ?? 0), 'days' => (int) ($c->days ?? 0), 'classrooms' => (int) ($c->classrooms ?? 0)];
        }
        return ['term_id' => $termId, 'data' => $rows];
    }

    public function trash(int $termId): array
    {
        $this->term($termId);
        $rows = $this->base($termId)->whereNotNull('l.deleted_at')->get()->map(fn ($r) => $this->shape($r))->all();
        return ['term_id' => $termId, 'data' => $rows];
    }

    // ---------------------------------------------------------------- guards
    /** Closed term: admin only, with confirm=true. Returns audit detail describing the override. */
    private function gate(object $term, Request $r): array
    {
        abort_unless($this->canWrite(), 403, 'บัญชีนี้ดูตารางได้อย่างเดียว');
        if (!$this->isClosed($term)) return [];
        if (!$this->isAdmin()) $this->fail('ภาคเรียนนี้สิ้นสุดแล้ว แก้ไขได้เฉพาะผู้ดูแลระบบ', 403);
        if (!filter_var($r->input('confirm', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->fail('ภาคเรียน '.$term->year.'/'.$term->term.' สิ้นสุดแล้ว หากต้องการแก้ไขให้ยืนยันอีกครั้ง', 409, ['code' => 'term_closed', 'requires_confirm' => true]);
        }
        return ['closed_term_override' => true, 'term' => $term->year.'/'.$term->term];
    }

    private function describe(object $l): string
    {
        $day = self::DAYS[(int) $l->weekday] ?? (string) $l->weekday;
        return 'คาบ '.$l->period_number.' วัน'.$day;
    }

    /** @return array<int,array{type:string,message:string,lesson:array}> */
    private function clashes(iterable $rows, int $teacherId, int $classroomId, ?string $roomKey, ?int $exceptId): array
    {
        $found = [];
        foreach ($rows as $o) {
            if ($exceptId !== null && (int) $o->id === $exceptId) continue;
            $what = $this->describe($o).' ('.$o->classroom_name.' วิชา'.$o->subject.')';
            if ((int) $o->teacher_id === $teacherId) $found[] = ['type' => 'teacher', 'message' => $o->teacher_name.' มีสอน'.$what.' ในช่วงเวลานี้แล้ว', 'lesson' => $this->shape($o)];
            if ((int) $o->classroom_id === $classroomId) $found[] = ['type' => 'classroom', 'message' => 'ห้อง '.$o->classroom_name.' มีเรียน'.$what.' โดย'.$o->teacher_name.' ในช่วงเวลานี้แล้ว', 'lesson' => $this->shape($o)];
            if ($roomKey !== null && $this->roomKeyOf($o) === $roomKey) $found[] = ['type' => 'room', 'message' => 'สถานที่ "'.$this->roomName($o).'" ถูกใช้แล้ว'.$what.' โดย'.$o->teacher_name, 'lesson' => $this->shape($o)];
        }
        return $found;
    }

    private function slotRows(int $termId, int $weekday, int $periodId)
    {
        return $this->base($termId)->whereNull('l.deleted_at')->where('l.weekday', $weekday)->where('l.period_id', $periodId)->get();
    }

    private function assertFree(int $termId, int $weekday, int $periodId, int $teacherId, int $classroomId, ?string $roomKey, ?int $exceptId, bool $checkDuty = true): void
    {
        $found = $this->clashes($this->slotRows($termId, $weekday, $periodId), $teacherId, $classroomId, $roomKey, $exceptId);
        if ($found) $this->fail($found[0]['message'], 409, ['code' => 'collision', 'conflicts' => $found]);
        if ($checkDuty) $this->assertNoDuty($termId, $weekday, $periodId, $teacherId);
    }

    /** Read-only: an assigned duty of the same teacher overlapping the period on the same weekday inside the term (rule of the old prototype). */
    private function assertNoDuty(int $termId, int $weekday, int $periodId, int $teacherId): void
    {
        if (!Schema::hasTable('duties')) return;
        $term = $this->term($termId);
        $p = DB::table('bell_periods')->where('school_id', $this->school())->where('id', $periodId)->first();
        if (!$p) return;
        $duties = DB::table('duties')->where('school_id', $this->school())->where('teacher_id', $teacherId)->where('status', 'assigned')
            ->whereBetween('date', [$term->starts_on, $term->ends_on])->orderBy('date')->get();
        foreach ($duties as $d) {
            if (CarbonImmutable::parse($d->date)->isoWeekday() !== $weekday) continue;
            if (!((string) $p->starts_at < (string) $d->ends_at && (string) $d->starts_at < (string) $p->ends_at)) continue;
            $date = substr((string) $d->date, 0, 10);
            $msg = 'คาบสอนทับกับเวรที่มอบหมายแล้ว (วันที่ '.$date.' '.$d->starts_at.'-'.$d->ends_at.' '.$d->location.')';
            $this->fail($msg, 409, ['code' => 'duty_collision', 'conflicts' => [['type' => 'duty', 'message' => $msg, 'duty' => ['id' => (int) $d->id, 'date' => $date, 'location' => $d->location]]]]);
        }
    }

    /** Soft-deleted rows still hold the legacy unique indexes; drop the ones in the way (audited with a snapshot). */
    private function tombstoneQuery(int $termId, int $weekday, int $periodId, int $teacherId, int $classroomId, ?string $roomKey, ?string $room, ?int $exceptId)
    {
        $q = DB::table('lessons')->where('school_id', $this->school())->where('term_id', $termId)->where('weekday', $weekday)->where('period_id', $periodId)->whereNotNull('deleted_at')
            ->where(function ($w) use ($teacherId, $classroomId, $roomKey, $room) {
                $w->where('teacher_id', $teacherId)->orWhere('classroom_id', $classroomId);
                if ($roomKey !== null) $w->orWhere('room_key', $roomKey)->orWhere('room', $room);
            });
        if ($exceptId !== null) $q->where('id', '!=', $exceptId);
        return $q;
    }

    /** @return int number of tombstones removed */
    private function purgeTombstones(int $termId, int $weekday, int $periodId, int $teacherId, int $classroomId, ?string $roomKey, ?string $room, ?int $exceptId): int
    {
        $n = 0;
        foreach ($this->tombstoneQuery($termId, $weekday, $periodId, $teacherId, $classroomId, $roomKey, $room, $exceptId)->get() as $t) {
            DB::table('lessons')->where('id', $t->id)->delete();
            Mutation::audit('timetable.lesson.purged', 'lessons', (int) $t->id, ['reason' => 'slot reused', 'snapshot' => $this->snapshot($t)]);
            $n++;
        }
        return $n;
    }

    private function snapshot(object $l): array
    {
        return ['term_id' => (int) $l->term_id, 'weekday' => (int) $l->weekday, 'period_id' => (int) $l->period_id, 'teacher_id' => (int) $l->teacher_id,
            'classroom_id' => (int) $l->classroom_id, 'room' => $this->roomName($l), 'subject' => $l->subject];
    }

    public static function isUnique(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driver = (int) ($e->errorInfo[1] ?? 0);
        $msg = strtolower($e->getMessage());
        return $state === '23505' || ($state === '23000' && ($driver === 1062 || str_contains($msg, 'unique'))) || ($driver === 19 && str_contains($msg, 'unique')) || str_contains($msg, 'duplicate entry');
    }

    /** Run a write; a concurrent unique violation becomes 409 (named clash when we can find it), never 500. */
    private function guarded(callable $fn, array $slot)
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            if (!self::isUnique($e)) throw $e;
            $rows = $this->slotRows(...array_slice($slot, 0, 3));
            $found = $this->clashes($rows, $slot[3], $slot[4], $slot[5], $slot[6] ?? null);
            // the legacy raw `room` index follows the database collation: let the database name the clash PHP could not see
            $raw = $slot[7] ?? null;
            if (!$found && $raw !== null && $raw !== '') {
                $byDb = $this->base((int) $slot[0])->whereNull('l.deleted_at')->where('l.weekday', $slot[1])->where('l.period_id', $slot[2])->where('l.room', $raw)
                    ->when($slot[6] ?? null, fn ($q, $x) => $q->where('l.id', '!=', $x))->get();
                foreach ($byDb as $o) {
                    $found[] = ['type' => 'room', 'message' => 'สถานที่ "'.$this->roomName($o).'" ถูกใช้แล้ว'.$this->describe($o).' ('.$o->classroom_name.' วิชา'.$o->subject.') โดย'.$o->teacher_name, 'lesson' => $this->shape($o)];
                }
            }
            if ($found) $this->fail($found[0]['message'], 409, ['code' => 'collision', 'conflicts' => $found]);
            $this->fail('มีรายการชนกันจากการบันทึกพร้อมกัน กรุณาโหลดตารางใหม่', 409, ['code' => 'collision', 'conflicts' => []]);
        }
    }

    private function normaliseRoom(?string $room): array
    {
        $room = trim((string) preg_replace('/\s+/u', ' ', (string) $room));
        if ($room === '') return [self::NO_ROOM.bin2hex(random_bytes(8)), null];
        return [$room, self::key($room)];
    }

    private function live(int $id): object
    {
        $row = DB::table('lessons')->where('school_id', $this->school())->where('id', $id)->first() ?? abort(404, 'ไม่พบคาบเรียน');
        return $row;
    }

    private function returned(int $id): array
    {
        $r = $this->base((int) DB::table('lessons')->where('id', $id)->value('term_id'))->where('l.id', $id)->first();
        return $this->shape($r);
    }

    // ---------------------------------------------------------------- writes
    public function create(array $d, Request $req): array
    {
        $term = $this->term((int) $d['term_id']);
        $detail = $this->gate($term, $req);
        $this->ref('teachers', (int) $d['teacher_id'], 'teacher_id');
        $this->ref('classrooms', (int) $d['classroom_id'], 'classroom_id');
        $this->periodRow((int) $d['period_id']);
        [$room, $roomKey] = $this->normaliseRoom($d['room'] ?? null);
        $slot = [(int) $term->id, (int) $d['weekday'], (int) $d['period_id'], (int) $d['teacher_id'], (int) $d['classroom_id'], $roomKey, null, $roomKey === null ? null : $room];
        $row = $this->guarded(fn () => DB::transaction(function () use ($d, $term, $slot, $room, $roomKey, $detail) {
            $this->assertFree(...array_slice($slot, 0, 7));
            $this->purgeTombstones($slot[0], $slot[1], $slot[2], $slot[3], $slot[4], $roomKey, $room, null);
            return Mutation::insert('lessons', [
                'term_id' => (int) $term->id, 'teacher_id' => $slot[3], 'classroom_id' => $slot[4], 'period_id' => $slot[2], 'weekday' => $slot[1],
                'room' => $room, 'room_key' => $roomKey, 'subject' => $d['subject'], 'note' => $d['note'] ?? null, 'updated_by' => auth()->id(),
            ], 'timetable.lesson.created', $detail + ['term_id' => (int) $term->id, 'weekday' => $slot[1], 'period_id' => $slot[2], 'teacher_id' => $slot[3], 'classroom_id' => $slot[4]]);
        }), $slot);
        return $this->returned((int) $row->id);
    }

    public function update(int $id, array $d, Request $req): array
    {
        $old = $this->live($id);
        if ($old->deleted_at !== null) $this->fail('คาบนี้ถูกลบแล้ว กรุณาเรียกคืนก่อนแก้ไข', 409);
        // OPS-002: the lesson stays in its own term. A request that names another term is refused, never silently moved.
        if ((int) $d['term_id'] !== (int) $old->term_id) {
            throw ValidationException::withMessages(['term_id' => ['คาบนี้อยู่ในภาคเรียนอื่น ย้ายข้ามภาคเรียนไม่ได้ ใช้การคัดลอกตารางแทน']]);
        }
        $term = $this->term((int) $old->term_id);
        $detail = $this->gate($term, $req);
        if ((int) $old->version !== (int) $d['version']) throw new VersionConflict((array) $old);
        // a closed past term keeps teachers who have left: only a changed teacher / classroom has to be active
        $this->ref('teachers', (int) $d['teacher_id'], 'teacher_id', (int) $d['teacher_id'] !== (int) $old->teacher_id);
        $this->ref('classrooms', (int) $d['classroom_id'], 'classroom_id', (int) $d['classroom_id'] !== (int) $old->classroom_id);
        $this->periodRow((int) $d['period_id']);
        [$room, $roomKey] = $this->normaliseRoom($d['room'] ?? null);
        $slot = [(int) $old->term_id, (int) $d['weekday'], (int) $d['period_id'], (int) $d['teacher_id'], (int) $d['classroom_id'], $roomKey, $id, $roomKey === null ? null : $room];
        $slotChanged = (int) $old->weekday !== $slot[1] || (int) $old->period_id !== $slot[2] || (int) $old->teacher_id !== $slot[3];
        $this->guarded(fn () => DB::transaction(function () use ($id, $d, $old, $slot, $room, $roomKey, $detail, $slotChanged) {
            $this->assertFree(...array_slice($slot, 0, 7), checkDuty: $slotChanged);
            $this->purgeTombstones($slot[0], $slot[1], $slot[2], $slot[3], $slot[4], $roomKey, $room, $id);
            Mutation::update('lessons', $id, (int) $d['version'], [
                'teacher_id' => $slot[3], 'classroom_id' => $slot[4], 'period_id' => $slot[2], 'weekday' => $slot[1], 'room' => $room, 'room_key' => $roomKey,
                'subject' => $d['subject'], 'note' => $d['note'] ?? null, 'updated_by' => auth()->id(),
            ], 'timetable.lesson.updated', $detail + ['term_id' => (int) $old->term_id, 'before' => $this->snapshot($old)]);
        }), $slot);
        return $this->returned($id);
    }

    private function sameTerm(int $termId, object $old): void
    {
        if ($termId !== (int) $old->term_id) throw ValidationException::withMessages(['term_id' => ['คาบนี้อยู่ในภาคเรียนอื่น']]);
    }

    public function delete(int $id, int $termId, int $version, Request $req): array
    {
        $old = $this->live($id);
        $this->sameTerm($termId, $old);
        $term = $this->term((int) $old->term_id);
        $detail = $this->gate($term, $req);
        if ($old->deleted_at !== null) $this->fail('คาบนี้ถูกลบไปแล้ว', 409);
        if ((int) $old->version !== $version) throw new VersionConflict((array) $old);
        $row = Mutation::update('lessons', $id, $version, ['deleted_at' => now()->toDateTimeString(), 'updated_by' => auth()->id()], 'timetable.lesson.deleted',
            $detail + ['term_id' => (int) $old->term_id, 'snapshot' => $this->snapshot($old)]);
        return ['ok' => true, 'id' => $id, 'version' => (int) $row->version, 'undo' => true];
    }

    public function restore(int $id, int $termId, int $version, Request $req): array
    {
        $old = $this->live($id);
        $this->sameTerm($termId, $old);
        $term = $this->term((int) $old->term_id);
        $detail = $this->gate($term, $req);
        if ($old->deleted_at === null) $this->fail('คาบนี้ไม่ได้ถูกลบ', 409);
        if ((int) $old->version !== $version) throw new VersionConflict((array) $old);
        $roomKey = $this->roomKeyOf($old);
        $slot = [(int) $old->term_id, (int) $old->weekday, (int) $old->period_id, (int) $old->teacher_id, (int) $old->classroom_id, $roomKey, $id, $roomKey === null ? null : $this->roomName($old)];
        $this->guarded(fn () => DB::transaction(function () use ($id, $version, $slot, $old, $roomKey, $detail) {
            $this->assertFree(...array_slice($slot, 0, 7));
            $this->purgeTombstones($slot[0], $slot[1], $slot[2], $slot[3], $slot[4], $roomKey, (string) $old->room, $id);
            Mutation::update('lessons', $id, $version, ['deleted_at' => null, 'updated_by' => auth()->id()], 'timetable.lesson.restored', $detail + ['term_id' => (int) $old->term_id]);
        }), $slot);
        return $this->returned($id);
    }

    // ---------------------------------------------------------------- copy from a previous term
    /** Pure planning: nothing is written. */
    private function plan(int $fromId, int $toId, ?array $classroomIds): array
    {
        $school = $this->school();
        $source = $this->base($fromId)->whereNull('l.deleted_at')->when($classroomIds, fn ($q) => $q->whereIn('l.classroom_id', $classroomIds))->get();
        $target = $this->base($toId)->whereNull('l.deleted_at')->get()->groupBy(fn ($r) => $r->weekday.'|'.$r->period_id)->map(fn ($g) => $g->all())->all();
        $inactiveTeachers = Schema::hasColumn('teachers', 'is_active') ? DB::table('teachers')->where('school_id', $school)->where('is_active', false)->pluck('id')->all() : [];
        $inactiveRooms = Schema::hasColumn('classrooms', 'is_active') ? DB::table('classrooms')->where('school_id', $school)->where('is_active', false)->pluck('id')->all() : [];
        $copy = []; $collisions = []; $duplicates = 0; $inactive = 0;
        foreach ($source as $s) {
            if (in_array((int) $s->teacher_id, $inactiveTeachers, true) || in_array((int) $s->classroom_id, $inactiveRooms, true)) { $inactive++; continue; }
            $slotKey = $s->weekday.'|'.$s->period_id;
                        $here = $target[$slotKey] ?? [];
            if (collect($here)->contains(fn ($o) => (int) $o->classroom_id === (int) $s->classroom_id && (int) $o->teacher_id === (int) $s->teacher_id && $o->subject === $s->subject)) { $duplicates++; continue; }
            $found = $this->clashes($here, (int) $s->teacher_id, (int) $s->classroom_id, $this->roomKeyOf($s), null);
            if ($found) {
                $collisions[] = ['lesson' => $this->shape($s), 'messages' => array_column($found, 'message'), 'conflicts' => $found];
                continue;
            }
$copy[] = $s;
            $o = clone $s;
            $target[$slotKey][] = $o; // later source rows are checked against what this run adds
        }
        $by = [];
        foreach ($copy as $c) $by[$c->classroom_name] = ($by[$c->classroom_name] ?? 0) + 1;
        // soft-deleted rows of the target that still hold a slot are removed on commit (their undo is lost)
        $tomb = [];
        foreach ($copy as $c) {
            foreach ($this->tombstoneQuery($toId, (int) $c->weekday, (int) $c->period_id, (int) $c->teacher_id, (int) $c->classroom_id, $this->roomKeyOf($c), $this->roomName($c) ?: null, null)->pluck('id') as $tid) $tomb[(int) $tid] = true;
        }
        return ['copy' => $copy, 'collisions' => $collisions, 'duplicates' => $duplicates, 'inactive' => $inactive, 'total' => count($source), 'by_classroom' => $by, 'tombstones' => count($tomb)];
    }

    private function copyScope(array $d): array
    {
        abort_if((int) $d['from_term_id'] === (int) $d['to_term_id'], 422, 'ภาคเรียนต้นทางและปลายทางต้องต่างกัน');
        $from = $this->term((int) $d['from_term_id']);
        $to = $this->term((int) $d['to_term_id']);
        $ids = !empty($d['classroom_ids']) ? array_values(array_unique(array_map('intval', $d['classroom_ids']))) : null;
        if ($ids) abort_unless(DB::table('classrooms')->where('school_id', $this->school())->whereIn('id', $ids)->count() === count($ids), 422, 'ห้องเรียนไม่ถูกต้อง');
        return [$from, $to, $ids];
    }

    private function report(array $plan, object $from, object $to): array
    {
        return ['from_term_id' => (int) $from->id, 'to_term_id' => (int) $to->id, 'total' => $plan['total'], 'copyable' => count($plan['copy']), 'duplicates' => $plan['duplicates'],
            'inactive_skipped' => $plan['inactive'], 'collision_count' => count($plan['collisions']), 'collisions' => $plan['collisions'], 'by_classroom' => $plan['by_classroom'],
            'tombstones_purged' => $plan['tombstones'], 'target_closed' => $this->isClosed($to)];
    }

    public function copyPreview(array $d): array
    {
        abort_unless($this->canWrite(), 403, 'บัญชีนี้ดูตารางได้อย่างเดียว');
        [$from, $to, $ids] = $this->copyScope($d);
        return $this->report($this->plan((int) $from->id, (int) $to->id, $ids), $from, $to);
    }

    public function copyCommit(array $d, Request $req): array
    {
        [$from, $to, $ids] = $this->copyScope($d);
        $detail = $this->gate($to, $req);
        $skip = filter_var($d['skip_collisions'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $result = DB::transaction(function () use ($from, $to, $ids, $skip, $detail) {
            $plan = $this->plan((int) $from->id, (int) $to->id, $ids);
            if ($plan['collisions'] && !$skip) {
                $this->fail('พบคาบชนกัน '.count($plan['collisions']).' รายการ ตรวจรายงานก่อน แล้วยืนยันข้ามรายการที่ชน', 409, ['code' => 'collisions', 'report' => $this->report($plan, $from, $to)]);
            }
            $now = now(); $lessonIds = []; $purged = 0;
            foreach ($plan['copy'] as $s) {
                [$room, $key] = $this->roomName($s) === '' ? $this->normaliseRoom(null) : [$this->roomName($s), $this->roomKeyOf($s)];
                $slot = [(int) $to->id, (int) $s->weekday, (int) $s->period_id, (int) $s->teacher_id, (int) $s->classroom_id, $key, null, $key === null ? null : $room];
                $purged += $this->purgeTombstones($slot[0], $slot[1], $slot[2], $slot[3], $slot[4], $key, $key === null ? null : $room, null);
                $row = ['school_id' => $this->school(), 'term_id' => (int) $to->id, 'teacher_id' => $slot[3], 'classroom_id' => $slot[4], 'period_id' => $slot[2],
                    'weekday' => $slot[1], 'room' => $room, 'room_key' => $key, 'subject' => $s->subject, 'note' => $s->note, 'updated_by' => auth()->id(), 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
                // nested transaction = savepoint, so a violation is recoverable and can be named
                $lessonIds[] = (int) $this->guarded(fn () => DB::transaction(fn () => DB::table('lessons')->insertGetId($row)), $slot);
            }
            Mutation::audit('timetable.copied', 'lessons', null, $detail + ['from_term_id' => (int) $from->id, 'to_term_id' => (int) $to->id, 'copied' => count($lessonIds),
                'skipped_collisions' => count($plan['collisions']), 'duplicates' => $plan['duplicates'], 'tombstones_purged' => $purged, 'classroom_ids' => $ids, 'lesson_ids' => $lessonIds]);
            return array_merge($this->report($plan, $from, $to), ['copied' => count($lessonIds), 'tombstones_purged' => $purged, 'lesson_ids' => $lessonIds]);
        });
        return $result;
    }

    // ---------------------------------------------------------------- print
    public function printData(string $type, int $id, ?int $termId): array
    {
        abort_unless(in_array($type, ['classroom', 'teacher'], true), 404);
        $termId ??= TermScope::currentId();
        abort_unless($termId, 404, 'ยังไม่ได้ตั้งภาคเรียน');
        $term = $this->term($termId);
        $data = $this->lessons($termId, $type, $id, null);
        $subject = $type === 'classroom'
            ? DB::table('classrooms')->where('school_id', $this->school())->where('id', $id)->value('name')
            : DB::table('teachers')->where('school_id', $this->school())->where('id', $id)->value('name');
        $periods = DB::table('bell_periods')->where('school_id', $this->school())->orderBy('starts_at')->orderBy('number')->get();
        $cells = [];
        $days = [1, 2, 3, 4, 5];
        foreach ($data['lessons'] as $l) {
            $cells[$l['weekday']][$l['period_id']][] = $l;
            if ($l['weekday'] > 5 && !in_array($l['weekday'], $days, true)) $days[] = $l['weekday'];
        }
        sort($days);
        return ['type' => $type, 'subjectName' => (string) $subject, 'term' => $term, 'periods' => $periods, 'days' => $days, 'dayNames' => self::DAYS, 'cells' => $cells,
            'count' => count($data['lessons']), 'school' => (string) (\App\Support\Saan\Settings::get('school.name') ?: config('saan.display', 'โรงเรียน')), 'printedOn' => now()->toDateString()];
    }
}
