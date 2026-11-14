<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Outbox;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Publish\Receiver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Saan\Modules\Complaints\Turnstile as ComplaintsTurnstile;
use Saan\Modules\WebNotices\NoticeRepository;
use Saan\Modules\WebNotices\NoticeSearch;
use Tests\TestCase;

/**
 * F9 notices with FULL names: expiring full_name_until, no names in the initial HTML, Turnstile + one-time token delivery, search-own-name mode,
 * and the guarantee that neither a public database dump nor the public renderer shows a full name after full_name_until.
 * Demo school: โรงเรียนต้นธารวิทยา. All names are invented; every surname below is a marker that must never appear where it must not.
 */
class NoticeFullNamesTest extends TestCase
{
    use RefreshDatabase;

    private const NAMES = [
        'นายอรรถพลมาร์ค สุขสวัสดิ์', 'เด็กหญิงพิมพ์ลดามาร์ค เจริญสุข', 'นางสาวกัลยามาร์ค ทองประเสริฐ', 'เด็กชายภูริทัตมาร์ค แก้วมณี',
        'นายวรเมธมาร์ค ศรีสมบูรณ์', 'นางสาวชนิดามาร์ค พึ่งบุญ', 'เด็กชายธนกฤตมาร์ค บุญเรือง',
    ];
    private const SURNAMES = ['สุขสวัสดิ์', 'เจริญสุข', 'ทองประเสริฐ', 'แก้วมณี', 'ศรีสมบูรณ์', 'พึ่งบุญ', 'บุญเรือง'];
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        Storage::fake('local');
        config(['saan.keys_dir' => $this->tempDir()]);
        Outbox::forget();
        $this->actingAs(User::where('role', 'admin')->first());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        config(['database.default' => $this->baseConnection]);
        try { DB::purge('pubdb'); } catch (\Throwable) {}
        parent::tearDown();
        gc_collect_cycles();
        foreach ($this->tmp as $p) is_dir($p) ? $this->rrmdir($p) : @unlink($p);
    }

    // ---------------------------------------------------------------- helpers

    private function tempDir(): string
    {
        $d = sys_get_temp_dir().'/saan-nfn-'.bin2hex(random_bytes(6));
        mkdir($d, 0777, true);
        return $this->tmp[] = $d;
    }

    private function rrmdir(string $d): void
    {
        foreach (glob($d.'/{,.}*', GLOB_BRACE) ?: [] as $p) {
            if (in_array(basename($p), ['.', '..'], true)) continue;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }

    private function turnstileOn(): void
    {
        config(['saan.turnstile.site_key' => 'site-demo', 'saan.turnstile.secret' => 'secret-demo']);
    }

    private function fakeTurnstile(bool $ok = true): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => $ok], 200)]);
    }

    private function guest(): void { $this->app['auth']->forgetGuards(); }

    private static function until(string $modify = '+5 days'): string
    {
        return CarbonImmutable::now('Asia/Bangkok')->modify($modify)->format('Y-m-d H:i:s');
    }

    /** A published list with the demo names. $o overrides columns of notice_lists. @return array{0:int,1:string} id, slug */
    private function makeList(array $o = [], bool $withRows = true): array
    {
        $slug = $o['slug'] ?? 'full-'.strtolower(Str::random(8));
        $today = CarbonImmutable::now('Asia/Bangkok');
        $id = DB::table('notice_lists')->insertGetId($o + ['school_id' => (int) config('school.school_id'), 'slug' => $slug, 'title' => 'ผลการจัดห้องเรียน โรงเรียนต้นธารวิทยา', 'intro' => 'ตรวจสอบรายชื่อ',
            'columns' => json_encode(['order_no', 'display_name']), 'name_mode' => 'full', 'publish_from' => $today->subDay()->toDateString(), 'expires_on' => $today->addDays(30)->toDateString(),
            'status' => 'published', 'version' => 1, 'full_name_until' => self::until(), 'search_only' => 0, 'created_at' => now(), 'updated_at' => now()]);
        if ($withRows) foreach (self::NAMES as $i => $n) DB::table('notice_rows')->insert(['school_id' => (int) config('school.school_id'), 'list_id' => $id, 'order_no' => $i + 1, 'display_name' => $n]);
        return [$id, $slug];
    }

    private function sid(): string { return Str::random(40); }

    /** Public POST as one browser session ($sid) from one address. */
    private function pcall(string $slug, string $path, array $body, string $sid, string $ip = '203.0.113.9')
    {
        config(['school.demo' => false, 'school.local_only' => false]); // public visitors come from arbitrary addresses (SchoolBoundary only lets loopback in while demo is on)
        return $this->withCookie((string) config('session.cookie'), $sid)->withServerVariables(['REMOTE_ADDR' => $ip])->withCredentials()->postJson("/portal/notices/$slug/$path", $body);
    }

    private function token(string $slug, string $sid, string $ip = '203.0.113.9'): string
    {
        return (string) $this->pcall($slug, 'challenge', ['cf-turnstile-response' => 'good'], $sid, $ip)->assertOk()->json('token');
    }

    private function rowsOf(string $slug, string $sid, string $ip = '203.0.113.9')
    {
        return $this->pcall($slug, 'rows', ['token' => $this->token($slug, $sid, $ip)], $sid, $ip);
    }

    /** Puts data into the session $sid before the browser request arrives (e.g. an accepted access code). */
    private function seedSession(string $sid, array $data): void
    {
        $store = app('session')->driver();
        $store->setId($sid);
        $store->start();
        foreach ($data as $k => $v) $store->put($k, $v);
        $store->save();
    }

    private function assertNoNames(string $text, string $why = ''): void
    {
        foreach (self::SURNAMES as $s) $this->assertStringNotContainsString($s, $text, $why.' '.$s);
    }

    // ---------------------------------------------------------------- admin: data rules

    public function test_full_mode_is_refused_without_turnstile_and_search_only_too(): void
    {
        $today = NoticeRepository::today();
        $base = ['title' => 'ประกาศ', 'publish_from' => $today, 'expires_on' => date('Y-m-d', strtotime('+30 days')), 'columns' => ['order_no', 'display_name']];
        $r = $this->postJson('/api/notices/lists', $base + ['name_mode' => 'full'])->assertStatus(422);
        $this->assertStringContainsString('Turnstile', $r->json('errors.name_mode.0'));
        $this->assertSame(0, DB::table('notice_lists')->count());
        $this->postJson('/api/notices/lists', $base + ['name_mode' => 'first_initial', 'search_only' => true])->assertStatus(422)->assertJsonValidationErrors('search_only');
        // masked lists are unaffected
        $n = $this->postJson('/api/notices/lists', $base + ['name_mode' => 'first_initial'])->assertCreated()->json();
        $this->assertNull($n['list']['full_name_until']);
        // an existing masked list cannot be switched to full without Turnstile
        $this->putJson('/api/notices/lists/'.$n['list']['id'], ['version' => $n['list']['version'], 'name_mode' => 'full'])->assertStatus(422)->assertJsonValidationErrors('name_mode');

        $this->turnstileOn();
        $f = $this->postJson('/api/notices/lists', $base + ['name_mode' => 'full'])->assertCreated()->json();
        $this->assertSame('full', $f['list']['name_mode']);
        $this->assertTrue($f['list']['full_active']);
        // removing Turnstile later: a gated list cannot be published
        $rows = $this->putJson('/api/notices/lists/'.$f['list']['id'].'/rows', ['version' => $f['list']['version'], 'text' => "ลำดับ\tชื่อ-สกุล\n1\t".self::NAMES[0]])->assertOk()->json();
        config(['saan.turnstile.site_key' => '', 'saan.turnstile.secret' => '']);
        $this->postJson('/api/notices/lists/'.$f['list']['id'].'/status', ['version' => $rows['list']['version'], 'action' => 'publish'])->assertStatus(422);
    }

    public function test_full_name_until_default_limit_and_validation(): void
    {
        $this->turnstileOn();
        $tz = 'Asia/Bangkok';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $mk = fn (array $o = []) => $this->postJson('/api/notices/lists', $o + ['title' => 'ประกาศ', 'publish_from' => $today->toDateString(), 'expires_on' => $today->addDays(40)->toDateString(),
            'columns' => ['order_no', 'display_name'], 'name_mode' => 'full']);
        // default = publish_from + 14 days
        $a = $mk()->assertCreated()->json();
        $this->assertSame($today->addDays(14)->format('Y-m-d H:i:s'), $a['list']['full_name_until']);
        // default never later than the end of expires_on
        $b = $mk(['expires_on' => $today->addDays(5)->toDateString()])->assertCreated()->json();
        $this->assertSame($today->addDays(5)->endOfDay()->format('Y-m-d H:i:s'), $b['list']['full_name_until']);
        // later than expires_on is refused (422), also on update
        $mk(['expires_on' => $today->addDays(5)->toDateString(), 'full_name_until' => $today->addDays(6)->format('Y-m-d\TH:i')])->assertStatus(422)->assertJsonValidationErrors('full_name_until');
        $this->putJson('/api/notices/lists/'.$a['list']['id'], ['version' => $a['list']['version'], 'full_name_until' => $today->addDays(41)->format('Y-m-d H:i:s')])->assertStatus(422)->assertJsonValidationErrors('full_name_until');
        $this->putJson('/api/notices/lists/'.$a['list']['id'], ['version' => $a['list']['version'], 'full_name_until' => 'ไม่ใช่วันที่'])->assertStatus(422)->assertJsonValidationErrors('full_name_until');
        // before publish_from is refused
        $this->putJson('/api/notices/lists/'.$a['list']['id'], ['version' => $a['list']['version'], 'full_name_until' => $today->subDay()->format('Y-m-d H:i:s')])->assertStatus(422);
        // the admin can shorten it (datetime-local form accepted) and it is audited with the new value
        $short = $today->addDays(3)->setTime(9, 30)->format('Y-m-d\TH:i');
        $u = $this->putJson('/api/notices/lists/'.$a['list']['id'], ['version' => $a['list']['version'], 'full_name_until' => $short])->assertOk()->json();
        $this->assertSame($today->addDays(3)->setTime(9, 30)->format('Y-m-d H:i:s'), $u['list']['full_name_until']);
        $detail = (string) DB::table('audit_events')->where('action', 'notices.updated')->latest('id')->value('detail');
        $this->assertStringContainsString('full_name_until', $detail);
        $this->assertStringContainsString($today->addDays(3)->format('Y-m-d').' 09:30:00', $detail);
        $this->assertStringContainsString('full_name_until', (string) DB::table('audit_events')->where('action', 'notices.created')->latest('id')->value('detail'));
        // shortening expires_on below the stored window is refused instead of silently keeping an illegal value
        $this->putJson('/api/notices/lists/'.$a['list']['id'], ['version' => $u['list']['version'], 'expires_on' => $today->addDays(1)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('full_name_until');
        // only with name_mode full: a masked mode refuses a date and drops the stored one
        $this->putJson('/api/notices/lists/'.$a['list']['id'], ['version' => $u['list']['version'], 'name_mode' => 'first_initial', 'full_name_until' => $short])->assertStatus(422);
        $m = $this->putJson('/api/notices/lists/'.$a['list']['id'], ['version' => $u['list']['version'], 'name_mode' => 'first_initial'])->assertOk()->json();
        $this->assertNull($m['list']['full_name_until']);
    }

    public function test_admin_view_never_returns_a_full_name_and_describes_the_public_view(): void
    {
        $this->turnstileOn();
        $today = NoticeRepository::today();
        $n = $this->postJson('/api/notices/lists', ['title' => 'ประกาศ', 'publish_from' => $today, 'expires_on' => date('Y-m-d', strtotime('+30 days')), 'columns' => ['order_no', 'display_name'], 'name_mode' => 'full'])->assertCreated()->json();
        $text = "ลำดับ\tชื่อ-สกุล\n".implode("\n", array_map(fn ($i, $name) => ($i + 1)."\t".$name, array_keys(self::NAMES), self::NAMES));
        $r = $this->putJson('/api/notices/lists/'.$n['list']['id'].'/rows', ['version' => $n['list']['version'], 'text' => $text])->assertOk();
        $this->assertNoNames(json_encode($r->json(), JSON_UNESCAPED_UNICODE), 'admin rows response');
        $read = $this->getJson('/api/notices/lists/'.$n['list']['id'])->assertOk();
        $this->assertNoNames(json_encode($read->json(), JSON_UNESCAPED_UNICODE), 'admin read');
        $this->assertSame('นายอรรถพลมาร์ค ส.', $read->json('preview.0.display_name'));
        $this->assertSame('full_after_turnstile', $read->json('public_view.now'));
        $this->assertSame('first_initial', $read->json('public_view.after'));
        $this->assertNotNull($read->json('public_view.until'));
        $this->assertNoNames(json_encode($this->getJson('/api/notices/lists')->json(), JSON_UNESCAPED_UNICODE), 'admin index');
        // the list can be published with Turnstile on
        $this->postJson('/api/notices/lists/'.$n['list']['id'].'/status', ['version' => $read->json('list.version'), 'action' => 'publish'])->assertOk();
    }

    // ---------------------------------------------------------------- public: initial HTML and token flow

    public function test_plain_get_of_a_full_name_notice_has_no_name_and_curl_yields_nothing(): void
    {
        $this->turnstileOn();
        [, $slug] = $this->makeList();
        $this->guest();
        $r = $this->get("/portal/notices/$slug")->assertOk();
        $html = $r->getContent();
        $this->assertNoNames($html, 'initial HTML');
        foreach (self::NAMES as $n) $this->assertStringNotContainsString(explode(' ', $n)[0], $html, 'neither the first name nor a masked name is in the HTML');
        $this->assertStringContainsString('ผลการจัดห้องเรียน โรงเรียนต้นธารวิทยา', $html);
        $this->assertStringContainsString('ตรวจสอบรายชื่อ', $html);
        $this->assertStringContainsString('7 รายการ', $html);
        $this->assertStringContainsString('data-sitekey="site-demo"', $html);
        $this->assertStringContainsString('id="notice-turnstile"', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('secret-demo', $html);
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'));
        // no download of any kind
        foreach (['csv', 'xlsx', 'pdf', 'export', 'download'] as $x) $this->get("/portal/notices/$slug/$x")->assertNotFound();
        // the page without Turnstile configured names nothing either (fails closed)
        config(['saan.turnstile.site_key' => '', 'saan.turnstile.secret' => '']);
        $off = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertNoNames($off, 'turnstile off');
        $this->assertStringNotContainsString('data-sitekey', $off);
    }

    public function test_token_flow_delivers_full_names_as_json_with_safe_headers(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        [, $slug] = $this->makeList();
        $this->guest();
        $sid = $this->sid();
        $c = $this->pcall($slug, 'challenge', ['cf-turnstile-response' => 'good'], $sid)->assertOk();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $c->json('token'));
        $this->assertSame(120, $c->json('expires_in'));
        $r = $this->pcall($slug, 'rows', ['token' => $c->json('token')], $sid)->assertOk();
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'));
        $this->assertSame(array_column($r->json('rows'), 'display_name'), self::NAMES);
        $this->assertSame(['order_no', 'display_name'], array_column($r->json('columns'), 'key'));
        // the server really asked Turnstile, without the visitor's address
        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://challenges.cloudflare.com/turnstile/v0/siteverify') && $req['secret'] === 'secret-demo' && $req['response'] === 'good' && !isset($req['remoteip']));
        // no phone, no id number, only the enabled columns
        $this->assertSame(['order_no', 'display_name'], array_keys($r->json('rows.0')));
    }

    public function test_rows_endpoint_refuses_missing_reused_expired_foreign_session_and_foreign_notice_tokens(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        [, $a] = $this->makeList();
        [, $b] = $this->makeList();
        $this->guest();
        $sid = $this->sid();
        $generic = 'ไม่สามารถแสดงรายชื่อได้ในขณะนี้';
        // no token / garbage
        $this->pcall($a, 'rows', [], $sid)->assertStatus(403)->assertJsonPath('message', fn ($m) => str_contains($m, $generic));
        $this->pcall($a, 'rows', ['token' => 'abc'], $sid)->assertStatus(403);
        $this->pcall($a, 'rows', ['token' => str_repeat('a', 64)], $sid)->assertStatus(403);
        // reused: the second use fails, and so does a parallel replay
        $t = $this->token($a, $sid);
        $this->pcall($a, 'rows', ['token' => $t], $sid)->assertOk();
        $this->pcall($a, 'rows', ['token' => $t], $sid)->assertStatus(403);
        // expired after 2 minutes
        $t = $this->token($a, $sid);
        $this->travel(121)->seconds();
        $this->pcall($a, 'rows', ['token' => $t], $sid)->assertStatus(403);
        $this->travelBack();
        // another session
        $t = $this->token($a, $sid);
        $this->pcall($a, 'rows', ['token' => $t], $this->sid())->assertStatus(403);
        $this->pcall($a, 'rows', ['token' => $t], $sid)->assertStatus(403); // burned by the failed attempt
        // another notice
        $t = $this->token($a, $sid);
        $this->pcall($b, 'rows', ['token' => $t], $sid)->assertStatus(403);
        // the token is stored hashed: the cache does not hold the plain value
        $t = $this->token($a, $sid);
        $this->assertFalse(\Cache::has('notice-token:'.$t));
        $this->assertTrue(\Cache::has('notice-token:'.hash('sha256', $t)));
        // the right session and notice still work with a fresh token
        $this->pcall($a, 'rows', ['token' => $t], $sid)->assertOk();
    }

    public function test_the_token_can_be_claimed_only_once_atomically(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        [, $slug] = $this->makeList();
        $this->guest();
        $sid = $this->sid();
        $t = $this->token($slug, $sid);
        // simulate the loser of a race: the "used" claim already exists -> refused even though the record is still there
        \Cache::add('notice-token:'.hash('sha256', $t).':used', 1, 60);
        $this->pcall($slug, 'rows', ['token' => $t], $sid)->assertStatus(403);
    }

    public function test_turnstile_failure_or_missing_config_is_refused_without_a_token(): void
    {
        $this->turnstileOn();
        [, $slug] = $this->makeList();
        $this->guest();
        $sid = $this->sid();
        $this->fakeTurnstile(false);
        $this->pcall($slug, 'challenge', ['cf-turnstile-response' => 'bad'], $sid)->assertStatus(403)->assertJsonMissingPath('token');
        $this->pcall($slug, 'challenge', [], $sid)->assertStatus(403);
        Http::fake(['challenges.cloudflare.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);
        $this->pcall($slug, 'challenge', ['cf-turnstile-response' => 'x'], $sid)->assertStatus(403);
        // Turnstile not configured: strict mode never passes (the old verify() would)
        config(['saan.turnstile.site_key' => '', 'saan.turnstile.secret' => '']);
        $this->fakeTurnstile();
        $this->pcall($slug, 'challenge', ['cf-turnstile-response' => 'good'], $sid)->assertStatus(403);
        $this->assertTrue(\App\Support\Saan\Turnstile::verify('anything'));
        $this->assertFalse(\App\Support\Saan\Turnstile::strict('anything'));
        // the complaints class is a thin alias of the shared one
        $this->turnstileOn();
        $this->assertTrue(ComplaintsTurnstile::enabled());
        $this->assertSame('site-demo', ComplaintsTurnstile::siteKey());
        $this->fakeTurnstile(false);
        $this->assertFalse(ComplaintsTurnstile::verify('t'));
    }

    public function test_endpoints_refuse_masked_lists_and_unknown_slugs(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        [, $slug] = $this->makeList(['name_mode' => 'first_initial', 'full_name_until' => null]);
        $this->guest();
        $sid = $this->sid();
        // masked list: current behaviour, names (masked) are in the page, endpoints give nothing
        $html = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertStringContainsString('นายอรรถพลมาร์ค ส.', $html);
        $this->assertNoNames($html, 'masked list');
        $this->pcall($slug, 'challenge', ['cf-turnstile-response' => 'good'], $sid)->assertStatus(403);
        $this->pcall($slug, 'rows', ['token' => 'x'], $sid)->assertStatus(403);
        $this->pcall('no-such-list', 'challenge', [], $sid)->assertNotFound();
    }

    public function test_access_code_is_checked_first_for_a_full_name_list(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        [$id, $slug] = $this->makeList(['access_code_hash' => NoticeRepository::hashCode('รหัสลับ-demo-2569')]);
        $this->guest();
        $sid = $this->sid();
        // locked: only the unlock form, no count of names, no gate
        $html = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertStringContainsString('รหัสเข้าดูรายชื่อ', $html);
        $this->assertStringNotContainsString('id="notice-turnstile"', $html);
        $this->assertNoNames($html);
        $this->pcall($slug, 'challenge', ['cf-turnstile-response' => 'good'], $sid)->assertStatus(403);
        // unlocked session: the gate page appears and the token flow works
        $list = DB::table('notice_lists')->find($id);
        $this->seedSession($sid, ['notice_unlock.'.$id => NoticeRepository::unlockToken($list)]);
        $page = $this->withCookie((string) config('session.cookie'), $sid)->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertStringContainsString('id="notice-turnstile"', $page);
        $this->assertNoNames($page);
        $this->rowsOf($slug, $sid)->assertOk();
    }

    // ---------------------------------------------------------------- rate limits

    public function test_rate_limit_per_64_per_48_and_per_notice_counts_every_request(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        $this->guest();
        $msg = 'ไม่สามารถแสดงรายชื่อได้ในขณะนี้';
        // per /64: addresses inside one /64 share the budget
        config(['saan.notices.per_net' => 3, 'saan.notices.per_wide' => 1000, 'saan.notices.per_list' => 1000]);
        [, $a] = $this->makeList();
        foreach (['2001:db8:aa:1::1', '2001:db8:aa:1::2', '2001:db8:aa:1:ffff::3'] as $ip) $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), $ip)->assertOk();
        $r = $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), '2001:db8:aa:1::9')->assertStatus(429);
        $this->assertStringContainsString($msg, $r->json('message'));
        // the refused request was counted too (a bot cannot probe for free): a request that would be refused for another reason also counts
        $this->pcall($a, 'rows', [], $this->sid(), '2001:db8:aa:1::9')->assertStatus(429);
        // another /64 inside the same /48 is not limited by the /64 budget
        $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), '2001:db8:aa:2::1')->assertOk();

        // per /48
        \Cache::flush();
        config(['saan.notices.per_net' => 1000, 'saan.notices.per_wide' => 3, 'saan.notices.per_list' => 1000]);
        foreach (['2001:db8:bb:1::1', '2001:db8:bb:2::1', '2001:db8:bb:3::1'] as $ip) $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), $ip)->assertOk();
        $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), '2001:db8:bb:4::1')->assertStatus(429);
        $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), '2001:db8:cc:1::1')->assertOk(); // another /48

        // per notice: different networks, same list; another list is not affected
        \Cache::flush();
        config(['saan.notices.per_net' => 1000, 'saan.notices.per_wide' => 1000, 'saan.notices.per_list' => 3]);
        [, $b] = $this->makeList();
        foreach (['198.51.100.1', '198.51.101.1', '198.51.102.1'] as $ip) $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), $ip)->assertOk();
        $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), '198.51.103.1')->assertStatus(429);
        $this->pcall($b, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), '198.51.103.1')->assertOk();
    }

    // ---------------------------------------------------------------- expiry (combined: one database)

    public function test_after_full_name_until_the_notice_shows_masked_names_and_the_full_list_is_refused(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        [, $slug] = $this->makeList(['full_name_until' => self::until('+2 hours')]);
        $this->guest();
        $sid = $this->sid();
        $this->assertSame(self::NAMES, array_column($this->rowsOf($slug, $sid)->assertOk()->json('rows'), 'display_name'));
        $this->travel(3)->hours();
        // not gated any more: the normal page with first_initial names
        $html = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertNoNames($html, 'after expiry');
        $this->assertStringContainsString('นายอรรถพลมาร์ค ส.', $html);
        $this->assertStringContainsString('<table', $html);
        $this->pcall($slug, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid())->assertStatus(403);
        $this->pcall($slug, 'rows', ['token' => 'x'], $this->sid())->assertStatus(403);
        // a legacy full list without any date counts as expired: masked
        [, $old] = $this->makeList(['full_name_until' => null]);
        $this->assertNoNames($this->get("/portal/notices/$old")->assertOk()->getContent(), 'legacy full list');
    }

    // ---------------------------------------------------------------- search-own-name mode

    public function test_search_mode_limits_results_and_refuses_short_queries_and_the_full_list(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        config(['saan.notices.search_session' => 100, 'saan.notices.per_net' => 500, 'saan.notices.per_wide' => 500]);
        [, $slug] = $this->makeList(['search_only' => 1]);
        $this->guest();
        $sid = $this->sid();
        $html = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertNoNames($html, 'search page');
        $this->assertStringContainsString('id="notice-search"', $html);
        $this->assertStringContainsString('7 รายการ', $html);
        $this->assertStringNotContainsString('<table', $html);
        $search = fn (string $q, ?string $s = null) => $this->pcall($slug, 'search', ['token' => $this->token($slug, $s ?? $sid), 'q' => $q], $s ?? $sid);

        // at most 5 of the 7 matches, with a hint that there are more
        $r = $search('มาร์ค')->assertOk();
        $this->assertCount(5, $r->json('rows'));
        $this->assertTrue($r->json('more'));
        // a precise query returns exactly that row (full name while the window is open)
        $r = $search('ภูริทัต')->assertOk();
        $this->assertSame(['เด็กชายภูริทัตมาร์ค แก้วมณี'], array_column($r->json('rows'), 'display_name'));
        $this->assertFalse($r->json('more'));
        // zero-width characters, spaces and case are normalised
        $this->assertCount(1, $search("ภูริ\u{200B}ทัต")->assertOk()->json('rows'));
        $this->assertCount(1, $search("  ภูริทัต\u{FEFF}  ")->assertOk()->json('rows'));
        // the row order (1..N) is NOT a search key: a list without an applicant / seat number column has no number search at all
        foreach (['4', '๓', '99', '0004', '1234'] as $q) $search($q, null)->assertStatus(422);
        // too short, title only, empty, too long, not a string: 422 and the token stays usable
        $t = $this->token($slug, $sid);
        foreach (['อร', 'ภู', '  ', "\u{200B}\u{200B}\u{200B}", 'นาย', 'เด็กชาย'] as $q) {
            $this->pcall($slug, 'search', ['token' => $t, 'q' => $q], $sid)->assertStatus(422);
        }
        $this->pcall($slug, 'search', ['token' => $t, 'q' => str_repeat('ก', 200)], $sid)->assertStatus(422);
        $this->pcall($slug, 'search', ['token' => $t, 'q' => ['a']], $sid)->assertStatus(422);
        $this->pcall($slug, 'search', ['token' => $t, 'q' => 'ภูริทัต'], $sid)->assertOk();
        // search without a token is refused
        $this->pcall($slug, 'search', ['q' => 'ภูริทัต'], $sid)->assertStatus(403);
        // the full-list endpoint is refused in this mode even with a valid token
        $this->pcall($slug, 'rows', ['token' => $this->token($slug, $sid)], $sid)->assertStatus(403);
        // normalisation unit checks
        $this->assertSame('123', NoticeSearch::normalize("๑๒\u{200C}๓"));
        $this->assertNull(NoticeSearch::parse('ab'));
        $this->assertNull(NoticeSearch::parse('๑๒'), 'a number needs the applicant column');
        $this->assertNull(NoticeSearch::parse('๑๒', true), 'and at least '.NoticeSearch::MIN_DIGITS.' digits');
        $this->assertSame(['kind' => 'number', 'value' => '20417'], NoticeSearch::parse('๒๐๔๑๗', true));
        $this->assertNull(NoticeSearch::parse(str_repeat('1', NoticeSearch::MAX_DIGITS + 1), true));
    }

    public function test_search_by_number_matches_only_the_applicant_number_column_exactly(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        config(['saan.notices.search_session' => 100, 'saan.notices.per_net' => 500, 'saan.notices.per_wide' => 500]);
        [$id, $slug] = $this->makeList(['search_only' => 1, 'columns' => json_encode(['order_no', 'display_name', 'applicant_no'])]);
        $nos = ['20417', '31952', '48206', '57391', '60825', '71468', '89034'];
        foreach (DB::table('notice_rows')->where('list_id', $id)->orderBy('order_no')->get() as $i => $r) DB::table('notice_rows')->where('id', $r->id)->update(['applicant_no' => $nos[$i]]);
        $this->guest();
        $sid = $this->sid();
        $search = fn (string $q) => $this->pcall($slug, 'search', ['token' => $this->token($slug, $sid), 'q' => $q], $sid);
        $html = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertStringContainsString('เลขที่สมัคร/เลขที่นั่งสอบ', $html);
        // exact match, Thai digits too
        $r = $search('48206')->assertOk();
        $this->assertSame(['48206'], array_column($r->json('rows'), 'applicant_no'));
        $this->assertSame([\Saan\Modules\WebNotices\NoticeSearch::HIDDEN_NAME], array_column($r->json('rows'), 'display_name'), 'a bare number returns no part of the name');
        $this->assertStringNotContainsString('กัลยา', $r->getContent(), 'nothing a bot could feed into the number + name query');
        // number plus part of the name (second factor, either order) returns the cells as shown; a wrong name returns nothing
        foreach (['48206 กัลยา', 'กัลยา 48206'] as $two) $this->assertSame(['นางสาวกัลยามาร์ค ทองประเสริฐ'], array_column($search($two)->assertOk()->json('rows'), 'display_name'), $two);
        $this->assertSame([], $search('48206 สมชาย')->assertOk()->json('rows'));
        $this->assertSame(['89034'], array_column($search('๘๙๐๓๔')->assertOk()->json('rows'), 'applicant_no'));
        // the row order is no key, a partial or unknown number finds nothing, and a short number is refused
        $this->assertSame([], $search('4820')->assertOk()->json('rows'));
        $this->assertSame([], $search('99999')->assertOk()->json('rows'));
        $search('1')->assertStatus(422);
        $search('482')->assertStatus(422);
        $this->assertSame([], $search('0001')->assertOk()->json('rows'));
        // no column in the list: the same number is refused outright
        [, $plain] = $this->makeList(['search_only' => 1]);
        $this->assertStringNotContainsString('เลขที่สมัคร', $this->get("/portal/notices/$plain")->assertOk()->getContent());
        $this->pcall($plain, 'search', ['token' => $this->token($plain, $sid), 'q' => '48206'], $sid)->assertStatus(422);
    }

    public function test_the_public_script_loads_a_list_once_and_never_loops_on_turnstile_resets(): void
    {
        $node = (new \Symfony\Component\Process\ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('node is not installed');
        $p = new \Symfony\Component\Process\Process([$node, base_path('tests/js/notices-public.harness.mjs'), base_path('public/assets/modules/web-notices/notices-public.js')]);
        $p->setTimeout(60)->run();
        $out = json_decode(trim($p->getOutput()), true);
        $this->assertIsArray($out, $p->getOutput().$p->getErrorOutput());
        $this->assertSame([], $out['failures'], json_encode($out['log'], JSON_UNESCAPED_UNICODE));
        $this->assertTrue($p->isSuccessful());
    }

    public function test_the_row_parser_reads_and_validates_the_applicant_number_column(): void
    {
        $cols = ['order_no', 'display_name', 'applicant_no'];
        $rows = \Saan\Modules\WebNotices\RowParser::parse("เลขที่สมัคร\tชื่อ-สกุล\n๒๐๔๑๗\t".self::NAMES[0]."\n31952\t".self::NAMES[1], $cols);
        $this->assertSame(['20417', '31952'], array_column($rows, 'applicant_no'));
        $this->assertSame([1, 2], array_column($rows, 'order_no'), 'order_no is only the display order');
        foreach (["1234567890123\t".self::NAMES[0], "12\t".self::NAMES[0], "ab1234\t".self::NAMES[0]] as $bad) {
            try { \Saan\Modules\WebNotices\RowParser::parse("เลขที่สมัคร\tชื่อ-สกุล\n".$bad, $cols); $this->fail('accepted '.$bad); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('text', $e->errors()); }
        }
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        \Saan\Modules\WebNotices\RowParser::parse("เลขที่สมัคร\tชื่อ-สกุล\n20417\t".self::NAMES[0]."\n20417\t".self::NAMES[1], $cols);
    }

    // ---------------------------------------------------------------- review fixes: budgets, legacy full lists, expired windows

    public function test_the_per_list_budget_is_spent_only_by_requests_that_passed_turnstile(): void
    {
        $this->turnstileOn();
        $this->guest();
        config(['saan.notices.per_net' => 1000, 'saan.notices.per_wide' => 1000, 'saan.notices.per_list' => 3]);
        [, $a] = $this->makeList();
        // junk (no Turnstile response, failed Turnstile, bad tokens) from many networks must not close the notice for everybody
        Http::fake(['challenges.cloudflare.com/*' => fn (\Illuminate\Http\Client\Request $req) => Http::response(['success' => ($req['response'] ?? '') === 'good'], 200)]);
        foreach (range(1, 12) as $i) {
            $this->pcall($a, 'challenge', [], $this->sid(), '198.51.100.'.$i)->assertStatus(403);
            $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'bad'], $this->sid(), '198.51.101.'.$i)->assertStatus(403);
            $this->pcall($a, 'rows', ['token' => str_repeat('a', 64)], $this->sid(), '198.51.102.'.$i)->assertStatus(403);
        }
        // a real visitor still gets in; only passed requests count (3 here), the fourth passed request is refused
        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), $ip)->assertOk();
        $this->pcall($a, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid(), '203.0.113.4')->assertStatus(429);
        // the network budgets still count every request, passed or not (a bot is stopped per address)
        \Cache::flush();
        config(['saan.notices.per_net' => 3, 'saan.notices.per_list' => 1000]);
        foreach (range(1, 3) as $i) $this->pcall($a, 'challenge', [], $this->sid(), '203.0.113.50')->assertStatus(403);
        $this->pcall($a, 'challenge', [], $this->sid(), '203.0.113.50')->assertStatus(429);
        // the shipped defaults leave room for a results day (see config/saan.php)
        $cfg = require base_path('config/saan.php');
        $this->assertGreaterThanOrEqual(6000, $cfg['notices']['per_list']);
        $this->assertGreaterThanOrEqual(60, $cfg['notices']['per_net']);
    }

    public function test_editing_the_title_of_a_legacy_full_list_does_not_open_full_names(): void
    {
        $this->turnstileOn();
        [$id, ] = $this->makeList(['full_name_until' => null, 'status' => 'draft']);
        $l = DB::table('notice_lists')->find($id);
        $r = $this->putJson('/api/notices/lists/'.$id, ['version' => $l->version, 'title' => 'ผลการจัดห้องเรียน (แก้ไขชื่อเรื่อง)'])->assertOk()->json();
        $this->assertNull(DB::table('notice_lists')->where('id', $id)->value('full_name_until'), 'a title edit must not create a window');
        $this->assertFalse($r['list']['full_active']);
        $this->assertNull($r['list']['full_name_until']);
        // saving the whole form (name_mode full, no date: the admin screen leaves the empty field out) keeps it expired too
        $r = $this->putJson('/api/notices/lists/'.$id, ['version' => $r['list']['version'], 'name_mode' => 'full', 'title' => 'ผลการจัดห้องเรียน'])->assertOk()->json();
        $this->assertNull(DB::table('notice_lists')->where('id', $id)->value('full_name_until'));
        // only an explicit date opens it, and that needs Turnstile
        $until = self::until('+2 days');
        config(['saan.turnstile.site_key' => '', 'saan.turnstile.secret' => '']);
        $this->putJson('/api/notices/lists/'.$id, ['version' => $r['list']['version'], 'full_name_until' => $until])->assertStatus(422)->assertJsonValidationErrors('name_mode');
        $this->assertNull(DB::table('notice_lists')->where('id', $id)->value('full_name_until'));
        $this->turnstileOn();
        $r = $this->putJson('/api/notices/lists/'.$id, ['version' => $r['list']['version'], 'full_name_until' => $until])->assertOk()->json();
        $this->assertSame($until, $r['list']['full_name_until']);
        // leaving full mode ends the running window, so coming back is refused; the default window applies only when a list that never had one BECOMES full
        $m = $this->putJson('/api/notices/lists/'.$id, ['version' => $r['list']['version'], 'name_mode' => 'first_initial'])->assertOk()->json();
        $this->putJson('/api/notices/lists/'.$id, ['version' => $m['list']['version'], 'name_mode' => 'full'])->assertStatus(422);
        [$id3, ] = $this->makeList(['name_mode' => 'first_initial', 'full_name_until' => null, 'status' => 'draft']);
        $f = $this->putJson('/api/notices/lists/'.$id3, ['version' => 1, 'name_mode' => 'full'])->assertOk()->json();
        $this->assertNotNull($f['list']['full_name_until']);
    }

    public function test_an_expired_full_name_window_cannot_be_reopened_and_bad_dates_are_422_not_500(): void
    {
        $this->turnstileOn();
        [$id, ] = $this->makeList(['full_name_until' => self::until('-2 hours'), 'status' => 'draft']);
        $v = (int) DB::table('notice_lists')->where('id', $id)->value('version');
        $r = $this->putJson('/api/notices/lists/'.$id, ['version' => $v, 'full_name_until' => self::until('+1 day')])->assertStatus(422)->assertJsonValidationErrors('full_name_until');
        $this->assertStringContainsString('สิ้นสุดไปแล้ว', $r->json('errors.full_name_until.0'));
        $this->assertTrue(NoticeRepository::normUntil(self::until('-2 hours')) < NoticeRepository::nowLocal());
        // shortening to another past time is allowed (nothing reopens)
        $this->putJson('/api/notices/lists/'.$id, ['version' => $v, 'full_name_until' => self::until('-1 day')])->assertOk();
        // malformed or impossible date-times: 422, never 500 and never stored
        [$id2, ] = $this->makeList(['full_name_until' => self::until('+1 day'), 'status' => 'draft']);
        foreach (['2026-11-20 25:00', '2026-02-30 10:00', '2026-11-20 10:00 junk', '2026-11-20 10:61', '2026-13-01 10:00'] as $bad) {
            $this->putJson('/api/notices/lists/'.$id2, ['version' => 1, 'full_name_until' => $bad])->assertStatus(422)->assertJsonValidationErrors('full_name_until');
        }
        $this->assertSame('2026-11-20 10:30:00', NoticeRepository::normUntil('2026-11-20T10:30'));
        $this->assertSame('2026-11-20 10:30:15', NoticeRepository::normUntil('2026-11-20 10:30:15'));
        foreach (['2026-02-30 10:00', '2026-11-20 25:00', '2026-11-20 10:00x', '', null, 'x'] as $bad) $this->assertNull(NoticeRepository::normUntil($bad), (string) $bad);
        // the repository itself (not only the HTTP validator) refuses an unreadable value
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new NoticeRepository())->update($id2, ['version' => 1, 'full_name_until' => '2026-02-30 10:00']);
    }

    public function test_an_expired_window_cannot_be_reopened_through_a_masked_mode_detour(): void
    {
        $this->turnstileOn();
        [$id, ] = $this->makeList(['full_name_until' => self::until('-2 hours'), 'status' => 'draft']);
        $v = fn () => (int) DB::table('notice_lists')->where('id', $id)->value('version');
        $this->putJson('/api/notices/lists/'.$id, ['version' => $v(), 'name_mode' => 'first_initial'])->assertOk();
        $this->assertNull(DB::table('notice_lists')->where('id', $id)->value('full_name_until'));
        $this->assertNotNull(DB::table('notice_lists')->where('id', $id)->value('full_name_spent_until')); // the ended window is remembered
        // back to full: the default window would be in the future, so it is refused, and nothing is stored
        $this->putJson('/api/notices/lists/'.$id, ['version' => $v(), 'name_mode' => 'full'])->assertStatus(422)->assertJsonValidationErrors('name_mode');
        $this->putJson('/api/notices/lists/'.$id, ['version' => $v(), 'name_mode' => 'full', 'full_name_until' => self::until('+1 day')])->assertStatus(422)->assertJsonValidationErrors('full_name_until');
        $row = DB::table('notice_lists')->where('id', $id)->first();
        $this->assertSame('first_initial', $row->name_mode);
        $this->assertNull($row->full_name_until);
        // leaving full mode ends the window (masked cells may already be public): re-entering with a new future end is refused, even while the old end was still ahead
        [$id2, ] = $this->makeList(['full_name_until' => self::until('+2 days'), 'status' => 'draft']);
        $v2 = fn () => (int) DB::table('notice_lists')->where('id', $id2)->value('version');
        $this->putJson('/api/notices/lists/'.$id2, ['version' => $v2(), 'name_mode' => 'first_initial'])->assertOk();
        $this->putJson('/api/notices/lists/'.$id2, ['version' => $v2(), 'name_mode' => 'full'])->assertStatus(422)->assertJsonValidationErrors('name_mode');
        $this->assertNull(DB::table('notice_lists')->where('id', $id2)->value('full_name_until'));
    }

    public function test_shortening_the_window_into_the_past_ends_it_for_good(): void
    {
        $this->turnstileOn();
        [$id, ] = $this->makeList(['full_name_until' => self::until('+5 days'), 'full_name_spent_until' => self::until('+5 days'), 'status' => 'draft']);
        $v = fn () => (int) DB::table('notice_lists')->where('id', $id)->value('version');
        $this->putJson('/api/notices/lists/'.$id, ['version' => $v(), 'full_name_until' => self::until('-1 hour')])->assertOk()->assertJsonPath('list.full_active', false);
        $this->assertTrue(NoticeRepository::normUntil(DB::table('notice_lists')->where('id', $id)->value('full_name_spent_until')) <= NoticeRepository::nowLocal());
        $r = $this->putJson('/api/notices/lists/'.$id, ['version' => $v(), 'full_name_until' => self::until('+3 days')])->assertStatus(422)->assertJsonValidationErrors('full_name_until');
        $this->assertStringContainsString('สิ้นสุดไปแล้ว', $r->json('errors.full_name_until.0'));
    }

    public function test_a_window_shortened_to_a_near_future_end_cannot_be_extended_after_that_end_passed(): void
    {
        $this->turnstileOn();
        [$id, ] = $this->makeList(['full_name_until' => self::until('+5 days'), 'full_name_spent_until' => self::until('+5 days'), 'status' => 'draft']);
        $v = fn () => (int) DB::table('notice_lists')->where('id', $id)->value('version');
        // shortened to 10 minutes ahead: still open, the forward-only mark stays at +5 days
        $this->putJson('/api/notices/lists/'.$id, ['version' => $v(), 'full_name_until' => self::until('+10 minutes')])->assertOk()->assertJsonPath('list.full_active', true);
        $this->travel(20)->minutes();
        $r = $this->putJson('/api/notices/lists/'.$id, ['version' => $v(), 'full_name_until' => self::until('+3 days')])->assertStatus(422)->assertJsonValidationErrors('full_name_until');
        $this->assertStringContainsString('สิ้นสุดไปแล้ว', $r->json('errors.full_name_until.0'));
        $this->assertTrue(NoticeRepository::normUntil(DB::table('notice_lists')->where('id', $id)->value('full_name_until')) <= NoticeRepository::nowLocal());
    }

    public function test_a_non_gated_list_with_the_applicant_number_column_renders_instead_of_500(): void
    {
        [$id, $slug] = $this->makeList(['name_mode' => 'first_initial', 'full_name_until' => null, 'columns' => json_encode(['order_no', 'display_name', 'applicant_no'])]);
        DB::table('notice_rows')->where('list_id', $id)->update(['applicant_no' => '10001']);
        $this->guest();
        $this->get('/portal/notices/'.$slug)->assertOk()->assertSee('เลขที่สมัคร/เลขที่นั่งสอบ');
        // an expired full-name window is no longer gated and must not turn the page into a 500 either
        [, $slug2] = $this->makeList(['full_name_until' => self::until('-1 hour'), 'columns' => json_encode(['order_no', 'display_name', 'applicant_no'])]);
        $this->get('/portal/notices/'.$slug2)->assertOk();
    }

    public function test_the_public_page_and_answers_say_full_only_when_the_cells_are_full(): void
    {
        // split: the list says full (+3 days) but the cells arrived masked (peer without capabilities): the visitor is not told "full names"
        $this->internalSplit();
        [, $slug] = $this->makeList(['full_name_until' => self::until('+3 days')]);
        $this->publicSide('pubdb');
        [$pub] = $this->pair();
        $this->assertSame(0, $pub->run(30)['failed']);
        $this->assertSame(7, DB::connection('pubdb')->table('pub_notice_rows')->whereNotNull('full_name_until')->count());
        // what a masked batch (peer without capabilities) leaves behind: masked cells, no window
        foreach (DB::connection('pubdb')->table('pub_notice_rows')->get() as $pr) DB::connection('pubdb')->table('pub_notice_rows')->where('id', $pr->id)->update(['cells' => json_encode(NoticeRepository::guardCells((string) $pr->cells, '1970-01-01 00:00:00', false), JSON_UNESCAPED_UNICODE), 'full_name_until' => null]);
        $this->fakeTurnstile();
        config(['saan.edition' => 'public', 'database.default' => 'pubdb']);
        $this->guest();
        $html = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertStringNotContainsString('แสดงชื่อเต็มถึง', $html);
        $this->assertStringContainsString('ชื่อถูกปิดบังบางส่วน', $html);
        $r = $this->rowsOf($slug, $this->sid())->assertOk();
        $this->assertFalse($r->json('full'));
        $this->assertNoNames(json_encode($r->json('rows'), JSON_UNESCAPED_UNICODE), 'masked cells');
    }

    // ---------------------------------------------------------------- review fixes: version skew between the two sides

    public function test_split_internal_sends_masked_cells_to_a_peer_that_does_not_announce_support(): void
    {
        $this->internalSplit();
        $this->makeList(['full_name_until' => self::until('+3 days')]);
        $this->publicSide('pubdb');
        // an older receiver: it does not know kind 'caps' and answers 400 bad_kind
        $real = new Receiver('pubdb', Keys::publicKey());
        $old = function (string $body, array $headers) use ($real) {
            config(['saan.push.receive_disk' => 'pubfiles']);
            if (($j = json_decode($body, true)) && ($j['kind'] ?? '') === 'caps') return [400, ['error' => 'bad_kind']];
            return $real->handle($body, $headers);
        };
        $pub = new Publisher(null, $old);
        $this->assertSame(0, $pub->run(30)['failed']);
        $this->assertNoNames($this->dump('pubdb'), 'peer without caps gets masked cells only');
        $this->assertSame(0, DB::connection('pubdb')->table('pub_notice_rows')->whereNotNull('full_name_until')->count());
        $this->assertFalse(\App\Support\Saan\Publish\Builder::peerAllowsFullNames());
        // a receiver that announces the capability but has Turnstile off: still masked
        \Cache::flush();
        config(['saan.turnstile.site_key' => '', 'saan.turnstile.secret' => '']);
        $this->assertFalse((new \App\Support\Saan\Publish\Builder())->needsPeerCaps());
    }

    public function test_split_internal_sends_full_cells_once_the_peer_announces_support_and_the_admin_can_see_the_state(): void
    {
        $this->internalSplit();
        [$id, ] = $this->makeList(['full_name_until' => self::until('+3 days')]);
        $this->publicSide('pubdb');
        [$pub] = $this->pair();
        $this->assertSame(0, $pub->run(30)['failed']);
        $this->assertTrue(\App\Support\Saan\Publish\Builder::peerAllowsFullNames());
        $this->assertSame(7, DB::connection('pubdb')->table('pub_notice_rows')->whereNotNull('full_name_until')->count());
        $caps = \App\Support\Saan\Publish\Builder::peerCaps();
        $this->assertTrue($caps['notice_full_name_until']);
        $this->assertTrue($caps['turnstile']);
        $this->assertTrue((new NoticeRepository())->read($id)['public_view']['public_ready']);
        // the peer says it has no Turnstile: nothing full is sent and the admin sees false
        \App\Support\Saan\Publish\Builder::rememberPeerCaps(['notice_full_name_until' => true, 'turnstile' => false]);
        $this->assertFalse((new NoticeRepository())->read($id)['public_view']['public_ready']);
        $built = (new \App\Support\Saan\Publish\Builder())->rows('pub_notice_rows');
        $this->assertCount(7, $built);
        foreach ($built as $b) { $this->assertNull($b['full_name_until']); $this->assertNoNames((string) $b['cells'], 'peer without turnstile'); }
    }

    public function test_split_receiver_without_the_full_name_until_column_masks_instead_of_dropping_the_key(): void
    {
        $this->internalSplit();
        $this->publicSide('pubdb');
        $p = DB::connection('pubdb');
        // a public database where the 2026_11_17_100001 migration has not run
        $p->getSchemaBuilder()->table('pub_notice_rows', function ($t) { $t->dropIndex('pub_notice_rows_full_name_until_index'); $t->dropColumn('full_name_until'); });
        $this->assertFalse($p->getSchemaBuilder()->hasColumn('pub_notice_rows', 'full_name_until'));
        $receiver = new Receiver('pubdb', Keys::publicKey());
        $cap = $receiver->handle(self::signed('caps', []), self::sigHeaders(self::signed('caps', [])));
        $this->assertSame(200, $cap[0]);
        $this->assertFalse($cap[1]['caps']['notice_full_name_until'], 'the receiver announces what it cannot do');
        // a batch that nevertheless carries a full cell with a future window
        $payload = ['stream' => 'pub_notice_rows', 'op' => 'upsert', 'seq' => 1, 'rows' => [['id' => 1, 'school_id' => (int) config('school.school_id'), 'list_id' => 1, 'order_no' => 1,
            'cells' => json_encode(['order_no' => 1, 'display_name' => self::NAMES[0]], JSON_UNESCAPED_UNICODE), 'full_name_until' => self::until('+3 days')]], 'deletes' => [], 'files' => []];
        $body = self::signed('batch', $payload);
        [$st] = $receiver->handle($body, self::sigHeaders($body));
        $this->assertSame(200, $st);
        $stored = (string) $p->table('pub_notice_rows')->value('cells');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('สุขสวัสดิ์', $stored, 'the full name must not be stored where it cannot expire');
        $this->assertStringContainsString('นายอรรถพลมาร์ค ส.', $stored);
    }

    public function test_public_prune_leaves_no_full_name_in_any_row_of_any_table_of_the_public_database(): void
    {
        $this->internalSplit();
        $this->makeList(['full_name_until' => self::until('+3 days')]);
        $this->publicSide('pubdb');
        [$pub] = $this->pair();
        $this->assertSame(0, $pub->run(30)['failed']);
        $database = (string) config('database.connections.pubdb.database');
        $this->assertStringContainsString('สุขสวัสดิ์', $this->tempDbDump($database), 'sanity: the cells are stored while the window is open');
        $this->travel(4)->days();
        $this->assertSame(7, NoticeRepository::maskExpiredPublicRows('pubdb'));
        // every column of every table, read through a separate connection (committed data only)
        $dump = $this->tempDbDump($database);
        foreach (self::SURNAMES as $s) $this->assertStringNotContainsString($s, $dump, 'database level: '.$s);
        $this->assertStringContainsString('pub_notice_rows', $dump, 'the dump really read the public database');
    }

    /** Signed push envelope like the Publisher builds it. */
    private static function signed(string $kind, array $payload): string
    {
        $ts = time();
        $pj = json_encode($payload, JSON_UNESCAPED_UNICODE);
        return '{"ts":'.$ts.',"kind":"'.$kind.'","payload_hash":"'.\App\Support\Saan\Publish\Wire::payloadHash($payload).'","payload":'.($payload === [] ? '[]' : $pj).'}';
    }

    private static function sigHeaders(string $body): array
    {
        $ts = (int) json_decode($body, true)['ts'];
        return ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Signature' => Keys::sign($ts, $body)];
    }

    public function test_search_mode_has_a_per_session_budget_and_masked_names_after_expiry(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        config(['saan.notices.search_session' => 2]);
        [, $slug] = $this->makeList(['search_only' => 1, 'full_name_until' => self::until('+1 hour')]);
        $this->guest();
        $sid = $this->sid();
        $search = fn (string $s) => $this->pcall($slug, 'search', ['token' => $this->token($slug, $s), 'q' => 'ภูริทัต'], $s);
        $search($sid)->assertOk();
        $search($sid)->assertOk();
        $search($sid)->assertStatus(429);
        $search($this->sid())->assertOk(); // another session has its own budget
        // after the window the same search answers with the masked name
        $this->travel(2)->hours();
        $r = $search($this->sid())->assertOk();
        $this->assertSame(['เด็กชายภูริทัตมาร์ค ก.'], array_column($r->json('rows'), 'display_name'));
        $this->assertFalse($r->json('full'));
    }

    public function test_search_only_on_a_masked_list_matches_only_what_is_shown(): void
    {
        $this->turnstileOn();
        $this->fakeTurnstile();
        [, $slug] = $this->makeList(['search_only' => 1, 'name_mode' => 'first_initial', 'full_name_until' => null]);
        $this->guest();
        $sid = $this->sid();
        $html = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertNoNames($html);
        $this->assertStringNotContainsString('นายอรรถพลมาร์ค ส.', $html); // search mode never prints rows in the page
        // a surname the visitor cannot see is not an oracle
        $r = $this->pcall($slug, 'search', ['token' => $this->token($slug, $sid), 'q' => 'แก้วมณี'], $sid)->assertOk();
        $this->assertSame([], $r->json('rows'));
        $r = $this->pcall($slug, 'search', ['token' => $this->token($slug, $sid), 'q' => 'ภูริทัต'], $sid)->assertOk();
        $this->assertSame(['เด็กชายภูริทัตมาร์ค ก.'], array_column($r->json('rows'), 'display_name'));
    }

    // ---------------------------------------------------------------- split editions: public DB holds no full name after expiry

    private function publicSide(string $name): void
    {
        // a fresh copy of a fully migrated database on the suite's server (Tests\Concerns\TempDatabases); dropped at teardown
        $this->migratedTempConnection($name);
        config(['filesystems.disks.pubfiles' => ['driver' => 'local', 'root' => $this->tempDir()]]);
    }

    /** @return array{0:Publisher,1:Receiver} */
    private function pair(): array
    {
        $receiver = new Receiver('pubdb', Keys::publicKey());
        $transport = function (string $body, array $headers) use ($receiver) {
            config(['saan.push.receive_disk' => 'pubfiles']);
            return $receiver->handle($body, $headers);
        };
        return [new Publisher(null, $transport), $receiver];
    }

    /** Every table of the public database as one string (plain and unicode-escaped), for "no full name anywhere" searches. */
    private function dump(string $conn): string
    {
        $plain = $this->tempDbDump((string) config("database.connections.$conn.database"));
        return $plain."\n".json_encode($plain);
    }

    private function internalSplit(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->turnstileOn();
    }

    public function test_split_public_database_carries_full_names_only_until_the_window_ends(): void
    {
        $this->internalSplit();
        [$id, $slug] = $this->makeList(['full_name_until' => self::until('+3 days')]);
        $this->publicSide('pubdb');
        [$pub] = $this->pair();
        $r = $pub->run(30);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $p = DB::connection('pubdb');
        $this->assertSame(7, $p->table('pub_notice_rows')->count());
        $this->assertSame(7, $p->table('pub_notice_rows')->whereNotNull('full_name_until')->count(), 'full cells carry their expiry');
        $this->assertStringContainsString('สุขสวัสดิ์', $this->dump('pubdb'), 'inside the window the public database holds the full cells');
        $this->assertSame(1, $p->table('notice_lists')->count());
        $this->assertNotNull($p->table('notice_lists')->value('full_name_until'));

        // public edition: no name in the HTML, names only through Turnstile + token, from pub_* tables
        $this->fakeTurnstile();
        config(['saan.edition' => 'public', 'database.default' => 'pubdb']);
        $this->guest();
        $html = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertNoNames($html, 'public HTML');
        $this->assertStringContainsString('7 รายการ', $html);
        $sid = $this->sid();
        $this->assertSame(self::NAMES, array_column($this->rowsOf($slug, $sid)->assertOk()->json('rows'), 'display_name'));

        // past the window and BEFORE the public prune: the renderer itself refuses to serve a full cell
        $this->travel(4)->days();
        $this->assertStringContainsString('สุขสวัสดิ์', $this->dump('pubdb'), 'the stored cells are still full here');
        $page = $this->get("/portal/notices/$slug")->assertOk()->getContent();
        $this->assertNoNames($page, 'renderer after expiry');
        $this->assertStringContainsString('นายอรรถพลมาร์ค ส.', $page);
        $this->pcall($slug, 'rows', ['token' => 'x'], $this->sid())->assertStatus(403);
        $this->pcall($slug, 'challenge', ['cf-turnstile-response' => 'good'], $this->sid())->assertStatus(403);
    }

    public function test_split_publisher_sends_masked_rows_once_the_window_passed_and_the_dump_has_no_full_name(): void
    {
        $this->internalSplit();
        [, $slug] = $this->makeList(['full_name_until' => self::until('+3 days')]);
        $this->publicSide('pubdb');
        [$pub] = $this->pair();
        $this->assertSame(0, $pub->run(30)['failed']);
        $this->assertStringContainsString('สุขสวัสดิ์', $this->dump('pubdb'));
        $this->assertSame(0, $pub->run(30)['sent'], 'nothing changes while the window is open');

        // time passes, nobody edits anything: the scheduler tick (timed stream) re-evaluates and sends masked rows
        $this->travel(4)->days();
        $r = $pub->run(30);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $this->assertGreaterThan(0, $r['sent']);
        $dump = $this->dump('pubdb');
        $this->assertNoNames($dump, 'public dump after the publisher ran');
        $this->assertStringContainsString('นายอรรถพลมาร์ค ส.', $dump);
        $this->assertSame(0, DB::connection('pubdb')->table('pub_notice_rows')->whereNotNull('full_name_until')->count());
        $this->assertTrue($pub->verify()['ok'], 'internal and public checksums agree');
        // the internal side itself still has the full names (the original list)
        $this->assertSame(7, DB::table('notice_rows')->where('display_name', 'like', '%มาร์ค%')->count());
    }

    public function test_split_public_prune_masks_full_cells_when_the_internal_side_is_late_or_down(): void
    {
        $this->internalSplit();
        [, $slug] = $this->makeList(['full_name_until' => self::until('+3 days')]);
        $this->publicSide('pubdb');
        [$pub] = $this->pair();
        $this->assertSame(0, $pub->run(30)['failed']);
        $this->assertStringContainsString('สุขสวัสดิ์', $this->dump('pubdb'));

        // the internal side is down from now on: only the public side acts
        config(['saan.edition' => 'public', 'database.default' => 'pubdb']);
        $this->travel(2)->days();
        $this->assertSame(0, Artisan::call('saan:notices-public-prune'), 'window still open: nothing to do');
        $this->assertStringContainsString('สุขสวัสดิ์', $this->dump('pubdb'));
        $this->travel(2)->days();
        $this->assertSame(0, Artisan::call('saan:notices-public-prune'));
        $this->assertStringContainsString('rows_masked=7', Artisan::output());
        $dump = $this->dump('pubdb');
        $this->assertNoNames($dump, 'public dump after the public prune');
        $this->assertStringContainsString('นายอรรถพลมาร์ค ส.', $dump);
        $this->assertSame(0, DB::connection('pubdb')->table('pub_notice_rows')->whereNotNull('full_name_until')->count());
        // idempotent
        $this->assertSame(0, Artisan::call('saan:notices-public-prune'));
        $this->assertStringContainsString('rows_masked=0', Artisan::output());
        // and the public page still works, with masked names, without any internal side
        $this->guest();
        $this->assertStringContainsString('นายอรรถพลมาร์ค ส.', $this->get("/portal/notices/$slug")->assertOk()->getContent());
    }

    public function test_split_receiver_masks_a_batch_that_arrives_after_the_window(): void
    {
        $this->internalSplit();
        $this->makeList(['full_name_until' => self::until('+3 days')]);
        $this->publicSide('pubdb');
        [$pub] = $this->pair();
        // the batch with full cells is built now but delivered only after the window (a stuck queue)
        $pub->reconcile(true);
        $this->travel(4)->days();
        $r = $pub->deliver(30);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $this->assertSame(7, DB::connection('pubdb')->table('pub_notice_rows')->count());
        $dump = $this->dump('pubdb');
        $this->assertNoNames($dump, 'late batch');
        $this->assertSame(0, DB::connection('pubdb')->table('pub_notice_rows')->whereNotNull('full_name_until')->count());
        // unit check of the rule the receiver applies
        $row = NoticeRepository::guardReceivedRow(['id' => '1', 'cells' => json_encode(['order_no' => 1, 'display_name' => self::NAMES[0]], JSON_UNESCAPED_UNICODE), 'full_name_until' => '2000-01-01 00:00:00']);
        $this->assertNull($row['full_name_until']);
        $this->assertStringNotContainsString('สุขสวัสดิ์', $row['cells']);
        $open = ['cells' => '{"display_name":"x"}', 'full_name_until' => self::until('+1 day')];
        $this->assertSame($open, NoticeRepository::guardReceivedRow($open));
    }

    public function test_split_internal_without_turnstile_never_sends_full_cells(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']); // Turnstile off
        $this->makeList(['full_name_until' => self::until('+3 days')]);
        $this->publicSide('pubdb');
        [$pub] = $this->pair();
        $this->assertSame(0, $pub->run(30)['failed']);
        $this->assertNoNames($this->dump('pubdb'), 'no turnstile, no full cells');
        $this->assertSame(0, DB::connection('pubdb')->table('pub_notice_rows')->whereNotNull('full_name_until')->count());
    }

    public function test_split_internal_sends_nothing_of_a_search_only_list_to_a_peer_that_does_not_keep_the_flag(): void
    {
        $this->internalSplit();
        [$id, ] = $this->makeList(['name_mode' => 'first_initial', 'full_name_until' => null, 'search_only' => 1]);
        $this->publicSide('pubdb');
        [$pub] = $this->pair();
        \App\Support\Saan\Publish\Builder::rememberPeerCaps(['notice_full_name_until' => true, 'turnstile' => true]); // an older public side: no notice_search_only
        $this->assertSame(0, $pub->run(30)['failed']);
        $this->assertSame(0, DB::connection('pubdb')->table('pub_notice_rows')->where('list_id', $id)->count(), 'fail closed: no rows for a peer that would show the whole list');
        $this->assertFalse($this->getJson('/api/notices/lists/'.$id)->json('public_view.public_ready') ?? false);
        \App\Support\Saan\Publish\Builder::rememberPeerCaps(['notice_full_name_until' => true, 'turnstile' => true, 'notice_search_only' => true]);
        $this->assertSame(0, $pub->run(30)['failed']);
        $this->assertGreaterThan(0, DB::connection('pubdb')->table('pub_notice_rows')->where('list_id', $id)->count());
    }

    public function test_the_projection_declares_the_new_columns_and_the_module_command_is_registered(): void
    {
        $by = [];
        foreach (\App\Support\Saan\Projection::declared(true, true) as $e) $by[$e['table']] = $e;
        $this->assertSame('keep', $by['pub_notice_rows']['columns']['full_name_until']);
        $this->assertSame('keep', $by['notice_lists']['columns']['full_name_until']);
        $this->assertSame('keep', $by['notice_lists']['columns']['search_only']);
        $this->assertArrayHasKey('saan:notices-public-prune', Artisan::all());
        $this->assertContains('pub_notice_rows', (new \App\Support\Saan\Publish\Builder())->timedStreams());
    }
}
