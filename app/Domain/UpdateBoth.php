<?php

namespace App\Domain;

use RuntimeException;

/**
 * S7: `saan:update --both <zip>` on the internal edition of a standard (one machine, two app roots) install.
 * One signed package serves both editions, so the package is applied to the internal root first and then to the public root (a separate
 * `php artisan saan:update` process in that root, which has its own .env, storage and database). Before anything is written both sides are
 * checked (signature, files, requirements, doctor). If the public side fails after the internal side was updated, the internal update is taken
 * back from its own snapshot (files only: migrations are expand-only and stay). The public side rolls itself back through its own Updater.
 *
 * $runPublic(string $root, list<string> $artisanArgs): array{0:int,1:string} exit code and output; tests replace it.
 */
final class UpdateBoth
{
    /** @var callable */
    private $runPublic;
    /** @var callable */
    private $log;
    /** True when the real process runner is used (tests replace it). */
    private bool $real;
    /** Environment variable through which the parent tells the public child which edition it must run as; the child refuses anything else. */
    public const EXPECT_ENV = 'SAAN_UPDATE_EXPECT_EDITION';
    /** The only variables the public child inherits from the internal process (upper case): where to find programs and a temp folder, nothing of any .env. */
    private const KEEP_ENV = ['PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR', 'COMSPEC', 'PATHEXT', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'LANG', 'LC_ALL', 'TZ', 'PHPRC', 'PHP_INI_SCAN_DIR'];

    public function __construct(?callable $runPublic = null, ?callable $log = null, private ?string $php = null)
    {
        $this->real = $runPublic === null;
        $this->runPublic = $runPublic ?? $this->process(...);
        $this->log = $log ?? fn () => null;
    }

    private function say(string $line): void { ($this->log)($line); }

