<?php

namespace Saan\Modules\Assessment;

use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use App\Support\Saan\VersionConflict;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Assessment data access. Rules enforced here (never in the browser):
 * - teachers reach only courses whose asm_courses.teacher_id = users.teacher_id (GAP-OPS-002, not homeroom based)
 * - current-term endpoints use TermScope::current(); another term needs an explicit, validated term id (OPS-001)
 * - score writes are compare-and-swap per cell under a course row lock, with one revision row per change (OPS-004)
 * - every write returns the freshly persisted rows (OPS-008)
 */
class Assessment
{
    public const KINDS = ['characteristic', 'competency', 'reading'];
    private const CHUNK = 300;

    private static array $cols = [];

    private function has(string $table, string $column): bool
    {
        return self::$cols[$table][$column] ??= Schema::hasColumn($table, $column);
    }

    public function school(): int { return (int) config('school.school_id'); }

    public static function key(string $value): string
    {
        if (class_exists(\Normalizer::class)) $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        // identity key: ALL whitespace removed ('ท 11101' and 'ท11101' are the same subject), like Academic::key
        return mb_strtolower((string) preg_replace('/\s+/u', '', $value));
    }

    public static function isUnique(QueryException $e): bool
    {
        return \App\Support\Saan\DbError::isUnique($e);

    }

    /** Run a write; a unique-index violation (race after our own pre-check) becomes 409, never 500. */
    public function guard(\Closure $fn, string $message): mixed
    {
        try { return $fn(); } catch (QueryException $e) { if (self::isUnique($e)) abort(409, $message); throw $e; }
    }

    public function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    // ---- terms -----------------------------------------------------------------------------------------------

    public function term(?int $explicit = null): object
    {
        if ($explicit !== null) {
            $row = DB::table('academic_terms')->where('school_id', $this->school())->where('id', $explicit)->first();
            abort_unless($row, 404, 'ไม่พบภาคเรียน');
            return $row;
        }
        $row = TermScope::current();
        if (!$row) $this->fail('term_id', 'ยังไม่ได้ตั้งภาคเรียนปัจจุบัน');
        return $row;
    }

    public function terms(): array
    {
        $current = TermScope::currentId();
        return DB::table('academic_terms')->where('school_id', $this->school())->orderByDesc('year')->orderByDesc('term')->get(['id', 'year', 'term'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'year' => (int) $t->year, 'term' => (int) $t->term, 'current' => (int) $t->id === $current])->all();
    }

    public static function termInfo(object $t): array
    {
        return ['id' => (int) $t->id, 'year' => (int) $t->year, 'term' => (int) $t->term];
    }

    // ---- access ----------------------------------------------------------------------------------------------

    private function user(): object
    {
        $u = auth()->user();
        abort_unless($u && in_array($u->role, ['admin', 'principal', 'teacher'], true), 403, 'บัญชีนี้ไม่มีสิทธิ์ใช้งานส่วนนี้');
        return $u;
    }

    public function isManager(): bool { return in_array($this->user()->role, ['admin', 'principal'], true); }

    private function courseQuery()
    {
        $q = DB::table('asm_courses as c')
            ->join('asm_subjects as s', 's.id', '=', 'c.subject_id')
            ->join('classrooms as k', 'k.id', '=', 'c.classroom_id')
            ->join('academic_terms as t', 't.id', '=', 'c.term_id')
            ->leftJoin('teachers as th', 'th.id', '=', 'c.teacher_id')
            ->where('c.school_id', $this->school())
            ->select('c.*', 's.group_name', 'k.name as classroom_name',
                't.year as term_year', 't.term as term_no', 'th.name as teacher_name')
            ->selectRaw($this->has('asm_courses', 'snap_at')
                ? 'COALESCE(c.subject_code_snap, s.code) as subject_code, COALESCE(c.subject_name_snap, s.name) as subject_name'
                : 's.code as subject_code, s.name as subject_name');
        $u = $this->user();
        if ($u->role === 'teacher') {
            // GAP-OPS-002: only courses assigned to this teacher; no homeroom shortcut
            if ($u->teacher_id) $q->where('c.teacher_id', (int) $u->teacher_id); else $q->whereRaw('1 = 0');
        }
        return $q;
    }

    /** Loads one course the signed-in staff member may use; 404 when it does not exist, 403 when it belongs to someone else. */
    public function course(int $id): object
    {
        $user = $this->user();
        $row = $this->courseQuery()->where('c.id', $id)->first();
        if (!$row) {
            abort_unless(DB::table('asm_courses')->where('school_id', $this->school())->where('id', $id)->exists(), 404, 'ไม่พบรายวิชา');
            abort(403, 'รายวิชานี้ไม่ได้มอบหมายให้บัญชีของคุณ');
        }
        return $row;
    }

    public function present(object $c): array
    {
        return [
            'id' => (int) $c->id, 'version' => (int) $c->version, 'status' => $c->status,
            'term' => ['id' => (int) $c->term_id, 'year' => (int) $c->term_year, 'term' => (int) $c->term_no],
            'subject' => ['id' => (int) $c->subject_id, 'code' => $c->subject_code, 'name' => $c->subject_name, 'group_name' => $c->group_name],
            'classroom' => ['id' => (int) $c->classroom_id, 'name' => $c->classroom_name],
            'teacher' => $c->teacher_id ? ['id' => (int) $c->teacher_id, 'name' => $c->teacher_name] : null,
            'policy' => json_decode((string) $c->policy, true) ?: GradePolicy::defaults(),
        ];
    }

