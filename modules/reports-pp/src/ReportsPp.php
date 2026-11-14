<?php

namespace Saan\Modules\ReportsPp;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use App\Support\Saan\VersionConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Data side of the ปพ. reports: access rules, roster, results, GPA, templates. Reads assessment/registry/academic tables
 * directly; writes only rpt_* tables.
 * Access: ปพ.5 and special results = the course teacher (asm_courses.teacher_id) or admin/principal;
 *         ปพ.6, class summary and remarks = homeroom teacher of the classroom or admin/principal.
 */
class ReportsPp
{
    public const KINDS = ['pp5', 'pp6', 'summary'];
    public const LEVELS = [0 => 'ไม่ผ่าน', 1 => 'ผ่าน', 2 => 'ดี', 3 => 'ดีเยี่ยม'];
    public const ATTR_KINDS = ['characteristic' => 'คุณลักษณะอันพึงประสงค์', 'competency' => 'สมรรถนะสำคัญของผู้เรียน', 'reading' => 'การอ่าน คิดวิเคราะห์ และเขียน'];
    public const TITLES = [
        'pp5' => 'แบบบันทึกผลการเรียนประจำรายวิชา (ปพ.5)',
        'pp6' => 'แบบรายงานผลการพัฒนาคุณภาพผู้เรียนรายบุคคล (ปพ.6)',
        'summary' => 'รายงานสรุปผลการเรียนรายห้อง',
    ];

    public function school(): int { return (int) config('school.school_id'); }

