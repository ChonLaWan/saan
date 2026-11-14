<?php

namespace App\Domain\Demo;

use App\Support\Saan\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Trial school, part 2: timetable, attendance, assessment and report cards, behaviour, health. */
final class DemoLearning
{
    /** Weekday => homeroom activity in the last period of that day (taught by the homeroom teacher in the class's own room). */
    private const ACTIVITY = [3 => 'กิจกรรมชุมนุม', 4 => 'ลูกเสือ-เนตรนารี', 5 => 'แนะแนวและโฮมรูม'];
    /** Students who appear in the portals (see DemoAcademic): they always get a few rows so the portal pages are not empty. */
    private const SHOWN = [0, 21, 60, 81];

    public function __construct(private DemoContext $c) {}

    private static function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_substr(mb_strtolower((string) preg_replace('/\s+/u', '', $v)), 0, 120);
    }

    private function homeUser(int $room): int
    {
        return (int) ($this->c->teachers[$this->c->classrooms[$room]['homeroom']]['user_id'] ?? $this->c->admin->id);
    }

    // ---------------------------------------------------------------- timetable

    /**
     * A full week (35 periods) for each of the six rooms in both terms. 32 periods are subject lessons, spread so that no teacher,
     * room or class is booked twice in a slot: the lessons form a bipartite multigraph (class - teacher) of degree 32, which always
     * has a proper colouring with 32 colours (one colour = one slot). The last period of Wednesday to Friday is a homeroom activity.
     */
    public function timetable(): void
    {
        $c = $this->c;
        $edges = [];
        foreach ($c->classrooms as $r => $room) {
            foreach (DemoNames::SUBJECTS as $key => [, , $count, , $junior, $senior]) {
                for ($i = 0; $i < $count; $i++) $edges[] = [$r, $room['level'] <= 3 ? $junior : $senior, $key];
            }
        }
        $colour = [];
        $atRoom = [];
        $atTeacher = [];
        $free = function (array $used): int { for ($k = 0; $k < 32; $k++) if (!isset($used[$k])) return $k; throw new \LogicException('timetable needs more than 32 slots'); };
        foreach ($edges as $e => [$r, $t]) {
            $a = $free($atRoom[$r] ?? []);
            $b = $free($atTeacher[$t] ?? []);
            if ($a !== $b) {
                // free colour a at the teacher: swap a and b along the alternating path that starts there (it can never reach room r)
                $path = [];
                [$node, $side, $col] = [$t, 'T', $a];
                while (($next = $side === 'T' ? ($atTeacher[$node][$col] ?? null) : ($atRoom[$node][$col] ?? null)) !== null) {
                    $path[] = $next;
                    [$node, $side] = $side === 'T' ? [$edges[$next][0], 'R'] : [$edges[$next][1], 'T'];
                    $col = $col === $a ? $b : $a;
                }
                foreach ($path as $p) unset($atRoom[$edges[$p][0]][$colour[$p]], $atTeacher[$edges[$p][1]][$colour[$p]]);
                foreach ($path as $p) {
                    $colour[$p] = $colour[$p] === $a ? $b : $a;
                    $atRoom[$edges[$p][0]][$colour[$p]] = $p;
                    $atTeacher[$edges[$p][1]][$colour[$p]] = $p;
                }
            }
            $colour[$e] = $a;
            $atRoom[$r][$a] = $e;
            $atTeacher[$t][$a] = $e;
        }
        // 32 teaching slots: every period except the last one of Wednesday, Thursday and Friday
        $slots = [];
        for ($day = 1; $day <= 5; $day++) for ($p = 1; $p <= 7; $p++) if (!($p === 7 && isset(self::ACTIVITY[$day]))) $slots[] = [$day, $p];
        // which colour goes to which slot is free: take the arrangement with the fewest "same subject three times a day"
        $best = null;
        $bestCost = PHP_INT_MAX;
        for ($seed = 0; $seed < 24; $seed++) {
            $order = range(0, 31);
            usort($order, fn ($x, $y) => DemoNames::pick('slot'.$seed.'|'.$x, 100000) <=> DemoNames::pick('slot'.$seed.'|'.$y, 100000));
            $perDay = [];
            foreach ($edges as $e => [$r, , $key]) { $k = $r.'|'.$slots[$order[$colour[$e]]][0].'|'.$key; $perDay[$k] = ($perDay[$k] ?? 0) + 1; }
            $cost = array_sum(array_map(fn ($n) => max(0, $n - 2) * 4 + max(0, $n - 1), $perDay));
            if ($cost < $bestCost) [$best, $bestCost] = [$order, $cost];
        }
        $rows = [];
        foreach ([$c->term, $c->prev] as $term) {
            foreach ($edges as $e => [$r, $t, $key]) {
                [$day, $period] = $slots[$best[$colour[$e]]];
                $special = DemoNames::SUBJECTS[$key][6];
                $place = $special === null ? 'ห้อง 10'.($r + 1) : ($key === 'science' ? $special.' '.($c->classrooms[$r]['level'] <= 3 ? 1 : 2) : $special);
                $rows[] = $this->lesson($term['id'], $t, $r, $day, $period, $place, DemoNames::SUBJECTS[$key][0]);
            }
            foreach ($c->classrooms as $r => $room) {
                foreach (self::ACTIVITY as $day => $label) {
                    if ($day === 4 && $room['level'] > 3) $label = 'กิจกรรมเพื่อสังคมและสาธารณประโยชน์';
                    $rows[] = $this->lesson($term['id'], $room['homeroom'], $r, $day, 7, 'ห้อง 10'.($r + 1), $label);
                }
            }
        }
        $c->insert('lessons', $rows);
        $c->has('timetable');
    }

    private function lesson(int $term, int $teacher, int $room, int $day, int $period, string $place, string $subject): array
    {
        $c = $this->c;
        return ['term_id' => $term, 'teacher_id' => $c->teachers[$teacher]['id'], 'classroom_id' => $c->classrooms[$room]['id'], 'period_id' => $c->periods[$period],
            'weekday' => $day, 'room' => $place, 'room_key' => self::key($place), 'subject' => $subject, 'note' => null, 'updated_by' => $c->admin->id, 'deleted_at' => null];
    }

    // ---------------------------------------------------------------- attendance

    /** The last 15 school days: every class day is confirmed by the homeroom teacher, and only the exceptions are stored (the module's own model). */
    public function attendance(): void
    {
        $c = $this->c;
        $module = $c->has('attendance', 'att_class_days');
        $days = $c->lastSchoolDays(15, CarbonImmutable::parse($c->prev['starts_on'], 'Asia/Bangkok'));
        $classDays = [];
        $marks = [];
        foreach ($days as $i => $day) {
            $date = $day->toDateString();
            foreach ($c->classrooms as $r => $room) {
                $by = $this->homeUser($r);
                $classDays[] = ['classroom_id' => $room['id'], 'date' => $date, 'confirmed_by' => $by, 'confirmed_at' => $c->at($date, '08:45'), 'student_count' => 20];
                foreach ($c->studentsOf($r) as $k => $s) {
                    $p = DemoNames::pick('att|'.$k.'|'.$date, 100);
                    $status = $p < 3 ? 'absent' : ($p < 5 ? 'late' : ($p < 6 ? 'sick' : ($p < 7 ? 'leave' : null)));
                    if (in_array($k, self::SHOWN, true) && $i === count($days) - 3) $status = 'late';
                    if (in_array($k, self::SHOWN, true) && $i === count($days) - 7) $status = 'sick';
                    if ($status === null) continue;
                    $marks[] = ['student_id' => $s['id'], 'date' => $date, 'status' => $status, 'recorded_by' => $by, 'updated_by' => $by, 'term_id' => $c->termOf($date),
                        'classroom_id' => $room['id'], 'note' => $status === 'leave' ? 'ลากิจ ผู้ปกครองแจ้งล่วงหน้า (ข้อมูลสมมติ)' : null];
                }
            }
        }
        if ($module) $c->insert('att_class_days', $classDays);
        $c->insert('attendances', $marks);
    }

    // ---------------------------------------------------------------- assessment

    /**
     * Subjects and one course per room, subject and teacher in both terms. The previous term has every score, is closed and announced
     * (so the student and guardian portals show grades, GPA and GPAX); the current term is open with the in-term score only.
     */
    public function assessment(): void
    {
        $c = $this->c;
        if (!$c->has('assessment', 'asm_subjects', 'asm_courses', 'asm_scores')) return;
        $policy = \Saan\Modules\Assessment\GradePolicy::defaults();
        $subjects = [];
        $plan = [];
        foreach ([[$c->prev, 'closed'], [$c->term, 'open']] as [$term, $status]) {
            foreach ($c->classrooms as $r => $room) {
                foreach (DemoNames::SUBJECTS as $key => [$label, $letter, $count, $credit, $junior, $senior]) {
                    $level = $room['level'];
                    $code = $letter.($level <= 3 ? '2'.$level : '3'.($level - 3)).'10'.$term['term'];
                    $name = $label.' '.((($level - 1) % 3) * 2 + $term['term']);
                    $subjects[$code] = ['code' => $code, 'code_key' => mb_strtolower($code), 'name' => $name, 'group_name' => $label, 'credit' => $credit,
                        'hours_per_year' => $count * 20, 'grade_levels' => [$room['grade']], 'is_active' => true];
                    $plan[] = [$term, $status, $r, $key, $code, $name, $credit, $level <= 3 ? $junior : $senior];
                }
            }
        }
        $c->insert('asm_subjects', array_values($subjects));
        $subjectId = DB::table('asm_subjects')->where('school_id', $c->school)->pluck('id', 'code');
        $now = now();
        $rows = [];
        foreach ($plan as [$term, $status, $r, , $code, $name, $credit, $teacher]) {
            $closed = $status === 'closed';
            $rows[] = ['term_id' => $term['id'], 'subject_id' => (int) $subjectId[$code], 'classroom_id' => $c->classrooms[$r]['id'], 'teacher_id' => $c->teachers[$teacher]['id'],
                'policy' => $policy, 'status' => $status, 'snap_at' => $closed ? $now : null, 'subject_code_snap' => $closed ? $code : null,
                'subject_name_snap' => $closed ? $name : null, 'credit_snap' => $closed ? $credit : null];
        }
        $c->insert('asm_courses', $rows);
        $courseId = [];
        foreach (DB::table('asm_courses')->where('school_id', $c->school)->get(['id', 'term_id', 'subject_id', 'classroom_id']) as $row) $courseId[$row->term_id.'|'.$row->subject_id.'|'.$row->classroom_id] = (int) $row->id;

        $scores = [];
        $special = null;
        foreach ($plan as [$term, $status, $r, $key, $code, , , $teacher]) {
            $course = $courseId[$term['id'].'|'.$subjectId[$code].'|'.$c->classrooms[$r]['id']];
            $by = $c->teachers[$teacher]['user_id'];
            foreach ($c->studentsOf($r) as $k => $s) {
                $ability = 0.5 + DemoNames::pick('ability'.$k, 45) / 100;
                $shift = fn (string $part) => (DemoNames::pick($part.$k.$key.$term['id'], 17) - 8) / 100;
                $continuous = (int) round(70 * max(0.3, min(1, $ability + $shift('c'))));
                $scores[] = ['course_id' => $course, 'student_id' => $s['id'], 'component_key' => 'continuous', 'score_centi' => $continuous * 100, 'updated_by' => $by];
                if ($status !== 'closed') continue;
                $exam = (int) round(30 * max(0.2, min(1, $ability - 0.05 + $shift('e'))));
                $scores[] = ['course_id' => $course, 'student_id' => $s['id'], 'component_key' => 'exam', 'score_centi' => $exam * 100, 'updated_by' => $by];
                if ($k === 45 && $key === 'math') $special = ['course_id' => $course, 'student_id' => $s['id'], 'code' => 'ร', 'note' => 'ส่งงานไม่ครบ รอแก้ไข (ข้อมูลสมมติ)', 'updated_by' => $by];
            }
        }
        $c->insert('asm_scores', $scores);

        // desirable characteristics, competencies and reading-thinking-writing of the closed term (entered per student by the homeroom teacher)
        if (isset($c->columns('asm_attributes')['kind'])) {
            $rows = [];
            foreach ($c->students as $k => $s) {
                foreach (['characteristic' => 8, 'competency' => 5, 'reading' => 1] as $kind => $items) {
                    for ($i = 1; $i <= $items; $i++) {
                        $rows[] = ['term_id' => $c->prev['id'], 'course_id' => 0, 'student_id' => $s['id'], 'kind' => $kind, 'item_code' => strtoupper($kind[0] === 'c' && $kind[1] === 'o' ? 'K' : $kind[0]).$i,
                            'level' => [3, 3, 2, 2, 2, 1][DemoNames::pick('attr'.$k.$kind.$i, 6)], 'updated_by' => $this->homeUser($s['room'])];
                    }
                }
            }
            $c->insert('asm_attributes', $rows);
        }

        if ($c->has('reports-pp', 'rpt_remarks')) {
            $remarks = ['ตั้งใจเรียน รับผิดชอบงานที่ได้รับมอบหมายดี', 'มีน้ำใจช่วยเหลือเพื่อน ควรทบทวนบทเรียนสม่ำเสมอ', 'กล้าแสดงความคิดเห็น มีความเป็นผู้นำ', 'เรียบร้อย มาเรียนสม่ำเสมอ ควรฝึกทำแบบฝึกหัดเพิ่ม',
                'สนใจกิจกรรมของโรงเรียน ควรแบ่งเวลาอ่านหนังสือ', 'มีพัฒนาการดีขึ้นตลอดภาคเรียน'];
            $rows = [];
            foreach ($c->students as $k => $s) $rows[] = ['term_id' => $c->prev['id'], 'student_id' => $s['id'], 'remark' => $remarks[DemoNames::pick('remark'.$k, 6)].' (ข้อมูลสมมติ)', 'updated_by' => $this->homeUser($s['room'])];
            $c->insert('rpt_remarks', $rows);
            if ($special) $c->insert('rpt_special_results', [$special]);
            Settings::put('reports.signers', [['label' => 'ผู้อำนวยการโรงเรียน', 'name' => $c->teachers[0]['name']], ['label' => 'นายทะเบียน', 'name' => $c->teachers[10]['name']]]);
        }
        // results of the closed term become visible to students and guardians only after this announcement
        $at = now()->subDay()->format('Y-m-d H:i:s');
        if (class_exists(\Saan\Modules\GuardianPortal\Announce::class)) \Saan\Modules\GuardianPortal\Announce::set((int) $c->prev['id'], $at);
        else Settings::put('student.announce.'.$c->prev['id'], ['at' => $at]);
    }

    // ---------------------------------------------------------------- behaviour

    public function behavior(): void
    {
        $c = $this->c;
        if (!$c->has('behavior', 'bhv_rules', 'bhv_records')) return;
        app(\Saan\Modules\Behavior\BehaviorRepository::class)->ensureRules();
        $rules = DB::table('bhv_rules')->where('school_id', $c->school)->where('is_active', true)->orderBy('id')->get()->all();
        if (!$rules) return;
        $good = array_values(array_filter($rules, fn ($r) => $r->points > 0));
        $improve = array_values(array_filter($rules, fn ($r) => $r->points < 0));
        $days = $c->lastSchoolDays(20, CarbonImmutable::parse($c->prev['starts_on'], 'Asia/Bangkok'));
        $row = function (int $k, object $rule, CarbonImmutable $day, ?string $note = null) use ($c) {
            $s = $c->students[$k];
            $date = $day->toDateString();
            return ['term_id' => $c->termOf($date) ?? $c->term['id'], 'student_id' => $s['id'], 'rule_id' => $rule->id, 'occurred_on' => $date, 'points' => (int) $rule->points,
                'note' => $note, 'recorded_by' => $this->homeUser($s['room']), 'status' => 'active', 'guardian_visible' => true, 'classroom_id' => $c->classrooms[$s['room']]['id'],
                'rule_label' => $rule->label];
        };
        $rows = [];
        for ($i = 0; $i < 70; $i++) {
            $k = DemoNames::pick('bhv-student'.$i, 120);
            $pool = DemoNames::pick('bhv-kind'.$i, 10) < 6 ? $good : $improve;
            if (!$pool) continue;
            $rows[] = $row($k, $pool[DemoNames::pick('bhv-rule'.$i, count($pool))], $days[DemoNames::pick('bhv-day'.$i, count($days))]);
        }
        foreach (self::SHOWN as $k) {
            if ($good) $rows[] = $row($k, $good[$k % count($good)], $days[count($days) - 1], 'ช่วยครูจัดเตรียมห้องเรียน (ข้อมูลสมมติ)');
            if ($improve) $rows[] = $row($k, $improve[$k % count($improve)], $days[max(0, count($days) - 4)], 'ตักเตือนแล้ว ปรับปรุงตัวดี (ข้อมูลสมมติ)');
        }
        $c->insert('bhv_records', $rows);
    }

    // ---------------------------------------------------------------- health

    /** Weight and height of every student (the class grid): two rounds in the closed term, one in the current term; a few health profiles. */
    public function health(): void
    {
        $c = $this->c;
        if (!$c->has('health', 'hlth_measurements', 'hlth_profiles')) return;
        $start = fn (array $term, int $days) => min(CarbonImmutable::parse($term['starts_on'], 'Asia/Bangkok')->addDays($days)->toDateString(), $c->today->toDateString());
        $rounds = [[$c->prev['id'], 1, $start($c->prev, 20), 0], [$c->prev['id'], 2, $start($c->prev, 120), 1], [$c->term['id'], 1, $start($c->term, 10), 2]];
        $rows = [];
        foreach ($c->students as $k => $s) {
            $level = $c->classrooms[$s['room']]['level'];
            $height = ($s['sex'] === 'M' ? 146 + $level * 4.5 : 147 + $level * 2.6) + DemoNames::pick('h'.$k, 15) - 7;
            $weight = ($height - 104) * 0.92 + DemoNames::pick('w'.$k, 15) - 5;
            foreach ($rounds as [$term, $round, $date, $step]) {
                $rows[] = ['term_id' => $term, 'round' => $round, 'student_id' => $s['id'], 'measured_on' => $date,
                    'weight_kg' => number_format($weight + $step * (0.4 + DemoNames::pick('wg'.$k, 6) / 10), 2, '.', ''),
                    'height_cm' => number_format($height + $step * (0.5 + DemoNames::pick('hg'.$k, 8) / 10), 1, '.', ''), 'recorded_by' => $this->homeUser($s['room'])];
            }
        }
        $c->insert('hlth_measurements', $rows);
        $crypt = \Saan\Modules\Health\ProfileCrypt::class;
        $rows = [];
        foreach ($c->students as $k => $s) {
            if ($k % 9 !== 0 && !in_array($k, self::SHOWN, true)) continue;
            $rows[] = ['student_id' => $s['id'], 'blood_group' => ['A', 'B', 'AB', 'O'][DemoNames::pick('blood'.$k, 4)],
                'allergies' => $k % 2 === 0 ? $crypt::encrypt('แพ้อาหารทะเล (ข้อมูลสมมติ)') : null, 'chronic_conditions' => $k % 18 === 0 ? $crypt::encrypt('หอบหืด พกยาพ่นประจำตัว (ข้อมูลสมมติ)') : null,
                'medications' => null, 'emergency_note' => $crypt::encrypt('ติดต่อผู้ปกครองก่อนให้ยาทุกครั้ง (ข้อมูลสมมติ)'), 'updated_by' => $this->homeUser($s['room'])];
        }
        $c->insert('hlth_profiles', $rows);
    }
}
