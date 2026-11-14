<?php

namespace Saan\Modules\Behavior;

use App\Support\Saan\TermScope;
use Illuminate\Support\Facades\DB;

/**
 * Stable read API for other modules (guardian portal, reports). Read only: never write bhv_* from here.
 *
 * forStudent() returns:
 * [
 *   'student_id' => int, 'term_id' => ?int,
 *   'records' => [ ['id','occurred_on','points','category','rule_id','label','note','status','voided_reason',
 *                   'guardian_visible','guardian_ack_at','guardian_ack_by','guardian_ack_note','recorded_by_name','version'], ... ]  newest first,
 *   'totals'  => ['good_points' => int>=0, 'improve_points' => int<=0, 'net_points' => int, 'records' => int (active only), 'voided' => int],
 * ]
 * Staff mode lists voided records (status 'voided') but never counts them in totals. $guardianVisibleOnly (guardian portal) returns
 * only active, guardian-visible records: voided and hidden rows, and any voided_reason, never leave this class in that mode.
 * To acknowledge a record, use BehaviorAcknowledgement (this module owns bhv_records; other modules never write it).
 * The caller is responsible for deciding who may see the student; this class only filters by school.
 */
class BehaviorReadModel
{
    public function forStudent(int $studentId, ?int $termId = null, bool $guardianVisibleOnly = false): array
    {
        $school = (int) config('school.school_id');
        $termId ??= TermScope::currentId();
        $empty = ['good_points' => 0, 'improve_points' => 0, 'net_points' => 0, 'records' => 0, 'voided' => 0];
        if ($termId === null) return ['student_id' => $studentId, 'term_id' => null, 'records' => [], 'totals' => $empty];

        $q = DB::table('bhv_records as r')
            ->leftJoin('bhv_rules as u', function ($j) { $j->on('u.id', '=', 'r.rule_id')->on('u.school_id', '=', 'r.school_id'); })
            ->leftJoin('users as w', 'w.id', '=', 'r.recorded_by')
            ->where('r.school_id', $school)->where('r.student_id', $studentId)->where('r.term_id', $termId);
        if ($guardianVisibleOnly) $q->where('r.guardian_visible', true)->where('r.status', 'active');
        $rows = $q->orderByDesc('r.occurred_on')->orderByDesc('r.id')
            ->get(['r.id', 'r.occurred_on', 'r.points', 'r.rule_id', DB::raw('coalesce(r.rule_label, u.label) as label'), 'r.note', 'r.status', 'r.voided_reason', 'r.guardian_visible',
                'r.guardian_ack_at', 'r.guardian_ack_by', 'r.guardian_ack_note', 'w.name as recorded_by_name', 'r.version']);

        $records = [];
        $totals = $empty;
        foreach ($rows as $r) {
            $points = (int) $r->points;
            $records[] = [
                'id' => (int) $r->id, 'occurred_on' => (string) $r->occurred_on, 'points' => $points,
                'category' => $points >= 0 ? 'good' : 'improve', 'rule_id' => $r->rule_id === null ? null : (int) $r->rule_id,
                'label' => $r->label, 'note' => $r->note, 'status' => (string) $r->status, 'voided_reason' => $guardianVisibleOnly ? null : $r->voided_reason,
                'guardian_visible' => (bool) $r->guardian_visible, 'guardian_ack_at' => $r->guardian_ack_at, 'guardian_ack_by' => $r->guardian_ack_by,
                'guardian_ack_note' => $r->guardian_ack_note,
                'recorded_by_name' => $r->recorded_by_name, 'version' => (int) $r->version,
            ];
            if ($r->status === 'voided') { $totals['voided']++; continue; }
            $totals['records']++;
            if ($points >= 0) $totals['good_points'] += $points; else $totals['improve_points'] += $points;
        }
        $totals['net_points'] = $totals['good_points'] + $totals['improve_points'];
        return ['student_id' => $studentId, 'term_id' => $termId, 'records' => $records, 'totals' => $totals];
    }
}
