<?php

namespace Saan\Modules\Behavior;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use App\Support\Saan\VersionConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Rules, records, summaries, report and CSV rows of one school. Every query is scoped by config('school.school_id'). */
class BehaviorRepository
{
    public const CATEGORIES = ['good', 'improve'];
    public const MAX_POINTS = 100;
    public const MAX_STUDENTS = 200;
    public const EXPORT_LIMIT = 20000;

    /** Editable starting set seeded once per school on first use (code, label, category, points). */
    public const DEFAULT_RULES = [
        ['help-school', 'ช่วยงานโรงเรียน', 'good', 5],
        ['help-friend', 'ช่วยเหลือเพื่อน', 'good', 5],
        ['honest', 'ซื่อสัตย์ เก็บของได้แล้วส่งคืน', 'good', 10],
        ['represent', 'เป็นตัวแทนโรงเรียน', 'good', 10],
        ['late', 'มาสาย', 'improve', -2],
        ['uniform', 'ไม่แต่งกายตามระเบียบ', 'improve', -3],
        ['no-homework', 'ไม่ส่งงาน', 'improve', -2],
        ['disturb', 'รบกวนการเรียนการสอน', 'improve', -5],
        ['fight', 'ทะเลาะวิวาท', 'improve', -10],
    ];

    private function school(): int
    {
        return (int) config('school.school_id');
    }

