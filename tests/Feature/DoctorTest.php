<?php
namespace Tests\Feature;

use App\Domain\Cms\DeploymentHealth;
use App\Domain\Updater;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DoctorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function health(array $probes = []): DeploymentHealth
    {
        return app(DeploymentHealth::class)->with($probes);
    }

    private function check(array $report, string $key): array
    {
        $c = collect($report['checks'])->firstWhere('key', $key);
        $this->assertNotNull($c, 'missing check '.$key);
        return $c;
    }

    private function production(): void
    {
        app()->detectEnvironment(fn () => 'production');
    }

    public function test_every_check_has_level_and_summary_counts_match(): void
    {
        $r = $this->health()->inspect();
        foreach ($r['checks'] as $c) $this->assertContains($c['level'], ['ok', 'warn', 'err']);
        foreach (['php', 'extensions', 'database', 'db_version', 'schema', 'key', 'disk', 'storage', 'mailer', 'heartbeat', 'modules', 'timezone'] as $k) $this->check($r, $k);
        $this->assertSame(count($r['checks']), array_sum($r['summary']));
        $this->assertSame($r['summary']['err'] > 0, $r['has_errors']);
    }

    public function test_php_version_and_required_extensions(): void
    {
        $this->assertSame('err', $this->check($this->health(['php_version' => '8.2.0'])->inspect(), 'php')['level']);
        $this->assertSame('ok', $this->check($this->health(['php_version' => '8.4.1'])->inspect(), 'php')['level']);

        $all = ['ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo', 'sodium', 'tokenizer', 'xml', 'zip', 'gd', 'pdo_mysql'];
        $this->assertSame('ok', $this->check($this->health(['extensions' => $all])->inspect(), 'extensions')['level']);
        $noSodium = $this->check($this->health(['extensions' => array_diff($all, ['sodium'])])->inspect(), 'extensions');
        $this->assertSame('err', $noSodium['level']);
        $this->assertStringContainsString('sodium', $noSodium['detail']);
        // gd or imagick: neither is an error, one of them is enough
        $noImg = $this->check($this->health(['extensions' => array_diff($all, ['gd'])])->inspect(), 'extensions');
        $this->assertSame('err', $noImg['level']);
        $this->assertStringContainsString('imagick', $noImg['detail']);
        $this->assertSame('ok', $this->check($this->health(['extensions' => [...array_diff($all, ['gd']), 'imagick']])->inspect(), 'extensions')['level']);
    }

    public function test_disk_free_must_cover_twice_package_plus_last_backup(): void
    {
        $mb = 1048576;
        $probes = ['disk_free' => 100 * $mb, 'last_backup_bytes' => 40 * $mb];
        // 2 x (30 + 40) = 140 MB > 100 MB free: blocking once a package is given
        $blocked = $this->check($this->health($probes)->inspect(30 * $mb), 'disk');
        $this->assertSame('err', $blocked['level']);
        $this->assertSame('ok', $this->check($this->health($probes)->inspect(5 * $mb), 'disk')['level']); // 2 x 45 = 90
        // without a package it only warns (last backup alone)
        $this->assertSame('warn', $this->check($this->health(['disk_free' => 50 * $mb, 'last_backup_bytes' => 40 * $mb])->inspect(), 'disk')['level']);
        $this->assertSame('ok', $this->check($this->health(['disk_free' => 10 * $mb, 'last_backup_bytes' => 0])->inspect(), 'disk')['level']);
    }

    public function test_heartbeat_is_ok_when_fresh_and_warns_when_older_than_ten_minutes(): void
    {
        $this->assertSame('warn', $this->check($this->health()->inspect(), 'heartbeat')['level']); // never written
        $this->assertTrue(DeploymentHealth::writeHeartbeat());
        Storage::disk('local')->assertExists(DeploymentHealth::HEARTBEAT_FILE);
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'heartbeat')['level']);

        Storage::disk('local')->put(DeploymentHealth::HEARTBEAT_FILE, json_encode(['at' => now()->subMinutes(9)->utc()->toIso8601String()]));
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'heartbeat')['level']);
        Storage::disk('local')->put(DeploymentHealth::HEARTBEAT_FILE, json_encode(['at' => now()->subMinutes(11)->utc()->toIso8601String()]));
        $stale = $this->check($this->health()->inspect(), 'heartbeat');
        $this->assertSame('warn', $stale['level']);
        $this->assertStringContainsString('cron ไม่ทำงาน', $stale['detail']);
        Storage::disk('local')->put(DeploymentHealth::HEARTBEAT_FILE, 'not json');
        $this->assertSame('warn', $this->check($this->health()->inspect(), 'heartbeat')['level']);
    }

    public function test_heartbeat_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'saan-heartbeat');
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_last_backup_warns_when_missing_or_older_than_three_days(): void
    {
        $at = now();
        $level = fn (?array $last) => $this->check($this->health(['last_backup' => $last, 'now' => $at])->inspect(), 'last_backup');
        $none = $level(null);
        $this->assertSame('warn', $none['level']);
        $this->assertStringContainsString('ยังไม่มีไฟล์สำรอง', $none['detail']);
        $this->assertSame('ok', $level(['file' => 'auto-20261113.zip', 'at' => $at->copy()->subHours(5)->getTimestamp()])['level']);
        $this->assertSame('ok', $level(['file' => 'auto-x.zip', 'at' => $at->copy()->subHours(71)->getTimestamp()])['level']);
        $old = $level(['file' => 'auto-old.zip', 'at' => $at->copy()->subHours(73)->getTimestamp()]);
        $this->assertSame('warn', $old['level']);
        $this->assertStringContainsString('auto-old.zip', $old['detail']);
    }

    public function test_last_backup_reads_the_newest_zip_in_the_backups_folder(): void
    {
        $tmp = sys_get_temp_dir().'/saan-doctor-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/app/private/backups', 0700, true);
        $this->app->useStoragePath($tmp);
        try {
            $this->assertSame('warn', $this->check($this->health()->inspect(), 'last_backup')['level']);
            file_put_contents($tmp.'/app/private/backups/note.txt', 'not an archive');
            $this->assertSame('warn', $this->check($this->health()->inspect(), 'last_backup')['level']);
            file_put_contents($tmp.'/app/private/backups/auto-20260101.zip', 'x');
            touch($tmp.'/app/private/backups/auto-20260101.zip', time() - 86400 * 10);
            $this->assertSame('warn', $this->check($this->health()->inspect(), 'last_backup')['level']);
            file_put_contents($tmp.'/app/private/backups/pre-update-x.zip', 'x');
            $fresh = $this->check($this->health()->inspect(), 'last_backup');
            $this->assertSame('ok', $fresh['level']);
            $this->assertStringContainsString('pre-update-x.zip', $fresh['detail']);
        } finally {
            foreach (glob($tmp.'/app/private/backups/*') ?: [] as $f) @unlink($f);
            @rmdir($tmp.'/app/private/backups'); @rmdir($tmp.'/app/private'); @rmdir($tmp.'/app'); @rmdir($tmp);
        }
    }

    public function test_public_edition_skips_staff_only_checks_and_their_advice(): void
    {
        $staffOnly = ['mailer', 'mail', 'admin', 'admin_mfa', 'scheduler', 'last_backup'];
        $combined = $this->health()->inspect();
        foreach ($staffOnly as $k) $this->check($combined, $k);

        config(['saan.edition' => 'public']);
        $public = $this->health()->inspect();
        $keys = array_column($public['checks'], 'key');
        foreach ($staffOnly as $k) $this->assertNotContains($k, $keys, $k.' must not be checked on the public edition');
        foreach (['php', 'database', 'schema', 'heartbeat', 'timezone'] as $k) $this->assertContains($k, $keys, $k);
        $this->assertStringNotContainsString('school:admin', json_encode($public, JSON_UNESCAPED_UNICODE));
        // the readiness of the scheduler follows the cron heartbeat there (cms:publish-due does not run on the public edition)
        DeploymentHealth::writeHeartbeat();
        $this->assertTrue($this->health()->inspect()['readiness']['scheduler']);
        Storage::disk('local')->delete(DeploymentHealth::HEARTBEAT_FILE);
        $this->assertFalse($this->health()->inspect()['readiness']['scheduler']);
    }

    public function test_full_trial_install_is_flagged_and_its_account_file_is_warned_about(): void
    {
        $keys = fn () => array_column($this->health()->inspect()['checks'], 'key');
        $this->assertNotContains('demo_school', $keys()); // an ordinary install shows neither row
        $this->assertNotContains('demo_accounts_file', $keys());

        \App\Support\Saan\Settings::put(\App\Domain\Demo\DemoSchool::MARKER, ['installed_at' => now()->toIso8601String(), 'source' => 'installer']);
        $tmp = sys_get_temp_dir().'/saan-doctor-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/app/private', 0700, true);
        $this->app->useStoragePath($tmp);
        try {
            $r = $this->health()->inspect();
            $trial = $this->check($r, 'demo_school');
            $this->assertSame('warn', $trial['level']);
            $this->assertStringContainsString('ระบบทดลอง (ข้อมูลสมมติ) ห้ามใช้กับโรงเรียนจริง', $trial['detail']);
            $this->assertSame('ok', $this->check($r, 'demo_accounts_file')['level']); // a warning, never an [ERR] that would block updates

            file_put_contents(\App\Domain\Demo\DemoSchool::accountFile(), "role\tlogin\tpassword\n");
            $r = $this->health()->inspect();
            $file = $this->check($r, 'demo_accounts_file');
            $this->assertSame('warn', $file['level']);
            $this->assertStringContainsString('demo-accounts.txt', $file['detail']);
            $text = DeploymentHealth::renderText($r);
            $this->assertMatchesRegularExpression('/\[WARN\][^\n]*ระบบทดลอง \(ข้อมูลสมมติ\) ห้ามใช้กับโรงเรียนจริง/u', $text);
            $this->assertMatchesRegularExpression('/\[WARN\][^\n]*demo-accounts\.txt/u', $text);

            // the public edition has no marker of its own to read (saan_settings is not published): nothing is reported there
            config(['saan.edition' => 'public']);
            $this->assertNotContains('demo_school', $keys());
            $this->assertNotContains('demo_accounts_file', $keys());
        } finally {
            @unlink($tmp.'/app/private/demo-accounts.txt');
            @rmdir($tmp.'/app/private'); @rmdir($tmp.'/app'); @rmdir($tmp);
        }
    }

    public function test_timezone_advice_names_the_config_file_not_an_env_variable(): void
    {
        config(['app.timezone' => 'UTC']);
        $tz = $this->check($this->health()->inspect(), 'timezone');
        $this->assertSame('warn', $tz['level']);
        $this->assertStringContainsString('config/app.php', $tz['detail']);
        $this->assertStringNotContainsString('APP_TIMEZONE', $tz['detail']);
    }

    public function test_queue_flags_failed_and_stuck_jobs(): void
    {
        if (!Schema::hasTable('jobs') || !Schema::hasTable('failed_jobs')) $this->markTestSkipped('queue tables not present');
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'queue')['level']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time() - 7200]);
        $q = $this->check($this->health()->inspect(), 'queue');
        $this->assertSame('warn', $q['level']);
        $this->assertStringContainsString('1 งานค้าง', $q['detail']);
        DB::table('jobs')->delete();
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'queue')['level']);
        DB::table('failed_jobs')->insert(['uuid' => 'fake-uuid-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'fictional', 'failed_at' => now()]);
        $this->assertStringContainsString('1 งานล้มเหลว', $this->check($this->health()->inspect(), 'queue')['detail']);
    }

    public function test_mailer_debug_and_app_key_in_production(): void
    {
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'mailer')['level']); // not production: log mailer is fine
        $this->production();
        $this->assertSame('school', config('mail.default')); // real value after boot; the check must not rely on it
        config(['mail.school_fallback' => 'log', 'app.debug' => true]);
        $r = $this->health()->inspect();
        $this->assertSame('warn', $this->check($r, 'mailer')['level']);
        $this->assertStringContainsString('smtp', $this->check($r, 'mailer')['detail']);
        $this->assertSame('err', $this->check($r, 'debug')['level']);
        $this->assertStringContainsString('ใช้งานจริง', $this->check($r, 'debug')['detail']);
        config(['mail.school_fallback' => 'smtp', 'app.debug' => false]);
        $r = $this->health()->inspect();
        $this->assertSame('ok', $this->check($r, 'mailer')['level']);
        $this->assertSame('ok', $this->check($r, 'debug')['level']);

        // fallback=log but the school saved an smtp provider (encrypted)
        config(['mail.school_fallback' => 'log']);
        DB::table('school_mail_settings')->insert(['school_id' => config('school.school_id'), 'encrypted_payload' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['provider' => 'smtp'])), 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'mailer')['level']);
        DB::table('school_mail_settings')->update(['encrypted_payload' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['provider' => 'disabled']))]);
        $this->assertSame('warn', $this->check($this->health()->inspect(), 'mailer')['level']);
        DB::table('school_mail_settings')->update(['encrypted_payload' => 'garbage']);
        $this->assertStringContainsString('unreadable', $this->check($this->health()->inspect(), 'mailer')['detail']);
        DB::table('school_mail_settings')->delete();

        config(['app.key' => '']);
        $key = $this->check($this->health()->inspect(), 'key');
        $this->assertSame('err', $key['level']);
        $this->assertStringContainsString('APP_KEY', $key['detail']);
    }

    public function test_https_and_timezone(): void
    {
        config(['app.url' => 'http://school.test', 'app.timezone' => 'UTC']);
        $r = $this->health()->inspect();
        $this->assertSame('warn', $this->check($r, 'https')['level']);
        $this->assertSame('warn', $this->check($r, 'timezone')['level']);
        config(['app.url' => 'https://school.test', 'app.timezone' => 'Asia/Bangkok']);
        $r = $this->health()->inspect();
        $this->assertSame('ok', $this->check($r, 'https')['level']);
        $this->assertSame('ok', $this->check($r, 'timezone')['level']);
    }

    public function test_database_version_thresholds(): void
    {
        $level = fn (string $v) => $this->check($this->health(['db_version' => $v])->inspect(), 'db_version')['level'];
        $this->assertSame('warn', $level('10.5.9-MariaDB'));
        $this->assertSame('ok', $level('10.6.12-MariaDB-log'));
        $this->assertSame('ok', $level('5.5.5-10.11.6-MariaDB')); // legacy replication prefix is ignored
        $this->assertSame('warn', $level('5.7.44'));
        $this->assertSame('ok', $level('8.0.36'));
        $this->assertSame('warn', $level(''));
    }

    public function test_pending_migration_is_an_error(): void
    {
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'schema')['level']);
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();
        $s = $this->check($this->health()->inspect(), 'schema');
        $this->assertSame('err', $s['level']);
        $this->assertStringContainsString('รอติดตั้ง', $s['detail']);
    }

    public function test_pending_migration_of_an_active_module_is_counted(): void
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $dirs = glob(base_path('modules/*/database/migrations'), GLOB_ONLYDIR) ?: [];
        $target = null;
        foreach ($dirs as $d) foreach (glob($d.'/*.php') ?: [] as $f) if (in_array(pathinfo($f, PATHINFO_FILENAME), $ran, true)) { $target = pathinfo($f, PATHINFO_FILENAME); break 2; }
        if (!$target) $this->markTestSkipped('no applied module migration');
        DB::table('migrations')->where('migration', $target)->delete();
        $this->assertSame('err', $this->check($this->health()->inspect(), 'schema')['level']);
    }

    private function fakeRegistry(array $modules): void
    {
        $this->app->instance(ModuleRegistry::class, new class($modules) extends ModuleRegistry {
            public function __construct(private array $list) {}
            public function all(): array { return $this->list; }
            public function summary(): array { return []; }
        });
    }

    private function module(string $id, array $requires, bool $active = true, string $reason = 'เปิดใช้งาน', string $version = '0.1.0'): array
    {
        return ['id' => $id, 'name' => $id, 'version' => $version, 'readiness' => 'beta', 'requires' => $requires, 'active' => $active, 'reason' => $reason, 'path' => base_path('modules/'.$id)];
    }

    public function test_modules_with_unmet_dependencies_are_errors(): void
    {
        $this->fakeRegistry(['a' => $this->module('a', ['core' => '>=0.1.0', 'modules' => []]), 'b' => $this->module('b', ['core' => '>=0.1.0', 'modules' => ['a' => '>=0.1.0']])]);
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'modules')['level']);

        // registry already switched these off: advice only (warn), never a blocker
        $this->fakeRegistry(['b' => $this->module('b', ['core' => '>=0.1.0', 'modules' => ['ghost' => '>=0.1.0']], false, 'ไม่พบโมดูลที่ต้องใช้: ghost')]);
        $r = $this->health()->inspect();
        $this->assertSame('ok', $this->check($r, 'modules')['level']);
        $m = $this->check($r, 'modules_inactive');
        $this->assertSame('warn', $m['level']);
        $this->assertStringContainsString('ghost', $m['detail']);

        $this->fakeRegistry(['a' => $this->module('a', ['core' => '>=0.1.0', 'modules' => []], true, 'เปิดใช้งาน', '0.1.0'), 'b' => $this->module('b', ['core' => '>=0.1.0', 'modules' => ['a' => '>=9.0.0']], false, 'ต้องใช้ a >=9.0.0')]);
        $this->assertSame('warn', $this->check($this->health()->inspect(), 'modules_inactive')['level']);

        $this->fakeRegistry(['n' => $this->module('n', ['core' => '>=99.0.0', 'modules' => []], false, 'ต้องการแกนรุ่น >=99.0.0')]);
        $this->assertSame('warn', $this->check($this->health()->inspect(), 'modules_inactive')['level']);

        // disabled by admin or .env: not listed at all
        foreach (['admin', 'env'] as $src) {
            $this->fakeRegistry(['d' => $this->module('d', ['core' => '>=0.1.0', 'modules' => ['ghost' => '*']], false, 'ข้อความอะไรก็ได้') + ['disabled_by' => $src]]);
            $r = $this->health()->inspect();
            $this->assertSame('ok', $this->check($r, 'modules')['level']);
            $this->assertNull(collect($r['checks'])->firstWhere('key', 'modules_inactive'));
        }
    }

    public function test_updater_preflight_of_a_is_not_blocked_by_module_b_waiting_for_a(): void
    {
        $this->fakeRegistry(['a' => $this->module('a', ['core' => '>=0.1.0', 'modules' => []], true, 'เปิดใช้งาน', '0.1.0'), 'b' => $this->module('b', ['core' => '>=0.1.0', 'modules' => ['a' => '>=0.2.0']], false, 'ต้องใช้ a >=0.2.0')]);
        $m = ['type' => 'module', 'id' => 'a', 'version' => '0.2.0', 'current_version' => '0.1.0', 'requires' => ['core' => '>=0.1.0', 'modules' => []], 'files' => []];
        $this->app->instance(DeploymentHealth::class, $this->health($this->safeProbes()));
        $this->assertSame([], array_values(array_filter((new Updater)->preflight($m), fn ($p) => str_starts_with($p, '[ERR]'))));
    }

    private function safeProbes(): array
    {
        $all = ['ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo', 'sodium', 'tokenizer', 'xml', 'zip', 'gd', 'pdo_mysql'];
        $p = ['php_version' => '8.4.1', 'extensions' => $all, 'disk_free' => PHP_INT_MAX, 'last_backup_bytes' => 0];
        foreach (['storage/app/private', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $d) $p['writable:'.$d] = true;
        return $p;
    }

    public function test_storage_writable_check_covers_subfolders(): void
    {
        $this->assertSame('ok', $this->check($this->health($this->safeProbes())->inspect(), 'storage')['level']);
        $s = $this->check($this->health(['writable:storage/framework/views' => false] + $this->safeProbes())->inspect(), 'storage');
        $this->assertSame('err', $s['level']);
        $this->assertStringContainsString('storage/framework/views', $s['detail']);
    }

    public function test_disk_unknown_free_space_is_a_warn(): void
    {
        $mb = 1048576;
        $this->assertSame('warn', $this->check($this->health(['disk_free' => false, 'last_backup_bytes' => 0])->inspect(), 'disk')['level']);
        $this->assertSame('warn', $this->check($this->health(['disk_free' => false, 'last_backup_bytes' => 0])->inspect(5 * $mb), 'disk')['level']);
    }

    public function test_schema_detail_names_pending_migrations(): void
    {
        $name = DB::table('migrations')->orderByDesc('id')->value('migration');
        DB::table('migrations')->where('migration', $name)->delete();
        $this->assertStringContainsString($name, $this->check($this->health()->inspect(), 'schema')['detail']);
    }

    public function test_json_command_survives_invalid_utf8_and_exit_codes(): void
    {
        $this->app->instance(ModuleRegistry::class, new class extends ModuleRegistry {
            public function all(): array { throw new \RuntimeException("module.json \xB1\xFF bad"); }
            public function summary(): array { return []; }
        });
        $this->assertSame(2, Artisan::call('school:doctor', ['--json' => true]));
        $this->assertIsArray(json_decode(Artisan::output(), true));
    }

    public function test_invalid_module_json_is_an_error(): void
    {
        $this->app->instance(ModuleRegistry::class, new class extends ModuleRegistry {
            public function all(): array { throw new \RuntimeException('module.json ไม่ถูกต้อง: modules/x/module.json'); }
            public function summary(): array { return []; }
        });
        $m = $this->check($this->health()->inspect(), 'modules');
        $this->assertSame('err', $m['level']);
        $this->assertStringContainsString('module.json', $m['detail']);
    }

    public function test_real_modules_on_disk_are_valid(): void
    {
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'modules')['level']);
    }

    public function test_storage_quota_is_reported(): void
    {
        config(['saan.storage.quota_mb' => 0]);
        $this->assertSame('ok', $this->check($this->health()->inspect(), 'storage_quota')['level']);
        $mb = 1048576;
        $this->assertSame('warn', $this->check($this->health(['storage_quota' => ['quota_bytes' => $mb, 'used_bytes' => (int) ($mb * 0.95), 'percent' => 95]])->inspect(), 'storage_quota')['level']);
        $this->assertSame('ok', $this->check($this->health(['storage_quota' => ['quota_bytes' => $mb, 'used_bytes' => (int) ($mb * 0.5), 'percent' => 50]])->inspect(), 'storage_quota')['level']);
        // an unreadable quota is reported, not silently dropped
        $this->assertSame('warn', $this->check($this->health(['storage_quota' => []])->inspect(), 'storage_quota')['level']);
    }

    public function test_text_report_uses_markers_and_summary(): void
    {
        $text = DeploymentHealth::renderText($this->health(['php_version' => '8.1.0'])->inspect());
        $this->assertStringContainsString('[ERR]', $text);
        $this->assertStringContainsString('[OK]', $text);
        $this->assertStringContainsString('สรุป:', $text);
        $this->assertStringContainsString('ต้องแก้ก่อนอัปเดต', $text);
    }

    public function test_command_prints_thai_report_and_json(): void
    {
        $this->assertSame(1, Artisan::call('school:doctor')); // test env has WARNs
        $out = Artisan::output();
        $this->assertStringContainsString('[OK]', $out);
        $this->assertStringContainsString('สรุป:', $out);
        $this->assertStringNotContainsString(config('app.key'), $out);

        Artisan::call('school:doctor', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('summary', $json);
        $this->assertArrayHasKey('level', $json['checks'][0]);

        Artisan::call('school:doctor', ['--json' => true, '--package-bytes' => PHP_INT_MAX >> 2]);
        $big = collect(json_decode(Artisan::output(), true)['checks'])->firstWhere('key', 'disk');
        $this->assertSame('err', $big['level']);
    }

    public function test_updater_preflight_refuses_on_any_err_and_passes_without(): void
    {
        $m = ['type' => 'core', 'id' => 'core', 'version' => '9.9.9', 'current_version' => null, 'requires' => [], 'files' => []];
        $this->app->instance(DeploymentHealth::class, $this->health(['php_version' => '8.1.0']));
        $problems = (new Updater)->preflight($m);
        $this->assertTrue(collect($problems)->contains(fn ($p) => str_starts_with($p, '[ERR]') && str_contains($p, 'PHP')));

        $this->app->instance(DeploymentHealth::class, $this->health($this->safeProbes()));
        $this->assertSame([], array_values(array_filter((new Updater)->preflight($m), fn ($p) => str_starts_with($p, '[ERR]'))));
    }

    public function test_updater_preflight_blocks_on_disk_with_package_size(): void
    {
        $mb = 1048576;
        $m = ['type' => 'core', 'id' => 'core', 'version' => '9.9.9', 'current_version' => null, 'requires' => [], 'files' => [['path' => 'app/x.php', 'bytes' => 30 * $mb, 'sha256' => str_repeat('0', 64)]]];
        $this->app->instance(DeploymentHealth::class, $this->health(['disk_free' => 100 * $mb, 'last_backup_bytes' => 40 * $mb]));
        $this->assertTrue(collect((new Updater)->preflight($m))->contains(fn ($p) => str_starts_with($p, '[ERR]') && str_contains($p, 'พื้นที่ดิสก์')));
    }
}
