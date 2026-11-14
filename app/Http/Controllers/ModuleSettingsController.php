<?php

namespace App\Http\Controllers;

use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\VersionConflict;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Admin page for module settings declared in module.json "settings_schema" (otherwise only settable in code).
 * Optimistic concurrency uses saan_settings.version of the key (0 = row not stored yet). Admin only (route middleware).
 */
class ModuleSettingsController extends Controller
{
    public function __construct(private ModuleRegistry $modules) {}

    public function index(): JsonResponse
    {
        $groups = [];
        foreach ($this->modules->settingsSchema() as $def) {
            $g = &$groups[$def['module']];
            $g ??= ['module' => $def['module'], 'name' => $def['module_name'], 'items' => []];
            $g['items'][] = $this->item($def);
            unset($g);
        }
        $users = DB::table('users')->where('school_id', self::school())->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name, 'role' => (string) $u->role])->all();
        return response()->json(['groups' => array_values($groups), 'users' => $users]);
    }

    /** Keys whose owning module guards its own form with ANOTHER key's saan_settings.version: moved together with the key. */
    private const DEPENDENT_TOKENS = [
        'reports.show_rank' => 'reports.settings_version',
        'reports.gpa_rounding' => 'reports.settings_version',
        'reports.signers' => 'reports.settings_version',
        'maintenance.photo_retention_months' => 'maintenance.technician_user_ids',
    ];

    public function update(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $d = $request->validate(['key' => 'required|string|max:120', 'version' => 'required|integer|min:0', 'value' => 'present']);
        $def = $this->modules->settingDef($d['key']) ?? abort(404, 'ไม่พบการตั้งค่านี้ (โมดูลอาจถูกปิดอยู่)');
        $value = $this->normalise($def, $d['value']);
        $expected = (int) $d['version'];

        return Idempotency::run($request, function () use ($def, $value, $expected) {
            try {
                DB::transaction(function () use ($def, $value, $expected) {
                    $this->write($def, $value, $expected);
                });
            } catch (QueryException $e) {
                // concurrent first write: unique violation, or a gap-lock deadlock/timeout on MariaDB/MySQL -> the loser gets 409, never 500
                if (self::isUnique($e) || ($e->errorInfo[0] ?? '') === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) throw new VersionConflict($this->item($def));
                throw $e;
            }
            return response()->json($this->item($def));
        });
    }

    private function write(array $def, mixed $value, int $expected): void
    {
        $school = self::school();
        $key = $def['key'];
        if (in_array($key, ['finance.invoice_prefix', 'finance.receipt_prefix'], true)) {
            // lock both prefix rows so two admins cannot make them equal at the same time
            DB::table('saan_settings')->where('school_id', $school)->whereIn('key', ['finance.invoice_prefix', 'finance.receipt_prefix'])->lockForUpdate()->get();
        }
        $row = DB::table('saan_settings')->where('school_id', $school)->where('key', $key)->lockForUpdate()->first();
        $current = $row ? (int) $row->version : 0;
        if ($current !== $expected) throw new VersionConflict($this->item($def));
        if ($key === 'finance.invoice_prefix' || $key === 'finance.receipt_prefix') {
            $other = $key === 'finance.invoice_prefix' ? 'finance.receipt_prefix' : 'finance.invoice_prefix';
            // compare what finance will actually print: an empty or invalid value falls back to INV / RC (Finance Support::prefix)
            $effective = fn (string $k, mixed $v): string => is_string($v) && preg_match('/^[A-Za-z0-9]{1,8}$/D', $v) ? $v : ($k === 'finance.invoice_prefix' ? 'INV' : 'RC');
            if (strcasecmp($effective($key, $value), $effective($other, Settings::get($other))) === 0) self::invalid('value', 'คำนำหน้าใบแจ้งหนี้และใบเสร็จต้องไม่ซ้ำกัน');
        }
        if ($key === 'constructionism.rubric') $this->rubricGuard($value);
        $before = $this->effective($def);
        if (!$row) {
            DB::table('saan_settings')->insert(['school_id' => $school, 'key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            Settings::put($key, $value);
        }
        $dep = self::DEPENDENT_TOKENS[$key] ?? null;
        if ($dep === 'reports.settings_version') {
            Settings::put($dep, (int) Settings::get($dep, 0) + 1);
        } elseif ($dep !== null) {
            // bump the row version without changing its value (an absent row is created with its current value, so version 0 becomes 1)
            Settings::put($dep, Settings::get($dep, []));
        }
        Mutation::audit('settings.module.updated', 'saan_settings', null, ['key' => $key, 'module' => $def['module'], 'before' => $before, 'after' => $value]);
    }

    // ---------- reading ----------

    private function item(array $def): array
    {
        $row = DB::table('saan_settings')->where('school_id', self::school())->where('key', $def['key'])->first();
        $stored = $row && $row->value !== null;
        $value = $this->effective($def);
        return $def + ['value' => $value, 'is_default' => !$stored, 'version' => $row ? (int) $row->version : 0];
    }

    private function effective(array $def): mixed
    {
        if ($def['key'] === 'constructionism.rubric' && class_exists(\Saan\Modules\Constructionism\Rubric::class)) return ['criteria' => \Saan\Modules\Constructionism\Rubric::criteria()];
        $v = Settings::get($def['key'], $def['default'] ?? null);
        return match ($def['type']) {
            'bool' => $v === true,
            'int' => is_numeric($v) ? (int) $v : ($def['default'] ?? 0),
            'users' => is_array($v) ? array_values(array_unique(array_map('intval', $v))) : [],
            'text' => is_string($v) ? $v : (string) ($def['default'] ?? ''),
            default => $v,
        };
    }

    // ---------- validation per type ----------

    private function normalise(array $def, mixed $v): mixed
    {
        $f = 'value';
        switch ($def['type']) {
            case 'int':
                if (is_string($v) && preg_match('/^-?\d{1,12}$/D', trim($v))) $v = (int) trim($v);
                if (!is_int($v) || $v < $def['min'] || $v > $def['max']) self::invalid($f, 'ต้องเป็นจำนวนเต็มระหว่าง '.$def['min'].' ถึง '.$def['max']);
                return $v;
            case 'bool':
                if (!is_bool($v)) self::invalid($f, 'ต้องเป็นค่าเปิดหรือปิด');
                return $v;
            case 'users':
                if (!is_array($v) || !array_is_list($v) || count($v) > 200) self::invalid($f, 'รายการบัญชีไม่ถูกต้อง');
                foreach ($v as $id) if (!is_int($id) && !(is_string($id) && preg_match('/^\d{1,10}$/D', $id))) self::invalid($f, 'รายการบัญชีไม่ถูกต้อง');
                $ids = array_values(array_unique(array_map('intval', $v)));
                sort($ids);
                $ok = $ids ? DB::table('users')->where('school_id', self::school())->where('is_active', true)->when(!empty($def['roles']), fn ($q) => $q->whereIn('role', $def['roles']))->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
                if (count($ok) !== count($ids)) self::invalid($f, 'มีบัญชีที่ไม่อยู่ในโรงเรียนนี้ ถูกปิดใช้งาน หรือมีบทบาทที่ไม่รองรับ');
                return $ids;
            case 'text':
                if ($v === null) $v = ''; // ConvertEmptyStringsToNull: an emptied field arrives as null
                if (!is_string($v)) self::invalid($f, 'ต้องเป็นข้อความ');
                $v = trim((string) preg_replace('/\s+/u', ' ', $v));
                if (preg_match('/[\x00-\x1F\x7F]/', $v) || mb_strlen($v) > $def['maxlength']) self::invalid($f, 'ข้อความยาวเกิน '.$def['maxlength'].' ตัวอักษรหรือมีอักขระต้องห้าม');
                if ($def['options'] !== null && !array_key_exists($v, $def['options'])) self::invalid($f, 'ตัวเลือกไม่ถูกต้อง');
                if ($v !== '' && $def['pattern'] !== null && !preg_match('/'.str_replace('/', '\/', $def['pattern']).'/Du', $v)) self::invalid($f, 'รูปแบบไม่ถูกต้อง: '.($def['help'] ?: $def['pattern']));
                if ($def['key'] === 'activities.cert_prefix' && $v !== '' && class_exists(\Saan\Modules\Activities\Certificates::class) && strtoupper($v) === \Saan\Modules\Activities\Certificates::surveysCode()) {
                    self::invalid($f, 'อักษรนำนี้ใช้กับเกียรติบัตรแบบสำรวจแล้ว กรุณาใช้อักษรอื่น');
                }
                return $v;
            default:
                return $this->normaliseJson($def, $v);
        }
    }

    private function normaliseJson(array $def, mixed $v): mixed
    {
        $f = 'value';
        if (!is_array($v)) self::invalid($f, 'ต้องเป็นรายการหรือออบเจ็กต์ JSON');
        if (strlen(json_encode($v, JSON_UNESCAPED_UNICODE)) > 20000) self::invalid($f, 'ข้อมูลใหญ่เกิน 20,000 ไบต์');
        if ($def['key'] === 'reports.signers') {
            if (!array_is_list($v) || count($v) > 6) self::invalid($f, 'ผู้ลงนามมีได้ไม่เกิน 6 ราย');
            $out = [];
            foreach ($v as $i => $s) {
                if (!is_array($s) || !is_string($s['label'] ?? null) || trim($s['label']) === '' || mb_strlen(trim($s['label'])) > 80) self::invalid($f, 'ผู้ลงนามรายที่ '.($i + 1).' ต้องมีตำแหน่ง (label) ไม่เกิน 80 ตัวอักษร');
                $name = $s['name'] ?? '';
                if (!is_string($name) || mb_strlen(trim($name)) > 120) self::invalid($f, 'ชื่อผู้ลงนามรายที่ '.($i + 1).' ไม่ถูกต้อง (ไม่เกิน 120 ตัวอักษร)');
                $out[] = ['label' => trim($s['label']), 'name' => trim($name)];
            }
            return $out;
        }
        if ($def['key'] === 'constructionism.rubric') return $this->rubric($v);
        return $v;
    }

    private function rubric(array $v): array
    {
        $list = $v['criteria'] ?? null;
        if (!is_array($list) || !array_is_list($list) || count($list) < 1 || count($list) > 10) self::invalid('value', 'ต้องมีเกณฑ์ 1 ถึง 10 ข้อ ในรูป {"criteria":[...]}');
        $out = []; $keys = []; $labels = [];
        foreach ($list as $i => $c) {
            $key = is_array($c) && is_string($c['key'] ?? null) ? $c['key'] : '';
            if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/D', $key)) self::invalid('value', 'เกณฑ์ข้อ '.($i + 1).': รหัส (key) ต้องเป็นอังกฤษพิมพ์เล็ก ตัวเลข หรือ _ ไม่เกิน 30 ตัว');
            if (!is_string($c['label'] ?? null)) self::invalid('value', 'เกณฑ์ข้อ '.($i + 1).': ต้องมีชื่อไม่เกิน 80 ตัวอักษร');
            $label = trim((string) preg_replace('/\s+/u', ' ', $c['label']));
            if ($label === '' || mb_strlen($label) > 80) self::invalid('value', 'เกณฑ์ข้อ '.($i + 1).': ต้องมีชื่อไม่เกิน 80 ตัวอักษร');
            $lk = mb_strtolower($label);
            if (isset($keys[$key]) || isset($labels[$lk])) self::invalid('value', 'รหัสหรือชื่อเกณฑ์ซ้ำกัน');
            $keys[$key] = $labels[$lk] = true;
            $levels = $c['levels'] ?? [];
            if (!is_array($levels) || !array_is_list($levels) || count($levels) > 4) self::invalid('value', 'เกณฑ์ข้อ '.($i + 1).': คำอธิบายระดับมีได้ไม่เกิน 4 ระดับ');
            foreach ($levels as $j => $l) {
                if (!is_string($l) || mb_strlen($l) > 200) self::invalid('value', 'เกณฑ์ข้อ '.($i + 1).': คำอธิบายระดับต้องเป็นข้อความไม่เกิน 200 ตัวอักษร');
                $levels[$j] = trim($l);
            }
            $out[] = ['key' => $key, 'label' => $label, 'levels' => $levels];
        }
        return ['criteria' => $out];
    }

    /** Runs inside the write transaction (after the version check): a criterion that already has scores cannot be dropped. */
    private function rubricGuard(array $value): void
    {
        if (!Schema::hasTable('con_reflections')) return;
        $keys = [];
        foreach ($value['criteria'] as $c) $keys[$c['key']] = true;
        $used = [];
        foreach (DB::table('con_reflections')->where('school_id', self::school())->whereNotNull('rubric')->lockForUpdate()->pluck('rubric') as $json) {
            foreach (array_keys((array) json_decode((string) $json, true)) as $k) $used[$k] = true;
        }
        $rubricClass = \Saan\Modules\Constructionism\Rubric::class;
        $saved = class_exists($rubricClass) ? $rubricClass::criteria() : (Settings::get('constructionism.rubric')['criteria'] ?? []);
        foreach (is_array($saved) ? $saved : [] as $old) {
            $ok = is_array($old) ? (string) ($old['key'] ?? '') : '';
            if (isset($used[$ok]) && !isset($keys[$ok])) self::invalid('value', 'เกณฑ์ "'.($old['label'] ?? $ok).'" มีการให้คะแนนแล้ว ลบไม่ได้ (เปลี่ยนชื่อได้)');
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private static function school(): int
    {
        return (int) config('school.school_id');
    }

    private static function isUnique(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driver = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
        return $driver === 1062 || $state === '23505' || $state === '23000' || $driver === 19;
    }
}
