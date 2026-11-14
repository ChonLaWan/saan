<?php

namespace App\Support;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G9: per-school file quota. quota_mb = 0 means unlimited.
 *
 * The total counts cms_media plus every file table a module declares in module.json:
 *   "storage_tables": [{"table": "per_files", "bytes": "size", "where": {"status": ["active", "archived"]}}]
 * "where" values are equality (scalar) or whereIn (list). Tables that do not exist yet are skipped. Modules that are
 * switched off still count, because their files stay on disk (disable_policy keep-data).
 * A declared table MUST have a school_id column (tables without one are skipped, counted as 0).
 * Not counted: personnel staff photos (two small 480px JPEG copies per person, public + private derivative).
 * assertRoom() is a soft cap: it is not locked, so two simultaneous uploads can overshoot the quota by one file.
 */
class StorageQuota
{
    public static function quotaBytes(): int { return max(0, (int) config('saan.storage.quota_mb')) * 1048576; }

    private static function school(?int $school): int { return $school ?? (int) config('school.school_id'); }

    /** Declared storage tables of every module: [table => [bytes column, where]]. */
    public static function declared(): array
    {
        $out = [];
        foreach (app(ModuleRegistry::class)->all() as $module) foreach ((array) ($module['storage_tables'] ?? []) as $d) {
            if (!is_array($d) || !is_string($d['table'] ?? null) || !is_string($d['bytes'] ?? null)) continue;
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $d['table']) || !preg_match('/^[A-Za-z0-9_]+$/D', $d['bytes'])) continue;
            $where = [];
            foreach ((array) ($d['where'] ?? []) as $col => $val) if (is_string($col) && preg_match('/^[A-Za-z0-9_]+$/D', $col)) $where[$col] = $val;
            $out[$d['table'].'.'.$d['bytes'].'.'.md5(json_encode($where))] = ['table' => $d['table'], 'bytes' => $d['bytes'], 'where' => $where];
        }
        return array_values($out);
    }

    /** Bytes held by one declared table for the school (0 when the table or column is missing). */
    public static function tableBytes(array $d, int $school): int
    {
        if (!Schema::hasTable($d['table']) || !Schema::hasColumn($d['table'], $d['bytes'])) return 0;
        // a declared table must carry school_id: without it one school's files could eat into another's quota
        if (!Schema::hasColumn($d['table'], 'school_id')) return 0;
        $q = DB::table($d['table'])->where('school_id', $school);
        foreach ($d['where'] as $col => $val) {
            if (!Schema::hasColumn($d['table'], $col)) return 0;
            is_array($val) ? $q->whereIn($col, $val) : $q->where($col, $val);
        }
        return (int) $q->sum($d['bytes']);
    }

    public static function usedBytes(?int $school = null): int
    {
        $school = self::school($school);
        $n = (int) DB::table('cms_media')->where('school_id', $school)->sum('bytes');
        foreach (self::declared() as $d) $n += self::tableBytes($d, $school);
        return $n;
    }

    public static function summary(?int $school = null): array
    {
        $quota = self::quotaBytes(); $used = self::usedBytes($school);
        return ['used_bytes' => $used, 'quota_bytes' => $quota, 'percent' => $quota ? round($used / $quota * 100, 1) : null, 'max_upload_mb' => (int) config('saan.storage.max_upload_mb')];
    }

    /**
     * assertRoom($bytes) checks the current school; the older assertRoom($school, $bytes) form still works.
     * $bytes is only the NEW bytes: everything already stored is counted by usedBytes().
     */
    public static function assertRoom(int $bytes, ?int $moreBytes = null): void
    {
        [$school, $bytes] = $moreBytes === null ? [null, $bytes] : [$bytes, $moreBytes];
        $quota = self::quotaBytes();
        if ($quota && self::usedBytes($school) + max(0, $bytes) > $quota)
            abort(422, 'พื้นที่ไฟล์ของโรงเรียนเต็ม (ใช้ได้ '.round($quota / 1048576).' MB) ลบไฟล์ที่ไม่ใช้แล้ว หรือให้ผู้ดูแลเครื่องเพิ่ม SAAN_STORAGE_QUOTA_MB');
    }
}
