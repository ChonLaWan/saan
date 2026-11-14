<?php
namespace App\Domain\Cms;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Doctor / preflight (plan 3.3 step 1, RT-07). Every check has `ok` (bool) and `level` ok|warn|err.
 * An `err` blocks updates (Updater::preflight); a `warn` is advice only.
 */
class DeploymentHealth
{
    public const HEARTBEAT_FILE = 'system/heartbeat.json';
    public const HEARTBEAT_STALE_MINUTES = 10;
    /** The "last backup" check warns when the newest file in storage/app/private/backups is older than this many days. */
    public const BACKUP_STALE_DAYS = 3;
    public const MIN_MARIADB = '10.6';
    public const MIN_MYSQL = '8.0';

    /** @var array<string,mixed> test fakes: php_version, extensions (loaded list), disk_free, last_backup_bytes, db_version, now */
    private array $probes = [];

    /** Replace real probes with fakes (tests). Returns a new instance. */
    public function with(array $probes): static
    {
        $c = clone $this; $c->probes = $probes + $this->probes; return $c;
    }

    /** Scheduled every minute from routes/console.php. Returns false when the file could not be written. */
    public static function writeHeartbeat(): bool
    {
        return (bool) Storage::disk('local')->put(self::HEARTBEAT_FILE, json_encode(['at' => now()->utc()->toIso8601String()]));
    }

    private function probe(string $key, callable $real): mixed
    {
        return array_key_exists($key, $this->probes) ? $this->probes[$key] : $real();
    }

