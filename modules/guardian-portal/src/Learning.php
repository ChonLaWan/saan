<?php

namespace Saan\Modules\GuardianPortal;

use App\Support\Saan\Settings;
use App\Support\Saan\TermScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Saan\Modules\ReportsPp\Calc;
use Saan\Modules\ReportsPp\ReportsPp;
use Saan\Modules\Timetable\Timetable;

/**
 * F8 read model shared by the student surface and the guardian portal: results (grade, credit, term GPA, cumulative GPAX, ร/มผ/มส,
 * attributes, rank) and the timetable of the student's classroom. Callers have already proved that the actor may see this student.
 *
 * Results use the reports-pp functions themselves (ReportsPp::compute -> Calc::gpa, Calc::ranks) so a number here can never differ from the
 * printed ปพ.6. Only courses with status closed/published count, and only terms the school has announced (Announce).
 * Hand-picked fields only: no codes, no identifiers, no staff names.
 */
final class Learning
{
    public static function empty(): array
    {
        return ['available' => false, 'show_rank' => false, 'pp6' => false, 'gpax' => null, 'terms' => []];
    }

    /** @param int|null $guardianId when given, each term carries that guardian's own acknowledgement state */
    public static function report(int $studentId, ?int $guardianId = null): array
    {
        if (!GuardianData::moduleData('assessment', 'asm_courses', 'asm_scores') || !GuardianData::moduleData('reports-pp') || !class_exists(ReportsPp::class)) return self::empty();
        $school = Access::school();
        $s = DB::table('students')->where('school_id', $school)->where('id', $studentId)->first(['id', 'classroom_id']);
        if (!$s) return self::empty();
        $rp = new ReportsPp();
        $set = $rp->settings();
        $pp6 = Announce::showPp6();
        $current = TermScope::currentId();
        $acks = $guardianId === null ? collect() : collect(DB::table('gp_acks')->where('school_id', $school)->where('guardian_id', $guardianId)->where('student_id', $studentId)
            ->where('kind', 'term_result')->get(['ref_id', 'acknowledged_at']))->keyBy('ref_id');
        $items = [];
        $out = [];
        foreach (DB::table('academic_terms')->where('school_id', $school)->orderByDesc('year')->orderByDesc('term')->orderByDesc('id')->get() as $term) {
            $tid = (int) $term->id;
            if (!Announce::isAnnounced($tid)) continue;
            $room = $tid === $current ? ($s->classroom_id ? (int) $s->classroom_id : null) : ($rp->dominantRooms([$studentId], $tid)[$studentId] ?? null);
            $closed = $rp->studentCourses($studentId, $tid, $room)->filter(fn ($c) => in_array($c->status, GuardianData::RESULT_STATUSES, true))->values();
            if ($closed->isEmpty()) continue;
            $calc = $rp->compute($closed, [['id' => $studentId]]);
            $lines = [];
            $termItems = [];
            foreach ($calc['courses'] as $cid => $ci) {
                $res = $calc['results'][$studentId][$cid];
                if ($res['state'] === 'none') continue;
                $graded = $res['state'] === 'graded';
                $lines[] = ['subject' => (string) $ci['name'], 'code' => (string) $ci['code'], 'credit' => $ci['credit_tenths'] === null ? null : $ci['credit_tenths'] / 10,
                    'state' => $res['state'], 'grade' => $graded || $res['state'] === 'special' ? (string) $res['grade'] : null, 'total' => $graded ? $res['total'] : null,
                    'special' => $res['special'], 'special_label' => $res['special'] ? (Calc::SPECIAL[$res['special']] ?? null) : null];
                $termItems[] = ['credit' => $ci['credit_tenths'], 'grade' => $graded ? $res['grade'] : null];
            }
            if (!$lines) continue;
            array_push($items, ...$termItems);
            $g = $calc['gpa'][$studentId];
            $rank = null;
            if ($set['show_rank'] && $room) {
                $cc = $rp->classroomCourses($room, $tid)->filter(fn ($c) => in_array($c->status, GuardianData::RESULT_STATUSES, true))->values();
                $cs = $rp->roster($room, $term, $cc->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
                $pool = array_replace($rp->compute($cc, $cs)['gpa'], [$studentId => $g]);
                $centi = array_map(fn ($x) => $x['gpa_centi'], $pool);
                $ranks = Calc::ranks($centi);
                $rank = ['position' => $ranks[$studentId] ?? null, 'of' => count(array_filter($centi, fn ($v) => $v !== null))];
            }
            $attr = $rp->termAttributes([$studentId], $tid, 0)[$studentId] ?? [];
            $attributes = [];
            foreach (['characteristic', 'reading'] as $kind) {
                foreach ($attr[$kind] ?? [] as $a) $attributes[] = ['kind' => $kind, 'kind_label' => ReportsPp::ATTR_KINDS[$kind], 'code' => (string) $a['code'], 'level' => $a['level'], 'level_label' => ReportsPp::LEVELS[$a['level']] ?? ''];
            }
            $own = $acks->get($tid);
            $row = ['term_id' => $tid, 'year' => (int) $term->year, 'term' => (int) $term->term, 'courses' => $lines,
                'gpa' => $g['gpa_centi'] === null ? null : Calc::fmtCenti($g['gpa_centi']), 'credits' => $g['credits_tenths'] / 10, 'rank' => $rank, 'attributes' => $attributes];
            if ($guardianId !== null) $row += ['acknowledged' => (bool) $own, 'acknowledged_at' => $own ? (string) $own->acknowledged_at : null];
            if ($pp6) {
                $att = $rp->attendance([$studentId], $term, [$studentId => (int) ($room ?? 0)]);
                $row['pp6'] = ['attendance' => $att === null ? null : ($att[$studentId] ?? []), 'remark' => (string) ($rp->remarks([$studentId], $tid)[$studentId]['remark'] ?? '')];
            }
            $out[] = $row;
        }
        $gpax = Calc::gpa($items, $set['gpa_rounding']);
        return ['available' => true, 'show_rank' => (bool) $set['show_rank'], 'pp6' => $pp6, 'terms' => $out,
            'gpax' => $gpax['gpa_centi'] === null ? null : ['value' => Calc::fmtCenti($gpax['gpa_centi']), 'credits' => $gpax['credits_tenths'] / 10]];
    }

    /** Timetable of the student's classroom for the current term (deleted periods excluded). No teacher names. */
    public static function timetable(int $studentId): array
    {
        $none = ['available' => false, 'term' => null, 'classroom' => null, 'periods' => [], 'days' => []];
        if (!GuardianData::moduleData('timetable', 'lessons', 'bell_periods') || !class_exists(Timetable::class)) return $none;
        $school = Access::school();
        $s = DB::table('students')->where('school_id', $school)->where('id', $studentId)->first(['id', 'classroom_id']);
        $term = TermScope::current();
        if (!$s || !$s->classroom_id || !$term) return ['available' => true] + $none;
        $room = DB::table('classrooms')->where('school_id', $school)->where('id', $s->classroom_id)->value('name');
        $q = DB::table('lessons as l')->join('bell_periods as p', 'p.id', '=', 'l.period_id')
            ->where('l.school_id', $school)->where('l.term_id', $term->id)->where('l.classroom_id', $s->classroom_id);
        $rows = Timetable::liveLessons($q, 'l')->orderBy('l.weekday')->orderBy('p.number')->orderBy('l.id')
            ->get(['l.weekday', 'p.number', 'p.starts_at', 'p.ends_at', 'l.subject', 'l.room', 'l.room_key']); // l.note is free teacher text (substitutions, names): never sent out
        $hm = fn ($v) => substr((string) $v, 0, 5);
        $days = [];
        foreach ($rows as $r) {
            $d = (int) $r->weekday;
            $days[$d]['weekday'] = $d;
            $days[$d]['name'] = Timetable::DAYS[$d] ?? (string) $d;
            $days[$d]['lessons'][] = ['period' => (int) $r->number, 'starts_at' => $hm($r->starts_at), 'ends_at' => $hm($r->ends_at), 'subject' => (string) $r->subject, 'room' => Timetable::roomLabel($r)];
        }
        ksort($days);
        $periods = DB::table('bell_periods')->where('school_id', $school)->orderBy('starts_at')->orderBy('number')->get(['number', 'starts_at', 'ends_at'])
            ->map(fn ($p) => ['number' => (int) $p->number, 'starts_at' => $hm($p->starts_at), 'ends_at' => $hm($p->ends_at)])->all();
        return ['available' => true, 'term' => ['id' => (int) $term->id, 'year' => (int) $term->year, 'term' => (int) $term->term], 'classroom' => $room, 'periods' => $periods, 'days' => array_values($days)];
    }

    /** Rate-limited audit of a view (one row per actor, student and part every 10 minutes), like the other read audits. */
    public static function auditOnce(string $key, \Closure $write): void
    {
        if (Cache::add('portal-view:'.sha1($key), 1, 600)) $write();
    }
}
