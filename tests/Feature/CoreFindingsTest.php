<?php

namespace Tests\Feature;

use App\Domain\Updater;
use App\Support\JsonSort;
use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Core audit findings: Mutation encoding/409 read, idempotency binding, JSON numeric sort, semver guard, recovery, scheduling, module route guard. */
class CoreFindingsTest extends TestCase
{
    use RefreshDatabase;

    private function encode(array $values): array
    {
        $m = new \ReflectionMethod(Mutation::class, 'encode'); $m->setAccessible(true);
        return $m->invoke(null, $values);
    }

    public function test_mutation_encode_formats_dates_enums_and_keeps_expressions(): void
    {
        config(['app.timezone' => 'Asia/Bangkok']);
        $raw = DB::raw('version + 1');
        $out = $this->encode(['at' => Carbon::parse('2026-11-06 01:00:00', 'UTC'), 'imm' => new \DateTimeImmutable('2026-11-06 08:00:00'), 'raw' => $raw, 'arr' => ['ก' => 1], 'plain' => 'x', 'n' => 3]);
        $this->assertSame('2026-11-06 08:00:00', $out['at'], 'Carbon is written in the app timezone as a plain datetime');
        $this->assertSame('2026-11-06 08:00:00', $out['imm']);
        $this->assertInstanceOf(Expression::class, $out['raw'], 'DB::raw must not become {}');
        $this->assertSame('{"ก":1}', $out['arr']);
        $this->assertSame('x', $out['plain']);
        $this->assertSame(3, $out['n']);
        $this->assertStringNotContainsString('"', (string) $out['at']);
    }