    /** @param int|null $packageBytes size of the package about to be installed (disk-space check becomes blocking when given) */
    public function inspect(?int $packageBytes = null): array
    {
        $production = app()->environment('production');
        // The public edition has no staff accounts, mail settings or cms:publish-due job: those checks (and their advice) exist only where staff work is served.
        $staff = \App\Support\Saan\Edition::serves('staff');
        $now = $this->probe('now', fn () => now());
        $checks = [];
        // $err: when the check fails, is it an [ERR] (blocks updates) rather than a [WARN]?
        $add = function ($key, $label, $ok, $detail, bool $err = false) use (&$checks) {
            $ok = (bool) $ok;
            $checks[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'level' => $ok ? 'ok' : ($err ? 'err' : 'warn'), 'detail' => $detail];
        };

        // PHP and extensions
        $phpVersion = (string) $this->probe('php_version', fn () => PHP_VERSION);
        $add('php', 'PHP ที่รองรับ', version_compare($phpVersion, '8.4.1', '>='), $phpVersion, true);
        // Only the web server's own setting matters (a CLI run shows the CLI's): with timestamp validation off, PHP-FPM never notices changed
        // files, so after an update started from SSH or cron the site keeps running the previous release until PHP is reloaded.
        if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
            $validates = filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL);
            // opcache.restrict_api can forbid this site to empty the cache: then the updater cannot do it either
            $usable = is_array(@opcache_get_status(false)) || @opcache_invalidate(__FILE__, false) === true;
            $add('opcache', 'OPcache หลังอัปเดต', $validates || $usable, $validates
                ? 'เว็บตรวจไฟล์ที่เปลี่ยนเอง (opcache.validate_timestamps เปิด)'.($usable ? '' : ' · โฮสต์ไม่ให้เว็บล้าง OPcache เอง (opcache.restrict_api)')
                : ($usable ? 'opcache.validate_timestamps=0: ตัวอัปเดตล้าง OPcache ของเว็บให้เองทั้งจากปุ่มในหลังบ้านและจากคำสั่ง (ต้องเรียกเว็บของตัวเองได้)'
                    : 'opcache.validate_timestamps=0 และโฮสต์ไม่ให้เว็บล้าง OPcache เอง (opcache.restrict_api): หลังอัปเดตทุกครั้งต้อง restart PHP จึงจะได้โค้ดชุดใหม่'));
        }
        $loaded = $this->probe('extensions', fn () => array_map('strtolower', get_loaded_extensions()));
        $has = fn ($x) => in_array($x, $loaded, true);
        $need = ['ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo', 'sodium', 'tokenizer', 'xml', 'zip'];
        $driver = 'unknown';
        try { $driver = DB::connection()->getDriverName(); } catch (\Throwable) {}
        if (in_array($driver, ['mysql', 'mariadb'], true) || $production) $need[] = 'pdo_mysql';
        $missing = array_values(array_filter($need, fn ($x) => !$has($x)));
        if (!$has('gd') && !$has('imagick')) $missing[] = 'gd หรือ imagick';
        $add('extensions', 'ส่วนขยาย PHP', !$missing, $missing ? 'ขาด: '.implode(', ', $missing) : 'ครบ', true);

        // S1: a parent-domain session cookie (SESSION_DOMAIN=.school.ac.th) would be sent to the public edition's host too (XSRF-TOKEN has no edition suffix).
        $sessionDomain = trim((string) config('session.domain'));
        $add('session_domain', 'คุกกี้เซสชันผูกกับโฮสต์เดียว (SESSION_DOMAIN)', !str_starts_with($sessionDomain, '.'), $sessionDomain === '' ? 'ว่าง (เฉพาะโฮสต์)' : $sessionDomain.' - ควรเว้นว่างเมื่อแยกหน้าบ้านกับระบบภายใน');

        // S6: split install (internal/public): pairing, last push/pull, envelopes, rejected signatures, key files. Empty in the combined edition.
        $splitChecks = [];
        try { $splitChecks = array_merge(\App\Support\Saan\Publish\SplitHealth::identityChecks(), \App\Support\Saan\Publish\SplitHealth::checks()); } catch (\Throwable) {}

        // Database
        $dbOk = false;
        try { DB::select('select 1'); $dbOk = true; $add('database', 'เชื่อมต่อฐานข้อมูล', true, $driver); } catch (\Throwable) { $add('database', 'เชื่อมต่อฐานข้อมูล', false, 'เชื่อมต่อไม่ได้', true); }
        $add(...$this->dbVersionCheck($driver, $dbOk));

        // Pending migrations: core + active modules
        $pending = [];
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
            $dirs = [database_path('migrations'), ...app('migrator')->paths()];
            foreach ($this->activeModulePaths() as $p) $dirs[] = $p.'/database/migrations';
            foreach (array_unique($dirs) as $dir) foreach (glob($dir.'/*.php') ?: [] as $file) if (!in_array(pathinfo($file, PATHINFO_FILENAME), $ran, true)) $pending[] = basename($file);
            $pending = array_values(array_unique($pending));
        } catch (\Throwable) { $pending = ['ยังไม่มีตาราง migration']; }
        $schemaDetail = !$pending ? 'เป็นปัจจุบัน' : ($pending === ['ยังไม่มีตาราง migration'] ? $pending[0] : count($pending).' รายการรอติดตั้ง: '.implode(', ', array_slice($pending, 0, 5)).(count($pending) > 5 ? ' ...' : ''));
        $add('schema', 'โครงสร้างฐานข้อมูล', !$pending, $schemaDetail, true);
        foreach ($splitChecks as $c) $add(...$c);

        // RT-07: the key must actually work with the configured cipher, not just be long enough.
        $keySet = (string) config('app.key') !== '';
        try { \Illuminate\Support\Facades\Crypt::decryptString(\Illuminate\Support\Facades\Crypt::encryptString('saan-health')); $keyOk = $keySet; } catch (\Throwable) { $keyOk = false; }
        $add('key', 'application key ใช้เข้ารหัสได้จริง', $keyOk, !$keySet ? 'ยังไม่ได้ตั้ง APP_KEY' : ($keyOk ? 'เข้ารหัส/ถอดรหัสได้ (ไม่แสดงค่า key)' : 'APP_KEY ไม่ถูกต้องกับ cipher '.config('app.cipher')), true);
        $add('debug', 'ปิด debug สำหรับเผยแพร่', !config('app.debug'), config('app.debug') ? ($production ? 'APP_DEBUG เปิดอยู่ในระบบใช้งานจริง ต้องปิด' : 'ยังเปิด debug') : 'ปิดแล้ว', $production);
        $add('demo', 'ปิดบัญชีสาธิต', !config('school.demo'), config('school.demo') ? 'สาธิตในเครื่อง' : 'ปิดแล้ว');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $add('https', 'URL เว็บไซต์ใช้ HTTPS', $https, $https ? 'ใช้ HTTPS' : 'HTTP / เครื่องทดสอบ');
        $add('sessions', 'เข้ารหัส session', config('session.encrypt') === true, config('session.encrypt') ? 'เปิดแล้ว' : 'แนะนำเปิดสำหรับโรงเรียนจริง');
        $add('public_access', 'เปิดให้ผู้ชมภายนอกเข้าถึง', !config('school.local_only'), config('school.local_only') ? 'SCHOOL_LOCAL_ONLY=true ใช้ได้เฉพาะเครื่องนี้' : 'เปิดสาธารณะ');
        $secureCookie = (bool) config('session.secure');
        $add('cookies', 'cookie ปลอดภัย (Secure/HttpOnly)', $secureCookie && config('session.http_only') !== false, ($secureCookie ? 'Secure' : 'ยังไม่ Secure (ตั้ง SESSION_SECURE_COOKIE=true เมื่อใช้ HTTPS)').' · '.(config('session.http_only') !== false ? 'HttpOnly' : 'ไม่ HttpOnly'));
        $add('backup', 'สำรอง/กู้คืนได้ (ZipArchive)', class_exists(\ZipArchive::class), class_exists(\ZipArchive::class) ? 'มี ZipArchive สำหรับ school:archive' : 'ต้องเปิด PHP zip extension ก่อนสำรองข้อมูล');

        // Mail: RT-01 smtp in production, plus the last recorded test (staff editions only)
        // config('mail.default') is always 'school' after AppServiceProvider boot: inspect the transport actually used.
        if ($staff) {
        $fallback = (string) config('mail.school_fallback', 'log'); $provider = 'env';
        try { $mrow = DB::table('school_mail_settings')->where('school_id', config('school.school_id'))->first(); if ($mrow) $provider = (string) (json_decode(\Illuminate\Support\Facades\Crypt::decryptString($mrow->encrypted_payload), true)['provider'] ?? 'env'); } catch (\Throwable) { $provider = 'unreadable'; }
        $effective = $provider === 'env' ? $fallback : $provider;
        $smtp = in_array($effective, ['smtp', 'gmail'], true);
        $add('mailer', 'ตัวส่งเมลเป็น SMTP', !$production || $smtp, $smtp ? 'smtp ('.$provider.')' : (($provider === 'env' ? 'MAIL_MAILER='.$fallback : 'ตั้งค่าเมลโรงเรียน: '.$provider).($production ? ' ในระบบใช้งานจริง ต้องเป็น smtp (RT-01)' : ' (เครื่องทดสอบ)')));
        $mailOk = false; $mailDetail = 'ยังไม่ทดสอบส่งเมล';
        try { $row = DB::table('school_mail_settings')->where('school_id', config('school.school_id'))->first(); if ($row) { $mailOk = $row->last_test_status === 'accepted'; $mailDetail = $row->last_test_status ? ('ทดสอบล่าสุด: '.$row->last_test_status) : 'บันทึกแล้วแต่ยังไม่ทดสอบ'; } } catch (\Throwable) {}
        $add('mail', 'ส่งอีเมลได้', $mailOk, $mailDetail);
        }

        // Storage quota and disk
        try { $q = $this->probe('storage_quota', fn () => \App\Support\StorageQuota::summary((int) config('school.school_id'))); $add('storage_quota', 'พื้นที่ไฟล์ของโรงเรียน', $q['quota_bytes'] === 0 || $q['percent'] < 90, $q['quota_bytes'] ? (round($q['used_bytes'] / 1048576, 1).' / '.round($q['quota_bytes'] / 1048576).' MB ('.$q['percent'].'%)') : ('ใช้ไป '.round($q['used_bytes'] / 1048576, 1).' MB ไม่จำกัดโควตา')); } catch (\Throwable) { $add('storage_quota', 'พื้นที่ไฟล์ของโรงเรียน', false, 'อ่านการใช้พื้นที่ไม่ได้'); }
        $free = $this->probe('disk_free', fn () => @disk_free_space(base_path()));
        $lastBackup = (int) $this->probe('last_backup_bytes', function () {
            $b = \App\Domain\Updater::backups()[0]['bytes'] ?? null;
            if ($b !== null) return $b;
            try { return (int) \App\Support\StorageQuota::summary((int) config('school.school_id'))['used_bytes']; } catch (\Throwable) { return 0; }
        });
        $required = 2 * ((int) $packageBytes + $lastBackup);
        $mb = fn ($b) => round($b / 1048576).' MB';
        $freeKnown = $free !== false && $free !== null;
        $diskOk = $freeKnown && $free >= $required;
        $add('disk', 'พื้นที่ดิสก์สำหรับอัปเดต', $diskOk, $freeKnown ? ('ว่าง '.$mb($free).' ต้องการอย่างน้อย '.$mb($required).' (2 x แพ็กเกจ+สำรองล่าสุด)') : 'ตรวจพื้นที่ว่างไม่ได้', $freeKnown && $packageBytes !== null);
        $dirs = ['storage/app/private', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'];
        $bootstrapChecked = is_dir(base_path('bootstrap'));
        if ($bootstrapChecked) $dirs[] = 'bootstrap/cache';
        $bad = array_values(array_filter($dirs, fn ($d) => !$this->probe('writable:'.$d, function () use ($d) {
            // storage/* via storage_path (may be relocated); a missing folder is fine when the nearest existing parent is writable (it gets created on use)
            $path = str_starts_with($d, 'storage/') ? storage_path(substr($d, 8)) : base_path($d);
            while (!file_exists($path) && $path !== dirname($path)) $path = dirname($path);
            return is_dir($path) && is_writable($path);
        })));
        $add('storage', 'เขียนพื้นที่จัดเก็บได้', !$bad, $bad ? 'เขียนไม่ได้: '.implode(', ', $bad) : 'storage'.($bootstrapChecked ? ' และ bootstrap/cache' : ' (ไม่พบ bootstrap ตรวจ bootstrap/cache ไม่ได้)'), true);

        if ($staff) {
        try { $admin = DB::table('users')->where('school_id', config('school.school_id'))->where('role', 'admin')->where('is_active', true)->where('is_demo', false)->exists(); } catch (\Throwable) { $admin = false; }
        $add('admin', 'บัญชีผู้ดูแลจริง', $admin, $admin ? 'มีบัญชีผู้ดูแลที่เปิดใช้งาน' : 'สร้างด้วย school:admin');
        try { $admins = DB::table('users')->where('school_id', config('school.school_id'))->where('role', 'admin')->where('is_active', true)->where('is_demo', false); $total = (clone $admins)->count(); $withMfa = (clone $admins)->whereNotNull('mfa_enabled_at')->count(); $add('admin_mfa', 'ผู้ดูแลเปิดยืนยันสองขั้นตอน', $total > 0 && $withMfa === $total, $withMfa.' / '.$total.' บัญชีผู้ดูแล (ตั้งได้ที่ บัญชีของฉัน)'); } catch (\Throwable) {}
        }

        // Full trial install (installer mode "full" or saan:demo install): every report says so, and warns while the account list with its passwords is on disk.
        // Staff editions only: the marker lives in saan_settings, which never leaves the internal side. An ordinary install gets neither row.
        if ($staff && $this->probe('demo_school', fn () => \App\Domain\Demo\DemoSchool::marker())) {
            $add('demo_school', 'ระบบทดลอง (ข้อมูลสมมติ)', false, 'ระบบทดลอง (ข้อมูลสมมติ) ห้ามใช้กับโรงเรียนจริง เมื่อจะใช้จริงให้ติดตั้งใหม่ในโฟลเดอร์และฐานข้อมูลใหม่');
            $list = (bool) $this->probe('demo_accounts_file', fn () => is_file(\App\Domain\Demo\DemoSchool::accountFile()));
            $add('demo_accounts_file', 'ไฟล์รายการบัญชีทดลอง', !$list, $list ? 'storage/app/private/'.\App\Domain\Demo\DemoSchool::ACCOUNT_FILE.' ยังอยู่ (มีรหัสผ่านของบัญชีทดลอง) ลบไฟล์นี้เมื่อจดแล้ว' : 'ลบแล้ว');
        }

        // Last backup: the newest *.zip in storage/app/private/backups (scheduled auto-*.zip, pre-update-*.zip or a manual archive put there).
        // Staff editions only: the public edition holds nothing that saan:publish --full cannot rebuild from the internal one.
        if ($staff) {
            $last = $this->probe('last_backup', function () {
                $newest = null;
                foreach (glob(\App\Console\Commands\SchoolArchive::backupDir().'/*.zip') ?: [] as $f) { $m = (int) @filemtime($f); if ($newest === null || $m > $newest['at']) $newest = ['file' => basename($f), 'at' => $m]; }
                return $newest;
            });
            $ageHours = $last ? max(0, (int) floor(($now->getTimestamp() - (int) $last['at']) / 3600)) : null;
            $fresh = $last && $ageHours < self::BACKUP_STALE_DAYS * 24;
            $auto = trim((string) config('saan.backup.daily_at', '')) !== '';
            $add('last_backup', 'ไฟล์สำรองล่าสุด', $fresh, $fresh
                ? $last['file'].' เมื่อ '.($ageHours < 48 ? $ageHours.' ชั่วโมงก่อน' : intdiv($ageHours, 24).' วันก่อน')
                : ($last ? 'ไฟล์ล่าสุด '.$last['file'].' เก่ากว่า '.self::BACKUP_STALE_DAYS.' วัน' : 'ยังไม่มีไฟล์สำรองใน storage/app/private/backups')
                    .($auto ? ' (ตั้งสำรองอัตโนมัติไว้แล้ว ตรวจว่า cron ทำงาน)' : ' ตั้ง SAAN_BACKUP_DAILY_AT หรือรัน school:archive backup'));
        }

        // Scheduler: publish-due heartbeat (5 min) and the generic cron heartbeat (10 min)
        $read = function (string $file) { try { return Storage::disk('local')->exists($file) ? json_decode((string) Storage::disk('local')->get($file), true) : null; } catch (\Throwable) { return null; } };
        if ($staff) {
            $hb = $read('system/cms-publish-heartbeat.json');
            $scheduler = $hb && isset($hb['at']) && \Carbon\CarbonImmutable::parse($hb['at'])->greaterThan($now->copy()->subMinutes(5));
            $add('scheduler', 'งานเผยแพร่ตามเวลา', $scheduler, $scheduler ? 'พบการทำงานภายใน 5 นาที' : 'ยังไม่พบหลักฐานล่าสุด ให้ตั้ง schedule:run ทุกนาที');
        }
        $beat = $read(self::HEARTBEAT_FILE);
        $beatAt = null; try { $beatAt = ($beat && isset($beat['at'])) ? \Carbon\CarbonImmutable::parse($beat['at']) : null; } catch (\Throwable) {}
        $beatOk = $beatAt && $beatAt->greaterThan($now->copy()->subMinutes(self::HEARTBEAT_STALE_MINUTES));
        $add('heartbeat', 'cron (schedule:run) ทำงาน', $beatOk, $beatOk ? 'ตรวจพบล่าสุด '.$beatAt->toIso8601String() : ($beatAt ? 'cron ไม่ทำงาน: สัญญาณล่าสุดเมื่อ '.$beatAt->toIso8601String() : 'cron ไม่ทำงาน: ยังไม่พบสัญญาณ ตั้ง schedule:run ทุกนาที'));

        // Queue
        try {
            if (Schema::hasTable('failed_jobs') || Schema::hasTable('jobs')) {
                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
                $stuck = Schema::hasTable('jobs') ? DB::table('jobs')->where('created_at', '<', $now->copy()->subHour()->getTimestamp())->count() : 0;
                $add('queue', 'คิวงาน', $failed === 0 && $stuck === 0, $failed === 0 && $stuck === 0 ? 'ไม่มีงานล้มเหลวหรือค้าง' : $failed.' งานล้มเหลว · '.$stuck.' งานค้างเกิน 1 ชั่วโมง');
            }
        } catch (\Throwable) {}

        // Modules and timezone
        $add(...$this->modulesCheck());
        if ($inactive = $this->inactiveModulesCheck()) $add(...$inactive);
        $tz = (string) config('app.timezone');
        $add('timezone', 'เขตเวลา Asia/Bangkok', $tz === 'Asia/Bangkok', $tz === 'Asia/Bangkok' ? $tz : 'ตั้งไว้เป็น '.$tz.' ใน config/app.php (เขตเวลากำหนดในไฟล์นั้น ไม่ใช่ตัวแปร .env และสานออกแบบไว้สำหรับ Asia/Bangkok)');

        $group = fn (array $keys) => collect($checks)->whereIn('key', $keys)->every(fn ($c) => $c['ok']);
        $readiness = ['cms_public' => $group(['php', 'extensions', 'database', 'schema', 'key', 'debug', 'demo', 'https', 'public_access', 'cookies', 'storage', 'admin']), 'backup' => $group(['backup', 'storage']), 'mail' => $group(['mail']), 'scheduler' => $group($staff ? ['scheduler'] : ['heartbeat'])];
        $count = fn ($l) => count(array_filter($checks, fn ($c) => $c['level'] === $l));
        return ['release' => config('saan.version'), 'readiness' => $readiness, 'modules' => app(ModuleRegistry::class)->summary(), 'checks' => $checks,
            'summary' => ['ok' => $count('ok'), 'warn' => $count('warn'), 'err' => $count('err')], 'has_errors' => $count('err') > 0,
            'technical_checks_passed' => count(array_filter($checks, fn ($c) => $c['ok'])), 'technical_checks_total' => count($checks), 'local_only' => (bool) config('school.local_only'),
            'note' => 'ผลนี้เป็นการตรวจทางเทคนิคของเครื่องปัจจุบัน ต้องมี backup/restore ที่ตรวจแล้วและโรงเรียนตรวจรับก่อนใช้งานจริง'];
    }

    /** Messages of failed [ERR] checks: what blocks an update. */
    public function errors(?int $packageBytes = null): array
    {
        return array_values(array_map(fn ($c) => $c['label'].': '.$c['detail'], array_filter($this->inspect($packageBytes)['checks'], fn ($c) => $c['level'] === 'err')));
    }

    /** Thai report with [OK]/[WARN]/[ERR] markers, one line per check. */
    public static function renderText(array $report): string
    {
        $mark = ['ok' => '[OK]  ', 'warn' => '[WARN]', 'err' => '[ERR] '];
        $lines = ['ตรวจความพร้อมระบบ สาน (Saan) รุ่น '.($report['release'] ?? '-')];
        foreach ($report['checks'] as $c) $lines[] = $mark[$c['level']].' '.$c['label'].' — '.$c['detail'];
        $s = $report['summary'];
        $lines[] = 'สรุป: ผ่าน '.$s['ok'].' · เตือน '.$s['warn'].' · ผิดพลาด '.$s['err'].($s['err'] ? ' (ผิดพลาดต้องแก้ก่อนอัปเดตระบบ)' : '');
        return implode(PHP_EOL, $lines);
    }

    private function dbVersionCheck(string $driver, bool $dbOk): array
    {
        $label = 'รุ่นฐานข้อมูล';
        if (!in_array($driver, ['mysql', 'mariadb'], true) && !array_key_exists('db_version', $this->probes)) return ['db_version', $label, true, $driver.' (ไม่ตรวจรุ่น)'];
        try { $raw = (string) $this->probe('db_version', fn () => $dbOk ? (string) (DB::selectOne('select version() as v')->v ?? '') : ''); } catch (\Throwable) { $raw = ''; }
        if ($raw === '') return ['db_version', $label, false, 'อ่านรุ่นฐานข้อมูลไม่ได้'];
        $maria = stripos($raw, 'mariadb') !== false;
        $clean = preg_replace('/^5\.5\.5-/', '', $raw);
        preg_match('/\d+\.\d+(\.\d+)?/', $clean, $m);
        $v = $m[0] ?? '0';
        $min = $maria ? self::MIN_MARIADB : self::MIN_MYSQL;
        $ok = version_compare($v, $min, '>=');
        return ['db_version', $label, $ok, ($maria ? 'MariaDB ' : 'MySQL ').$v.($ok ? '' : ' ต่ำกว่าที่รองรับ (ขั้นต่ำ '.$min.')')];
    }

    private function modulesCheck(): array
    {
        $label = 'โมดูลที่ติดตั้ง';
        try { $all = app(ModuleRegistry::class)->all(); } catch (\Throwable $e) { return ['modules', $label, false, 'module.json ไม่ถูกต้อง: '.$e->getMessage(), true]; }
        return ['modules', $label, true, count($all).' โมดูล module.json ถูกต้อง', true];
    }

    /** Modules the registry switched off for unmet conditions: advice only, they are not running so they cannot break an update. */
    private function inactiveModulesCheck(): ?array
    {
        try { $all = app(ModuleRegistry::class)->all(); } catch (\Throwable) { return null; }
        $warn = [];
        foreach ($all as $id => $m) {
            if ($m['active']) continue;
            if (($m['disabled_by'] ?? null) !== null || ($m['readiness'] ?? '') === 'experimental') continue;
            $warn[] = $id.': '.$m['reason'];
        }
        return $warn ? ['modules_inactive', 'โมดูลที่ถูกปิดเพราะเงื่อนไขไม่ครบ', false, implode('; ', $warn)] : null;
    }

    private function activeModulePaths(): array
    {
        try { return array_values(array_map(fn ($m) => $m['path'], array_filter(app(ModuleRegistry::class)->all(), fn ($m) => $m['active']))); } catch (\Throwable) { return []; }
    }
}
