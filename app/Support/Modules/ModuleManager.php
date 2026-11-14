<?php

namespace App\Support\Modules;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

/**
 * Admin-side module switch and data export. The choice is stored in saan_settings (modules.enabled / modules.disabled)
 * and merged by ModuleRegistry; .env (SAAN_DISABLE_MODULES) still wins. Switching off never deletes data (disable_policy).
 */
class ModuleManager
{
    /** Columns never written to an export: credentials, one-time secrets, PIN/ID hashes and ciphertext. A module can add more with export_exclude in module.json. */
    private const SECRET_COLUMN = '/(password|secret|token|totp|api_key|remember|(^|_)otp(_|$)|(^|_)pin(_|$)|(^|_)hash$|_enc$)/i';

    public function __construct(private ModuleRegistry $registry) {}

    /** Rows for the admin page. */
    public function listing(): array
    {
        $all = $this->registry->all();
        $out = [];
        foreach ($all as $m) {
            $deps = [];
            foreach ($m['requires']['modules'] ?? [] as $dep => $constraint) $deps[] = ['id' => $dep, 'constraint' => $constraint, 'name' => $all[$dep]['name'] ?? $dep, 'active' => (bool) ($all[$dep]['active'] ?? false)];
            $locked = ($m['disable_policy'] ?? '') === 'core-required';
            $out[] = [
                'id' => $m['id'], 'name' => $m['name'], 'version' => $m['version'], 'readiness' => $m['readiness'],
                'description' => (string) ($m['description'] ?? ''), 'families' => array_values($m['families'] ?? []),
                'active' => $m['active'], 'reason' => $m['reason'], 'disabled_by' => $m['disabled_by'] ?? null,
                'depends_on' => $deps, 'required_by' => $this->registry->activeDependents($m['id']),
                'core_required' => $locked, 'has_data' => $this->tables($m['id']) !== [],
                'can_disable' => $m['active'] && !$locked && $this->registry->activeDependents($m['id']) === [],
                'can_enable' => !$m['active'] && ($m['disabled_by'] ?? null) !== 'env',
                'changelog' => is_file($m['path'].'/CHANGELOG.md'),
            ];
        }
        return $out;
    }

