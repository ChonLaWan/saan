<?php

namespace Saan\Modules\Behavior;

use App\Support\Saan\Mutation;
use Illuminate\Support\Facades\DB;

/**
 * Guardian acknowledgement of one behaviour record. bhv_records belongs to this module, so the guardian-portal module
 * calls this class instead of writing the table.
 *
 * The CALLER must already have verified that the signed-in guardian is linked to the record's student; this class only
 * checks the school, that the record is active and guardian-visible, and that it was not acknowledged before.
 * Writes are versioned and audited (behavior.record.acknowledged); a stale $expectedVersion raises VersionConflict (HTTP 409).
 * $guardianRef is an opaque label for audit (for example "guardian:12"), never a password or token.
 */
class BehaviorAcknowledgement
{
    /** @return array{id:int,student_id:int,guardian_ack_at:?string,guardian_ack_by:?string,guardian_ack_note:?string,version:int} */
    public function acknowledge(int $recordId, int $expectedVersion, string $guardianRef, ?string $note): array
    {
        $rec = DB::table('bhv_records')->where('school_id', (int) config('school.school_id'))->where('id', $recordId)
            ->where('status', 'active')->where('guardian_visible', true)->first();
        if (!$rec) abort(404);
        if ($rec->guardian_ack_at !== null) abort(422, 'รายการนี้ได้รับทราบแล้ว');
        $note = mb_substr(trim((string) $note), 0, 1000);
        $row = Mutation::update('bhv_records', $recordId, $expectedVersion, [
            'guardian_ack_at' => now()->toDateTimeString(), 'guardian_ack_by' => mb_substr(trim($guardianRef), 0, 120),
            'guardian_ack_note' => $note !== '' ? $note : null,
        ], 'behavior.record.acknowledged', ['student_id' => (int) $rec->student_id]);
        return ['id' => (int) $row->id, 'student_id' => (int) $row->student_id, 'guardian_ack_at' => $row->guardian_ack_at ? (string) $row->guardian_ack_at : null,
            'guardian_ack_by' => $row->guardian_ack_by, 'guardian_ack_note' => $row->guardian_ack_note, 'version' => (int) $row->version];
    }
}
