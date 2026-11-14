<?php

namespace Saan\Modules\Attendance;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use App\Support\Saan\VersionConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class-day attendance. Teachers record exceptions only: a student without a row is "present" once the class day is confirmed.
 * Every write checks per-row versions (OPS-006) plus the class-day version, in one transaction: a stale save writes nothing.
 */
class Attendance
{
    public const STATUSES = ['present', 'absent', 'late', 'leave', 'sick'];
    public const LABELS = ['present' => 'มา', 'absent' => 'ขาด', 'late' => 'สาย', 'leave' => 'ลา', 'sick' => 'ป่วย'];
    public const EXCEPTIONS = ['absent', 'late', 'leave', 'sick'];

    public function school(): int
    {
        return (int) config('school.school_id');
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    public function windowDays(): int
    {
        return max(0, min(365, (int) Settings::get('attendance.edit_window_days', 3)));
    }

    // ---- scope -------------------------------------------------------------------------------------------------

    /** @param string[] $dates dates the caller will read or write; a teacher is scoped with the term each date belongs to, not only today's term */
    public function access(int $classroomId, array $dates = []): object
    {
        abort_unless(TeacherScope::canClassroom($classroomId), 403, 'ไม่มีสิทธิ์ดูหรือบันทึกห้องเรียนนี้');
        foreach (array_unique($dates) as $date) {
            $ids = TeacherScope::classroomIdsForTerm(null, $this->termFor($date));
            abort_unless($ids === null || in_array($classroomId, $ids, true), 403, 'ไม่มีสิทธิ์ดูหรือบันทึกห้องเรียนนี้ในภาคเรียนของวันที่เลือก');
        }
        return DB::table('classrooms')->where('school_id', $this->school())->where('id', $classroomId)->first();
    }

    public function classrooms(): array
    {
        $q = DB::table('classrooms')->where('school_id', $this->school());
        if (Schema::hasColumn('classrooms', 'is_active')) $q->where(fn ($w) => $w->where('is_active', true)->orWhereNull('is_active'));
        $ids = TeacherScope::classroomIds();
        if ($ids !== null) $q->whereIn('id', $ids ?: [0]);
        $home = TeacherScope::homeroomIds() ?? [];
        return $q->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'homeroom' => in_array((int) $c->id, $home, true)])->all();
    }

    public function students(int $classroomId): Collection
    {
        $q = DB::table('students')->where('school_id', $this->school())->where('classroom_id', $classroomId);
        if (Schema::hasColumn('students', 'status')) $q->where('status', 'studying');
        if (Schema::hasColumn('students', 'roll_number')) $q->orderByRaw('roll_number is null')->orderBy('roll_number');
        return $q->orderBy('code')->orderBy('id')->get()->map(fn ($r) => $this->student($r));
    }

    private function student(object $r): object
    {
        $full = trim(trim((string) ($r->prefix ?? '')).trim((string) ($r->first_name ?? '')).' '.trim((string) ($r->last_name ?? '')));
        return (object) ['id' => (int) $r->id, 'code' => (string) $r->code, 'name' => $full !== '' ? $full : (string) $r->name, 'roll' => $r->roll_number ?? null, 'classroom_id' => $r->classroom_id === null ? null : (int) $r->classroom_id];
    }

    // ---- day rules ---------------------------------------------------------------------------------------------

    /** @return array{kind:string,title:string}|null */
    public function blocked(string $date): ?array
    {
        if (CarbonImmutable::parse($date)->isWeekend()) return ['kind' => 'weekend', 'title' => 'วันเสาร์-อาทิตย์'];
        if (Schema::hasTable('acad_calendar_days')) {
            $row = DB::table('acad_calendar_days')->where('school_id', $this->school())->where('date', $date)->whereIn('kind', ['holiday', 'no_class'])->orderBy('id')->first();
            if ($row) return ['kind' => $row->kind, 'title' => (string) $row->title];
        }
        return null;
    }

    public function daysAgo(string $date): int
    {
        return max(0, (int) CarbonImmutable::parse($date)->startOfDay()->diffInDays($this->today(), false));
    }

    public function needsReason(string $date): bool
    {
        return $this->daysAgo($date) > $this->windowDays();
    }

    private function names(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values()->all();
        return $ids ? DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->all() : [];
    }

    // ---- sheet -------------------------------------------------------------------------------------------------

    public function dayRow(int $classroomId, string $date): ?object
    {
        return DB::table('att_class_days')->where('school_id', $this->school())->where('classroom_id', $classroomId)->where('date', $date)->first();
    }

    private function dayPayload(?object $day): array
    {
        $by = $day?->confirmed_by ? ($this->names([$day->confirmed_by])[$day->confirmed_by] ?? null) : null;
        return ['version' => $day ? (int) $day->version : 0, 'confirmed' => (bool) $day?->confirmed_at, 'confirmed_by' => $by, 'confirmed_at' => $day?->confirmed_at];
    }

    private function rowsFor(array $studentIds, string $date): Collection
    {
        return $studentIds ? DB::table('attendances')->where('school_id', $this->school())->where('date', $date)->whereIn('student_id', $studentIds)->get()->keyBy('student_id') : collect();
    }

    public function sheet(int $classroomId, string $date): array
    {
        $room = $this->access($classroomId, [$date]);
        $students = $this->students($classroomId);
        $rows = $this->rowsFor($students->pluck('id')->all(), $date);
        $names = $this->names($rows->map(fn ($r) => $r->updated_by ?? $r->recorded_by));
        $blocked = $this->blocked($date);
        return [
            'classroom' => ['id' => (int) $room->id, 'name' => $room->name],
            'date' => $date,
            'students' => $students->map(function ($s) use ($rows, $names) {
                $r = $rows->get($s->id);
                $by = $r ? ($r->updated_by ?? $r->recorded_by) : null;
                return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'roll' => $s->roll,
                    'status' => $r ? $r->status : 'present', 'recorded' => (bool) $r, 'version' => $r ? (int) $r->version : 0, 'note' => $r->note ?? null,
                    'changed_by' => $by ? ($names[$by] ?? null) : null, 'changed_at' => $r ? (string) $r->updated_at : null];
            })->values()->all(),
            'class_day' => $this->dayPayload($this->dayRow($classroomId, $date)),
            'blocked' => $blocked, 'can_force' => auth()->user()?->role === 'admin',
            'needs_reason' => $this->needsReason($date), 'edit_window_days' => $this->windowDays(),
            'is_future' => CarbonImmutable::parse($date)->greaterThan($this->today()),
        ];
    }

    // ---- save --------------------------------------------------------------------------------------------------

    /** @param array<int,array{student_id:int,status:string,version:int,note?:?string}> $rows */
    public function save(int $classroomId, string $date, int $dayVersion, array $rows, bool $confirm, ?string $reason, bool $force): array
    {
        $user = auth()->user();
        $this->access($classroomId, [$date]);
        abort_if(CarbonImmutable::parse($date)->greaterThan($this->today()), 422, 'บันทึกวันที่ยังมาไม่ถึงไม่ได้');
        // a teacher never writes into ANOTHER term; closing a past year is an admin job (admin keeps the audited reason path). A day outside every term
        // (term dates not entered exactly, classes held after the last day) is not another term: it stays writable as before, with term_id null.
        $dateTerm = $this->termFor($date);
        abort_if($user->role === 'teacher' && $dateTerm !== null && $dateTerm !== TermScope::currentId(), 403, 'ครูบันทึกได้เฉพาะวันที่อยู่ในภาคเรียนปัจจุบัน');
        $blocked = $this->blocked($date);
        if ($blocked && !($force && $user->role === 'admin')) abort(422, 'วันที่เลือกเป็น'.$blocked['title'].' ไม่เปิดให้เช็คชื่อ (ผู้ดูแลระบบบันทึกแบบบังคับได้)');
        $valid = $this->students($classroomId)->keyBy('id');
        $mine = [];
        foreach ($rows as $r) {
            abort_unless($valid->has((int) $r['student_id']), 422, 'มีนักเรียนที่ไม่ได้อยู่ในห้องนี้หรือไม่ได้อยู่ในสถานะกำลังศึกษา');
            abort_if(isset($mine[(int) $r['student_id']]), 422, 'มีนักเรียนซ้ำในรายการ');
            $mine[(int) $r['student_id']] = $r;
        }
        $reason = trim((string) $reason);
        $needsReason = $this->needsReason($date);
        $termId = $this->termFor($date);
        $forced = (bool) $blocked;
        $stats = ['created' => 0, 'updated' => 0, 'corrections' => 0, 'confirmed' => false];

        try {
            DB::transaction(function () use ($classroomId, $date, $dayVersion, $mine, $confirm, $reason, $needsReason, $termId, $user, $forced, $valid, &$stats) {
                $existing = $this->rowsFor(array_keys($mine), $date);
                $day = $this->dayRow($classroomId, $date);
                $conflicts = $this->conflicts($mine, $existing);
                $dayStale = ($day ? (int) $day->version : 0) !== $dayVersion;
                $implicitPresent = (bool) ($day && $day->confirmed_at);
                if ($conflicts || $dayStale) throw new SheetConflict($this->conflictPayload($classroomId, $date, $conflicts, $day));

                $plan = [];
                foreach ($mine as $sid => $r) {
                    $cur = $existing->get($sid);
                    $note = array_key_exists('note', $r) ? $this->cleanNote($r['note']) : ($cur->note ?? null);
                    if ($cur) {
                        if ($cur->status === $r['status'] && ($cur->note ?? null) === $note) continue;
                        $plan[] = [$cur, $r['status'], $note];
                    } elseif ($r['status'] !== 'present' || $note !== null) {
                        $plan[] = [$sid, $r['status'], $note];
                    }
                }
                abort_if(($plan || $confirm) && $needsReason && $reason === '', 422, 'วันที่เลือกย้อนหลังเกิน '.$this->windowDays().' วัน ต้องระบุเหตุผล');

                foreach ($plan as [$cur, $status, $note]) {
                    if (is_object($cur)) {
                        Mutation::update('attendances', (int) $cur->id, (int) $cur->version, ['status' => $status, 'note' => $note, 'updated_by' => $user->id],
                            'attendance.updated', ['classroom_id' => $classroomId, 'date' => $date, 'student_id' => (int) $cur->student_id, 'old' => $cur->status, 'new' => $status]);
                        $stats['updated']++;
                        if ($cur->status !== $status) {
                            DB::table('att_corrections')->insert(['school_id' => $this->school(), 'attendance_id' => (int) $cur->id, 'student_id' => (int) $cur->student_id, 'date' => $date,
                                'old_status' => $cur->status, 'new_status' => $status, 'reason' => $reason === '' ? null : $reason, 'changed_by' => $user->id, 'created_at' => now()]);
                            $stats['corrections']++;
                        }
                    } else {
                        $new = Mutation::insert('attendances', ['student_id' => $cur, 'date' => $date, 'status' => $status, 'note' => $note, 'term_id' => $termId,
                            'recorded_by' => $user->id, 'updated_by' => $user->id] + (\Illuminate\Support\Facades\Schema::hasColumn('attendances', 'classroom_id') ? ['classroom_id' => $classroomId] : []), 'attendance.created',
                            ['classroom_id' => $classroomId, 'date' => $date, 'student_id' => $cur, 'status' => $status, 'reason' => $reason === '' ? null : $reason]);
                        $stats['created']++;
                        // a confirmed day counts a student without a row as present: moving them off present is a real correction
                        if ($implicitPresent && $status !== 'present') {
                            DB::table('att_corrections')->insert(['school_id' => $this->school(), 'attendance_id' => (int) $new->id, 'student_id' => (int) $cur, 'date' => $date,
                                'old_status' => 'present', 'new_status' => $status, 'reason' => $reason === '' ? null : $reason, 'changed_by' => $user->id, 'created_at' => now()]);
                            $stats['corrections']++;
                        }
                    }
                }
                if ($confirm) {
                    $at = now()->toDateTimeString();
                    $snap = \Illuminate\Support\Facades\Schema::hasColumn('att_class_days', 'student_count') ? ['student_count' => $valid->count()] : [];
                    if ($day) Mutation::update('att_class_days', (int) $day->id, (int) $day->version, ['confirmed_by' => $user->id, 'confirmed_at' => $at] + $snap, 'attendance.day_confirmed', ['classroom_id' => $classroomId, 'date' => $date]);
                    else Mutation::insert('att_class_days', ['classroom_id' => $classroomId, 'date' => $date, 'confirmed_by' => $user->id, 'confirmed_at' => $at] + $snap, 'attendance.day_confirmed', ['classroom_id' => $classroomId, 'date' => $date]);
                    $stats['confirmed'] = true;
                }
                if ($plan || $confirm) Mutation::audit('attendance.sheet_saved', 'classrooms', $classroomId, ['date' => $date, 'created' => $stats['created'], 'updated' => $stats['updated'], 'corrections' => $stats['corrections'], 'confirmed' => $confirm, 'forced' => $forced, 'reason' => $reason === '' ? null : $reason]);
            });
        } catch (VersionConflict|QueryException|DeadlockException $e) {
            // Laravel turns a deadlock inside a nested Mutation transaction into DeadlockException (a PDOException) for the outer retry loop
            if ($e instanceof QueryException && !$this->isUniqueViolation($e) && !$this->isConcurrency($e)) throw $e;
            // a concurrent save won the race between our check and our write: everything rolled back, report it like a stale save
            $existing = $this->rowsFor(array_keys($mine), $date);
            $day = $this->dayRow($classroomId, $date);
            $conflicts = $this->conflicts($mine, $existing);
            throw new SheetConflict($this->conflictPayload($classroomId, $date, $conflicts, $day));
        }
        return ['saved' => $stats, 'sheet' => $this->sheet($classroomId, $date)];
    }

    private function cleanNote(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, 200);
    }

    private function termFor(string $date): ?int
    {
        $row = DB::table('academic_terms')->where('school_id', $this->school())->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->orderByDesc('id')->first();
        return $row ? (int) $row->id : null; // outside every term (break day, before first term): term_id stays null
    }

    /** deadlock / serialization failure / lock wait timeout between concurrent saves: report as a conflict, never 500 */
    private function isConcurrency(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? '') === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 alone is shared by NOT NULL (1048), FK (1451/1452) and CHECK violations: only duplicate-key codes count
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $code = (int) ($e->errorInfo[1] ?? 0);
        if ($state === '23505' || in_array($code, [1062, 1586], true)) return true;
        return $code === 19 && stripos($e->getMessage(), 'UNIQUE') !== false;
    }

    /** @return array<int,array{student_id:int,mine:array}> students whose stored version differs from the version the client edited */
    private function conflicts(array $mine, Collection $existing): array
    {
        $out = [];
        foreach ($mine as $sid => $r) {
            $cur = $existing->get($sid);
            if (($cur ? (int) $cur->version : 0) !== (int) $r['version']) $out[$sid] = ['student_id' => $sid, 'mine' => ['status' => $r['status'], 'version' => (int) $r['version'], 'note' => $r['note'] ?? null]];
        }
        return $out;
    }

    private function conflictPayload(int $classroomId, string $date, array $conflicts, ?object $day): array
    {
        $students = $this->students($classroomId)->keyBy('id');
        $rows = $this->rowsFor(array_keys($conflicts), $date);
        $names = $this->names($rows->map(fn ($r) => $r->updated_by ?? $r->recorded_by));
        $list = [];
        foreach ($conflicts as $sid => $c) {
            $cur = $rows->get($sid);
            $by = $cur ? ($cur->updated_by ?? $cur->recorded_by) : null;
            $list[] = ['student_id' => $sid, 'name' => $students->get($sid)->name ?? '', 'code' => $students->get($sid)->code ?? '', 'mine' => $c['mine'],
                'current' => ['status' => $cur ? $cur->status : 'present', 'version' => $cur ? (int) $cur->version : 0, 'note' => $cur->note ?? null,
                    'changed_by' => $by ? ($names[$by] ?? null) : null, 'changed_at' => $cur ? (string) $cur->updated_at : null]];
        }
        $dayInfo = $this->dayPayload($day);
        $message = $list ? 'มีรายการที่ถูกแก้ไขโดยผู้อื่นแล้ว '.count($list).' คน ยังไม่มีการบันทึก' : 'สถานะการยืนยันห้องนี้ถูกเปลี่ยนโดยผู้อื่นแล้ว ยังไม่มีการบันทึก';
        return ['message' => $message, 'conflicts' => $list, 'class_day' => $dayInfo, 'current' => ['conflicts' => $list, 'class_day' => $dayInfo]];
    }

    // ---- reports -----------------------------------------------------------------------------------------------

    public function month(int $classroomId, string $month): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $month);
        $from = $first->toDateString();
        $to = $first->endOfMonth()->toDateString();
        $room = $this->access($classroomId, [$from, $to]);
        $school = $this->school();
        $holidays = Schema::hasTable('acad_calendar_days')
            ? DB::table('acad_calendar_days')->where('school_id', $school)->whereBetween('date', [$from, $to])->whereIn('kind', ['holiday', 'no_class'])->orderBy('id')->pluck('title', 'date')->all() : [];
        $confirmed = DB::table('att_class_days')->where('school_id', $school)->where('classroom_id', $classroomId)->whereBetween('date', [$from, $to])->whereNotNull('confirmed_at')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->flip()->all();
        $days = [];
        for ($d = $first; $d->lessThanOrEqualTo($first->endOfMonth()); $d = $d->addDay()) {
            $iso = $d->toDateString();
            $off = $d->isWeekend() ? 'วันเสาร์-อาทิตย์' : ($holidays[$iso] ?? null);
            $days[] = ['date' => $iso, 'day' => $d->day, 'off' => $off, 'weekend' => $d->isWeekend(), 'confirmed' => isset($confirmed[$iso])];
        }
        $students = $this->students($classroomId);
        // A mark belongs to the room it was taken in (attendances.classroom_id snapshot; older rows fall back to the student's current room).
        $inRoom = fn ($q) => $q->join('students as st', 'st.id', '=', 'attendances.student_id')->where('attendances.school_id', $school)->whereBetween('attendances.date', [$from, $to])
            ->whereRaw('COALESCE(attendances.classroom_id, st.classroom_id) = ?', [$classroomId]);
        $extra = $inRoom(DB::table('attendances'))->pluck('attendances.student_id')->unique()->diff($students->pluck('id'))->values()->all();
        if (Schema::hasTable('student_classroom_moves')) {
            // pupils who left this room during the month without any exception mark (implicit presence only) are found through the room history
            $left = DB::table('student_classroom_moves')->where('school_id', $school)->where('from_classroom_id', $classroomId)->where('effective_on', '>', $from)->pluck('student_id')->map(fn ($v) => (int) $v)->unique()->all();
            $extra = array_values(array_unique(array_merge($extra, array_values(array_diff($left, $students->pluck('id')->map(fn ($v) => (int) $v)->all())))));
        }
        if ($extra) {
            // pupils who were in this room this month and have since moved on still belong in this month's report
            $q = DB::table('students')->where('school_id', $school)->whereIn('id', $extra)->orderBy('code')->get();
            $students = $students->concat($q->map(fn ($r) => $this->student($r)));
        }
        $att = $inRoom(DB::table('attendances'))->whereIn('attendances.student_id', $students->pluck('id')->all() ?: [0])->get(['attendances.student_id', 'attendances.date', 'attendances.status'])->groupBy('student_id');
        // room history and admission date bound the implicit "present" (same rule as ReportsPp::attendance)
        $ids = $students->pluck('id')->map(fn ($v) => (int) $v)->all();
        $moveOf = [];
        if ($ids && Schema::hasTable('student_classroom_moves')) {
            foreach (DB::table('student_classroom_moves')->where('school_id', $school)->whereIn('student_id', $ids)->where('effective_on', '>', $from)->orderBy('effective_on')->orderBy('id')
                ->get(['student_id', 'from_classroom_id', 'to_classroom_id', 'effective_on']) as $mv) {
                $moveOf[(int) $mv->student_id][] = ['on' => substr((string) $mv->effective_on, 0, 10), 'from' => $mv->from_classroom_id === null ? null : (int) $mv->from_classroom_id,
                    'to' => $mv->to_classroom_id === null ? null : (int) $mv->to_classroom_id];
            }
        }
        $admitted = $ids && Schema::hasColumn('students', 'admitted_on')
            ? DB::table('students')->whereIn('id', $ids)->whereNotNull('admitted_on')->pluck('admitted_on', 'id')->map(fn ($d) => substr((string) $d, 0, 10))->all() : [];
        $currentRoom = $students->pluck('classroom_id', 'id')->all();
        $out = [];
        foreach ($students as $s) {
            $by = ($att->get($s->id) ?? collect())->mapWithKeys(fn ($r) => [substr((string) $r->date, 0, 10) => $r->status]);
            $cells = [];
            $totals = array_fill_keys(self::STATUSES, 0);
            foreach ($days as $day) {
                $st = $by->get($day['date']);
                if ($st === null && $day['confirmed'] && !$day['off']
                    && (!isset($admitted[$s->id]) || $day['date'] >= $admitted[$s->id])
                    && (!isset($moveOf[$s->id]) || \App\Support\Saan\ClassroomMoves::roomOn($moveOf[$s->id], isset($currentRoom[$s->id]) ? (int) $currentRoom[$s->id] : null, $day['date']) === $classroomId)) $st = 'present';
                if ($st !== null) { $cells[$day['date']] = $st; $totals[$st] = ($totals[$st] ?? 0) + 1; }
            }
            $out[] = ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'roll' => $s->roll, 'cells' => (object) $cells, 'totals' => $totals];
        }
        return ['classroom' => ['id' => (int) $room->id, 'name' => $room->name], 'month' => $month, 'days' => $days, 'students' => $out, 'statuses' => self::LABELS];
    }

    public function daily(string $date): array
    {
        $school = $this->school();
        $rooms = $this->classrooms();
        // same scope as sheet() and month(): a teacher gets the rooms of the term the date belongs to, not today's homeroom on a day of an earlier cohort
        $scope = TeacherScope::classroomIdsForTerm(null, $this->termFor($date));
        if ($scope !== null) $rooms = array_values(array_filter($rooms, fn ($r) => in_array($r['id'], $scope, true)));
        $ids = array_column($rooms, 'id');
        $studentQ = DB::table('students')->where('school_id', $school)->whereIn('classroom_id', $ids ?: [0]);
        if (Schema::hasColumn('students', 'status')) $studentQ->where('status', 'studying');
        $count = $studentQ->selectRaw('classroom_id, count(*) as n')->groupBy('classroom_id')->pluck('n', 'classroom_id')->all();
        // a past date is counted under the room the mark was taken in (snapshot), not under today's room
        $roomExpr = 'COALESCE(attendances.classroom_id, students.classroom_id)';
        $attQuery = DB::table('attendances')->join('students', 'students.id', '=', 'attendances.student_id')->where('attendances.school_id', $school)->where('attendances.date', $date)
            ->whereRaw($roomExpr.' IN ('.implode(',', array_map('intval', $ids ?: [0])).')');
        // marks of pupils who have left since are kept apart: a day confirmed with a frozen roll size counts them (history), a live count does not
        $live = Schema::hasColumn('students', 'status') ? "CASE WHEN students.status = 'studying' THEN 1 ELSE 0 END" : '1';
        $att = $attQuery->selectRaw($roomExpr.' as cid, attendances.status as st, '.$live.' as live, count(*) as n')->groupByRaw($roomExpr.', attendances.status, '.$live)->get();
        $by = []; $byAll = [];
        foreach ($att as $a) {
            $byAll[(int) $a->cid][$a->st] = ($byAll[(int) $a->cid][$a->st] ?? 0) + (int) $a->n;
            if ((int) $a->live === 1) $by[(int) $a->cid][$a->st] = ($by[(int) $a->cid][$a->st] ?? 0) + (int) $a->n;
        }
        $dayRows = DB::table('att_class_days')->where('school_id', $school)->where('date', $date)->whereNotNull('confirmed_at')->whereIn('classroom_id', $ids ?: [0])->get();
        $confirmed = $dayRows->pluck('classroom_id')->map(fn ($v) => (int) $v)->flip()->all();
        // roll size frozen when the day was confirmed (class changes since then do not rewrite history)
        $frozen = Schema::hasColumn('att_class_days', 'student_count') ? $dayRows->filter(fn ($d) => $d->student_count !== null)->pluck('student_count', 'classroom_id')->map(fn ($v) => (int) $v)->all() : [];
        $list = [];
        $total = ['students' => 0, 'present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'sick' => 0, 'checked' => 0];
        foreach ($rooms as $r) {
            $n = (int) ($frozen[$r['id']] ?? $count[$r['id']] ?? 0);
            $c = isset($frozen[$r['id']]) ? ($byAll[$r['id']] ?? []) : ($by[$r['id']] ?? []);
            $isConfirmed = isset($confirmed[$r['id']]);
            $exc = array_sum(array_map(fn ($s) => (int) ($c[$s] ?? 0), self::EXCEPTIONS));
            $present = $isConfirmed ? max(0, $n - $exc) : (int) ($c['present'] ?? 0);
            $row = ['classroom_id' => $r['id'], 'name' => $r['name'], 'students' => $n, 'present' => $present, 'absent' => (int) ($c['absent'] ?? 0), 'late' => (int) ($c['late'] ?? 0),
                'leave' => (int) ($c['leave'] ?? 0), 'sick' => (int) ($c['sick'] ?? 0), 'confirmed' => $isConfirmed];
            $list[] = $row;
            foreach (['students', 'present', 'absent', 'late', 'leave', 'sick'] as $k) $total[$k] += $row[$k];
            $total['checked'] += $isConfirmed ? 1 : 0;
        }
        return ['date' => $date, 'blocked' => $this->blocked($date), 'classrooms' => $list, 'total' => $total + ['classrooms' => count($list)], 'statuses' => self::LABELS];
    }

    public function studentHistory(int $studentId, ?string $from, ?string $to): array
    {
        $school = $this->school();
        $row = DB::table('students')->where('school_id', $school)->where('id', $studentId)->first();
        abort_unless($row, 404, 'ไม่พบนักเรียน');
        abort_unless(TeacherScope::canSeeStudent($row), 404, 'ไม่พบนักเรียน');
        $s = $this->student($row);
        $all = TeacherScope::classroomIds() === null;
        abort_unless($all || ($s->classroom_id && TeacherScope::canClassroom($s->classroom_id)), 403, 'ไม่มีสิทธิ์ดูข้อมูลนักเรียนคนนี้');
        $to = $to ?: $this->today()->toDateString();
        $from = $from ?: CarbonImmutable::parse($to)->subDays(89)->toDateString();
        abort_if($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366, 422, 'ช่วงวันที่ไม่ถูกต้อง (ไม่เกิน 366 วัน)');
        $rows = DB::table('attendances')->where('school_id', $school)->where('student_id', $studentId)->whereBetween('date', [$from, $to])->orderByDesc('date')->get();
        $corr = DB::table('att_corrections')->where('school_id', $school)->where('student_id', $studentId)->whereBetween('date', [$from, $to])->orderBy('id')->get()->groupBy('attendance_id');
        $names = $this->names($rows->map(fn ($r) => $r->updated_by ?? $r->recorded_by)->merge($corr->flatten()->pluck('changed_by')));
        $totals = array_fill_keys(self::STATUSES, 0);
        $list = $rows->map(function ($r) use ($corr, $names, &$totals) {
            $totals[$r->status] = ($totals[$r->status] ?? 0) + 1;
            $by = $r->updated_by ?? $r->recorded_by;
            return ['date' => substr((string) $r->date, 0, 10), 'status' => $r->status, 'note' => $r->note ?? null, 'version' => (int) ($r->version ?? 1), 'changed_by' => $names[$by] ?? null, 'changed_at' => (string) $r->updated_at,
                'corrections' => ($corr->get($r->id) ?? collect())->map(fn ($c) => ['old' => $c->old_status, 'new' => $c->new_status, 'reason' => $c->reason, 'by' => $names[$c->changed_by] ?? null, 'at' => (string) $c->created_at])->values()->all()];
        })->values()->all();
        return ['student' => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'classroom_id' => $s->classroom_id], 'from' => $from, 'to' => $to, 'rows' => $list, 'totals' => $totals, 'statuses' => self::LABELS];
    }

    // ---- CSV ---------------------------------------------------------------------------------------------------

    /** UTF-8 with BOM; cells that start with a formula character are neutralised. */
    public function csv(array $header, array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        $clean = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'".$v : $v;
        fputcsv($h, array_map($clean, $header), ',', '"', '');
        foreach ($rows as $r) fputcsv($h, array_map($clean, $r), ',', '"', '');
        rewind($h);
        $body = stream_get_contents($h);
        fclose($h);
        return "\xEF\xBB\xBF".$body;
    }

    public function monthCsv(array $m): string
    {
        $header = array_merge(['เลขที่', 'รหัส', 'ชื่อ-สกุล'], array_map(fn ($d) => (string) $d['day'], $m['days']), ['มา', 'ขาด', 'สาย', 'ลา', 'ป่วย']);
        $rows = [];
        foreach ($m['students'] as $s) {
            $cells = (array) $s['cells'];
            $line = [$s['roll'] ?? '', $s['code'], $s['name']];
            foreach ($m['days'] as $d) $line[] = isset($cells[$d['date']]) ? self::LABELS[$cells[$d['date']]] : ($d['off'] ? 'หยุด' : '');
            foreach (self::STATUSES as $st) $line[] = $s['totals'][$st];
            $rows[] = $line;
        }
        return $this->csv($header, $rows);
    }

    public function dailyCsv(array $d): string
    {
        $rows = array_map(fn ($r) => [$r['name'], $r['students'], $r['present'], $r['absent'], $r['late'], $r['leave'], $r['sick'], $r['confirmed'] ? 'เช็คแล้ว' : 'ยังไม่เช็ค'], $d['classrooms']);
        $t = $d['total'];
        $rows[] = ['รวม', $t['students'], $t['present'], $t['absent'], $t['late'], $t['leave'], $t['sick'], $t['checked'].'/'.$t['classrooms'].' ห้อง'];
        return $this->csv(['ห้องเรียน', 'จำนวนนักเรียน', 'มา', 'ขาด', 'สาย', 'ลา', 'ป่วย', 'สถานะการเช็ค'], $rows);
    }

    public function studentCsv(array $h): string
    {
        $rows = array_map(fn ($r) => [$r['date'], self::LABELS[$r['status']] ?? $r['status'], $r['note'] ?? '', $r['changed_by'] ?? '', $r['changed_at'], count($r['corrections'])], $h['rows']);
        return $this->csv(['วันที่', 'สถานะ', 'หมายเหตุ', 'ผู้บันทึกล่าสุด', 'เวลาแก้ไขล่าสุด', 'จำนวนครั้งที่แก้สถานะ'], $rows);
    }
}