    /** @throws ModuleActionException with a Thai message and 'code' = HTTP status */
    public function setActive(string $id, bool $enable, string $confirm = ''): array
    {
                $this->registry->get($id) ?? throw $this->fail('ไม่พบโมดูลนี้', 404);
        $school = (int) config('school.school_id');
        try {
            DB::transaction(function () use ($id, $enable, $confirm, $school) {
                // both rows must exist so they can be locked; a concurrent admin toggle waits here and then sees our result
                $now = now();
                foreach (['modules.enabled', 'modules.disabled'] as $k) {
                    DB::table('saan_settings')->insertOrIgnore(['school_id' => $school, 'key' => $k, 'value' => '[]', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                }
                $rows = DB::table('saan_settings')->where('school_id', $school)->whereIn('key', ['modules.enabled', 'modules.disabled'])->lockForUpdate()->get()->keyBy('key');
                // re-run every check against the state we just locked
                $this->registry->flush();
                $m = $this->registry->get($id) ?? throw $this->fail('ไม่พบโมดูลนี้', 404);
                $this->guard($m, $id, $enable, $confirm);
                $list = function (string $k) use ($rows): array {
                    $v = json_decode((string) ($rows[$k]->value ?? '[]'), true);
                    return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
                };
                $on = array_values(array_diff($list('modules.enabled'), [$id])); $off = array_values(array_diff($list('modules.disabled'), [$id]));
                if ($enable) $on[] = $id; else $off[] = $id;
                sort($on); sort($off);
                foreach (['modules.enabled' => $on, 'modules.disabled' => $off] as $k => $v) {
                    DB::table('saan_settings')->where('school_id', $school)->where('key', $k)->update(['value' => json_encode($v, JSON_UNESCAPED_UNICODE), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                }
                Mutation::audit($enable ? 'module.enabled' : 'module.disabled', 'modules', null, ['module' => $id, 'version' => $m['version'], 'readiness' => $m['readiness'], 'data_kept' => true]);
            });
        } catch (QueryException $e) {
            if (self::isUniqueViolation($e)) throw $this->fail('มีผู้ดูแลอีกคนกำลังเปลี่ยนโมดูลอยู่ โปรดลองอีกครั้ง', 409);
            throw $e;
        }
        $this->registry->flush();
        // module routes are registered at boot for active modules only: a cached route file would keep a newly enabled module at 404
        if (app()->routesAreCached()) {
            try { \Illuminate\Support\Facades\Artisan::call('route:clear'); } catch (\Throwable) { /* doctor reports an unwritable bootstrap/cache */ }
        }
        return $this->listing();
    }

    /** Unique violation by SQLSTATE 23000/23505 (or driver code 19). */
    public static function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        return in_array($code, ['23000', '23505', '19'], true) || (int) ($e->errorInfo[1] ?? 0) === 19 || ($e->errorInfo[0] ?? '') === '23000';
    }

    private function guard(array $m, string $id, bool $enable, string $confirm): void
    {
        if ($enable) {
            if (($m['disabled_by'] ?? null) === 'env') throw $this->fail('โมดูลนี้ถูกปิดไว้ที่ไฟล์ตั้งค่าเซิร์ฟเวอร์ (.env) เปิดจากหน้านี้ไม่ได้', 422);
            if ($m['readiness'] === 'experimental' && !hash_equals($id, trim($confirm))) throw $this->fail('โมดูลทดลอง ต้องพิมพ์รหัสโมดูล "'.$id.'" เพื่อยืนยัน', 422);
            if (!ModuleRegistry::satisfies((string) config('saan.version'), $m['requires']['core'])) throw $this->fail('โมดูลนี้ต้องการแกนรุ่น '.$m['requires']['core'], 422);
            foreach ($m['requires']['modules'] ?? [] as $dep => $constraint) {
                $d = $this->registry->get($dep);
                if (!$d) throw $this->fail('ไม่พบโมดูลที่ต้องใช้: '.$dep, 422);
                if (!$d['active']) throw $this->fail('ต้องเปิดโมดูล '.$dep.' ก่อน', 422);
                if (!ModuleRegistry::satisfies($d['version'], $constraint)) throw $this->fail('ต้องใช้ '.$dep.' '.$constraint, 422);
            }
        } else {
            if (($m['disable_policy'] ?? '') === 'core-required') throw $this->fail('โมดูลนี้เป็นส่วนที่ระบบต้องใช้ ปิดไม่ได้', 422);
            $by = $this->registry->activeDependents($id);
            if ($by) throw $this->fail('ปิดไม่ได้ เพราะโมดูลที่เปิดอยู่ต้องใช้โมดูลนี้: '.implode(', ', $by), 422);
        }
    }

    /** Tables created by the module's migrations that exist in the database. */
    public function tables(string $id): array
    {
        $dir = base_path('modules/'.$id.'/database/migrations');
        if (!preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $id) || !is_dir($dir)) return [];
        $names = [];
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            if (preg_match_all('/Schema::create\(\s*[\'"]([a-z][a-z0-9_]{0,63})[\'"]/', (string) file_get_contents($file), $mm)) array_push($names, ...$mm[1]);
        }
        $names = array_values(array_unique($names));
        return array_values(array_filter($names, fn ($t) => Schema::hasTable($t)));
    }

    /**
     * Build a ZIP with one JSONL file per module table (rows of this school only when the table has school_id),
     * plus manifest.json. Credential-like columns are left out. Returns the temp file path; caller deletes it.
     */
    public function export(string $id): string
    {
        $m = $this->registry->get($id) ?? throw $this->fail('ไม่พบโมดูลนี้', 404);
        $tables = $this->tables($id);
        $dir = storage_path('app/private/tmp'); if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $zipPath = $dir.'/module-export-'.bin2hex(random_bytes(8)).'.zip';
        $parts = [];
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) throw $this->fail('สร้างไฟล์ส่งออกไม่ได้', 500);
        $manifest = ['module' => $id, 'name' => $m['name'], 'version' => $m['version'], 'core_version' => config('saan.version'), 'exported_at' => now()->toIso8601String(), 'format' => 'jsonl', 'tables' => [], 'skipped_unscoped' => [],
            'note' => 'ไฟล์แนบส่วนตัวของโมดูลอยู่ใน storage/app/private/'.$id.' และสำรองด้วย school:archive ไม่รวมในไฟล์นี้; คอลัมน์ที่เป็นรหัสผ่านหรือโทเคนถูกตัดออก'];
        try {
            foreach ($tables as $table) {
                $cols = Schema::getColumnListing($table);
                $extra = is_array($m['export_exclude'][$table] ?? null) ? array_filter($m['export_exclude'][$table], 'is_string') : [];
                $keep = array_values(array_filter($cols, fn ($c) => !preg_match(self::SECRET_COLUMN, $c) && !in_array($c, $extra, true)));
                $omitted = array_values(array_diff($cols, $keep));
                $scoped = in_array('school_id', $cols, true);
                $q = DB::table($table)->select($keep);
                // rows of other schools never leave: direct school_id, or through a foreign key to a scoped parent table of this module
                if (!$this->scope($q, $table, $tables)) {
                    $manifest['skipped_unscoped'][] = $table;
                    continue;
                }
                $tmp = $dir.'/module-export-'.bin2hex(random_bytes(6)).'.jsonl'; $parts[] = $tmp;
                $fh = fopen($tmp, 'wb'); $count = 0;
                $order = in_array('id', $cols, true) ? 'id' : null;
                if ($order) $q->orderBy('id');
                $writer = function ($rows) use ($fh, &$count) { foreach ($rows as $row) { fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n"); $count++; } };
                if ($order) $q->chunkById(500, $writer, 'id'); else $writer($q->get());
                fclose($fh);
                $zip->addFile($tmp, $table.'.jsonl');
                $manifest['tables'][] = ['name' => $table, 'rows' => $count, 'school_scoped' => $scoped, 'omitted_columns' => $omitted];
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if (!$zip->close()) throw $this->fail('เขียนไฟล์ส่งออกไม่สำเร็จ', 500);
        } catch (\Throwable $e) {
            @$zip->close(); @unlink($zipPath); foreach ($parts as $p) @unlink($p);
            throw $e;
        }
        foreach ($parts as $p) @unlink($p);
        Mutation::audit('module.exported', 'modules', null, ['module' => $id, 'tables' => array_column($manifest['tables'], 'rows', 'name')]);
        return $zipPath;
    }

    /** Restrict $q to this school. False when the table has no school_id and no foreign-key path to a scoped table of the module. */
    private function scope($q, string $table, array $moduleTables, int $depth = 0): bool
    {
        if (in_array('school_id', Schema::getColumnListing($table), true)) {
            $q->where('school_id', (int) config('school.school_id'));
            return true;
        }
        if ($depth >= 4) return false;
        foreach (Schema::getForeignKeys($table) as $fk) {
            $parent = $fk['foreign_table'] ?? null;
            if (!$parent || $parent === $table || !in_array($parent, $moduleTables, true) || count($fk['columns']) !== 1 || count($fk['foreign_columns']) !== 1) continue;
            $sub = DB::table($parent)->select($fk['foreign_columns'][0]);
            if ($this->scope($sub, $parent, $moduleTables, $depth + 1)) {
                $q->whereIn($fk['columns'][0], $sub);
                return true;
            }
        }
        return false;
    }

    private function fail(string $message, int $code): ModuleActionException
    {
        return new ModuleActionException($message, $code);
    }
}