    /** Active classrooms and teachers for the setup screens. */
    public function lookups(): array
    {
        $hasGrade = $this->has('classrooms', 'grade_level');
        $rooms = DB::table('classrooms')->where('school_id', $this->school())->when($this->has('classrooms', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get($hasGrade ? ['id', 'name', 'grade_level'] : ['id', 'name']);
        $teachers = DB::table('teachers')->where('school_id', $this->school())->when($this->has('teachers', 'is_active'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get(['id', 'name']);
        // the academic grade_level column wins; the name prefix ("ป.4/1" -> "ป.4") is only a fallback for rows without one
        $grades = $rooms->map(fn ($k) => $hasGrade && trim((string) ($k->grade_level ?? '')) !== '' ? trim((string) $k->grade_level) : trim(explode('/', (string) $k->name)[0]))
            ->filter()->unique()->values()->all();
        return ['classrooms' => $rooms->map(fn ($k) => ['id' => (int) $k->id, 'name' => $k->name])->all(), 'teachers' => $teachers->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->all(), 'grade_levels' => $grades];
    }

    // ---- subjects --------------------------------------------------------------------------------------------

    public static function subjectRow(object $s): array
    {
        return [
            'id' => (int) $s->id, 'version' => (int) $s->version, 'code' => $s->code, 'name' => $s->name, 'group_name' => $s->group_name,
            'credit' => $s->credit === null ? null : (float) $s->credit, 'hours_per_year' => $s->hours_per_year === null ? null : (int) $s->hours_per_year,
            'grade_levels' => json_decode((string) ($s->grade_levels ?? '[]'), true) ?: [], 'is_active' => (bool) $s->is_active,
        ];
    }

    public function subjects(bool $activeOnly = false): array
    {
        $q = DB::table('asm_subjects')->where('school_id', $this->school());
        if ($activeOnly) $q->where('is_active', true);
        return $q->orderBy('group_name')->orderBy('code_key')->get()->map(fn ($s) => self::subjectRow($s))->all();
    }

    public function subjectSave(array $d, ?int $id, ?int $version): array
    {
        $key = self::key($d['code']);
        $dupe = DB::table('asm_subjects')->where('school_id', $this->school())->where('code_key', $key)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($dupe) abort(409, 'รหัสวิชานี้มีอยู่แล้ว');
        $values = [
            'code' => trim($d['code']), 'code_key' => $key, 'name' => trim($d['name']), 'group_name' => ($d['group_name'] ?? null) ?: null,
            'credit' => $d['credit'] ?? null, 'hours_per_year' => $d['hours_per_year'] ?? null,
            'grade_levels' => array_values(array_unique(array_map('trim', $d['grade_levels'] ?? []))), 'is_active' => (bool) ($d['is_active'] ?? true),
        ];
        $row = $this->guard(fn () => $id
            ? Mutation::update('asm_subjects', $id, (int) $version, $values, 'assessment.subject.updated', ['code' => $values['code']])
            : Mutation::insert('asm_subjects', $values, 'assessment.subject.created', ['code' => $values['code']]), 'รหัสวิชานี้มีอยู่แล้ว');
        if ($id) $this->refreshSnapshots((int) $id, $values);
        return self::subjectRow($row);
    }

    /** A corrected subject code/name/credit flows into courses of the current and later terms (past terms stay frozen as reported). */
    private function refreshSnapshots(int $subjectId, array $v): void
    {
        if (!$this->has('asm_courses', 'snap_at')) return;
        $cur = TermScope::currentId();
        $from = $cur ? DB::table('academic_terms')->where('id', $cur)->value('starts_on') : null;
        $termIds = DB::table('academic_terms')->where('school_id', $this->school())
            ->where(fn ($q) => $q->when($cur, fn ($w) => $w->where('id', $cur))->when($from, fn ($w) => $w->orWhere('starts_on', '>', $from)))
            ->pluck('id')->all();
        if (!$cur || !$termIds) return;
        $n = DB::table('asm_courses')->where('school_id', $this->school())->where('subject_id', $subjectId)->whereIn('term_id', $termIds)
            ->where(fn ($q) => $q->whereNull('subject_code_snap')->orWhere('subject_code_snap', '!=', $v['code'])->orWhere('subject_name_snap', '!=', $v['name'])
                ->orWhereNull('credit_snap')->orWhere('credit_snap', '!=', $v['credit'] ?? -1))
            ->update(['snap_at' => now(), 'subject_code_snap' => $v['code'], 'subject_name_snap' => $v['name'], 'credit_snap' => $v['credit'] ?? null]);
        if ($n) Mutation::audit('assessment.subject.snapshot_refreshed', 'asm_subjects', $subjectId, ['courses' => $n, 'code' => $v['code']]);
    }

    public function subjectDelete(int $id, int $version): void
    {
        abort_unless(DB::table('asm_subjects')->where('school_id', $this->school())->where('id', $id)->exists(), 404, 'ไม่พบรายวิชา');
        if (DB::table('asm_courses')->where('subject_id', $id)->exists()) $this->fail('id', 'วิชานี้ถูกใช้ในรายวิชาที่เปิดสอนแล้ว ลบไม่ได้ ให้ปิดการใช้งานแทน');
        Mutation::delete('asm_subjects', $id, $version, 'assessment.subject.deleted');
    }

    // ---- courses ---------------------------------------------------------------------------------------------

    /** @return array{0:array,1:object} rows with student/cell counts, and the term used */
    public function courses(object $term, array $filter = []): array
    {
        $q = $this->courseQuery()->where('c.term_id', $term->id);
        if (!empty($filter['classroom_id'])) $q->where('c.classroom_id', (int) $filter['classroom_id']);
        if (!empty($filter['subject_id'])) $q->where('c.subject_id', (int) $filter['subject_id']);
        $rows = $q->orderBy('k.name')->orderBy('s.code_key')->get();
        $ids = $rows->pluck('id')->all();
        // a cleared cell keeps its row (score_centi null), so only filled cells count as entered
        $cells = $ids ? DB::table('asm_scores')->whereNotNull('score_centi')->whereIn('course_id', $ids)->groupBy('course_id')->selectRaw('course_id, count(*) as n')->pluck('n', 'course_id') : collect();
        if ($this->isCurrent($term)) {
            $classes = $rows->pluck('classroom_id')->unique()->all();
            $students = $classes ? $this->studentQuery()->whereIn('classroom_id', $classes)->groupBy('classroom_id')->selectRaw('classroom_id, count(*) as n')->pluck('n', 'classroom_id') : collect();
            $countOf = fn ($c) => (int) ($students[$c->classroom_id] ?? 0);
        } else {
            // a past term never borrows today's class list: its students are the ones that have rows in that course
            $own = $ids ? DB::table('asm_scores')->whereIn('course_id', $ids)->groupBy('course_id')->selectRaw('course_id, count(distinct student_id) as n')->pluck('n', 'course_id') : collect();
            $countOf = fn ($c) => (int) ($own[$c->id] ?? 0);
        }
        $data = $rows->map(fn ($c) => $this->present($c) + [
            'students_count' => $countOf($c), 'cells_entered' => (int) ($cells[$c->id] ?? 0),
        ])->all();
        return [$data, $term];
    }

    private function isCurrent(object $term): bool { return (int) $term->id === (int) TermScope::currentId(); }

    private function studentQuery()
    {
        $q = DB::table('students')->where('school_id', $this->school());
        if ($this->has('students', 'status')) $q->where('status', 'studying');
        return $q;
    }

    private function checkRefs(?int $classroomId, ?int $teacherId, ?int $subjectId = null): void
    {
        if ($classroomId !== null) {
            $q = DB::table('classrooms')->where('school_id', $this->school())->where('id', $classroomId);
            if ($this->has('classrooms', 'is_active')) $q->where('is_active', true);
            if (!$q->exists()) $this->fail('classroom_id', 'ไม่พบห้องเรียน หรือห้องปิดใช้งาน');
        }
        if ($teacherId !== null) {
            $q = DB::table('teachers')->where('school_id', $this->school())->where('id', $teacherId);
            if ($this->has('teachers', 'is_active')) $q->where('is_active', true);
            if (!$q->exists()) $this->fail('teacher_id', 'ไม่พบครู หรือครูปิดใช้งาน');
        }
        if ($subjectId !== null && !DB::table('asm_subjects')->where('school_id', $this->school())->where('id', $subjectId)->where('is_active', true)->exists()) {
            $this->fail('subject_id', 'ไม่พบรายวิชา หรือปิดใช้งาน');
        }
    }

    public function policy(?array $policy): array
    {
        try { return GradePolicy::normalize($policy); } catch (InvalidArgumentException $e) { $this->fail('policy', $e->getMessage()); }
    }

    /** Copy of the subject's code, name and credit stored on a new course, so later subject edits never change a reported term. */
    private function subjectSnapshot(int $subjectId): array
    {
        if (!$this->has('asm_courses', 'snap_at')) return [];
        $s = DB::table('asm_subjects')->where('id', $subjectId)->first(['code', 'name', 'credit']);
        return $s ? ['snap_at' => now(), 'subject_code_snap' => $s->code, 'subject_name_snap' => $s->name, 'credit_snap' => $s->credit] : [];
    }

    public function courseCreate(object $term, int $subjectId, int $classroomId, ?int $teacherId, ?array $policy): array
    {
        $this->checkRefs($classroomId, $teacherId, $subjectId);
        $policy = $this->policy($policy);
        if (DB::table('asm_courses')->where('term_id', $term->id)->where('subject_id', $subjectId)->where('classroom_id', $classroomId)->exists()) abort(409, 'ห้องนี้มีรายวิชานี้ในภาคเรียนนี้แล้ว');
        $row = $this->guard(fn () => Mutation::insert('asm_courses', [
            'term_id' => (int) $term->id, 'subject_id' => $subjectId, 'classroom_id' => $classroomId, 'teacher_id' => $teacherId, 'policy' => $policy, 'status' => 'open',
        ] + $this->subjectSnapshot($subjectId), 'assessment.course.created', ['term_id' => (int) $term->id, 'subject_id' => $subjectId, 'classroom_id' => $classroomId]), 'ห้องนี้มีรายวิชานี้ในภาคเรียนนี้แล้ว');
        return $this->present($this->course((int) $row->id));
    }

    public function courseUpdate(int $id, int $version, array $d): array
    {
        $course = $this->course($id);
        $changes = [];
        if (array_key_exists('teacher_id', $d)) { $this->checkRefs(null, $d['teacher_id'] === null ? null : (int) $d['teacher_id']); $changes['teacher_id'] = $d['teacher_id']; }
        if (array_key_exists('policy', $d) && $d['policy'] !== null) {
            $policy = $this->policy($d['policy']);
            if ($policy !== (json_decode((string) $course->policy, true) ?: null)) $changes['policy'] = $policy;
        }
        if (array_key_exists('status', $d)) $changes['status'] = $d['status'];
        if (!$changes) $this->fail('version', 'ไม่มีข้อมูลที่เปลี่ยน');
        DB::transaction(function () use ($id, $version, $changes) {
            // lock the course row first (the same lock saveScores takes), then check "has scores" so a first score save cannot slip in between
            DB::table('asm_courses')->where('id', $id)->where('school_id', $this->school())->lockForUpdate()->first(['id']);
            if (isset($changes['policy']) && DB::table('asm_scores')->where('course_id', $id)->exists()) $this->fail('policy', 'รายวิชานี้มีคะแนนแล้ว แก้เกณฑ์ไม่ได้');
            Mutation::update('asm_courses', $id, $version, $changes, 'assessment.course.updated', ['changed' => array_keys($changes)]);
        });
        return $this->present($this->course($id));
    }

    public function courseDelete(int $id, int $version): void
    {
        $this->course($id);
        DB::transaction(function () use ($id, $version) {
            // the same course-row lock saveScores / saveAttributes / courseUpdate take: a first score cannot slip in between the checks and the delete
            DB::table('asm_courses')->where('id', $id)->where('school_id', $this->school())->lockForUpdate()->first(['id']) ?? abort(404, 'ไม่พบรายวิชา');
            if (DB::table('asm_scores')->where('course_id', $id)->exists()) $this->fail('id', 'รายวิชานี้มีคะแนนแล้ว ลบไม่ได้ ให้ปิดรายวิชาแทน');
            if (DB::table('asm_attributes')->where('course_id', $id)->exists() || DB::table('asm_score_revisions')->where('course_id', $id)->exists()) {
                $this->fail('id', 'มีประวัติคะแนน/คุณลักษณะแล้ว ให้ปิดรายวิชาแทน');
            }
            Mutation::delete('asm_courses', $id, $version, 'assessment.course.deleted');
        });
    }

    private function classroomsOfGrade(string $grade)
    {
        $q = DB::table('classrooms')->where('school_id', $this->school());
        if ($this->has('classrooms', 'is_active')) $q->where('is_active', true);
        if ($this->has('classrooms', 'grade_level')) {
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $grade).'/%';
            $q->where(fn ($w) => $w->where('grade_level', $grade)->orWhere('name', 'like', $like));
        } else {
            $q->where('name', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $grade).'/%');
        }
        return $q->orderBy('name')->get(['id', 'name']);
    }

    /** One course per classroom of the grade level; existing ones are skipped. */
    public function courseBulk(object $term, int $subjectId, string $grade, ?int $teacherId, ?array $policy): array
    {
        $this->checkRefs(null, $teacherId, $subjectId);
        $policy = $this->policy($policy);
        $classes = $this->classroomsOfGrade($grade);
        if ($classes->isEmpty()) $this->fail('grade_level', 'ไม่พบห้องเรียนของชั้นนี้');
        $have = DB::table('asm_courses')->where('term_id', $term->id)->where('subject_id', $subjectId)->pluck('classroom_id')->map(fn ($v) => (int) $v)->all();
        $todo = $classes->filter(fn ($k) => !in_array((int) $k->id, $have, true));
        $this->insertCourses($term, $todo->map(fn ($k) => ['subject_id' => $subjectId, 'classroom_id' => (int) $k->id, 'teacher_id' => $teacherId, 'policy' => $policy])->all(), 'assessment.course.bulk_created', ['grade_level' => $grade, 'subject_id' => $subjectId]);
        return ['created' => $todo->count(), 'skipped' => $classes->count() - $todo->count()];
    }

    /** Copies subject + classroom + teacher + policy of a previous term; scores are never copied. */
    public function courseCopy(object $from, object $to): array
    {
        if ((int) $from->id === (int) $to->id) $this->fail('from_term_id', 'ภาคเรียนต้นทางและปลายทางต้องต่างกัน');
        $source = DB::table('asm_courses as c')->join('asm_subjects as s', 's.id', '=', 'c.subject_id')->join('classrooms as k', 'k.id', '=', 'c.classroom_id')
            ->where('c.school_id', $this->school())->where('c.term_id', $from->id)->where('s.is_active', true)
            ->when($this->has('classrooms', 'is_active'), fn ($q) => $q->where('k.is_active', true))
            ->get(['c.subject_id', 'c.classroom_id', 'c.teacher_id', 'c.policy']);
        $have = DB::table('asm_courses')->where('term_id', $to->id)->get(['subject_id', 'classroom_id'])->map(fn ($r) => $r->subject_id.'|'.$r->classroom_id)->all();
        $teachers = DB::table('teachers')->where('school_id', $this->school())->when($this->has('teachers', 'is_active'), fn ($q) => $q->where('is_active', true))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $todo = $source->filter(fn ($r) => !in_array($r->subject_id.'|'.$r->classroom_id, $have, true));
        $this->insertCourses($to, $todo->map(fn ($r) => [
            'subject_id' => (int) $r->subject_id, 'classroom_id' => (int) $r->classroom_id,
            'teacher_id' => $r->teacher_id && in_array((int) $r->teacher_id, $teachers, true) ? (int) $r->teacher_id : null,
            'policy' => json_decode((string) $r->policy, true),
        ])->all(), 'assessment.course.copied', ['from_term_id' => (int) $from->id, 'to_term_id' => (int) $to->id]);
        return ['created' => $todo->count(), 'skipped' => $source->count() - $todo->count()];
    }

    private function insertCourses(object $term, array $items, string $action, array $detail): void
    {
        if (!$items) return;
        $this->guard(fn () => DB::transaction(function () use ($term, $items, $action, $detail) {
            $now = now();
            $snaps = [];
            foreach (array_chunk($items, 100) as $chunk) {
                DB::table('asm_courses')->insert(array_map(function ($i) use ($now, $term, &$snaps) {
                    $snaps[$i['subject_id']] ??= $this->subjectSnapshot((int) $i['subject_id']);
                    return [
                        'school_id' => $this->school(), 'term_id' => (int) $term->id, 'subject_id' => $i['subject_id'], 'classroom_id' => $i['classroom_id'],
                        'teacher_id' => $i['teacher_id'], 'policy' => json_encode($i['policy'], JSON_UNESCAPED_UNICODE), 'status' => 'open', 'version' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ] + $snaps[$i['subject_id']];
                }, $chunk));
            }
            Mutation::audit($action, 'asm_courses', null, $detail + ['count' => count($items), 'term_id' => (int) $term->id]);
        }), 'มีรายวิชาซ้ำในภาคเรียนนี้ กรุณาโหลดใหม่');
    }

    // ---- scores ----------------------------------------------------------------------------------------------

    /**
     * Roster of a course (also the set of students a write may touch).
     * - course of the current term: the classroom's studying students plus every student that already has rows in this
     *   course, so a student who moved or left stays visible and correctable (in_class = false, shown as a badge);
     * - course of a past term: only students that already have rows in it. Classrooms persist across years, so today's
     *   class list is another cohort and must never leak into (or take writes for) an old term (OPS-001).
     * @return array<int,array{id:int,code:string,name:string,in_class:bool}>
     */
    private function classStudents(object $course, ?int $currentTermId): array
    {
        $cur = (int) $course->term_id === (int) $currentTermId;
        $hasStatus = $this->has('students', 'status');
        $q = DB::table('students')->where('school_id', $this->school())->where(function ($w) use ($course, $cur, $hasStatus) {
            if ($cur) {
                $w->orWhere(function ($c) use ($course, $hasStatus) {
                    $c->where('classroom_id', $course->classroom_id);
                    if ($hasStatus) $c->where('status', 'studying');
                });
            }
            $w->orWhereIn('id', DB::table('asm_scores')->where('course_id', $course->id)->select('student_id'))
                ->orWhereIn('id', DB::table('asm_attributes')->where('course_id', $course->id)->select('student_id'));
        });
        if ($this->has('students', 'roll_number')) $q->orderByRaw('roll_number is null')->orderBy('roll_number');
        $cols = ['id', 'code', 'name', 'classroom_id'];
        if ($hasStatus) $cols[] = 'status';
        return $q->orderBy('code')->get($cols)->map(fn ($s) => [
            'id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name,
            'in_class' => !$cur || ((int) $s->classroom_id === (int) $course->classroom_id && (!$hasStatus || $s->status === 'studying')),
        ])->all();
    }

    /** Past-term courses are read-only for teachers; admin/principal may correct them but must give a reason. */
    private function guardTerm(object $course, ?int $currentTermId, ?string $reason, bool $needReason): void
    {
        if ((int) $course->term_id === (int) $currentTermId) return;
        if (!$this->isManager()) abort(423, 'ภาคเรียนนี้ผ่านไปแล้ว แก้คะแนนต้องให้ผู้ดูแลเปิด');
        if ($needReason && trim((string) $reason) === '') $this->fail('reason', 'ภาคเรียนที่ผ่านมา ต้องระบุเหตุผลที่แก้');
    }

    /** Cleared cells keep their row (score_centi null, version kept) so the client always has a version to compare. */
    private function scoreRows(int $courseId): array
    {
        return DB::table('asm_scores')->where('course_id', $courseId)->orderBy('student_id')->orderBy('id')
            ->get(['student_id', 'component_key', 'score_centi', 'version', 'updated_at'])
            ->map(fn ($r) => ['student_id' => (int) $r->student_id, 'component_key' => $r->component_key, 'score_centi' => $r->score_centi === null ? null : (int) $r->score_centi, 'version' => (int) $r->version, 'updated_at' => (string) $r->updated_at])->all();
    }

    private function totals(array $policy, array $studentIds, array $scores): array
    {
        $by = [];
        foreach ($scores as $s) $by[$s['student_id']][$s['component_key']] = $s['score_centi'];
        $out = [];
        foreach ($studentIds as $id) $out[] = ['student_id' => $id] + GradePolicy::compute($policy, $by[$id] ?? []);
        return $out;
    }

    public function read(int $id): array
    {
        $course = $this->course($id);
        $current = TermScope::currentId();
        $students = $this->classStudents($course, $current);
        $scores = $this->scoreRows($id);
        $p = $this->present($course);
        return ['course' => $p, 'current_term_id' => $current, 'students' => $students, 'scores' => $scores, 'totals' => $this->totals($p['policy'], array_column($students, 'id'), $scores)];
    }

    /**
     * @param array<int,array{student_id:int,component_key:string,score:mixed,version:int}> $cells
     * score = null clears the cell; version = the cell version the editor saw (0 = it saw no row).
     */
    public function saveScores(int $id, array $cells, ?string $reason): array
    {
        $course = $this->course($id);
        if ($course->status !== 'open') abort(423, 'รายวิชานี้ปิดผลแล้ว แก้คะแนนไม่ได้ (ผู้ดูแลต้องเปิดรายวิชาก่อน)');
        $current = TermScope::currentId();
        $this->guardTerm($course, $current, $reason, true);
        $policy = $this->present($course)['policy'];
        $max = array_column($policy['components'], 'max', 'key');
        $students = $this->classStudents($course, $current);
        $studentIds = array_column($students, 'id');
        $valid = array_flip($studentIds);

        $errors = [];
        $seen = [];
        $plan = [];
        foreach ($cells as $i => $c) {
            $sid = (int) $c['student_id'];
            $key = (string) $c['component_key'];
            if (!isset($valid[$sid])) { $errors["cells.$i.student_id"] = 'นักเรียนไม่ได้อยู่ในห้องของรายวิชานี้'; continue; }
            if (!isset($max[$key])) { $errors["cells.$i.component_key"] = 'ไม่มีองค์ประกอบคะแนนนี้ในรายวิชา'; continue; }
            if (isset($seen[$sid.'|'.$key])) { $errors["cells.$i.component_key"] = 'ช่องคะแนนซ้ำในคำขอเดียวกัน'; continue; }
            $seen[$sid.'|'.$key] = true;
            // a missing score key is a client bug, not "clear this cell": clearing must say score = null explicitly
            if (!array_key_exists('score', $c)) { $errors["cells.$i.score"] = 'ต้องส่งค่า score ทุกช่อง (ส่ง null เพื่อล้างช่อง)'; continue; }
            try { $centi = $c['score'] === null ? null : GradePolicy::parseCenti($c['score'], (int) $max[$key]); }
            catch (InvalidArgumentException $e) { $errors["cells.$i.score"] = $e->getMessage(); continue; }
            $plan[] = ['student_id' => $sid, 'key' => $key, 'centi' => $centi, 'version' => (int) $c['version']];
        }
        if ($errors) throw ValidationException::withMessages($errors);

        $actor = auth()->id();
        $now = now();
        $saved = DB::transaction(function () use ($id, $plan, $reason, $actor, $now, $course) {
            // the course row lock serialises every writer of this course; versions are then compared in memory
            $locked = DB::table('asm_courses')->where('id', $id)->where('school_id', $this->school())->lockForUpdate()->first(['status', 'version', 'policy']);
            if (!$locked) abort(404, 'ไม่พบรายวิชา');
            if ($locked->status !== 'open') abort(423, 'รายวิชานี้ปิดผลแล้ว แก้คะแนนไม่ได้ (ผู้ดูแลต้องเปิดรายวิชาก่อน)');
            // cells were validated against the policy read before the lock: a course edited meanwhile must be reloaded
            if ((int) $locked->version !== (int) $course->version || json_decode((string) $locked->policy, true) !== json_decode((string) $course->policy, true)) {
                abort(409, 'รายวิชานี้ถูกแก้ไขระหว่างที่กำลังบันทึก กรุณาโหลดใหม่แล้วบันทึกอีกครั้ง');
            }
            $existing = [];
            foreach (DB::table('asm_scores')->where('course_id', $id)->get(['id', 'student_id', 'component_key', 'score_centi', 'version']) as $r) $existing[$r->student_id.'|'.$r->component_key] = $r;

            $conflicts = [];
            $upserts = [];
            $revisions = []; // [student_id, key, old, new, score_id|null]
            foreach ($plan as $p) {
                $e = $existing[$p['student_id'].'|'.$p['key']] ?? null;
                if ((int) ($e->version ?? 0) !== $p['version']) {
                    $conflicts[] = ['student_id' => $p['student_id'], 'component_key' => $p['key'], 'score_centi' => $e && $e->score_centi !== null ? (int) $e->score_centi : null, 'version' => (int) ($e->version ?? 0)];
                    continue;
                }
                $old = $e && $e->score_centi !== null ? (int) $e->score_centi : null;
                if ($old === $p['centi']) continue; // unchanged, or clearing a cell that is already empty
                // clearing writes null and bumps the version; the row is never deleted, so a stale editor can never match again (no ABA)
                $upserts[] = ['school_id' => $this->school(), 'course_id' => $id, 'student_id' => $p['student_id'], 'component_key' => $p['key'],
                    'score_centi' => $p['centi'], 'version' => $e ? (int) $e->version + 1 : 1, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now];
                $revisions[] = [$p['student_id'], $p['key'], $old, $p['centi'], $e ? (int) $e->id : null];
            }
            if ($conflicts) throw new VersionConflict(['conflicts' => $conflicts]);

            foreach (array_chunk($upserts, self::CHUNK) as $chunk) {
                DB::table('asm_scores')->upsert($chunk, ['course_id', 'student_id', 'component_key'], ['score_centi', 'version', 'updated_by', 'updated_at']);
            }
            $fresh = $this->scoreRows($id);
            if ($revisions) {
                $ids = [];
                if (array_filter($revisions, fn ($r) => $r[4] === null)) {
                    foreach (DB::table('asm_scores')->where('course_id', $id)->get(['id', 'student_id', 'component_key']) as $r) $ids[$r->student_id.'|'.$r->component_key] = (int) $r->id;
                }
                $rows = array_map(fn ($r) => ['school_id' => $this->school(), 'score_id' => $r[4] ?? $ids[$r[0].'|'.$r[1]], 'course_id' => $id, 'student_id' => $r[0], 'component_key' => $r[1],
                    'old_centi' => $r[2], 'new_centi' => $r[3], 'reason' => $reason, 'changed_by' => $actor, 'created_at' => $now], $revisions);
                foreach (array_chunk($rows, self::CHUNK) as $chunk) DB::table('asm_score_revisions')->insert($chunk);
            }
            if ($revisions) Mutation::audit('assessment.scores.saved', 'asm_courses', $id, ['changed' => count($revisions), 'reason' => $reason]);
            return ['changed' => count($revisions), 'scores' => $fresh];
        });

        return ['saved' => $saved['changed'], 'saved_at' => $now->toIso8601String(), 'scores' => $saved['scores'],
            'totals' => $this->totals($policy, $studentIds, $saved['scores'])];
    }

    public function revisions(int $id, ?int $studentId, ?string $key): array
    {
        $this->course($id);
        $rows = DB::table('asm_score_revisions as r')->leftJoin('users as u', 'u.id', '=', 'r.changed_by')
            ->where('r.school_id', $this->school())->where('r.course_id', $id)
            ->when($studentId, fn ($q) => $q->where('r.student_id', $studentId))->when($key, fn ($q) => $q->where('r.component_key', $key))
            ->orderByDesc('r.id')->limit(200)->get(['r.id', 'r.student_id', 'r.component_key', 'r.old_centi', 'r.new_centi', 'r.reason', 'r.created_at', 'u.name as changed_by_name']);
        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'student_id' => (int) $r->student_id, 'component_key' => $r->component_key,
            'old_centi' => $r->old_centi === null ? null : (int) $r->old_centi, 'new_centi' => $r->new_centi === null ? null : (int) $r->new_centi,
            'reason' => $r->reason, 'changed_by' => $r->changed_by_name, 'created_at' => (string) $r->created_at])->all();
    }