    public function test_mutation_update_with_now_stores_a_clean_datetime_and_409_returns_current_row(): void
    {
        $this->ddl(fn () => Schema::create('cf_mutation_probe', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedInteger('version')->default(1); $t->dateTime('read_at')->nullable(); $t->string('label')->nullable(); }));
        $id = DB::table('cf_mutation_probe')->insertGetId(['school_id' => (int) config('school.school_id'), 'version' => 1, 'label' => 'a']);
        $row = Mutation::update('cf_mutation_probe', $id, 1, ['read_at' => now(), 'label' => 'b'], 'core.probe');
        $this->assertSame(2, (int) $row->version);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $row->read_at);
        try { Mutation::update('cf_mutation_probe', $id, 1, ['label' => 'c'], 'core.probe'); $this->fail('stale version must conflict'); }
        catch (\App\Support\Saan\VersionConflict $e) { $this->assertSame(2, (int) $e->current['version']); $this->assertSame('b', $e->current['label']); }
    }

    public function test_idempotency_key_is_bound_to_the_body(): void
    {
        config(['cache.default' => 'array']); Cache::flush();
        $make = fn (array $body) => tap(Request::create('/api/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'k-1'], json_encode($body)), fn ($r) => null);
        $calls = 0;
        $first = Idempotency::run($make(['a' => 1]), function () use (&$calls) { $calls++; return response()->json(['ok' => $calls], 201); });
        $this->assertSame(201, $first->getStatusCode());
        $same = Idempotency::run($make(['a' => 1]), function () use (&$calls) { $calls++; return response()->json(['ok' => $calls], 201); });
        $this->assertSame('true', $same->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, $calls, 'same key and body replays');
        try { Idempotency::run($make(['a' => 2]), function () use (&$calls) { $calls++; return response()->json([], 201); }); $this->fail('changed body must be refused'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->assertSame(1, $calls);
    }

    public function test_json_sort_orders_numerically(): void
    {
        $this->ddl(fn () => Schema::create('core_sort', function (Blueprint $t) { $t->id(); $t->json('metadata')->nullable(); }));
        foreach ([10, 2, 1, 11, 3] as $n) DB::table('core_sort')->insert(['metadata' => json_encode(['sort_order' => $n])]);
        $order = JsonSort::numeric(DB::table('core_sort'))->get()->map(fn ($r) => json_decode($r->metadata, true)['sort_order'])->all();
        $this->assertSame([1, 2, 3, 10, 11], $order);
        $this->assertStringContainsString('CAST(', JsonSort::numeric(DB::table('core_sort'))->toSql());
    }

    public function test_version_comparison_ranks_prereleases_below_the_release(): void
    {
        $this->assertSame(-1, Updater::compareVersions('1.2.0-rc.1', '1.2.0'));
        $this->assertSame(1, Updater::compareVersions('1.2.0', '1.2.0-rc.1'));
        $this->assertSame(-1, Updater::compareVersions('1.2.0-rc.2', '1.2.0-rc.10'));
        $this->assertSame(-1, Updater::compareVersions('1.2.0-alpha', '1.2.0-beta'));
        $this->assertSame(1, Updater::compareVersions('1.10.0', '1.9.9'));
        $this->assertSame(0, Updater::compareVersions('1.2.0', '1.2.0'));
        $this->assertSame(-1, Updater::compareVersions('1.2.0-rc.1', '1.2.0-rc.1.1'));
    }

    public function test_unfinished_update_is_listed_and_recovered(): void
    {
        $base = base_path('storage/app/private/recover-probe'); @mkdir($base, 0700, true);
        $keep = $base.'/old.txt'; $new = $base.'/created.txt';
        file_put_contents($keep, 'NEW-BROKEN'); file_put_contents($new, 'x');
        $work = storage_path('app/private/updates/19990101T000000Z-probe-aaaaaa');
        @mkdir($work.'/snapshot/storage/app/private/recover-probe', 0700, true);
        file_put_contents($work.'/snapshot/storage/app/private/recover-probe/old.txt', 'OLD-GOOD');
        file_put_contents($work.'/receipt.json', json_encode(['type' => 'module', 'id' => 'probe', 'manifest_sha256' => str_repeat('a', 64), 'from' => '1.0.0', 'to' => '1.1.0', 'started_at' => '1999-01-01T00:00:00+00:00', 'was_down' => true, 'created' => ['storage/app/private/recover-probe/created.txt'], 'steps' => []]));
        try {
            $mine = array_values(array_filter(Updater::unfinished(), fn ($u) => $u['dir'] === $work));
            $this->assertCount(1, $mine);
            (new Updater)->recover($work);
            $this->assertSame('OLD-GOOD', file_get_contents($keep));
            $this->assertFileDoesNotExist($new);
            $this->assertEmpty(array_filter(Updater::unfinished(), fn ($u) => $u['dir'] === $work), 'recovered receipt is no longer unfinished');
        } finally {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($base); (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($work);
        }
    }

    public function test_retention_commands_are_scheduled(): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");
        $this->assertStringContainsString('saan:api-prune', $commands);
        $this->assertStringContainsString('saan:personnel-retention', $commands);
        $this->assertStringContainsString('saan:maintenance-prune', $commands);
    }

    public function test_trusted_proxy_headers_never_change_the_host_used_in_links(): void
    {
        config(['saan.trusted_proxies' => '*']);
        (new \App\Providers\AppServiceProvider($this->app))->boot();
        try {
            \Illuminate\Support\Facades\Route::get('/__proxy-probe', fn (Request $r) => response()->json(['url' => url('/reset-password/x'), 'ip' => $r->ip(), 'secure' => $r->isSecure()]));
            $res = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
                ->withHeaders(['X-Forwarded-Host' => 'attacker.example', 'X-Forwarded-Port' => '8443', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
                ->getJson('/__proxy-probe')->assertOk();
            $this->assertStringNotContainsString('attacker.example', $res->json('url'));
            $this->assertStringNotContainsString('8443', $res->json('url'));
            $this->assertSame('203.0.113.7', $res->json('ip')); // X-Forwarded-For is still trusted
            $this->assertTrue($res->json('secure')); // and so is X-Forwarded-Proto
        } finally {
            \Illuminate\Http\Middleware\TrustProxies::flushState();
        }
    }

    public function test_restore_carries_the_saan_hash_key_and_refuses_to_replace_a_different_one(): void
    {
        $carry = [\App\Console\Commands\SchoolArchive::class, 'carryHashKey'];
        $src = ['SAAN_HASH_KEY' => 'base64:abc"def'];
        // target without the key: appended, quoted
        $out = $carry("APP_KEY=\"x\"\n", "APP_KEY=\"x\"\n", $src);
        $this->assertSame('base64:abc"def', \Dotenv\Dotenv::parse($out)['SAAN_HASH_KEY']);
        // target with an empty key: replaced in place
        $out = $carry("SAAN_HASH_KEY=\nA=1\n", "SAAN_HASH_KEY=\nA=1\n", $src);
        $this->assertSame(1, substr_count($out, 'SAAN_HASH_KEY'));
        $this->assertSame('base64:abc"def', \Dotenv\Dotenv::parse($out)['SAAN_HASH_KEY']);
        // same value: fine; archive without the key: untouched
        $this->assertSame('base64:abc"def', \Dotenv\Dotenv::parse($carry("SAAN_HASH_KEY=\"base64:abc\\\"def\"\n", "A=1\n", $src))['SAAN_HASH_KEY']);
        $this->assertSame("A=1\n", $carry("A=1\n", "A=1\n", []));
        // a different non-empty target key is refused unless explicitly replaced
        try { $carry("SAAN_HASH_KEY=other\n", "SAAN_HASH_KEY=other\n", $src); $this->fail('expected refusal'); } catch (\RuntimeException) {}
        $this->assertSame('base64:abc"def', \Dotenv\Dotenv::parse($carry("SAAN_HASH_KEY=other\n", "SAAN_HASH_KEY=other\n", $src, true))['SAAN_HASH_KEY']);
    }

    public function test_module_routes_carry_the_module_guard(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => str_starts_with($r->uri(), 'api/finance/'));
        $this->assertNotNull($route, 'a finance route exists');
        $this->assertContains('module:finance', $route->gatherMiddleware());
    }
}