    private function has(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    public function user(): object
    {
        $u = auth()->user();
        abort_unless($u && in_array($u->role, ['admin', 'principal', 'teacher'], true), 403, 'บัญชีนี้ไม่มีสิทธิ์ใช้งานส่วนนี้');
        return $u;
    }

    public function isManager(): bool { return in_array($this->user()->role, ['admin', 'principal'], true); }

    public static function isUnique(QueryException $e): bool
    {
        return \App\Support\Saan\DbError::isUnique($e);

    }

    public function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    // ---- terms, classrooms, courses ------------------------------------------------------------------------------

    public function term(?int $id = null): object
    {
        if ($id !== null) {
            $row = DB::table('academic_terms')->where('school_id', $this->school())->where('id', $id)->first();
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

    public function classroom(int $id): object
    {
        $row = DB::table('classrooms')->where('school_id', $this->school())->where('id', $id)->first();
        abort_unless($row, 404, 'ไม่พบห้องเรียน');
        return $row;
    }

    /** Classrooms the account may report on as homeroom teacher; null = all. */
    public function homeroomIds(): ?array
    {
        return TeacherScope::homeroomIds($this->user());
    }

    public function assertHomeroom(int $classroomId): void
    {
        $ids = $this->homeroomIds();
        abort_unless($ids === null || in_array($classroomId, $ids, true), 403, 'รายงานนี้ดูได้เฉพาะครูประจำชั้นของห้องนั้น ผู้อำนวยการ และผู้ดูแลระบบ');
    }

    /**
     * Homeroom is only known for today (no homeroom history), so a teacher may read ปพ.6 / summary / remarks / CSV of the
     * current term only; a past term's cohort is another group of children. Managers may open any term.
     */
    public function assertTermAccess(object $term): void
    {
        abort_unless($this->isManager() || (int) $term->id === (int) TermScope::currentId(), 403, 'ภาคเรียนที่ผ่านมาดูรายงานนี้ได้เฉพาะผู้อำนวยการและผู้ดูแลระบบ');
    }

    private function courseQuery()
    {
        $snap = $this->has('asm_courses', 'snap_at');
        $q =DB::table('asm_courses as c')
            ->join('asm_subjects as s', 's.id', '=', 'c.subject_id')
            ->join('classrooms as k', 'k.id', '=', 'c.classroom_id')
            ->join('academic_terms as t', 't.id', '=', 'c.term_id')
            ->leftJoin('teachers as th', 'th.id', '=', 'c.teacher_id')
            ->where('c.school_id', $this->school())
            // code, name and credit come from the snapshot taken when the course was created (snap_at), so editing a subject never rewrites a reported term
            ->select('c.*', DB::raw($snap ? 'case when c.snap_at is null then s.code else c.subject_code_snap end as subject_code' : 's.code as subject_code'),
                DB::raw($snap ? 'case when c.snap_at is null then s.name else c.subject_name_snap end as subject_name' : 's.name as subject_name'), 's.group_name',
                DB::raw($snap ? 'case when c.snap_at is null then s.credit else c.credit_snap end as credit' : 's.credit as credit'), 's.hours_per_year',
                'k.name as classroom_name', 't.year as term_year', 't.term as term_no', 't.starts_on', 't.ends_on', 'th.name as teacher_name');
        return $q;
    }

    /** One course for ปพ.5: 404 when missing, 403 when it belongs to another teacher. */
    public function course(int $id): object
    {
        $user = $this->user();
        $row = $this->courseQuery()->where('c.id', $id)->first();
        abort_unless($row, 404, 'ไม่พบรายวิชา');
        if ($user->role === 'teacher') {
            abort_unless($user->teacher_id && (int) $row->teacher_id === (int) $user->teacher_id, 403, 'รายวิชานี้ไม่ได้มอบหมายให้บัญชีของคุณ');
        }
        return $row;
    }

    /** Courses of a classroom in a term (no teacher restriction: homeroom/admin report). */
    public function classroomCourses(int $classroomId, int $termId)
    {
        return $this->courseQuery()->where('c.term_id', $termId)->where('c.classroom_id', $classroomId)
            ->orderBy('s.group_name')->orderBy('s.code')->get();
    }

    public function listCourses(object $term): array
    {
        $q = $this->courseQuery()->where('c.term_id', $term->id)->orderBy('k.name')->orderBy('s.code');
        $u = $this->user();
        if ($u->role === 'teacher') {
            if ($u->teacher_id) $q->where('c.teacher_id', (int) $u->teacher_id); else $q->whereRaw('1 = 0');
        }
        return $q->get()->map(fn ($c) => [
            'id' => (int) $c->id, 'status' => $c->status,
            'subject' => ['code' => $c->subject_code, 'name' => $c->subject_name, 'credit' => $c->credit === null ? null : (float) $c->credit],
            'classroom' => ['id' => (int) $c->classroom_id, 'name' => $c->classroom_name],
            'teacher' => $c->teacher_id ? ['id' => (int) $c->teacher_id, 'name' => $c->teacher_name] : null,
        ])->all();
    }

    public function listClassrooms(object $term): array
    {
        $ids = $this->homeroomIds();
        $q = DB::table('classrooms')->where('school_id', $this->school())->orderBy('name');
        if ($ids !== null) $q->whereIn('id', $ids ?: [0]);
        if ($this->has('classrooms', 'is_active')) $q->where(fn ($w) => $w->where('is_active', true)->orWhereIn('id', DB::table('asm_courses')->where('term_id', $term->id)->select('classroom_id')));
        return $q->get(['id', 'name'])->map(fn ($k) => ['id' => (int) $k->id, 'name' => $k->name])->all();
    }

    // ---- rosters -------------------------------------------------------------------------------------------------

    private function studentCols(): array
    {
        $cols = ['id', 'code', 'name', 'classroom_id'];
        foreach (['status', 'roll_number'] as $c) if ($this->has('students', $c)) $cols[] = $c;
        return $cols;
    }

    private function studentOrder($q)
    {
        if ($this->has('students', 'roll_number')) $q->orderByRaw('roll_number is null')->orderBy('roll_number');
        return $q->orderBy('code');
    }

    private function presentStudent(object $s): array
    {
        return ['id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name, 'classroom_id' => (int) $s->classroom_id,
            'roll' => isset($s->roll_number) && $s->roll_number !== null ? (int) $s->roll_number : null];
    }

    /**
     * Students of a classroom for a term. Current term: the classroom's studying students plus anybody with rows in the
     * given courses. Past term: only students with rows (today's class list is another cohort).
     * $strict (ปพ.6, summary, remarks): current term = the classroom's own studying students only; past term = students whose
     * dominant classroom of that term is this one. Non-strict (ปพ.5, special results) lists everybody with rows in the course.
     * @param int[] $courseIds
     */
    public function roster(int $classroomId, object $term, array $courseIds, bool $strict = false): array
    {
        $cur = (int) $term->id === (int) TermScope::currentId();
        if ($strict && $cur) $courseIds = [];
        $hasStatus = $this->has('students', 'status');
        $q = DB::table('students')->where('school_id', $this->school())->where(function ($w) use ($cur, $classroomId, $hasStatus, $courseIds) {
            if ($cur) {
                $w->orWhere(function ($c) use ($classroomId, $hasStatus) {
                    $c->where('classroom_id', $classroomId);
                    if ($hasStatus) $c->where('status', 'studying');
                });
            }
            if ($courseIds) {
                $w->orWhereIn('id', DB::table('asm_scores')->whereIn('course_id', $courseIds)->select('student_id'))
                    ->orWhereIn('id', DB::table('rpt_special_results')->whereIn('course_id', $courseIds)->whereNotNull('code')->select('student_id'));
            }
            if (!$cur && !$courseIds) $w->whereRaw('1 = 0');
        });
        $list = $this->studentOrder($q)->get($this->studentCols())->map(fn ($s) => $this->presentStudent($s))->all();
        if ($strict && !$cur) {
            $rooms = $this->dominantRooms(array_column($list, 'id'), (int) $term->id);
            $list = array_values(array_filter($list, fn ($s) => ($rooms[$s['id']] ?? null) === $classroomId));
        }
        return $list;
    }

    /**
     * The classroom a student belonged to in a term, judged from the courses they have rows in (scores or special results):
     * most courses wins, ties go to the lowest classroom id. Students without rows are absent from the result.
     * @param int[] $studentIds
     * @return array<int,int> student id => classroom id
     */
    public function dominantRooms(array $studentIds, int $termId): array
    {
        $count = [];
        foreach (array_chunk(array_values(array_unique($studentIds)), 400) as $chunk) {
            $q1 = DB::table('asm_scores as r')->join('asm_courses as c', 'c.id', '=', 'r.course_id')
                ->where('c.school_id', $this->school())->where('c.term_id', $termId)->whereIn('r.student_id', $chunk)->select('r.student_id', 'c.classroom_id', 'c.id as cid');
            $q2 = DB::table('rpt_special_results as r')->join('asm_courses as c', 'c.id', '=', 'r.course_id')
                ->where('c.school_id', $this->school())->where('c.term_id', $termId)->whereNotNull('r.code')->whereIn('r.student_id', $chunk)->select('r.student_id', 'c.classroom_id', 'c.id as cid');
            foreach ($q1->union($q2)->get() as $r) $count[(int) $r->student_id][(int) $r->classroom_id][(int) $r->cid] = true;
        }
        $out = [];
        foreach ($count as $sid => $rooms) {
            $best = null;
            foreach ($rooms as $room => $cids) {
                $n = count($cids);
                if ($best === null || $n > $best[1] || ($n === $best[1] && $room < $best[0])) $best = [$room, $n];
            }
            $out[$sid] = $best[0];
        }
        return $out;
    }

    /** Classroom of each student in the term: today's classroom for the current term, else the dominant classroom of that term (falling back to today's). */
    private function termRooms(array $students, object $term): array
    {
        $cur = (int) $term->id === (int) TermScope::currentId();
        $dom = $cur ? [] : $this->dominantRooms(array_column($students, 'id'), (int) $term->id);
        $out = [];
        foreach ($students as $s) $out[$s['id']] = $cur ? (int) $s['classroom_id'] : ($dom[$s['id']] ?? (int) $s['classroom_id']);
        return $out;
    }

    // ---- computation ---------------------------------------------------------------------------------------------

    /**
     * Results of students over courses.
     * @param object[] $courses rows from courseQuery()
     * @param array[] $students presentStudent() rows
     * @return array{results:array<int,array<int,array>>,gpa:array<int,array>,courses:array<int,array>}
     */
    public function compute(iterable $courses, array $students): array
    {
        $courses = collect($courses)->values();
        $cids = $courses->pluck('id')->map(fn ($v) => (int) $v)->all();
        $sids = array_column($students, 'id');
        $centi = [];
        $special = [];
        if ($cids && $sids) {
            foreach (array_chunk($sids, 400) as $chunk) {
                foreach (DB::table('asm_scores')->whereIn('course_id', $cids)->whereIn('student_id', $chunk)->get(['course_id', 'student_id', 'component_key', 'score_centi']) as $r) {
                    $centi[(int) $r->course_id][(int) $r->student_id][$r->component_key] = $r->score_centi === null ? null : (int) $r->score_centi;
                }
                foreach (DB::table('rpt_special_results')->where('school_id', $this->school())->whereIn('course_id', $cids)->whereIn('student_id', $chunk)->whereNotNull('code')->get(['course_id', 'student_id', 'code', 'note']) as $r) {
                    $special[(int) $r->course_id][(int) $r->student_id] = ['code' => $r->code, 'note' => $r->note];
                }
            }
        }
        $policies = [];
        $info = [];
        foreach ($courses as $c) {
            $p = json_decode((string) $c->policy, true);
            $policies[(int) $c->id] = \Saan\Modules\Assessment\GradePolicy::normalize(is_array($p) ? $p : null);
            $info[(int) $c->id] = ['id' => (int) $c->id, 'code' => $c->subject_code, 'name' => $c->subject_name, 'group_name' => $c->group_name,
                'credit_tenths' => Calc::creditTenths($c->credit), 'classroom' => $c->classroom_name, 'teacher' => $c->teacher_name, 'policy' => $policies[(int) $c->id]];
        }
        $results = [];
        $gpa = [];
        $gpaMode = $this->settings()['gpa_rounding'];
        foreach ($students as $s) {
            $sid = $s['id'];
            $items = [];
            foreach ($cids as $cid) {
                $res = Calc::result($policies[$cid], $centi[$cid][$sid] ?? [], $special[$cid][$sid] ?? null);
                $res['note'] = $special[$cid][$sid]['note'] ?? null;
                $res['centi'] = $centi[$cid][$sid] ?? [];
                $results[$sid][$cid] = $res;
                $items[] = ['credit' => $info[$cid]['credit_tenths'], 'grade' => $res['state'] === 'graded' ? $res['grade'] : null];
            }
            $gpa[$sid] = Calc::gpa($items, $gpaMode);
        }
        return ['results' => $results, 'gpa' => $gpa, 'courses' => $info];
    }

    /** @var array<int,string> student id => warning set by attendance() */
    public array $attendanceWarnings = [];

    /** @return array<int,array<string,int>> student id => status => days */
    public function attendance(array $studentIds, object $term, array $roomOf = []): ?array
    {
        $this->attendanceWarnings = [];
        if (!$studentIds || !Schema::hasTable('attendances') || !$this->has('attendances', 'status') || !$this->has('attendances', 'date')) return null;
        $from = substr((string) $term->starts_on, 0, 10);
        $to = substr((string) $term->ends_on, 0, 10);
        // Same rule as Attendance::monthly: a confirmed classroom day (not weekend/holiday) without an exception row = present.
        $off = [];
        if (Schema::hasTable('acad_calendar_days')) {
            $off = DB::table('acad_calendar_days')->where('school_id', $this->school())->whereBetween('date', [$from, $to])->whereIn('kind', ['holiday', 'no_class'])
                ->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->flip()->all();
        }
        $confirmedByRoom = [];
        // Room history (student_classroom_moves) attributes each date to the room the student was in then; only moves after the term start matter.
        $moveOf = [];
        if (Schema::hasTable('student_classroom_moves')) {
            foreach (array_chunk($studentIds, 400) as $chunk) {
                foreach (DB::table('student_classroom_moves')->where('school_id', $this->school())->whereIn('student_id', $chunk)->where('effective_on', '>', $from)
                    ->orderBy('effective_on')->orderBy('id')->get(['student_id', 'from_classroom_id', 'to_classroom_id', 'effective_on']) as $m) {
                    $moveOf[(int) $m->student_id][] = ['on' => substr((string) $m->effective_on, 0, 10), 'from' => $m->from_classroom_id === null ? null : (int) $m->from_classroom_id,
                        'to' => $m->to_classroom_id === null ? null : (int) $m->to_classroom_id];
                }
            }
        }
        $rooms = array_values(array_unique(array_filter(array_merge(array_map(fn ($id) => (int) ($roomOf[$id] ?? 0), $studentIds),
            ...array_map(fn ($ms) => array_merge(array_column($ms, 'from'), array_column($ms, 'to')), array_values($moveOf))))));
        if ($rooms && Schema::hasTable('att_class_days')) {
            $rows = DB::table('att_class_days')->where('school_id', $this->school())->whereIn('classroom_id', $rooms)->whereBetween('date', [$from, $to])
                ->whereNotNull('confirmed_at')->get(['classroom_id', 'date']);
            foreach ($rows as $r) {
                $d = substr((string) $r->date, 0, 10);
                if (isset($off[$d]) || \Carbon\CarbonImmutable::parse($d)->isWeekend()) continue;
                $confirmedByRoom[(int) $r->classroom_id][$d] = true;
            }
        }
        $out = [];
        foreach (array_chunk($studentIds, 400) as $chunk) {
            $by = [];
            foreach (DB::table('attendances')->where('school_id', $this->school())->whereIn('student_id', $chunk)->whereBetween('date', [$from, $to])->get(['student_id', 'date', 'status']) as $r) {
                $by[(int) $r->student_id][substr((string) $r->date, 0, 10)] = $r->status;
            }
            // Without a room history only the student's own admission date bounds the implicit days: no implicit 'present' before admission.
            $admitted = $this->has('students', 'admitted_on')
                ? DB::table('students')->whereIn('id', $chunk)->whereNotNull('admitted_on')->pluck('admitted_on', 'id')->map(fn ($d) => substr((string) $d, 0, 10))->all() : [];
            // No room history yet: a student who also has scores in a different classroom this term probably moved rooms, so an implicit 'present'
            // from the final room would count days spent elsewhere. Only recorded marks are counted for them and the page gets a warning.
            $moved = [];
            if (Schema::hasTable('asm_scores') && Schema::hasTable('asm_courses')) {
                foreach (DB::table('asm_scores as sc')->join('asm_courses as c', 'c.id', '=', 'sc.course_id')->where('c.term_id', $term->id)
                    ->whereIn('sc.student_id', $chunk)->distinct()->get(['sc.student_id', 'c.classroom_id']) as $r) {
                    if ((int) $r->classroom_id !== (int) ($roomOf[(int) $r->student_id] ?? 0)) $moved[(int) $r->student_id] = true;
                }
            }
            foreach ($chunk as $sid) {
                $marks = $by[$sid] ?? [];
                $since = $admitted[$sid] ?? null;
                if (isset($moveOf[$sid])) {
                    // Known room history: an implicit present only counts on days confirmed by the room the student was in on that date.
                    foreach ($confirmedByRoom as $room => $days) foreach (array_keys($days) as $d) {
                        if (!isset($marks[$d]) && ($since === null || $d >= $since)
                            && \App\Support\Saan\ClassroomMoves::roomOn($moveOf[$sid], isset($roomOf[$sid]) ? (int) $roomOf[$sid] : null, $d) === (int) $room) $marks[$d] = 'present';
                    }
                } elseif (isset($moved[$sid])) {
                    $this->attendanceWarnings[$sid] = 'นักเรียนมีข้อมูลคะแนนในห้องอื่นของภาคเรียนนี้ (อาจย้ายห้อง) จึงนับเฉพาะวันที่บันทึกไว้ ไม่เติมมาเรียนอัตโนมัติ ต้องตรวจสอบเวลาเรียน';
                } else {
                    foreach (array_keys($confirmedByRoom[(int) ($roomOf[$sid] ?? 0)] ?? []) as $d) if (!isset($marks[$d]) && ($since === null || $d >= $since)) $marks[$d] = 'present';
                }
                foreach ($marks as $st) $out[$sid][$st] = ($out[$sid][$st] ?? 0) + 1;
            }
        }
        return $out;
    }

    /** @return array<int,array<string,array<int,array{code:string,level:int}>>> */
    public function termAttributes(array $studentIds, int $termId, int $courseId = 0): array
    {
        $out = [];
        if (!$studentIds) return $out;
        foreach (array_chunk($studentIds, 400) as $chunk) {
            foreach (DB::table('asm_attributes')->where('school_id', $this->school())->where('term_id', $termId)->where('course_id', $courseId)->whereIn('student_id', $chunk)
                ->orderBy('kind')->orderBy('item_code')->get(['student_id', 'kind', 'item_code', 'level']) as $r) {
                $out[(int) $r->student_id][$r->kind][] = ['code' => $r->item_code, 'level' => (int) $r->level];
            }
        }
        return $out;
    }

    public function remarks(array $studentIds, int $termId): array
    {
        if (!$studentIds) return [];
        $out = [];
        foreach (array_chunk($studentIds, 400) as $chunk) {
            foreach (DB::table('rpt_remarks')->where('school_id', $this->school())->where('term_id', $termId)->whereIn('student_id', $chunk)->get(['student_id', 'remark', 'version']) as $r) {
                $out[(int) $r->student_id] = ['remark' => $r->remark, 'version' => (int) $r->version];
            }
        }
        return $out;
    }

    // ---- page data -----------------------------------------------------------------------------------------------

    public function school_header(): array
    {
        $name = (string) config('saan.display', 'โรงเรียน');
        $logo = null;
        try {
            $theme = app(\App\Domain\CmsPublishing::class)->theme($this->school())['published'] ?? [];
            if (!empty($theme['school_name'])) $name = (string) $theme['school_name'];
            if (!empty($theme['logo_media_id'])) $logo = url('/media/'.(int) $theme['logo_media_id']);
        } catch (\Throwable) {
            // the website theme is optional for reports
        }
        return ['name' => $name, 'logo' => $logo];
    }

    public function settings(): array
    {
        $signers = Settings::get('reports.signers');
        $gpaMode = Settings::get('reports.gpa_rounding', 'truncate');
        return ['show_rank' => (bool) Settings::get('reports.show_rank', false), 'signers' => $this->cleanSigners(is_array($signers) ? $signers : []),
            'gpa_rounding' => $gpaMode === 'round' ? 'round' : 'truncate',
            'version' => (int) Settings::get('reports.settings_version', 0)];
    }

    private function cleanSigners(array $list): array
    {
        $out = [];
        foreach (array_slice(array_values($list), 0, 6) as $s) {
            if (!is_array($s)) continue;
            $label = trim((string) ($s['label'] ?? ''));
            if ($label === '') continue;
            $out[] = ['label' => mb_substr($label, 0, 80), 'name' => mb_substr(trim((string) ($s['name'] ?? '')), 0, 120)];
        }
        return $out;
    }

    public function saveSettings(array $d, int $version): array
    {
        return DB::transaction(function () use ($d, $version) {
            $cur = $this->settings();
            if ($cur['version'] !== $version) throw new VersionConflict($cur);
            if (array_key_exists('show_rank', $d)) Settings::put('reports.show_rank', (bool) $d['show_rank']);
            if (array_key_exists('gpa_rounding', $d)) Settings::put('reports.gpa_rounding', $d['gpa_rounding'] === 'round' ? 'round' : 'truncate');
            if (array_key_exists('signers', $d)) Settings::put('reports.signers', $this->cleanSigners($d['signers'] ?? []));
            Settings::put('reports.settings_version', $version + 1);
            Mutation::audit('reports.settings.saved', 'saan_settings', null, ['keys' => array_keys($d), 'version' => $version + 1]);
            return $this->settings();
        });
    }

    /** Signature lines of a page: the configured list, else a default per kind; "ครูผู้สอน"/"ครูประจำชั้น" are filled from data when blank. */
    public function signers(string $kind, array $fill = []): array
    {
        $list = $this->settings()['signers'];
        if (!$list) {
            $list = match ($kind) {
                'pp5' => [['label' => 'ครูผู้สอน', 'name' => ''], ['label' => 'หัวหน้ากลุ่มสาระการเรียนรู้', 'name' => ''], ['label' => 'รองผู้อำนวยการฝ่ายวิชาการ', 'name' => ''], ['label' => 'ผู้อำนวยการโรงเรียน', 'name' => '']],
                default => [['label' => 'ครูประจำชั้น', 'name' => ''], ['label' => 'รองผู้อำนวยการฝ่ายวิชาการ', 'name' => ''], ['label' => 'ผู้อำนวยการโรงเรียน', 'name' => '']],
            };
        }
        return array_map(function ($s) use ($fill) {
            if ($s['name'] === '' && isset($fill[$s['label']])) $s['name'] = (string) $fill[$s['label']];
            return $s;
        }, $list);
    }

    // ---- templates (layout per academic year) ----------------------------------------------------------------------

    public static function defaultOptions(string $kind): array
    {
        return ['title' => self::TITLES[$kind] ?? '', 'show_components' => true, 'show_attributes' => true, 'show_attendance' => true, 'footer_note' => '', 'font_size' => 12];
    }

    public function normalizeOptions(string $kind, array $o): array
    {
        $d = self::defaultOptions($kind);
        $title = trim((string) ($o['title'] ?? $d['title']));
        if ($title === '' || mb_strlen($title) > 120) $this->fail('options.title', 'ชื่อหัวรายงานต้องไม่ว่างและไม่เกิน 120 ตัวอักษร');
        $size = $o['font_size'] ?? $d['font_size'];
        if (!is_numeric($size) || (int) $size != $size || (int) $size < 10 || (int) $size > 16) $this->fail('options.font_size', 'ขนาดตัวอักษรต้องเป็นจำนวนเต็ม 10-16');
        $note = trim((string) ($o['footer_note'] ?? ''));
        if (mb_strlen($note) > 300) $this->fail('options.footer_note', 'หมายเหตุท้ายรายงานไม่เกิน 300 ตัวอักษร');
        return ['title' => $title, 'font_size' => (int) $size, 'footer_note' => $note,
            'show_components' => filter_var($o['show_components'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'show_attributes' => filter_var($o['show_attributes'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'show_attendance' => filter_var($o['show_attendance'] ?? true, FILTER_VALIDATE_BOOLEAN)];
    }

    public function templates(): array
    {
        return DB::table('rpt_templates')->where('school_id', $this->school())->orderBy('kind')->orderByDesc('year_be')->get()
            ->map(fn ($t) => ['id' => (int) $t->id, 'kind' => $t->kind, 'year_be' => (int) $t->year_be, 'version' => (int) $t->version,
                'options' => json_decode((string) $t->options, true) ?: []])->all();
    }

    /**
     * The template a report of academic year $yearBe renders with: the newest template saved for that year or an earlier
     * one (so a school keeps last year's layout until it saves a new one), else the built-in default.
     * @return array{options:array,year_be:?int}
     */
    public function template(string $kind, int $yearBe): array
    {
        $row = DB::table('rpt_templates')->where('school_id', $this->school())->where('kind', $kind)->where('year_be', '<=', $yearBe)->orderByDesc('year_be')->first();
        $saved = $row ? (json_decode((string) $row->options, true) ?: []) : [];
        return ['options' => array_replace(self::defaultOptions($kind), array_intersect_key($saved, self::defaultOptions($kind))), 'year_be' => $row ? (int) $row->year_be : null];
    }

    public function saveTemplate(string $kind, int $yearBe, array $options, int $version): array
    {
        abort_unless($this->isManager(), 403, 'เฉพาะผู้อำนวยการและผู้ดูแลระบบ');
        $opts = $this->normalizeOptions($kind, $options);
        $existing = DB::table('rpt_templates')->where('school_id', $this->school())->where('kind', $kind)->where('year_be', $yearBe)->first();
        if ($version === 0) {
            if ($existing) throw new VersionConflict($this->tplRow($existing));
            try {
                $row = Mutation::insert('rpt_templates', ['kind' => $kind, 'year_be' => $yearBe, 'options' => $opts], 'reports.template.created', ['kind' => $kind, 'year_be' => $yearBe]);
            } catch (QueryException $e) {
                if (!self::isUnique($e)) throw $e;
                $cur = DB::table('rpt_templates')->where('school_id', $this->school())->where('kind', $kind)->where('year_be', $yearBe)->first();
                throw new VersionConflict($cur ? $this->tplRow($cur) : []);
            }
            return $this->tplRow($row);
        }
        abort_unless($existing, 404, 'ไม่พบเทมเพลต');
        if ((int) $existing->version === $version && $this->inheritedByLaterYear($kind, $yearBe)) {
            $this->fail('year_be', 'มีปีการศึกษาถัดไปที่ใช้เทมเพลตนี้อยู่ การแก้ไขจะเปลี่ยนรายงานที่พิมพ์ไปแล้ว กรุณาสร้างเทมเพลตของปีใหม่แทน');
        }
        return $this->tplRow(Mutation::update('rpt_templates', (int) $existing->id, $version, ['options' => $opts], 'reports.template.updated', ['kind' => $kind, 'year_be' => $yearBe]));
    }

    /** True when an academic year after $yearBe (and before the next template of this kind) exists and so renders with this template. */
    private function inheritedByLaterYear(string $kind, int $yearBe): bool
    {
        $next = DB::table('rpt_templates')->where('school_id', $this->school())->where('kind', $kind)->where('year_be', '>', $yearBe)->min('year_be');
        $q = DB::table('academic_terms')->where('school_id', $this->school())->where('year', '>', $yearBe);
        if ($next !== null) $q->where('year', '<', (int) $next);
        return $q->exists();
    }

    private function tplRow(object $t): array
    {
        return ['id' => (int) $t->id, 'kind' => $t->kind, 'year_be' => (int) $t->year_be, 'version' => (int) $t->version, 'options' => json_decode((string) $t->options, true) ?: []];
    }

    // ---- remarks and special results -------------------------------------------------------------------------------

    public function saveRemark(int $studentId, int $termId, string $remark, int $version): array
    {
        $user = $this->user();
        $student = DB::table('students')->where('school_id', $this->school())->where('id', $studentId)->first($this->studentCols());
        abort_unless($student && TeacherScope::canSeeStudent($student, $user), 404, 'ไม่พบนักเรียน'); // a graduated / transferred pupil stays in the old classroom row: teachers do not reach them
        $term = $this->term($termId);
        $this->assertHomeroom((int) $student->classroom_id);
        if ($user->role === 'teacher') abort_unless((int) $term->id === (int) TermScope::currentId(), 403, 'ภาคเรียนที่ผ่านมาแก้หมายเหตุได้เฉพาะผู้อำนวยการและผู้ดูแลระบบ');
        $remark = trim(preg_replace('/\s+/u', ' ', $remark) ?? '');
        if (mb_strlen($remark) > 500) $this->fail('remark', 'หมายเหตุไม่เกิน 500 ตัวอักษร');
        $existing = DB::table('rpt_remarks')->where('school_id', $this->school())->where('term_id', $term->id)->where('student_id', $studentId)->first();
        if ($version === 0) {
            if ($existing) throw new VersionConflict($this->plain($existing));
            try {
                $row = Mutation::insert('rpt_remarks', ['term_id' => (int) $term->id, 'student_id' => $studentId, 'remark' => $remark, 'updated_by' => auth()->id()], 'reports.remark.created', ['student_id' => $studentId, 'term_id' => (int) $term->id]);
            } catch (QueryException $e) {
                if (!self::isUnique($e)) throw $e;
                $cur = DB::table('rpt_remarks')->where('school_id', $this->school())->where('term_id', $term->id)->where('student_id', $studentId)->first();
                throw new VersionConflict($cur ? $this->plain($cur) : []);
            }
        } else {
            abort_unless($existing, 404, 'ไม่พบหมายเหตุ');
            $row = Mutation::update('rpt_remarks', (int) $existing->id, $version, ['remark' => $remark, 'updated_by' => auth()->id()], 'reports.remark.updated', ['student_id' => $studentId, 'term_id' => (int) $term->id]);
        }
        return ['student_id' => $studentId, 'term_id' => (int) $term->id, 'remark' => $row->remark, 'version' => (int) $row->version];
    }

    private function plain(object $r): array
    {
        return ['student_id' => (int) $r->student_id, 'remark' => $r->remark, 'version' => (int) $r->version];
    }

    public function remarkList(int $classroomId, object $term): array
    {
        $this->assertHomeroom($classroomId);
        $this->assertTermAccess($term);
        $room = $this->classroom($classroomId);
        $courses = $this->classroomCourses($classroomId, (int) $term->id);
        $students = $this->roster($classroomId, $term, $courses->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
        $rem =$this->remarks(array_column($students, 'id'), (int) $term->id);
        return ['classroom' => ['id' => (int) $room->id, 'name' => $room->name], 'term' => ['id' => (int) $term->id, 'year' => (int) $term->year, 'term' => (int) $term->term],
            'data' => array_map(fn ($s) => $s + ['remark' => $rem[$s['id']]['remark'] ?? '', 'version' => $rem[$s['id']]['version'] ?? 0], $students)];
    }

    public function specialList(int $courseId): array
    {
        $c = $this->course($courseId);
        $term = $this->term((int) $c->term_id);
        $students = $this->roster((int) $c->classroom_id, $term, [(int) $c->id]);
        $sp = DB::table('rpt_special_results')->where('school_id', $this->school())->where('course_id', $courseId)->get()->keyBy('student_id');
        return ['course' => ['id' => (int) $c->id, 'subject' => ['code' => $c->subject_code, 'name' => $c->subject_name], 'classroom' => $c->classroom_name],
            'data' => array_map(fn ($s) => $s + ['special' => $sp[$s['id']]->code ?? null, 'note' => $sp[$s['id']]->note ?? null, 'version' => (int) ($sp[$s['id']]->version ?? 0)], $students)];
    }

    /**
     * code null clears the mark (version required). Rows are never deleted: clearing sets code null and bumps the version, so a stale
     * client can never overwrite a later row (no ABA). A closed course takes special-result changes from admin/principal only.
     */
    public function saveSpecial(int $courseId, int $studentId, ?string $code, ?string $note, int $version, ?string $reason = null): array
    {
        $c = $this->course($courseId);
        $extra = [];
        // same rule as Assessment::guardTerm: a past term is closed to teachers; a manager must say why
        if ((int) $c->term_id !== (int) TermScope::currentId()) {
            abort_unless($this->isManager(), 423, 'ภาคเรียนนี้ผ่านไปแล้ว แก้ผลพิเศษต้องให้ผู้ดูแลเปิด');
            $reason = trim((string) $reason);
            if ($reason === '') $this->fail('reason', 'ภาคเรียนที่ผ่านมา ต้องระบุเหตุผลที่แก้');
            $extra = ['reason' => mb_substr($reason, 0, 200), 'term_id' => (int) $c->term_id];
        }
        return DB::transaction(function () use ($c, $courseId, $studentId, $code, $note, $version, $extra) {
            $locked = DB::table('asm_courses')->where('school_id', $this->school())->where('id', $courseId)->lockForUpdate()->first(['status']);
            abort_unless($locked, 404, 'ไม่พบรายวิชา');
            $c->status = $locked->status;
            return $this->saveSpecialLocked($c, $courseId, $studentId, $code, $note, $version, $extra);
        });
    }

    private function saveSpecialLocked(object $c, int $courseId, int $studentId, ?string $code, ?string $note, int $version, array $extra): array
    {
        abort_unless($c->status === 'open' || $this->isManager(), 403, 'รายวิชาปิดผลแล้ว แก้ผลพิเศษได้เฉพาะผู้อำนวยการและผู้ดูแลระบบ');
        $term = $this->term((int) $c->term_id);
        $ids = array_column($this->roster((int) $c->classroom_id, $term, [(int) $c->id]), 'id');
        if (!in_array($studentId, $ids, true)) $this->fail('student_id', 'นักเรียนไม่ได้อยู่ในรายวิชานี้');
        $note = $note === null ? null : mb_substr(trim($note), 0, 200);
        $existing = DB::table('rpt_special_results')->where('school_id', $this->school())->where('course_id', $courseId)->where('student_id', $studentId)->first();
        if ($code === null) {
            if (!$existing) return ['student_id' => $studentId, 'code' => null, 'note' => null, 'version' => 0];
            if ($existing->code === null) {
                if ((int) $existing->version !== $version) throw new VersionConflict(['student_id' => $studentId, 'code' => null, 'note' => null, 'version' => (int) $existing->version]);
                return ['student_id' => $studentId, 'code' => null, 'note' => null, 'version' => $version];
            }
            $row = Mutation::update('rpt_special_results', (int) $existing->id, $version, ['code' => null, 'note' => null, 'updated_by' => auth()->id()], 'reports.special.cleared', ['course_id' => $courseId, 'student_id' => $studentId] + $extra);
            return ['student_id' => $studentId, 'code' => null, 'note' => null, 'version' => (int) $row->version];
        }
        if (!array_key_exists($code, Calc::SPECIAL)) $this->fail('code', 'ผลพิเศษต้องเป็น ร, มผ หรือ มส');
        if ($version === 0) {
            if ($existing) throw new VersionConflict(['student_id' => $studentId, 'code' => $existing->code, 'note' => $existing->note, 'version' => (int) $existing->version]);
            try {
                $row = Mutation::insert('rpt_special_results', ['course_id' => $courseId, 'student_id' => $studentId, 'code' => $code, 'note' => $note, 'updated_by' => auth()->id()], 'reports.special.created', ['course_id' => $courseId, 'student_id' => $studentId, 'code' => $code] + $extra);
            } catch (QueryException $e) {
                if (!self::isUnique($e)) throw $e;
                $cur = DB::table('rpt_special_results')->where('school_id', $this->school())->where('course_id', $courseId)->where('student_id', $studentId)->first();
                throw new VersionConflict($cur ? ['student_id' => $studentId, 'code' => $cur->code, 'note' => $cur->note, 'version' => (int) $cur->version] : []);
            }
        } else {
            abort_unless($existing, 404, 'ไม่พบผลพิเศษ');
            $row = Mutation::update('rpt_special_results', (int) $existing->id, $version, ['code' => $code, 'note' => $note, 'updated_by' => auth()->id()], 'reports.special.updated', ['course_id' => $courseId, 'student_id' => $studentId, 'code' => $code] + $extra);
        }
        return ['student_id' => $studentId, 'code' => $row->code, 'note' => $row->note, 'version' => (int) $row->version];
    }

    // ---- reports -------------------------------------------------------------------------------------------------

    private function head(object $term, string $kind): array
    {
        return ['school' => $this->school_header(), 'term' => ['id' => (int) $term->id, 'year' => (int) $term->year, 'term' => (int) $term->term],
            'template' => $this->template($kind, (int) $term->year)];
    }

    /** ปพ.5 of one course. */
    public function pp5(int $courseId): array
    {
        $c = $this->course($courseId);
        $term = $this->term((int) $c->term_id);
        $students = $this->roster((int) $c->classroom_id, $term, [(int) $c->id]);
        $calc = $this->compute([$c], $students);
        $attr = $this->termAttributes(array_column($students, 'id'), (int) $term->id, (int) $c->id);
        $codes = [];
        foreach ($attr as $byKind) foreach ($byKind as $kind => $items) foreach ($items as $i) $codes[$kind][$i['code']] = true;
        $rows = [];
        foreach ($students as $s) $rows[] = ['student' => $s, 'result' => $calc['results'][$s['id']][(int) $c->id], 'attributes' => $attr[$s['id']] ?? []];
        $stats = Calc::distribution(array_column($rows, 'result'));
        return $this->head($term, 'pp5') + [
            'course' => $calc['courses'][(int) $c->id] + ['status' => $c->status, 'hours' => $c->hours_per_year],
            'classroom' => $c->classroom_name, 'rows' => $rows, 'stats' => $stats,
            'attr_codes' => array_map(fn ($m) => array_keys($m), $codes),
            'signers' => $this->signers('pp5', ['ครูผู้สอน' => $c->teacher_name ?? '']),
        ];
    }

    /** ปพ.6 data for many students of one classroom (bulk) or one student. */
    private function pp6Pages(object $term, array $students, array $courses, ?array $rankPool = null): array
    {
        $calc = $this->compute($courses, $students);
        $ids = array_column($students, 'id');
        $att = $this->attendance($ids, $term, $this->termRooms($students, $term));
        $attr = $this->termAttributes($ids, (int) $term->id, 0);
        $rem = $this->remarks($ids, (int) $term->id);
        $showRank = $this->settings()['show_rank'];
        // the rank must come from the SAME gpa that is printed: put this page's students' own gpa back into the class pool
        $pool = $rankPool === null ? $calc['gpa'] : array_replace($rankPool, array_intersect_key($calc['gpa'], array_flip($ids)));
        $ranks = $showRank ? Calc::ranks(array_map(fn ($g) => $g['gpa_centi'], $pool)) : [];
        $pages = [];
        foreach ($students as $s) {
            $lines = [];
            foreach ($calc['courses'] as $cid => $ci) $lines[] = ['course' => $ci, 'result' => $calc['results'][$s['id']][$cid]];
            $pages[] = ['student' => $s, 'lines' => $lines, 'gpa' => $calc['gpa'][$s['id']], 'rank' => $ranks[$s['id']] ?? null, 'attendance' => $att === null ? null : ($att[$s['id']] ?? []),
                'attendance_warning' => $this->attendanceWarnings[$s['id']] ?? null,
                'attributes' => $attr[$s['id']] ?? [], 'remark' => $rem[$s['id']]['remark'] ?? ''];
        }
        return ['pages' => $pages, 'show_rank' => $showRank, 'has_attendance' => $att !== null];
    }

    /** Whole classroom, one ปพ.6 page per student. */
    public function pp6Class(int $classroomId, object $term): array
    {
        $this->assertHomeroom($classroomId);
        $this->assertTermAccess($term);
        $room = $this->classroom($classroomId);
        $courses = $this->classroomCourses($classroomId, (int) $term->id);
        $students = $this->roster($classroomId, $term, $courses->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
        $homeroom = $room->homeroom_teacher_id ? DB::table('teachers')->where('id', $room->homeroom_teacher_id)->value('name') : '';
        return $this->head($term, 'pp6') + $this->pp6Pages($term, $students, $courses->all()) + ['classroom' => $room->name, 'classroom_id' => (int) $room->id, 'signers' => $this->signers('pp6', ['ครูประจำชั้น' => (string) $homeroom])];
    }

    /** One student. Courses = the student's classroom courses (current term) plus any course where the student has rows. */
    public function pp6Student(int $studentId, object $term): array
    {
        $s = DB::table('students')->where('school_id', $this->school())->where('id', $studentId)->first($this->studentCols());
        abort_unless($s && TeacherScope::canSeeStudent($s, $this->user()), 404, 'ไม่พบนักเรียน');
        $this->assertTermAccess($term);
        $cur = (int) $term->id === (int) TermScope::currentId();
        // one classroom per student per term: today's for the current term, else the dominant classroom of that term's courses
        $dom = $cur ? null : ($this->dominantRooms([(int) $s->id], (int) $term->id)[(int) $s->id] ?? null);
        $termRoom = $cur ? (int) $s->classroom_id : ($dom ?? (int) $s->classroom_id);
        $mine = $this->homeroomIds();
        abort_unless($mine === null || in_array($termRoom, $mine, true), 403, 'รายงานนี้ดูได้เฉพาะครูประจำชั้นของนักเรียน ผู้อำนวยการ และผู้ดูแลระบบ');
        $baseRoom = $cur ? (int) $s->classroom_id : $dom;
        $courses = $this->studentCourses((int) $s->id, (int) $term->id, $baseRoom);
        return $this->pp6For($s, $term, $courses, $termRoom);
    }

    /** Courses of one student in a term: the base classroom's courses plus any course where the student has scores or a special result. No access check (callers decide). */
    public function studentCourses(int $studentId, int $termId, ?int $baseRoom)
    {
        return $this->courseQuery()->where('c.term_id', $termId)->where(function ($w) use ($studentId, $baseRoom) {
            if ($baseRoom) $w->orWhere('c.classroom_id', $baseRoom);
            $w->orWhereIn('c.id', DB::table('asm_scores')->where('student_id', $studentId)->select('course_id'))
                ->orWhereIn('c.id', DB::table('rpt_special_results')->where('student_id', $studentId)->whereNotNull('code')->select('course_id'));
        })->orderBy('s.group_name')->orderBy('s.code')->get();
    }

    private function pp6For(object $s, object $term, $courses, int $termRoom): array
    {
        $student = $this->presentStudent($s);
        $room = DB::table('classrooms')->where('school_id', $this->school())->where('id', $termRoom)->first();
        $homeroom = $room && $room->homeroom_teacher_id ? DB::table('teachers')->where('id', $room->homeroom_teacher_id)->value('name') : '';
        // rank is a class standing: computed against the classroom the student belonged to in that term
        $rankPool = null;
        if ($this->settings()['show_rank'] && $room) {
            $cc = $this->classroomCourses((int) $room->id, (int) $term->id);
            $cs = $this->roster((int) $room->id, $term, $cc->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
            $rankPool = $this->compute($cc, $cs)['gpa'];
        }
        return $this->head($term, 'pp6') + $this->pp6Pages($term, [$student], $courses->all(), $rankPool) + ['classroom' => $room->name ?? '', 'signers' => $this->signers('pp6', ['ครูประจำชั้น' => (string) $homeroom])];
    }

    /** M12 class summary. */
    public function summary(int $classroomId, object $term): array
    {
        $this->assertHomeroom($classroomId);
        $this->assertTermAccess($term);
        $room = $this->classroom($classroomId);
        $courses = $this->classroomCourses($classroomId, (int) $term->id);
        $students = $this->roster($classroomId, $term, $courses->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
        $calc = $this->compute($courses, $students);
        $per = [];
        foreach ($calc['courses'] as $cid => $ci) {
            $per[] = ['course' => $ci, 'dist' => Calc::distribution(array_map(fn ($s) => $calc['results'][$s['id']][$cid], $students))];
        }
        $showRank = $this->settings()['show_rank'];
        $ranks = $showRank ? Calc::ranks(array_map(fn ($g) => $g['gpa_centi'], $calc['gpa'])) : [];
        $list = [];
        foreach (array_values($students) as $idx => $s) {
            $res = $calc['results'][$s['id']];
            $list[] = ['seq' => $idx + 1, 'student' => $s, 'gpa' => $calc['gpa'][$s['id']], 'rank' => $ranks[$s['id']] ?? null,
                'zero' => count(array_filter($res, fn ($r) => $r['state'] === 'graded' && $r['grade'] === '0')),
                'r' => count(array_filter($res, fn ($r) => $r['special'] === 'ร')), 'mp' => count(array_filter($res, fn ($r) => $r['special'] === 'มผ')),
                'ms' => count(array_filter($res, fn ($r) => $r['special'] === 'มส')), 'incomplete' => count(array_filter($res, fn ($r) => in_array($r['state'], ['incomplete', 'none'], true)))];
        }
        if ($showRank) usort($list, fn ($a, $b) => [$a['rank'] ?? PHP_INT_MAX, $a['student']['code']] <=> [$b['rank'] ?? PHP_INT_MAX, $b['student']['code']]);
        $placeholders = ['zero' => array_sum(array_column($list, 'zero')), 'r' => array_sum(array_column($list, 'r')), 'mp' => array_sum(array_column($list, 'mp')),
            'ms' => array_sum(array_column($list, 'ms')), 'incomplete' => array_sum(array_column($list, 'incomplete'))];
        $homeroom = $room->homeroom_teacher_id ? DB::table('teachers')->where('id', $room->homeroom_teacher_id)->value('name') : '';
        return $this->head($term, 'summary') + ['classroom' => $room->name, 'subjects' => $per, 'students' => $list, 'show_rank' => $showRank, 'placeholders' => $placeholders,
            'student_count' => count($students), 'signers' => $this->signers('summary', ['ครูประจำชั้น' => (string) $homeroom])];
    }
}