    /** The public app root recorded by the installer in storage/installed.json (internal edition of a standard install only). */
    public static function publicRoot(): string
    {
        $file = storage_path('installed.json');
        $i = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($i) || ($i['layout'] ?? '') !== 'standard' || ($i['edition'] ?? '') !== 'internal' || !is_string($i['public_root'] ?? null) || $i['public_root'] === '') {
            throw new RuntimeException('--both ใช้ได้เฉพาะระบบภายในของการติดตั้งแบบมาตรฐาน (ไม่พบ public_root ใน storage/installed.json)');
        }
        $root = realpath($i['public_root']);
        if (!$root || !is_file($root.'/artisan')) throw new RuntimeException('ไม่พบโฟลเดอร์แอปหน้าบ้าน: '.$i['public_root']);
        if ($root === realpath(base_path())) throw new RuntimeException('โฟลเดอร์หน้าบ้านต้องไม่ใช่โฟลเดอร์เดียวกับระบบภายใน');
        return $root;
    }

    /** True on the internal side of a standard install: the only place where one update must reach two app roots. Never throws. */
    public static function required(): bool
    {
        $file = storage_path('installed.json');
        $i = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        return is_array($i) && ($i['layout'] ?? '') === 'standard' && ($i['edition'] ?? '') === 'internal';
    }

    /** Update folders of the public root (storage/app/private/updates/*, not incoming). */
    private static function updateDirs(string $root): array
    {
        $out = [];
        foreach (glob($root.'/storage/app/private/updates/*', GLOB_ONLYDIR) ?: [] as $d) if (basename($d) !== 'incoming') $out[] = realpath($d) ?: $d;
        return $out;
    }

    /** A public update folder that is neither rolled back nor recovered (unfinished or completed): it must be taken back. */
    private static function mustTakeBack(string $dir): bool
    {
        $r = is_file($dir.'/receipt.json') ? json_decode((string) @file_get_contents($dir.'/receipt.json'), true) : null;
        return is_array($r) && empty($r['rolled_back']) && empty($r['recovered_at']);
    }

    /** @return array{internal:array,public:string} */
    public function run(string $zip, bool $backupDatabase = true): array
    {
        $root = self::publicRoot();
        if ($this->real) self::assertOwnerSafe($root);
        $updater = new Updater($this->log);
        // one lock on the internal side from the first check to the last rollback: the web update button or another saan:update cannot slip in between
        $lock = Updater::acquireLock();
        try { return $this->runLocked($updater, $root, $zip, $backupDatabase); }
        finally { Updater::releaseLock($lock); }
    }

    private function runLocked(Updater $updater, string $root, string $zip, bool $backupDatabase): array
    {
        $m = $updater->inspect($zip);
        if ($problems = $updater->preflight($m)) throw new RuntimeException("ตรวจความพร้อมฝั่งระบบภายในไม่ผ่าน ยังไม่แก้ไฟล์ทั้งสองฝั่ง:\n- ".implode("\n- ", $problems));
        [$code, $out] = ($this->runPublic)($root, ['saan:update', '--check', $zip]);
        if ($code !== 0) throw new RuntimeException("ตรวจความพร้อมฝั่งหน้าบ้านไม่ผ่าน ยังไม่แก้ไฟล์ทั้งสองฝั่ง:\n".trim($out));
        $this->say('[OK] ตรวจแพ็กเกจผ่านทั้งสองฝั่ง');

        $this->say('[OK] ระบบภายใน: เริ่มอัปเดต');
        $before = self::updateDirs($root);
        $receipt = $updater->applyLocked($zip, $backupDatabase); // a failure here has already restored the internal files; the public side was not touched
        $work = (string) ($receipt['work'] ?? '');

        $this->say('[OK] หน้าบ้าน: เริ่มอัปเดต');
        $args = ['saan:update', $zip];
        if (!$backupDatabase) $args[] = '--skip-db-backup';
        try {
            [$code, $out] = ($this->runPublic)($root, $args);
            if ($code === 0) { $this->say('[OK] อัปเดตทั้งสองฝั่งเสร็จ'); return ['internal' => $receipt, 'public' => trim($out)]; }
            $why = trim($out);
        } catch (\Throwable $e) {
            $why = $e->getMessage();
        }
        // the public side failed. Only the update folders THIS run created are touched (never an old leftover snapshot), and they are taken back even when the
        // public update had already completed (non-zero exit or a timeout after the last step), so both sides end on the old release
        $this->say('[ERR] หน้าบ้านอัปเดตไม่สำเร็จ กำลังคืนหน้าบ้านและระบบภายในเป็นรุ่นเดิม');
        $publicBack = [];
        foreach (array_diff(self::updateDirs($root), $before) as $dir) {
            if (!self::mustTakeBack($dir)) continue;
            try {
                [$c, $o] = ($this->runPublic)($root, ['saan:update', '--recover', '--completed', '--recover-dir='.$dir]);
                if ($c !== 0) $publicBack[] = basename($dir).': '.trim($o);
            } catch (\Throwable $e) { $publicBack[] = basename($dir).': '.$e->getMessage(); }
        }
        try {
            if ($work === '') throw new RuntimeException('ไม่มีบันทึกอัปเดตของระบบภายใน');
            $updater->recoverLocked($work, true);
        } catch (\Throwable $e) {
            throw new RuntimeException('หน้าบ้านอัปเดตไม่สำเร็จ และคืนระบบภายในไม่สำเร็จ ต้องกู้ด้วยมือ (saan:update --recover): '.$e->getMessage().' | '.$why, 0, $e);
        }
        if ($publicBack) throw new RuntimeException('หน้าบ้านอัปเดตไม่สำเร็จ คืนระบบภายในแล้ว แต่คืนหน้าบ้านไม่สำเร็จ ต้องรัน saan:update --recover ในโฟลเดอร์หน้าบ้านด้วยมือ: '.implode(' | ', $publicBack).' | '.$why);
        throw new RuntimeException('หน้าบ้านอัปเดตไม่สำเร็จ คืนไฟล์ทั้งสองฝั่งเป็นรุ่นเดิมแล้ว (migration แบบเพิ่มอย่างเดียวคงอยู่): '.$why);
    }

    /**
     * The public root is writable by the public site, so PHP from it (bootstrap/app.php, vendor/autoload.php) must never run with the rights of the
     * internal side. --both is refused when this process is root, or is not the owner of the public root: update each root on its own, as the user of that root.
     * $euid and $owner are for tests (default: this process and the owner of artisan; without POSIX there is no owner model and nothing is checked).
     */
    public static function assertOwnerSafe(string $root, ?int $euid = null, ?int $owner = null, ?array $stats = null): void
    {
        $euid ??= function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($owner === null && $euid !== null) { $o = @fileowner($root.'/artisan'); $owner = $o === false ? null : (int) $o; }
        if ($euid === null) return;
        if ($euid === 0) throw new RuntimeException('ไม่รัน --both ด้วยสิทธิ์ root: โค้ด PHP ในโฟลเดอร์หน้าบ้านที่ถูกเจาะจะทำงานเป็น root ได้ ให้รัน saan:update ในแต่ละโฟลเดอร์ด้วยผู้ใช้ของโฟลเดอร์นั้น');
        if ($owner === null || $owner !== $euid) throw new RuntimeException('โฟลเดอร์หน้าบ้านเป็นของผู้ใช้ระบบคนละคนกับผู้ที่รันคำสั่งนี้ (uid '.$euid.' / '.($owner ?? '?').') ไม่รัน PHP จากโฟลเดอร์ที่หน้าบ้านเขียนได้ด้วยสิทธิ์ฝั่งภายใน ให้รัน saan:update ในแต่ละโฟลเดอร์ด้วยผู้ใช้ของโฟลเดอร์นั้น');
        // the code the child loads must be this user's and not writable by group or others (the public pool may share the group).
        // bootstrap/cache and the compiled views are not checked here: the child never reads them (childCachePaths), and storage holds no code it runs.
        $stats ??= self::codeStats($root);
        foreach ($stats as $path => [$uid, $mode]) {
            if ($uid !== $euid || ($mode & 0022) !== 0) throw new RuntimeException('โค้ดหน้าบ้าน '.$path.' เป็นของผู้ใช้อื่นหรือกลุ่ม/คนอื่นเขียนได้ (uid '.$uid.', mode '.decoct($mode & 0777).') ไม่รัน --both ให้แก้สิทธิ์ (chmod go-w) หรือรัน saan:update ในแต่ละโฟลเดอร์ด้วยผู้ใช้ของโฟลเดอร์นั้น');
        }
    }

    /** Files and folders of the public root whose PHP the child runs (top level only; vendor is checked at its entry points). @return array<string,array{0:int,1:int}> */
    private static function codeStats(string $root): array
    {
        $out = [];
        foreach (['', 'artisan', 'bootstrap', 'bootstrap/app.php', 'bootstrap/providers.php', 'app', 'config', 'routes', 'modules', 'database', 'database/migrations', 'installer', 'vendor', 'vendor/autoload.php', 'vendor/composer', 'vendor/laravel/framework/src'] as $rel) {
            $p = $rel === '' ? $root : $root.'/'.$rel;
            if (!file_exists($p)) continue;
            $s = @stat($p);
            if ($s === false) throw new RuntimeException('อ่านสิทธิ์ของ '.$p.' ไม่ได้ ไม่รัน --both');
            $out[$rel === '' ? '.' : $rel] = [(int) $s['uid'], (int) $s['mode']];
        }
        return $out;
    }

    /**
     * Cache files the public child would otherwise load from bootstrap/cache (writable by the public site) are sent to a fresh private folder,
     * so a config.php, services.php or packages.php the public side planted never runs with the rights of the internal side.
     * @return array<string,string>
     */
    public static function childCachePaths(string $dir): array
    {
        return [
            'APP_CONFIG_CACHE' => $dir.'/config.php', 'APP_SERVICES_CACHE' => $dir.'/services.php', 'APP_PACKAGES_CACHE' => $dir.'/packages.php',
            'APP_ROUTES_CACHE' => $dir.'/routes-v7.php', 'APP_EVENTS_CACHE' => $dir.'/events.php', 'VIEW_COMPILED_PATH' => $dir.'/views',
        ];
    }

    /** Names defined in an .env file (empty on a missing or unreadable file). @return list<string> */
    private static function envFileKeys(string $file): array
    {
        try { return is_file($file) ? array_map('strval', array_keys(\Dotenv\Dotenv::parse((string) file_get_contents($file)))) : []; } catch (\Throwable) { return []; }
    }

    /**
     * Environment of the public child: every variable of this process and every key of the internal .env is removed (false = unset in Symfony Process),
     * except the few in KEEP_ENV. Without this the child inherits what Laravel's dotenv put into getenv()/$_ENV for the internal .env, and that immutable
     * environment wins over the public root's own .env (internal database, APP_KEY, edition).
     * @param list<string> $present names in this process's environment
     * @param list<string> $envKeys names defined in the internal .env
     * @return array<string,string|false>
     */
    public static function childEnv(array $present, array $envKeys): array
    {
        $env = [];
        foreach (array_unique(array_merge($present, $envKeys)) as $k) {
            $k = (string) $k;
            if ($k === '' || in_array(strtoupper($k), self::KEEP_ENV, true)) continue;
            $env[$k] = false;
        }
        $env[self::EXPECT_ENV] = 'public';
        return $env;
    }

    /** Runs in the public child before anything is written: the edition and the database must be the public ones, not the internal environment that leaked in. */
    public static function assertChildEdition(string $expected): void
    {
        $edition = (string) config('saan.edition', 'combined');
        if ($edition !== $expected) throw new RuntimeException('โฟลเดอร์นี้ทำงานเป็น edition '.$edition.' แต่ต้องเป็น '.$expected.' (ได้ environment ของอีกฝั่งมา) ยกเลิก ไม่แก้อะไร');
        $file = base_path('.env');
        $want = is_file($file) ? (string) (\Dotenv\Dotenv::parse((string) file_get_contents($file))['DB_DATABASE'] ?? '') : '';
        $have = (string) config('database.connections.'.config('database.default').'.database', '');
        if ($want !== '' && $want !== $have) throw new RuntimeException('ฐานข้อมูลที่ใช้อยู่ ('.$have.') ไม่ตรงกับ .env ของโฟลเดอร์นี้ ('.$want.') ยกเลิก ไม่แก้อะไร');
    }

    /** Real runner: one artisan process in the public root (its own .env, storage and database). */
    private function process(string $root, array $args): array
    {
        $php = $this->php ?: PHP_BINARY;
        $present = array_merge(array_keys(getenv()), array_keys($_ENV), array_keys(array_filter($_SERVER, 'is_string')));
        $env = self::childEnv($present, self::envFileKeys(base_path('.env')));
        $tmp = rtrim(sys_get_temp_dir(), '/\\').'/saan-both-'.bin2hex(random_bytes(8));
        $old = umask(0077);
        try {
            if (!@mkdir($tmp, 0700) || !@mkdir($tmp.'/views', 0700)) throw new RuntimeException('สร้างโฟลเดอร์ชั่วคราวสำหรับอัปเดตหน้าบ้านไม่ได้');
        } finally { umask($old); }
        // Laravel treats only paths starting with / or \ as absolute: elsewhere (a Windows drive letter) the default bootstrap/cache stays, which has no owner model anyway
        if (str_starts_with($tmp, '/') || str_starts_with($tmp, '\\')) $env = self::childCachePaths($tmp) + $env;
        try {
            // longer than the public side's own backup + finish step: killing it while its finisher still migrates would leave the two sides on different releases
            $p = new \Symfony\Component\Process\Process(array_merge([$php, 'artisan'], $args, ['--no-interaction']), $root, $env, null, (int) config('saan.update_finish_timeout', 1800) + 900);
            $p->run();
            return [$p->getExitCode() ?? 1, $p->getOutput().$p->getErrorOutput()];
        } finally {
            self::removeTree($tmp);
            // the child's config:clear/route:clear went to $tmp: remove the public site's own compiled caches so it rebuilds them for the new release (deleting runs nothing)
            foreach (['config.php', 'routes-v7.php', 'events.php', 'services.php', 'packages.php'] as $f) @unlink($root.'/bootstrap/cache/'.$f);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) { @unlink($dir); return; }
        foreach (scandir($dir) ?: [] as $f) if ($f !== '.' && $f !== '..') self::removeTree($dir.'/'.$f);
        @rmdir($dir);
    }
}