    // ---- attributes (desirable characteristics / competencies / reading-thinking-writing) -----------------------------

    private static function attrRow(object $r): array
    {
        return ['student_id' => (int) $r->student_id, 'kind' => $r->kind, 'item_code' => $r->item_code, 'level' => (int) $r->level, 'version' => (int) $r->version];
    }

    public function attributes(int $id, ?string $kind): array
    {
        $this->course($id);
        return DB::table('asm_attributes')->where('course_id', $id)->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('student_id')->orderBy('kind')->orderBy('item_code')->get(['student_id', 'kind', 'item_code', 'level', 'version'])
            ->map(fn ($r) => self::attrRow($r))->all();
    }

    /** Validates the cells against a roster; returns the error map (empty = ok). */
    private function attributeErrors(array $cells, array $valid): array
    {
        $errors = [];
        $seen = [];
        foreach ($cells as $i => $c) {
            if (!isset($valid[(int) $c['student_id']])) $errors["cells.$i.student_id"] = 'นักเรียนไม่ได้อยู่ในห้องของรายวิชานี้';
            $k = $c['student_id'].'|'.$c['kind'].'|'.$c['item_code'];
            if (isset($seen[$k])) $errors["cells.$i.item_code"] = 'ช่องซ้ำในคำขอเดียวกัน';
            $seen[$k] = true;
        }
        return $errors;
    }

