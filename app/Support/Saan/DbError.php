<?php

namespace App\Support\Saan;

use Illuminate\Database\QueryException;

/** Shared database error classification. SQLSTATE 23000 alone is NOT a duplicate (it also covers NOT NULL, FK and CHECK failures). */
final class DbError
{
    /** Duplicate key only: MySQL/MariaDB 1062/1586, PostgreSQL 23505, or driver codes 19/2067/1555 whose message says UNIQUE or PRIMARY KEY. */
    public static function isUnique(\Throwable $e): bool
    {
        if (!$e instanceof QueryException) return false;
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driver = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($driver, [1062, 1586], true) || $state === '23505') return true;
        $msg = $e->getMessage();
        return in_array($driver, [19, 2067, 1555], true) && (str_contains($msg, 'UNIQUE constraint failed') || str_contains($msg, 'PRIMARY KEY'));
    }
}
