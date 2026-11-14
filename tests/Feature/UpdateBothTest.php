<?php

namespace Tests\Feature;

use App\Domain\UpdateBoth;
use App\Domain\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S7: `saan:update --both` on the internal edition of a standard install. The internal side is the real Updater working in a temp tree
 * (same set-up as UpdaterTest); the public side is a mocked artisan runner that records its calls and answers with the exit code the test wants.
 */
class UpdateBothTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;
    private string $dir;
    private string $realBase;
    private string $id = 'zz-update-both';
    private string $publicRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $kp = sodium_crypto_sign_keypair(); $this->secret = sodium_crypto_sign_secretkey($kp);
        config(['saan.release_public_key' => base64_encode(sodium_crypto_sign_publickey($kp))]);
        $this->dir = sys_get_temp_dir().'/saan-both-'.bin2hex(random_bytes(4)); mkdir($this->dir);
        foreach (['/storage/app/private', '/storage/framework/cache', '/storage/framework/views', '/storage/logs'] as $sub) mkdir($this->dir.$sub, 0700, true);
        $this->realBase = base_path();
        foreach (['/base/modules', '/base/public/assets/modules'] as $sub) mkdir($this->dir.$sub, 0755, true);
        $this->publicRoot = $this->dir.'/public-app'; mkdir($this->publicRoot); file_put_contents($this->publicRoot.'/artisan', '<?php');
        $this->app->setBasePath($this->dir.'/base');
        $this->app->useStoragePath($this->dir.'/storage');
        $this->app->usePublicPath($this->dir.'/base/public');
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $this->installed(['layout' => 'standard', 'edition' => 'internal', 'public_root' => $this->publicRoot]);
    }

    protected function tearDown(): void
    {
        try { app(\App\Support\Modules\ModuleRegistry::class)->flush(); }
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

    private function installed(array $data): void
    {
        file_put_contents(storage_path('installed.json'), json_encode($data));
    }

    private function package(string $version, array $files): string
    {
        $manifest = ['type' => 'module', 'id' => $this->id, 'version' => $version, 'requires' => ['core' => '>=0.6.0', 'modules' => []], 'created_at' => gmdate('c'), 'files' => []];
        foreach ($files as $path => $content) $manifest['files'][] = ['path' => $path, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $zipPath = $this->dir.'/pkg-'.$version.'-'.bin2hex(random_bytes(3)).'.zip'; $zip = new \ZipArchive; $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', $json); $zip->addFromString('manifest.sig', base64_encode(sodium_crypto_sign_detached($json, $this->secret)));
        foreach ($files as $path => $content) $zip->addFromString('payload/'.$path, $content);
        $zip->close(); return $zipPath;
    }

    private function moduleJson(string $version): string
    {
        return json_encode(['id' => $this->id, 'name' => 'Update both test', 'version' => $version, 'readiness' => 'beta', 'requires' => ['core' => '>=0.6.0', 'modules' => []], 'package' => 'separate'], JSON_PRETTY_PRINT);
    }

    private function release(string $v, string $js): string
    {
        return $this->package($v, ['modules/'.$this->id.'/module.json' => $this->moduleJson($v), 'public/assets/modules/'.$this->id.'/app.js' => $js]);
    }

    /** @return array{0:callable,1:\ArrayObject} runner and the calls it saw; $answers maps 'check' | 'apply' | 'recover' to [exit code, output] */
    private function runner(array $answers = [], ?callable $onApply = null): array
    {
        $calls = new \ArrayObject();
        $fn = function (string $root, array $args) use ($calls, $answers, $onApply) {
            $kind = in_array('--check', $args, true) ? 'check' : (in_array('--recover', $args, true) ? 'recover' : 'apply');
            $calls[] = [$root, $kind, $args];
            if ($kind === 'apply' && $onApply) $onApply();
            return $answers[$kind] ?? [0, ''];
        };
        return [$fn, $calls];
    }

    /** An update folder of the public app (what its own Updater leaves behind); returns the real path. */
    private function publicUpdate(string $name, array $receipt): string
    {
        $dir = $this->publicRoot.'/storage/app/private/updates/'.$name; mkdir($dir.'/snapshot', 0700, true);
        file_put_contents($dir.'/receipt.json', json_encode($receipt));
        return realpath($dir);
    }

    private function app(): string { return file_get_contents(public_path('assets/modules/'.$this->id.'/app.js')); }

    public function test_both_sides_are_checked_first_then_internal_then_public(): void
    {
        (new Updater)->apply($this->release('1.0.0', "console.log('v1')"), false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        [$run, $calls] = $this->runner();
        $zip = $this->release('1.0.1', "console.log('v2')");
        $r = (new UpdateBoth($run))->run($zip, false);
        $this->assertSame('1.0.1', $r['internal']['to']);
        $this->assertStringContainsString('v2', $this->app());
        $this->assertSame([realpath($this->publicRoot), 'check', ['saan:update', '--check', $zip]], $calls[0]);
        $this->assertSame([realpath($this->publicRoot), 'apply', ['saan:update', $zip, '--skip-db-backup']], $calls[1]);
        $this->assertCount(2, $calls);
        $this->assertDatabaseHas('system_updates', ['package_id' => $this->id, 'to_version' => '1.0.1', 'status' => 'completed']);
    }

    public function test_public_failure_takes_the_internal_update_back_too(): void
    {
        (new Updater)->apply($this->release('1.0.0', "console.log('v1')"), false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        [$run, $calls] = $this->runner(['apply' => [1, "[ERR] migrate failed\n"]], fn () => $this->publicUpdate('20261106T000000Z-x-aaaaaa', ['started_at' => '2026-11-06T00:00:00+00:00']));
        try { (new UpdateBoth($run))->run($this->release('1.0.1', "console.log('v2')"), false); $this->fail('must fail'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('คืนไฟล์ทั้งสองฝั่ง', $e->getMessage()); $this->assertStringContainsString('migrate failed', $e->getMessage()); }
        $this->assertStringContainsString('v1', $this->app(), 'internal files are back at the old release');
        $this->assertStringContainsString('"1.0.0"', file_get_contents(base_path('modules/'.$this->id.'/module.json')));
        $this->assertDatabaseHas('system_updates', ['package_id' => $this->id, 'to_version' => '1.0.1', 'status' => 'rolled_back']);
        $this->assertSame(['check', 'apply', 'recover'], array_map(fn ($c) => $c[1], $calls->getArrayCopy()), 'the public side is asked to recover an interrupted update too');
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_a_public_runner_that_throws_is_treated_like_a_failure(): void
    {
        (new Updater)->apply($this->release('1.0.0', "console.log('v1')"), false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $n = 0;
        $run = function (string $root, array $args) use (&$n) { if (in_array('--check', $args, true)) return [0, '']; if (in_array('--recover', $args, true)) return [1, 'none']; throw new \RuntimeException('process killed'); };
        try { (new UpdateBoth($run))->run($this->release('1.0.1', "console.log('v2')"), false); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('process killed', $e->getMessage()); }
        $this->assertStringContainsString('v1', $this->app());
    }

    public function test_internal_failure_never_touches_the_public_side(): void
    {
        (new Updater)->apply($this->release('1.0.0', "console.log('v1')"), false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $broken = "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void { throw new \\RuntimeException('boom'); } };\n";
        $zip = $this->package('1.0.1', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.1'), 'public/assets/modules/'.$this->id.'/app.js' => "console.log('v2')", 'modules/'.$this->id.'/database/migrations/2099_01_01_000000_broken.php' => $broken]);
        app('migrator')->path(base_path('modules/'.$this->id.'/database/migrations'));
        [$run, $calls] = $this->runner();
        try { (new UpdateBoth($run))->run($zip, false); $this->fail(); } catch (\RuntimeException $e) { $this->assertStringContainsString('คืนไฟล์รุ่นเดิมแล้ว', $e->getMessage()); }
        $this->assertStringContainsString('v1', $this->app());
        $this->assertSame(['check'], array_map(fn ($c) => $c[1], $calls->getArrayCopy()), 'no apply and no recover on the public side');
    }

    public function test_a_failed_public_check_changes_nothing_on_either_side(): void
    {
        (new Updater)->apply($this->release('1.0.0', "console.log('v1')"), false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        [$run, $calls] = $this->runner(['check' => [1, '[ERR] disk full']]);
        try { (new UpdateBoth($run))->run($this->release('1.0.1', "console.log('v2')"), false); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('disk full', $e->getMessage()); $this->assertStringContainsString('ยังไม่แก้ไฟล์', $e->getMessage()); }
        $this->assertStringContainsString('v1', $this->app());
        $this->assertCount(1, $calls);
        $this->assertDatabaseMissing('system_updates', ['package_id' => $this->id, 'to_version' => '1.0.1']);
    }

    public function test_the_internal_update_lock_is_held_from_the_first_write_to_the_last(): void
    {
        (new Updater)->apply($this->release('1.0.0', "console.log('v1')"), false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $busy = [];
        [$run] = $this->runner([], function () use (&$busy) {
            try { $h = Updater::acquireLock(); Updater::releaseLock($h); $busy[] = false; } catch (\App\Domain\UpdateBusyException) { $busy[] = true; }
        });
        (new UpdateBoth($run))->run($this->release('1.0.1', "console.log('v2')"), false);
        $this->assertSame([true], $busy, 'while the public side is updating, another update or the web button cannot start on the internal side');
        $h = Updater::acquireLock(); Updater::releaseLock($h); // and the lock is released afterwards
        $this->addToAssertionCount(1);
    }

    public function test_only_update_folders_created_by_this_run_are_taken_back_even_when_the_public_update_had_completed(): void
    {
        (new Updater)->apply($this->release('1.0.0', "console.log('v1')"), false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $old = $this->publicUpdate('20260101T000000Z-old-aaaaaa', ['started_at' => '2026-01-01T00:00:00+00:00']); // unfinished leftover of an earlier run
        $done = $rolled = null;
        [$run, $calls] = $this->runner(['apply' => [1, 'timeout after completed']], function () use (&$done, &$rolled) {
            $done = $this->publicUpdate('20261106T000000Z-new-bbbbbb', ['started_at' => '2026-11-06T00:00:00+00:00', 'completed_at' => '2026-11-06T00:01:00+00:00']);
            $rolled = $this->publicUpdate('20261106T000100Z-new-cccccc', ['rolled_back' => true]);
        });
        try { (new UpdateBoth($run))->run($this->release('1.0.1', "console.log('v2')"), false); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('คืนไฟล์ทั้งสองฝั่ง', $e->getMessage()); }
        $recover = array_values(array_filter($calls->getArrayCopy(), fn ($c) => $c[1] === 'recover'));
        $this->assertCount(1, $recover, 'one folder to take back: not the old leftover, not the one that already rolled itself back');
        $this->assertSame(['saan:update', '--recover', '--completed', '--recover-dir='.$done], $recover[0][2]);
        $this->assertStringContainsString('v1', $this->app(), 'internal side is back too');
        $this->assertNotContains('--recover-dir='.$old, $recover[0][2]); $this->assertNotContains('--recover-dir='.$rolled, $recover[0][2]);
    }

    public function test_a_failed_public_recover_is_reported_after_the_internal_side_is_back(): void
    {
        (new Updater)->apply($this->release('1.0.0', "console.log('v1')"), false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        [$run] = $this->runner(['apply' => [1, 'boom'], 'recover' => [1, 'disk full']], fn () => $this->publicUpdate('20261106T000000Z-new-dddddd', ['started_at' => 'x']));
        try { (new UpdateBoth($run))->run($this->release('1.0.1', "console.log('v2')"), false); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('คืนหน้าบ้านไม่สำเร็จ', $e->getMessage()); $this->assertStringContainsString('disk full', $e->getMessage()); }
        $this->assertStringContainsString('v1', $this->app());
    }

    public function test_taking_back_a_completed_update_also_restores_the_installed_file_list(): void
    {
        (new Updater)->apply($this->release('1.0.0', "console.log('v1')"), false); app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $list = storage_path('app/private/updates/installed-module-'.$this->id.'.json');
        $v1 = json_decode(file_get_contents($list), true); $this->assertSame('1.0.0', $v1['version']);
        $zip = $this->package('1.0.1', ['modules/'.$this->id.'/module.json' => $this->moduleJson('1.0.1'), 'public/assets/modules/'.$this->id.'/app.js' => "console.log('v2')", 'public/assets/modules/'.$this->id.'/extra.js' => 'x']);
        [$run] = $this->runner(['apply' => [1, 'public failed']]);
        try { (new UpdateBoth($run))->run($zip, false); $this->fail(); } catch (\RuntimeException) { $this->addToAssertionCount(1); }
        $after = json_decode(file_get_contents($list), true);
        $this->assertSame($v1, $after, 'the list of installed files is the one of the restored release again');
        $this->assertFileDoesNotExist(public_path('assets/modules/'.$this->id.'/extra.js'));
    }

    public function test_both_is_refused_unless_this_is_the_internal_side_of_a_standard_install(): void
    {
        foreach ([['layout' => 'advanced', 'edition' => 'internal', 'public_root' => $this->publicRoot], ['layout' => 'standard', 'edition' => 'public'], ['layout' => 'standard', 'edition' => 'internal', 'public_root' => $this->dir.'/missing'], ['layout' => 'standard', 'edition' => 'internal', 'public_root' => $this->dir.'/base']] as $data) {
            $this->installed($data);
            try { UpdateBoth::publicRoot(); $this->fail(json_encode($data)); } catch (\RuntimeException $e) { $this->addToAssertionCount(1); }
        }
        unlink(storage_path('installed.json'));
        $this->expectException(\RuntimeException::class); UpdateBoth::publicRoot();
    }

    // ---------------------------------------------------------------- split review fixes

    /** Leaks an internal variable the way Laravel's dotenv does: into getenv(), $_ENV and $_SERVER. @return callable restore */
    private function leak(array $vars): callable
    {
        $old = [];
        foreach ($vars as $k => $v) { $old[$k] = [getenv($k), $_ENV[$k] ?? null, $_SERVER[$k] ?? null]; putenv($k.'='.$v); $_ENV[$k] = $_SERVER[$k] = $v; }
        return function () use ($old) {
            foreach ($old as $k => [$g, $e, $s]) {
                putenv($g === false ? $k : $k.'='.$g);
                if ($e === null) unset($_ENV[$k]); else $_ENV[$k] = $e;
                if ($s === null) unset($_SERVER[$k]); else $_SERVER[$k] = $s;
            }
        };
    }

    public function test_the_public_child_starts_with_none_of_the_internal_environment(): void
    {
        file_put_contents(base_path('.env'), "APP_KEY=base64:INTERNALKEY\nDB_DATABASE=internal_db\nSAAN_EDITION=internal\nONLY_IN_THE_ENV_FILE=1\n");
        $restore = $this->leak(['SAAN_EDITION' => 'internal', 'DB_DATABASE' => 'internal_db', 'APP_KEY' => 'base64:INTERNALKEY']);
        try {
            file_put_contents($this->publicRoot.'/artisan', '<?php echo json_encode(["ed" => getenv("SAAN_EDITION"), "db" => getenv("DB_DATABASE"), "key" => getenv("APP_KEY"), "file" => getenv("ONLY_IN_THE_ENV_FILE"), "expect" => getenv("SAAN_UPDATE_EXPECT_EDITION"), "server" => $_SERVER["DB_DATABASE"] ?? null, "path" => (getenv("PATH") !== false || getenv("Path") !== false), "argv" => array_slice($argv, 1)]);');
            $m = new \ReflectionMethod(UpdateBoth::class, 'process');
            [$code, $out] = $m->invoke(new UpdateBoth(null, null, PHP_BINARY), realpath($this->publicRoot), ['saan:update', '--check', 'x.zip']);
            $r = json_decode($out, true);
            $this->assertIsArray($r, $out);
            $this->assertSame(0, $code);
            foreach (['ed', 'db', 'key', 'file', 'server'] as $k) $this->assertTrue($r[$k] === false || $r[$k] === null, "the child still sees the internal $k: ".json_encode($r[$k]));
            $this->assertSame('public', $r['expect'], 'the child is told which edition it must run as');
            $this->assertTrue($r['path'], 'it can still find programs');
            $this->assertSame(['saan:update', '--check', 'x.zip', '--no-interaction'], $r['argv']);
        } finally { $restore(); }
    }

    public function test_child_environment_removes_every_name_except_the_few_it_needs(): void
    {
        $env = UpdateBoth::childEnv(['PATH', 'Path', 'HOME', 'DB_DATABASE', 'APP_KEY', 'SystemRoot', 'TEMP'], ['APP_KEY', 'MAIL_PASSWORD', 'SAAN_EDITION']);
        foreach (['DB_DATABASE', 'APP_KEY', 'MAIL_PASSWORD', 'SAAN_EDITION'] as $k) $this->assertFalse($env[$k], $k);
        foreach (['PATH', 'Path', 'HOME', 'SystemRoot', 'TEMP'] as $k) $this->assertArrayNotHasKey($k, $env, $k.' is inherited as it is');
        $this->assertSame('public', $env[UpdateBoth::EXPECT_ENV]);
    }

    public function test_both_is_refused_for_root_and_for_a_public_root_of_another_os_user(): void
    {
        $clean = ['artisan' => [1000, 0100644], 'bootstrap' => [1000, 040755], 'vendor' => [1000, 040755]];
        UpdateBoth::assertOwnerSafe($this->publicRoot, 1000, 1000, $clean);
        $this->addToAssertionCount(1);
        foreach ([[0, 0, 'root'], [1000, 1001, 'ผู้ใช้ระบบคนละคน'], [1000, 0, 'ผู้ใช้ระบบคนละคน']] as [$euid, $owner, $word]) {
            try { UpdateBoth::assertOwnerSafe($this->publicRoot, $euid, $owner, $clean); $this->fail("$euid $owner"); }
            catch (\RuntimeException $e) { $this->assertStringContainsString($word, $e->getMessage()); }
        }
        // code the public pool can write through the group, or that belongs to another user, is refused even when artisan is ours
        foreach ([['bootstrap' => [1000, 040775]], ['vendor' => [1000, 040757]], ['vendor' => [1001, 040755]]] as $bad) {
            try { UpdateBoth::assertOwnerSafe($this->publicRoot, 1000, 1000, $bad + $clean); $this->fail(json_encode($bad)); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('chmod go-w', $e->getMessage()); }
        }
    }

    public function test_the_child_never_loads_cache_files_from_the_public_bootstrap_cache(): void
    {
        $paths = UpdateBoth::childCachePaths('/tmp/saan-both-x');
        foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'VIEW_COMPILED_PATH'] as $k) {
            $this->assertArrayHasKey($k, $paths);
            $this->assertStringStartsWith('/tmp/saan-both-x/', $paths[$k]);
        }
        // Laravel resolves these names as absolute paths only when they start with a slash (Application::normalizeCachePath)
        $_ENV['APP_CONFIG_CACHE'] = $paths['APP_CONFIG_CACHE'];
        $_ENV['APP_PACKAGES_CACHE'] = $paths['APP_PACKAGES_CACHE'];
        try {
            $this->assertSame('/tmp/saan-both-x/config.php', app()->getCachedConfigPath());
            $this->assertSame('/tmp/saan-both-x/packages.php', app()->getCachedPackagesPath());
        } finally { unset($_ENV['APP_CONFIG_CACHE'], $_ENV['APP_PACKAGES_CACHE']); }
    }

    public function test_the_child_refuses_to_run_as_another_edition_or_on_another_database(): void
    {
        config(['saan.edition' => 'internal']);
        try { UpdateBoth::assertChildEdition('public'); $this->fail('wrong edition'); } catch (\RuntimeException $e) { $this->assertStringContainsString('edition internal', $e->getMessage()); }
        // the child's settings name the database public_db (settings only: the open suite connection is not touched and nothing connects to that name)
        $name = 'database.connections.'.$this->baseConnection.'.database';
        $real = config($name);
        config(['saan.edition' => 'public', $name => 'public_db']);
        try {
            UpdateBoth::assertChildEdition('public'); // no .env in this root: nothing to compare with
            file_put_contents(base_path('.env'), "DB_DATABASE=other_db\n");
            try { UpdateBoth::assertChildEdition('public'); $this->fail('wrong database'); } catch (\RuntimeException $e) { $this->assertStringContainsString('other_db', $e->getMessage()); $this->assertStringContainsString('public_db', $e->getMessage()); }
            file_put_contents(base_path('.env'), "DB_DATABASE=public_db\n");
            UpdateBoth::assertChildEdition('public');
        } finally { config([$name => $real]); }
        // saan:update does this before anything else when the parent said so
        config(['saan.edition' => 'internal']);
        $restore = $this->leak([UpdateBoth::EXPECT_ENV => 'public']);
        try { $this->artisan('saan:update', ['--recover' => true])->expectsOutputToContain('ไม่แก้อะไร')->assertExitCode(1); } finally { $restore(); }
    }
}