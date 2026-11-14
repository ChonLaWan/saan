<?php

namespace Tests\Feature;

use App\Domain\Cms\SiteExtras;
use App\Domain\Cms\SiteSettings;
use App\Models\User;
use App\Support\Saan\Inbox;
use App\Support\Saan\Inbox\Handlers;
use App\Support\Saan\Inbox\Puller;
use App\Support\Saan\Inbox\PullServer;
use App\Support\Saan\Projection;
use App\Support\Saan\Publish\Builder;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Pairing;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Publish\Receiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\WebCms\PostViews;
use Tests\TestCase;

/**
 * F5a public-site extras: the news view counter (no personal data, daily salted dedupe held only in the cache, bots and unpublished posts ignored, aggregate
 * envelopes through the sealed inbox with idempotent replay, aggregate projection back to the public edition, retention), the "ข่าวยอดนิยม" block, the chat
 * links (strict validation, nothing third-party before a click, CSP unchanged) and the map block (validation, static placeholder, frame-src only when present).
 */
class SiteExtrasTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tmp = [];
    private const PUSH = 'https://public.test/_saan/push';
    private const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => false, 'school.local_only' => false]);
        $this->seed();
        config(['filesystems.disks.local.root' => $this->tempDir()]); // a private root: the shared Storage::fake directory is wiped by tests running in parallel
        Storage::purge('local');
        Handlers::forget();
        config(['saan.keys_dir' => $this->tempDir()]);
        \App\Support\Saan\Publish\Outbox::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['database.default' => $this->baseConnection, 'saan.inbox_connection' => null, 'saan.edition' => 'combined']);
        Handlers::forget();
        try { DB::purge('pubdb'); } catch (\Throwable) {}
        parent::tearDown();
        gc_collect_cycles();
        foreach ($this->tmp as $p) is_dir($p) ? $this->rrmdir($p) : @unlink($p);
    }

    private function tempDir(): string
    {
        $d = sys_get_temp_dir().'/saan-extras-'.bin2hex(random_bytes(6));
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

    // ---------------------------------------------------------------- helpers

    private function guest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        return $this;
    }

    private function admin(): static
    {
        config(['school.demo' => true]); // the staff API answers a demo install to the local address only
        $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        return $this->actingAs(User::where('role', 'admin')->first());
    }

    private function news(string $slug, array $o = []): int
    {
        return (int) DB::table('posts')->insertGetId($o + ['school_id' => 1, 'author_id' => 1, 'slug' => $slug, 'title' => 'ข่าว '.$slug, 'body' => 'เนื้อข่าวของโรงเรียนต้นธารวิทยา', 'status' => 'published', 'content_type' => 'article',
            'category' => 'news', 'metadata' => json_encode(['source_kind' => 'original']), 'published_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Saves and publishes the site settings (extras and, optionally, the home sections) as the admin, then signs out. */
    private function settings(array $extras, ?array $sections = null): void
    {
        $svc = app(SiteSettings::class);
        $this->admin();
        $cur = $svc->read(1);
        $s = $cur['draft'];
        $s['extras'] = SiteExtras::normalize(array_replace_recursive(SiteExtras::defaults(), $extras));
        if ($sections !== null) $s['homepage_sections'] = $sections;
        $svc->save($s, (int) $cur['version'], true, 'all');
        $this->guest();
    }

    /** One visit of a reader: browser string and address are chosen by the test. */
    private function visit(string $path, string $ua = self::UA, string $ip = '198.51.100.10', array $headers = [])
    {
        config(['school.demo' => false]);
        $this->guest()->flushHeaders();
        return $this->withHeaders(['User-Agent' => $ua] + $headers)->withServerVariables(['REMOTE_ADDR' => $ip])->get($path);
    }

    private function views(int $postId): int
    {
        return (int) DB::table('cms_post_views')->where('post_id', $postId)->sum('n');
    }

    /** @return list<string> every script, iframe, link and img tag of a page, for comparing what a page loads */
    private function loads(string $html): array
    {
        preg_match_all('#<(?:script|iframe|link|img|source|video)\b[^>]*>#i', $html, $m);
        $t = $m[0];
        sort($t);
        return $t;
    }

    private function publicSide(string $name, string $disk): string
    {
        $db = $this->migratedTempConnection($name); // a fresh copy of a fully migrated temporary database, dropped at teardown
        config(["filesystems.disks.$disk" => ['driver' => 'local', 'root' => $this->tempDir()]]);
        return $db;
    }

    private function as(string $edition, ?string $conn = null): void
    {
        config(['saan.edition' => $edition, 'database.default' => $conn ?? $this->baseConnection, 'saan.inbox_connection' => $conn]);
    }

    // ---------------------------------------------------------------- counting rules

    public function test_a_reader_counts_once_per_post_per_day_and_each_post_has_its_own_bucket(): void
    {
        $a = $this->news('alpha');
        $b = $this->news('beta');
        $this->visit('/portal/news/alpha')->assertOk();
        $this->visit('/portal/news/alpha')->assertOk();
        $this->assertSame(1, $this->views($a), 'same network and browser, same day: one count');
        $this->visit('/portal/news/alpha', self::UA.' X', '198.51.100.10')->assertOk();
        $this->assertSame(2, $this->views($a), 'another browser string is another reader');
        $this->visit('/portal/news/alpha', self::UA, '198.51.100.11')->assertOk();
        $this->assertSame(3, $this->views($a), 'another network is another reader');
        $this->visit('/portal/news/beta')->assertOk();
        $this->assertSame(1, $this->views($b), 'a different post has its own count for the same reader');
        $this->assertSame(1, DB::table('cms_post_views')->where('post_id', $a)->count(), 'one row per post per day');
        $this->assertSame(Carbon::now('Asia/Bangkok')->toDateString(), (string) DB::table('cms_post_views')->where('post_id', $a)->value('day'));
        // IPv6 readers of one /64 are one reader
        $this->visit('/portal/news/beta', self::UA, '2001:db8:1:2::1');
        $this->visit('/portal/news/beta', self::UA, '2001:db8:1:2::ffff');
        $this->assertSame(2, $this->views($b));
        // the next Bangkok day the same reader counts again, in a new bucket
        Carbon::setTestNow(Carbon::now('Asia/Bangkok')->addDay());
        $this->visit('/portal/news/alpha')->assertOk();
        $this->assertSame(4, $this->views($a));
        $this->assertSame(2, DB::table('cms_post_views')->where('post_id', $a)->count());
    }

    public function test_robots_prefetches_other_methods_staff_and_unpublished_posts_are_not_counted(): void
    {
        $id = $this->news('gamma');
        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'facebookexternalhit/1.1', 'curl/8.4.0', 'python-requests/2.31', 'Mozilla/5.0 HeadlessChrome/120', 'WhatsApp/2.23', ''] as $ua) {
            $this->visit('/portal/news/gamma', $ua, '198.51.100.20')->assertOk();
        }
        foreach ([['Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch;prerender'], ['X-Moz' => 'prefetch'], ['Sec-Fetch-Dest' => 'image'], ['Sec-Fetch-Dest' => 'iframe']] as $h) {
            $this->visit('/portal/news/gamma', self::UA, '198.51.100.21', $h)->assertOk();
        }
        config(['school.demo' => false]);
        $this->guest()->flushHeaders()->call('HEAD', '/portal/news/gamma', [], [], [], ['REMOTE_ADDR' => '198.51.100.22', 'HTTP_USER_AGENT' => self::UA]);
        $this->assertSame(0, $this->views($id), 'nothing above is a reader');
        // a real document navigation counts
        $this->visit('/portal/news/gamma', self::UA, '198.51.100.23', ['Sec-Fetch-Dest' => 'document'])->assertOk();
        $this->assertSame(1, $this->views($id));
        // signed-in staff reading the live page are not readers
        $this->admin(); config(['school.demo' => false]);
        $this->withHeaders(['User-Agent' => self::UA])->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])->get('/portal/news/gamma')->assertOk();
        $this->assertSame(1, $this->views($id));
        // unpublished, deleted and other-school posts: not found, never counted
        $draft = $this->news('draft-one', ['status' => 'draft']);
        $gone = $this->news('gone-one', ['deleted_at' => now()]);
        $other = $this->news('other-school', ['school_id' => 2]);
        foreach (['draft-one', 'gone-one', 'other-school'] as $slug) $this->visit('/portal/news/'.$slug, self::UA, '198.51.100.25')->assertNotFound();
        $this->assertSame(0, $this->views($draft) + $this->views($gone) + $this->views($other));
        // the counter itself refuses them as well (defence in depth for any other caller)
        $r = \Illuminate\Http\Request::create('/x', 'GET', [], [], [], ['HTTP_USER_AGENT' => self::UA, 'REMOTE_ADDR' => '198.51.100.26']);
        $this->assertFalse(PostViews::record($r, (object) ['id' => $draft, 'status' => 'draft', 'school_id' => 1, 'deleted_at' => null]));
        $this->assertFalse(PostViews::record($r, (object) ['id' => $gone, 'status' => 'published', 'school_id' => 1, 'deleted_at' => '2026-01-01']));
        $this->assertFalse(PostViews::record($r, (object) ['id' => $other, 'status' => 'published', 'school_id' => 2, 'deleted_at' => null]));
        $this->assertTrue(PostViews::record($r, (object) ['id' => $id, 'status' => 'published', 'school_id' => 1, 'deleted_at' => null, 'content_type' => 'article']));
        // another content type is never counted, even for a visitor who would count
        $this->assertFalse(PostViews::record(\Illuminate\Http\Request::create('/x', 'GET', [], [], [], ['HTTP_USER_AGENT' => self::UA, 'REMOTE_ADDR' => '198.51.100.27']), (object) ['id' => $id, 'status' => 'published', 'school_id' => 1, 'deleted_at' => null, 'content_type' => 'page']));
        // the preview route of staff never counts
        $this->admin()->get('/preview/'.$draft)->status();
        $this->assertSame(0, $this->views($draft));
    }

    public function test_no_personal_data_in_any_table_log_or_cache_key_and_the_salt_rotates_daily(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) { $logs[] = $e->message.json_encode($e->context); });
        $id = $this->news('delta');
        $ua = 'Mozilla/5.0 UniqueReaderMarker/9.9 (X11; Linux) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';
        $ip = '203.0.113.77';
        $this->visit('/portal/news/delta', $ua, $ip)->assertOk();
        $this->assertSame(1, $this->views($id));
        // the aggregate row carries no visitor column at all
        $this->assertEqualsCanonicalizing(['id', 'school_id', 'post_id', 'day', 'n', 'sent_n', 'created_at', 'updated_at'], Schema::getColumnListing('cms_post_views'));
        $this->assertEqualsCanonicalizing(['post_id', 'views_7', 'views_30', 'views_total'], Schema::getColumnListing('pub_popular_posts'));
        // no table anywhere holds the address or the browser string
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $t) { // only the suite database: the server also holds temporary databases of other tests
            $dump = json_encode(DB::table($t)->get());
            $this->assertStringNotContainsString('UniqueReaderMarker', $dump, $t);
            $this->assertStringNotContainsString($ip, $dump, $t);
            $this->assertStringNotContainsString(hash('sha256', $ip), $dump, $t);
        }
        $this->assertSame([], array_filter($logs, fn ($l) => str_contains($l, 'UniqueReaderMarker') || str_contains($l, $ip)), 'nothing is logged about a reader');
        // the cache holds only salted hashes, under keys without the address or the browser string, and the salt exists for the current day only
        $store = Cache::getStore();
        $prop = new \ReflectionProperty($store, 'storage');
        $all = $prop->getValue($store);
        $dayKey = Carbon::now('Asia/Bangkok')->toDateString();
        $mine = array_filter(array_keys($all), fn ($k) => str_starts_with($k, 'postviews:') || str_contains($k, 'postviews:'));
        $this->assertNotEmpty($mine);
        foreach ($mine as $k) {
            $this->assertStringNotContainsString($ip, $k);
            $this->assertStringNotContainsString('UniqueReaderMarker', $k);
        }
        $saltKeys = array_values(array_filter($mine, fn ($k) => str_contains($k, 'postviews:salt:')));
        $this->assertCount(1, $saltKeys);
        $this->assertStringContainsString($dayKey, $saltKeys[0]);
        $salt1 = Cache::get('postviews:salt:'.$dayKey);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', (string) $salt1);
        foreach (Schema::getTableListing() as $t) $this->assertStringNotContainsString((string) $salt1, json_encode(DB::table($t)->get()), 'the salt is never stored in a table: '.$t);
        // tomorrow brings a new salt (so yesterday's entries can no longer be matched) and entries expire within a day and a bit
        Carbon::setTestNow(Carbon::now('Asia/Bangkok')->addDay());
        $this->visit('/portal/news/delta', $ua, $ip)->assertOk();
        $day2 = Carbon::now('Asia/Bangkok')->toDateString();
        $salt2 = Cache::get('postviews:salt:'.$day2);
        $this->assertNotSame($salt1, $salt2);
        $this->assertSame(2, $this->views($id));
        $this->assertLessThanOrEqual(90000, 90000);
        $this->assertSame(90000, (new \ReflectionClassConstant(PostViews::class, 'SALT_TTL'))->getValue(), 'salt and entries live 25 hours at most');
    }

    public function test_with_a_file_cache_the_salt_of_a_past_day_does_not_stay_on_the_disk_and_a_new_day_sweeps_it(): void
    {
        $dir = $this->tempDir();
        config(['cache.default' => 'file', 'cache.stores.file.path' => $dir]);
        Cache::forgetDriver('file');
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Bangkok'));
        $id = $this->news('filestore');
        $this->visit('/portal/news/filestore')->assertOk();
        $this->assertSame(1, $this->views($id));
        $files = fn (string $key): array => glob($dir.'/*/*/'.sha1($key)) ?: [];
        $this->assertNotEmpty($files('postviews:salt:2026-11-10'), 'the salt of the day is on the disk');
        // two days later the first reader of the new day makes the salt of that day and removes the old one
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Asia/Bangkok'));
        $this->visit('/portal/news/filestore')->assertOk();
        $this->assertSame(2, $this->views($id));
        $this->assertNotEmpty($files('postviews:salt:2026-11-12'));
        $this->assertSame([], $files('postviews:salt:2026-11-10'), 'no salt of an old day is left on the disk');
        // the daily command sweeps a salt that nobody replaced (a site with no visitors)
        Cache::put('postviews:salt:2026-11-05', 'abc', 90000);
        $this->assertNotEmpty($files('postviews:salt:2026-11-05'));
        $this->assertSame(0, Artisan::call('saan:post-views-prune'));
        $this->assertSame([], $files('postviews:salt:2026-11-05'));
        $this->assertNotEmpty($files('postviews:salt:2026-11-12'), 'today keeps its salt');
        // a separate store for the counter can be named
        config(['saan.views.cache_store' => 'array']);
        $this->visit('/portal/news/filestore', self::UA.' Y')->assertOk();
        $this->assertNotEmpty(Cache::store('array')->get('postviews:salt:2026-11-12'));
    }

    public function test_one_network_cannot_raise_a_post_past_the_daily_ceiling_by_changing_its_browser_string(): void
    {
        $id = $this->news('flood');
        $this->assertSame(5, PostViews::perNet());
        for ($i = 0; $i < 50; $i++) $this->visit('/portal/news/flood', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0.'.$i.' Safari/537.36', '203.0.113.9')->assertOk();
        $this->assertSame(5, $this->views($id), 'fifty browser strings from one address count five times at most');
        $keys = array_filter(array_keys((new \ReflectionProperty(Cache::getStore(), 'storage'))->getValue(Cache::getStore())), fn ($k) => str_contains($k, 'postviews:seen:'));
        $this->assertCount(5, $keys, 'past the ceiling nothing more is stored');
        // a reader that reloads does not use up the ceiling, and another network is not affected
        $this->visit('/portal/news/flood', self::UA, '203.0.113.10');
        $this->visit('/portal/news/flood', self::UA, '203.0.113.10');
        $this->assertSame(6, $this->views($id));
        // another post has its own ceiling for the same network
        $other = $this->news('flood-two');
        $this->visit('/portal/news/flood-two', self::UA, '203.0.113.9')->assertOk();
        $this->assertSame(1, $this->views($other));
        config(['saan.views.count_per_net_day' => 2]);
        $this->assertSame(2, PostViews::perNet());
    }

    public function test_popular_projection_keeps_recent_posts_when_old_posts_fill_the_cap_and_every_count_fits_an_unsigned_int(): void
    {
        config(['saan.views.project_max' => 3]);
        $old = [];
        foreach (['old-a', 'old-b', 'old-c', 'old-d'] as $s) $old[] = $this->news($s, ['published_at' => now()->subDays(400)]);
        $new = $this->news('rising', ['published_at' => now()->subDays(2)]);
        $folded = fn (int $id, int $n) => DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => $id, 'day' => PostViews::FOLDED, 'n' => $n, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($old as $i => $o) $folded($o, 100000 - $i);
        DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => $new, 'day' => PostViews::today(), 'n' => 80, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $rows = collect(PostViews::projectedRows());
        $this->assertCount(3, $rows);
        $this->assertSame($new, $rows->first()['post_id'], 'the post read this week leads, whatever the old totals are');
        $this->assertSame(80, $rows->first()['views_7']);
        $this->assertSame(80, $rows->first()['views_30']);
        // the block on the public side reads it
        $this->settings(['popular' => ['days' => 7, 'limit' => 3]], ['popular']);
        config(['saan.views.project_max' => 20000]);
        $this->assertSame([$new => 80], PostViews::popular(7, 3));
        // no count can leave the unsigned int range: folding saturates, the projection clamps, the largest accepted envelope row is fine
        DB::table('cms_post_views')->delete();
        $max = PostViews::UINT_MAX;
        $folded($new, $max - 5);
        $day1 = Carbon::now('Asia/Bangkok')->subDays(PostViews::retentionDays() + 4)->toDateString();
        DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => $new, 'day' => $day1, 'n' => 1000000, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, PostViews::prune());
        $this->assertSame($max, (int) DB::table('cms_post_views')->where('post_id', $new)->where('day', PostViews::FOLDED)->value('n'));
        $this->assertSame(1, PostViews::receive(['rows' => [['p' => $new, 'day' => PostViews::today(), 'n' => 1000000]]])['rows']);
        $this->assertSame(0, PostViews::receive(['rows' => [['p' => $new, 'day' => PostViews::today(), 'n' => 1000001]]])['rows'], 'one day cannot carry more than a million');
        $row = PostViews::projectedRows()[0];
        $this->assertSame($max, $row['views_total'], 'clamped');
        $this->assertSame(1000000, $row['views_30']);
        foreach (['views_7', 'views_30', 'views_total'] as $k) $this->assertLessThanOrEqual($max, $row[$k]);
    }

    public function test_the_home_page_does_not_query_popular_for_a_block_that_is_only_in_the_draft_and_keeps_the_ranking_for_a_few_minutes(): void
    {
        $a = $this->news('cached-a');
        DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => $a, 'day' => PostViews::today(), 'n' => 3, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        // published list without the block, draft list with it
        $this->settings(['popular' => ['days' => 7, 'limit' => 3]], ['news']);
        $svc = app(SiteSettings::class);
        $this->admin();
        $cur = $svc->read(1);
        $s = $cur['draft'];
        $s['homepage_sections'] = ['popular', 'news'];
        $svc->save($s, (int) $cur['version'], false, 'all');
        $this->guest();
        $queries = fn () => collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'cms_post_views'))->count();
        DB::enableQueryLog();
        $html = $this->visit('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="popular"', $html, 'a draft block is not shown to visitors');
        $this->assertSame(0, $queries(), 'and its query is not run');
        // published: the ranking is computed once, then served from the cache
        $this->settings(['popular' => ['days' => 7, 'limit' => 3]], ['popular', 'news']);
        DB::flushQueryLog();
        $this->assertStringContainsString('id="popular"', $this->visit('/')->getContent());
        $first = $queries();
        $this->assertGreaterThan(0, $first);
        DB::flushQueryLog();
        $this->assertStringContainsString('id="popular"', $this->visit('/', self::UA, '198.51.100.99')->getContent());
        $this->assertSame(0, $queries(), 'the second visitor reads the cached ranking');
        // the staff preview of the draft reads fresh data and shows the draft block
        DB::flushQueryLog();
        $this->admin()->get('/theme-preview')->assertOk();
        $this->assertGreaterThan(0, $queries());
    }

    // ---------------------------------------------------------------- aggregation through the sealed inbox

    public function test_the_handler_is_idempotent_bounded_and_ignores_unknown_or_folded_days(): void
    {
        $id = $this->news('epsilon');
        $day = PostViews::today();
        $p = ['rows' => [['p' => $id, 'day' => $day, 'n' => 5]]];
        $this->assertSame(1, PostViews::receive($p)['rows']);
        PostViews::receive($p); // the same envelope twice
        $this->assertSame(5, $this->views($id));
        PostViews::receive(['rows' => [['p' => $id, 'day' => $day, 'n' => 3]]]); // an older, lower total never lowers it
        $this->assertSame(5, $this->views($id));
        PostViews::receive(['rows' => [['p' => $id, 'day' => $day, 'n' => 9]]]);
        $this->assertSame(9, $this->views($id));
        $other = $this->news('zeta', ['school_id' => 2]);
        $gone = $this->news('eta', ['deleted_at' => now()]);
        $old = Carbon::now('Asia/Bangkok')->subDays(PostViews::retentionDays() + 3)->toDateString();
        $this->assertSame(0, PostViews::receive(['rows' => [['p' => 999999, 'day' => $day, 'n' => 5], ['p' => $other, 'day' => $day, 'n' => 5], ['p' => $gone, 'day' => $day, 'n' => 5],
            ['p' => $id, 'day' => $old, 'n' => 5], ['p' => $id, 'day' => '2999-01-01', 'n' => 5], ['p' => $id, 'day' => $day, 'n' => 0], ['p' => $id, 'day' => $day, 'n' => 999999999999]]])['rows']);
        $this->assertSame(9, $this->views($id));
        $this->assertSame(0, $this->views($other) + $this->views($gone));
        foreach ([['rows' => 'x'], ['rows' => [['p' => 'a', 'day' => $day, 'n' => 1]]], ['rows' => [['p' => $id, 'day' => 'tomorrow', 'n' => 1]]], ['rows' => [['p' => $id, 'day' => '2026-02-31', 'n' => 1]]],
            ['rows' => array_fill(0, 1001, ['p' => $id, 'day' => $day, 'n' => 1])], ['rows' => ['k' => ['p' => $id, 'day' => $day, 'n' => 1]]], []] as $bad) {
            try { PostViews::receive($bad); $this->fail('accepted '.json_encode($bad)); } catch (Inbox\InboxRejected) { $this->assertTrue(true); }
        }
        $this->assertSame(['status' => 'ok', 'rows' => 1], Handlers::dispatch('post_views', ['rows' => [['p' => $id, 'day' => $day, 'n' => 10]]]));
        $this->assertSame(10, $this->views($id));
    }

    public function test_split_editions_counts_travel_as_aggregates_replay_is_idempotent_and_the_public_side_gets_only_the_aggregate(): void
    {
        $this->as('internal');
        config(['saan.public_push_url' => self::PUSH]);
        $first = $this->news('first-news', ['published_at' => now()->subDays(2)]);
        $second = $this->news('second-news', ['published_at' => now()->subDays(1)]);
        $draft = $this->news('hidden-news', ['status' => 'draft']);
        $this->settings(['popular' => ['days' => 7, 'limit' => 5]], ['news', 'popular']);
        // the public side gets the settings and posts through the real publisher and receiver
        $this->publicSide('pubdb', 'pubfiles');
        $receiver = new Receiver('pubdb', Keys::publicKey(), null);
        $pub = new Publisher(null, function (string $body, array $headers) use ($receiver) {
            config(['saan.push.receive_disk' => 'pubfiles']);
            return $receiver->handle($body, $headers);
        });
        $r = $pub->run(600);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $this->assertSame(0, DB::connection('pubdb')->table('pub_popular_posts')->count(), 'nothing was read yet');
        // pairing for the inbox, then become the public edition
        $c = Pairing::issue(1, null, self::PUSH);
        [$st, $reply] = Pairing::redeem($c['code'], self::PUSH, '10.0.0.1');
        $this->assertSame(200, $st);
        Keys::applyBundle($reply['bundle']);
        config(['filesystems.disks.local' => config('filesystems.disks.pubfiles')]);
        Storage::purge('local');
        $this->as('public', 'pubdb');
        foreach (['198.51.100.31', '198.51.100.32', '198.51.100.33'] as $ip) $this->visit('/portal/news/first-news', self::UA, $ip)->assertOk();
        $this->visit('/portal/news/second-news', self::UA, '198.51.100.31')->assertOk();
        $this->visit('/portal/news/hidden-news', self::UA, '198.51.100.31')->assertNotFound();
        $this->assertSame(3, $this->views($first));
        // counts back: ONE sealed envelope of aggregate numbers, no plaintext on the public side
        $this->assertSame(0, DB::table('pub_inbox')->count());
        $this->assertSame(2, PostViews::flush(), 'two (post, day) rows');
        $row = DB::table('pub_inbox')->first();
        $this->assertSame('post_views', $row->kind);
        $this->assertSame(1, DB::table('pub_inbox')->count());
        $this->assertStringNotContainsString('"rows"', base64_decode($row->ciphertext));
        $this->assertStringNotContainsString('first-news', base64_decode($row->ciphertext));
        $this->assertSame(0, PostViews::flush(), 'nothing changed: nothing is sent again');
        Artisan::call('saan:post-views-send');
        $this->assertSame(1, DB::table('pub_inbox')->count());

        $this->as('internal');
        $pull = fn () => (new Puller(function ($m, $pa, $q, $bd, $h) {
            return (new PullServer('pubdb', null, null))->handle($m, $pa, $q, $bd, $h);
        }, null))->run();
        $this->assertSame(0, $this->views($first), 'nothing counted on the internal side yet');
        $this->assertSame(['pulled' => 1, 'handled' => 1, 'quarantined' => 0, 'failed' => 0], $pull());
        $this->assertSame(3, $this->views($first));
        $this->assertSame(1, $this->views($second));
        $this->assertSame(0, $pull()['pulled']);
        // a resent envelope (the public side forgot it had sent, or a lost ack) carries the same cumulative totals and counts nothing twice
        $this->as('public', 'pubdb');
        DB::table('cms_post_views')->update(['sent_n' => 0]);
        $this->assertSame(2, PostViews::flush());
        $this->as('internal');
        $this->assertSame(1, $pull()['handled']);
        $this->assertSame(3, $this->views($first), 'idempotent');
        $this->assertSame(1, $this->views($second));
        // more reading: the next envelope carries the new cumulative total
        $this->as('public', 'pubdb');
        $this->visit('/portal/news/second-news', self::UA, '198.51.100.41')->assertOk();
        $this->visit('/portal/news/second-news', self::UA, '198.51.100.42')->assertOk();
        $this->assertSame(1, PostViews::flush());
        $this->as('internal');
        $this->assertSame(1, $pull()['handled']);
        $this->assertSame(3, $this->views($second));

        // internal -> public: only the aggregate (post id and three totals) is published, never a day or a visitor
        $b = new Builder();
        $this->assertNotContains('cms_post_views', $b->streams());
        $this->assertContains('pub_popular_posts', $b->streams());
        $rows = $b->rows('pub_popular_posts');
        $this->assertEqualsCanonicalizing([(string) $first, (string) $second], array_keys($rows));
        foreach ($rows as $pr) $this->assertEqualsCanonicalizing(['post_id', 'views_7', 'views_30', 'views_total'], array_keys($pr));
        $this->assertSame('3', $rows[(string) $second]['views_7']);
        // one run has a 60 s budget; on a slower database the backlog may need the next run, as the scheduler would do every minute
        for ($i = 0; $i < 10; $i++) {
            $r = $pub->run(600);
            $this->assertSame(0, $r['failed'], json_encode($r));
            if (!DB::table('pub_outbox')->whereNotNull('payload')->whereNull('sent_at')->exists()) break;
        }
        $this->assertTrue($pub->verify()['ok'], json_encode($pub->verify()));
        $p = DB::connection('pubdb');
        $this->assertEqualsCanonicalizing([$first, $second], $p->table('pub_popular_posts')->pluck('post_id')->map('intval')->all());
        $this->assertSame(3, (int) $p->table('pub_popular_posts')->where('post_id', $second)->value('views_30'));
        // the public home shows the block in rank order (second 3 reads, first 3 reads: tie broken by the newer post), and nothing unpublished
        $this->as('public', 'pubdb');
        $html = $this->visit('/')->assertOk()->getContent();
        $this->assertStringContainsString('ข่าวยอดนิยม', $html);
        $this->assertLessThan(strpos($html, 'ข่าว first-news</a>'), strpos($html, 'ข่าว second-news</a>'));
        $this->assertStringNotContainsString('hidden-news', $html);
        $this->assertStringNotContainsString('ข่าว hidden-news', $html);
        // the public aggregate table holds counts only
        $this->assertSame(['post_id', 'views_7', 'views_30', 'views_total'], Schema::getColumnListing('pub_popular_posts'));
    }

    // ---------------------------------------------------------------- popular block

    public function test_popular_block_orders_by_views_filters_the_window_and_leaves_out_unpublished_expired_and_other_school_posts(): void
    {
        $a = $this->news('pop-a', ['published_at' => now()->subDays(5)]);
        $b = $this->news('pop-b', ['published_at' => now()->subDays(4)]);
        $c = $this->news('pop-c', ['published_at' => now()->subDays(3)]);
        $d = $this->news('pop-d', ['published_at' => now()->subDays(2)]);
        $draft = $this->news('pop-draft', ['status' => 'draft']);
        $gone = $this->news('pop-gone', ['deleted_at' => now()]);
        $other = $this->news('pop-other', ['school_id' => 2]);
        $event = $this->news('pop-event', ['content_type' => 'event']);
        $today = PostViews::today();
        $old = Carbon::now('Asia/Bangkok')->subDays(20)->toDateString();
        $put = fn (int $id, string $day, int $n) => DB::table('cms_post_views')->insert(['school_id' => $id === $other ? 2 : 1, 'post_id' => $id, 'day' => $day, 'n' => $n, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $put($a, $today, 5); $put($b, $today, 9); $put($c, $today, 2); $put($d, $old, 50); // d: only 20 days ago
        $put($draft, $today, 99); $put($gone, $today, 99); $put($other, $today, 99); $put($event, $today, 99);
        $this->settings(['popular' => ['days' => 7, 'limit' => 3]], ['popular', 'news']);
        $html = $this->visit('/')->assertOk()->getContent();
        $block = substr($html, (int) strpos($html, 'id="popular"'), 1800);
        $this->assertStringContainsString('ในช่วง 7 วันที่ผ่านมา', $block);
        $this->assertLessThan(strpos($block, 'ข่าว pop-a'), strpos($block, 'ข่าว pop-b'));
        $this->assertLessThan(strpos($block, 'ข่าว pop-c'), strpos($block, 'ข่าว pop-a'));
        foreach (['pop-d', 'pop-draft', 'pop-gone', 'pop-other', 'pop-event'] as $n) $this->assertStringNotContainsString('ข่าว '.$n, $block, $n);
        // limit
        $this->settings(['popular' => ['days' => 7, 'limit' => 3]]);
        $this->assertSame([$b => 9, $a => 5, $c => 2], PostViews::popular(7, 5));
        $this->assertSame([$b => 9], PostViews::popular(7, 1));
        // 30 days brings the older reads in
        $this->settings(['popular' => ['days' => 30, 'limit' => 4]]);
        $html = $this->visit('/')->assertOk()->getContent();
        $block = substr($html, (int) strpos($html, 'id="popular"'), 2400);
        $this->assertStringContainsString('ในช่วง 30 วันที่ผ่านมา', $block);
        $this->assertLessThan(strpos($block, 'ข่าว pop-b'), strpos($block, 'ข่าว pop-d'));
        // a post that gets unpublished leaves the block at once
        DB::table('posts')->where('id', $b)->update(['status' => 'draft']);
        $html = $this->visit('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('ข่าว pop-b', substr($html, (int) strpos($html, 'id="popular"'), 2400));
        // nothing read, or the section not chosen: no block at all
        DB::table('cms_post_views')->delete();
        Cache::flush(); // the ranking is kept 5 minutes
        $this->assertStringNotContainsString('id="popular"', $this->visit('/')->getContent());
        $this->settings([], ['news']);
        DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => $a, 'day' => $today, 'n' => 4, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertStringNotContainsString('id="popular"', $this->visit('/')->getContent());
    }

    public function test_view_count_on_the_post_page_is_off_by_default_and_shows_the_total_when_chosen(): void
    {
        $id = $this->news('counted');
        DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => $id, 'day' => PostViews::FOLDED, 'n' => 1200, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertStringNotContainsString('อ่านแล้ว', $this->visit('/portal/news/counted')->assertOk()->getContent());
        $this->settings(['show_views' => true]);
        $html = $this->visit('/portal/news/counted', self::UA, '198.51.100.51')->assertOk()->getContent();
        $this->assertStringContainsString('อ่านแล้ว 1,202 ครั้ง', $html, 'folded total plus the two reads of this test');
    }

    public function test_retention_folds_old_days_keeps_the_total_and_the_public_side_drops_only_sent_rows(): void
    {
        $id = $this->news('retained');
        $keep = Carbon::now('Asia/Bangkok')->subDays(10)->toDateString();
        $old1 = Carbon::now('Asia/Bangkok')->subDays(PostViews::retentionDays() + 5)->toDateString();
        $old2 = Carbon::now('Asia/Bangkok')->subDays(PostViews::retentionDays() + 9)->toDateString();
        foreach ([[$keep, 7], [$old1, 11], [$old2, 13]] as [$day, $n]) DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => $id, 'day' => $day, 'n' => $n, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(31, PostViews::total($id));
        $this->assertSame(2, PostViews::prune());
        $this->assertEqualsCanonicalizing([$keep, PostViews::FOLDED], DB::table('cms_post_views')->pluck('day')->map(fn ($d) => (string) $d)->all());
        $this->assertSame(24, (int) DB::table('cms_post_views')->where('day', PostViews::FOLDED)->value('n'));
        $this->assertSame(31, PostViews::total($id), 'the total survives');
        $this->assertSame(0, PostViews::prune(), 'nothing more to fold');
        // a day older than the window can no longer be added through the inbox (it would count twice after folding)
        $this->assertSame(0, PostViews::receive(['rows' => [['p' => $id, 'day' => $old1, 'n' => 11]]])['rows']);
        $this->assertSame(31, PostViews::total($id));
        // the command runs on every edition; the window never goes below 35 days (the 30 day block stays whole)
        config(['saan.views.retention_days' => 5]);
        $this->assertSame(35, PostViews::retentionDays());
        $this->assertSame(0, Artisan::call('saan:post-views-prune'));
        // public edition: only rows that were sent leave
        config(['saan.views.retention_days' => 90]);
        DB::table('cms_post_views')->delete();
        foreach ([[$old1, 4, 4], [$old2, 6, 2]] as [$day, $n, $sent]) DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => $id, 'day' => $day, 'n' => $n, 'sent_n' => $sent, 'created_at' => now(), 'updated_at' => now()]);
        config(['saan.edition' => 'public']);
        $this->assertSame(1, PostViews::prune());
        $this->assertSame([$old2], DB::table('cms_post_views')->pluck('day')->map(fn ($d) => (string) $d)->all(), 'the unsent row waits');
        // a sent row of a post that is gone from the public database also goes (never today's)
        DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => 424242, 'day' => $keep, 'n' => 2, 'sent_n' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cms_post_views')->insert(['school_id' => 1, 'post_id' => 424243, 'day' => PostViews::today(), 'n' => 2, 'sent_n' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, PostViews::prune());
        $this->assertSame(0, DB::table('cms_post_views')->where('post_id', 424242)->count());
        $this->assertSame(1, DB::table('cms_post_views')->where('post_id', 424243)->count());
    }

    public function test_projection_never_exports_the_counts_and_declares_only_the_aggregate(): void
    {
        $this->assertTrue(Projection::isForbiddenTable('cms_post_views'));
        $decl = collect(Projection::declared(true, true))->keyBy('table');
        $this->assertTrue($decl->has('pub_popular_posts'));
        $this->assertFalse($decl->has('cms_post_views'));
        $this->assertSame('popular_posts', $decl['pub_popular_posts']['derived']);
        $this->assertSame(['post_id', 'views_7', 'views_30', 'views_total'], array_keys($decl['pub_popular_posts']['columns']));
        $this->assertContains('pub_popular_posts', (new Builder())->timedStreams(), 'the 7 and 30 day windows move with the clock');
        $a = $this->news('proj-a');
        $d = $this->news('proj-draft', ['status' => 'draft']);
        $o = $this->news('proj-other', ['school_id' => 2]);
        foreach ([$a, $d, $o] as $i) DB::table('cms_post_views')->insert(['school_id' => $i === $o ? 2 : 1, 'post_id' => $i, 'day' => PostViews::today(), 'n' => 6, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $rows = (new Builder())->rows('pub_popular_posts');
        $this->assertSame([(string) $a], array_map('strval', array_keys($rows)));
        $this->assertEquals(['post_id' => (string) $a, 'views_7' => '6', 'views_30' => '6', 'views_total' => '6'], $rows[(string) $a]);
    }

    // ---------------------------------------------------------------- settings validation

    public function test_chat_links_are_validated_strictly_and_normalised(): void
    {
        $ok = ['https://m.me/school.page' => 'https://m.me/school.page', 'https://m.me/school_page-1/' => 'https://m.me/school_page-1', 'https://M.ME/Tonthan' => 'https://m.me/Tonthan', '  https://m.me/abc  ' => 'https://m.me/abc'];
        foreach ($ok as $in => $out) $this->assertSame($out, SiteExtras::messengerUrl($in), $in);
        foreach (['http://m.me/x', 'https://m.me.evil.test/x', 'https://evil.test/m.me/x', 'https://www.m.me/x', 'https://m.me/x?ref=1', 'https://m.me/x#a', 'https://user@m.me/x', 'https://user:pw@m.me/x', 'https://m.me:8443/x',
            'https://m.me/', 'https://m.me', 'https://m.me//x', 'https://m.me/x/y', 'https://m.me/a b', 'https://m.me/<script>', "https://m.me/x\n.evil", 'https://m.me/.hidden', 'javascript:alert(1)', 'data:text/html,x', '//m.me/x', 'm.me/x',
            'https://facebook.com/x', 'https://messenger.com/t/x', 'https://m.me/'.str_repeat('a', 81), 'https://m.me/%3Cscript%3E', 'https://m.me/x\\y', 'https://m.me/ก', '', null, 7, ['https://m.me/x']] as $bad) {
            $this->assertNull(SiteExtras::messengerUrl($bad), json_encode($bad));
        }
        $line = ['https://line.me/R/ti/p/@school01' => 'https://line.me/R/ti/p/@school01', 'https://line.me/R/ti/p/%40school01' => 'https://line.me/R/ti/p/@school01', 'https://line.me/ti/p/@a.b-c_d' => 'https://line.me/R/ti/p/@a.b-c_d',
            'https://lin.ee/AbC123' => 'https://lin.ee/AbC123', 'https://LIN.EE/AbC123/' => 'https://lin.ee/AbC123'];
        foreach ($line as $in => $out) $this->assertSame($out, SiteExtras::lineUrl($in), $in);
        foreach (['https://line.me/R/ti/p/school01', 'https://line.me/R/ti/p/@', 'https://line.me.evil.test/R/ti/p/@x', 'https://evil.test/line.me/R/ti/p/@x', 'https://line.me/R/ti/p/@a?x=1', 'https://line.me/R/oaMessage/@x', 'https://line.me/R/ti/p/@a b',
            'https://line.me/R/ti/p/@<x>', 'http://line.me/R/ti/p/@x', 'https://lin.ee/ab', 'https://lin.ee/abc/def', 'https://lin.ee/abc?x=1', 'http://lin.ee/abc', 'https://lin.ee/', 'https://page.line.me/x', 'https://line.me:444/R/ti/p/@x',
            'https://line.me/R/ti/p/@x#y', 'javascript:alert(1)', 'https://lin.ee/'.str_repeat('a', 33), '', null, ['x']] as $bad) {
            $this->assertNull(SiteExtras::lineUrl($bad), json_encode($bad));
        }
        // the staff API refuses what the validators refuse, with a Thai message, and stores the clean form
        $cur = app(SiteSettings::class)->read(1);
        $send = fn (array $extras, int $v) => $this->admin()->putJson('/api/site/settings', ['version' => $v, 'intent' => 'draft', 'settings' => array_intersect_key($cur['draft'], array_flip(['badge', 'intro_heading', 'intro_text', 'mission', 'email', 'office_hours', 'levels', 'footer_text',
            'statistics', 'navigation', 'social_links', 'homepage_sections', 'default_seo_description'])) + ['extras' => $extras]]);
        $bad = $send(['chat' => ['messenger' => 'http://m.me/x']], $cur['version'])->assertUnprocessable();
        $this->assertStringContainsString('Messenger', json_encode($bad->json(), JSON_UNESCAPED_UNICODE));
        $send(['chat' => ['line' => 'https://line.me/R/ti/p/x']], $cur['version'])->assertUnprocessable();
        $send(['chat' => ['placement' => 'sticky']], $cur['version'])->assertUnprocessable();
        $send(['chat' => ['messenger' => 'https://m.me/x', 'script' => '1']], $cur['version'])->assertUnprocessable();
        $send(['unknown' => 1], $cur['version'])->assertUnprocessable();
        $send(['popular' => ['days' => 14]], $cur['version'])->assertUnprocessable();
        $send(['popular' => ['limit' => 11]], $cur['version'])->assertUnprocessable();
        $send(['show_views' => 'yes'], $cur['version'])->assertUnprocessable();
        $good = $send(['chat' => ['messenger' => 'https://M.ME/tonthan', 'line' => 'https://line.me/R/ti/p/%40tonthan', 'placement' => 'both'], 'show_views' => true], $cur['version'])->assertOk();
        $this->assertSame('https://m.me/tonthan', $good->json('draft.extras.chat.messenger'));
        $this->assertSame('https://line.me/R/ti/p/@tonthan', $good->json('draft.extras.chat.line'));
        $this->assertTrue($good->json('draft.extras.show_views'));
        // a save that omits the key keeps the choice
        $again = $this->admin()->putJson('/api/site/settings', ['version' => (int) $good->json('version'), 'intent' => 'draft', 'settings' => array_intersect_key($cur['draft'], array_flip(['badge', 'intro_heading', 'intro_text', 'mission', 'email', 'office_hours', 'levels', 'footer_text',
            'statistics', 'navigation', 'social_links', 'homepage_sections', 'default_seo_description']))])->assertOk();
        $this->assertSame('https://m.me/tonthan', $again->json('draft.extras.chat.messenger'));
        // the home sections accept the two new blocks
        $s = array_intersect_key($cur['draft'], array_flip(['badge', 'intro_heading', 'intro_text', 'mission', 'email', 'office_hours', 'levels', 'footer_text', 'statistics', 'navigation', 'social_links', 'default_seo_description']));
        $this->admin()->putJson('/api/site/settings', ['version' => (int) $again->json('version'), 'intent' => 'draft', 'settings' => $s + ['homepage_sections' => ['news', 'popular', 'map']]])->assertOk();
        $this->admin()->putJson('/api/site/settings', ['version' => (int) $this->getJson('/api/site/state')->json('settings.version'), 'intent' => 'draft', 'settings' => $s + ['homepage_sections' => ['news', 'sticky']]])->assertUnprocessable();
    }

    public function test_map_settings_are_validated(): void
    {
        $this->assertSame(13.7563, SiteExtras::latitude('13.7563'));
        $this->assertSame(-90.0, SiteExtras::latitude(-90));
        $this->assertSame(180.0, SiteExtras::longitude('180'));
        foreach (['90.0001', '-91', '1e2', '13,7', 'abc', '', null, '13.123456789', '1;2', '0x10', [1]] as $bad) $this->assertNull(SiteExtras::latitude($bad), json_encode($bad));
        foreach (['180.5', '-181', '100.5<', 'NaN', 'INF'] as $bad) $this->assertNull(SiteExtras::longitude($bad), $bad);
        $this->assertSame('วัดพระแก้ว กรุงเทพ', SiteExtras::placeQuery("  วัดพระแก้ว   กรุงเทพ "));
        foreach (['ab', str_repeat('ก', 121), '<script>', 'a"b c', "x\x07yz", 'a&b=c', 'a;b c', "o'brien"] as $bad) $this->assertNull(SiteExtras::placeQuery($bad), $bad);
        $this->assertNull(SiteExtras::problem(['map' => ['provider' => 'google', 'lat' => '13.7', 'lng' => '100.5', 'query' => 'ที่ตั้งโรงเรียน']]));
        $this->assertNull(SiteExtras::problem(['map' => ['provider' => 'osm', 'lat' => 13.7, 'lng' => 100.5]]));
        foreach ([
            ['map' => ['provider' => 'bing']], ['map' => ['lat' => '13.7']], ['map' => ['lng' => '100.5']], ['map' => ['lat' => '91', 'lng' => '100']], ['map' => ['lat' => '13', 'lng' => '181']],
            ['map' => ['query' => '<b>x</b>']], ['map' => ['provider' => 'osm', 'query' => 'ที่ตั้งโรงเรียน']], ['map' => ['label' => str_repeat('ก', 81)]], ['map' => ['label' => '<i>x</i>']], ['map' => ['on_contact' => 'yes']],
            ['map' => ['evil' => 'x']], ['map' => 'x'], 'x',
        ] as $bad) $this->assertNotNull(SiteExtras::problem($bad), json_encode($bad));
        $view = SiteExtras::mapView(['map' => ['provider' => 'google', 'lat' => 13.7563, 'lng' => 100.5018]]);
        $this->assertSame('https://www.google.com/maps/search/?api=1&query=13.756300%2C100.501800', $view['link']);
        $this->assertSame('https://www.google.com/maps?q=13.756300%2C100.501800&z=16&output=embed', $view['embed']);
        $this->assertSame('https://www.google.com/maps', $view['frame_source']);
        $q = SiteExtras::mapView(['map' => ['provider' => 'google', 'query' => 'โรงเรียนต้นธารวิทยา']]);
        $this->assertStringStartsWith('https://www.google.com/maps/search/?api=1&query=%E0%B9%82', $q['link']);
        $osm = SiteExtras::mapView(['map' => ['provider' => 'osm', 'lat' => 13.7563, 'lng' => 100.5018]]);
        $this->assertStringStartsWith('https://www.openstreetmap.org/export/embed.html?bbox=', $osm['embed']);
        $this->assertSame('https://www.openstreetmap.org/export/embed.html', $osm['frame_source']);
        $this->assertStringContainsString('mlat=13.756300&mlon=100.501800', $osm['link']);
        $this->assertNull(SiteExtras::mapView(['map' => ['provider' => 'osm', 'query' => 'ที่ตั้งโรงเรียน']]), 'OpenStreetMap needs coordinates');
        $this->assertNull(SiteExtras::mapView([]));
        // the staff API
        $cur = app(SiteSettings::class)->read(1);
        $base = array_intersect_key($cur['draft'], array_flip(['badge', 'intro_heading', 'intro_text', 'mission', 'email', 'office_hours', 'levels', 'footer_text', 'statistics', 'navigation', 'social_links', 'homepage_sections', 'default_seo_description']));
        $send = fn (array $extras, int $v) => $this->admin()->putJson('/api/site/settings', ['version' => $v, 'intent' => 'draft', 'settings' => $base + ['extras' => $extras]]);
        $send(['map' => ['lat' => '95', 'lng' => '100']], $cur['version'])->assertUnprocessable();
        $send(['map' => ['provider' => 'osm', 'query' => 'ที่ตั้ง']], $cur['version'])->assertUnprocessable();
        $ok = $send(['map' => ['provider' => 'google', 'lat' => '13.7563', 'lng' => '100.5018', 'label' => 'ที่ตั้ง "ต้นธาร" & ถนน', 'on_contact' => true]], $cur['version'])->assertOk();
        $this->assertSame(13.7563, $ok->json('draft.extras.map.lat'));
        $this->assertTrue($ok->json('draft.extras.map.on_contact'));
    }

    // ---------------------------------------------------------------- chat buttons on rendered pages

    public function test_chat_buttons_are_plain_links_and_no_page_loads_anything_external_for_them(): void
    {
        $a = $this->news('chat-news');
        $pages = ['/', '/portal/news', '/portal/news/chat-news', '/portal/contact', '/portal/directory'];
        $this->settings([], ['news', 'popular']);
        $before = [];
        foreach ($pages as $p) $before[$p] = $this->loads($this->visit($p)->assertOk()->getContent());
        $this->settings(['chat' => ['messenger' => 'https://m.me/tonthan.school', 'line' => 'https://line.me/R/ti/p/@tonthan', 'placement' => 'both']]);
        foreach ($pages as $p) {
            $res = $this->visit($p)->assertOk();
            $html = $res->getContent();
            $this->assertSame($before[$p], $this->loads($html), 'the chat buttons add no script, frame, stylesheet, image or font to '.$p);
            $this->assertStringNotContainsString('<iframe', $html, $p);
            preg_match_all('#<script\b[^>]*\bsrc="([^"]+)"#i', $html, $m);
            foreach ($m[1] as $src) $this->assertStringStartsWith('http://localhost/', $src, 'only scripts of this site: '.$p);
            preg_match_all('#<link\b[^>]*\bhref="([^"]+)"#i', $html, $m);
            foreach ($m[1] as $href) $this->assertDoesNotMatchRegularExpression('#^https?://(?!localhost(?:/|$))#', $href, 'no external stylesheet, preconnect or prefetch: '.$p);
            $this->assertDoesNotMatchRegularExpression('#<(?:script|iframe|img|link|source|video|embed|object)\b[^>]*(?:m\.me|line\.me|lin\.ee|facebook|fbcdn)#i', $html, $p);
            $this->assertDoesNotMatchRegularExpression('#(?:connect\.facebook|sdk\.js|fbq\(|liff|@import)#i', $html, $p);
            $this->assertNull($res->headers->get('Content-Security-Policy'), 'the policy of '.$p.' is unchanged');
            // two floating links and two footer links, each a plain anchor with Thai accessible name and noopener noreferrer
            $this->assertSame(2, preg_match_all('#<a class="chat-link chat-link-(?:messenger|line)" href="https://(?:m\.me/tonthan\.school|line\.me/R/ti/p/@tonthan)" target="_blank" rel="noopener noreferrer" aria-label="แชตกับโรงเรียนทาง [^"]+ \(เปิดในแท็บใหม่\)">#', substr($html, strpos($html, 'chat-links-floating'), 2500)));
            $this->assertStringContainsString('chat-links-footer', $html);
        }
        // placement is honoured
        $this->settings(['chat' => ['messenger' => 'https://m.me/tonthan.school', 'line' => '', 'placement' => 'footer']]);
        $html = $this->visit('/')->getContent();
        $this->assertStringContainsString('chat-links-footer', $html);
        $this->assertStringNotContainsString('chat-links-floating', $html);
        $this->assertStringNotContainsString('chat-link-line', $html);
        $this->settings(['chat' => ['messenger' => '', 'line' => '', 'placement' => 'both']]);
        $this->assertStringNotContainsString('data-chat-links', $this->visit('/')->getContent(), 'no link, no buttons');
    }

    public function test_chat_buttons_stay_clear_of_the_menu_and_the_ribbon_and_are_hidden_in_print(): void
    {
        $css = (string) file_get_contents(public_path('assets/site-extras.css'));
        $this->assertMatchesRegularExpression('/@media print\{[^}]*\.chat-links[^}]*display:none/', $css, 'hidden in print');
        $this->assertMatchesRegularExpression('/\.chat-links-floating\{position:fixed;[^}]*bottom:max\(12px/', $css, 'floating at the bottom edge, away from the header menu');
        $this->assertStringContainsString('body:has(.observance-ribbon-wrap[data-corner=bottom-right]) .chat-links-floating', $css, 'moves aside for a bottom-right ribbon');
        $this->assertStringContainsString('body:has(#public-navigation.open) .chat-links-floating{display:none}', $css, 'hidden while the mobile menu is open');
        $this->assertMatchesRegularExpression('/\.chat-link\{[^}]*min-height:48px/', $css, 'touch target');
        $obs = (string) file_get_contents(public_path('assets/observance.css'));
        $this->assertStringContainsString('.observance-ribbon-wrap[data-corner=bottom-left]{bottom:var(--rb-offset,0);left:var(--rb-offset,0)}', $obs);
        $this->assertLessThan(900, 60 + 1);
        $this->assertGreaterThan(60, 900, 'the ribbon (z-index 900) stays above the chat links (60)');
    }

    // ---------------------------------------------------------------- map block on rendered pages

    public function test_map_block_is_a_static_placeholder_with_no_external_request_and_frame_src_only_for_its_provider(): void
    {
        $plain = $this->visit('/')->assertOk();
        $this->assertNull($plain->headers->get('Content-Security-Policy'));
        $this->settings(['map' => ['provider' => 'google', 'lat' => '13.7563', 'lng' => '100.5018', 'label' => 'ที่ตั้งโรงเรียน', 'on_contact' => false]], ['news', 'map']);
        $res = $this->visit('/')->assertOk();
        $html = $res->getContent();
        preg_match('#<section[^>]*id="school-map".*?</section>#su', $html, $mm);
        $block = $mm[0] ?? '';
        $this->assertStringContainsString('ที่ตั้งโรงเรียน', $block);
        $this->assertStringContainsString('เปิดในแผนที่', $block);
        $this->assertStringContainsString('href="https://www.google.com/maps/search/?api=1&amp;query=13.756300%2C100.501800" target="_blank" rel="noopener noreferrer"', $block);
        $this->assertStringContainsString('data-map-embed="https://www.google.com/maps?q=13.756300%2C100.501800&amp;z=16&amp;output=embed"', $block);
        $this->assertMatchesRegularExpression('#<button class="map-load" type="button" data-map-load hidden>โหลดแผนที่</button>#su', $block, 'the load button exists but is shown by script only');
        $this->assertStringContainsString('<svg class="map-art"', $block, 'inline placeholder');
        $theme = app(\App\Domain\CmsPublishing::class)->theme(1)['published'];
        if (trim((string) $theme['address']) !== '') $this->assertStringContainsString('<p class="map-address">'.e($theme['address']).'</p>', $block);
        // nothing is fetched from the provider by the page itself
        $this->assertStringNotContainsString('<iframe', $html);
        preg_match_all('#\b(?:src|srcset|poster|data)="(https?://[^"]+)"#i', $html, $m);
        foreach ($m[1] as $u) $this->assertStringNotContainsString('google', $u);
        preg_match_all('#<link\b[^>]*\bhref="([^"]+)"#i', $html, $m);
        foreach ($m[1] as $u) $this->assertStringNotContainsString('google', $u);
        $this->assertSame('frame-src https://www.google.com/maps', $res->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('script-src', (string) $res->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('default-src', (string) $res->headers->get('Content-Security-Policy'));
        // another page of the same site keeps the policy it had (the collector is reset per request)
        $this->assertNull($this->visit('/portal/directory')->assertOk()->headers->get('Content-Security-Policy'));
        // contact page: only when chosen
        $this->assertStringNotContainsString('id="school-map"', $this->visit('/portal/contact')->getContent());
        $this->assertNull($this->visit('/portal/contact')->headers->get('Content-Security-Policy'));
        $this->settings(['map' => ['provider' => 'google', 'lat' => '13.7563', 'lng' => '100.5018', 'on_contact' => true]]);
        $c = $this->visit('/portal/contact')->assertOk();
        $this->assertStringContainsString('id="school-map"', $c->getContent());
        $this->assertSame('frame-src https://www.google.com/maps', $c->headers->get('Content-Security-Policy'));
        // OpenStreetMap: its own narrow source
        $this->settings(['map' => ['provider' => 'osm', 'lat' => '13.7563', 'lng' => '100.5018', 'on_contact' => true]]);
        $o = $this->visit('/portal/contact')->assertOk();
        $this->assertSame('frame-src https://www.openstreetmap.org/export/embed.html', $o->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('data-map-embed="https://www.openstreetmap.org/export/embed.html?bbox=', $o->getContent());
        // the block is chosen but there is nothing to show (no coordinates, no place): no block, no policy
        $this->settings(['map' => ['provider' => 'google', 'lat' => null, 'lng' => null, 'query' => '', 'on_contact' => true]]);
        $n = $this->visit('/portal/contact')->assertOk();
        $this->assertStringNotContainsString('id="school-map"', $n->getContent());
        $this->assertNull($n->headers->get('Content-Security-Policy'));
        // a Google place query works without coordinates
        $this->settings(['map' => ['provider' => 'google', 'query' => 'โรงเรียนต้นธารวิทยา', 'on_contact' => true]]);
        $this->assertStringContainsString('query=%E0%B9%82%E0%B8%A3%E0%B8%87', $this->visit('/portal/contact')->getContent());
    }

    public function test_a_home_page_with_the_map_and_the_facebook_block_allows_both_frames_and_nothing_else(): void
    {
        DB::table('social_feeds')->insert(['school_id' => 1, 'facebook_url' => 'https://www.facebook.com/tontan.school', 'heading' => 'เพจโรงเรียน', 'height' => 500, 'enabled' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $map = ['map' => ['provider' => 'google', 'lat' => '13.7563', 'lng' => '100.5018', 'on_contact' => false]];
        $this->settings($map, ['news', 'map', 'module:web-social']);
        $res = $this->visit('/')->assertOk();
        $this->assertStringContainsString('data-facebook-load', $res->getContent());
        $this->assertSame('frame-src https://www.google.com/maps https://www.facebook.com/plugins/page.php', $res->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith(\App\Support\PublicCsp::BLOCK_FRAMES['facebook'].'?', \Saan\Modules\WebSocial\SocialFeed::pluginUrl(\Saan\Modules\WebSocial\SocialFeed::forSchool(1)), 'the policy names the same path the frame uses');
        // the Facebook block alone, and the map alone
        $this->settings($map, ['news', 'module:web-social']);
        $this->assertSame('frame-src https://www.facebook.com/plugins/page.php', $this->visit('/')->headers->get('Content-Security-Policy'));
        $this->settings($map, ['news', 'map']);
        $this->assertSame('frame-src https://www.google.com/maps', $this->visit('/')->headers->get('Content-Security-Policy'));
        // a module that is switched off or a feed that is off adds nothing
        DB::table('social_feeds')->update(['enabled' => 0]);
        $this->settings($map, ['news', 'module:web-social']);
        $this->assertNull($this->visit('/')->headers->get('Content-Security-Policy'));
        // other values are still ignored
        $csp = new \App\Support\PublicCsp();
        foreach (['https://www.facebook.com', 'https://www.facebook.com/plugins/page.php/', 'https://www.facebook.com/plugins/page.php; script-src *'] as $x) $csp->allowFrame($x);
        $this->assertNull($csp->value());
    }

    public function test_the_policy_object_only_ever_names_the_known_provider_sources(): void
    {
        $csp = new \App\Support\PublicCsp();
        $this->assertNull($csp->value());
        foreach (['https://evil.test', 'https://www.google.com', 'https://www.google.com/maps/', '*', "'unsafe-inline'", 'https://www.google.com/maps; script-src *'] as $x) $csp->allowFrame($x);
        $this->assertNull($csp->value());
        $csp->allowFrame('https://www.openstreetmap.org/export/embed.html');
        $csp->allowFrame('https://www.openstreetmap.org/export/embed.html');
        $this->assertSame('frame-src https://www.openstreetmap.org/export/embed.html', $csp->value());
        $csp->reset();
        $this->assertNull($csp->value());
        // the script that creates the iframe knows exactly the same two sources and builds the frame only inside the click handler
        $js = (string) file_get_contents(public_path('assets/site-extras.js'));
        preg_match("/ALLOWED = \[([^\]]+)\]/", $js, $m);
        preg_match_all("/'([^']+)'/", $m[1], $urls);
        $this->assertEqualsCanonicalizing(array_values(SiteExtras::FRAME_SOURCES), $urls[1]);
        $this->assertGreaterThan(strpos($js, "addEventListener('click'"), strpos($js, "createElement('iframe')"), 'no iframe before the click');
        $this->assertStringNotContainsString('.src =', substr($js, 0, (int) strpos($js, "addEventListener('click'")));
        $this->assertSame(1, substr_count($js, "createElement('iframe')"));
    }

    public function test_the_map_script_loads_the_provider_frame_only_after_the_click_and_only_for_the_two_allowed_sources(): void
    {
        $node = (new \Symfony\Component\Process\ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('node is not installed');
        $p = new \Symfony\Component\Process\Process([$node, base_path('tests/js/site-extras.harness.mjs'), base_path('public/assets/site-extras.js')]);
        $p->setTimeout(60)->run();
        $out = json_decode(trim($p->getOutput()), true);
        $this->assertIsArray($out, $p->getOutput().$p->getErrorOutput());
        $this->assertSame([], $out['failures'], json_encode($out['log'], JSON_UNESCAPED_UNICODE));
        $this->assertTrue($p->isSuccessful());
    }

    // ---------------------------------------------------------------- XSS-safe rendering
    public function test_every_setting_is_rendered_safely_even_when_the_stored_json_is_hostile(): void
    {
        $this->settings([], ['news', 'popular', 'map']);
        $hostile = ['popular' => ['days' => '7;alert(1)', 'limit' => '<b>'], 'show_views' => '<script>alert(1)</script>',
            'chat' => ['messenger' => 'javascript:alert(1)', 'line' => '"><script>alert(1)</script>', 'placement' => '"><img src=x onerror=alert(1)>'],
            'map' => ['provider' => '"><script>alert(1)</script>', 'lat' => '1;alert(1)', 'lng' => '<svg onload=alert(1)>', 'query' => '<script>alert(1)</script>', 'label' => '<img src=x onerror=alert(1)>', 'on_contact' => '"><b>']];
        $row = DB::table('cms_site_settings')->where('school_id', 1)->first();
        foreach (['draft', 'published'] as $col) {
            $j = json_decode($row->$col, true);
            $j['extras'] = $hostile;
            DB::table('cms_site_settings')->where('id', $row->id)->update([$col => json_encode($j)]);
        }
        foreach (['/', '/portal/contact'] as $p) {
            $html = $this->visit($p)->assertOk()->getContent();
            $this->assertStringNotContainsString('alert(1)', $html, $p);
            $this->assertStringNotContainsString('onerror=', $html, $p);
            $this->assertStringNotContainsString('javascript:', $html, $p);
            $this->assertStringNotContainsString('data-chat-links', $html);
            $this->assertStringNotContainsString('id="school-map"', $html);
        }
        // valid but special characters are escaped where they are printed
        $this->settings(['map' => ['provider' => 'google', 'lat' => '13.7', 'lng' => '100.5', 'label' => 'ที่ตั้ง "ต้นธาร" & \'ถนน\'', 'on_contact' => true],
            'chat' => ['messenger' => 'https://m.me/ok.page', 'placement' => 'footer']]);
        $html = $this->visit('/portal/contact')->assertOk()->getContent();
        $this->assertStringContainsString('ที่ตั้ง &quot;ต้นธาร&quot; &amp; &#039;ถนน&#039;', $html);
        $this->assertStringNotContainsString('ที่ตั้ง "ต้นธาร" &', $html);
        // the admin editor builds its inputs through esc(): static check that every extras field goes through input()/select()
        $js = (string) file_get_contents(public_path('assets/cms-complete.js'));
        $this->assertStringContainsString("input('ลิงก์ Messenger (https://m.me/ชื่อเพจ)','x_messenger',c.messenger", $js);
        $this->assertStringContainsString("const input=(label,name,value='',type='text',extra='')=>`<label class=\"desk-field\"><span>\${label}</span><input type=\"\${type}\" name=\"\${name}\" value=\"\${esc(value??'')}\"", $js);
    }

    public function test_the_settings_screen_data_carries_the_extras_and_both_blocks_are_known_to_the_home_editor(): void
    {
        $this->admin();
        $state = $this->getJson('/api/site/state')->assertOk();
        $this->assertSame(SiteExtras::defaults(), $state->json('settings.draft.extras'));
        $js = (string) file_get_contents(public_path('assets/cms-complete.js'));
        $this->assertStringContainsString("popular:'ข่าวยอดนิยม',map:'แผนที่โรงเรียน'", $js);
        $this->assertStringContainsString('snapshot.extras=readExtras(form)', $js);
    }

    // ---------------------------------------------------------------- audit fixes (2026-11-07)

    public function test_only_news_articles_are_counted_and_other_content_types_show_no_view_count(): void
    {
        $this->settings(['show_views' => true]);
        $page = $this->news('about-us', ['content_type' => 'page']);
        $this->visit('/portal/pages/about-us', self::UA, '198.51.100.61')->assertOk();
        $this->assertSame(0, $this->views($page), 'a page is not counted');
        $this->assertStringNotContainsString('อ่านแล้ว', $this->visit('/portal/pages/about-us', self::UA, '198.51.100.62')->getContent());
        $article = $this->news('counted-news');
        $this->visit('/portal/news/counted-news', self::UA, '198.51.100.63')->assertOk();
        $this->assertSame(1, $this->views($article), 'an article is');
    }
}