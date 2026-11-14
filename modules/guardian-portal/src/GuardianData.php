<?php

namespace Saan\Modules\GuardianPortal;

use App\Support\Saan\TermScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\Assessment\GradePolicy;
use Saan\Modules\Behavior\BehaviorReadModel;

/**
 * Read model for the guardian pages. Every method returns a hand-picked whitelist of fields: never student codes or any
 * identifier, documents, the health profile, pickup information, staff names, other children or other guardians.
 * The CALLER (GuardianController) has already checked that the student is one of the signed-in guardian's viewable students.
 */
final class GuardianData
{
    public const ATT_LABELS = ['absent' => 'ขาด', 'late' => 'สาย', 'leave' => 'ลา', 'sick' => 'ป่วย'];
    public const RESULT_STATUSES = ['closed', 'published'];

    public static function validMonth(?string $month): ?string
    {
        if ($month === null || $month === '') return now('Asia/Bangkok')->format('Y-m');
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $month)) return null;
        return $month > now('Asia/Bangkok')->format('Y-m') ? null : $month;
    }

    /** True when the module is switched on (same source as route loading) and its table exists. Migrations and classes of inactive modules are always present. */
    public static function moduleData(string $module, string ...$tables): bool
    {
        if (!app(\App\Support\Modules\ModuleRegistry::class)->active($module)) return false;
        foreach ($tables as $t) if (!Schema::hasTable($t)) return false;
        return true;
    }

    /** @param int[] $studentIds */
    public static function children(array $studentIds): array
    {
        if (!$studentIds) return [];
        $rows = DB::table('students as s')->leftJoin('classrooms as c', 'c.id', '=', 's.classroom_id')
            ->where('s.school_id', Access::school())->whereIn('s.id', $studentIds)->orderBy('s.id')
            ->get(['s.id', 's.name', 's.first_name', 's.last_name', 'c.name as classroom']);
        return $rows->map(function ($r) {
            $full = trim(trim((string) $r->first_name).' '.trim((string) $r->last_name));
            return ['id' => (int) $r->id, 'name' => $full !== '' ? $full : (string) $r->name, 'classroom' => $r->classroom];
        })->all();
    }

    private static function acked(int $guardianId, int $studentId, string $kind): array
    {
        return DB::table('gp_acks')->where('school_id', Access::school())->where('guardian_id', $guardianId)->where('student_id', $studentId)->where('kind', $kind)
            ->get(['ref_id', 'ref_month', 'acknowledged_at'])->all();
    }

    public static function attendance(int $guardianId, int $studentId, string $month): array
    {
        if (!self::moduleData('attendance', 'attendances')) {
            return ['available' => false, 'month' => $month, 'confirmed_days' => 0, 'counts' => array_fill_keys(array_keys(self::ATT_LABELS), 0), 'exceptions' => [],
                'acknowledged' => false, 'acknowledged_at' => null];
        }
        $school = Access::school();
        $from = $month.'-01';
        $to = date('Y-m-t', strtotime($from));
        $rows = DB::table('attendances')->where('school_id', $school)->where('student_id', $studentId)->whereBetween('date', [$from, $to])
            ->whereIn('status', array_keys(self::ATT_LABELS))->orderBy('date')->get(['date', 'status']);
        $days = 0;
        if (Schema::hasTable('att_class_days')) {
            // a confirmed day counts for the room the child was in on that date (room history) and not before admission, like the teacher's report
            $stu = DB::table('students')->where('school_id', $school)->where('id', $studentId)->first();
            $room = $stu?->classroom_id;
            $moves = [];
            if (Schema::hasTable('student_classroom_moves')) {
                foreach (DB::table('student_classroom_moves')->where('school_id', $school)->where('student_id', $studentId)->where('effective_on', '>', $from)->orderBy('effective_on')->orderBy('id')->get() as $mv) {
                    $moves[] = ['on' => substr((string) $mv->effective_on, 0, 10), 'from' => $mv->from_classroom_id === null ? null : (int) $mv->from_classroom_id, 'to' => $mv->to_classroom_id === null ? null : (int) $mv->to_classroom_id];
                }
            }
            $rooms = array_values(array_unique(array_filter(array_merge([(int) $room], array_column($moves, 'from'), array_column($moves, 'to')))));
            $since = !empty($stu?->admitted_on) ? substr((string) $stu->admitted_on, 0, 10) : null;
            if ($rooms) {
                foreach (DB::table('att_class_days')->where('school_id', $school)->whereIn('classroom_id', $rooms)->whereBetween('date', [$from, $to])->whereNotNull('confirmed_at')->get(['classroom_id', 'date']) as $cd) {
                    $d = substr((string) $cd->date, 0, 10);
                    if ($since !== null && $d < $since) continue;
                    if (\App\Support\Saan\ClassroomMoves::roomOn($moves, $room ? (int) $room : null, $d) === (int) $cd->classroom_id) $days++;
                }
            }
        }
        $ack = collect(self::acked($guardianId, $studentId, 'attendance_month'))->firstWhere('ref_month', $month);
        $counts = array_fill_keys(array_keys(self::ATT_LABELS), 0);
        $exceptions = [];
        foreach ($rows as $r) {
            $counts[$r->status]++;
            $exceptions[] = ['date' => substr((string) $r->date, 0, 10), 'status' => $r->status, 'label' => self::ATT_LABELS[$r->status]];
        }
        return ['available' => true, 'month' => $month, 'confirmed_days' => $days, 'counts' => $counts, 'exceptions' => $exceptions,
            'acknowledged' => (bool) $ack, 'acknowledged_at' => $ack ? (string) $ack->acknowledged_at : null];
    }

    public static function behavior(int $guardianId, int $studentId): array
    {
        if (!self::moduleData('behavior', 'bhv_records') || !class_exists(BehaviorReadModel::class)) return ['available' => false, 'term_id' => null, 'records' => [], 'totals' => null];
        $data = (new BehaviorReadModel())->forStudent($studentId, null, true);
        $mine = collect(self::acked($guardianId, $studentId, 'behavior'))->keyBy('ref_id');
        $records = array_map(function (array $r) use ($mine) {
            $own = $mine->get($r['id']);
            // per guardian: only this guardian's own gp_acks row counts; family_acknowledged tells that somebody else already did
            return ['id' => $r['id'], 'occurred_on' => $r['occurred_on'], 'points' => $r['points'], 'category' => $r['category'], 'label' => $r['label'], 'note' => $r['note'],
                'acknowledged' => $own !== null, 'acknowledged_at' => $own ? (string) $own->acknowledged_at : null,
                'family_acknowledged' => $own !== null || ($r['guardian_ack_at'] ?? null) !== null];
        }, $data['records']);
        $t = $data['totals'];
        return ['available' => true, 'term_id' => $data['term_id'], 'records' => $records,
            'totals' => ['good_points' => $t['good_points'], 'improve_points' => $t['improve_points'], 'net_points' => $t['net_points']]];
    }

    /** The student's own projection: no teacher note and no guardian acknowledgement state (the student screen shows neither). */
    public static function behaviorForStudent(int $studentId): array
    {
        if (!self::moduleData('behavior', 'bhv_records') || !class_exists(BehaviorReadModel::class)) return ['available' => false, 'term_id' => null, 'records' => [], 'totals' => null];
        $data = (new BehaviorReadModel())->forStudent($studentId, null, true);
        $records = array_map(fn (array $r) => ['id' => $r['id'], 'occurred_on' => $r['occurred_on'], 'points' => $r['points'], 'category' => $r['category'], 'label' => $r['label']], $data['records']);
        $t = $data['totals'];
        return ['available' => true, 'term_id' => $data['term_id'], 'records' => $records,
            'totals' => ['good_points' => $t['good_points'], 'improve_points' => $t['improve_points'], 'net_points' => $t['net_points']]];
    }

    /** Courses of closed/published status only; an open course never shows scores. */
    public static function results(int $guardianId, int $studentId): array
    {
        if (!self::moduleData('assessment', 'asm_courses', 'asm_scores')) return ['available' => false, 'terms' => []];
        $school = Access::school();
        $courseIds = DB::table('asm_scores')->where('school_id', $school)->where('student_id', $studentId)->distinct()->pluck('course_id')->all();
        // ร / มผ / มส of reports-pp override the computed grade in the official ปพ., so the portal must show the same value
        $special = collect();
        if (self::moduleData('reports-pp', 'rpt_special_results')) {
            $special = DB::table('rpt_special_results')->where('school_id', $school)->where('student_id', $studentId)->whereNotNull('code')->pluck('code', 'course_id');
            $courseIds = array_values(array_unique(array_merge($courseIds, $special->keys()->map(fn ($v) => (int) $v)->all())));
        }
        if (!$courseIds) return ['available' => true, 'terms' => []];
        $courses = DB::table('asm_courses as c')->leftJoin('asm_subjects as s', 's.id', '=', 'c.subject_id')
            ->where('c.school_id', $school)->whereIn('c.id', $courseIds)->whereIn('c.status', self::RESULT_STATUSES)->orderBy('c.term_id')->orderBy('s.name')
            ->get(['c.id', 'c.term_id', 'c.policy', 's.name as subject'])
            // F8: only terms the school has announced (Announce); a closed course of a term that is not announced yet stays hidden
            ->filter(fn ($c) => Announce::isAnnounced((int) $c->term_id))->values();
        $scores = DB::table('asm_scores')->where('school_id', $school)->where('student_id', $studentId)->whereIn('course_id', $courses->pluck('id')->all())
            ->get(['course_id', 'component_key', 'score_centi'])->groupBy('course_id');
        $terms = DB::table('academic_terms')->where('school_id', $school)->whereIn('id', $courses->pluck('term_id')->unique()->all())->get(['id', 'year', 'term'])->keyBy('id');
        $mine = collect(self::acked($guardianId, $studentId, 'term_result'))->keyBy('ref_id');
        $out = [];
        foreach ($courses as $c) {
            $policy = json_decode((string) $c->policy, true) ?: [];
            $byKey = [];
            foreach ($scores->get($c->id, collect()) as $s) $byKey[$s->component_key] = $s->score_centi === null ? null : (int) $s->score_centi;
            try {
                $calc = GradePolicy::compute($policy, $byKey);
            } catch (\InvalidArgumentException) {
                $calc = ['status' => 'incomplete', 'total' => null, 'grade' => null, 'passed' => null];
            }
            $components = [];
            foreach ($policy['components'] ?? [] as $comp) {
                $v = $byKey[$comp['key'] ?? ''] ?? null;
                $components[] = ['label' => (string) ($comp['label'] ?? ''), 'max' => (int) ($comp['max'] ?? 0), 'score' => $v === null ? null : $v / 100];
            }
            $tid = (int) $c->term_id;
            $t = $terms->get($tid);
            $own = $mine->get($tid);
            $out[$tid] ??= ['term_id' => $tid, 'year' => $t->year ?? null, 'term' => $t->term ?? null, 'acknowledged' => (bool) $own,
                'acknowledged_at' => $own ? (string) $own->acknowledged_at : null, 'courses' => []];
            $code = $special->get($c->id);
            $out[$tid]['courses'][] = $code !== null
                ? ['subject' => (string) $c->subject, 'complete' => true, 'total' => null, 'grade' => (string) $code, 'passed' => false, 'special' => (string) $code, 'components' => $components]
                : ['subject' => (string) $c->subject, 'complete' => $calc['status'] === 'complete', 'total' => $calc['total'], 'grade' => $calc['grade'],
                    'passed' => $calc['passed'], 'special' => null, 'components' => $components];
        }
        return ['available' => true, 'terms' => array_values(array_reverse($out))];
    }

    /** Weight and height only. The health profile (allergies, conditions, medication, emergency note) is never read here. */
    public static function health(int $studentId): array
    {
        if (!self::moduleData('health', 'hlth_measurements')) return ['available' => false, 'measurements' => []];
        $rows = DB::table('hlth_measurements')->where('school_id', Access::school())->where('student_id', $studentId)
            ->orderByDesc('measured_on')->orderByDesc('id')->limit(12)->get(['measured_on', 'weight_kg', 'height_cm']);
        return ['available' => true, 'measurements' => $rows->map(fn ($r) => [
            'measured_on' => substr((string) $r->measured_on, 0, 10),
            'weight_kg' => $r->weight_kg === null ? null : (float) $r->weight_kg, 'height_cm' => $r->height_cm === null ? null : (float) $r->height_cm,
        ])->all()];
    }

    /** Current term id helper for staff status. */
    public static function currentTermId(): ?int
    {
        return TermScope::currentId();
    }
}
