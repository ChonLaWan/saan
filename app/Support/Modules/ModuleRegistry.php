<?php

namespace App\Support\Modules;

use RuntimeException;

/**
 * Reads modules/<id>/module.json and decides which modules are active.
 *
 * Rules:
 * - experimental: active only in demo mode, or when listed in SAAN_ENABLE_MODULES
 * - beta / stable: active unless listed in SAAN_DISABLE_MODULES (core-required modules cannot be disabled)
 * - a module whose dependency is inactive, or whose core/module version requirement fails, is inactive
 */
class ModuleRegistry
{
    public const READINESS = ['experimental', 'beta', 'stable'];
    /** module.json "edition": internal = staff/back-office only, public = public edition only, both = loads in both. Entries of "public_projection" are checked deeply by Saan\Projection. */
    public const EDITIONS = ['internal', 'public', 'both'];
    private ?array $modules = null;
    private array $adminState = ['enabled' => [], 'disabled' => [], 'public_active' => null];

    /** @param string|null $root folder that holds the module folders (default base_path('modules')); tests pass a temp folder */
    public function __construct(private ?string $root = null)
    {
    }

    public function all(): array
    {
        if ($this->modules !== null) return $this->modules;
        $this->loadAdminState();
        $list = [];
        foreach (glob(($this->root ?? base_path('modules')).'/*/module.json') ?: [] as $file) {
            $m = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            $this->validate($m, $file);
            // S1: which edition may load this module. Default: a module that ships routes/public.php is 'both', any other is 'internal'.
            $m['edition'] ??= is_file(dirname($file).'/routes/public.php') ? 'both' : 'internal';
            if ($m['edition'] === 'internal' && is_file(dirname($file).'/routes/public.php')) throw new RuntimeException('module.json ไม่ถูกต้อง: edition internal แต่มี routes/public.php: '.$file);
            if ($m['edition'] === 'public' && is_file(dirname($file).'/routes/web.php')) throw new RuntimeException('module.json ไม่ถูกต้อง: edition public แต่มี routes/web.php (ไม่มีรุ่นไหนโหลดไฟล์นี้): '.$file);
            if (isset($list[$m['id']])) throw new RuntimeException('module id ซ้ำ: '.$m['id']);
            $list[$m['id']] = $m + ['path' => dirname($file)];
        }
        ksort($list);
        $resolved = [];
        foreach (array_keys($list) as $id) $this->resolve($id, $list, $resolved, []);
        ksort($resolved);
        return $this->modules = $resolved;
    }

