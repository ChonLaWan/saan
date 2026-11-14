<?php

namespace Saan\Modules\GuardianPortal;

use App\Support\Saan\Mutation;
use App\Support\Saan\VersionConflict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "รับทราบ" by a guardian. One gp_acks row per guardian + student + kind + reference (unique index), so pressing twice,
 * a retry or two tabs all end with ONE acknowledgement and the same 200 answer (already = true on the repeats).
 *
 * Narrow write into another module: stampBehavior() below is the ONLY place that touches bhv_records, and only the columns
 * guardian_ack_at / guardian_ack_by ("guardian:<id>") / guardian_ack_note, versioned and audited. The target was already checked
 * (active, guardian-visible, the guardian's child). If another guardian or a teacher acknowledged the record first, this
 * guardian still gets their own gp_acks row (with their own note) and bhv_records keeps the first acknowledgement.
 */
final class Acks
{
    public const KINDS = ['behavior', 'attendance_month', 'term_result'];

    /** @return array{kind:string,ref_id:?int,ref_month:?string,acknowledged:bool,already:bool,acknowledged_at:?string} */
    public static function acknowledge(Request $request, int $guardianId, int $studentId, string $kind, ?int $refId, ?string $refMonth, ?string $note): array
    {
        if (!in_array($kind, self::KINDS, true)) abort(422, 'ชนิดการรับทราบไม่ถูกต้อง');
        if ($kind === 'attendance_month') {
            $refId = null;
            if (GuardianData::validMonth($refMonth) === null || $refMonth === null || $refMonth === '') abort(422, 'เดือนไม่ถูกต้อง');
        } else {
            $refMonth = null;
            if (!$refId) abort(422, 'ต้องระบุรายการที่รับทราบ');
        }
        self::assertTarget($studentId, $kind, $refId, $refMonth);
        $refKey = ($refId ?? 0).'|'.($refMonth ?? '');
        $find = fn () => DB::table('gp_acks')->where('school_id', Access::school())->where('guardian_id', $guardianId)->where('student_id', $studentId)
            ->where('kind', $kind)->where('ref_key', $refKey)->first();
        $shape = fn (object $row, bool $already) => ['kind' => $kind, 'ref_id' => $refId, 'ref_month' => $refMonth, 'acknowledged' => true, 'already' => $already,
            'acknowledged_at' => (string) $row->acknowledged_at];
        $note = mb_substr(trim((string) $note), 0, 500);
        $note = $note !== '' ? $note : null;

        if ($row = $find()) return $shape($row, true);
        $created = false;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                DB::transaction(function () use ($request, $guardianId, $studentId, $kind, $refId, $refMonth, $refKey, $note) {
                    DB::table('gp_acks')->insert(['school_id' => Access::school(), 'guardian_id' => $guardianId, 'student_id' => $studentId, 'kind' => $kind,
                        'ref_id' => $refId, 'ref_month' => $refMonth, 'ref_key' => $refKey, 'note' => $note, 'acknowledged_at' => now()->toDateTimeString(), 'ip_hash' => Access::ipHash($request)]);
                    if ($kind === 'behavior') self::stampBehavior((int) $refId, $guardianId, $note);
                    Access::event('guardian.acknowledged', $guardianId, $studentId, ['kind' => $kind, 'ref_id' => $refId, 'ref_month' => $refMonth], $request);
                });
                $created = true;
                break;
            } catch (VersionConflict $e) {
                if ($attempt === 1) throw $e; // the record changed under us twice: the guardian presses again
            } catch (\Illuminate\Database\QueryException $e) {
                if (Links::isUnique($e)) break; // a parallel request stored the same acknowledgement first
                throw $e;
            }
        }
        $row = $find();
        if (!$row) abort(409, 'บันทึกการรับทราบไม่สำเร็จ กรุณาลองใหม่');
        return $shape($row, !$created);
    }

    /**
     * The one narrow write into bhv_records (this module's own method, not BehaviorAcknowledgement::acknowledge, because that one
     * sends a Carbon object through Mutation::encode which json-encodes it into an invalid DATETIME value on strict MariaDB/MySQL).
     * Writes only guardian_ack_at / guardian_ack_by / guardian_ack_note, with a plain 'Y-m-d H:i:s' string, through Mutation::update
     * (version + audit). The read is a locking read inside the caller's transaction; when another guardian or a teacher already
     * acknowledged the record nothing is written (this guardian still keeps their own gp_acks row) and no error is raised.
     */
    private static function stampBehavior(int $recordId, int $guardianId, ?string $note): void
    {
        $rec = DB::table('bhv_records')->where('school_id', Access::school())->where('id', $recordId)->lockForUpdate()->first();
        if (!$rec || $rec->guardian_ack_at !== null) return;
        $changes = ['guardian_ack_at' => now()->toDateTimeString(), 'guardian_ack_by' => 'guardian:'.$guardianId];
        if ($note !== null && Schema::hasColumn('bhv_records', 'guardian_ack_note')) $changes['guardian_ack_note'] = mb_substr($note, 0, 1000);
        // Not Mutation::update: it audits as auth()->id(), which is the staff user when a teacher is signed in on the same browser
        // (or nothing at all). The guardian action is recorded in gp_events by Access::event() instead.
        $changes['updated_at'] = now()->toDateTimeString();
        $changes['version'] = DB::raw('version + 1');
        $rows = DB::table('bhv_records')->where('school_id', Access::school())->where('id', $recordId)->where('version', (int) $rec->version)->update($changes);
        if ($rows === 0) throw new VersionConflict((array) DB::table('bhv_records')->where('school_id', Access::school())->where('id', $recordId)->first());
    }

    private static function assertTarget(int $studentId, string $kind, ?int $refId, ?string $refMonth): void
    {
        $school = Access::school();
        if ($kind === 'attendance_month') {
            // a month with no meaning (before school history or in the future) is not acknowledgeable: today back to 12 months ago
            if (!GuardianData::moduleData('attendance', 'attendances')) abort(404);
            $oldest = now('Asia/Bangkok')->startOfMonth()->subMonthsNoOverflow(12)->format('Y-m');
            if ($refMonth === null || $refMonth < $oldest || $refMonth > now('Asia/Bangkok')->format('Y-m')) abort(422, 'เดือนนี้รับทราบไม่ได้');
        } elseif ($kind === 'behavior') {
            $ok = GuardianData::moduleData('behavior', 'bhv_records') && DB::table('bhv_records')->where('school_id', $school)->where('id', $refId)->where('student_id', $studentId)
                ->where('status', 'active')->where('guardian_visible', true)->exists();
            if (!$ok) abort(404);
        } elseif ($kind === 'term_result') {
            // same course-id source as GuardianData::results(): a score row OR a special result (ร/มผ/มส) in that course
            // F8: a term the school has not announced does not exist for the family (same 404 as an unknown term)
            $ok = Announce::isAnnounced($refId) && GuardianData::moduleData('assessment', 'asm_courses', 'asm_scores') && DB::table('asm_courses as c')->where('c.school_id', $school)->where('c.term_id', $refId)->whereIn('c.status', GuardianData::RESULT_STATUSES)
                ->where(function ($w) use ($school, $studentId) {
                    $w->whereIn('c.id', DB::table('asm_scores')->where('school_id', $school)->where('student_id', $studentId)->select('course_id'));
                    if (GuardianData::moduleData('reports-pp', 'rpt_special_results')) {
                        $w->orWhereIn('c.id', DB::table('rpt_special_results')->where('school_id', $school)->where('student_id', $studentId)->whereNotNull('code')->select('course_id'));
                    }
                })->exists();
            if (!$ok) abort(404);
        }
    }
}