    /** Compare-and-swap upsert of attribute cells; runs inside the caller's transaction, after the caller took its lock. @return int rows changed */
    private function upsertAttributes(int $termId, int $courseId, array $cells, array $studentIds, $actor, $now): int
    {
        $existing = [];
        foreach (DB::table('asm_attributes')->where('term_id', $termId)->where('course_id', $courseId)->whereIn('student_id', $studentIds ?: [0])
            ->get(['student_id', 'kind', 'item_code', 'level', 'version']) as $r) $existing[$r->student_id.'|'.$r->kind.'|'.$r->item_code] = $r;
        $conflicts = [];
        $rows = [];
        foreach ($cells as $c) {
            $e = $existing[$c['student_id'].'|'.$c['kind'].'|'.$c['item_code']] ?? null;
            if ((int) ($e->version ?? 0) !== (int) $c['version']) { $conflicts[] = ['student_id' => (int) $c['student_id'], 'kind' => $c['kind'], 'item_code' => $c['item_code'], 'level' => $e ? (int) $e->level : null, 'version' => (int) ($e->version ?? 0)]; continue; }
            if ($e && (int) $e->level === (int) $c['level']) continue;
            $rows[] = ['school_id' => $this->school(), 'term_id' => $termId, 'course_id' => $courseId, 'student_id' => (int) $c['student_id'], 'kind' => $c['kind'],
                'item_code' => $c['item_code'], 'level' => (int) $c['level'], 'version' => $e ? (int) $e->version + 1 : 1, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($conflicts) throw new VersionConflict(['conflicts' => $conflicts]);
        foreach (array_chunk($rows, 200) as $chunk) DB::table('asm_attributes')->upsert($chunk, ['term_id', 'course_id', 'student_id', 'kind', 'item_code'], ['level', 'version', 'updated_by', 'updated_at']);
        return count($rows);
    }

    public function saveAttributes(int $id, array $cells): array
    {
        $course = $this->course($id);
        if ($course->status !== 'open') abort(423, 'รายวิชานี้ปิดผลแล้ว แก้ข้อมูลไม่ได้');
        $current = TermScope::currentId();
        $this->guardTerm($course, $current, null, false);
        $students = $this->classStudents($course, $current);
        $errors = $this->attributeErrors($cells, array_flip(array_column($students, 'id')));
        if ($errors) throw ValidationException::withMessages($errors);
        $actor = auth()->id();
        $now = now();
        DB::transaction(function () use ($id, $course, $cells, $students, $actor, $now) {
            // same lock and same status re-check as saveScores: a course closed a moment ago takes no more writes
            $locked = DB::table('asm_courses')->where('id', $id)->where('school_id', $this->school())->lockForUpdate()->first(['status']);
            if (!$locked) abort(404, 'ไม่พบรายวิชา');
            if ($locked->status !== 'open') abort(423, 'รายวิชานี้ปิดผลแล้ว แก้ข้อมูลไม่ได้');
            $changed = $this->upsertAttributes((int) $course->term_id, $id, $cells, array_column($students, 'id'), $actor, $now);
            if ($changed) Mutation::audit('assessment.attributes.saved', 'asm_courses', $id, ['changed' => $changed]);
        });
        return ['saved_at' => $now->toIso8601String(), 'attributes' => $this->attributes($id, null)];
    }

    /** Removes mistaken attribute cells (a wrong item_code could never be taken back). Version-checked per cell, audited with counts only. */
    private function deleteAttributes(int $termId, int $courseId, array $cells, array $studentIds): int
    {
        $n = 0;
        foreach ($cells as $c) {
            $e = DB::table('asm_attributes')->where('term_id', $termId)->where('course_id', $courseId)->where('student_id', (int) $c['student_id'])->where('kind', $c['kind'])->where('item_code', $c['item_code'])->lockForUpdate()->first(['id', 'version']);
            if (!$e || !in_array((int) $c['student_id'], $studentIds, true)) abort(404, 'ไม่พบรายการที่จะลบ');
            if ((int) $e->version !== (int) $c['version']) throw new VersionConflict(['conflicts' => [['student_id' => (int) $c['student_id'], 'kind' => $c['kind'], 'item_code' => $c['item_code'], 'version' => (int) $e->version]]]);
            $n += DB::table('asm_attributes')->where('id', $e->id)->delete();
        }
        return $n;
    }

    public function deleteCourseAttributes(int $id, array $cells): array
    {
        $course = $this->course($id);
        if ($course->status !== 'open') abort(423, 'รายวิชานี้ปิดผลแล้ว แก้ข้อมูลไม่ได้');
        $current = TermScope::currentId();
        $this->guardTerm($course, $current, null, false);
        $ids = array_column($this->classStudents($course, $current), 'id');
        DB::transaction(function () use ($id, $course, $cells, $ids) {
            $locked = DB::table('asm_courses')->where('id', $id)->where('school_id', $this->school())->lockForUpdate()->first(['status']);
            if (!$locked) abort(404, 'ไม่พบรายวิชา');
            if ($locked->status !== 'open') abort(423, 'รายวิชานี้ปิดผลแล้ว แก้ข้อมูลไม่ได้');
            $n = $this->deleteAttributes((int) $course->term_id, $id, $cells, $ids);
            if ($n) Mutation::audit('assessment.attributes.deleted', 'asm_courses', $id, ['deleted' => $n]);
        });
        return ['attributes' => $this->attributes($id, null)];
    }

    // ---- term-level attributes (course_id = 0): assessed once per term by the homeroom teacher ---------------------

    /** Current term + a classroom the signed-in account may use: admin/principal any, teacher homeroom only. */
    private function termClassroom(int $classroomId): object
    {
        $user = $this->user();
        $term = $this->term();
        $room = DB::table('classrooms')->where('school_id', $this->school())->where('id', $classroomId)->first(['id', 'name']);
        abort_unless($room, 404, 'ไม่พบห้องเรียน');
        if ($user->role === 'teacher' && !in_array($classroomId, TeacherScope::homeroomIds($user) ?? [], true)) abort(403, 'คุณลักษณะประจำภาคเรียนบันทึกได้เฉพาะครูประจำชั้นของห้องนั้น');
        return (object) ['term' => $term, 'room' => $room];
    }

    private function termStudents(int $classroomId): array
    {
        return $this->studentQuery()->where('classroom_id', $classroomId)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name])->all();
    }

