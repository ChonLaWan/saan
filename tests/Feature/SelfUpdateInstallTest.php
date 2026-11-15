<?php

namespace Tests\Feature;

use App\Domain\SelfUpdate;
use App\Domain\Updater;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\UpdateFeed;
use App\Support\Saan\Settings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Automatic patch update end to end on a throw-away module: the signed package is downloaded from a faked feed folder and installed by the
 * REAL Updater (the same way `saan:update --all` applies it, only in this process instead of a PHP CLI child). Like UpdaterTest, the whole app
 * (base, storage, public) is re-pointed at a private temp tree, so the real modules/ and public/ folders are never written.
 */
class SelfUpdateInstallTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'https://feed.example.test/releases/';
    private string $secret;
    private string $dir;
    private string $realBase;
    private string $id = 'zz-update-test';

    protected function setUp(): void
    {
        parent::setUp();
        $kp = sodium_crypto_sign_keypair(); $this->secret = sodium_crypto_sign_secretkey($kp);
        config(['saan.release_public_key' => base64_encode(sodium_crypto_sign_publickey($kp)), 'saan.update_feed' => self::FEED.'feed.json']);
        $this->dir = sys_get_temp_dir().'/saan-selfinstall-'.bin2hex(random_bytes(4)); mkdir($this->dir);
        foreach (['/storage/app/private', '/storage/framework/cache', '/storage/framework/views', '/storage/logs', '/base/modules', '/base/public/assets/modules'] as $sub) mkdir($this->dir.$sub, 0700, true);
        $this->realBase = base_path();
        $this->app->setBasePath($this->dir.'/base');
        $this->app->useStoragePath($this->dir.'/storage');
        $this->app->usePublicPath($this->dir.'/base/public');
        app(ModuleRegistry::class)->flush();
    }

    protected function tearDown(): void
    {
        try { $this->rm(base_path('modules/'.$this->id)); $this->rm(public_path('assets/modules/'.$this->id)); }
        finally {
            $this->app->setBasePath($this->realBase);
            app(ModuleRegistry::class)->flush();
            $this->rm($this->dir);
            parent::tearDown();
        }
    }

    private function rm(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            for ($i = 0; $i < 20; $i++) { try { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); break; } catch (\ErrorException) { usleep(50000); clearstatcache(); } }
        }
        @rmdir($d);
    }

    private function moduleJson(string $version, array $requires = ['core' => '>=0.6.0', 'modules' => []]): string
    {
        return json_encode(['id' => $this->id, 'name' => 'Update test', 'version' => $version, 'readiness' => 'beta', 'requires' => $requires, 'package' => 'separate']);
    }

    /** @return array{name:string,body:string,bytes:int,sha:string,path:string} */
    private function package(string $version, array $requires = ['core' => '>=0.6.0', 'modules' => []]): array
    {
        $files = ['modules/'.$this->id.'/module.json' => $this->moduleJson($version, $requires), 'public/assets/modules/'.$this->id.'/app.js' => "console.log('v".$version."')"];
        $m = ['type' => 'module', 'id' => $this->id, 'version' => $version, 'requires' => $requires, 'created_at' => gmdate('c'), 'files' => []];
        foreach ($files as $p => $c) $m['files'][] = ['path' => $p, 'bytes' => strlen($c), 'sha256' => hash('sha256', $c)];
        $json = json_encode($m, JSON_UNESCAPED_SLASHES);
        $path = $this->dir.'/pkg-'.$version.'-'.bin2hex(random_bytes(3)).'.zip'; $z = new \ZipArchive; $z->open($path, \ZipArchive::CREATE);
        $z->addFromString('manifest.json', $json); $z->addFromString('manifest.sig', base64_encode(sodium_crypto_sign_detached($json, $this->secret)));
        foreach ($files as $p => $c) $z->addFromString('payload/'.$p, $c);
        $z->close(); $body = (string) file_get_contents($path);
        return ['name' => 'saan-mod-'.$this->id.'-'.$version.'.zip', 'body' => $body, 'bytes' => strlen($body), 'sha' => hash('sha256', $body), 'path' => $path];
    }

    private function serve(array $pkg, string $base): void
    {
        $version = preg_replace('/^.*-(\d+\.\d+\.\d+)\.zip$/', '$1', $pkg['name']);
        $feed = ['version' => 2, 'generated_at' => gmdate('c', time() + random_int(1, 1000)), 'base' => $base.'/', 'modules' => [$this->id => ['version' => $version, 'summary' => 'x', 'zip' => $pkg['name'], 'bytes' => $pkg['bytes'], 'sha256' => $pkg['sha']]]];
        $json = json_encode($feed); $sig = base64_encode(sodium_crypto_sign_detached($json, $this->secret));
        Http::swap(new HttpFactory); Http::preventStrayRequests();
        Http::fake([self::FEED.'feed.json' => fn () => Http::response($json), self::FEED.'feed.sig' => fn () => Http::response($sig), self::FEED.$base.'/'.$pkg['name'] => fn () => Http::response($pkg['body'], 200, ['Content-Type' => 'application/zip'])]);
        Settings::put('updates.feed_enabled', true);
        app(ModuleRegistry::class)->flush(); // the module installed a moment ago
        (new UpdateFeed)->check();
    }

    /** What `saan:update --all=<folder>` does, in this process: every package of the folder, in dependency order, by the real Updater. */
    private function runner(): \Closure
    {
        return function (array $args, int $timeout): array {
            $dir = substr($args[1], strlen('--all='));
            $updater = new Updater; $man = [];
            foreach (glob($dir.'/saan-*.zip') as $f) $man[$f] = $updater->inspect($f);
            try { foreach (Updater::installOrder($man) as $f) (new Updater)->apply($f, false); }
            catch (\Throwable $e) { return [1, '[ERR] '.$e->getMessage()]; }
            return [0, '[OK] ติดตั้งครบ'];
        };
    }

    private function installedVersion(): string
    {
        app(ModuleRegistry::class)->flush();
        return (string) app(ModuleRegistry::class)->get($this->id)['version'];
    }

    public function test_a_patch_release_of_a_module_is_downloaded_and_installed_by_the_scheduler_and_written_to_the_history(): void
    {
        (new Updater)->apply($this->package('1.0.0')['path'], false);
        $this->assertSame('1.0.0', $this->installedVersion());
        SelfUpdate::setMode('auto_patch', '02:00', '05:00');
        $v2 = $this->package('1.0.1');
        $this->serve($v2, '0.7.1');
        $r = (new SelfUpdate($this->runner()))->runAuto(Carbon::parse('2026-11-15 03:10:00', 'Asia/Bangkok'));
        $this->assertSame('installed', $r['action'], $r['message']);
        $this->assertSame('1.0.1', $this->installedVersion());
        $this->assertStringContainsString('v1.0.1', (string) file_get_contents(public_path('assets/modules/'.$this->id.'/app.js')));
        $this->assertDatabaseHas('system_updates', ['package_id' => $this->id, 'from_version' => '1.0.0', 'to_version' => '1.0.1', 'status' => 'completed']);
        $notice = Settings::get('updates.auto')['notice'];
        $this->assertSame('ok', $notice['kind']);
        $this->assertStringContainsString('อัปเดตอัตโนมัติเป็นรุ่น', $notice['text']);
        $this->assertSame([], glob(SelfUpdate::incoming().'/*'), 'no package and no run folder is left in incoming/');
        // the notice about the new release is gone: it is installed
        $this->assertFalse((new SelfUpdate)->plan()['any']);
    }

    public function test_a_package_the_updater_refuses_is_one_failed_attempt_and_goes_back_to_incoming(): void
    {
        (new Updater)->apply($this->package('1.0.0')['path'], false);
        SelfUpdate::setMode('auto_patch', '02:00', '05:00');
        $bad = $this->package('1.0.1', ['core' => '>=99.0.0', 'modules' => []]); // signed, genuine, but this core is too old for it: the preflight refuses
        $this->serve($bad, '0.7.1');
        $su = new SelfUpdate($this->runner());
        $r = $su->runAuto(Carbon::parse('2026-11-15 03:10:00', 'Asia/Bangkok'));
        $this->assertSame('failed', $r['action'], $r['message']);
        $this->assertSame('1.0.0', $this->installedVersion(), 'nothing was changed');
        $this->assertSame('failed', Settings::get('updates.auto')['attempts'][$this->id.'@1.0.1']['state']);
        $this->assertStringContainsString('ไม่สำเร็จ', Settings::get('updates.auto')['notice']['text']);
        $this->assertFileExists(SelfUpdate::incoming().'/'.$bad['name']);
        $this->assertSame('none', $su->runAuto(Carbon::parse('2026-11-15 03:30:00', 'Asia/Bangkok'))['action'], 'the release is not tried again by itself');
    }
}
