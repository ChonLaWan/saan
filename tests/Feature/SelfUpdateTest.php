<?php

namespace Tests\Feature;

use App\Domain\SelfUpdate;
use App\Domain\SelfUpdateException;
use App\Domain\Updater;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\UpdateFeed;
use App\Support\Saan\Settings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Self-update: signed feed version 2, plan, resumable download, automatic patch installs, endpoints, command.
 * Real signed packages are built here and served through Http::fake (no network). The storage folder is re-pointed at a private temp tree,
 * so incoming/, update folders and locks never touch the real storage. Installing itself is Updater's job (UpdaterTest, SelfUpdateInstallTest):
 * here the installer is a recording fake.
 */
class SelfUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Self-Update-Admin-2026';
    private const FEED = 'https://feed.example.test/releases/';

    private string $secret;
    private string $dir;
    private string $realStorage;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        DB::table('users')->where('role', 'admin')->update(['password' => Hash::make(self::PASSWORD), 'is_demo' => false]);
        $kp = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($kp);
        config(['saan.release_public_key' => base64_encode(sodium_crypto_sign_publickey($kp)), 'saan.update_feed' => self::FEED.'feed.json', 'saan.version' => '0.7.0']);
        $this->dir = sys_get_temp_dir().'/saan-selfupdate-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage/app/private', 0700, true);
        $this->realStorage = storage_path();
        $this->app->useStoragePath($this->dir.'/storage');
        $this->fake([]);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->realStorage);
        $this->rm($this->dir);
        parent::tearDown();
    }

    private function rm(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($d);
    }

    private function admin(): void { $this->actingAs(User::where('role', 'admin')->first()); }

    /** A fresh Http fake: a later fake() for the same URL would lose against an earlier one. */
    private function fake(array $map): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake($map);
    }

    /** @return array{name:string,body:string,bytes:int,sha:string} a signed package (core or module) */
    private function pkg(string $type, string $id, string $version, ?array $files = null, ?string $secret = null, array $manifest = []): array
    {
        $files ??= $type === 'core' ? ['VERSION' => $version."\n"] : ['modules/'.$id.'/module.json' => json_encode(['id' => $id, 'version' => $version])];
        $m = array_replace(['type' => $type, 'id' => $id, 'version' => $version, 'requires' => ['php' => '>=8.0', 'extensions' => [], 'modules' => []], 'created_at' => gmdate('c'), 'files' => []], $manifest);
        foreach ($files as $p => $c) $m['files'][] = ['path' => $p, 'bytes' => strlen($c), 'sha256' => hash('sha256', $c)];
        $json = json_encode($m, JSON_UNESCAPED_SLASHES);
        $tmp = $this->dir.'/build-'.bin2hex(random_bytes(3)).'.zip'; $z = new \ZipArchive; $z->open($tmp, \ZipArchive::CREATE);
        $z->addFromString('manifest.json', $json); $z->addFromString('manifest.sig', base64_encode(sodium_crypto_sign_detached($json, $secret ?? $this->secret)));
        foreach ($files as $p => $c) $z->addFromString('payload/'.$p, $c);
        $z->close(); $body = (string) file_get_contents($tmp); unlink($tmp);
        return ['name' => 'saan-'.($type === 'core' ? 'core-' : 'mod-'.$id.'-').$version.'.zip', 'body' => $body, 'bytes' => strlen($body), 'sha' => hash('sha256', $body)];
    }

    private function entry(array $pkg, array $extra = []): array
    {
        return array_replace(['version' => preg_replace('/^.*?(\d+\.\d+\.\d+)\.zip$/', '$1', $pkg['name']), 'summary' => 'ทดสอบ', 'notes_url' => 'https://example.test/notes', 'zip' => $pkg['name'], 'bytes' => $pkg['bytes'], 'sha256' => $pkg['sha']], $extra);
    }

    private function feed(?array $core, array $modules = [], array $top = []): array
    {
        $f = array_replace(['version' => 2, 'generated_at' => gmdate('c'), 'base' => '0.7.1/', 'modules' => $modules], $top);
        if ($core !== null) $f['core'] = $core;
        return $f;
    }

    private function sign(array|string $feed, ?string $secret = null): array
    {
        $json = is_string($feed) ? $feed : json_encode($feed);
        return [$json, base64_encode(sodium_crypto_sign_detached($json, $secret ?? $this->secret))];
    }

    /** Serve + switch the feed on + fetch it once. @param array<string,array> $packages file name => pkg served under the base folder */
    private function serve(array $feed, array $packages = [], bool $check = true): void
    {
        [$json, $sig] = $this->sign($feed);
        $map = [self::FEED.'feed.json' => fn () => Http::response($json), self::FEED.'feed.sig' => fn () => Http::response($sig)]; // a closure: a response body can be read only once
        foreach ($packages as $pkg) $map[self::FEED.($feed['base'] ?? '').$pkg['name']] = fn () => Http::response($pkg['body'], 200, ['Content-Type' => 'application/zip', 'Content-Length' => (string) $pkg['bytes']]);
        $this->fake($map);
        Settings::put('updates.feed_enabled', true);
        if ($check) (new UpdateFeed)->check();
    }

    private function bump(string $v, string $part): string
    {
        [$a, $b, $c] = array_map('intval', explode('.', $v));
        return match ($part) { 'patch' => "$a.$b.".($c + 1), 'minor' => "$a.".($b + 1).'.0', default => ($a + 1).'.0.0' };
    }

    private function localModule(): array
    {
        $m = app(ModuleRegistry::class)->get('web-ita'); // installed here, with web-cms underneath it
        return ['id' => 'web-ita', 'version' => $m['version']];
    }

    private function su(?callable $runner = null): SelfUpdate { return new SelfUpdate($runner); }

    private function incoming(): string { return SelfUpdate::incoming(); }

    private function expectFailure(callable $call, string $reason): SelfUpdateException
    {
        try { $call(); } catch (SelfUpdateException $e) { $this->assertSame($reason, $e->reason, $e->getMessage()); return $e; }
        $this->fail('expected SelfUpdateException '.$reason);
    }

    // ---------------------------------------------------------------- feed version 2

    public function test_feed_v2_keeps_download_data_and_an_old_feed_gives_the_notice_only(): void
    {
        $core = $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core, ['released' => '2026-12-01', 'laravel' => '13.45.1', 'requires' => ['php' => '>=8.4.1', 'from' => '0.7.0']])), [$core]);
        $a = Settings::get('updates.available');
        $this->assertSame('0.7.1/', $a['base']);
        $this->assertSame(2, $a['feed_version']);
        $this->assertSame(['zip' => 'saan-core-0.7.1.zip', 'bytes' => $core['bytes'], 'sha256' => $core['sha']], array_intersect_key($a['core'], array_flip(['zip', 'bytes', 'sha256'])));
        $this->assertSame('13.45.1', $a['core']['laravel']);
        $this->assertSame(['php' => '>=8.4.1', 'from' => '0.7.0'], $a['core']['requires']);
        $plan = $this->su()->plan();
        $this->assertTrue($plan['downloadable']);
        $this->assertFalse($plan['manual_only']);

        // an old (version 1) feed: the notice works, there is nothing to download, the page offers the manual upload only
        $this->serve(['generated_at' => gmdate('c', time() + 5), 'core' => ['version' => '0.7.1', 'summary' => 'x'], 'modules' => new \stdClass]);
        $plan = $this->su()->plan();
        $this->assertTrue($plan['any']);
        $this->assertTrue($plan['manual_only']);
        $this->assertFalse($plan['ready']);
        $this->assertNull($plan['packages'][0]['zip']);
        $this->expectFailure(fn () => $this->su()->download('core'), 'manual_only');
    }

    public function test_malformed_download_fields_drop_the_whole_set_and_unsafe_folders_are_refused(): void
    {
        $core = $this->pkg('core', 'core', '0.7.1');
        foreach ([['zip' => '../saan-core-0.7.1.zip'], ['zip' => 'saan-core-9.9.9.zip'], ['sha256' => 'ABC'], ['bytes' => -1], ['bytes' => '5']] as $i => $bad) {
            $this->serve($this->feed($this->entry($core, $bad), [], ['generated_at' => gmdate('c', time() + $i + 1)]), [$core]);
            $this->assertArrayNotHasKey('zip', Settings::get('updates.available')['core'], json_encode($bad));
        }
        foreach (['../evil/', '/abs/', 'a/../b/', './', 'a b/', 'https://evil.test/'] as $i => $base) {
            $this->serve($this->feed($this->entry($core), [], ['base' => $base, 'generated_at' => gmdate('c', time() + 20 + $i)]), [$core]);
            $this->assertNull(Settings::get('updates.available')['base'], $base);
            $this->expectFailure(fn () => $this->su()->download('core'), 'manual_only');
        }
    }

    public function test_tampered_feed_tampered_package_entry_and_a_feed_of_another_key_are_rejected(): void
    {
        $core = $this->pkg('core', 'core', '0.7.1');
        $good = $this->feed($this->entry($core));
        $this->serve($good, [$core]);
        $before = Settings::get('updates.available');
        $check = function (string $json, string $sig) use ($core, $before) {
            $this->fake([self::FEED.'feed.json' => Http::response($json), self::FEED.'feed.sig' => Http::response($sig)]);
            (new UpdateFeed)->check();
            $last = Settings::get('updates.last_check');
            $this->assertFalse($last['ok']);
            $this->assertStringContainsString('ลายเซ็น', $last['error']);
            $this->assertSame($before, Settings::get('updates.available'), 'a rejected feed must not replace the accepted one');
        };
        [$json, $sig] = $this->sign($good);
        $check(str_replace('0.7.1', '0.7.2', $json), $sig);                                  // tampered feed
        $check(str_replace((string) $core['bytes'], (string) ($core['bytes'] + 1), $json), $sig); // tampered package entry (size)
        $check(str_replace($core['sha'], str_repeat('a', 64), $json), $sig);                 // tampered package entry (sha256)
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        [$j2, $s2] = $this->sign($good, $other);
        $check($j2, $s2);                                                                    // feed signed by another key
    }

    // ---------------------------------------------------------------- plan

    public function test_plan_core_only_and_nothing_to_do_and_laravel_only_when_it_changes(): void
    {
        $this->serve($this->feed(null)); // nothing new
        $plan = $this->su()->plan();
        $this->assertFalse($plan['any']);
        $this->assertSame([], $plan['packages']);
        $core = $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core, ['laravel' => '13.45.1']), [], ['generated_at' => gmdate('c', time() + 5)]), [$core]);
        $plan = $this->su()->plan();
        $this->assertSame(['core'], array_column($plan['packages'], 'key'));
        $this->assertSame(['from' => SelfUpdate::laravelVersion(), 'to' => '13.45.1', 'changes' => true], $plan['laravel']);
        $this->assertTrue($plan['packages'][0]['same_minor']);
        $this->assertSame($core['bytes'], $plan['download_bytes']);
        $this->assertTrue($plan['ready'], json_encode($plan['blockers']));
        // the same framework version in the new release: shown as unchanged
        $this->serve($this->feed($this->entry($core, ['laravel' => SelfUpdate::laravelVersion()]), [], ['generated_at' => gmdate('c', time() + 10)]), [$core]);
        $this->assertFalse($this->su()->plan()['laravel']['changes']);
    }

    public function test_plan_modules_only_and_core_plus_modules_in_dependency_order(): void
    {
        $ita = $this->localModule();
        $mod = $this->pkg('module', 'web-ita', $this->bump($ita['version'], 'patch'));
        $this->serve($this->feed(null, ['web-ita' => $this->entry($mod)]), [$mod]);
        $plan = $this->su()->plan();
        $this->assertSame(['web-ita'], array_column($plan['packages'], 'key'));
        $this->assertSame($ita['version'], $plan['packages'][0]['from']);

        // core + two modules: before download, core first and modules by id; after download the real requirements decide (web-cms needs web-ita here)
        $cms = app(ModuleRegistry::class)->get('web-cms');
        $cmsPkg = $this->pkg('module', 'web-cms', $this->bump($cms['version'], 'patch'), null, null, ['requires' => ['php' => '>=8.0', 'core' => '*', 'modules' => ['web-ita' => '*']]]);
        $core = $this->pkg('core', 'core', '0.7.1');
        $feed = $this->feed($this->entry($core), ['web-ita' => $this->entry($mod), 'web-cms' => $this->entry($cmsPkg)], ['generated_at' => gmdate('c', time() + 5)]);
        $this->serve($feed, [$core, $mod, $cmsPkg]);
        $plan = $this->su()->plan(true);
        $this->assertSame(['core', 'web-cms', 'web-ita'], array_column($plan['packages'], 'key'));
        $this->assertSame('feed', $plan['order_by']);
        foreach (['core', 'web-ita', 'web-cms'] as $key) $this->su()->download($key);
        $plan = $this->su()->plan(true);
        $this->assertSame('manifest', $plan['order_by']);
        $this->assertSame(['core', 'web-ita', 'web-cms'], array_column($plan['packages'], 'key'));
    }

    public function test_plan_blockers_known_before_the_download(): void
    {
        $core = $this->pkg('core', 'core', '0.7.1');
        $codes = fn () => array_column($this->su()->plan()['blockers'], 'code');
        $this->serve($this->feed($this->entry($core, ['requires' => ['php' => '>=99.0']])), [$core]);
        $this->assertContains('php', $codes());
        $this->expectFailure(fn () => $this->su()->download('core'), 'php');
        $this->serve($this->feed($this->entry($core, ['requires' => ['from' => '0.7.5']]), [], ['generated_at' => gmdate('c', time() + 5)]), [$core]);
        $this->assertContains('from', $codes());
        $this->serve($this->feed($this->entry($core), [], ['generated_at' => gmdate('c', time() + 10)]), [$core]);
        $this->assertSame([], $codes());
        // disk space: a host with 1 KB free
        $tiny = new class extends SelfUpdate { protected function freeSpace(): ?float { return 1024.0; } };
        $this->assertContains('disk', array_column($tiny->plan()['blockers'], 'code'));
        $this->expectFailure(fn () => $tiny->download('core'), 'disk');
        // an update that never finished
        $work = storage_path('app/private/updates/20261101T000000Z-core-abc123'); mkdir($work, 0700, true);
        file_put_contents($work.'/receipt.json', json_encode(['type' => 'core', 'id' => 'core', 'from' => '0.6.0', 'to' => '0.7.0', 'manifest_sha256' => 'x', 'started_at' => gmdate('c'), 'contract' => 1, 'steps' => [['started', gmdate('c')]]]));
        $this->assertContains('unfinished', $codes());
        $this->expectFailure(fn () => $this->su()->download('core'), 'unfinished');
        unlink($work.'/receipt.json'); rmdir($work);
        // a feed record older than 7 days must be checked again first
        $a = Settings::get('updates.available'); $a['checked_at'] = now()->subDays(8)->toIso8601String(); Settings::put('updates.available', $a);
        $this->assertContains('stale', $codes());
        $this->expectFailure(fn () => $this->su()->download('core'), 'stale');
        // the feed switched off: nothing leaves the site and nothing is planned
        Settings::put('updates.feed_enabled', false);
        $this->assertContains('feed_off', $codes());
        $this->assertSame('off', SelfUpdate::mode());
    }

    // ---------------------------------------------------------------- download

    private function core(array $feedExtra = [], ?array $pkg = null): array
    {
        $core = $pkg ?? $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core, $feedExtra)), [$core]);
        return $core;
    }

    private function packageUrl(string $name = 'saan-core-0.7.1.zip'): string { return self::FEED.'0.7.1/'.$name; }

    public function test_download_happy_path_second_call_is_a_no_op_and_nothing_stays_behind(): void
    {
        $core = $this->core();
        $r = $this->su()->download('core');
        $this->assertSame(['ready', false], [$r['status'], $r['skipped']]);
        $this->assertFileExists($this->incoming().'/saan-core-0.7.1.zip');
        $this->assertSame($core['sha'], hash_file('sha256', $this->incoming().'/saan-core-0.7.1.zip'));
        $this->assertSame([], glob($this->incoming().'/*.part*'));
        Http::assertSent(fn ($req) => $req->url() === $this->packageUrl() && !$req->hasHeader('Authorization') && !$req->hasHeader('Cookie') && !$req->hasHeader('Range'));
        $sent = count(Http::recorded());
        $again = $this->su()->download('core');
        $this->assertSame(['ready', true], [$again['status'], $again['skipped']]);
        $this->assertCount($sent, Http::recorded(), 'a complete matching file is not fetched again');
        $this->assertTrue($this->su()->plan()['packages'][0]['downloaded']);
    }

    public function test_download_resumes_with_a_range_request_and_restarts_when_the_server_ignores_it(): void
    {
        $core = $this->core();
        $half = intdiv($core['bytes'], 2);
        file_put_contents($this->incoming().'/saan-core-0.7.1.zip.part', substr($core['body'], 0, $half));
        $tail = substr($core['body'], $half);
        $this->fake([self::FEED.'feed.json' => Http::response('x'), $this->packageUrl() => Http::response($tail, 206, ['Content-Range' => 'bytes '.$half.'-'.($core['bytes'] - 1).'/'.$core['bytes'], 'Content-Type' => 'application/zip'])]);
        $r = $this->su()->download('core');
        $this->assertSame('ready', $r['status']);
        Http::assertSent(fn ($req) => $req->url() === $this->packageUrl() && $req->header('Range') === ['bytes='.$half.'-']);
        $this->assertSame($core['sha'], hash_file('sha256', $this->incoming().'/saan-core-0.7.1.zip'));
        // a server that answers 200 with the whole file to a Range request: the download restarts from zero and still succeeds
        unlink($this->incoming().'/saan-core-0.7.1.zip');
        file_put_contents($this->incoming().'/saan-core-0.7.1.zip.part', substr($core['body'], 0, $half));
        $this->fake([$this->packageUrl() => Http::response($core['body'], 200, ['Content-Type' => 'application/zip', 'Content-Length' => (string) $core['bytes']])]);
        $this->assertSame('ready', $this->su()->download('core')['status']);
        $this->assertSame($core['sha'], hash_file('sha256', $this->incoming().'/saan-core-0.7.1.zip'));
        // a wrong Content-Range is refused and the half file is dropped
        unlink($this->incoming().'/saan-core-0.7.1.zip');
        file_put_contents($this->incoming().'/saan-core-0.7.1.zip.part', substr($core['body'], 0, $half));
        $this->fake([$this->packageUrl() => Http::response($tail, 206, ['Content-Range' => 'bytes 3-'.($core['bytes'] - 1).'/'.$core['bytes']])]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'range');
        $this->assertSame([], glob($this->incoming().'/*.part*'));
    }

    public function test_download_refuses_wrong_size_wrong_checksum_and_a_package_with_a_bad_signature(): void
    {
        $core = $this->core();
        $url = $this->packageUrl();
        // longer than announced
        $this->fake([$url => Http::response($core['body'].'EXTRA', 200, ['Content-Type' => 'application/zip'])]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'size');
        // shorter than announced (a connection that ended early but completed)
        $this->fake([$url => Http::response(substr($core['body'], 0, -5), 200, ['Content-Type' => 'application/zip'])]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'size');
        // same length, different bytes
        $bad = $core['body']; $bad[strlen($bad) - 30] = $bad[strlen($bad) - 30] === 'A' ? 'B' : 'A';
        $this->fake([$url => Http::response($bad, 200, ['Content-Type' => 'application/zip'])]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'checksum');
        $this->assertSame([], glob($this->incoming().'/saan-*'), 'no file of a failed download may stay');
        // a package signed by another key whose size and checksum the (signed) feed announces: the package's own signature fails, the file is deleted
        $evil = $this->pkg('core', 'core', '0.7.1', null, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->serve($this->feed($this->entry($evil), [], ['generated_at' => gmdate('c', time() + 5)]), [$evil]);
        $e = $this->expectFailure(fn () => $this->su()->download('core'), 'signature');
        $this->assertStringContainsString('ลายเซ็น', $e->getMessage());
        $this->assertSame([], glob($this->incoming().'/saan-*'));
        // a correctly signed package of another version than the feed announces
        $other = $this->pkg('core', 'core', '0.7.9');
        $entry = $this->entry($other); $entry['version'] = '0.7.1'; $entry['zip'] = 'saan-core-0.7.1.zip';
        $this->serve($this->feed($entry, [], ['generated_at' => gmdate('c', time() + 10)]), []);
        $this->fake([$this->packageUrl() => Http::response($other['body'], 200)] + [self::FEED.'feed.json' => Http::response('x')]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'mismatch');
        $this->assertSame([], glob($this->incoming().'/saan-*'));
    }

    public function test_download_refuses_html_answers_redirects_404_and_network_errors(): void
    {
        $core = $this->core();
        $url = $this->packageUrl();
        $this->fake([$url => Http::response('<!DOCTYPE html><html><title>Just a moment...</title></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'html');
        $this->fake([$url => Http::response('<html><body>Attention Required! | Cloudflare</body></html>', 403, ['Content-Type' => 'text/html'])]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'html');
        $this->fake([$url => Http::response('<html>plain</html>', 200)]); // HTML without a content type
        $this->expectFailure(fn () => $this->su()->download('core'), 'html');
        // a redirect (to another host) is refused and never followed
        $this->fake([$url => Http::response('', 302, ['Location' => 'https://evil.test/saan-core-0.7.1.zip']), 'https://evil.test/*' => Http::response($core['body'])]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'redirect');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'evil.test'));
        $this->fake([$url => Http::response('not here', 404)]);
        $e = $this->expectFailure(fn () => $this->su()->download('core'), 'not_found');
        $this->assertStringContainsString('404', $e->getMessage());
        $this->fake([$url => Http::response('boom', 500, ['Content-Type' => 'text/plain'])]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'http');
        $this->fake([$url => fn () => throw new ConnectionException('cURL error 6: Could not resolve host')]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'network');
        $this->assertSame([], glob($this->incoming().'/saan-*'));
    }

    public function test_the_url_is_built_from_the_feed_only_and_never_leaves_its_folder(): void
    {
        $core = $this->core();
        $plan = $this->su()->plan();
        $this->assertSame(self::FEED.'0.7.1/saan-core-0.7.1.zip', $this->su()->urlFor($plan['packages'][0]));
        foreach (['../saan-core-0.7.1.zip', 'a/b.zip', 'saan-core-0.7.1.zip?x=1', "saan-core-0.7.1.zip\n", 'evil.zip', 'https://evil.test/saan-core-0.7.1.zip'] as $zip) {
            $this->expectFailure(fn () => $this->su()->urlFor(['zip' => $zip] + $plan['packages'][0]), 'manual_only');
        }
        // an unknown package key and a user-supplied URL do not exist as inputs
        $this->expectFailure(fn () => $this->su()->download('https://evil.test/x.zip'), 'not_planned');
        $this->expectFailure(fn () => $this->su()->download('web-nothing'), 'not_planned');
        // a feed address that is not https, or carries credentials, is not used for downloads either
        foreach (['http://feed.example.test/releases/feed.json', 'https://u:p@feed.example.test/releases/feed.json', 'https://feed.example.test/releases/other.json'] as $feedUrl) {
            config(['saan.update_feed' => $feedUrl]);
            $this->expectFailure(fn () => $this->su()->urlFor($plan['packages'][0]), 'feed_url');
        }
    }

    public function test_stale_part_files_are_swept_and_the_download_lock_serialises_calls(): void
    {
        $core = $this->core();
        $old = $this->incoming().'/saan-mod-gone-1.0.0.zip.part'; file_put_contents($old, 'x'); touch($old, time() - 4 * 86400);
        $fresh = $this->incoming().'/saan-mod-other-1.0.0.zip.part'; file_put_contents($fresh, 'x');
        $this->su()->download('core');
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh, 'a recent partial download of another package is kept');
        // another process holds the download lock
        unlink($this->incoming().'/saan-core-0.7.1.zip');
        $h = fopen(storage_path('app/private/updates/.download.lock'), 'c'); $this->assertTrue(flock($h, LOCK_EX | LOCK_NB));
        try {
            $before = count(Http::recorded());
            $e = $this->expectFailure(fn () => $this->su()->download('core'), 'busy');
            $this->assertSame(409, $e->http);
            $this->assertCount($before, Http::recorded(), 'nothing is fetched while another download runs');
        } finally { flock($h, LOCK_UN); fclose($h); }
        $this->assertSame('ready', $this->su()->download('core')['status']);
    }

    public function test_finished_packages_that_are_already_installed_are_swept_after_an_hour_and_everything_else_stays(): void
    {
        $in = $this->incoming(); if (!is_dir($in)) mkdir($in, 0700, true);
        $mod = $this->localModule();
        $put = function (string $name, int $ageSeconds) use ($in): string { $f = $in.'/'.$name; file_put_contents($f, 'x'); touch($f, time() - $ageSeconds); return $f; };
        $installed = $put('saan-core-0.7.0.zip', 7200);           // the version that runs here
        $older = $put('saan-core-0.6.9.zip', 7200);               // older than what runs here
        $waiting = $put('saan-core-0.7.1.zip', 7200);             // newer: downloaded, not installed yet
        $justDone = $put('saan-mod-'.$mod['id'].'-'.$mod['version'].'.zip', 60); // installed a minute ago
        $unknown = $put('saan-mod-nosuchmodule-1.0.0.zip', 7200); // a module this school does not have
        $foreign = $put('notes-0.7.0.zip', 7200);                 // not a package name
        $this->assertSame(2, $this->su()->sweepIncoming());
        $this->assertFileDoesNotExist($installed); $this->assertFileDoesNotExist($older);
        foreach ([$waiting, $justDone, $unknown, $foreign] as $kept) $this->assertFileExists($kept);
        touch($justDone, time() - 7200);
        $this->assertSame(1, $this->su()->sweepIncoming());
        $this->assertFileDoesNotExist($justDone);
        $this->assertSame(0, $this->su()->sweepIncoming());
    }

    public function test_a_failed_check_stores_a_thai_message_without_the_library_text_or_server_paths(): void
    {
        $core = $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core)), [$core]);
        $before = Settings::get('updates.available');
        $path = '/var/www/school/vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php';
        $check = function (\Throwable $e, string $expect) use ($before, $path) {
            $this->fake([self::FEED.'feed.json' => fn () => throw $e, self::FEED.'feed.sig' => fn () => throw $e]);
            (new UpdateFeed)->check();
            $last = Settings::get('updates.last_check');
            $this->assertFalse($last['ok']);
            $this->assertStringContainsString($expect, $last['error']);
            $this->assertStringNotContainsString('/var/www', $last['error']);
            $this->assertStringNotContainsString('cURL', $last['error']);
            $this->assertSame($before, Settings::get('updates.available'), 'a failed check keeps the last accepted feed');
        };
        $check(new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect to example.test port 443 in '.$path), 'เชื่อมต่อเซิร์ฟเวอร์โครงการไม่ได้');
        $check(new \RuntimeException('cURL error 60: SSL certificate problem: unable to get local issuer certificate in '.$path), 'ใบรับรอง HTTPS');
        $check(new \LogicException('unexpected state in '.$path), 'ตรวจรุ่นใหม่ไม่สำเร็จ (LogicException)');
    }

    public function test_a_server_that_limits_each_answer_gives_partial_then_ready(): void
    {
        $core = $this->core();
        $third = intdiv($core['bytes'], 3);
        file_put_contents($this->incoming().'/saan-core-0.7.1.zip.part', substr($core['body'], 0, $third));
        $mid = substr($core['body'], $third, $third);
        $this->fake([$this->packageUrl() => fn () => Http::response($mid, 206, ['Content-Range' => 'bytes '.$third.'-'.(2 * $third - 1).'/'.$core['bytes'], 'Content-Type' => 'application/zip'])]);
        $r = $this->su()->download('core');
        $this->assertSame(['partial', 2 * $third], [$r['status'], $r['received']]);
        $this->assertSame(2 * $third, filesize($this->incoming().'/saan-core-0.7.1.zip.part'));
        $this->assertFileDoesNotExist($this->incoming().'/saan-core-0.7.1.zip');
        $this->assertSame(1, $this->su()->plan()['packages'][0]['part_bytes'] > 0 ? 1 : 0);
        $rest = substr($core['body'], 2 * $third);
        $this->fake([$this->packageUrl() => fn () => Http::response($rest, 206, ['Content-Range' => 'bytes '.(2 * $third).'-'.($core['bytes'] - 1).'/'.$core['bytes'], 'Content-Type' => 'application/zip'])]);
        $this->assertSame('ready', $this->su()->download('core')['status']);
        Http::assertSent(fn ($req) => $req->header('Range') === ['bytes='.(2 * $third).'-']);
        $this->assertSame($core['sha'], hash_file('sha256', $this->incoming().'/saan-core-0.7.1.zip'));
        $this->assertSame([], glob($this->incoming().'/*.part*'));
    }

    public function test_download_refuses_a_non_zip_body_and_endpoint_errors_carry_a_reason_and_a_thai_message(): void
    {
        $core = $this->core();
        $this->fake([$this->packageUrl() => fn () => Http::response(str_repeat('x', $core['bytes']), 200, ['Content-Type' => 'application/octet-stream'])]);
        $this->expectFailure(fn () => $this->su()->download('core'), 'html');
        $this->assertSame([], glob($this->incoming().'/saan-*'));
        $this->admin();
        $this->fake([$this->packageUrl() => fn () => Http::response('gone', 404)]);
        $r = $this->postJson('/api/site/updates/self/download', ['package' => 'core', 'current_password' => self::PASSWORD])->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('reason', 'not_found');
        $this->assertStringContainsString('ไม่พบไฟล์', $r->json('message'));
    }

    // ---------------------------------------------------------------- automatic patch updates
    private function autoOn(): void { SelfUpdate::setMode('auto_patch', '02:00', '05:00'); }

    private function night(string $time = '03:00'): Carbon { return Carbon::parse('2026-11-15 '.$time.':00', 'Asia/Bangkok'); }

    /** @return array{0:SelfUpdate,1:\ArrayObject} an updater whose installer only records what it was asked to install */
    private function recorder(int $code = 0): array
    {
        $log = new \ArrayObject;
        $su = new SelfUpdate(function (array $args, int $timeout) use ($log, $code) {
            $dir = substr($args[1], strlen('--all='));
            $log[] = ['args' => $args, 'files' => array_map('basename', glob($dir.'/saan-*.zip') ?: [])];
            return [$code, $code === 0 ? '[OK] ติดตั้งครบ' : '[ERR] หยุดที่ saan-core-0.7.1.zip'];
        });
        return [$su, $log];
    }

    public function test_auto_patch_installs_a_patch_release_inside_the_window_and_records_it(): void
    {
        $this->autoOn();
        $core = $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core, ['laravel' => '13.45.1'])), [$core]);
        [$su, $log] = $this->recorder();
        $r = $su->runAuto($this->night());
        $this->assertSame('installed', $r['action'], $r['message']);
        $this->assertCount(1, $log);
        $this->assertStringStartsWith('--all=', $log[0]['args'][1]);
        $this->assertSame(['saan:update'], [$log[0]['args'][0]]);
        $this->assertSame(['saan-core-0.7.1.zip'], $log[0]['files'], 'only the packages of this run are in the run folder');
        $notice = Settings::get('updates.auto')['notice'];
        $this->assertSame('ok', $notice['kind']);
        $this->assertStringContainsString('อัปเดตอัตโนมัติเป็นรุ่น', $notice['text']);
        $this->assertStringContainsString('0.7.1', $notice['text']);
        $this->assertSame('ok', Settings::get('updates.auto')['attempts']['core@0.7.1']['state']);
        $this->assertSame([], glob($this->incoming().'/run-*'), 'the run folder is gone');
        $this->assertSame([], glob($this->incoming().'/saan-*.zip'), 'installed packages are not left behind');
    }

    public function test_auto_patch_never_installs_minor_or_major_releases_and_says_why(): void
    {
        $this->autoOn();
        foreach (['minor' => '0.8.0', 'major' => '1.0.0'] as $kind => $version) {
            $core = $this->pkg('core', 'core', $version);
            $this->serve($this->feed($this->entry($core), [], ['generated_at' => gmdate('c', time() + (int) ($version[0]) + 1)] + ['base' => $version.'/']), [$core]);
            [$su, $log] = $this->recorder();
            $r = $su->runAuto($this->night());
            $this->assertSame('skipped', $r['action'], $kind);
            $this->assertCount(0, $log, $kind);
            $this->assertStringContainsString('ไม่ใช่แพตช์', Settings::get('updates.auto')['notice']['text']);
        }
        // a module patch alone is installed (same rule per module) while the core minor stays for the administrator
        $ita = $this->localModule(); $mod = $this->pkg('module', 'web-ita', $this->bump($ita['version'], 'patch')); $minorMod = $this->bump($ita['version'], 'minor');
        $core = $this->pkg('core', 'core', '0.8.0');
        $this->serve($this->feed($this->entry($core), ['web-ita' => $this->entry($mod)], ['base' => '0.8.0/', 'generated_at' => gmdate('c', time() + 50)]), [$core, $mod]);
        [$su, $log] = $this->recorder();
        $this->assertSame('installed', $su->runAuto($this->night())['action']);
        $this->assertSame([$mod['name']], $log[0]['files']);
        // and a module minor release is not installed by itself either
        $mod2 = $this->pkg('module', 'web-ita', $minorMod);
        $this->serve($this->feed(null, ['web-ita' => $this->entry($mod2)], ['base' => '0.8.1/', 'generated_at' => gmdate('c', time() + 60)]), [$mod2]);
        [$su, $log] = $this->recorder();
        $this->assertSame('skipped', $su->runAuto($this->night())['action']);
        $this->assertCount(0, $log);
    }

    public function test_auto_patch_waits_for_the_window_and_tries_each_release_once(): void
    {
        $this->autoOn();
        $core = $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core)), [$core]);
        [$su, $log] = $this->recorder(1);
        foreach (['01:59', '05:00', '12:00', '23:30'] as $t) $this->assertSame('none', $su->runAuto($this->night($t))['action'], $t);
        $this->assertCount(0, $log, 'outside the window nothing is installed');
        $this->assertSame('failed', $su->runAuto($this->night('02:00'))['action']);
        $this->assertCount(1, $log);
        $this->assertSame('failed', Settings::get('updates.auto')['attempts']['core@0.7.1']['state']);
        $this->assertSame('failed', Settings::get('updates.auto')['notice']['kind']);
        $this->assertSame('none', $su->runAuto($this->night('03:00'))['action'], 'a failed attempt is not repeated by itself');
        $this->assertSame('none', $su->runAuto($this->night('04:00'))['action']);
        $this->assertCount(1, $log);
        $this->assertFileExists($this->incoming().'/saan-core-0.7.1.zip', 'the failed package is back in incoming/ for the administrator');
        // the administrator asks for another try
        $this->su()->check(true);
        $this->assertSame('failed', $su->runAuto($this->night('04:30'))['action']);
        $this->assertCount(2, $log);
        // a window over midnight
        SelfUpdate::setMode('auto_patch', '22:00', '03:00');
        $this->assertTrue(SelfUpdate::inWindow($this->night('23:00')));
        $this->assertTrue(SelfUpdate::inWindow($this->night('02:59')));
        $this->assertFalse(SelfUpdate::inWindow($this->night('03:00')));
        $this->assertFalse(SelfUpdate::inWindow($this->night('12:00')));
    }

    public function test_auto_patch_does_nothing_while_an_update_is_unfinished_or_running_or_blocked(): void
    {
        $this->autoOn();
        $core = $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core, ['requires' => ['php' => '>=99.0']])), [$core]);
        [$su, $log] = $this->recorder();
        $r = $su->runAuto($this->night());
        $this->assertSame('blocked', $r['action']);
        $this->assertStringContainsString('PHP', Settings::get('updates.auto')['notice']['text']);
        $this->assertArrayNotHasKey('core@0.7.1', Settings::get('updates.auto')['attempts'], 'a blocked release is not an attempt');
        $this->serve($this->feed($this->entry($core), [], ['generated_at' => gmdate('c', time() + 5)]), [$core]);
        $work = storage_path('app/private/updates/20261101T000000Z-core-abc123'); mkdir($work, 0700, true);
        file_put_contents($work.'/receipt.json', json_encode(['type' => 'core', 'id' => 'core', 'from' => '0.6.0', 'to' => '0.7.0', 'manifest_sha256' => 'x', 'started_at' => gmdate('c'), 'contract' => 1, 'steps' => []]));
        $this->assertSame('none', $su->runAuto($this->night())['action']);
        unlink($work.'/receipt.json'); rmdir($work);
        $h = fopen(storage_path('app/private/updates/.lock'), 'c'); $this->assertTrue(flock($h, LOCK_EX | LOCK_NB));
        try { $this->assertSame('none', $su->runAuto($this->night())['action']); } finally { flock($h, LOCK_UN); fclose($h); }
        $this->assertCount(0, $log);
        $this->assertSame('installed', $su->runAuto($this->night())['action'], 'free again');
    }

    public function test_notify_and_off_modes_never_install_and_off_never_calls_out(): void
    {
        $core = $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core)), [$core]);
        [$su, $log] = $this->recorder();
        SelfUpdate::setMode('notify');
        $this->assertSame('notify', SelfUpdate::mode());
        $this->assertSame('none', $su->runAuto($this->night())['action']);
        $this->fake([]);
        SelfUpdate::setMode('off');
        $this->assertSame('off', SelfUpdate::mode());
        $this->assertFalse(UpdateFeed::enabled(), 'off is the feed switch being off: the two can never disagree');
        $this->assertSame('none', $su->runAuto($this->night())['action']);
        $this->expectFailure(fn () => $su->check(), 'feed_off');
        Http::assertNothingSent();
        $this->assertCount(0, $log);
        // switching the feed on the old way gives the old behaviour: notify
        Settings::put('updates.feed_enabled', true);
        $this->assertSame('notify', SelfUpdate::mode());
        $this->expectException(SelfUpdateException::class);
        SelfUpdate::setMode('auto_patch', '02:00', '02:00');
    }

    // ---------------------------------------------------------------- endpoints

    public function test_endpoints_are_admin_only_have_named_throttles_and_the_public_edition_does_not_serve_them(): void
    {
        $routes = ['GET /admin/updates', 'GET /api/site/updates/self', 'POST /api/site/updates/self/check', 'POST /api/site/updates/self/download', 'PUT /api/site/updates/self/mode'];
        // guest
        $this->get('/admin/updates')->assertRedirect();
        $this->getJson('/api/site/updates/self')->assertUnauthorized();
        $this->postJson('/api/site/updates/self/check')->assertUnauthorized();
        $this->postJson('/api/site/updates/self/download', ['package' => 'core'])->assertUnauthorized();
        $this->putJson('/api/site/updates/self/mode', ['mode' => 'off'])->assertUnauthorized();
        // a teacher
        $this->actingAs(User::where('role', 'teacher')->first());
        $this->get('/admin/updates')->assertForbidden();
        $this->getJson('/api/site/updates/self')->assertForbidden();
        $this->postJson('/api/site/updates/self/check')->assertForbidden();
        $this->postJson('/api/site/updates/self/download', ['package' => 'core', 'current_password' => self::PASSWORD])->assertForbidden();
        $this->putJson('/api/site/updates/self/mode', ['mode' => 'off', 'current_password' => self::PASSWORD])->assertForbidden();
        // named throttles, like the neighbours
        $names = ['POST /api/site/updates/self/check' => 'post-api-site-updates-self-check', 'POST /api/site/updates/self/download' => 'post-api-site-updates-self-download', 'PUT /api/site/updates/self/mode' => 'put-api-site-updates-self-mode'];
        foreach ($names as $route => $name) {
            [$method, $uri] = explode(' ', $route);
            $mw = app('router')->getRoutes()->match(\Illuminate\Http\Request::create($uri, $method))->gatherMiddleware();
            $this->assertNotEmpty(array_filter($mw, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && str_ends_with($m, ','.$name)), $route.' needs the throttle '.$name);
        }
        // the public edition serves none of them
        foreach ($routes as $r) {
            $path = explode(' ', $r)[1];
            $this->assertTrue(\App\Support\Saan\Edition::blockedInPublic($path), $path);
            $this->assertFalse(\App\Support\Saan\Edition::servesIn('public', 'staff'));
        }
        foreach (['internal', 'combined'] as $e) $this->assertTrue(\App\Support\Saan\Edition::servesIn($e, 'staff'));
    }

    public function test_page_status_check_mode_and_download_work_for_the_administrator(): void
    {
        $this->admin();
        $core = $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core, ['laravel' => '13.45.1'])), [$core], false);
        Settings::put('updates.feed_enabled', false);
        $page = $this->get('/admin/updates')->assertOk();
        foreach (['รุ่นที่ติดตั้ง', 'รุ่นใหม่', 'การทำงาน', 'การตั้งค่า', 'ติดตั้งจากไฟล์ที่อัปโหลดเอง', 'ประวัติ', 'ไฟล์สำรองก่อนอัปเดต', 'updates-admin.js', 'updates-admin.css', 'ไม่ส่งชื่อโรงเรียน'] as $needle) $page->assertSee($needle);
        $page->assertDontSee('<script>', false); // no inline script: the page loads its code from a file
        $s = $this->getJson('/api/site/updates/self')->assertOk()->json();
        foreach (['versions', 'modules', 'feed', 'mode', 'auto', 'plan', 'downloads', 'running', 'unfinished', 'last_update', 'history', 'backups'] as $key) $this->assertArrayHasKey($key, $s);
        $this->assertSame(SelfUpdate::laravelVersion(), $s['versions']['laravel']);
        $this->assertSame(PHP_VERSION, $s['versions']['php']);
        $this->assertSame('0.7.0', $s['versions']['saan']);
        $this->assertNotEmpty($s['versions']['database']['version']);
        $this->assertNotEmpty($s['modules']);
        // off: check is refused, nothing is fetched
        $this->postJson('/api/site/updates/self/check')->assertUnprocessable()->assertJsonPath('reason', 'feed_off');
        // mode: password required, wrong password refused, bad window refused
        $this->putJson('/api/site/updates/self/mode', ['mode' => 'notify', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->assertSame('off', SelfUpdate::mode());
        $this->putJson('/api/site/updates/self/mode', ['mode' => 'auto_patch', 'window_start' => '02:00', 'window_end' => '02:00', 'current_password' => self::PASSWORD])->assertUnprocessable();
        $this->putJson('/api/site/updates/self/mode', ['mode' => 'auto_patch', 'window_start' => '25:00', 'window_end' => '03:00', 'current_password' => self::PASSWORD])->assertUnprocessable();
        $this->putJson('/api/site/updates/self/mode', ['mode' => 'auto_patch', 'window_start' => '01:30', 'window_end' => '04:15', 'current_password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('mode.mode', 'auto_patch')->assertJsonPath('mode.window.start', '01:30')->assertJsonPath('mode.window.end', '04:15');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'updates.mode_changed')->count());
        // check, then the plan shows the new release and the framework change
        $this->postJson('/api/site/updates/self/check')->assertOk()->assertJsonPath('plan.packages.0.to', '0.7.1')->assertJsonPath('plan.laravel.to', '13.45.1')->assertJsonPath('plan.laravel.changes', true);
        // download: package name only, password required
        $this->postJson('/api/site/updates/self/download', ['package' => 'core'])->assertUnprocessable();
        $this->postJson('/api/site/updates/self/download', ['package' => 'core', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/site/updates/self/download', ['package' => 'https://evil.test/x.zip', 'current_password' => self::PASSWORD])->assertUnprocessable();
        $this->postJson('/api/site/updates/self/download', ['package' => 'core', 'current_password' => self::PASSWORD])->assertOk()->assertJsonPath('status', 'ready');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.test'));
        $this->postJson('/api/site/updates/self/download', ['package' => 'web-nothing', 'current_password' => self::PASSWORD])->assertNotFound()->assertJsonPath('reason', 'not_planned');
        $s = $this->getJson('/api/site/updates/self')->assertOk()->json();
        $this->assertTrue($s['plan']['packages'][0]['downloaded']);
        $this->assertSame('saan-core-0.7.1.zip', $s['downloads'][0]['file']);
        // installing reuses the existing apply endpoint: it still asks for the password, and its file name rule is unchanged
        $this->postJson('/api/site/updates/apply', ['file' => 'saan-core-0.7.1.zip', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/site/updates/apply', ['file' => '../x.zip', 'current_password' => self::PASSWORD])->assertUnprocessable();
        // the old feed switch endpoints keep working and agree with the mode
        $this->putJson('/api/site/updates/feed', ['enabled' => false, 'current_password' => self::PASSWORD])->assertOk()->assertJsonPath('enabled', false);
        $this->getJson('/api/site/updates/self')->assertJsonPath('mode.mode', 'off');
    }

    public function test_navigation_and_the_old_pages_point_to_the_new_page(): void
    {
        $this->admin();
        $this->get('/admin/modules')->assertOk()->assertSee('/admin/updates', false);
        $this->get('/work')->assertOk()->assertSee('/admin/updates', false);
        $this->actingAs(User::where('role', 'teacher')->first());
        $this->get('/work')->assertOk()->assertDontSee('/admin/updates', false);
        $js = (string) file_get_contents(public_path('assets/cms-deployment.js'));
        $this->assertStringContainsString('admin/updates', $js);
        $this->assertStringNotContainsString("request('/api/site/updates/apply'", $js, 'the install list moved to the new page');
    }

    // ---------------------------------------------------------------- command

    public function test_command_check_plan_and_install_on_a_fixture_release(): void
    {
        // off: --check says so and sends nothing
        $this->artisan('saan:self-update', ['--check' => true])->expectsOutputToContain('ปิดการตรวจรุ่นใหม่อยู่')->assertExitCode(0);
        Http::assertNothingSent();
        $core = $this->pkg('core', 'core', '0.7.1');
        $this->serve($this->feed($this->entry($core, ['laravel' => '13.45.1'])), [$core], false);
        $this->artisan('saan:self-update', ['--check' => true])->expectsOutputToContain('ตรวจรุ่นใหม่แล้ว')->expectsOutputToContain('0.7.0 -> 0.7.1')->expectsOutputToContain('Laravel '.SelfUpdate::laravelVersion().' -> 13.45.1')->assertExitCode(0);
        $this->artisan('saan:self-update', ['--plan' => true])->expectsOutputToContain('(Saan) 0.7.0 -> 0.7.1')->assertExitCode(0);
        // --install refuses without --yes when it cannot ask
        $this->artisan('saan:self-update', ['--install' => true, '--no-interaction' => true])->expectsOutputToContain('ไม่ได้ยืนยัน')->assertExitCode(1);
        $this->assertSame([], glob($this->incoming().'/saan-*.zip'));
        // --download only downloads
        $this->artisan('saan:self-update', ['--download' => true])->expectsOutputToContain('ตรวจ SHA-256 และลายเซ็นผ่าน')->assertExitCode(0);
        $this->assertFileExists($this->incoming().'/saan-core-0.7.1.zip');
        // --install --yes installs through saan:update --all in a run folder holding exactly the planned packages
        [$su, $log] = $this->recorder();
        $this->app->bind(SelfUpdate::class, fn () => $su);
        $this->artisan('saan:self-update', ['--install' => true, '--yes' => true])->expectsOutputToContain('ติดตั้งครบ')->assertExitCode(0);
        $this->assertCount(1, $log);
        $this->assertSame(['saan-core-0.7.1.zip'], $log[0]['files']);
        $this->assertStringStartsWith('--all=', $log[0]['args'][1]);
        // a failing install exits 1
        [$su2] = $this->recorder(1);
        $this->app->bind(SelfUpdate::class, fn () => $su2);
        $this->artisan('saan:self-update', ['--install' => true, '--yes' => true])->assertExitCode(1);
        // --auto is silent when the mode is not auto_patch
        $this->artisan('saan:self-update', ['--auto' => true])->assertExitCode(0);
    }

    public function test_the_scheduler_runs_the_automatic_tick_every_ten_minutes_without_overlap(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'saan:self-update --auto'));
        $this->assertCount(1, $events);
        $e = $events->first();
        $this->assertSame('*/10 * * * *', $e->expression);
        $this->assertTrue($e->withoutOverlapping);
        $this->assertNotEmpty(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($x) => str_contains((string) $x->command, 'saan:update-check')), 'the daily notice check stays');
    }
}
