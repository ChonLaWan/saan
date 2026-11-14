<?php

namespace Saan\Modules\GuardianPortal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-portal notices for a guardian (for example "your payment slip was approved"). No e-mail: guardian accounts hold an unverified
 * address and the project has no verified-mail notification pattern, so the portal is the only channel.
 * Bodies carry reference numbers and the school's own reason text, never account numbers or file names.
 */
final class Notices
{
    public static function push(int $guardianId, ?int $studentId, string $kind, ?int $refId, string $title, ?string $body = null): void
    {
        if (!Schema::hasTable('gp_notices')) return;
        DB::table('gp_notices')->insert(['school_id' => Access::school(), 'guardian_id' => $guardianId, 'student_id' => $studentId, 'kind' => mb_substr($kind, 0, 20), 'ref_id' => $refId,
            'title' => mb_substr($title, 0, 160), 'body' => $body === null ? null : mb_substr($body, 0, 400), 'created_at' => now()]);
    }

    /** Latest notices of this guardian for one child (read ones too, newest first). */
    public static function forChild(int $guardianId, int $studentId): array
    {
        if (!Schema::hasTable('gp_notices')) return [];
        return DB::table('gp_notices')->where('school_id', Access::school())->where('guardian_id', $guardianId)->where('student_id', $studentId)
            ->orderByDesc('id')->limit(20)->get(['id', 'kind', 'ref_id', 'title', 'body', 'read_at', 'created_at'])
            ->map(fn ($n) => ['id' => (int) $n->id, 'kind' => $n->kind, 'ref_id' => $n->ref_id === null ? null : (int) $n->ref_id, 'title' => $n->title, 'body' => $n->body,
                'read' => $n->read_at !== null, 'created_at' => (string) $n->created_at])->all();
    }

    public static function markRead(int $guardianId, int $studentId): int
    {
        if (!Schema::hasTable('gp_notices')) return 0;
        return DB::table('gp_notices')->where('school_id', Access::school())->where('guardian_id', $guardianId)->where('student_id', $studentId)->whereNull('read_at')->update(['read_at' => now()]);
    }
}
