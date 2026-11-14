<?php

namespace Tests\Feature;

use App\Domain\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REL-02: signed package install, refusal cases and automatic rollback. Uses a throw-away module "zz-update-test".
 * The whole app (base_path, public_path, storage_path) is re-pointed at a private temp tree for each test, so the real
 * modules/ and public/ folders are never written and parallel test processes cannot see the throw-away module.
 */
class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;
    private string $dir;
    private string $realBase;
    private string $id = 'zz-update-test';

    protected function setUp(): void
    {
        parent::setUp();
        $kp = sodium_crypto_sign_keypair(); $this->secret = sodium_crypto_sign_secretkey($kp);
        config(['saan.release_public_key' => base64_encode(sodium_crypto_sign_publickey($kp))]);
        $this->dir = sys_get_temp_dir().'/saan-updater-'.bin2hex(random_bytes(4)); mkdir($this->dir);
        foreach (['/storage/app/private','/storage/framework/cache','/storage/framework/views','/storage/logs'] as $sub) mkdir($this->dir.$sub, 0700, true);
        $this->realBase = base_path();
        foreach (['/base/modules', '/base/public/assets/modules'] as $sub) mkdir($this->dir.$sub, 0755, true);
        $this->app->setBasePath($this->dir.'/base');
        $this->app->useStoragePath($this->dir.'/storage');
        $this->app->usePublicPath($this->dir.'/base/public');
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
    }

    protected function tearDown(): void
    {
        try { $this->cleanup(); }
        finally {
            $this->app->setBasePath($this->realBase);
            app(\App\Support\Modules\ModuleRegistry::class)->flush();
            $this->removeTree($this->dir);
            parent::tearDown();
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }

    private function cleanup(): void
    {
        foreach ([base_path('modules/'.$this->id), public_path('assets/modules/'.$this->id)] as $d) if (is_dir($d)) {
            // Windows: an indexer or antivirus may hold a just-written file for a moment ("Resource temporarily unavailable"); retry briefly
            $retry = static function (callable $op, string $p): void { for ($i = 0; ; $i++) { try { $op($p); return; } catch (\ErrorException $e) { if ($i >= 20) throw $e; usleep(50000); clearstatcache(); } } };
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) $retry($f->isDir() ? 'rmdir' : 'unlink', $f->getPathname());
            $retry('rmdir', $d);
        }
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
    }

    private function package(string $version, array $files, array $override = [], ?callable $tamper = null): string
    {
        $manifest = array_replace(['type' => 'module', 'id' => $this->id, 'version' => $version, 'requires' => ['core' => '>=0.6.0', 'modules' => []], 'created_at' => gmdate('c'), 'files' => []], $override);
        foreach ($files as $path => $content) $manifest['files'][] = ['path' => $path, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $zipPath = $this->dir.'/pkg-'.$version.'-'.bin2hex(random_bytes(3)).'.zip'; $zip = new \ZipArchive; $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', $json); $zip->addFromString('manifest.sig', base64_encode(sodium_crypto_sign_detached($json, $this->secret)));
        foreach ($files as $path => $content) $zip->addFromString('payload/'.$path, $tamper ? $tamper($path, $content) : $content);
        $zip->close(); return $zipPath;
    }

    private function moduleJson(string $version): string
    {
        return json_encode(['id' => $this->id, 'name' => 'Update test', 'version' => $version, 'readiness' => 'beta', 'requires' => ['core' => '>=0.6.0', 'modules' => []], 'package' => 'separate'], JSON_PRETTY_PRINT);
    }

    public function test_signed_module_package_installs_and_is_recorded(): void
    {
        $pkg = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0'), 'public/assets/modules/'.$this->id.'/app.js' => "console.log('v1')"]);
        $receipt = (new Updater)->apply($pkg, false);
        $this->assertSame('1.0.0', $receipt['to']);
        $this->assertStringContainsString('v1', file_get_contents(public_path('assets/modules/'.$this->id.'/app.js')));
        $this->assertDatabaseHas('system_updates', ['package_id' => $this->id, 'to_version' => '1.0.0', 'status' => 'completed']);
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $v2 = $this->package('1.0.1', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.1'), 'public/assets/modules/'.$this->id.'/app.js' => "console.log('v2')"]);
        $this->assertSame('1.0.0', (new Updater)->inspect($v2)['current_version']);
        (new Updater)->apply($v2, false);
        $this->assertStringContainsString('v2', file_get_contents(public_path('assets/modules/'.$this->id.'/app.js')));
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $old = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0')]);
        $this->assertNotEmpty((new Updater)->preflight((new Updater)->inspect($old)), 'downgrade must be refused');
        // isolation: the throw-away module lives only in the temp tree, never in the real folders
        $this->assertDirectoryExists(base_path('modules/'.$this->id));
        $this->assertStringStartsWith($this->dir, base_path());
        $this->assertDirectoryDoesNotExist($this->realBase.'/modules/'.$this->id);
        $this->assertDirectoryDoesNotExist($this->realBase.'/public/assets/modules/'.$this->id);
    }

    public function test_updates_do_not_flush_the_cache_and_remove_files_the_new_release_dropped(): void
    {
        \Illuminate\Support\Facades\Cache::put('saan-test-counter', 7, 600);
        $v1 = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0'), 'public/assets/modules/'.$this->id.'/app.js' => 'a', 'public/assets/modules/'.$this->id.'/old.js' => 'old']);
        (new Updater)->apply($v1, false);
        $this->assertSame(7, \Illuminate\Support\Facades\Cache::get('saan-test-counter'), 'rate-limit counters and idempotency records must survive an update');
        $this->assertFileExists(public_path('assets/modules/'.$this->id.'/old.js'));
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $v2 = $this->package('1.0.1', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.1'), 'public/assets/modules/'.$this->id.'/app.js' => 'b']);
        $receipt = (new Updater)->apply($v2, false);
        $this->assertFileDoesNotExist(public_path('assets/modules/'.$this->id.'/old.js'));
        $this->assertSame(['public/assets/modules/'.$this->id.'/old.js'], $receipt['removed']);
        $this->assertSame(7, \Illuminate\Support\Facades\Cache::get('saan-test-counter'));
    }

    public function test_a_second_update_or_a_recover_is_refused_while_one_is_running(): void
    {
        $dir = storage_path('app/private/updates'); @mkdir($dir, 0700, true);
        $h = fopen($dir.'/.lock', 'c'); $this->assertTrue(flock($h, LOCK_EX | LOCK_NB));
        try {
            $pkg = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0')]);
            try { (new Updater)->apply($pkg, false); $this->fail('apply must be refused'); } catch (\App\Domain\UpdateBusyException) {}
            try { (new Updater)->recover(); $this->fail('recover must be refused'); } catch (\App\Domain\UpdateBusyException) {}
            $this->assertDirectoryDoesNotExist(base_path('modules/'.$this->id));
        } finally { flock($h, LOCK_UN); fclose($h); }
        (new Updater)->apply($pkg, false); // free again
        $this->assertDirectoryExists(base_path('modules/'.$this->id));
    }

    public function test_tampered_unsigned_and_out_of_scope_packages_are_refused(): void
    {
        $tampered = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0')], [], fn ($p, $c) => $c.' ');
        $this->expectRefusal($tampered, 'ไม่ครบหรือถูกแก้');
        $escape = $this->package('1.0.0', ['app/Domain/Evil.php' => '<?php']);
        $this->expectRefusal($escape, 'นอกพื้นที่');
        $env = $this->package('1.0.0', ['.env' => 'APP_KEY=x'], ['type' => 'core', 'id' => 'core']);
        $this->expectRefusal($env, 'นอกพื้นที่');
        $pkg = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0')]);
        config(['saan.release_public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
        $this->expectRefusal($pkg, 'ลายเซ็น');
        $this->assertDirectoryDoesNotExist(base_path('modules/'.$this->id));
    }

    private function expectRefusal(string $pkg, string $needle): void
    {
        try { (new Updater)->apply($pkg, false); $this->fail('package should be refused'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString($needle, $e->getMessage()); }
    }

    public function test_failed_migration_rolls_back_files(): void
    {
        $v1 = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0'), 'public/assets/modules/'.$this->id.'/app.js' => "console.log('v1')"]);
        (new Updater)->apply($v1, false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $broken = "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void { throw new \\RuntimeException('boom'); } };\n";
        $v2 = $this->package('1.0.1', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.1'), 'public/assets/modules/'.$this->id.'/app.js' => "console.log('v2')", 'modules/'.$this->id.'/database/migrations/2099_01_01_000000_broken.php' => $broken]);
        // the provider registers module migration paths at boot; register this new path the same way
        app('migrator')->path(base_path('modules/'.$this->id.'/database/migrations'));
        try { (new Updater)->apply($v2, false); $this->fail('should roll back'); } catch (\RuntimeException $e) { $this->assertStringContainsString('คืนไฟล์รุ่นเดิมแล้ว', $e->getMessage()); }
        $this->assertStringContainsString('v1', file_get_contents(public_path('assets/modules/'.$this->id.'/app.js')));
        $this->assertFileDoesNotExist(base_path('modules/'.$this->id.'/database/migrations/2099_01_01_000000_broken.php'));
        $this->assertStringContainsString('"1.0.0"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
        $this->assertDatabaseHas('system_updates', ['package_id' => $this->id, 'to_version' => '1.0.1', 'status' => 'rolled_back']);
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_web_updater_lists_incoming_packages_and_requires_password(): void
    {
        config(['school.demo' => false]);
        // on MySQL/MariaDB the web updater takes a pre-update archive; the real one needs its own transaction (REPEATABLE READ), which cannot
        // start inside the test's wrapping transaction, so a stand-in command takes its place (the archive itself has its own tests)
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            app(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new class extends \Illuminate\Console\Command {
                protected $signature = 'school:archive {action} {path}';
                public function handle(): int { file_put_contents($this->argument('path'), 'stub'); return 0; }
            });
        }
        $admin = \App\Models\User::forceCreate(['name' => 'Admin', 'email' => 'upd-admin@school.test', 'password' => 'Original-Password-2026', 'role' => 'admin', 'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1]);
        $pkg = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0')]);
        @mkdir(storage_path('app/private/updates/incoming'), 0700, true); copy($pkg, storage_path('app/private/updates/incoming/saan-mod-'.$this->id.'-1.0.0.zip'));
        file_put_contents(storage_path('app/private/updates/incoming/saan-fake.zip'), 'not a zip');
        $list = $this->actingAs($admin)->getJson('/api/site/updates')->assertOk()->json('packages');
        $this->assertTrue(collect($list)->firstWhere('file', 'saan-mod-'.$this->id.'-1.0.0.zip')['valid']);
        $this->assertFalse(collect($list)->firstWhere('file', 'saan-fake.zip')['valid']);
        $this->postJson('/api/site/updates/apply', ['file' => 'saan-mod-'.$this->id.'-1.0.0.zip'])->assertUnprocessable();
        $this->postJson('/api/site/updates/apply', ['file' => '../../.env', 'current_password' => 'Original-Password-2026'])->assertUnprocessable();
        $this->postJson('/api/site/updates/apply', ['file' => 'saan-mod-'.$this->id.'-1.0.0.zip', 'current_password' => 'Original-Password-2026'])->assertOk()->assertJsonPath('ok', true);
        $this->assertFileExists(base_path('modules/'.$this->id.'/module.json'));
        $editor = \App\Models\User::forceCreate(['name' => 'Ed', 'email' => 'upd-ed@school.test', 'password' => 'Original-Password-2026', 'role' => 'editor', 'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1]);
        $this->actingAs($editor)->getJson('/api/site/updates')->assertForbidden();
    }

    public function test_web_updater_of_a_standard_internal_install_defers_to_update_both(): void
    {
        config(['school.demo' => false]);
        $admin = \App\Models\User::forceCreate(['name' => 'Admin', 'email' => 'upd-admin2@school.test', 'password' => 'Original-Password-2026', 'role' => 'admin', 'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1]);
        $pkg = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0')]);
        @mkdir(storage_path('app/private/updates/incoming'), 0700, true); copy($pkg, storage_path('app/private/updates/incoming/saan-mod-'.$this->id.'-1.0.0.zip'));
        file_put_contents(storage_path('installed.json'), json_encode(['layout' => 'standard', 'edition' => 'internal', 'public_root' => '/x']));
        $this->actingAs($admin)->getJson('/api/site/updates')->assertOk()->assertJsonPath('both_required', true);
        $r = $this->actingAs($admin)->postJson('/api/site/updates/apply', ['file' => 'saan-mod-'.$this->id.'-1.0.0.zip', 'current_password' => 'Original-Password-2026']);
        $r->assertStatus(409)->assertJsonPath('ok', false);
        $this->assertStringContainsString('saan:update --both', $r->json('message'));
        $this->assertFileDoesNotExist(base_path('modules/'.$this->id.'/module.json'), 'nothing was installed on one side only');
        // the public side of a standard install, or a combined/advanced one, keeps the normal behaviour
        foreach ([['layout' => 'standard', 'edition' => 'public'], ['layout' => 'advanced', 'edition' => 'internal']] as $data) {
            file_put_contents(storage_path('installed.json'), json_encode($data));
            $this->assertFalse(\App\Domain\UpdateBoth::required());
        }
        unlink(storage_path('installed.json'));
        $this->assertFalse(\App\Domain\UpdateBoth::required());
    }

    // ---- large updates: stage first, swap by rename, finish on new code through contract 1 ----

    private function steps(array $receipt): array { return array_column($receipt['steps'], 0); }

    public function test_files_are_staged_while_the_site_is_up_and_the_finish_step_runs_through_the_contract(): void
    {
        $v1 = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0'), 'public/assets/modules/'.$this->id.'/app.js' => 'v1']);
        (new Updater)->apply($v1, false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $seen = [];
        $finisher = function (string $work) use (&$seen) {
            // what the fresh process of the new release finds: site down, new files in place, old ones in the snapshot, receipt waiting for the finish step
            $seen = ['down' => app()->isDownForMaintenance(), 'live' => file_get_contents(public_path('assets/modules/'.$this->id.'/app.js')),
                'snapshot' => file_get_contents($work.'/snapshot/public/assets/modules/'.$this->id.'/app.js'), 'steps' => $this->steps(json_decode(file_get_contents($work.'/receipt.json'), true)),
                'token' => strlen(trim(file_get_contents($work.'/finish.token'))), 'manifest' => is_file($work.'/manifest.json')];
            $r = (new Updater)->finish(basename($work), false);
            $seen['result'] = $r; $seen['still_down'] = app()->isDownForMaintenance();
        };
        $v2 = $this->package('1.0.1', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.1'), 'public/assets/modules/'.$this->id.'/app.js' => 'v2', 'public/assets/modules/'.$this->id.'/new.js' => 'n']);
        $receipt = (new Updater(null, $finisher))->apply($v2, false);
        $this->assertSame(['down' => true, 'live' => 'v2', 'snapshot' => 'v1', 'steps' => ['started', 'staged', 'maintenance_on', 'files_written', 'finish_started'], 'token' => 64, 'manifest' => true], array_diff_key($seen, ['result' => 1, 'still_down' => 1]));
        $this->assertTrue($seen['result']['ok']); $this->assertSame(Updater::CONTRACT, $seen['result']['contract']);
        $this->assertTrue($seen['still_down'], 'while the starter is alive the finisher leaves maintenance mode to it');
        $this->assertSame(['started', 'staged', 'maintenance_on', 'files_written', 'finish_started', 'health_ok', 'maintenance_off', 'completed'], $this->steps($receipt));
        $this->assertSame('custom', $receipt['finish_mode']); $this->assertSame(3, $receipt['staged']);
        $this->assertSame(['public/assets/modules/'.$this->id.'/new.js'], $receipt['created']);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertDirectoryDoesNotExist($receipt['work'].'/new', 'the staging folder is emptied by the swap and removed');
        $this->assertSame(1, DB::table('system_updates')->where('to_version', '1.0.1')->count(), 'one row per update: started, then completed');
        $this->assertDatabaseHas('system_updates', ['to_version' => '1.0.1', 'status' => 'completed']);
        // a second answer for the same update never runs the steps again
        $this->expectException(\RuntimeException::class);
        (new Updater)->finish(basename($receipt['work']), false);
    }

    public function test_new_code_that_cannot_start_or_report_puts_the_old_release_back(): void
    {
        $v1 = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0'), 'public/assets/modules/'.$this->id.'/app.js' => 'v1']);
        (new Updater)->apply($v1, false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $v2 = $this->package('1.0.1', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.1'), 'public/assets/modules/'.$this->id.'/app.js' => 'v2', 'public/assets/modules/'.$this->id.'/new.js' => 'n']);
        $work = null;
        try { (new Updater(null, function (string $w) use (&$work) { $work = $w; /* the child died: no finish.json */ }))->apply($v2, false); $this->fail('must roll back'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('คืนไฟล์รุ่นเดิมแล้ว', $e->getMessage()); $this->assertStringContainsString('ไม่รายงานผล', $e->getMessage()); }
        $this->assertSame('v1', file_get_contents(public_path('assets/modules/'.$this->id.'/app.js')));
        $this->assertFileDoesNotExist(public_path('assets/modules/'.$this->id.'/new.js'));
        $this->assertStringContainsString('"1.0.0"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame([], Updater::unfinished());
        $this->assertDatabaseHas('system_updates', ['to_version' => '1.0.1', 'status' => 'rolled_back']);
        // a late finisher (a slow request that arrives after the rollback) is refused and migrates nothing
        try { (new Updater)->finish(basename($work), false); $this->fail('late finisher must be refused'); } catch (\RuntimeException $e) { $this->assertStringContainsString('ไม่มีการอัปเดตที่รอ', $e->getMessage()); }
    }

    public function test_a_failure_before_the_swap_leaves_the_site_untouched_and_up(): void
    {
        $v1 = $this->package('1.0.0', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.0'), 'public/assets/modules/'.$this->id.'/app.js' => 'v1']);
        (new Updater)->apply($v1, false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        // the payload passes inspect() and is then replaced on disk (a full disk or a second writer): staging re-verifies every file
        $v2 = $this->package('1.0.1', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.1'), 'public/assets/modules/'.$this->id.'/app.js' => 'v2']);
        $u = new class extends Updater { public ?string $swapWith = null; public function inspect(string $zipPath): array { $m = parent::inspect($zipPath); if ($this->swapWith) copy($this->swapWith, $zipPath); return $m; } };
        $u->swapWith = $this->package('1.0.1', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.1'), 'public/assets/modules/'.$this->id.'/app.js' => 'XX']);
        try { $u->apply($v2, false); $this->fail('staging must refuse'); } catch (\RuntimeException $e) { $this->assertStringContainsString('ยังไม่ได้แก้ไฟล์ของเว็บ', $e->getMessage()); }
        $this->assertSame('v1', file_get_contents(public_path('assets/modules/'.$this->id.'/app.js')));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame([], Updater::unfinished());
        // one history row for the abandoned update, also after the history is synchronised from the receipts (it used to become two)
        $rows = fn () => DB::table('system_updates')->where('package_id', $this->id)->where('to_version', '1.0.1')->pluck('status')->all();
        $this->assertSame(['rolled_back'], $rows());
        Updater::syncHistory();
        $this->assertSame(['rolled_back'], $rows());
    }

    /** An update folder as the swap leaves it: new module.json live, the old one in the snapshot, site down, starter gone. */
    private function swappedUpdate(?array $finish): string
    {
        $path = 'modules/'.$this->id.'/module.json'; $new = $this->moduleJson('1.0.1');
        $work = storage_path('app/private/updates/20990101T000000Z-'.$this->id.'-abcdef');
        mkdir(dirname($work.'/snapshot/'.$path), 0700, true); @mkdir(dirname(base_path($path)), 0755, true);
        file_put_contents($work.'/snapshot/'.$path, $this->moduleJson('1.0.0')); file_put_contents(base_path($path), $new);
        file_put_contents($work.'/manifest.json', json_encode(['type' => 'module', 'id' => $this->id, 'version' => '1.0.1', 'files' => [['path' => $path, 'bytes' => strlen($new), 'sha256' => hash('sha256', $new)]]]));
        file_put_contents($work.'/receipt.json', json_encode(['package' => 'x.zip', 'manifest_sha256' => str_repeat('a', 64), 'type' => 'module', 'id' => $this->id, 'from' => '1.0.0', 'to' => '1.0.1', 'contract' => 1,
            'started_at' => gmdate('c'), 'steps' => [['finish_started', gmdate('c')]], 'work' => $work, 'was_down' => false, 'created' => []]));
        file_put_contents($work.'/finish.token', str_repeat('ab', 32));
        if ($finish !== null) file_put_contents($work.'/finish.json', json_encode($finish));
        \Illuminate\Support\Facades\Artisan::call('down');
        return $work;
    }

    public function test_recover_goes_forward_when_every_step_had_passed_and_back_otherwise(): void
    {
        $work = $this->swappedUpdate(['ok' => true, 'contract' => 1]);
        $receipt = (new Updater)->recover();
        $this->assertArrayHasKey('completed_at', $receipt); $this->assertArrayNotHasKey('rolled_back', $receipt);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertStringContainsString('"1.0.1"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
        $this->assertFileExists(storage_path('app/private/updates/installed-module-'.$this->id.'.json'));
        $this->assertDatabaseHas('system_updates', ['to_version' => '1.0.1', 'status' => 'completed']);
        $this->removeTree($work); unlink(storage_path('app/private/updates/installed-module-'.$this->id.'.json'));

        $this->swappedUpdate(null); // the finish step never answered
        $this->artisan('saan:update', ['--recover' => true, '--auto' => true])->assertExitCode(0); // what the scheduler runs every minute
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertStringContainsString('"1.0.0"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
        $this->assertSame([], Updater::unfinished());
        $this->artisan('saan:update', ['--recover' => true, '--auto' => true])->assertExitCode(0)->doesntExpectOutputToContain('ERR'); // nothing left: silent
    }

    public function test_finish_route_answers_in_maintenance_mode_only_to_the_update_token_and_settles_an_orphaned_update(): void
    {
        // the probe proves the answering install is the asking one: it returns the hash of a nonce the asker wrote into its own storage
        @mkdir(storage_path('app/private/updates'), 0700, true); file_put_contents(storage_path('app/private/updates/.probe'), 'nonce-123');
        $this->postJson('/_saan/update-finish', ['probe' => 1])->assertOk()->assertExactJson(['saan_update' => Updater::CONTRACT, 'install' => hash('sha256', 'nonce-123')]);
        unlink(storage_path('app/private/updates/.probe'));
        $work = $this->swappedUpdate(null);
        $this->get('/login')->assertStatus(503);
        $this->postJson('/_saan/update-finish', ['work' => basename($work), 'token' => str_repeat('00', 32)])->assertNotFound();
        $this->postJson('/_saan/update-finish', ['work' => '../'.basename($work), 'token' => str_repeat('ab', 32)])->assertNotFound(); // a bare folder name only
        $this->assertTrue(app()->isDownForMaintenance());
        $this->postJson('/_saan/update-finish', ['work' => basename($work), 'token' => str_repeat('ab', 32)])->assertOk()->assertJsonPath('ok', true);
        $this->assertFalse(app()->isDownForMaintenance(), 'the starter is gone (its lock is free): the finisher brings the site up itself');
        $this->assertArrayHasKey('completed_at', json_decode(file_get_contents($work.'/receipt.json'), true));
        $this->postJson('/_saan/update-finish', ['work' => 'incoming', 'token' => str_repeat('ab', 32)])->assertNotFound();
        $this->postJson('/_saan/update-finish', ['work' => 'no-such-update-folder', 'token' => str_repeat('ab', 32)])->assertNotFound();
    }

    /** Runs the generated storage/framework/maintenance.php the way public/index.php does (it returns when the site is open again). */
    private function visitDuringUpdate(string $work): void
    {
        $m = new \ReflectionMethod(Updater::class, 'installStub'); $m->setAccessible(true); $m->invoke(new Updater, $work);
        $stub = storage_path('framework/maintenance.php');
        $this->assertStringContainsString('Saan updater: maintenance stub', file_get_contents($stub));
        (static function (string $file): void { include $file; })($stub);
    }

    public function test_the_maintenance_stub_heals_a_dead_starter_without_the_framework(): void
    {
        // died after the swap, before any finisher answered: the first visitor's request puts the old files back and opens the site
        $work = $this->swappedUpdate(null);
        file_put_contents(base_path('modules/'.$this->id.'/created-by-update.php'), '<?php');
        $r = json_decode(file_get_contents($work.'/receipt.json'), true); $r['created'] = ['modules/'.$this->id.'/created-by-update.php', '../outside.php']; file_put_contents($work.'/receipt.json', json_encode($r));
        $this->visitDuringUpdate($work);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertFileDoesNotExist(storage_path('framework/maintenance.php'));
        $this->assertStringContainsString('"1.0.0"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
        $this->assertFileDoesNotExist(base_path('modules/'.$this->id.'/created-by-update.php'));
        $after = json_decode(file_get_contents($work.'/receipt.json'), true);
        $this->assertTrue($after['rolled_back']); $this->assertContains('rollback_started', $this->steps($after));
        $this->assertSame([], Updater::unfinished());
        $this->removeTree($work);

        // every step had passed and only the last bookkeeping was lost: the stub only opens the site, the next update start records the completion
        $work = $this->swappedUpdate(['ok' => true, 'contract' => 1]);
        $this->visitDuringUpdate($work);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertStringContainsString('"1.0.1"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
        $this->assertCount(1, Updater::unfinished());
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $next = $this->package('1.0.2', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.2')]);
        $receipt = (new Updater)->apply($next, false); // settles the leftover first, then updates
        $this->assertSame('1.0.2', $receipt['to']);
        $this->assertArrayHasKey('completed_at', json_decode(file_get_contents($work.'/receipt.json'), true));
        $this->assertSame([], Updater::unfinished());
        $this->removeTree($work);

        // while the starter is alive (its lock is held) the stub never touches files
        $work = $this->swappedUpdate(null);
        $lock = Updater::acquireLock();
        try {
            $m = new \ReflectionMethod(Updater::class, 'installStub'); $m->setAccessible(true); $m->invoke(new Updater, $work);
            $code = file_get_contents(storage_path('framework/maintenance.php'));
            // run only the healing part: with the site closed the rest of the stub would send a 503 and end the process
            $heal = substr($code, 0, strpos($code, '$data = $json($u[\'down\']);'));
            $tmp = $this->dir.'/heal-only.php'; file_put_contents($tmp, $heal); (static function (string $f): void { include $f; })($tmp);
            $this->assertTrue(app()->isDownForMaintenance());
            $this->assertStringContainsString('"1.0.1"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
        } finally { Updater::releaseLock($lock); }
    }

    public function test_an_unfinished_update_is_settled_before_another_one_starts_and_a_started_rollback_is_never_reversed(): void
    {
        $this->swappedUpdate(null); // never answered: it is rolled back, and the new update is not started in the same run
        $next = $this->package('1.0.2', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.2')]);
        try { (new Updater)->apply($next, false); $this->fail('must stop after putting the old files back'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('กรุณาสั่งอัปเดตอีกครั้ง', $e->getMessage()); }
        $this->assertStringContainsString('"1.0.0"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
        $this->assertFalse(app()->isDownForMaintenance()); $this->assertSame([], Updater::unfinished());
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $this->assertSame('1.0.2', (new Updater)->apply($next, false)['to']);
        foreach (glob(storage_path('app/private/updates/2099*'), GLOB_ONLYDIR) as $d) $this->removeTree($d);

        // a rollback that began (marker in the receipt) goes back even when an "ok" answer is lying around, and no finisher may run any more
        $work = $this->swappedUpdate(['ok' => true, 'contract' => 1]);
        $r = json_decode(file_get_contents($work.'/receipt.json'), true); $r['steps'][] = ['rollback_started', gmdate('c')]; file_put_contents($work.'/receipt.json', json_encode($r));
        try { (new Updater)->finish(basename($work), false); $this->fail('no finisher after a rollback began'); } catch (\RuntimeException $e) { $this->assertStringContainsString('ไม่มีการอัปเดตที่รอ', $e->getMessage()); }
        $receipt = (new Updater)->recover();
        $this->assertTrue($receipt['rolled_back']); $this->assertArrayNotHasKey('completed_at', $receipt);
        $this->assertStringContainsString('"1.0.0"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
    }

    public function test_core_packages_state_their_requirements_and_vendor_changes_need_a_fresh_php(): void
    {
        file_put_contents(base_path('composer.lock'), 'installed lock');
        $core = fn (array $requires, array $files) => $this->package('9.9.9', $files, ['type' => 'core', 'id' => 'core', 'requires' => $requires]);
        $u = new Updater;
        $problems = implode("\n", $u->preflight($u->inspect($core(['php' => '>=8.3', 'extensions' => ['saan_no_such_ext', 'json'], 'from' => '99.0.0'], ['VERSION' => '9.9.9']))));
        $this->assertStringContainsString('saan_no_such_ext', $problems); $this->assertStringNotContainsString(': json', $problems);
        $this->assertStringContainsString('ให้อัปเดตเป็นรุ่น 99.0.0 ก่อน', $problems);
        // no PHP CLI and no reachable loopback: a package that replaces libraries is refused before anything is written
        config(['saan.update_finish' => 'http', 'app.url' => 'http://127.0.0.1:9']);
        $pkg = $core(['php' => '>=8.3'], ['VERSION' => '9.9.9', 'composer.lock' => 'new lock', 'vendor/saan-test/a.php' => '<?php']);
        $this->assertStringContainsString('PHP ที่เริ่มใหม่', implode("\n", $u->preflight($u->inspect($pkg))));
        try { $u->apply($pkg, false); $this->fail('must be refused'); } catch (\RuntimeException $e) { $this->assertStringContainsString('ตรวจความพร้อมไม่ผ่าน', $e->getMessage()); }
        $this->assertFileDoesNotExist(base_path('vendor/saan-test/a.php')); $this->assertSame('installed lock', file_get_contents(base_path('composer.lock')));
        $this->assertFalse(app()->isDownForMaintenance());
        // the same lock file (no library change) does not need the isolated finish step
        $same = $core(['php' => '>=8.3'], ['VERSION' => '9.9.9', 'composer.lock' => 'installed lock']);
        $this->assertStringNotContainsString('PHP ที่เริ่มใหม่', implode("\n", $u->preflight($u->inspect($same))));
    }

    public function test_packages_of_a_release_are_ordered_core_first_then_modules_after_what_they_require(): void
    {
        $m = fn (string $id, array $needs = [], string $type = 'module') => ['type' => $type, 'id' => $id, 'version' => '1.0.0', 'requires' => ['modules' => array_fill_keys($needs, '>=0.1.0')]];
        $order = Updater::installOrder([
            'student-portal.zip' => $m('student-portal', ['guardian-portal', 'api-gateway']), 'api-gateway.zip' => $m('api-gateway'), 'core.zip' => $m('core', [], 'core'),
            'guardian-portal.zip' => $m('guardian-portal', ['registry']), 'registry.zip' => $m('registry'), 'web-ita.zip' => $m('web-ita', ['web-cms']), // web-cms is not in the set: already installed, left to preflight
        ]);
        $this->assertSame('core.zip', $order[0]); $this->assertCount(6, $order);
        $pos = array_flip($order);
        $this->assertLessThan($pos['guardian-portal.zip'], $pos['registry.zip']);
        $this->assertLessThan($pos['student-portal.zip'], $pos['guardian-portal.zip']);
        $this->assertLessThan($pos['student-portal.zip'], $pos['api-gateway.zip']);
        $this->expectExceptionMessage('เป็นวง');
        Updater::installOrder(['a.zip' => $m('a', ['b']), 'b.zip' => $m('b', ['a'])]);
    }

    public function test_old_snapshots_are_dropped_but_receipts_and_unfinished_updates_are_kept(): void
    {
        $make = function (string $stamp, array $receipt) { $d = storage_path('app/private/updates/'.$stamp.'-x-000000'); mkdir($d.'/snapshot/a', 0700, true); file_put_contents($d.'/snapshot/a/f', 'x'); file_put_contents($d.'/receipt.json', json_encode($receipt)); return $d; };
        $old = $make('20260101T000000Z', ['completed_at' => 'x']); $stuck = $make('20260102T000000Z', ['started_at' => 'x']);
        $b = $make('20260103T000000Z', ['rolled_back' => true]); $c = $make('20260104T000000Z', ['completed_at' => 'x']);
        $this->assertSame(1, Updater::pruneWork(2));
        $this->assertDirectoryDoesNotExist($old.'/snapshot'); $this->assertFileExists($old.'/receipt.json');
        $this->assertDirectoryExists($stuck.'/snapshot', 'an unfinished update keeps the only way back');
        $this->assertDirectoryExists($b.'/snapshot'); $this->assertDirectoryExists($c.'/snapshot');
    }
}
