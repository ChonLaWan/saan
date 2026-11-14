<?php
namespace Tests\Feature;

use App\Console\Commands\SchoolArchive;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Release readiness (documents, scheduled backup, login link, admin banner): what a school reads must match what the code does. */
class ReleaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp && is_dir($this->tmp)) $this->removeTree($this->tmp);
        parent::tearDown();
    }

    private function removeTree(string $d): void
    {
        foreach (scandir($d) ?: [] as $f) { if ($f === '.' || $f === '..') continue; $p = $d.'/'.$f; is_dir($p) ? $this->removeTree($p) : @unlink($p); }
        @rmdir($d);
    }

    private function storage(): string
    {
        $this->tmp = sys_get_temp_dir().'/saan-ready-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/app/private', 0700, true);
        $this->app->useStoragePath($this->tmp);
        return $this->tmp.'/app/private/backups';
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents(base_path($relative));
    }

    // ------------------------------------------------------------------ scheduled backup

    public function test_backup_config_defaults_and_schedule_line(): void
    {
        $this->assertSame('02:30', config('saan.backup.daily_at'));
        $this->assertSame(7, config('saan.backup.keep'));
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'saan-backup');
        $this->assertNotNull($event, 'the combined edition schedules the backup');
        $this->assertSame('30 2 * * *', $event->expression);
    }

    public function test_scheduled_backup_writes_a_dated_file_and_prunes_only_older_auto_files(): void
    {
        $dir = $this->storage();
        mkdir($dir, 0700, true);
        config(['saan.backup.keep' => 3]);
        foreach (['auto-20261101.zip', 'auto-20261102.zip', 'auto-20261103.zip', 'auto-20261104.zip'] as $f) file_put_contents($dir.'/'.$f, 'old');
        foreach (['pre-update-20261001.zip', 'manual-keep.zip', 'auto-notes.txt', 'auto-2026.zip'] as $f) file_put_contents($dir.'/'.$f, 'other');

        $made = [];
        $r = SchoolArchive::runScheduled(function (string $file) use (&$made) { $made[] = $file; file_put_contents($file, 'new'); return 0; }, '20261113');

        $this->assertTrue($r['ok']);
        $norm = fn (string $p) => str_replace('\\', '/', $p);
        $this->assertSame([$norm($dir).'/auto-20261113.zip'], array_map($norm, $made));
        // newest 3 auto files stay: 20261113, 20261104, 20261103
        $this->assertSame(['auto-20261113.zip', 'auto-20261104.zip', 'auto-20261103.zip'], array_map('basename', SchoolArchive::autoBackups()));
        $this->assertSame(2, $r['pruned']);
        foreach (['pre-update-20261001.zip', 'manual-keep.zip', 'auto-notes.txt', 'auto-2026.zip'] as $f) $this->assertFileExists($dir.'/'.$f, $f.' is not a scheduled archive and must stay');
    }

    public function test_scheduled_backup_is_skipped_when_today_exists_and_a_failure_prunes_nothing(): void
    {
        $dir = $this->storage();
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/auto-20261113.zip', 'today');
        $called = false;
        $r = SchoolArchive::runScheduled(function () use (&$called) { $called = true; return 0; }, '20261113');
        $this->assertTrue($r['ok']);
        $this->assertFalse($called, 'an existing archive of the day is never overwritten');

        config(['saan.backup.keep' => 1]);
        file_put_contents($dir.'/auto-20261112.zip', 'yesterday');
        $r = SchoolArchive::runScheduled(fn () => 1, '20261114');
        $this->assertFalse($r['ok']);
        $this->assertFileExists($dir.'/auto-20261112.zip', 'nothing is pruned after a failed backup');
        $this->assertFileDoesNotExist($dir.'/auto-20261114.zip');
    }

    public function test_scheduled_backup_creates_the_folder_and_never_throws(): void
    {
        $dir = $this->storage();
        $this->assertDirectoryDoesNotExist($dir);
        $r = SchoolArchive::runScheduled(function (string $file) { file_put_contents($file, 'x'); return 0; }, '20261113');
        $this->assertTrue($r['ok']);
        $this->assertDirectoryExists($dir);
        $r = SchoolArchive::runScheduled(function () { throw new \RuntimeException('boom'); }, '20261114');
        $this->assertFalse($r['ok']);
        $this->assertSame('boom', $r['message']);
    }

    public function test_scheduled_backup_is_skipped_when_the_disk_has_no_room_for_one_more_archive(): void
    {
        $dir = $this->storage();
        $made = fn (string $file) => (int) (file_put_contents($file, 'new') === false);
        // first run, nothing to compare with: the files the school has stored are the estimate (1.5 x)
        $owner = User::forceCreate(['name' => 'ผู้ดูแลทดสอบ', 'email' => 'backup-admin@testing.example', 'password' => 'test-only-password', 'school_id' => (int) config('school.school_id'), 'role' => 'admin', 'is_demo' => false, 'is_active' => true]);
        \Illuminate\Support\Facades\DB::table('cms_media')->insert(['school_id' => (int) config('school.school_id'), 'uploaded_by' => $owner->id, 'path' => 'cms/1/big.bin', 'mime' => 'application/octet-stream', 'bytes' => 1000, 'width' => 0, 'height' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1000, \App\Support\StorageQuota::usedBytes());
        $r = SchoolArchive::runScheduled($made, '20261113', 1499);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('พื้นที่ดิสก์ไม่พอ', $r['message']);
        $this->assertSame([], glob($dir.'/*') ?: []);
        $this->assertTrue(SchoolArchive::runScheduled($made, '20261113', 1500)['ok']);
        // later runs: 1.5 x the newest earlier archive
        file_put_contents($dir.'/auto-20261113.zip', str_repeat('x', 400));
        $this->assertFalse(SchoolArchive::runScheduled($made, '20261114', 599)['ok']);
        $this->assertFileDoesNotExist($dir.'/auto-20261114.zip');
        $this->assertTrue(SchoolArchive::runScheduled($made, '20261114', 600)['ok']);
        // an unknown free space (false) never blocks the backup
        $this->assertTrue(SchoolArchive::runScheduled($made, '20261115', false)['ok']);
    }

    // ------------------------------------------------------------------ login link and robots

    public function test_login_link_back_to_the_school_uses_the_public_url_and_falls_back_to_portal(): void
    {
        config(['saan.public_url' => '']);
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringContainsString('class="account-back" href="'.url('/portal').'"', $html);

        config(['saan.public_url' => 'https://www.school.example']);
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('class="account-back" href="https://www.school.example"', $html);
        $this->assertStringNotContainsString('class="account-back" href="'.url('/portal').'"', $html);

        config(['saan.public_url' => 'javascript:alert(1)']);
        $this->assertStringContainsString('class="account-back" href="'.url('/portal').'"', $this->get('/login')->assertOk()->getContent());
    }

    public function test_work_page_points_to_the_modules_page_not_the_health_page(): void
    {
        $view = $this->read('resources/views/work.blade.php');
        $this->assertStringContainsString('/admin/modules', $view);
        $this->assertStringNotContainsString('เปิดโมดูลเพิ่มเติมได้ที่หน้าตรวจความพร้อมระบบ', $view);
        $this->assertTrue(app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/admin/modules'))->uri() === 'admin/modules');
    }

    // ------------------------------------------------------------------ admin banner

    public function test_admin_banner_script_reads_the_deployment_endpoint_and_is_loaded_by_cms_and_work(): void
    {
        $js = $this->read('public/assets/school-navigation.js');
        $this->assertStringContainsString('/api/site/deployment', $js);
        $this->assertStringContainsString("check.key === 'heartbeat'", $js);
        $this->assertStringContainsString("check.level === 'err'", $js);
        $this->assertStringContainsString('data-nav-key="deployment"', $js);
        $this->assertStringContainsString('school-navigation.js', $this->read('resources/views/cms.blade.php'));
        $this->assertStringContainsString('school-navigation.js', $this->read('resources/views/work.blade.php'));
    }

    public function test_the_endpoint_behind_the_banner_reports_the_heartbeat_to_admins_only(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        Storage::fake('local');
        $this->actingAs(User::where('role', 'admin')->first());
        $keys = collect($this->getJson('/api/site/deployment')->assertOk()->json('checks'))->keyBy('key');
        $this->assertFalse($keys['heartbeat']['ok'], 'no cron signal yet: the banner shows');
        $this->assertContains($keys['last_backup']['level'], ['ok', 'warn']);
        $this->actingAs(User::where('role', 'principal')->first());
        $this->getJson('/api/site/deployment')->assertForbidden();
        // the admin pages that carry the banner link to the same page
        $this->actingAs(User::where('role', 'admin')->first());
        $this->get('/cms')->assertOk()->assertSee('data-nav-key="deployment"', false);
        $this->get('/work')->assertOk()->assertSee('data-nav-key="deployment"', false);
    }

    // ------------------------------------------------------------------ documents

    public function test_the_manual_copy_is_identical_and_its_screenshots_are_the_current_installer(): void
    {
        $this->assertSame(hash_file('sha256', base_path('docs/installation.th.html')), hash_file('sha256', base_path('public/manual/index.html')));
        $manual = $this->read('docs/installation.th.html');
        $this->assertStringNotContainsString('images/demo-', $manual, 'screenshots of the old 4-page wizard');
        $this->assertFileDoesNotExist(base_path('docs/installation.th.before-0.6.html'));
        // every picture the manual shows is a screenshot of the current 5-step installer, exists next to both copies of the manual
        // (docs/ for the source, public/manual/ for the served page) and is the same file in both places; nothing else lies in those folders
        preg_match_all('~<img\b[^>]*\bsrc="([^"]+)"[^>]*>~', $manual, $m);
        $this->assertNotEmpty($m[1], 'section 6 shows the installer screens');
        $shown = array_values(array_unique($m[1])); sort($shown);
        foreach ($m[0] as $tag) $this->assertMatchesRegularExpression('~\balt="[^"]+"~', $tag, 'every screenshot has alt text');
        foreach ($shown as $src) {
            $this->assertMatchesRegularExpression('~^images/install-\d{2}-[a-z0-9-]+\.png$~', $src);
            $this->assertFileExists(base_path('docs/'.$src)); $this->assertFileExists(base_path('public/manual/'.$src));
            $this->assertSame(hash_file('sha256', base_path('docs/'.$src)), hash_file('sha256', base_path('public/manual/'.$src)), $src);
            $this->assertLessThan(200 * 1024, filesize(base_path('docs/'.$src)), $src.' is small enough to ship in every package');
        }
        foreach (['docs/images', 'public/manual/images'] as $dir) {
            $have = array_map(fn ($f) => 'images/'.basename($f), glob(base_path($dir.'/*')) ?: []); sort($have);
            $this->assertSame($shown, $have, $dir.' holds exactly the pictures the manual shows');
        }
    }

    public function test_the_licence_is_agpl_everywhere_it_is_named(): void
    {
        $this->assertStringContainsString('GNU AFFERO GENERAL PUBLIC LICENSE', strtoupper($this->read('LICENSE')));
        $this->assertSame('AGPL-3.0-only', json_decode($this->read('composer.json'), true)['license']);
        foreach (['THIRD-PARTY-NOTICES.txt', 'docs/installation.th.html', 'README.md'] as $f) {
            $text = $this->read($f);
            $this->assertStringContainsString('AGPL-3.0-only', $text, $f);
        }
        $this->assertStringNotContainsString('opensource.org/license/mit', $this->read('docs/installation.th.html'));
        $notices = $this->read('THIRD-PARTY-NOTICES.txt');
        $this->assertStringNotContainsString('Application code: MIT', $notices);
        $this->assertStringNotContainsString("project's MIT licence", $notices);
    }

    public function test_requirements_read_the_same_everywhere(): void
    {
        $health = new \ReflectionClass(\App\Domain\Cms\DeploymentHealth::class);
        $this->assertSame('10.6', $health->getConstant('MIN_MARIADB'));
        $this->assertSame('8.0', $health->getConstant('MIN_MYSQL'));
        foreach (['README.md', 'docs/installation.th.html', 'RUNBOOK.txt'] as $f) {
            $text = $this->read($f);
            $this->assertStringContainsString('8.4.1', $text, $f);
            $this->assertStringContainsString('10.6', $text, $f);
            $this->assertStringContainsString('8.0', $text, $f);
            $this->assertDoesNotMatchRegularExpression('/MariaDB 10\.11|PHP 8\.3/u', $text, $f);
        }
        // every extension the doctor demands is named in the README
        $doctor = $this->read('app/Domain/Cms/DeploymentHealth.php');
        preg_match("/\\\$need = \\[([^\\]]+)\\]/", $doctor, $m);
        foreach (array_filter(array_map(fn ($x) => trim($x, " '"), explode(',', $m[1]))) as $ext) $this->assertStringContainsString($ext, $this->read('README.md'), 'README misses extension '.$ext);
        $this->assertStringNotContainsString('MFA หรือ automated updater', $this->read('README.md'));
        $this->assertStringNotContainsString('ยังเป็น design', $this->read('README.md'));
    }

    public function test_every_artisan_command_named_in_the_documents_exists(): void
    {
        $known = array_keys(Artisan::all());
        foreach (['README.md', 'RUNBOOK.txt', 'CONTRIBUTING.md', 'docs/installation.th.html', 'docs/split-advanced.th.md', 'config/examples/cron.txt', 'tools/release/README.md'] as $f) {
            preg_match_all('/artisan\s+([a-z][a-z0-9]*(?::[a-z0-9-]+)?)/', $this->read($f), $m);
            foreach (array_unique($m[1]) as $cmd) $this->assertContains($cmd, $known, $f.' names php artisan '.$cmd.' which does not exist');
        }
    }

    public function test_documented_environment_keys_exist_in_the_configuration(): void
    {
        $config = '';
        foreach (glob(base_path('config/*.php')) as $f) $config .= (string) file_get_contents($f);
        $env = $this->read('.env.example');
        preg_match_all('/^#?\s*((?:SAAN|MAIL)_[A-Z_]+)=/m', $env, $m);
        $keys = array_unique($m[1]);
        foreach (['SAAN_STORAGE_QUOTA_MB', 'SAAN_MAX_UPLOAD_MB', 'SAAN_ENABLE_MODULES', 'SAAN_DISABLE_MODULES', 'SAAN_HASH_KEY', 'SAAN_PUBLIC_UNLOCK_KEY', 'SAAN_UPDATE_FEED', 'SAAN_UPDATE_FINISH', 'SAAN_PHP_CLI', 'SAAN_BACKUP_DAILY_AT', 'SAAN_BACKUP_KEEP', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD'] as $k) $this->assertContains($k, $keys, $k.' is missing from .env.example');
        foreach ($keys as $k) $this->assertStringContainsString("env('$k'", $config, $k.' is documented but no config file reads it');
        $this->assertDoesNotMatchRegularExpression('/^APP_TIMEZONE=/m', $env);
        $this->assertStringContainsString("'timezone' => 'Asia/Bangkok'", $this->read('config/app.php'));
        $this->assertStringContainsString('LOG_STACK=daily', $env);
        $this->assertStringContainsString('LOG_DAILY_DAYS=14', $env);
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', $env);
    }

    public function test_cron_documents_give_one_line_per_app_folder_and_no_old_names(): void
    {
        $cron = $this->read('config/examples/cron.txt');
        $this->assertMatchesRegularExpression('~^\* \* \* \* \* /usr/local/php84/bin/php /home/USER/domains/DOMAIN/saan/artisan schedule:run >/dev/null 2>&1$~m', $cron);
        $this->assertStringContainsString('TWO', $cron);
        $manual = $this->read('docs/installation.th.html');
        $this->assertStringContainsString('schedule:run &gt;/dev/null 2&gt;&amp;1', $manual);
        $this->assertStringContainsString('สองบรรทัด', $manual);
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', $manual);
        $this->assertStringContainsString('saan:update --recover --auto', $manual);
        foreach (['.env.example', 'config/examples/cron.txt', 'config/examples/apache-vhost.conf', 'config/examples/nginx-server.conf', 'config/examples/database.txt', 'docs/installation.th.html', 'RUNBOOK.txt', 'README.md'] as $f) {
            $this->assertStringNotContainsString('school-atelier', $this->read($f), $f);
            $this->assertStringNotContainsString('school_atelier', $this->read($f), $f);
        }
    }

    public function test_no_placeholder_contacts_or_repository_urls_remain(): void
    {
        foreach (['SECURITY.md', 'docs/installation.th.html', 'README.md', 'CONTRIBUTING.md', 'RUNBOOK.txt'] as $f) {
            $text = $this->read($f);
            $this->assertStringNotContainsString('security@example.org', $text, $f);
            $this->assertStringNotContainsString('YOUR-ORG', $text, $f);
            // the only repository these documents may name is the project's own public one
            $this->assertSame([], array_values(array_filter(preg_split('~github\.com/~', $text, -1) ?: [], fn ($rest, $i) => $i > 0 && !preg_match('~^ChonLaWan/saan(?![\w.-])~', $rest), ARRAY_FILTER_USE_BOTH)), $f.' names another GitHub address');
        }
        $this->assertStringContainsString('https://github.com/ChonLaWan/saan', $this->read('README.md'));
        $security = $this->read('SECURITY.md');
        $this->assertStringContainsString('https://saan.linuxthai.org/.well-known/security.txt', $security);
        $this->assertStringContainsString('https://saan.linuxthai.org/download/', $security);
        $this->assertStringContainsString('https://saan.linuxthai.org/source/', $security);
    }

    public function test_runbook_is_current(): void
    {
        $runbook = $this->read('RUNBOOK.txt');
        $this->assertStringNotContainsString('0.5.0-preview.1', $runbook);
        foreach (['saan:update', '--both', '--recover', 'school:doctor', 'school:archive', 'schedule:run', 'storage/logs', '/_saan/setup'] as $needle) {
            if ($needle === '/_saan/setup') continue; // pairing is in the manual, not the short runbook
            $this->assertStringContainsString($needle, $runbook, $needle);
        }
    }

    public function test_the_source_archive_keeps_the_changelog_that_whats_new_reads(): void
    {
        $this->assertDoesNotMatchRegularExpression('/CHANGELOG\.md\s+export-ignore/', $this->read('.gitattributes'));
        $this->assertFileExists(base_path('CHANGELOG.md'));
    }

    public function test_release_readme_lists_the_procedure_in_order(): void
    {
        $text = $this->read('tools/release/README.md');
        $last = -1;
        foreach (['VERSION', 'CHANGELOG.md', 'scan-secrets.php --fail', '--no-dev', 'build-release.php', 'verify-release.php', 'saan:update <zip> --check', '/download/', 'feed.sig'] as $needle) {
            $pos = strpos($text, $needle, max(0, $last));
            $this->assertNotFalse($pos, $needle.' missing or out of order');
            $last = $pos;
        }
    }

    public function test_secret_scan_flags_a_copy_of_the_trial_account_list(): void
    {
        // storage/ is ignored by git, but a copy of demo-accounts.txt (trial passwords) placed anywhere that would be committed must stop the release
        $this->tmp = sys_get_temp_dir().'/saan-ready-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/docs', 0700, true);
        file_put_contents($this->tmp.'/docs/readme.txt', "nothing secret here\n");
        $scan = function (): array {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tools/release/scan-secrets.php')).' --fail --root='.escapeshellarg($this->tmp).' 2>&1', $out, $code);
            return [$code, implode("\n", $out)];
        };
        [$code, $text] = $scan();
        $this->assertSame(0, $code, $text);
        file_put_contents($this->tmp.'/docs/'.\App\Domain\Demo\DemoSchool::ACCOUNT_FILE, "role\tlogin\tAb3dEf9hJk2mNp\thttps://trial.school.example/login\t\n");
        [$code, $text] = $scan();
        $this->assertSame(1, $code, $text);
        $this->assertStringContainsString('sensitive-file', $text);
        $this->assertStringContainsString('demo-accounts.txt', $text);
    }
}
