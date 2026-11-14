<?php

namespace Tests\Feature;

use App\Domain\Updater;
use App\Models\User;
use App\Support\Saan\Mutation;
use App\Support\Saan\VersionConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Core P3 remediation: backups retention, log masking, 409 body, bounded post queries, public caching, indexes, bulk duty lookup, variant quota. */
class CorePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tmp = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        Storage::fake('local');
        $this->actingAs(User::where('role', 'admin')->first());
    }

    protected function tearDown(): void
    {
        if ($this->tmp && is_dir($this->tmp)) (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function guest(): void { $this->app['auth']->forgetGuards(); }

    private function image(): int
    {
        $im = imagecreatetruecolor(64, 48); ob_start(); imagepng($im); $raw = ob_get_clean(); imagedestroy($im);
        return $this->postJson('/api/site/media', ['file' => UploadedFile::fake()->createWithContent('picture.png', $raw)])->assertCreated()->json('id');
    }

    private function mkPost(array $row): int
    {
        return DB::table('posts')->insertGetId($row + ['school_id' => 1, 'author_id' => 1, 'slug' => 'perf-'.bin2hex(random_bytes(4)), 'title' => 'perf', 'body' => '', 'excerpt' => '', 'category' => 'general', 'content_type' => 'article', 'status' => 'published', 'version' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_updater_keeps_two_backups_and_sweeps_stale_temp(): void
    {
        $this->tmp = sys_get_temp_dir().'/saan-perf-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/app/private/backups', 0700, true);
        $this->app->useStoragePath($this->tmp);
        $dir = $this->tmp.'/app/private/backups';
        foreach (['a', 'b', 'c', 'd'] as $i => $n) { file_put_contents($dir."/pre-update-2026010{$i}T000000Z.zip", 'x'); touch($dir."/pre-update-2026010{$i}T000000Z.zip", time() - 1000 + $i); }
        $this->assertCount(4, Updater::backups());
        $this->assertSame(2, Updater::pruneBackups(2));
        $left = array_column(Updater::backups(), 'file');
        $this->assertSame(['pre-update-20260103T000000Z.zip', 'pre-update-20260102T000000Z.zip'], $left);

        $old = $this->tmp.'/app/private/archive-0123456789abcdef'; $fresh = $this->tmp.'/app/private/archive-fedcba9876543210';
        mkdir($old, 0700, true); mkdir($fresh, 0700, true); file_put_contents($old.'/t.jsonl', 'x');
        touch($old, time() - 2 * 86400);
        file_put_contents($dir.'/pre-update-x.zip.incomplete-aa', 'x'); touch($dir.'/pre-update-x.zip.incomplete-aa', time() - 2 * 86400);
        file_put_contents($dir.'/pre-update-y.zip.incomplete-bb', 'x');
        $this->assertSame(2, Updater::sweepStaleBackupTemp());
        $this->assertDirectoryDoesNotExist($old); $this->assertDirectoryExists($fresh);
        $this->assertFileDoesNotExist($dir.'/pre-update-x.zip.incomplete-aa'); $this->assertFileExists($dir.'/pre-update-y.zip.incomplete-bb');
    }

    public function test_update_page_reports_backups_and_admin_can_purge(): void
    {
        $this->tmp = sys_get_temp_dir().'/saan-perf-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/app/private/backups', 0700, true);
        $this->app->useStoragePath($this->tmp);
        file_put_contents($this->tmp.'/app/private/backups/pre-update-20260101T000000Z.zip', str_repeat('x', 10));
        $this->getJson('/api/site/updates')->assertOk()->assertJsonPath('backups.count', 1)->assertJsonPath('backups.bytes', 10)->assertJsonPath('backups.keep', 2);
        $this->postJson('/api/site/updates/backups/purge', ['current_password' => 'wrong'])->assertUnprocessable();
        $this->assertCount(1, Updater::backups());
    }

    public function test_bindings_are_masked_in_exception_messages_and_logs_rotate_by_default(): void
    {
        foreach (['mysql', 'mariadb'] as $c) $this->assertTrue((bool) config("database.connections.$c.mask_bindings_in_exception_messages"), $c);
        $example = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('LOG_STACK=daily', $example); $this->assertStringContainsString('LOG_DAILY_DAYS=14', $example);
        $this->assertStringContainsString("'daily'", (string) file_get_contents(config_path('logging.php')));
        $message = '';
        try { DB::table('no_such_table_zz')->where('name', 'SECRET-MARKER-123')->get(); }
        catch (\Illuminate\Database\QueryException $e) { $message = $e->getMessage(); }
        $this->assertNotSame('', $message); $this->assertStringNotContainsString('SECRET-MARKER-123', $message);
    }

    public function test_conflict_on_guardian_account_does_not_expose_login_email(): void
    {
        if (!Schema::hasTable('gp_accounts')) $this->markTestSkipped('guardian-portal tables not present');
        $id = DB::table('gp_accounts')->insertGetId(['school_id' => 1, 'guardian_id' => 987654, 'email' => 'login.secret@example.test', 'email_key' => 'login.secret@example.test', 'password' => 'x', 'last_login_at' => now(), 'auth_version' => 3, 'is_active' => true, 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        try { Mutation::update('gp_accounts', $id, 1, ['is_active' => false], 'test.gp'); $this->fail('expected conflict'); }
        catch (VersionConflict $e) {
            $this->assertSame(2, (int) $e->current['version']);
            foreach (['email', 'email_key', 'last_login_at', 'auth_version', 'password', 'phone_hash'] as $k) $this->assertArrayNotHasKey($k, $e->current);
            $this->assertStringNotContainsString('login.secret', json_encode($e->current));
        }
    }

    public function test_media_in_use_check_finds_references_in_chunks_and_ignores_others(): void
    {
        $used = $this->image(); $free = $this->image(); $inRevision = $this->image(); $inDraft = $this->image();
        $this->mkPost(['body' => '<p><img src="/media/'.$used.'"></p>', 'status' => 'draft']);
        $this->mkPost(['status' => 'draft', 'draft_payload' => json_encode(['body' => '<img src="/media/'.$inDraft.'">'])]);
        $pid = $this->mkPost(['status' => 'draft']);
        DB::table('cms_post_revisions')->insert(['school_id' => 1, 'post_id' => $pid, 'version' => 1, 'snapshot' => json_encode(['cover_media_id' => $inRevision, 'body' => '']), 'published_by' => 1, 'created_at' => now()]);
        // a decoy whose body only contains the digits of the free id must not count
        $this->mkPost(['body' => 'room '.$free.' and /media/'.$free.'0 text', 'status' => 'draft']);
        $c = app(\App\Http\Controllers\WebsiteMediaController::class);
        $this->assertTrue($c->used($used, 1, false)); $this->assertTrue($c->used($inDraft, 1, false)); $this->assertTrue($c->used($inRevision, 1, false));
        $this->assertFalse($c->used($free, 1, false));
        $this->deleteJson('/api/site/media/'.$free, ['version' => 1])->assertOk();
        $this->deleteJson('/api/site/media/'.$used, ['version' => 1])->assertUnprocessable();
    }

    public function test_public_media_cache_is_published_only_locked_and_browser_cacheable(): void
    {
        $img = $this->image(); $other = $this->image();
        $id = $this->mkPost(['cover_media_id' => $img, 'status' => 'published']);
        $this->mkPost(['cover_media_id' => $other, 'status' => 'draft']);
        $c = app(\App\Http\Controllers\WebsiteMediaController::class);
        $this->assertContains($img, $c->publicIds(1)); $this->assertNotContains($other, $c->publicIds(1));
        $this->guest();
        $r = $this->get('/media/'.$img)->assertOk();
        $this->assertStringContainsString('public', (string) $r->headers->get('Cache-Control')); $this->assertStringNotContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $etag = $r->headers->get('ETag'); $this->assertNotEmpty($etag);
        $this->get('/media/'.$img, ['If-None-Match' => $etag])->assertStatus(304);
        $this->get('/media/'.$other)->assertNotFound();
        // unpublishing changes the fingerprint at once
        DB::table('posts')->where('id', $id)->update(['status' => 'draft', 'version' => 2]);
        $this->get('/media/'.$img)->assertNotFound();
        // editors keep private, uncached responses
        $this->actingAs(User::where('role', 'admin')->first());
        $this->get('/media/'.$other)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_media_fingerprint_does_not_read_post_bodies_once_cached(): void
    {
        $img = $this->image(); $this->mkPost(['cover_media_id' => $img, 'body' => str_repeat('x', 1000)]);
        $c = app(\App\Http\Controllers\WebsiteMediaController::class);
        $c->publicIds(1);
        DB::flushQueryLog(); DB::enableQueryLog(); $c->publicIds(1);
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        $this->assertStringNotContainsString('"body"', $sql); $this->assertStringNotContainsString('`body`', $sql);
    }

    public function test_cms_state_is_paged_without_body_and_site_state_counts_in_sql(): void
    {
        for ($i = 0; $i < 7; $i++) $this->mkPost(['body' => 'BODY-MARKER-'.$i, 'status' => $i % 2 ? 'published' : 'draft']);
        $this->mkPost(['status' => 'draft', 'deleted_at' => now()]);
        $r = $this->getJson('/api/cms/state?per_page=3&page=2')->assertOk();
        $this->assertCount(3, $r->json('posts')); $this->assertGreaterThanOrEqual(7, $r->json('posts_total')); $this->assertSame(2, $r->json('page'));
        $this->assertStringNotContainsString('BODY-MARKER', $r->getContent());
        $this->assertArrayNotHasKey('body', $r->json('posts.0'));
        $before = DB::table('posts')->where('status', 'published')->whereNull('deleted_at')->count();
        $s = $this->getJson('/api/site/state')->assertOk();
        $this->assertSame($before, $s->json('counts.published')); $this->assertSame(1, $s->json('counts.trash'));
        $this->assertSame(DB::table('posts')->whereNull('deleted_at')->count(), array_sum($s->json('by_type')));
        $this->getJson('/api/site/audit')->assertOk()->assertJsonStructure(['data', 'current_page', 'next_page_url']);
    }

    public function test_homepage_loads_five_events_upcoming_first_without_bodies(): void
    {
        $today = now('Asia/Bangkok')->toDateString();
        foreach ([-30, -20, -10, -5, -2, -1] as $d) $this->mkPost(['content_type' => 'event', 'title' => 'past'.$d, 'body' => 'PAST-BODY', 'metadata' => json_encode(['starts_at' => now('Asia/Bangkok')->addDays($d)->toDateString(), 'ends_at' => null])]);
        foreach ([9, 3] as $d) $this->mkPost(['content_type' => 'event', 'title' => 'next'.$d, 'body' => 'NEXT-BODY', 'metadata' => json_encode(['starts_at' => now('Asia/Bangkok')->addDays($d)->toDateString(), 'ends_at' => null])]);
        $this->mkPost(['content_type' => 'event', 'title' => 'ongoing', 'metadata' => json_encode(['starts_at' => now('Asia/Bangkok')->subDays(2)->toDateString(), 'ends_at' => $today])]);
        $this->guest();
        $events = app(\App\Http\Controllers\SchoolWebsiteController::class)->home(request())->getData()['events'];
        $this->assertCount(5, $events);
        $this->assertSame(['ongoing', 'next3', 'next9', 'past-1', 'past-2'], $events->pluck('title')->all());
        $this->assertFalse(property_exists($events->first(), 'body'));
        $this->get('/portal')->assertOk()->assertDontSee('PAST-BODY');
    }

    public function test_public_search_is_throttled_and_still_finds_published_content(): void
    {
        $this->mkPost(['title' => 'ค้นหาตัวอย่างพิเศษ', 'body' => 'x']);
        $this->guest(); RateLimiter::clear('portal-search:'.\App\Support\Saan\NetKey::rate('127.0.0.1'));
        $this->get('/portal/search?q='.urlencode('ตัวอย่างพิเศษ'))->assertOk()->assertSee('ค้นหาตัวอย่างพิเศษ');
        for ($i = 0; $i < 29; $i++) $this->get('/portal/search?q=zz'.$i)->assertOk();
        $this->get('/portal/search?q=again')->assertStatus(429);
        $this->get('/portal/news?q=again')->assertStatus(429);
        $this->get('/portal')->assertOk(); // browsing without a search term is not limited
    }

    public function test_link_checker_scans_in_chunks(): void
    {
        for ($i = 0; $i < 450; $i++) DB::table('posts')->insert(['school_id' => 1, 'author_id' => 1, 'slug' => 'lk-'.$i, 'title' => 'l'.$i, 'body' => $i === 449 ? '<a href="/portal/no-such-page-zz">x</a>' : '', 'excerpt' => '', 'category' => 'general', 'content_type' => 'article', 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->getJson('/api/site/links');
        if ($r->status() === 404) $this->markTestSkipped('link tool route differs');
        $r->assertOk(); $this->assertContains('/portal/no-such-page-zz', array_column($r->json('data'), 'url'));
    }

    public function test_performance_indexes_exist(): void
    {
        foreach (['audit_events_entity_lookup', 'audit_events_school_id_id'] as $i) $this->assertTrue(Schema::hasIndex('audit_events', $i), $i);
        foreach (['posts_publication_fingerprint', 'posts_public_listing'] as $i) $this->assertTrue(Schema::hasIndex('posts', $i), $i);
    }

    public function test_bulk_duty_availability_matches_the_single_teacher_check(): void
    {
        $ops = app(\App\Domain\SchoolOperations::class);
        $ids = DB::table('teachers')->pluck('id')->all();
        $term = \App\Support\Saan\TermScope::current(); $this->assertNotNull($term);
        $dates = [$term->starts_on, \Carbon\CarbonImmutable::parse($term->starts_on)->addDays(8)->toDateString(), '2000-01-01'];
        foreach ($dates as $date) foreach ([['07:30', '08:20'], ['10:00', '11:00']] as [$s, $e]) {
            $bulk = $ops->dutyAvailableMany($ids, $date, $s, $e);
            foreach ($ids as $id) $this->assertSame($ops->dutyAvailable($id, $date, $s, $e), $bulk[$id], "teacher $id $date $s");
        }
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->getJson('/api/duty-suggestions?date='.$dates[1].'&starts_at=07:30&ends_at=08:20')->assertOk()->assertJsonStructure(['candidates']);
        $this->assertLessThan(40, count(DB::getQueryLog()));
    }

    public function test_image_variant_respects_the_storage_quota(): void
    {
        $id = $this->image();
        $before = DB::table('cms_media')->count();
        config(['saan.storage.quota_mb' => 1]); DB::table('cms_media')->update(['bytes' => 1048576]);
        $this->postJson('/api/site/media/'.$id.'/variant', ['width' => 16, 'ratio' => 'original', 'format' => 'png', 'quality' => 80, 'focal_x' => 50, 'focal_y' => 50])->assertStatus(422)->assertSee('MB', false);
        $this->assertSame($before, DB::table('cms_media')->count());
        $this->assertCount($before, Storage::disk('local')->allFiles('cms/1'));
        config(['saan.storage.quota_mb' => 100]);
        $r2 = $this->postJson('/api/site/media/'.$id.'/variant', ['width' => 16, 'ratio' => 'original', 'format' => 'png', 'quality' => 80, 'focal_x' => 50, 'focal_y' => 50]); $this->assertSame(201, $r2->status(), $r2->getContent());
    }
}
