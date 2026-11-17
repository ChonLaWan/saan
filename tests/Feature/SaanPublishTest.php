<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Projection;
use App\Support\Saan\Publish\Builder;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Outbox;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Publish\Receiver;
use App\Support\Saan\Publish\Wire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * S2 + S3 + S4: derived public tables, the builder, the signed batches, the receiver and the whole chain in one process.
 * The internal side is the suite connection; the public side is a second, temporary database (connections "pubdb", "pubdb2")
 * that the receiver is called on directly with the built batches (no HTTP).
 */
class SaanPublishTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tmp = [];
    private string $keysDir;
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        config(['filesystems.disks.local.root' => $this->tempDir()]); // a private root: the shared Storage::fake directory is wiped by tests running in parallel
        Storage::purge('local');
        $this->keysDir = $this->tempDir();
        config(['saan.keys_dir' => $this->keysDir]);
        Outbox::forget();
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->baseConnection]); // tests that switch to the public connection must let RefreshDatabase roll back the right one
        foreach (['pubdb', 'pubdb2'] as $c) { try { DB::purge($c); } catch (\Throwable) {} }
        parent::tearDown();
        gc_collect_cycles();
        foreach ($this->tmp as $p) is_dir($p) ? $this->rrmdir($p) : @unlink($p);
    }

    private function tempDir(): string
    {
        $d = sys_get_temp_dir().'/saan-pub-'.bin2hex(random_bytes(6));
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

    /** A migrated public database as connection $name with its own file disk $disk. */
    private function publicSide(string $name, string $disk): string
    {
        $db = $this->migratedTempConnection($name); // a fresh copy of a fully migrated temporary database, dropped at teardown
        config(["filesystems.disks.$disk" => ['driver' => 'local', 'root' => $this->tempDir()]]);
        return $db;
    }

    /** @return array{0:Publisher,1:Receiver} */
    private function pair(string $conn = 'pubdb', ?string $disk = 'pubfiles', ?string $peerKey = null, ?int $now = null): array
    {
        $receiver = new Receiver($conn, $peerKey ?? Keys::publicKey(), $now);
        $transport = function (string $body, array $headers) use ($receiver, $disk) {
            config(['saan.push.receive_disk' => $disk]);
            return $receiver->handle($body, $headers);
        };
        return [new Publisher(null, $transport), $receiver];
    }

    // ---------------------------------------------------------------- internal data

    private function world(): void
    {
        $now = now();
        $id = fn (string $t, array $row) => DB::table($t)->insertGetId($row);
        // media: one used as a post cover, one nobody references
        $png = base64_decode(self::PNG);
        Storage::disk('local')->put('cms/1/11111111-1111-4111-8111-111111111111.png', $png);
        Storage::disk('local')->put('cms/1/22222222-2222-4222-8222-222222222222.png', $png);
        $cover = $id('cms_media', ['school_id' => 1, 'uploaded_by' => 3, 'path' => 'cms/1/11111111-1111-4111-8111-111111111111.png', 'mime' => 'image/png', 'bytes' => strlen($png), 'width' => 1, 'height' => 1, 'alt' => 'ปก', 'original_name' => 'private-name.png', 'folder' => 'ส่วนตัว', 'created_at' => $now, 'updated_at' => $now]);
        $id('cms_media', ['school_id' => 1, 'uploaded_by' => 3, 'path' => 'cms/1/22222222-2222-4222-8222-222222222222.png', 'mime' => 'image/png', 'bytes' => strlen($png), 'width' => 1, 'height' => 1, 'alt' => 'ไม่ได้ใช้', 'created_at' => $now, 'updated_at' => $now]);
        $post = ['school_id' => 1, 'body' => 'เนื้อหา', 'author_id' => 3, 'created_at' => $now, 'updated_at' => $now];
        $id('posts', $post + ['slug' => 'pub-1', 'title' => 'ข่าวเผยแพร่', 'status' => 'published', 'published_at' => $now, 'cover_media_id' => $cover]);
        $id('posts', $post + ['slug' => 'draft-1', 'title' => 'ร่างลับ', 'status' => 'draft', 'draft_payload' => '{"secret":"TOPSECRET"}']);
        $id('posts', $post + ['slug' => 'gone-1', 'title' => 'ลบแล้ว', 'status' => 'published', 'deleted_at' => $now]);
        // notices: one live (full names configured), one expired, one draft
        $cols = json_encode(['order_no', 'display_name']);
        $list = fn (string $slug, string $status, string $from, string $to) => $id('notice_lists', ['school_id' => 1, 'slug' => $slug, 'title' => 'ประกาศ '.$slug, 'columns' => $cols, 'name_mode' => 'full', 'publish_from' => $from, 'expires_on' => $to, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[$list('live-list', 'published', now()->subDay()->toDateString(), now()->addDays(10)->toDateString()), 'นายสมชาย ใจดี'],
            [$list('old-list', 'published', now()->subDays(20)->toDateString(), now()->subDay()->toDateString()), 'นางสาวมะลิ วงศ์ดี'],
            [$list('draft-list', 'draft', now()->toDateString(), now()->addDays(5)->toDateString()), 'เด็กชายปิติ มั่นคง']] as $i => [$lid, $name]) {
            $id('notice_rows', ['school_id' => 1, 'list_id' => $lid, 'order_no' => $i + 1, 'display_name' => $name]);
        }
        // surveys: an open one with an issued certificate, a closed one
        $sv = $id('srv_surveys', ['school_id' => 1, 'slug' => 'survey-open', 'title' => 'แบบสอบถามเปิด', 'questions' => '[]', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        $id('srv_surveys', ['school_id' => 1, 'slug' => 'survey-closed', 'title' => 'แบบสอบถามปิด', 'questions' => '[]', 'status' => 'closed', 'created_at' => $now, 'updated_at' => $now]);
        $id('srv_responses', ['survey_id' => $sv, 'respondent_key' => str_repeat('a', 64), 'display_name' => 'นางสมหญิง รักเรียน', 'answers' => '[]', 'certificate_no' => 'SCH-2569-000001', 'created_at' => $now, 'ip_hash' => str_repeat('b', 64)]);
        $id('srv_responses', ['survey_id' => $sv, 'respondent_key' => str_repeat('c', 64), 'display_name' => 'ไม่มีเกียรติบัตร', 'answers' => '[]', 'created_at' => $now, 'ip_hash' => str_repeat('d', 64)]);
        // learning links: classroom 1 has a live code, classroom 2 an expired one
        $id('lrn_access', ['school_id' => 1, 'classroom_id' => 1, 'code_hash' => str_repeat('e', 64), 'expires_at' => now()->addDays(3), 'created_at' => $now, 'updated_at' => $now]);
        $id('lrn_access', ['school_id' => 1, 'classroom_id' => 2, 'code_hash' => str_repeat('f', 64), 'expires_at' => now()->subDay(), 'created_at' => $now, 'updated_at' => $now]);
        $link = fn (string $scope, string $url, array $x = []) => $id('lrn_links', ['school_id' => 1, 'scope_key' => $scope, 'title' => 'ลิงก์ '.$scope, 'url' => $url, 'url_hash' => sha1($url), 'created_at' => $now, 'updated_at' => $now] + $x);
        $link('all', 'https://example.test/all');
        $link('c1', 'https://example.test/c1', ['classroom_id' => 1]);
        $link('k5', 'https://example.test/k5', ['course_id' => 5]);
        // directory: teacher 1 shown with a photo, teacher 2 has a profile that is not shown, teacher 3 has none
        ob_start(); $im = imagecreatetruecolor(8, 8); imagejpeg($im); $jpeg = (string) ob_get_clean();
        Storage::disk('local')->put('personnel/1/'.str_repeat('a', 32).'.jpg', $jpeg);
        $id('per_profiles', ['school_id' => 1, 'teacher_id' => 1, 'position' => 'ครู', 'show_in_directory' => 1, 'photo_path' => 'personnel/1/'.str_repeat('a', 32).'.jpg', 'education' => json_encode(['ลับ'], JSON_UNESCAPED_UNICODE), /* a JSON column (MariaDB checks json_valid) */ 'created_at' => $now, 'updated_at' => $now]);
        $id('per_profiles', ['school_id' => 1, 'teacher_id' => 2, 'position' => 'ครู', 'show_in_directory' => 0, 'created_at' => $now, 'updated_at' => $now]);
        // ITA: a published year with a topic and an item, and a draft year
        $y = $id('ita_years', ['school_id' => 1, 'fiscal_year' => 2569, 'title' => 'ITA 2569', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $d = $id('ita_years', ['school_id' => 1, 'fiscal_year' => 2570, 'title' => 'ITA ร่าง', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $t = $id('ita_topics', ['school_id' => 1, 'year_id' => $y, 'title' => 'O1', 'created_at' => $now, 'updated_at' => $now]);
        $id('ita_topics', ['school_id' => 1, 'year_id' => $d, 'title' => 'O1 ร่าง', 'created_at' => $now, 'updated_at' => $now]);
        $id('ita_items', ['school_id' => 1, 'topic_id' => $t, 'title' => 'รายการ', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('saan_settings')->insert(['school_id' => 1, 'key' => 'modules.disabled', 'value' => json_encode(['finance']), 'version' => 1]);
        DB::table('saan_settings')->insert(['school_id' => 1, 'key' => 'mail.password_demo', 'value' => json_encode('never'), 'version' => 1]);
        app(ModuleRegistry::class)->flush();
    }

    private function allRows(): array
    {
        $b = new Builder();
        $out = [];
        foreach ($b->streams() as $s) $out[$s] = $b->rows($s);
        return $out;
    }

    // ---------------------------------------------------------------- S2: derived tables

    public function test_the_derived_public_tables_exist_and_match_their_declarations(): void
    {
        foreach (['pub_certificates', 'pub_notice_rows', 'pub_learning_rooms', 'pub_active_modules', 'pub_sync_state', 'pub_outbox', 'pub_sent_rows'] as $t) $this->assertTrue(\Schema::hasTable($t), $t);
        foreach (['number', 'holder_name', 'survey_title', 'issued_at'] as $c) $this->assertTrue(\Schema::hasColumn('pub_certificates', $c), $c);
        foreach (['id', 'op', 'payload_hash', 'created_at', 'sent_at', 'attempts', 'last_error', 'stream'] as $c) $this->assertTrue(\Schema::hasColumn('pub_outbox', $c), $c);
        $have = fn (string $t) => array_column(\Schema::getColumns($t), 'name');
        foreach (Projection::declared(false) as $e) {
            if ($e['derived'] === null) continue;
            $this->assertSame([], array_diff(array_keys($e['columns']), $have($e['table'])), $e['table'].' declares a column the migration lacks');
            $this->assertSame([], array_diff($have($e['table']), array_keys($e['columns'])), $e['table'].' has a column the declaration does not fill');
        }
    }

    // ---------------------------------------------------------------- S3: builder

    public function test_builder_never_exports_forbidden_columns_or_unpublished_rows(): void
    {
        $this->world();
        $all = $this->allRows();
        $declared = array_column(Projection::declared(), null, 'table');
        $json = json_encode($all, JSON_UNESCAPED_UNICODE);
        foreach ($all as $stream => $rows) {
            if ($stream === Wire::MODULES) continue;
            $want = array_keys($declared[$stream]['columns']);
            sort($want);
            foreach ($rows as $row) $this->assertSame($want, array_keys($row), "$stream: exactly the declared columns");
        }
        // no internal text anywhere, whatever the table
        foreach (['TOPSECRET', 'demo.school.test', 'ใจดี', 'วงศ์ดี', 'มั่นคง', 'รักเรียน', 'private-name', 'ส่วนตัว', 'ไม่มีเกียรติบัตร', 'mail.password_demo', 'never'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
        // posts: published only, authors replaced, drafts cut
        $slugs = array_column($all['posts'], 'slug');
        $this->assertContains('pub-1', $slugs);
        $this->assertNotContains('draft-1', $slugs);
        $this->assertNotContains('gone-1', $slugs);
        foreach ($all['posts'] as $p) { $this->assertSame('1', $p['author_id']); $this->assertNull($p['draft_payload']); $this->assertNull($p['scheduled_by']); }
        // media only when a published post uses it
        $this->assertCount(1, $all['cms_media']);
        $m = array_values($all['cms_media'])[0];
        $this->assertSame('cms/1/11111111-1111-4111-8111-111111111111.png', $m['path']);
        $this->assertSame('', $m['original_name']);
        $this->assertSame('1', $m['uploaded_by']);
        $files = (new Builder())->filesFor('cms_media', $all['cms_media']);
        $this->assertCount(1, $files);
        $this->assertSame(hash('sha256', base64_decode(self::PNG)), $files[0]['sha256']);
        // notices: only the live list, names masked even though the list says "full"
        $this->assertSame(['live-list'], array_column($all['notice_lists'], 'slug'));
        $this->assertCount(1, $all['pub_notice_rows']);
        $cells = json_decode(array_values($all['pub_notice_rows'])[0]['cells'], true);
        $this->assertNotSame('นายสมชาย ใจดี', $cells['display_name']);
        $this->assertStringStartsWith('นายสมชาย', $cells['display_name']);
        // certificates: only issued ones, masked holder, no response data
        $this->assertSame(['SCH-2569-000001'], array_keys($all['pub_certificates']));
        $c = $all['pub_certificates']['SCH-2569-000001'];
        $this->assertNotSame('นางสมหญิง รักเรียน', $c['holder_name']);
        $this->assertSame('แบบสอบถามเปิด', $c['survey_title']);
        $this->assertSame(['srv_surveys'], array_values(array_filter(array_keys($all), fn ($s) => str_starts_with($s, 'srv_'))));
        $this->assertSame(['survey-open'], array_column($all['srv_surveys'], 'slug'));
        // learning: school-wide and live-room links only, rooms of live codes only
        $this->assertSame(['ลิงก์ all', 'ลิงก์ c1'], array_column($all['lrn_links'], 'title'));
        $this->assertSame(['1'], array_column($all['lrn_access'], 'classroom_id'));
        $this->assertSame(['1'], array_map('strval', array_keys($all['pub_learning_rooms'])));
        $this->assertSame(['id', 'name', 'school_id'], array_keys(array_values($all['pub_learning_rooms'])[0]));
        // directory, ITA, settings
        $this->assertSame(['1'], array_column($all['per_profiles'], 'teacher_id'));
        $this->assertSame(['1'], array_map('strval', array_keys($all['teachers'])));
        $this->assertNull(array_values($all['per_profiles'])[0]['education']);
        $this->assertSame(['ITA 2569'], array_column($all['ita_years'], 'title'));
        $this->assertSame(['O1'], array_column($all['ita_topics'], 'title'));
        $this->assertSame(['modules.disabled'], array_column($all['saan_settings'], 'key'));
        // module list: active modules that have public routes, never a module the school switched off
        $mods = array_keys($all[Wire::MODULES]);
        $this->assertContains('web-ita', $mods);
        $this->assertNotContains('finance', $mods);
        $this->assertNotContains('guardian-portal', $mods);
    }

    public function test_builder_rejects_nothing_declared_for_a_forbidden_table_and_the_stream_list_is_the_declaration(): void
    {
        $b = new Builder();
        $streams = $b->streams();
        $this->assertSame(Wire::MODULES, $streams[0]);
        foreach ($streams as $s) if ($s !== Wire::MODULES) $this->assertTrue($s === 'saan_settings' || !Projection::isForbiddenTable($s), $s);
        // parents before children (posts reference cms_media, ITA items reference topics)
        $this->assertLessThan(array_search('posts', $streams, true), array_search('cms_media', $streams, true));
        $this->assertLessThan(array_search('ita_topics', $streams, true), array_search('ita_years', $streams, true));
        $this->assertLessThan(array_search('ita_items', $streams, true), array_search('ita_topics', $streams, true));
    }

    // ---------------------------------------------------------------- signatures

    private function signed(array $payload, string $kind = 'batch', ?int $ts = null): array
    {
        $ts ??= time();
        $json = Wire::json($payload);
        $body = '{"ts":'.$ts.',"kind":"'.$kind.'","payload_hash":"'.hash('sha256', $json).'","payload":'.$json.'}';
        return [$body, ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Signature' => Keys::sign($ts, $body)]];
    }

    private function batch(string $stream, int $seq, array $rows = [], string $op = 'upsert', array $extra = []): array
    {
        return ['v' => 1, 'stream' => $stream, 'seq' => $seq, 'op' => $op, 'key' => 'id', 'rows' => $rows, 'deletes' => [], 'files' => []] + $extra;
    }

    public function test_receiver_rejects_tampered_wrong_key_old_and_replayed_batches(): void
    {
        $this->publicSide('pubdb', 'pubfiles');
        $receiver = new Receiver('pubdb', Keys::publicKey());
        $mods = $this->batch(Wire::MODULES, 1, [['module_id' => 'web-cms']]);
        [$body, $h] = $this->signed($mods);

        // tampered after signing
        $this->assertSame(401, $receiver->handle(str_replace('web-cms', 'web-ita', $body), $h)[0]);
        $this->assertSame(401, $receiver->handle($body, ['X-Saan-Timestamp' => $h['X-Saan-Timestamp']])[0]);
        $this->assertSame(0, DB::connection('pubdb')->table('pub_active_modules')->count());
        // signed by another key
        $other = new Receiver('pubdb', base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));
        $this->assertSame(401, $other->handle($body, $h)[0]);
        // not paired
        config(['saan.keys_dir' => $this->tempDir()]);
        $this->assertSame(503, (new Receiver('pubdb'))->handle($body, $h)[0]);
        config(['saan.keys_dir' => $this->keysDir]);
        // old and future timestamps (outside 5 minutes), also when the sender signs them correctly
        [$oldBody, $oldH] = $this->signed($mods, 'batch', time() - 301);
        $this->assertSame([401, ['error' => 'stale_timestamp']], $receiver->handle($oldBody, $oldH));
        [$futBody, $futH] = $this->signed($mods, 'batch', time() + 400);
        $this->assertSame(401, $receiver->handle($futBody, $futH)[0]);
        [$edgeBody, $edgeH] = $this->signed($mods, 'batch', time() - 240);
        // a timestamp header that differs from the signed body timestamp
        $bad = $h; $bad['X-Saan-Timestamp'] = (string) (time() - 5);
        $this->assertSame(401, $receiver->handle($body, $bad)[0]);
        // payload hash that does not match the payload (signed by the right key)
        $forged = '{"ts":'.time().',"kind":"batch","payload_hash":"'.str_repeat('0', 64).'","payload":'.Wire::json($mods).'}';
        $ts = time();
        $this->assertSame(422, $receiver->handle($forged, ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Signature' => Keys::sign($ts, $forged)])[0]);
        $this->assertSame(0, DB::connection('pubdb')->table('pub_active_modules')->count());

        // accepted once, then a replay and an old sequence are 409, a skipped sequence is a gap
        $this->assertSame(200, $receiver->handle($edgeBody, $edgeH)[0]);
        $this->assertSame(1, DB::connection('pubdb')->table('pub_active_modules')->count());
        [$r2b, $r2h] = $this->signed($mods);
        $r = $receiver->handle($r2b, $r2h);
        $this->assertSame(409, $r[0]);
        $this->assertSame('replay', $r[1]['error']);
        [$g, $gh] = $this->signed($this->batch(Wire::MODULES, 5, [['module_id' => 'web-ita']]));
        $this->assertSame([409, ['error' => 'gap', 'last_seq' => 1]], $receiver->handle($g, $gh));
        [$n, $nh] = $this->signed($this->batch(Wire::MODULES, 2, [['module_id' => 'web-ita']]));
        $this->assertSame(200, $receiver->handle($n, $nh)[0]);
        [$old, $oh] = $this->signed($this->batch(Wire::MODULES, 1, [['module_id' => 'web-notices']]));
        $this->assertSame(409, $receiver->handle($old, $oh)[0]);
        $this->assertSame(['web-cms', 'web-ita'], DB::connection('pubdb')->table('pub_active_modules')->orderBy('module_id')->pluck('module_id')->all());
    }

    public function test_receiver_enforces_its_own_allowlist(): void
    {
        $this->publicSide('pubdb', 'pubfiles');
        $receiver = new Receiver('pubdb', Keys::publicKey());
        foreach (['users', 'students', 'fin_invoices', 'srv_responses', 'sessions', 'cms_messages', 'not_a_table'] as $i => $table) {
            [$b, $h] = $this->signed($this->batch($table, 1, [['id' => '5', 'name' => 'x']]));
            $this->assertSame([403, ['error' => 'forbidden_stream']], $receiver->handle($b, $h), $table);
        }
        $this->assertSame(0, DB::connection('pubdb')->table('users')->count());
        // a declared table, but the sender adds columns, sends a draft and a non-null author: extra columns dropped, rules re-applied, draft rejected
        $this->world();
        $row = array_values(array_filter((new Builder())->rows('posts'), fn ($r) => $r['slug'] === 'news-1'))[0];
        $row = ['author_id' => '3', 'draft_payload' => 'LEAK', 'email' => 'a@b.test', 'scheduled_by' => '3'] + $row;
        $draft = ['id' => '11', 'slug' => 'x-11', 'status' => 'draft'] + $row;
        [$b, $h] = $this->signed($this->batch('posts', 1, [$row, $draft]));
        [$st, $reply] = $receiver->handle($b, $h);
        $this->assertSame(200, $st, json_encode($reply));
        $this->assertSame(1, $reply['applied']);
        $this->assertSame(1, $reply['rejected']);
        $db = DB::connection('pubdb')->table('posts');
        $this->assertSame(1, $db->count());
        $saved = (array) DB::connection('pubdb')->table('posts')->where('slug', 'news-1')->first();
        $this->assertNull($saved['draft_payload']);
        $this->assertSame(1, (int) $saved['author_id']);
        $this->assertNull($saved['scheduled_by']);
        $this->assertSame(0, DB::connection('pubdb')->table('users')->where('email', 'a@b.test')->count());
        $this->assertSame(1, DB::connection('pubdb')->table('users')->count(), 'only the placeholder user exists');
        $ph = DB::connection('pubdb')->table('users')->first();
        $this->assertSame(1, (int) $ph->id);
    }

    public function test_receiver_files_need_matching_sha_size_extension_and_mime(): void
    {
        $this->publicSide('pubdb', 'pubfiles');
        config(['saan.push.receive_disk' => 'pubfiles']);
        $receiver = new Receiver('pubdb', Keys::publicKey());
        $png = base64_decode(self::PNG);
        $meta = ['path' => 'cms/1/33333333-3333-4333-8333-333333333333.png', 'sha256' => hash('sha256', $png), 'size' => strlen($png), 'mime' => 'image/png'];
        [$b, $h] = $this->signed(['files' => [$meta]], 'files');
        $this->assertSame([200, ['ok' => true, 'need' => [$meta['path']]]], $receiver->handle($b, $h));
        // wrong content for the announced hash (same length)
        [$b, $h] = $this->signed($meta + ['offset' => 0, 'data' => base64_encode(str_repeat('x', strlen($png)))], 'chunk');
        $this->assertSame(422, $receiver->handle($b, $h)[0]);
        $this->assertFalse(Storage::disk('pubfiles')->exists($meta['path']));
        // a script announced and sent with an image extension is refused by the MIME sniff
        $php = '<?php echo 1;';
        $evil = ['path' => 'cms/1/44444444-4444-4444-8444-444444444444.png', 'sha256' => hash('sha256', $php), 'size' => strlen($php), 'mime' => 'image/png'];
        [$b, $h] = $this->signed($evil + ['offset' => 0, 'data' => base64_encode($php)], 'chunk');
        $this->assertSame([422, ['error' => 'mime']], $receiver->handle($b, $h));
        $this->assertFalse(Storage::disk('pubfiles')->exists($evil['path']));
        // paths outside the allowed shape
        foreach (['../x.png', 'cms/1/../../x.png', 'public/x.png', 'cms/1/44444444-4444-4444-8444-444444444444.php', 'cms/1/sub/a1234567.png'] as $path) {
            [$b, $h] = $this->signed(['path' => $path] + $meta + ['offset' => 0, 'data' => base64_encode($png)], 'chunk');
            $this->assertSame(422, $receiver->handle($b, $h)[0], $path);
        }
        // the real file, in two chunks
        $half = intdiv(strlen($png), 2);
        [$b, $h] = $this->signed($meta + ['offset' => 0, 'data' => base64_encode(substr($png, 0, $half))], 'chunk');
        $this->assertSame([200, ['ok' => true, 'complete' => false]], $receiver->handle($b, $h));
        [$b, $h] = $this->signed($meta + ['offset' => 3, 'data' => base64_encode(substr($png, $half))], 'chunk');
        $this->assertSame(409, $receiver->handle($b, $h)[0], 'wrong offset');
        [$b, $h] = $this->signed($meta + ['offset' => $half, 'data' => base64_encode(substr($png, $half))], 'chunk');
        $this->assertSame([200, ['ok' => true, 'complete' => true]], $receiver->handle($b, $h));
        $this->assertSame($png, Storage::disk('pubfiles')->get($meta['path']));
        [$b, $h] = $this->signed(['files' => [$meta]], 'files');
        $this->assertSame([], $receiver->handle($b, $h)[1]['need']);
    }

    // ---------------------------------------------------------------- end to end

    private function tableSums(string $conn): array
    {
        config(['saan.push.receive_disk' => 'pubfiles']);
        $out = [];
        $rc = new Receiver($conn, Keys::publicKey());
        [$b, $h] = $this->signed([], 'verify');
        [$st, $r] = $rc->handle($b, $h);
        $this->assertSame(200, $st);
        return $r['checksums'];
    }

    public function test_end_to_end_incremental_deletes_expiry_and_full_rebuild_agree(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        $this->publicSide('pubdb', 'pubfiles');
        [$pub] = $this->pair('pubdb', 'pubfiles');

        $r = $pub->run(600);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $this->assertGreaterThan(5, $r['sent']);
        $this->assertSame(0, Publisher::status()['queue']);
        $v = $pub->verify();
        $this->assertTrue($v['ok'], json_encode(array_filter($v['streams'], fn ($s) => !$s['match'])));
        $p = DB::connection('pubdb');
        $this->assertSame(['news-1', 'pub-1'], $p->table('posts')->orderBy('slug')->pluck('slug')->all());
        $this->assertSame(['live-list'], $p->table('notice_lists')->pluck('slug')->all());
        $this->assertSame(1, $p->table('pub_notice_rows')->count());
        $this->assertSame(['SCH-2569-000001'], $p->table('pub_certificates')->pluck('number')->all());
        $this->assertSame(['1'], $p->table('pub_learning_rooms')->pluck('id')->map('strval')->all());
        $this->assertContains('web-ita', $p->table('pub_active_modules')->pluck('module_id')->all());
        $this->assertTrue(Storage::disk('pubfiles')->exists('cms/1/11111111-1111-4111-8111-111111111111.png'));
        $this->assertFalse(Storage::disk('pubfiles')->exists('cms/1/22222222-2222-4222-8222-222222222222.png'), 'unreferenced media is never sent');
        $this->assertTrue(Storage::disk('pubfiles')->exists('personnel/1/'.str_repeat('a', 32).'.jpg'));
        $this->assertSame(0, $p->table('users')->where('role', '!=', 'placeholder')->count());
        // nothing internal arrived in the public database at all
        $dump = json_encode(array_map(fn ($t) => $p->table($t)->get(), ['posts', 'cms_media', 'notice_lists', 'pub_notice_rows', 'pub_certificates', 'srv_surveys', 'per_profiles', 'teachers', 'users', 'saan_settings']), JSON_UNESCAPED_UNICODE);
        foreach (['TOPSECRET', 'demo.school.test', 'ใจดี', 'รักเรียน', 'private-name', 'mail.password_demo'] as $needle) $this->assertStringNotContainsString($needle, $dump, $needle);

        // an unchanged second run sends nothing
        $this->assertSame(0, $pub->run(600)['sent']);

        // changes: unpublish a post, delete a certificate, expire a notice list, add a notice row and a post, close the survey, switch a module off
        DB::table('posts')->where('slug', 'pub-1')->update(['status' => 'draft']);
        DB::table('posts')->insert(['school_id' => 1, 'slug' => 'late-1', 'title' => 'ข่าวใหม่', 'body' => 'x', 'status' => 'published', 'author_id' => 3, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('srv_responses')->where('certificate_no', 'SCH-2569-000001')->update(['certificate_no' => null]);
        DB::table('notice_lists')->where('slug', 'live-list')->update(['expires_on' => now()->subDay()->toDateString(), 'publish_from' => now()->subDays(5)->toDateString()]);
        DB::table('srv_surveys')->where('slug', 'survey-open')->update(['status' => 'closed']);
        DB::table('lrn_access')->where('classroom_id', 1)->update(['expires_at' => now()->subMinute()]);
        DB::table('saan_settings')->where('key', 'modules.disabled')->update(['value' => json_encode(['finance', 'surveys'])]);
        app(ModuleRegistry::class)->flush();
        $pub->reconcile(true);
        $this->assertSame(0, $pub->deliver(600)['failed']);
        $this->assertSame(['late-1', 'news-1'], $p->table('posts')->orderBy('slug')->pluck('slug')->all(), 'unpublish and new publish propagate');
        $this->assertSame(0, $p->table('cms_media')->count(), 'media of an unpublished post is withdrawn');
        $this->assertFalse(Storage::disk('pubfiles')->exists('cms/1/11111111-1111-4111-8111-111111111111.png'), 'the file of withdrawn media is deleted on the public disk');
        $this->assertSame(0, $p->table('notice_lists')->count());
        $this->assertSame(0, $p->table('pub_notice_rows')->count(), 'expiry removes the masked rows');
        $this->assertSame(0, $p->table('pub_certificates')->count());
        $this->assertSame(0, $p->table('srv_surveys')->count());
        $this->assertSame(0, $p->table('pub_learning_rooms')->count());
        $this->assertNotContains('surveys', $p->table('pub_active_modules')->pluck('module_id')->all());
        $this->assertTrue($pub->verify()['ok']);

        // a full rebuild into a brand new public database equals what the incremental updates produced
        $incremental = $this->tableSums('pubdb');
        $this->publicSide('pubdb2', 'pubfiles2');
        [$pub2] = $this->pair('pubdb2', 'pubfiles2');
        $n = array_sum($pub2->full());
        $this->assertGreaterThan(0, $n);
        $this->assertSame(0, $pub2->deliver(600)['failed']);
        config(['saan.push.receive_disk' => 'pubfiles2']);
        $full = $this->tableSums('pubdb2');
        $this->assertSame($incremental, $full, '--full must equal the incremental result');
        $this->assertTrue($pub2->verify()['ok']);
        // full replaces stale rows: put junk into the public copy, a full resend removes it
        DB::connection('pubdb2')->table('posts')->insert(['id' => 999, 'school_id' => 1, 'title' => 'ขยะ', 'body' => 'x', 'status' => 'published', 'slug' => 'junk', 'author_id' => 1, 'version' => 1]);
        $this->assertFalse($pub2->verify()['ok']);
        $pub2->full();
        $r = $pub2->deliver(600);
        $this->assertSame(0, $r['failed'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, DB::connection('pubdb2')->table('posts')->where('slug', 'junk')->count());
        $this->assertTrue($pub2->verify()['ok']);
    }

    public function test_a_lost_reply_and_a_receiver_that_was_reset_are_handled(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        $this->publicSide('pubdb', 'pubfiles');
        $receiver = new Receiver('pubdb', Keys::publicKey());
        $lose = true;
        $pub = new Publisher(null, function (string $body, array $h) use ($receiver, &$lose) {
            config(['saan.push.receive_disk' => 'pubfiles']);
            $r = $receiver->handle($body, $h);
            if ($lose && str_contains($body, '"kind":"batch"') && $r[0] === 200) { $lose = false; throw new \RuntimeException('connection lost after the receiver applied the batch'); }
            return $r;
        });
        $pub->reconcile(true);
        $first = $pub->deliver(20);
        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, (int) DB::table('pub_outbox')->whereNotNull('last_error')->whereNull('sent_at')->count());
        DB::table('pub_outbox')->update(['available_at' => null]); // the backoff would otherwise make us wait
        $this->assertSame(0, $pub->deliver(20)['failed'], 'a replayed batch with the same hash counts as delivered');
        $this->assertTrue($pub->verify()['ok']);
        // the receiver loses its state (restored from an old backup): the publisher is told about the gap and rebuilds everything
        DB::connection('pubdb')->table('pub_sync_state')->delete();
        DB::connection('pubdb')->table('posts')->delete();
        DB::table('posts')->where('slug', 'pub-1')->update(['title' => 'แก้ชื่อ']);
        $pub->reconcile(true);
        $r = $pub->deliver(20);
        $this->assertSame(1, $r['failed']);
        $pub->run(600);
        $this->assertTrue($pub->verify()['ok']);
    }

    public function test_combined_and_public_editions_never_push(): void
    {
        $this->assertFalse(Publisher::active());
        $this->artisan('saan:publish')->expectsOutputToContain('ไม่มีการส่งข้อมูล')->assertExitCode(0);
        $before = DB::table('pub_outbox')->count();
        $this->assertSame(['sent' => 0, 'failed' => 0, 'note' => 'ไม่ต้องส่ง'], (new Publisher())->run());
        $this->assertSame($before, DB::table('pub_outbox')->count());
        config(['saan.edition' => 'public']);
        $this->assertFalse(Publisher::active());
        // the push endpoint answers only in the public edition (unpaired here: 503)
        $this->postJson('/_saan/push', [])->assertStatus(503);
        config(['saan.edition' => 'combined']);
        $this->postJson('/_saan/push', [])->assertStatus(404);
    }

    public function test_push_endpoint_in_the_public_edition_needs_a_valid_signature_and_no_session(): void
    {
        $this->publicSide('pubdb', 'pubfiles');
        [$body, $h] = $this->signed($this->batch(Wire::MODULES, 1, [['module_id' => 'web-cms']])); // signed with this (internal) side's key
        $peerKey = Keys::publicKey();
        config(['saan.edition' => 'public', 'database.default' => 'pubdb', 'saan.keys_dir' => $this->tempDir()]); // the public side has no key of its own yet
        $json = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SAAN_TIMESTAMP' => $h['X-Saan-Timestamp'], 'HTTP_X_SAAN_SIGNATURE' => $h['X-Saan-Signature']];
        $res = $this->call('POST', '/_saan/push', [], [], [], $json, $body);
        $this->assertSame(503, $res->getStatusCode(), 'not paired yet');
        file_put_contents(Keys::dir().'/peer-push.pub', base64_encode(random_bytes(32)));
        $res = $this->call('POST', '/_saan/push', [], [], [], $json, $body);
        $this->assertSame(401, $res->getStatusCode(), 'paired with another key');
        $this->assertEmpty($res->headers->getCookies(), 'no session or cookie on the push endpoint');
        file_put_contents(Keys::dir().'/peer-push.pub', $peerKey);
        $res = $this->call('POST', '/_saan/push', [], [], [], $json, $body);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame(['web-cms'], DB::connection('pubdb')->table('pub_active_modules')->pluck('module_id')->all());
        $this->assertEmpty($res->headers->getCookies());
        $res = $this->call('POST', '/_saan/push', [], [], [], $json, $body);
        $this->assertSame(409, $res->getStatusCode(), 'replay');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    // ---------------------------------------------------------------- public pages from pub_* tables

    public function test_public_edition_serves_certificate_verification_notices_and_rooms_from_the_derived_tables(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        $this->publicSide('pubdb', 'pubfiles');
        [$pub] = $this->pair();
        $this->assertSame(0, $pub->run(600)['failed']);
        $key = app(\Saan\Modules\Surveys\SurveyService::class)->certKey('SCH-2569-000001'); // the recipient's link token (internal master key)
        config(['saan.edition' => 'public', 'database.default' => 'pubdb']);
        // without the recipient's key: valid + date only, no masked name, no survey title (numbers are sequential and guessable)
        $html = $this->get('/portal/certificates/verify?no=SCH-2569-000001')->assertOk()->getContent();
        $masked = DB::table('pub_certificates')->value('holder_name');
        $this->assertStringContainsString('ถูกต้อง: พบเกียรติบัตรเลขที่ SCH-2569-000001', $html);
        $this->assertStringNotContainsString('แบบสอบถามเปิด', $html);
        $this->assertStringNotContainsString(e($masked), $html);
        $wrong = $this->get('/portal/certificates/verify?no=SCH-2569-000001&k=0123456789')->assertOk()->getContent();
        $this->assertStringNotContainsString('แบบสอบถามเปิด', $wrong);
        $this->assertStringNotContainsString(e($masked), $wrong);
        // with the key: masked name and survey title, never the full name
        $html = $this->get('/portal/certificates/verify?no=SCH-2569-000001&k='.$key)->assertOk()->getContent();
        $this->assertStringContainsString('ถูกต้อง: พบเกียรติบัตรเลขที่ SCH-2569-000001', $html);
        $this->assertStringContainsString('แบบสอบถามเปิด', $html);
        $this->assertStringNotContainsString('รักเรียน', $html);
        $this->assertStringContainsString(e($masked), $html);
        $this->get('/portal/certificates/verify?no=SCH-2569-000999')->assertOk()->assertSee('ไม่ถูกต้อง');
        $this->get('/portal/certificates/verify?no='.rawurlencode("x' or 1=1"))->assertOk()->assertSee('ไม่ถูกต้อง');
        // the full certificate document needs responses, which this edition never holds
        $this->get('/portal/certificates/SCH-2569-000001?k=abc')->assertNotFound();

        $notice = $this->get('/portal/notices/live-list')->assertOk()->getContent();
        $this->assertStringContainsString('นายสมชาย', $notice);
        $this->assertStringNotContainsString('ใจดี', $notice);
        $this->get('/portal/notices/old-list')->assertNotFound();
        $this->get('/portal/notices/draft-list')->assertNotFound();

        $this->get('/learn/1')->assertOk();
        $this->post('/learn/1', ['code' => 'WRONG123'])->assertStatus(422);
    }

    // ---------------------------------------------------------------- hooks, command, admin page

    public function test_insert_get_id_on_a_watched_table_returns_the_new_row_not_the_marker(): void
    {
        // a paired internal install: the marker used to be written between the INSERT and insertGetId() reading the id, so callers got the
        // id of the pub_outbox row (Mutation::insert returned null, creating a hub/category failed with a TypeError)
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        Outbox::watch();
        DB::table('pub_outbox')->delete();
        Outbox::forget();
        DB::table('pub_outbox')->insert(['stream' => 'filler', 'op' => 'upsert', 'created_at' => now()]); // outbox ids run ahead of category ids
        foreach (range(1, 5) as $i) DB::table('pub_outbox')->insert(['stream' => 'filler'.$i, 'op' => 'upsert', 'created_at' => now()]);
        $row = \App\Support\Saan\Mutation::insert('cms_categories', ['slug' => 'id-check', 'label' => 'ตรวจเลข'], 'category.saved');
        $this->assertSame('id-check', $row->slug);
        $id = DB::table('cms_categories')->insertGetId(['school_id' => 1, 'slug' => 'id-check-2', 'label' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('id-check-2', DB::table('cms_categories')->where('id', $id)->value('slug'));
        // the marker is still written (at the next statement), inside a transaction too
        $this->assertContains('cms_categories', DB::table('pub_outbox')->whereNull('payload')->whereNull('sent_at')->pluck('stream')->all());
        DB::table('pub_outbox')->where('stream', 'cms_categories')->delete();
        Outbox::forget();
        DB::transaction(fn () => DB::table('cms_categories')->insertGetId(['school_id' => 1, 'slug' => 'id-check-3', 'label' => 'x', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertContains('cms_categories', DB::table('pub_outbox')->whereNull('sent_at')->pluck('stream')->all());
        // a rolled back insert leaves no marker
        DB::table('pub_outbox')->where('stream', 'cms_categories')->delete();
        Outbox::forget();
        try { DB::transaction(function () { DB::table('cms_categories')->insert(['school_id' => 1, 'slug' => 'id-check-4', 'label' => 'x', 'created_at' => now(), 'updated_at' => now()]); throw new \RuntimeException('stop'); }); } catch (\RuntimeException) {}
        $this->assertNotContains('cms_categories', DB::table('pub_outbox')->whereNull('sent_at')->pluck('stream')->all());
    }

    public function test_writes_to_published_tables_queue_a_change_marker_and_the_publisher_consumes_it(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        Outbox::watch();
        DB::table('pub_outbox')->delete();
        Outbox::forget();
        DB::table('posts')->where('slug', 'pub-1')->update(['title' => 'แก้ไขหัวข้อ']);
        DB::table('notice_lists')->where('slug', 'live-list')->update(['status' => 'archived']);
        DB::table('srv_surveys')->where('slug', 'survey-open')->update(['status' => 'closed']);
        DB::table('ita_years')->where('fiscal_year', 2569)->update(['status' => 'draft']);
        DB::table('saan_settings')->where('key', 'modules.disabled')->update(['value' => '[]']);
        DB::table('per_profiles')->where('teacher_id', 1)->update(['show_in_directory' => 0]);
        DB::table('lrn_access')->where('classroom_id', 1)->delete();
        $markers = DB::table('pub_outbox')->whereNull('payload')->whereNull('sent_at')->pluck('stream')->all();
        foreach (['posts', 'cms_media', 'notice_lists', 'pub_notice_rows', 'srv_surveys', 'pub_certificates', 'ita_years', 'ita_items', 'saan_settings', Wire::MODULES, 'per_profiles', 'teachers', 'lrn_access', 'pub_learning_rooms', 'lrn_links'] as $s) {
            $this->assertContains($s, $markers, "no marker for $s");
        }
        $this->assertCount(count(array_unique($markers)), $markers, 'one marker per stream');
        // unrelated tables never mark anything
        DB::table('pub_outbox')->delete();
        Outbox::forget();
        DB::table('lessons')->where('id', 1)->update(['room' => 'x']);
        DB::table('students')->where('id', 1)->update(['name' => 'y']);
        $this->assertSame(0, DB::table('pub_outbox')->count());
        // the publisher turns markers into batches and removes them
        DB::table('posts')->where('slug', 'pub-1')->update(['title' => 'อีกครั้ง']);
        $this->assertTrue(Outbox::pendingMarkers());
        $this->publicSide('pubdb', 'pubfiles');
        [$pub] = $this->pair();
        $pub->run(600);
        $this->assertFalse(Outbox::pendingMarkers());
        $this->assertSame(0, Publisher::status()['queue']);
    }

    public function test_dry_run_and_status_change_nothing(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        $this->artisan('saan:publish --dry-run')->expectsOutputToContain('posts:')->assertExitCode(0);
        $this->artisan('saan:publish --full --dry-run')->expectsOutputToContain('dry-run')->assertExitCode(0);
        $this->assertSame(0, DB::table('pub_outbox')->count());
        $this->assertSame(0, DB::table('pub_sent_rows')->count());
        $s = Publisher::status();
        $this->assertSame(['edition' => 'internal', 'configured' => true, 'last_sync' => null, 'queue' => 0, 'markers' => 0, 'errors' => [], 'stuck' => 0, 'skipped' => []], $s);
    }

    public function test_admin_status_page_and_full_resend_button(): void
    {
        $this->actingAs(User::where('role', 'admin')->first());
        $this->get('/admin/publish')->assertOk()->assertSee('ไม่ต้องส่งข้อมูล');
        config(['saan.edition' => 'internal', 'saan.public_push_url' => '']);
        $this->get('/admin/publish')->assertOk()->assertSee('ส่งข้อมูลทั้งหมดใหม่')->assertSee('ชุดข้อมูลที่รอส่ง')->assertSee('ยังไม่ได้ตั้งที่อยู่หน้าบ้าน');
        $this->post('/admin/publish/full')->assertRedirect('/admin/publish');
        $this->getJson('/api/site/publish')->assertOk()->assertJsonPath('queue', 0);
        $this->actingAs(User::where('role', 'teacher')->first());
        $this->get('/admin/publish')->assertForbidden();
        $this->post('/admin/publish/full')->assertForbidden();
    }

    // ---------------------------------------------------------------- review fixes

    private const MEDIA1 = 'cms/1/11111111-1111-4111-8111-111111111111.png';

    /** Internal edition with a public side; the transport can be told to fail for chosen request bodies. */
    private function wired(?callable $fail = null): array
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        $this->publicSide('pubdb', 'pubfiles');
        $receiver = new Receiver('pubdb', Keys::publicKey());
        $pub = new Publisher(null, function (string $body, array $h) use ($receiver, &$fail) {
            config(['saan.push.receive_disk' => 'pubfiles']);
            if ($fail && ($r = $fail($body))) return $r;
            return $receiver->handle($body, $h);
        });
        return [$pub, $receiver, fn () => DB::connection('pubdb')];
    }

    private function retryNow(): void
    {
        DB::table('pub_outbox')->whereNull('sent_at')->update(['available_at' => null]);
    }

    public function test_a_file_that_vanished_before_sending_is_skipped_and_the_rows_still_go(): void
    {
        [$pub, , $p] = $this->wired();
        $pub->reconcile(true); // the batch is queued with the file listed
        Storage::disk('local')->delete(self::MEDIA1); // a teacher replaced the photo: the queued file is gone
        $r = $pub->deliver(600);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $this->assertSame(0, Publisher::status()['queue']);
        $this->assertSame(1, $p()->table('cms_media')->count(), 'the row is delivered, the file will 404 until the next diff');
        $this->assertFalse(Storage::disk('pubfiles')->exists(self::MEDIA1));
        $this->assertNotEmpty(array_filter(Publisher::status()['skipped'], fn ($s) => str_contains($s, self::MEDIA1)));
        $this->assertSame(['news-1', 'pub-1'], $p()->table('posts')->orderBy('slug')->pluck('slug')->all());
    }

    public function test_a_file_the_receiver_refuses_never_blocks_the_batch_or_a_full_rebuild(): void
    {
        [$pub, , $p] = $this->wired();
        Storage::disk('local')->put(self::MEDIA1, '<?php echo 1;'); // content that does not match its .png name
        $pub->reconcile(true);
        $r = $pub->deliver(600);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $this->assertSame(1, $p()->table('cms_media')->count());
        $this->assertFalse(Storage::disk('pubfiles')->exists(self::MEDIA1), 'a script is never stored');
        $pub->full();
        $r = $pub->deliver(600);
        $this->assertSame(0, $r['failed'], 'full rebuild is not blocked by the same file: '.json_encode($r));
        $this->assertSame(0, Publisher::status()['queue']);
    }

    public function test_a_stuck_stream_does_not_hold_back_unrelated_takedowns_and_is_rebuilt_after_repeated_failures(): void
    {
        $failMedia = false;
        [$pub, , $p] = $this->wired(function (string $body) use (&$failMedia) {
            return $failMedia && str_contains($body, '"stream":"cms_media"') && str_contains($body, '"kind":"batch"') ? [500, ['error' => 'apply_failed']] : null;
        });
        $this->assertSame(0, $pub->run(600)['failed']);
        $this->assertSame(['live-list'], $p()->table('notice_lists')->pluck('slug')->all());
        $failMedia = true;
        DB::table('cms_media')->where('path', self::MEDIA1)->update(['alt' => 'เปลี่ยนคำอธิบาย']);
        DB::table('notice_lists')->where('slug', 'live-list')->update(['status' => 'archived']);
        $pub->reconcile(true);
        $r = $pub->deliver(600);
        $this->assertGreaterThanOrEqual(1, $r['failed']);
        $this->assertSame(0, $p()->table('notice_lists')->count(), 'the takedown in an unrelated stream is delivered although cms_media is stuck');
        $this->assertSame(0, $p()->table('pub_notice_rows')->count());
        $this->assertSame('ปก', $p()->table('cms_media')->value('alt'));
        $this->assertSame(0, (int) DB::table('pub_outbox')->where('stream', 'cms_media')->whereNull('sent_at')->whereNotNull('payload')->where('op', 'full')->count());
        for ($i = 0; $i < Publisher::STUCK_AFTER; $i++) { $this->retryNow(); $pub->deliver(600); }
        $this->assertGreaterThan(0, DB::table('pub_outbox')->where('stream', 'cms_media')->where('op', 'full')->count(), 'a fresh rebuild of just that stream was queued');
        $this->assertSame(0, DB::table('pub_outbox')->where('stream', 'posts')->where('op', 'full')->count(), 'other streams were not rebuilt');
        $failMedia = false;
        $this->retryNow();
        $this->assertSame(0, $pub->deliver(600)['failed']);
        $this->assertTrue($pub->verify()['ok']);
        $this->assertSame('เปลี่ยนคำอธิบาย', $p()->table('cms_media')->value('alt'));
    }

    public function test_a_gap_rebuilds_the_stream_at_most_once_an_hour_and_429_is_not_a_failure(): void
    {
        $mode = null;
        [$pub, , $p] = $this->wired(function (string $body) use (&$mode) {
            if ($mode === 'gap' && str_contains($body, '"kind":"batch"')) return [409, ['error' => 'gap', 'last_seq' => 0]];
            if ($mode === '429') return [429, ['retry_after' => 7]];
            return null;
        });
        $this->assertSame(0, $pub->run(600)['failed']);
        $mode = 'gap';
        DB::table('posts')->where('slug', 'pub-1')->update(['title' => 'ชื่อใหม่ 1']);
        $pub->reconcile(true);
        $pub->deliver(600);
        $c1 = DB::table('pub_outbox')->where('stream', 'posts')->where('op', 'full')->count();
        $this->assertGreaterThan(0, $c1);
        DB::table('posts')->where('slug', 'pub-1')->update(['title' => 'ชื่อใหม่ 2']);
        $this->retryNow();
        $pub->reconcile(true);
        $this->retryNow();
        $pub->deliver(600);
        $this->assertSame($c1, DB::table('pub_outbox')->where('stream', 'posts')->where('op', 'full')->count(), 'no second automatic rebuild within the hour');
        $this->assertNotEmpty(Publisher::status()['errors'], 'the failure stays visible on the status page');
        // 429: wait as told, no attempt counted, no failure
        $mode = null;
        $this->retryNow();
        $pub->deliver(600);
        $mode = '429';
        DB::table('notice_lists')->where('slug', 'live-list')->update(['title' => 'ประกาศใหม่']);
        $pub->reconcile(true);
        $r = $pub->deliver(600);
        $this->assertSame(0, $r['failed']);
        $held = DB::table('pub_outbox')->whereNull('sent_at')->whereNotNull('payload')->orderBy('id')->first();
        $this->assertNotNull($held);
        $this->assertSame(0, (int) $held->attempts);
        $this->assertNotNull($held->available_at);
    }

    public function test_only_one_publisher_runs_at_a_time_and_sequence_numbers_are_unique(): void
    {
        [$pub] = $this->wired();
        $lock = \Illuminate\Support\Facades\Cache::lock('saan:publish', 30);
        $this->assertTrue($lock->get());
        $this->assertSame(['sent' => 0, 'failed' => 0, 'note' => 'มีอีกกระบวนการกำลังส่งข้อมูลอยู่'], $pub->run(5));
        $this->assertSame(0, DB::table('pub_outbox')->whereNotNull('payload')->count(), 'nothing was built while the lock was held elsewhere');
        $this->assertSame([], $pub->reconcile(true));
        try { $pub->full(); $this->fail('full() must refuse while another process holds the lock'); } catch (\RuntimeException $e) { $this->assertStringContainsString('ส่งข้อมูลอยู่', $e->getMessage()); }
        $lock->release();
        $this->assertSame(0, $pub->run(600)['failed']);
        // sequence numbers: strictly increasing per stream and the database refuses a second batch with the same number
        $seqs = DB::table('pub_outbox')->where('stream', 'posts')->whereNotNull('seq')->orderBy('id')->pluck('seq')->map('intval')->all();
        $this->assertSame(array_values(array_unique($seqs)), $seqs);
        $this->assertSame($seqs, array_values(array_unique($seqs)));
        $row = ['stream' => 'posts', 'seq' => 987654, 'op' => 'upsert', 'created_at' => now()];
        DB::table('pub_outbox')->insert($row);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('pub_outbox')->insert($row);
    }

    public function test_withdrawn_media_and_withdrawn_consent_delete_the_public_files_and_full_sweeps_strays(): void
    {
        [$pub, , $p] = $this->wired();
        $this->assertSame(0, $pub->run(600)['failed']);
        $photo = 'personnel/1/'.str_repeat('a', 32).'.jpg';
        $disk = Storage::disk('pubfiles');
        $this->assertTrue($disk->exists(self::MEDIA1));
        $this->assertTrue($disk->exists($photo));
        // consent withdrawn: row and photo leave
        DB::table('per_profiles')->where('teacher_id', 1)->update(['show_in_directory' => 0]);
        $pub->reconcile(true);
        $this->assertSame(0, $pub->deliver(600)['failed']);
        $this->assertSame(0, $p()->table('per_profiles')->count());
        $this->assertFalse($disk->exists($photo), 'photo of a withdrawn profile is deleted');
        $this->assertTrue($disk->exists(self::MEDIA1), 'a file that is still referenced stays');
        // junk that no row references, and an abandoned upload: a full rebuild clears both
        $stray = 'cms/1/99999999-9999-4999-8999-999999999999.png';
        $disk->put($stray, base64_decode(self::PNG));
        $disk->put('personnel/1/'.str_repeat('b', 32).'.jpg', 'x');
        $disk->put('saan-incoming/'.str_repeat('c', 64).'.part', 'half');
        touch($disk->path('saan-incoming/'.str_repeat('c', 64).'.part'), time() - 2 * 86400);
        $pub->full();
        $this->assertSame(0, $pub->deliver(600)['failed']);
        $this->assertFalse($disk->exists($stray));
        $this->assertFalse($disk->exists('personnel/1/'.str_repeat('b', 32).'.jpg'));
        $this->assertFalse($disk->exists('saan-incoming/'.str_repeat('c', 64).'.part'));
        $this->assertTrue($disk->exists(self::MEDIA1));
        $this->assertTrue($pub->verify()['ok']);
    }

    public function test_a_new_post_with_the_slug_of_a_deleted_one_replaces_it_on_the_public_side(): void
    {
        [$pub, , $p] = $this->wired();
        $post = ['school_id' => 1, 'title' => 'เกี่ยวกับเรา', 'body' => 'a', 'status' => 'published', 'author_id' => 3, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'slug' => 'about-us'];
        $a = DB::table('posts')->insertGetId($post);
        $this->assertSame(0, $pub->run(600)['failed']);
        $this->assertSame([$a], $p()->table('posts')->where('slug', 'about-us')->pluck('id')->map('intval')->all());
        DB::table('posts')->where('id', $a)->delete(); // permanently deleted
        $b = DB::table('posts')->insertGetId(['title' => 'เกี่ยวกับเรา ใหม่'] + $post); // same slug, new id, same reconcile
        $pub->reconcile(true);
        $r = $pub->deliver(600);
        $this->assertSame(0, $r['failed'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame([$b], $p()->table('posts')->where('slug', 'about-us')->pluck('id')->map('intval')->all());
        $this->assertSame('เกี่ยวกับเรา ใหม่', $p()->table('posts')->where('slug', 'about-us')->value('title'));
        $this->assertTrue($pub->verify()['ok']);
        $pub->full();
        $this->assertSame(0, $pub->deliver(600)['failed']);
        $this->assertTrue($pub->verify()['ok']);
    }

    public function test_batch_replies_carry_checksums_only_when_asked(): void
    {
        $this->publicSide('pubdb', 'pubfiles');
        $receiver = new Receiver('pubdb', Keys::publicKey());
        [$b, $h] = $this->signed($this->batch(Wire::MODULES, 1, [['module_id' => 'web-cms']]));
        $r = $receiver->handle($b, $h);
        $this->assertSame(200, $r[0]);
        $this->assertArrayNotHasKey('checksums', $r[1]);
        [$b, $h] = $this->signed($this->batch(Wire::MODULES, 2, [['module_id' => 'web-ita']], 'upsert', ['checksum' => true]));
        $r = $receiver->handle($b, $h);
        $this->assertSame(2, $r[1]['checksums'][Wire::MODULES]['count']);
    }

    public function test_push_url_must_be_https_and_a_redirect_is_a_failure(): void
    {
        config(['saan.edition' => 'internal']);
        foreach (['http://public.test/_saan/push' => '', 'ftp://x/y' => '', 'https://' => '', 'https://public.test/_saan/push' => 'https://public.test/_saan/push',
            'http://localhost:8000/_saan/push' => 'http://localhost:8000/_saan/push'] as $in => $out) {
            config(['saan.public_push_url' => $in]);
            $this->assertSame($out, Publisher::url(), $in);
        }
        config(['saan.public_push_url' => 'http://public.test/_saan/push']);
        $this->world();
        $this->expectExceptionObject(new \RuntimeException('SAAN_PUBLIC_PUSH_URL ต้องขึ้นต้นด้วย https://'));
        (new Publisher())->verify();
    }

    public function test_a_redirect_from_the_public_side_is_never_followed(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 302, ['Location' => 'http://10.0.0.5/admin'])]);
        $pub = new Publisher();
        $pub->reconcile(true);
        $r = $pub->deliver(10);
        $this->assertGreaterThanOrEqual(1, $r['failed']);
        $this->assertStringContainsString('ไม่อนุญาต', (string) $r['error']);
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($req) => str_contains($req->url(), '10.0.0.5'));
    }

    public function test_key_files_are_created_once_exported_consistently_and_never_inside_the_web_root(): void
    {
        $dir = $this->tempDir().'/keys';
        config(['saan.keys_dir' => $dir]);
        $secret = Keys::secret();
        $this->assertSame($secret, Keys::secret());
        $pub = Keys::publicKey();
        $this->assertSame($pub, trim((string) file_get_contents($dir.'/push.pub')));
        file_put_contents($dir.'/push.pub', base64_encode(random_bytes(32))); // a stale or foreign export
        $this->assertSame($pub, Keys::publicKey());
        $this->assertSame($pub, trim((string) file_get_contents($dir.'/push.pub')), 'push.pub is always derived from the secret on disk');
        $this->assertSame([], glob($dir.'/*.tmp') ?: [], 'no temporary file is left behind');
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($dir.'/push.secret')), -4));
            $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
        }
        config(['saan.keys_dir' => public_path('saan-keys')]);
        $this->expectException(\RuntimeException::class);
        Keys::secret();
    }

    public function test_media_of_a_disabled_ita_module_is_not_published(): void
    {
        $this->world();
        $ita = DB::table('ita_topics')->where('title', 'O1')->value('id');
        $other = DB::table('cms_media')->where('path', 'cms/1/22222222-2222-4222-8222-222222222222.png')->value('id');
        DB::table('ita_items')->where('topic_id', $ita)->update(['media_id' => $other]);
        $b = new Builder();
        $this->assertArrayHasKey((int) $other, $b->rows('cms_media'), 'referenced by a published ITA item of an active module');
        DB::table('saan_settings')->where('key', 'modules.disabled')->update(['value' => json_encode(['finance', 'web-ita'])]);
        app(ModuleRegistry::class)->flush();
        $this->assertArrayNotHasKey((int) $other, (new Builder())->rows('cms_media'), 'the module is off internally: its media is not copied');
    }

    public function test_profiles_and_photos_of_inactive_teachers_never_leave(): void
    {
        $this->world();
        $photo = 'personnel/1/'.str_repeat('a', 32).'.jpg';
        $b = new Builder();
        $this->assertSame(['1'], array_column($b->rows('per_profiles'), 'teacher_id'));
        $this->assertCount(1, $b->filesFor('per_profiles', $b->rows('per_profiles')));
        DB::table('teachers')->where('id', 1)->update(['is_active' => false]);
        $b = new Builder();
        $this->assertSame([], $b->rows('teachers'));
        $this->assertSame([], $b->rows('per_profiles'), 'the profile of an inactive teacher is not exported');
        $this->assertSame([], $b->filesFor('per_profiles', $b->rows('per_profiles')), 'neither is the photo ('.$photo.')');
    }

    public function test_unlock_hashes_use_a_derived_key_that_the_public_edition_can_hold_without_the_master_key(): void
    {
        $unlock = \App\Support\Saan\HashKey::publicUnlock();
        $master = \App\Support\Saan\HashKey::secret();
        $this->assertNotSame($master, $unlock);
        $room = \Saan\Modules\LearningLinks\Links::hashCode(1, 'abcd-2345');
        $this->assertNotSame(hash_hmac('sha256', \Saan\Modules\LearningLinks\Links::school().'|1|ABCD2345', $master), $room, 'new room codes are not keyed with the master key');
        $notice = \Saan\Modules\WebNotices\NoticeRepository::hashCode('secret-code');
        // the internal side still verifies hashes made the old way (master key)
        $legacy = hash_hmac('sha256', \Saan\Modules\LearningLinks\Links::school().'|1|ABCD2345', $master);
        $this->assertTrue(\Saan\Modules\LearningLinks\Links::codeMatches($legacy, 1, 'ABCD2345'));
        // public edition: only the derived key is configured, the master key and app key are different
        config(['saan.hash_key' => '', 'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'saan.public_unlock_key' => $unlock]);
        $this->assertNotSame($master, \App\Support\Saan\HashKey::secret());
        $this->assertTrue(\Saan\Modules\LearningLinks\Links::codeMatches($room, 1, 'ABCD2345'));
        $this->assertFalse(\Saan\Modules\LearningLinks\Links::codeMatches($room, 1, 'ABCD2346'));
        $this->assertTrue(\Saan\Modules\WebNotices\NoticeRepository::checkCode($notice, 'secret-code'));
        $this->assertFalse(\Saan\Modules\WebNotices\NoticeRepository::checkCode($notice, 'other'));
        $this->assertFalse(\Saan\Modules\LearningLinks\Links::codeMatches($legacy, 1, 'ABCD2345'), 'a master-keyed hash cannot be checked (or brute forced) on the public side');
    }

    // ---------------------------------------------------------------- split review fixes

    public function test_a_wizard_paired_install_gets_change_markers_and_a_module_switch_marks_every_module_stream(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => '']);
        $this->world();
        Outbox::watch();
        Outbox::forget();
        DB::table('pub_outbox')->delete();
        DB::table('posts')->where('slug', 'pub-1')->update(['title' => 'ก่อนจับคู่']);
        $this->assertSame(0, DB::table('pub_outbox')->count(), 'nothing is queued while there is nowhere to push to');
        \App\Support\Saan\Settings::put('saan.public_push_url', 'https://public.test/_saan/push'); // what the pairing wizard stores, never the .env
        $this->assertSame('', trim((string) config('saan.public_push_url')));
        DB::table('pub_outbox')->delete();
        Outbox::forget();
        DB::table('posts')->where('slug', 'pub-1')->update(['title' => 'หลังจับคู่']);
        $this->assertContains('posts', DB::table('pub_outbox')->whereNull('payload')->pluck('stream')->all(), 'a paired install gets its markers');
        // switching a module off marks every module stream, not only the module list
        DB::table('pub_outbox')->delete();
        Outbox::forget();
        DB::table('saan_settings')->where('key', 'modules.disabled')->update(['value' => '[]']);
        $m = DB::table('pub_outbox')->whereNull('payload')->pluck('stream')->all();
        foreach (['saan_settings', Wire::MODULES, 'srv_surveys', 'pub_certificates', 'pub_notice_rows', 'lrn_links', 'ita_years'] as $s) $this->assertContains($s, $m, "no marker for $s");
    }

    public function test_a_module_switched_off_in_the_env_is_noticed_by_the_minute_tick(): void
    {
        [$pub, , $conn] = $this->wired();
        $pub->run(600);
        $this->assertSame(1, $conn()->table('srv_surveys')->where('slug', 'survey-open')->count());
        config(['saan.modules.disable' => 'surveys']);
        app(ModuleRegistry::class)->flush();
        $this->assertFalse(app(ModuleRegistry::class)->active('surveys'));
        $pub->run(600); // no database row changed: only the fingerprint of the active modules did
        $this->assertSame(0, $conn()->table('srv_surveys')->count(), 'withdrawn at the next tick, not at the hourly reconcile');
    }

    public function test_a_change_committed_while_the_diff_is_built_keeps_its_marker_and_a_failed_run_puts_the_claim_back(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        Outbox::watch();
        DB::table('pub_outbox')->delete();
        Outbox::forget();
        $b = new class extends Builder {
            public $hook;
            public function rows(string $stream): array
            {
                if ($this->hook && $stream === 'posts') { $h = $this->hook; $this->hook = null; $h(); }
                return parent::rows($stream);
            }
        };
        $b->hook = fn () => DB::table('posts')->where('slug', 'pub-1')->update(['title' => 'เขียนระหว่าง diff']);
        Outbox::touch(['posts']);
        $pub = new Publisher($b, fn () => [200, ['ok' => true]]);
        $pub->reconcile();
        $this->assertContains('posts', DB::table('pub_outbox')->whereNull('payload')->whereNull('sent_at')->pluck('stream')->all(), 'the write made during the diff has a marker of its own');

        DB::table('pub_outbox')->delete();
        Outbox::forget();
        Outbox::touch(['posts']);
        $b->hook = fn () => throw new \RuntimeException('boom');
        try { $pub->reconcile(); $this->fail('must fail'); } catch (\RuntimeException $e) { $this->assertSame('boom', $e->getMessage()); }
        $this->assertTrue(Outbox::pendingMarkers(), 'a run that failed gives the markers back');
    }

    public function test_quiz_answer_keys_are_removed_before_they_reach_the_public_database(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        $q = json_encode([['key' => 'q1', 'type' => 'single', 'label' => 'ข้อ 1', 'options' => ['ก', 'ข'], 'correct' => 'ก'], ['key' => 'q2', 'type' => 'multi', 'label' => 'ข้อ 2', 'options' => ['ก', 'ข'], 'correct' => ['ก', 'ข']]], JSON_UNESCAPED_UNICODE);
        DB::table('srv_surveys')->where('slug', 'survey-open')->update(['questions' => $q]);
        $row = collect((new Builder())->rows('srv_surveys'))->first(fn ($r) => $r['slug'] === 'survey-open');
        $this->assertStringNotContainsString('correct', $row['questions']);
        $this->assertStringContainsString('"options"', $row['questions']);
        // the receiver removes it again from a sender that forgot to
        $this->publicSide('pubdb', 'pubfiles');
        config(['saan.push.receive_disk' => 'pubfiles']);
        $row['questions'] = $q;
        [$body, $h] = $this->signed($this->batch('srv_surveys', 1, [$row]));
        $this->assertSame(200, (new Receiver('pubdb', Keys::publicKey()))->handle($body, $h)[0]);
        $stored = (string) DB::connection('pubdb')->table('srv_surveys')->where('slug', 'survey-open')->value('questions');
        $this->assertStringNotContainsString('correct', $stored);
        $this->assertStringContainsString('options', $stored);
        // a row that was exported with its keys before this fix is overwritten by the next diff (the hash changed)
        $this->assertStringNotContainsString('correct', Wire::scrubAnswerKeys($q));
    }

    public function test_a_full_rebuild_of_saan_settings_keeps_the_public_sides_own_rows(): void
    {
        [$pub, , $conn] = $this->wired();
        $internalId = (int) DB::table('saan_settings')->where('key', 'modules.disabled')->value('id');
        $c = $conn();
        $c->table('saan_settings')->insert(['id' => $internalId, 'school_id' => 1, 'key' => 'updates.available', 'value' => '"1.2.3"', 'version' => 1]); // same id as an internal row
        $c->table('saan_settings')->insert(['school_id' => 1, 'key' => 'updates.last_check', 'value' => '"x"', 'version' => 1]);
        $pub->fullAndDeliver(600);
        $have = $c->table('saan_settings')->pluck('value', 'key')->all();
        $this->assertSame('"1.2.3"', $have['updates.available'] ?? null, 'the public side keeps its own settings');
        $this->assertArrayHasKey('updates.last_check', $have);
        $this->assertSame($internalId, (int) $c->table('saan_settings')->where('key', 'modules.disabled')->value('id'), 'the sender id is kept');
        $this->assertTrue($pub->verify()['streams']['saan_settings']['match'], 'checksums only count the rows the sender owns');
        // a takedown (delete) never reaches a row that is not on the whitelist
        DB::table('saan_settings')->where('key', 'modules.disabled')->delete();
        $pub->run(600);
        $this->assertArrayHasKey('updates.available', $c->table('saan_settings')->pluck('value', 'key')->all());
        $this->assertSame(0, $c->table('saan_settings')->where('key', 'modules.disabled')->count());
    }

    public function test_replies_from_the_public_side_are_capped_and_cleaned(): void
    {
        config(['saan.edition' => 'internal', 'saan.public_push_url' => 'https://public.test/_saan/push']);
        $this->world();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(str_repeat('a', Publisher::MAX_REPLY + 10), 200)]);
        try { (new Publisher())->verify(); $this->fail('too large'); } catch (\RuntimeException $e) { $this->assertStringContainsString('ใหญ่เกิน', $e->getMessage()); }
        // text from the other side cannot forge a log line or fill a status column with line breaks
        $pub = new Publisher(null, fn () => [500, ['error' => "x\nFORGED", 'message' => "line1\r\n[ERR] forged"]]);
        $pub->reconcile(true);
        $r = $pub->deliver(5);
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', (string) $r['error']);
        $errors = DB::table('pub_outbox')->whereNotNull('last_error')->pluck('last_error')->all();
        $this->assertNotEmpty($errors);
        foreach ($errors as $e) $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $e);
    }}
