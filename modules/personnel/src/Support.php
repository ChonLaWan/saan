<?php

namespace Saan\Modules\Personnel;

use App\Support\Saan\Settings;
use App\Support\Saan\VersionConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Small shared helpers of the personnel module. */
class Support
{
    public const REVIEWERS = ['admin', 'principal'];

    public static function school(): int { return (int) config('school.school_id'); }

    public static function disk() { return Storage::disk('local'); } // storage/app/private, never served by the web server

    public static function isReviewer(?object $user = null): bool
    {
        $user ??= auth()->user();
        return $user && in_array($user->role, self::REVIEWERS, true);
    }

    /** Case/space-insensitive key for uniqueness that does not depend on database collation. */
    public static function key(string $v): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v)));
    }

    /** SQLSTATE 23000 (MySQL/MariaDB), 23505 (PostgreSQL) or driver code 19. */
    public static function isUnique(\Throwable $e): bool
    {
        if (!$e instanceof QueryException) return false;
        return \App\Support\Saan\DbError::isUnique($e);

    }

    /** Run a write; a unique-index violation becomes HTTP 409 instead of 500. */
    public static function unique(\Closure $fn, string $message): mixed
    {
        try { return $fn(); }
        catch (QueryException $e) { if (self::isUnique($e)) abort(409, $message); throw $e; }
    }

    /** Remove columns the browser must never see from a 409 body. */
    public static function guard(\Closure $fn, array $hide): mixed
    {
        try { return $fn(); }
        catch (VersionConflict $e) { throw new VersionConflict($e->current === null ? null : array_diff_key($e->current, array_flip($hide))); }
    }

    public static function teacherName(object $t): string
    {
        $first = trim((string) ($t->first_name ?? ''));
        if ($first !== '') return trim(trim((string) ($t->prefix ?? '')).$first.' '.trim((string) ($t->last_name ?? '')));
        return trim((string) ($t->name ?? ''));
    }

    public static function teacher(int $id): object
    {
        return DB::table('teachers')->where('school_id', self::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบบุคลากร');
    }

    public static function activeTeachers()
    {
        $q = DB::table('teachers')->where('teachers.school_id', self::school());
        if (Schema::hasColumn('teachers', 'is_active')) $q->where('teachers.is_active', true);
        return $q;
    }

    /** Total portfolio quota per teacher in MB (0 = unlimited), school setting personnel.teacher_quota_mb. */
    public static function teacherQuotaMb(): int
    {
        return max(0, (int) Settings::get('personnel.teacher_quota_mb', 2048));
    }

    public static function serverLimitBytes(): int
    {
        $to = function (string $v): int {
            $v = trim($v); if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
            $n = (int) $v; return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
        };
        return min($to((string) ini_get('upload_max_filesize')), $to((string) ini_get('post_max_size')));
    }

    public static function mb(int $bytes): string
    {
        return (string) round($bytes / 1048576, 1);
    }
}