    private function termAttributeRows(int $termId, array $studentIds, ?string $kind): array
    {
        return DB::table('asm_attributes')->where('term_id', $termId)->where('course_id', 0)->whereIn('student_id', $studentIds ?: [0])->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('student_id')->orderBy('kind')->orderBy('item_code')->get(['student_id', 'kind', 'item_code', 'level', 'version'])->map(fn ($r) => self::attrRow($r))->all();
    }

    public function termAttributes(int $classroomId, ?string $kind): array
    {
        $ctx = $this->termClassroom($classroomId);
        $students = $this->termStudents($classroomId);
        return ['term' => self::termInfo($ctx->term), 'classroom' => ['id' => (int) $ctx->room->id, 'name' => $ctx->room->name], 'students' => $students,
            'data' => $this->termAttributeRows((int) $ctx->term->id, array_column($students, 'id'), $kind)];
    }

    public function deleteTermAttributes(int $classroomId, array $cells): array
    {
        $ctx = $this->termClassroom($classroomId);
        $ids = array_column($this->termStudents($classroomId), 'id');
        $termId = (int) $ctx->term->id;
        DB::transaction(function () use ($classroomId, $termId, $cells, $ids) {
            if (!DB::table('classrooms')->where('school_id', $this->school())->where('id', $classroomId)->lockForUpdate()->exists()) abort(404, 'ไม่พบห้องเรียน');
            $n = $this->deleteAttributes($termId, 0, $cells, $ids);
            if ($n) Mutation::audit('assessment.term_attributes.deleted', 'classrooms', $classroomId, ['deleted' => $n, 'term_id' => $termId]);
        });
        return ['term' => self::termInfo($ctx->term), 'data' => $this->termAttributeRows($termId, $ids, null)];
    }

    public function saveTermAttributes(int $classroomId, array $cells): array
    {
        $ctx = $this->termClassroom($classroomId);
        $students = $this->termStudents($classroomId);
        $ids = array_column($students, 'id');
        $errors = $this->attributeErrors($cells, array_flip($ids));
        if ($errors) throw ValidationException::withMessages($errors);
        $actor = auth()->id();
        $now = now();
        $termId = (int) $ctx->term->id;
        DB::transaction(function () use ($classroomId, $termId, $cells, $ids, $actor, $now) {
            // the classroom row is the mutex of this class's term-level cells
            if (!DB::table('classrooms')->where('school_id', $this->school())->where('id', $classroomId)->lockForUpdate()->exists()) abort(404, 'ไม่พบห้องเรียน');
            $changed = $this->upsertAttributes($termId, 0, $cells, $ids, $actor, $now);
            if ($changed) Mutation::audit('assessment.term_attributes.saved', 'classrooms', $classroomId, ['changed' => $changed, 'term_id' => $termId]);
        });
        return ['saved_at' => $now->toIso8601String(), 'term' => self::termInfo($ctx->term), 'data' => $this->termAttributeRows($termId, $ids, null)];
    }
}