    public static function key(string $code): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $code)));
    }

    public static function isDuplicate(QueryException $e): bool
    {
        $info = $e->errorInfo[1] ?? null;
        $code = (string) $e->getCode();
        if ($code === '23505' || in_array($info, [1062, 1586], true)) return true;
        // SQLSTATE 23000 alone also covers NOT NULL / FK / CHECK violations, so the message must also say UNIQUE
        return $info === 19 && stripos($e->getMessage(), 'UNIQUE') !== false;
    }

    // ------------------------------------------------------------------ rules

    public function ensureRules(): void
    {
        if (Settings::get('behavior.rules_seeded')) return;
        if (DB::table('bhv_rules')->where('school_id', $this->school())->exists()) { Settings::put('behavior.rules_seeded', true); return; }
        DB::transaction(function () {
            $now = now();
            foreach (self::DEFAULT_RULES as [$code, $label, $cat, $pts]) {
                DB::table('bhv_rules')->insertOrIgnore(['school_id' => $this->school(), 'code' => $code, 'code_key' => self::key($code), 'label' => $label,
                    'category' => $cat, 'points' => $pts, 'is_active' => true, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
            Mutation::audit('behavior.rules.seeded', 'bhv_rules', null, ['count' => count(self::DEFAULT_RULES)]);
            Settings::put('behavior.rules_seeded', true);
        });
    }

    public function rules(bool $activeOnly): array
    {
        $this->ensureRules();
        $q = DB::table('bhv_rules')->where('school_id', $this->school());
        if ($activeOnly) $q->where('is_active', true);
        return $q->orderBy('category')->orderByDesc('points')->orderBy('id')->get()->map(fn ($r) => $this->rule($r))->all();
    }

    private function rule(object $r): array
    {
        return ['id' => (int) $r->id, 'code' => $r->code, 'label' => $r->label, 'category' => $r->category, 'points' => (int) $r->points,
            'is_active' => (bool) $r->is_active, 'version' => (int) $r->version];
    }

    private function checkSign(string $category, int $points): void
    {
        if ($points === 0 || abs($points) > self::MAX_POINTS) abort(422, 'คะแนนต้องไม่เป็น 0 และไม่เกิน '.self::MAX_POINTS);
        if (($category === 'good') !== ($points > 0)) abort(422, 'หมวดความดีต้องเป็นคะแนนบวก และหมวดควรปรับปรุงต้องเป็นคะแนนลบ');
    }

    public function createRule(array $d): array
    {
        $this->ensureRules();
        $code = trim(preg_replace('/\s+/u', ' ', $d['code']));
        $this->checkSign($d['category'], (int) $d['points']);
        if (DB::table('bhv_rules')->where('school_id', $this->school())->where('code_key', self::key($code))->exists()) abort(409, 'รหัสกติกานี้มีอยู่แล้ว');
        try {
            $row = Mutation::insert('bhv_rules', ['code' => $code, 'code_key' => self::key($code), 'label' => trim($d['label']), 'category' => $d['category'],
                'points' => (int) $d['points'], 'is_active' => (bool) ($d['is_active'] ?? true)], 'behavior.rule.created', ['code' => $code]);
        } catch (QueryException $e) {
            if (self::isDuplicate($e)) abort(409, 'รหัสกติกานี้มีอยู่แล้ว');
            throw $e;
        }
        return $this->rule($row);
    }

    public function updateRule(int $id, array $d): array
    {
        $cur = DB::table('bhv_rules')->where('school_id', $this->school())->where('id', $id)->first();
        if (!$cur) abort(404);
        $changes = [];
        foreach (['label', 'category', 'points', 'is_active'] as $f) if (array_key_exists($f, $d)) $changes[$f] = $d[$f];
        if (isset($changes['label'])) $changes['label'] = trim($changes['label']);
        $this->checkSign($changes['category'] ?? $cur->category, (int) ($changes['points'] ?? $cur->points));
        if (!$changes) return $this->rule($cur);
        $row = Mutation::update('bhv_rules', $id, (int) $d['version'], $changes, 'behavior.rule.updated', ['code' => $cur->code] + $changes);
        return $this->rule($row);
    }

    // ------------------------------------------------------------------ terms / students

    public function term(?int $id): int
    {
        if ($id === null) return TermScope::currentId() ?? abort(422, 'ยังไม่ได้ตั้งภาคเรียน');
        if (!DB::table('academic_terms')->where('school_id', $this->school())->where('id', $id)->exists()) abort(404, 'ไม่พบภาคเรียน');
        return $id;
    }

    /** Term whose date range holds the day; the current term when no range matches. */
    private function termFor(string $date): int
    {
        $row = DB::table('academic_terms')->where('school_id', $this->school())->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)
            ->orderByDesc('year')->orderByDesc('term')->first();
        return $row ? (int) $row->id : $this->term(null);
    }

    private function studentColumns(): array
    {
        $cols = ['id', 'classroom_id', 'code', 'name'];
        foreach (['first_name', 'last_name', 'status', 'roll_number'] as $c) if (Schema::hasColumn('students', $c)) $cols[] = $c;
        return $cols;
    }

    private function studentName(object $s): string
    {
        $first = trim((string) ($s->first_name ?? ''));
        return $first !== '' ? trim($first.' '.trim((string) ($s->last_name ?? ''))) : (string) $s->name;
    }

    /** Throws 403 when the signed-in user may not act on the classroom in that term. */
    private function assertClassroom(?int $classroomId, ?object $user, int $termId): void
    {
        // past terms: only teachers who taught the room in THAT term (today's homeroom would expose the previous cohort)
        $ids = TeacherScope::classroomIdsForTerm($user, $termId);
        if ($ids !== null && ($classroomId === null || !in_array($classroomId, $ids, true))) abort(403, 'ไม่มีสิทธิ์ดูหรือบันทึกนักเรียนห้องนี้');
    }

    public function context(?object $user): array
    {
        $termId = TermScope::currentId();
        $term = $termId ? TermScope::current() : null;
        $ids = TeacherScope::classroomIds($user, $termId);
        $q = DB::table('classrooms')->where('school_id', $this->school());
        if ($ids !== null) $q->whereIn('id', $ids ?: [0]);
        $classrooms = $q->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all();
        $terms = DB::table('academic_terms')->where('school_id', $this->school())->orderByDesc('year')->orderByDesc('term')->get(['id', 'year', 'term'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'year' => (int) $t->year, 'term' => (int) $t->term])->all();
        return ['term' => $term ? ['id' => (int) $term->id, 'year' => (int) $term->year, 'term' => (int) $term->term] : null, 'terms' => $terms,
            'today' => \Carbon\Carbon::now('Asia/Bangkok')->toDateString(),
            'classrooms' => $classrooms, 'can_manage' => in_array($user?->role, TeacherScope::ALL_ROLES, true)];
    }

    // ------------------------------------------------------------------ records

    /** One rule (or custom points) applied to many students, atomically. @return array[] */
    public function record(array $d, object $user): array
    {
        $this->ensureRules();
        $termId = $this->termFor($d['occurred_on']);
        $ids = array_values(array_unique(array_map('intval', $d['student_ids'])));
        if (!$ids || count($ids) > self::MAX_STUDENTS) abort(422, 'เลือกนักเรียน 1 ถึง '.self::MAX_STUDENTS.' คน');
        $students = DB::table('students')->where('school_id', $this->school())->whereIn('id', $ids)->get($this->studentColumns())->keyBy('id');
        if ($students->count() !== count($ids)) abort(422, 'ไม่พบนักเรียนบางรายที่เลือก');
        // once per request, not per student. The student sits in their CURRENT classroom, so the teacher needs the right both in the term of the date
        // (a back-dated record belongs to the teachers of THAT term) and today; today's homeroom alone must not reach into an earlier term
        $allowed = TeacherScope::classroomIdsForTerm($user, $termId);
        $allowedNow = TeacherScope::classroomIds($user);
        foreach ($students as $s) {
            if ($allowed !== null && ($s->classroom_id === null || !in_array((int) $s->classroom_id, $allowed, true) || !in_array((int) $s->classroom_id, $allowedNow ?? [], true))) abort(403, 'ไม่มีสิทธิ์ดูหรือบันทึกนักเรียนห้องนี้');
            if (isset($s->status) && $s->status !== 'studying') abort(422, 'นักเรียนบางรายไม่ได้อยู่ในสถานะกำลังศึกษา');
        }

        $ruleId = null;
        if (!empty($d['rule_id'])) {
            $rule = DB::table('bhv_rules')->where('school_id', $this->school())->where('id', (int) $d['rule_id'])->first();
            if (!$rule || !$rule->is_active) abort(422, 'กติกานี้ไม่มีอยู่หรือปิดใช้งานแล้ว');
            $ruleId = (int) $rule->id; $points = (int) $rule->points; $ruleLabel = (string) $rule->label;
        } else {
            $ruleLabel = null;
            $points = (int) ($d['points'] ?? 0);
            if (trim((string) ($d['note'] ?? '')) === '') abort(422, 'การให้คะแนนเองต้องระบุหมายเหตุ');
            $this->checkSign($points > 0 ? 'good' : 'improve', $points);
        }
        $note = trim((string) ($d['note'] ?? '')) ?: null;

        return DB::transaction(function () use ($ids, $termId, $ruleId, $ruleLabel, $points, $note, $d, $user, $students) {
            $out = [];
            foreach ($ids as $sid) {
                $row = Mutation::insert('bhv_records', ['term_id' => $termId, 'student_id' => $sid, 'rule_id' => $ruleId, 'rule_label' => $ruleLabel,
                    'classroom_id' => $students[$sid]->classroom_id === null ? null : (int) $students[$sid]->classroom_id, 'occurred_on' => $d['occurred_on'],
                    'points' => $points, 'note' => $note, 'recorded_by' => $user->id, 'status' => 'active',
                    'guardian_visible' => (bool) ($d['guardian_visible'] ?? (count($ids) === 1))], 'behavior.record.created', ['student_id' => $sid, 'points' => $points, 'rule_id' => $ruleId]);
                $out[] = ['id' => (int) $row->id, 'student_id' => $sid, 'term_id' => $termId, 'rule_id' => $ruleId, 'occurred_on' => (string) $row->occurred_on,
                    'points' => $points, 'note' => $note, 'status' => 'active', 'guardian_visible' => (bool) $row->guardian_visible, 'version' => (int) $row->version];
            }
            return $out;
        });
    }

    /** @return array{id:int,year:int,term:int}|null */
    public function termInfo(int $id): ?array
    {
        $t = DB::table('academic_terms')->where('school_id', $this->school())->where('id', $id)->first(['id', 'year', 'term']);
        return $t ? ['id' => (int) $t->id, 'year' => (int) $t->year, 'term' => (int) $t->term] : null;
    }

    public function void(int $id, int $version, string $reason, object $user): array
    {
        $rec = DB::table('bhv_records')->where('school_id', $this->school())->where('id', $id)->first();
        if (!$rec) abort(404);
        if (!in_array($user->role, TeacherScope::ALL_ROLES, true)) {
            if ((int) $rec->recorded_by !== (int) $user->id) abort(403, 'ยกเลิกได้เฉพาะรายการที่ตนเองบันทึก');
            $classroom = $rec->classroom_id ?? DB::table('students')->where('school_id', $this->school())->where('id', $rec->student_id)->value('classroom_id');
            $this->assertClassroom($classroom === null ? null : (int) $classroom, $user, (int) $rec->term_id);
        }
        if ($rec->status === 'voided') {
            if ((int) $rec->version === $version) abort(422, 'รายการนี้ถูกยกเลิกแล้ว');
            throw new VersionConflict((array) $rec);
        }
        $row = Mutation::update('bhv_records', $id, $version, ['status' => 'voided', 'voided_reason' => trim($reason), 'voided_by' => $user->id, 'voided_at' => now()->toDateTimeString()],
            'behavior.record.voided', ['student_id' => (int) $rec->student_id, 'points' => (int) $rec->points]);
        return ['id' => (int) $row->id, 'status' => $row->status, 'voided_reason' => $row->voided_reason, 'version' => (int) $row->version];
    }

    public function timeline(int $studentId, ?int $termId, object $user): array
    {
        $termId = $this->term($termId);
        $s = DB::table('students')->where('school_id', $this->school())->where('id', $studentId)->first($this->studentColumns());
        if (!$s || !TeacherScope::canSeeStudent($s, $user)) abort(404);
        $manager = in_array($user->role, TeacherScope::ALL_ROLES, true);
        $allowed = $manager ? null : TeacherScope::classroomIdsForTerm($user, $termId);
        $room = $s->classroom_id ? DB::table('classrooms')->where('school_id', $this->school())->where('id', $s->classroom_id)->value('name') : null;
        $data = (new BehaviorReadModel())->forStudent($studentId, $termId);
        // can_void is a staff-only hint, so it is added here and not in the read model shared with the guardian portal
        $meta = DB::table('bhv_records')->where('school_id', $this->school())->whereIn('id', array_column($data['records'], 'id') ?: [0])
            ->get(['id', 'recorded_by', 'classroom_id'])->keyBy('id');
        if ($allowed !== null) {
            // classroom ids persist across years: authorise each record by the classroom the student was in when it was recorded in THAT term,
            // not by pairing the current classroom with a caller-chosen past term
            $inScope = fn (array $r) => in_array((int) (($meta->get($r['id'])?->classroom_id) ?? $s->classroom_id), $allowed, true);
            $data['records'] = array_values(array_filter($data['records'], $inScope));
            $currentOk = $s->classroom_id !== null && in_array((int) $s->classroom_id, $allowed, true);
            if (!$data['records'] && !$currentOk) abort(403, 'ไม่มีสิทธิ์ดูหรือบันทึกนักเรียนห้องนี้');
            $t = ['good_points' => 0, 'improve_points' => 0, 'net_points' => 0, 'records' => 0, 'voided' => 0];
            foreach ($data['records'] as $r) {
                if ($r['status'] === 'voided') { $t['voided']++; continue; }
                $t['records']++;
                if ($r['points'] >= 0) $t['good_points'] += $r['points']; else $t['improve_points'] += $r['points'];
            }
            $t['net_points'] = $t['good_points'] + $t['improve_points'];
            $data['totals'] = $t;
        }
        foreach ($data['records'] as &$r) {
            $m = $meta->get($r['id']);
            $recRoom = $m?->classroom_id ?? $s->classroom_id;
            $r['can_void'] = $r['status'] === 'active' && ($manager || ($m && (int) $m->recorded_by === (int) $user->id
                && $allowed !== null && $recRoom !== null && in_array((int) $recRoom, $allowed, true)));
        }
        unset($r);
        return ['student' => ['id' => (int) $s->id, 'code' => $s->code, 'name' => $this->studentName($s), 'classroom' => $room]] + $data;
    }

    /** Students of a classroom with term totals and rank (equal net points share a rank). */
    public function summary(int $classroomId, ?int $termId, object $user): array
    {
        $termId = $this->term($termId);
        $room = DB::table('classrooms')->where('school_id', $this->school())->where('id', $classroomId)->first();
        if (!$room) abort(404);
        $this->assertClassroom($classroomId, $user, $termId);
        // records count toward the classroom the student was in when recorded (older rows without a snapshot use the current one)
        $totals = DB::table('bhv_records as r')
            ->join('students as s', function ($j) { $j->on('s.id', '=', 'r.student_id')->on('s.school_id', '=', 'r.school_id'); })
            ->where('r.school_id', $this->school())->where('r.term_id', $termId)->where('r.status', 'active')
            ->whereRaw('coalesce(r.classroom_id, s.classroom_id) = ?', [$classroomId])->groupBy('r.student_id')
            ->selectRaw('r.student_id as student_id, count(*) as n, sum(case when r.points > 0 then r.points else 0 end) as good, sum(case when r.points < 0 then r.points else 0 end) as improve')
            ->get()->keyBy('student_id');
        $roster = DB::table('students')->where('school_id', $this->school())->where('classroom_id', $classroomId)->get($this->studentColumns())
            ->filter(fn ($s) => !isset($s->status) || $s->status === 'studying')->values();
        $extra = $totals->keys()->diff($roster->pluck('id'))->all();
        $students = $extra
            ? $roster->concat(DB::table('students')->where('school_id', $this->school())->whereIn('id', $extra)->get($this->studentColumns()))->values()
            : $roster;
        $rows = $students->map(function ($s) use ($totals) {
            $t = $totals->get($s->id);
            $good = (int) ($t->good ?? 0); $imp = (int) ($t->improve ?? 0);
            return ['id' => (int) $s->id, 'code' => $s->code, 'name' => $this->studentName($s), 'roll_number' => isset($s->roll_number) ? $s->roll_number : null,
                'records' => (int) ($t->n ?? 0), 'good_points' => $good, 'improve_points' => $imp, 'net_points' => $good + $imp];
        })->all();
        usort($rows, fn ($a, $b) => [$b['net_points'], $a['roll_number'] ?? PHP_INT_MAX, $a['code']] <=> [$a['net_points'], $b['roll_number'] ?? PHP_INT_MAX, $b['code']]);
        $rank = 0; $prev = null;
        foreach ($rows as $i => &$r) {
            if ($prev === null || $r['net_points'] !== $prev) $rank = $i + 1;
            $r['rank'] = $rank; $prev = $r['net_points'];
        }
        unset($r);
        return ['classroom' => ['id' => (int) $room->id, 'name' => $room->name], 'term_id' => $termId, 'students' => $rows];
    }

    public function report(?int $termId): array
    {
        $termId = $this->term($termId);
        $base = fn () => DB::table('bhv_records as r')->where('r.school_id', $this->school())->where('r.term_id', $termId)->where('r.status', 'active');
        $agg = 'count(*) as n, coalesce(sum(r.points), 0) as pts';
        $all = $base()->selectRaw($agg.', count(distinct r.student_id) as students')->first();
        $byCat = $base()->selectRaw('case when r.points > 0 then \'good\' else \'improve\' end as category, '.$agg.', count(distinct r.student_id) as students')
            ->groupByRaw('case when r.points > 0 then \'good\' else \'improve\' end')->get()->keyBy('category');
        $cats = [];
        foreach (self::CATEGORIES as $c) {
            $x = $byCat->get($c);
            $cats[] = ['category' => $c, 'records' => (int) ($x->n ?? 0), 'points' => (int) ($x->pts ?? 0), 'students' => (int) ($x->students ?? 0)];
        }
        $byRule = $base()->leftJoin('bhv_rules as u', function ($j) { $j->on('u.id', '=', 'r.rule_id')->on('u.school_id', '=', 'r.school_id'); })
            ->selectRaw('r.rule_id, coalesce(r.rule_label, u.label) as label, '.$agg)->groupByRaw('r.rule_id, coalesce(r.rule_label, u.label)')->orderByDesc('n')->limit(50)->get()
            ->map(fn ($x) => ['rule_id' => $x->rule_id === null ? null : (int) $x->rule_id, 'label' => $x->label ?? 'ให้คะแนนเอง', 'records' => (int) $x->n, 'points' => (int) $x->pts])->all();
        $byRoom = $base()->join('students as s', function ($j) { $j->on('s.id', '=', 'r.student_id')->on('s.school_id', '=', 'r.school_id'); })
            ->leftJoin('classrooms as c', function ($j) { $j->on('c.id', '=', DB::raw('coalesce(r.classroom_id, s.classroom_id)'))->on('c.school_id', '=', 'r.school_id'); })
            ->selectRaw('coalesce(r.classroom_id, s.classroom_id) as classroom_id, c.name, count(*) as n, coalesce(sum(case when r.points > 0 then r.points else 0 end), 0) as good, coalesce(sum(case when r.points < 0 then r.points else 0 end), 0) as improve')
            ->groupByRaw('coalesce(r.classroom_id, s.classroom_id), c.name')->orderBy('c.name')->get()
            ->map(fn ($x) => ['classroom_id' => $x->classroom_id === null ? null : (int) $x->classroom_id, 'name' => $x->name ?? 'ไม่ระบุห้อง', 'records' => (int) $x->n,
                'good_points' => (int) $x->good, 'improve_points' => (int) $x->improve, 'net_points' => (int) $x->good + (int) $x->improve])->all();
        return ['term_id' => $termId, 'totals' => ['records' => (int) $all->n, 'points' => (int) $all->pts, 'students' => (int) $all->students],
            'by_category' => $cats, 'by_rule' => $byRule, 'by_classroom' => $byRoom];
    }

    /** Rows for CSV, newest first, limited to the user's classrooms (whole result: use exportQuery() to stream it). */
    public function exportRows(?int $termId, ?int $classroomId, object $user): array
    {
        return array_map(fn ($r) => $this->exportRow($r), $this->exportQuery($termId, $classroomId, $user)->get()->all());
    }

    /** Ordered query for the CSV export; the caller chunks it (no row cap, nothing is silently dropped). */
    public function exportQuery(?int $termId, ?int $classroomId, object $user)
    {
        $termId = $this->term($termId);
        $allowed = TeacherScope::classroomIdsForTerm($user, $termId);
        if ($classroomId !== null) $this->assertClassroom($classroomId, $user, $termId);
        $q = DB::table('bhv_records as r')
            ->join('students as s', function ($j) { $j->on('s.id', '=', 'r.student_id')->on('s.school_id', '=', 'r.school_id'); })
            ->leftJoin('classrooms as c', function ($j) { $j->on('c.id', '=', DB::raw('coalesce(r.classroom_id, s.classroom_id)'))->on('c.school_id', '=', 'r.school_id'); })
            ->leftJoin('bhv_rules as u', function ($j) { $j->on('u.id', '=', 'r.rule_id')->on('u.school_id', '=', 'r.school_id'); })
            ->leftJoin('users as w', 'w.id', '=', 'r.recorded_by')
            ->where('r.school_id', $this->school())->where('r.term_id', $termId);
        $room = 'coalesce(r.classroom_id, s.classroom_id)';
        if ($classroomId !== null) $q->whereRaw("$room = ?", [$classroomId]);
        if ($allowed !== null) $q->whereRaw("$room in (".implode(',', array_map('intval', $allowed ?: [0])).')');
        $cols = ['r.occurred_on', 's.code', 's.name', 'c.name as classroom', DB::raw('coalesce(r.rule_label, u.label) as label'), 'r.points', 'r.note', 'r.status', 'r.voided_reason', 'w.name as by'];
        foreach (['first_name', 'last_name'] as $c) if (Schema::hasColumn('students', $c)) $cols[] = 's.'.$c;
        return $q->select($cols)->orderByDesc('r.occurred_on')->orderByDesc('r.id');
    }

    public function exportRow(object $r): array
    {
        $first = trim((string) ($r->first_name ?? ''));
        return [(string) $r->occurred_on, (string) $r->code, $first !== '' ? trim($first.' '.trim((string) ($r->last_name ?? ''))) : (string) $r->name,
            (string) $r->classroom, $r->label ?? 'ให้คะแนนเอง', (int) $r->points > 0 ? 'ความดี' : 'ควรปรับปรุง', (int) $r->points, (string) $r->note,
            $r->status === 'voided' ? 'ยกเลิก' : 'ใช้งาน', (string) $r->voided_reason, (string) $r->by];
    }
}
