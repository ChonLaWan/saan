<?php

namespace App\Support\Saan\Inbox;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Small counters and timestamps kept in pub_sync_state under names that are no stream: inbox.pull (last successful pull), inbox.sigfail,
 * push.sigfail (rejected signatures), inbox.purged (envelopes dropped unpulled), inbox.cursor. last_seq = counter, applied_at = last time.
 */
final class State
{
    public static function get(?string $conn, string $key): ?object
    {
        return DB::connection($conn)->table('pub_sync_state')->where('stream', $key)->first();
    }

    public static function mark(?string $conn, string $key, int $now, int $add = 0, ?int $set = null): void
    {
        $t = fn () => DB::connection($conn)->table('pub_sync_state')->where('stream', $key);
        $upd = ['applied_at' => date('Y-m-d H:i:s', $now)];
        if ($set !== null) $upd['last_seq'] = $set;
        elseif ($add) $upd['last_seq'] = DB::raw('last_seq + '.(int) $add);
        if ($t()->update($upd) > 0) return;
        try {
            DB::connection($conn)->table('pub_sync_state')->insert(['stream' => $key, 'last_seq' => $set ?? max(0, $add), 'applied_at' => $upd['applied_at']]);
        } catch (UniqueConstraintViolationException) {
            $t()->update($upd);
        }
    }

    public static function forget(?string $conn, string $key): void
    {
        DB::connection($conn)->table('pub_sync_state')->where('stream', $key)->delete();
    }

    public static function count(?string $conn, string $key): int
    {
        return (int) (self::get($conn, $key)->last_seq ?? 0);
    }
}