    /**
     * Tables a module declares under $key` in module.json ("student_tables", "term_tables"), for every module whether active or not
     * (its rows stay when it is switched off). Each entry: {table, column, label?}. Invalid names are ignored.
     * @return list<array{table:string,column:string,label:string}>
     */
    public function declaredTables(string $key): array
    {
        $out = [];
        foreach ($this->all() as $m) {
            foreach ((array) ($m[$key] ?? []) as $e) {
                if (!is_array($e) || !is_string($e['table'] ?? null) || !is_string($e['column'] ?? null)) continue;
                if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $e['table']) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $e['column'])) continue;
                $out[] = ['table' => $e['table'], 'column' => $e['column'], 'label' => is_string($e['label'] ?? null) ? $e['label'] : $e['table']];
            }
        }
        return $out;
    }

    public function get(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    public function active(string $id): bool
    {
        return (bool) ($this->get($id)['active'] ?? false);
    }

    public function summary(): array
    {
        return array_values(array_map(fn ($m) => [
            'id' => $m['id'], 'name' => $m['name'], 'version' => $m['version'], 'readiness' => $m['readiness'],
            'active' => $m['active'], 'reason' => $m['reason'],
        ], $this->all()));
    }

    /** CSS/JS files of active modules for the CMS shell (only paths under public/assets/modules). */
    public function adminAssets(): array
    {
        $out=[];
        foreach($this->all() as $m) if($m['active']) foreach($m['admin_assets']??[] as $asset) if(is_string($asset)&&preg_match('~^assets/modules/[a-z0-9-]+/[A-Za-z0-9._/-]+\.(js|css)$~D',$asset)&&is_file(public_path($asset)))$out[]=$asset;
        return $out;
    }

    /** CMS menu entries of active modules allowed for this role. */
    public function adminMenu(string $role): array
    {
        $out=[];
        foreach($this->all() as $m) if($m['active']) foreach($m['admin_menu']??[] as $item) if(in_array($role,$item['roles']??[],true)&&preg_match('~^/cms#[a-z0-9/-]+$~D',$item['path']??''))$out[]=$item+['icon'=>'web'];
        return $out;
    }

    /** Work-shell (/work) menu entries of active modules allowed for this role. Path must be /work#<key>. */
    public function workMenu(string $role): array
    {
        $icons = ['grid','calendar','shield','people','check','layers','web','edit','document','palette','chart','person'];
        $out = [];
        foreach ($this->all() as $m) if ($m['active']) foreach ($m['work_menu'] ?? [] as $item) {
            if (!is_array($item) || !is_string($item['path'] ?? null) || !preg_match('~^/work#[a-z0-9/-]+$~D', $item['path'])) continue;
            if (!is_string($item['label'] ?? null) || $item['label'] === '' || !in_array($role, $item['roles'] ?? [], true)) continue;
            $key = explode('/', substr($item['path'], 6))[0];
            if (isset($item['key']) && $item['key'] !== $key) continue;
            $out[] = ['key' => $key, 'label' => $item['label'], 'path' => $item['path'], 'icon' => in_array($item['icon'] ?? '', $icons, true) ? $item['icon'] : 'web', 'roles' => $item['roles'], 'module' => $m['id']];
        }
        return $out;
    }

    /** School-wide keys read by several modules: always listed, independent of which modules are active. */
    public const CORE_SETTINGS = [
        ['key' => 'school.name', 'label' => 'ชื่อโรงเรียน (หัวกระดาษเอกสาร)', 'type' => 'text', 'maxlength' => 150, 'default' => ''],
        ['key' => 'school.short_code', 'label' => 'รหัสย่อโรงเรียน (ใช้นำหน้าเลขที่เอกสาร)', 'type' => 'text', 'maxlength' => 12, 'pattern' => '^[A-Za-z0-9]{1,12}$', 'default' => '', 'help' => 'ตัวอักษรอังกฤษหรือตัวเลขไม่เกิน 12 ตัว'],
    ];

    public const SETTING_TYPES = ['int', 'bool', 'users', 'text', 'json'];

    /**
     * Settings declared by active modules ("settings_schema" in module.json), sanitised, deduplicated by key.
     * @return array<int,array<string,mixed>> each: key,label,type,min,max,maxlength,pattern,options,roles_hint,help,default,module,module_name
     */
    public function settingsSchema(): array
    {
        $out = [];
        $core = ['id' => 'core', 'name' => 'ทั่วไป (โรงเรียน)', 'active' => true, 'settings_schema' => self::CORE_SETTINGS];
        foreach (array_merge([$core], array_values($this->all())) as $m) if ($m['active']) foreach ($m['settings_schema'] ?? [] as $d) {
            if (!is_array($d) || !is_string($d['key'] ?? null) || !preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/D', $d['key']) || isset($out[$d['key']])) continue;
            if (!is_string($d['label'] ?? null) || $d['label'] === '' || !in_array($d['type'] ?? null, self::SETTING_TYPES, true)) continue;
            $e = ['key' => $d['key'], 'label' => $d['label'], 'type' => $d['type'], 'module' => $m['id'], 'module_name' => $m['name'],
                'help' => is_string($d['help'] ?? null) ? $d['help'] : '', 'roles_hint' => array_values(array_filter((array) ($d['roles_hint'] ?? []), 'is_string')),
                'default' => $d['default'] ?? null,
                'roles' => array_values(array_filter((array) ($d['roles'] ?? []), 'is_string'))];
            if ($d['type'] === 'int') { $e['min'] = (int) ($d['min'] ?? 0); $e['max'] = (int) ($d['max'] ?? 1000000); }
            if ($d['type'] === 'text') {
                $e['maxlength'] = max(1, min(500, (int) ($d['maxlength'] ?? 200)));
                $e['pattern'] = is_string($d['pattern'] ?? null) && @preg_match('/'.str_replace('/', '\/', $d['pattern']).'/Du', '') !== false ? $d['pattern'] : null;
                $e['options'] = is_array($d['options'] ?? null) ? array_map('strval', $d['options']) : null;
            }
            $out[$d['key']] = $e;
        }
        return array_values($out);
    }

    public function settingDef(string $key): ?array
    {
        foreach ($this->settingsSchema() as $e) if ($e['key'] === $key) return $e;
        return null;
    }

    /** CSS/JS files of active modules for the /work shell (only paths under public/assets/modules). */
    public function workAssets(): array
    {
        $out = [];
        foreach ($this->all() as $m) if ($m['active']) foreach ($m['work_assets'] ?? [] as $asset) if (is_string($asset) && preg_match('~^assets/modules/[a-z0-9-]+/[A-Za-z0-9._/-]+\.(js|css)$~D', $asset) && is_file(public_path($asset))) $out[] = $asset;
        return $out;
    }

    public function flush(): void
    {
        $this->modules = null;
    }

    /** Active modules that list $id in requires.modules (they stop working if $id is switched off). */
    public function activeDependents(string $id): array
    {
        $out = [];
        foreach ($this->all() as $m) if ($m['active'] && array_key_exists($id, $m['requires']['modules'] ?? [])) $out[] = $m['id'];
        return $out;
    }

    /**
     * One query per request. Only a missing table (installer, fresh database) means "no admin choice". Any other database
     * error is rethrown: treating it as "no choice" would switch back on modules the admin turned off (fail open).
     */
    private function loadAdminState(): void
    {
        $this->adminState = ['enabled' => [], 'disabled' => [], 'public_active' => \App\Support\Saan\Edition::isPublic() ? self::publishedModules() : null];
        try {
            $exists = \Illuminate\Support\Facades\Schema::hasTable('saan_settings');
        } catch (\Throwable) {
            $exists = false; // no database connection at all (installer): nothing was stored yet
        }
        if (!$exists) return;
        $rows = \Illuminate\Support\Facades\DB::table('saan_settings')->where('school_id', (int) config('school.school_id'))->whereIn('key', ['modules.enabled', 'modules.disabled'])->pluck('value', 'key');
        foreach (['enabled', 'disabled'] as $k) {
            $v = json_decode((string) ($rows['modules.'.$k] ?? '[]'), true);
            $this->adminState[$k] = is_array($v) ? array_values(array_filter($v, fn ($x) => is_string($x) && preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $x))) : [];
        }
    }

    /**
     * Public edition: the module ids named in pub_active_modules (written by the S4 receiver from the list the internal publisher sends).
     * Deny by default: a missing table or an empty list means no module is active there, so no module route is registered.
     * @return list<string>
     */
    public static function publishedModules(): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pub_active_modules')) return [];
            $ids = \Illuminate\Support\Facades\DB::table('pub_active_modules')->pluck('module_id')->all();
        } catch (\Throwable) {
            return [];
        }
        return array_values(array_filter($ids, fn ($x) => is_string($x) && preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $x)));
    }

