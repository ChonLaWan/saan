<?php

namespace App\Support\Saan;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** School-scoped key/value settings (table saan_settings). Values are JSON; every put bumps version. */
class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = DB::table('saan_settings')->where('school_id', self::school())->where('key', $key)->first();
        if (!$row || $row->value === null) return $default;
        $decoded = json_decode((string) $row->value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        if ($key === '' || mb_strlen($key) > 120) throw new \InvalidArgumentException('ชื่อการตั้งค่าไม่ถูกต้อง');
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $now = now();
        $update = fn () => DB::table('saan_settings')->where('school_id', self::school())->where('key', $key)
            ->update(['value' => $json, 'version' => DB::raw('version + 1'), 'updated_at' => $now]);
        if ($update() > 0) return;
        try {
            DB::table('saan_settings')->insert(['school_id' => self::school(), 'key' => $key, 'value' => $json, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        } catch (QueryException $e) {
            // a concurrent request inserted the same key first (unique school_id+key): update it instead; any other failure is real
            if ($update() === 0) throw $e;
        }
    }

    private static function school(): int
    {
        return (int) config('school.school_id');
    }
}