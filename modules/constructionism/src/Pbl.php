<?php

namespace Saan\Modules\Constructionism;

use App\Support\Saan\DbError;
use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use App\Support\Saan\VersionConflict;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** constructionism domain: projects, members, journal. Files live in Files, reflections in Reflections. Teacher-only entry. */
class Pbl
{
    public const STATUS = ['planning' => 'วางแผน', 'active' => 'กำลังทำ', 'presenting' => 'นำเสนอ', 'done' => 'เสร็จสิ้น'];

    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    public static function q(string $table)
    {
        return DB::table($table)->where('school_id', self::school());
    }

    public static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    public static function isAll(?object $user): bool
    {
        return $user && in_array($user->role, TeacherScope::ALL_ROLES, true);
    }

    public static function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v)));
    }

    /** Unique violation (race after our own check) becomes 409, never 500. */
    public static function guard(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            if (DbError::isUnique($e)) abort(409, 'ข้อมูลซ้ำกับรายการที่มีอยู่แล้ว กรุณาโหลดใหม่');
            throw $e;
        }
    }

    /** Mutation with the private-disk path removed from a 409 body. */
    public static function safe(\Closure $fn, array $hide = []): mixed
    {
        try {
            return self::guard($fn);
        } catch (VersionConflict $e) {
            throw new VersionConflict($e->current === null ? null : Arr::except($e->current, $hide), $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ access

    public static function project(int $id): object
    {
        return self::q('con_projects')->where('id', $id)->first() ?? abort(404, 'ไม่พบโครงงาน');
    }

    public static function canAccess(object $p, ?object $user): bool
    {
        if (!$user) return false;
        if (self::isAll($user)) return true;
        if ($user->role !== 'teacher') return false;
        if ($user->teacher_id && (int) $p->advisor_teacher_id === (int) $user->teacher_id) return true;
        $ids = TeacherScope::classroomIdsForTerm($user, (int) $p->term_id);
        return $ids === null || in_array((int) $p->classroom_id, $ids, true);
    }

    public static function readable(int $id, ?object $user): object
    {
        $p = self::project($id);
        if (!self::canAccess($p, $user)) abort(403, 'ไม่มีสิทธิ์ดูโครงงานนี้');
        return $p;
    }

    /** Access plus: teachers may change only projects of the current term (history is kept by admin/principal). */
    public static function writable(int $id, ?object $user): object
    {
        $p = self::readable($id, $user);
        if (!self::isAll($user) && (int) $p->term_id !== (int) TermScope::currentId()) abort(403, 'โครงงานของภาคเรียนที่ผ่านมาแก้ไขได้เฉพาะผู้ดูแลระบบหรือผู้อำนวยการ');
        return $p;
    }

    public static function canDelete(object $p, ?object $user): bool
    {
        return self::isAll($user) || ($user && $user->teacher_id && (int) $p->advisor_teacher_id === (int) $user->teacher_id) || ($user && (int) $p->created_by === (int) $user->id);
    }

    // ------------------------------------------------------------------ reads

    public static function options(?object $user): array
    {
        $term = TermScope::current();
        $ids = TeacherScope::classroomIds($user);
        $rooms = self::q('classrooms')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name']);
        return [
            'term' => $term ? ['id' => (int) $term->id, 'year' => (int) $term->year, 'term' => (int) $term->term] : null,
            'classrooms' => $rooms->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])->all(),
            'terms' => self::q('academic_terms')->orderByDesc('year')->orderByDesc('term')->limit(30)->get(['id', 'year', 'term'])->map(fn ($t) => ['id' => (int) $t->id, 'year' => (int) $t->year, 'term' => (int) $t->term])->all(),
            'teachers' => self::q('teachers')->orderBy('name')->get(['id', 'name'])->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->all(),
            'my_teacher_id' => $user && $user->teacher_id ? (int) $user->teacher_id : null,
            'statuses' => self::STATUS, 'kinds' => Files::KINDS, 'levels' => Rubric::LEVELS,
            'rubric' => ['criteria' => Rubric::criteria(), 'revision' => Rubric::revision(), 'custom' => Rubric::isCustom(), 'can_edit' => self::isAll($user)],
        ];
    }

    public static function list(?object $user, array $f): array
    {
        $term = !empty($f['term_id']) ? (int) $f['term_id'] : TermScope::currentId();
        $q = self::q('con_projects')->when($term, fn ($x) => $x->where('term_id', $term));
        if (!self::isAll($user)) {
            if (($user?->role) !== 'teacher') return [];
            $ids = TeacherScope::classroomIdsForTerm($user, $term) ?? [];
            $q->where(fn ($w) => $w->whereIn('classroom_id', $ids ?: [0])->when($user->teacher_id, fn ($x) => $x->orWhere('advisor_teacher_id', $user->teacher_id)));
        }
        if (!empty($f['classroom_id'])) $q->where('classroom_id', (int) $f['classroom_id']);
        if (!empty($f['status']) && isset(self::STATUS[$f['status']])) $q->where('status', $f['status']);
        $rows = $q->orderByDesc('id')->limit(300)->get();
        $ids = $rows->pluck('id')->all();
        $count = fn (string $t) => $ids ? self::q($t)->whereIn('project_id', $ids)->selectRaw('project_id, count(*) c')->groupBy('project_id')->pluck('c', 'project_id') : collect();
        $m = $count('con_members');
        $l = $count('con_logs');
        $a = $count('con_artifacts');
        $rooms = self::q('classrooms')->pluck('name', 'id');
        $teachers = self::q('teachers')->pluck('name', 'id');
        return $rows->map(fn ($p) => self::shape($p, $rooms, $teachers) + ['members' => (int) ($m[$p->id] ?? 0), 'logs' => (int) ($l[$p->id] ?? 0), 'artifacts' => (int) ($a[$p->id] ?? 0)])->all();
    }

    public static function shape(object $p, $rooms, $teachers): array
    {
        return ['id' => (int) $p->id, 'term_id' => (int) $p->term_id, 'classroom_id' => (int) $p->classroom_id, 'classroom_name' => $rooms[$p->classroom_id] ?? null,
            'title' => $p->title, 'driving_question' => $p->driving_question, 'advisor_teacher_id' => $p->advisor_teacher_id ? (int) $p->advisor_teacher_id : null,
            'advisor_name' => $p->advisor_teacher_id ? ($teachers[$p->advisor_teacher_id] ?? null) : null,
            'status' => $p->status, 'status_label' => self::STATUS[$p->status] ?? $p->status, 'version' => (int) $p->version, 'updated_at' => (string) $p->updated_at];
    }

    /** Everything the project screen and the printout need. Never contains artifact paths. */
    public static function detail(object $p, ?object $user): array
    {
        $rooms = self::q('classrooms')->pluck('name', 'id');
        $teachers = self::q('teachers')->pluck('name', 'id');
        $members = self::q('con_members')->where('project_id', $p->id)->orderBy('id')->get();
        $students = self::studentNames($members->pluck('student_id')->all());
        $logs = self::q('con_logs')->where('project_id', $p->id)->orderBy('date')->orderBy('id')->get();
        $refl = self::q('con_reflections')->where('project_id', $p->id)->orderBy('id')->get();
        $taken = $members->pluck('student_id')->all();
        // an earlier term's project must not list the current cohort of the same classroom as candidates
        $candidates = (int) $p->term_id !== (int) TermScope::currentId() ? collect() : self::q('students')->where('classroom_id', $p->classroom_id)
            ->when(Schema::hasColumn('students', 'status'), fn ($q) => $q->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'studying')))
            ->when($taken, fn ($q) => $q->whereNotIn('id', $taken))->orderBy('code')->limit(200)->get(['id', 'code', 'name']);
        return [
            'project' => self::shape($p, $rooms, $teachers) + ['can_delete' => self::canDelete($p, $user), 'can_write' => self::isAll($user) || (int) $p->term_id === (int) TermScope::currentId()],
            'members' => $members->map(fn ($m) => ['id' => (int) $m->id, 'student_id' => (int) $m->student_id, 'code' => $students[$m->student_id]['code'] ?? '', 'name' => $students[$m->student_id]['name'] ?? '(ไม่พบนักเรียน)', 'role' => $m->role, 'version' => (int) $m->version])->all(),
            'candidates' => $candidates->map(fn ($s) => ['id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name])->all(),
            'logs' => $logs->map(fn ($l) => ['id' => (int) $l->id, 'date' => (string) $l->date, 'entry' => $l->entry, 'by_teacher' => (bool) $l->by_teacher, 'by_name' => $l->by_name, 'version' => (int) $l->version])->all(),
            'artifacts' => Files::shapeMany($p->id),
            'reflections' => $refl->map(fn ($r) => Reflections::shape($r, $students))->all(),
            'rubric' => ['criteria' => Rubric::criteria(), 'revision' => Rubric::revision()],
        ];
    }

    private static function studentNames(array $ids): array
    {
        if (!$ids) return [];
        return self::q('students')->whereIn('id', $ids)->get(['id', 'code', 'name'])->mapWithKeys(fn ($s) => [(int) $s->id => ['code' => $s->code, 'name' => $s->name]])->all();
    }

    // ------------------------------------------------------------------ project writes

    private static function advisor(mixed $id): ?int
    {
        if ($id === null || $id === '') return null;
        if (!self::q('teachers')->where('id', (int) $id)->exists()) self::invalid('advisor_teacher_id', 'ไม่พบครูที่ปรึกษา');
        return (int) $id;
    }

    private static function title(string $raw): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ($t === '' || mb_strlen($t) > 160) self::invalid('title', 'กรุณากรอกชื่อโครงงาน (ไม่เกิน 160 ตัวอักษร)');
        return $t;
    }

    private static function dupTitle(int $term, int $classroom, string $title, ?int $except = null): void
    {
        $q = self::q('con_projects')->where('term_id', $term)->where('classroom_id', $classroom)->where('title_key', self::key($title));
        if ($except) $q->where('id', '!=', $except);
        if ($q->exists()) abort(409, 'ห้องนี้มีโครงงานชื่อนี้ในภาคเรียนนี้แล้ว');
    }

    public static function create(array $d, ?object $user): array
    {
        $term = TermScope::currentId() ?? self::invalid('classroom_id', 'ยังไม่ได้ตั้งภาคเรียน');
        $cid = (int) $d['classroom_id'];
        if (!TeacherScope::canClassroom($cid, $user)) abort(403, 'ไม่มีสิทธิ์สร้างโครงงานในห้องนี้');
        $title = self::title((string) $d['title']);
        self::dupTitle($term, $cid, $title);
        $advisor = array_key_exists('advisor_teacher_id', $d) ? self::advisor($d['advisor_teacher_id']) : ($user->teacher_id ? (int) $user->teacher_id : null);
        $row = self::guard(fn () => Mutation::insert('con_projects', ['term_id' => $term, 'classroom_id' => $cid, 'title' => $title, 'title_key' => self::key($title),
            'driving_question' => self::text($d['driving_question'] ?? null, 'driving_question', 1000), 'advisor_teacher_id' => $advisor, 'status' => 'planning', 'created_by' => $user->id], 'pbl.project.create', ['title' => $title]));
        return self::detail($row, $user);
    }

    public static function text(mixed $v, string $field, int $max): ?string
    {
        if ($v === null) return null;
        $v = trim(str_replace("\r\n", "\n", (string) $v));
        if (mb_strlen($v) > $max) self::invalid($field, 'ข้อความยาวเกิน '.$max.' ตัวอักษร');
        return $v === '' ? null : $v;
    }

    public static function update(int $id, array $d, ?object $user): array
    {
        $p = self::writable($id, $user);
        $title = self::title((string) $d['title']);
        if (!isset(self::STATUS[$d['status'] ?? $p->status])) self::invalid('status', 'สถานะไม่ถูกต้อง');
        self::dupTitle((int) $p->term_id, (int) $p->classroom_id, $title, $id);
        // the advisor decides who may delete: only someone who can already delete (advisor, creator, admin/principal) may hand it to another teacher
        if (array_key_exists('advisor_teacher_id', $d) && self::advisor($d['advisor_teacher_id']) !== ($p->advisor_teacher_id === null ? null : (int) $p->advisor_teacher_id) && !self::canDelete($p, $user)) abort(403, 'เปลี่ยนครูที่ปรึกษาได้เฉพาะครูที่ปรึกษาเดิม ผู้สร้าง หรือผู้ดูแลระบบ');
        $new = self::safe(fn () => Mutation::update('con_projects', $id, (int) $d['version'], [
            'title' => $title, 'title_key' => self::key($title), 'driving_question' => self::text($d['driving_question'] ?? null, 'driving_question', 1000),
            'advisor_teacher_id' => array_key_exists('advisor_teacher_id', $d) ? self::advisor($d['advisor_teacher_id']) : $p->advisor_teacher_id, 'status' => $d['status'] ?? $p->status,
        ], 'pbl.project.update', ['title' => $title, 'status' => $d['status'] ?? $p->status]));
        return self::detail($new, $user);
    }

    public static function delete(int $id, int $version, ?object $user): void
    {
        $p = self::writable($id, $user);
        if (!self::canDelete($p, $user)) abort(403, 'ลบโครงงานได้เฉพาะครูที่ปรึกษา ผู้สร้าง หรือผู้ดูแลระบบ');
        $paths = [];
        DB::transaction(function () use ($id, $version, &$paths) {
            Mutation::delete('con_projects', $id, $version, 'pbl.project.delete'); // version check first: a stale delete rolls nothing back because nothing else ran yet
            $paths = self::q('con_artifacts')->where('project_id', $id)->whereNotNull('path')->pluck('path')->all();
            $counts = [];
            foreach (['con_reflections', 'con_artifacts', 'con_logs', 'con_members'] as $t) $counts[$t] = self::q($t)->where('project_id', $id)->delete();
            Mutation::audit('pbl.project.cascade', 'con_projects', $id, $counts);
        });
        foreach ($paths as $path) Storage::disk('local')->delete($path);
    }

    // ------------------------------------------------------------------ members

    public static function addMember(int $projectId, array $d, ?object $user): array
    {
        $p = self::writable($projectId, $user);
        $s = self::q('students')->where('id', (int) $d['student_id'])->first() ?? self::invalid('student_id', 'ไม่พบนักเรียน');
        if ((int) $s->classroom_id !== (int) $p->classroom_id) self::invalid('student_id', 'นักเรียนต้องอยู่ห้องเดียวกับโครงงาน');
        if (property_exists($s, 'status') && $s->status !== null && $s->status !== 'studying') self::invalid('student_id', 'นักเรียนคนนี้ไม่ได้อยู่ในสถานะกำลังศึกษา');
        if (self::q('con_members')->where('project_id', $projectId)->where('student_id', $s->id)->exists()) abort(409, 'นักเรียนคนนี้เป็นสมาชิกโครงงานแล้ว');
        self::guard(fn () => Mutation::insert('con_members', ['project_id' => $projectId, 'student_id' => (int) $s->id, 'role' => self::text($d['role'] ?? null, 'role', 60)], 'pbl.member.add', ['project_id' => $projectId, 'student_id' => (int) $s->id]));
        return self::detail($p, $user);
    }

    private static function child(string $table, int $id, ?object $user, bool $write = true): array
    {
        $row = self::q($table)->where('id', $id)->first() ?? abort(404);
        $p = $write ? self::writable((int) $row->project_id, $user) : self::readable((int) $row->project_id, $user);
        return [$row, $p];
    }

    public static function updateMember(int $id, array $d, ?object $user): array
    {
        [, $p] = self::child('con_members', $id, $user);
        self::safe(fn () => Mutation::update('con_members', $id, (int) $d['version'], ['role' => self::text($d['role'] ?? null, 'role', 60)], 'pbl.member.update'));
        return self::detail($p, $user);
    }

    public static function removeMember(int $id, int $version, ?object $user): array
    {
        [$m, $p] = self::child('con_members', $id, $user);
        if (self::q('con_reflections')->where('project_id', $m->project_id)->where('student_id', $m->student_id)->exists()) self::invalid('member', 'นักเรียนคนนี้มีบันทึกการสะท้อนคิดแล้ว ลบการสะท้อนคิดก่อนจึงจะนำออกจากโครงงานได้');
        self::safe(fn () => Mutation::delete('con_members', $id, $version, 'pbl.member.remove'));
        return self::detail($p, $user);
    }

    // ------------------------------------------------------------------ journal

    private static function logFields(array $d): array
    {
        $date = (string) $d['date'];
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) self::invalid('date', 'วันที่ไม่ถูกต้อง');
        if ($date > now()->addDay()->toDateString()) self::invalid('date', 'บันทึกล่วงหน้าไม่ได้');
        $entry = self::text($d['entry'] ?? null, 'entry', 4000) ?? self::invalid('entry', 'กรุณากรอกบันทึก');
        return ['date' => $date, 'entry' => $entry, 'by_teacher' => (bool) ($d['by_teacher'] ?? true)];
    }

    public static function addLog(int $projectId, array $d, ?object $user): array
    {
        $p = self::writable($projectId, $user);
        Mutation::insert('con_logs', self::logFields($d) + ['project_id' => $projectId, 'by_user_id' => $user->id, 'by_name' => mb_substr((string) $user->name, 0, 120)], 'pbl.log.add', ['project_id' => $projectId]);
        return self::detail($p, $user);
    }

    public static function updateLog(int $id, array $d, ?object $user): array
    {
        [, $p] = self::child('con_logs', $id, $user);
        self::safe(fn () => Mutation::update('con_logs', $id, (int) $d['version'], self::logFields($d), 'pbl.log.update'));
        return self::detail($p, $user);
    }

    public static function removeLog(int $id, int $version, ?object $user): array
    {
        [, $p] = self::child('con_logs', $id, $user);
        self::safe(fn () => Mutation::delete('con_logs', $id, $version, 'pbl.log.remove'));
        return self::detail($p, $user);
    }

    public static function childOf(string $table, int $id, ?object $user, bool $write = true): array
    {
        return self::child($table, $id, $user, $write);
    }
}