private function validate(mixed $m, string $file): void
    {
        $ok = is_array($m)
            && is_string($m['id'] ?? null) && preg_match('/^[a-z][a-z0-9-]{1,39}$/', $m['id'])
            && basename(dirname($file)) === $m['id']
            && is_string($m['name'] ?? null) && $m['name'] !== ''
            && is_string($m['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $m['version'])
            && in_array($m['readiness'] ?? null, self::READINESS, true)
            && is_array($m['requires'] ?? null) && is_string($m['requires']['core'] ?? null)
            && is_array($m['requires']['modules'] ?? [])
            && (!array_key_exists('edition', $m) || in_array($m['edition'], self::EDITIONS, true))
            && (!array_key_exists('public_projection', $m) || (is_array($m['public_projection']) && array_is_list($m['public_projection'])
                && !array_filter($m['public_projection'], fn ($e) => !is_array($e))));
        if (!$ok) throw new RuntimeException('module.json ไม่ถูกต้อง: '.$file);
    }

    private function resolve(string $id, array $list, array &$resolved, array $stack): void
    {
        if (isset($resolved[$id])) return;
        if (in_array($id, $stack, true)) throw new RuntimeException('module พึ่งพากันเป็นวง: '.implode(' > ', [...$stack, $id]));
        $m = $list[$id];
        $envEnabled = array_filter(array_map('trim', explode(',', (string) config('saan.modules.enable', ''))));
        $envDisabled = array_filter(array_map('trim', explode(',', (string) config('saan.modules.disable', ''))));
        // config/env wins for an explicit disable; the admin choice is merged on top of the env enable list
        $enabled = array_values(array_unique([...$envEnabled, ...$this->adminState['enabled']]));
        $disabled = array_values(array_unique([...$envDisabled, ...$this->adminState['disabled']]));
        $source = in_array($id, $envDisabled, true) ? 'env' : (in_array($id, $this->adminState['disabled'], true) ? 'admin' : null);
        $coreRequired = ($m['disable_policy'] ?? '') === 'core-required';
        [$active, $reason] = [true, 'เปิดใช้งาน'];
        if (!self::satisfies(config('saan.version'), $m['requires']['core'])) {
            [$active, $reason] = [false, 'ต้องการแกนรุ่น '.$m['requires']['core']];
        } elseif ($m['readiness'] === 'experimental' && !config('school.demo') && !in_array($id, $enabled, true)) {
            [$active, $reason] = [false, 'โมดูลทดลอง ปิดเป็นค่าเริ่มต้นในระบบใช้งานจริง'];
        } elseif (!$coreRequired && in_array($id, $disabled, true)) {
            [$active, $reason] = [false, $source === 'env' ? 'ปิดไว้ในไฟล์ตั้งค่าเซิร์ฟเวอร์ (.env)' : 'ผู้ดูแลปิดไว้'];
        }
        if ($active && \App\Support\Saan\Edition::isPublic() && $this->adminState['public_active'] !== null && !in_array($id, $this->adminState['public_active'], true)) {
            [$active, $reason] = [false, 'ไม่ได้เปิดใช้งานในระบบภายใน (รายชื่อโมดูลที่เผยแพร่)'];
        }
        foreach ($m['requires']['modules'] ?? [] as $dep => $constraint) {
            if (!isset($list[$dep])) { [$active, $reason] = [false, 'ไม่พบโมดูลที่ต้องใช้: '.$dep]; break; }
            $this->resolve($dep, $list, $resolved, [...$stack, $id]);
            if (!$resolved[$dep]['active'] || !self::satisfies($resolved[$dep]['version'], $constraint)) { [$active, $reason] = [false, 'ต้องใช้ '.$dep.' '.$constraint]; break; }
        }
        $resolved[$id] = $m + ['active' => $active, 'reason' => $reason, 'disabled_by' => $source, 'admin_enabled' => in_array($id, $this->adminState['enabled'], true) || in_array($id, $envEnabled, true)];
    }

    /** Supports ">=x.y.z", "^x.y" / "^x.y.z" and exact versions. Pre-release tags are ignored for comparison. */
    public static function satisfies(string $version, string $constraint): bool
    {
        $v = preg_replace('/-.*$/', '', trim($version));
        $c = trim($constraint);
        if ($c === '' || $c === '*') return true;
        if (str_starts_with($c, '>=')) return version_compare($v, trim(substr($c, 2)), '>=');
        if (str_starts_with($c, '^')) {
            $base = trim(substr($c, 1));
            $parts = explode('.', $base);
            $major = (int) $parts[0];
            $upper = $major === 0 ? '0.'.(((int) ($parts[1] ?? 0)) + 1).'.0' : ($major + 1).'.0.0';
            return version_compare($v, $base, '>=') && version_compare($v, $upper, '<');
        }
        return version_compare($v, $c, '==');
    }
}
