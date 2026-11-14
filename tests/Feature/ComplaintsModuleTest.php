<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\Inbox;
use App\Support\Saan\Inbox\Handlers;
use App\Support\Saan\Inbox\InboxRejected;
use App\Support\Saan\Inbox\Puller;
use App\Support\Saan\Inbox\PullServer;
use App\Support\Saan\Projection;
use App\Support\Saan\Publish\Builder;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Pairing;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Publish\Receiver;
use App\Support\Saan\Publish\Outbox;
use App\Support\Saan\Settings;
use App\Support\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Complaints\Access;
use Saan\Modules\Complaints\Cases;
use Saan\Modules\Complaints\Complaints;
use Saan\Modules\Complaints\InboxHandler;
use Saan\Modules\Complaints\PublicStatus;
use Saan\Modules\Complaints\Reports;
use Tests\TestCase;

/**
 * F1 complaints and case intake (ITA O13 / O14). Internal side = the suite connection; public side = a second, temporary database (connection "pubdb")
 * that sealing, the pull server, the publisher and the public pages work on. All people, receipts and texts are invented (demo school).
 */
class ComplaintsModuleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tmp = [];
    private string $keysDir;
    private const PUSH = 'https://public.test/_saan/push';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $this->keysDir = $this->tempDir();
        config(['saan.keys_dir' => $this->keysDir]);
        Handlers::forget();
        Outbox::forget();
        RateLimiter::clear('complaint-inbox-min');
        RateLimiter::clear('complaint-inbox-day');
        RateLimiter::clear('complaint-inbox-bytes');
        $this->diskRoot = $this->tempDir();
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        Storage::purge('local');
    }

    private string $diskRoot = '';

    protected function tearDown(): void
    {
        config(['database.default' => $this->baseConnection, 'saan.inbox_connection' => null, 'saan.edition' => 'combined']);
        try { DB::purge('pubdb'); } catch (\Throwable) {}
        Storage::purge('local');
        parent::tearDown();
        gc_collect_cycles();
        foreach ($this->tmp as $p) is_dir($p) ? $this->rrmdir($p) : @unlink($p);
    }

    // ---------------------------------------------------------------- plumbing

    private function tempDir(): string
    {
        $d = sys_get_temp_dir().'/saan-cmp-'.bin2hex(random_bytes(6));
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

    private function publicSide(): string
    {
        $db = $this->migratedTempConnection('pubdb'); // a fresh copy of a fully migrated temporary database, dropped at teardown
        config(['filesystems.disks.pubfiles' => ['driver' => 'local', 'root' => $this->tempDir()]]);
        return $db;
    }

    private function as(string $edition, ?string $conn = null): void
    {
        config(['saan.edition' => $edition, 'database.default' => $conn ?? $this->baseConnection, 'saan.inbox_connection' => $conn]);
    }

    private function pairAll(): void
    {
        $this->as('internal');
        $c = Pairing::issue(1, null, self::PUSH);
        [$st, $r] = Pairing::redeem($c['code'], 'https://public.test/_saan/push', '10.0.0.1');
        $this->assertSame(200, $st);
        Keys::applyBundle($r['bundle']);
    }

    private function puller(): Puller
    {
        $server = new PullServer('pubdb');
        return new Puller(fn ($m, $p, $q, $b, $h) => $server->handle($m, $p, $q, $b, $h));
    }

    /** Publishes the internal projection into pubdb with the real builder, publisher and receiver, then leaves the app as the public edition. */
    private function publish(): void
    {
        config(['saan.edition' => 'internal', 'database.default' => $this->baseConnection, 'saan.public_push_url' => self::PUSH]);
        $receiver = new Receiver('pubdb', Keys::publicKey());
        $pub = new Publisher(null, function (string $body, array $h) use ($receiver) {
            config(['saan.push.receive_disk' => 'pubfiles']);
            return $receiver->handle($body, $h);
        });
        // one run has a time budget (60 s): on a slower database the queue the request-end hooks left behind may need more than one run, as the
        // scheduler would do every minute. Repeat until nothing queued is left (bounded).
        for ($i = 0; $i < 10; $i++) {
            $r = $pub->run(600);
            $this->assertSame(0, $r['failed'], json_encode($r));
            if (!DB::table('pub_outbox')->whereNotNull('payload')->whereNull('sent_at')->exists()) break;
        }
        $this->assertFalse(DB::table('pub_outbox')->whereNotNull('payload')->whereNull('sent_at')->exists(), 'everything queued was delivered');
    }

    /** The demo / local-only boundary (SchoolBoundary) lets only loopback addresses in; tests that use other network addresses switch it off. */
    private function openBoundary(): void
    {
        config(['school.demo' => false, 'school.local_only' => false]);
    }

    private function addUser(string $role, string $name): User
    {
        $id = DB::table('users')->insertGetId(['school_id' => 1, 'name' => $name.' (สมมติ)', 'email' => $name.bin2hex(random_bytes(3)).'@demo.school.test', 'password' => Hash::make(bin2hex(random_bytes(12))),
            'role' => $role, 'is_demo' => true, 'created_at' => now(), 'updated_at' => now()]);
        return User::find($id);
    }

    private function png(): string
    {
        return base64_decode(self::PNG);
    }

    /** @return array{id:int,receipt:string,code:string,payload:array} */
    private function intake(array $o = [], array $files = [], ?string $envelope = null): array
    {
        $payload = array_merge([
            'receipt_no' => Complaints::newReceipt(), 'track_code' => Complaints::newTrackingCode(), 'category' => 'general', 'anonymous' => false,
            'name' => 'ผู้ร้องตัวอย่าง (สมมติ)', 'email' => 'complainant@demo.school.test', 'phone' => '', 'subject' => 'เรื่องทดสอบ '.bin2hex(random_bytes(2)),
            'detail' => 'รายละเอียดทดสอบของเรื่องร้องเรียนโรงเรียนต้นธารวิทยา',
        ], $o);
        $r = (new InboxHandler())->handle($payload, $files, $envelope ? ['envelope' => $envelope] : []);
        return ['id' => $r['id'], 'receipt' => $payload['receipt_no'], 'code' => $payload['track_code'], 'payload' => $payload, 'result' => $r];
    }

    private function officer(string $name = 'เจ้าหน้าที่'): User
    {
        $u = $this->addUser('teacher', $name);
        Settings::put('complaints.officer_user_ids', array_values(array_unique(array_merge(Access::officerIds(), [$u->id]))));
        return $u;
    }

    private function executive(string $name = 'ผู้บริหาร'): User
    {
        $u = $this->addUser('principal', $name);
        Settings::put('complaints.executive_user_ids', array_values(array_unique(array_merge(Access::executiveIds(), [$u->id]))));
        return $u;
    }

    private function version(int $id): int
    {
        return (int) DB::table('cmp_cases')->where('id', $id)->value('version');
    }

    // ---------------------------------------------------------------- demo data and module wiring

    public function test_demo_seed_has_invented_complaints_and_the_module_is_wired(): void
    {
        $this->assertSame(4, DB::table('cmp_cases')->count());
        $this->assertStringNotContainsString('TNTHAN4ME6QP', json_encode(DB::table('cmp_cases')->get()), 'the tracking code is never stored');
        $this->assertTrue(app(ModuleRegistry::class)->active('complaints'));
        $this->assertContains('complaints', array_column(app(ModuleRegistry::class)->workMenu('teacher'), 'key'));
        $this->assertSame('both', app(ModuleRegistry::class)->get('complaints')['edition']);
        $this->assertContains('complaints.sla_corruption', array_column(app(ModuleRegistry::class)->settingsSchema(), 'key'));
        // the demo status page works with the documented receipt and code
        $this->post('/portal/complaints/status', ['receipt_no' => 'C69-DEM2K7XQ', 'tracking_code' => 'tnth-an4m-e6qp'])->assertOk()->assertSee('ตอบกลับแล้ว')->assertSee('โรงเรียนจะปรับจุดรับส่ง');
        // the contact form keeps working next to it (cms_messages is NOT migrated in this task)
        $this->post('/portal/contact', ['name' => 'สมชาย', 'email' => 'a@example.test', 'subject' => 'ติดต่อ', 'message' => 'ข้อความที่ยาวพอสมควร', 'consent' => '1'])->assertRedirect('/portal/contact');
        $this->assertSame(1, DB::table('cms_messages')->count());
        $this->assertSame(0, DB::table('cmp_cases')->where('subject', 'ติดต่อ')->count());
    }

    // ---------------------------------------------------------------- public side seals and stores nothing

    public function test_the_public_edition_seals_the_complaint_and_keeps_nothing_readable(): void
    {
        $dbFile = $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $needles = ['เรื่องลับของผู้ปกครอง', 'รายละเอียดลับเฉพาะ-'.bin2hex(random_bytes(3)), 'สมหญิง ลับมาก', 'secret.complainant@example.test', 'evidence-photo.png'];
        $res = $this->post('/portal/complaints', [
            'category' => 'corruption', 'name' => $needles[2], 'email' => $needles[3], 'phone' => '081-234-5678', 'consent' => '1', 'subject' => $needles[0], 'detail' => $needles[1],
            'files' => [UploadedFile::fake()->createWithContent($needles[4], $this->png())],
        ]);
        $res->assertStatus(201)->assertSee('เลขรับเรื่อง')->assertSee('รหัสติดตาม');
        $html = $res->getContent();
        $this->assertSame(1, preg_match('/C\d{2}-[A-HJ-NP-Z2-9]{8}/', $html, $rm));
        $this->assertSame(1, preg_match('/<dd><code>([A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4})<\/code><\/dd>/', $html, $cm), 'a 12 character code from the unambiguous alphabet');
        $receipt = $rm[0];
        $code = str_replace('-', '', $cm[1]);
        $this->assertSame('no-store, private', $res->headers->get('Cache-Control'));

        $pub = DB::connection('pubdb');
        $this->assertSame(1, $pub->table('pub_inbox')->where('kind', 'complaint')->count());
        // nothing about the complaint in any table of the public database but the sealed envelope, nothing readable anywhere in the database or the session
        $withRows = [];
        foreach ($this->tempDbTables($dbFile) as $t) if ($pub->table($t)->exists()) $withRows[] = $t;
        $this->assertSame([], array_values(array_diff($withRows, ['pub_inbox', 'users', 'migrations', 'pub_sync_state', 'saan_settings', 'pub_active_modules'])), 'tables with rows: '.implode(',', $withRows));
        $this->assertSame(0, $pub->table('pub_complaint_status')->count());
        $this->assertSame(0, $pub->table('cmp_cases')->count());
        $file = $this->tempDbDump($dbFile);
        $row = $pub->table('pub_inbox')->first();
        $stored = json_encode((array) $row, JSON_UNESCAPED_UNICODE).base64_decode($row->ciphertext).implode('', array_map(fn ($a) => base64_decode($a['c']), json_decode($row->attachments, true)));
        $session = json_encode(session()->all(), JSON_UNESCAPED_UNICODE);
        foreach (array_merge($needles, [$receipt, $code, $this->png()]) as $n) {
            $this->assertStringNotContainsString($n, $file, 'plaintext in the public database');
            $this->assertStringNotContainsString($n, $stored, 'plaintext in the stored envelope');
            $this->assertStringNotContainsString($n, $session, 'plaintext in the session');
        }

        // the internal edition opens it: receipt and code are inside, the network address of the sender is not
        $this->as('internal');
        $o = Inbox::open($row->ciphertext, $row->attachments, $row->key_id);
        $this->assertSame('complaint', $o['env']['kind']);
        $this->assertSame($receipt, $o['env']['payload']['receipt_no']);
        $this->assertSame($code, $o['env']['payload']['track_code']);
        $this->assertSame('corruption', $o['env']['payload']['category']);
        $this->assertArrayNotHasKey('ip', $o['env']['payload']);
        $this->assertSame($this->png(), $o['files'][0]['content']);
    }

    public function test_an_anonymous_complaint_carries_no_contact_field_and_the_form_refuses_bad_input_without_flashing(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $this->post('/portal/complaints', ['category' => 'general', 'anonymous' => '1', 'name' => 'ไม่ควรถูกส่ง', 'email' => 'drop@example.test', 'subject' => 'หัวข้อนิรนาม', 'detail' => 'รายละเอียดแบบไม่ระบุชื่อ ยาวพอ'])->assertStatus(201);
        $row = DB::connection('pubdb')->table('pub_inbox')->first();
        $this->as('internal');
        $p = Inbox::open($row->ciphertext, $row->attachments, $row->key_id)['env']['payload'];
        $this->assertTrue($p['anonymous']);
        foreach (['name', 'email', 'phone'] as $k) $this->assertArrayNotHasKey($k, $p);

        $this->as('public', 'pubdb');
        $this->openBoundary();
        $before = DB::connection('pubdb')->table('pub_inbox')->count();
        // named complaint without contact, without consent, too short, a bot filling the honeypot: all refused, nothing sealed, nothing flashed
        $bad = [
            ['category' => 'general', 'name' => 'สมศรี', 'subject' => 'ไม่มีช่องทางติดต่อ', 'detail' => 'รายละเอียดยาวพอสมควรแล้ว', 'consent' => '1'],
            ['category' => 'general', 'name' => 'สมศรี', 'email' => 'a@example.test', 'subject' => 'ไม่ยินยอม', 'detail' => 'รายละเอียดยาวพอสมควรแล้ว'],
            ['category' => 'general', 'anonymous' => '1', 'subject' => 'สั้น', 'detail' => 'สั้น'],
            ['category' => 'general', 'anonymous' => '1', 'subject' => 'บอท', 'detail' => 'รายละเอียดยาวพอสมควรแล้ว', 'website' => 'http://spam.invalid'],
            ['category' => 'unknown', 'anonymous' => '1', 'subject' => 'ประเภทแปลก', 'detail' => 'รายละเอียดยาวพอสมควรแล้ว'],
        ];
        foreach ($bad as $i => $b) $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.'.(10 + $i)])->post('/portal/complaints', $b)->assertStatus(422)->assertSee('ส่งเรื่องไม่สำเร็จ');
        $this->assertSame($before, DB::connection('pubdb')->table('pub_inbox')->count());
        $this->assertSame([], (array) session()->get('_old_input', []));
    }

    public function test_the_form_limits_every_try_per_64_and_per_48_and_turnstile_is_off_by_default(): void
    {
        $this->openBoundary(); // the demo boundary only lets 127.0.0.1 in; this test needs other addresses
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $this->get('/portal/complaints')->assertOk()->assertDontSee('cf-turnstile')->assertSee('ร้องเรียนและแจ้งเรื่อง');
        $ok = ['category' => 'suggestion', 'anonymous' => '1', 'subject' => 'ข้อเสนอแนะ', 'detail' => 'รายละเอียดยาวพอสมควรแล้ว'];
        // five addresses inside one IPv6 /64 share one budget of 5
        for ($i = 1; $i <= 5; $i++) $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:aaaa:bbbb::'.$i])->post('/portal/complaints', $ok)->assertStatus(201);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:aaaa:bbbb::99'])->post('/portal/complaints', $ok)->assertStatus(429);
        // a failed try counts too
        for ($i = 1; $i <= 5; $i++) $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:cccc:dddd::'.$i])->post('/portal/complaints', ['category' => 'general'])->assertStatus(422);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:cccc:dddd::7'])->post('/portal/complaints', $ok)->assertStatus(429);
        // the /48 ceiling (30 per hour) holds across many /64 networks of one site
        RateLimiter::clear('complaint-submit-wide:'.\App\Support\Saan\NetKey::wide('2001:db8:eeee::1'));
        for ($i = 0; $i < 30; $i++) $this->withServerVariables(['REMOTE_ADDR' => sprintf('2001:db8:eeee:%x::1', $i + 1)])->post('/portal/complaints', ['category' => 'general'])->assertStatus(422);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:eeee:ffff::1'])->post('/portal/complaints', $ok)->assertStatus(429);
        // of the complaints accepted per day one network gets a fifth of the school-wide ceiling of the handler, so it cannot use it up alone and close the form
        // for everybody; only a filing that went through counts (a failed try does not)
        $share = max(20, intdiv((int) config('saan.inbox.complaint_per_day'), 5));
        $this->assertLessThan((int) config('saan.inbox.complaint_per_day'), $share);
        $day = 'complaint-submit-day:'.\App\Support\Saan\NetKey::wide('2001:db8:1234::1');
        RateLimiter::clear($day);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1234:1::1'])->post('/portal/complaints', ['category' => 'general'])->assertStatus(422);
        $this->assertSame(0, (int) RateLimiter::attempts($day));
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1234:1::1'])->post('/portal/complaints', $ok)->assertStatus(201);
        $this->assertSame(1, (int) RateLimiter::attempts($day));
        for ($i = 1; $i < $share; $i++) RateLimiter::hit($day, 86400);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1234:2::1'])->post('/portal/complaints', $ok)->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:4321:1::1'])->post('/portal/complaints', $ok)->assertStatus(201);
    }

    public function test_turnstile_is_verified_on_the_server_without_remoteip_and_only_for_this_form(): void
    {
        $this->as('combined');
        $this->openBoundary();
        config(['saan.complaints.turnstile_site_key' => 'site-key-demo', 'saan.complaints.turnstile_secret' => 'secret-demo']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => true])->push(['success' => false])->push('boom', 500)]);
        $this->get('/portal/complaints')->assertOk()->assertSee('cf-turnstile')->assertSee('site-key-demo');
        $ok = ['category' => 'suggestion', 'anonymous' => '1', 'subject' => 'ข้อเสนอแนะ', 'detail' => 'รายละเอียดยาวพอสมควรแล้ว'];
        $this->post('/portal/complaints', $ok)->assertStatus(422)->assertSee('ไม่ผ่านการตรวจสอบ'); // no token: the verifier is not even asked
        Http::assertNothingSent();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->post('/portal/complaints', $ok + ['cf-turnstile-response' => 'tok-good'])->assertStatus(201);
        Http::assertSent(function ($req) {
            return str_starts_with($req->url(), 'https://challenges.cloudflare.com/turnstile/v0/siteverify') && $req['secret'] === 'secret-demo' && $req['response'] === 'tok-good' && !isset($req['remoteip']) && !str_contains($req->body(), '203.0.113.7');
        });
        $this->post('/portal/complaints', $ok + ['cf-turnstile-response' => 'tok-bad'])->assertStatus(422);
        $this->post('/portal/complaints', $ok + ['cf-turnstile-response' => 'tok-down'])->assertStatus(422); // fails closed
        // nothing else uses it: the status page and the contact form never call the verifier
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->post('/portal/complaints/status', ['receipt_no' => 'C69-DEM2K7XQ', 'tracking_code' => 'TNTHAN4ME6QP'])->assertOk();
        $this->post('/portal/contact', ['name' => 'สมชาย', 'email' => 'a@example.test', 'subject' => 'ติดต่อ', 'message' => 'ข้อความที่ยาวพอสมควร', 'consent' => '1'])->assertRedirect();
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- internal pull

    public function test_the_internal_pull_creates_the_case_once_with_files_and_only_the_hash_of_the_code(): void
    {
        DB::table('cmp_cases')->delete();
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $name = 'ผู้ร้องลับ ทดสอบ';
        $this->post('/portal/complaints', ['category' => 'general', 'name' => $name, 'email' => 'real.person@example.test', 'consent' => '1', 'subject' => 'ถนนหน้าโรงเรียนชำรุด', 'detail' => 'หลุมบนถนนหน้าประตูโรงเรียนต้นธารวิทยา อันตรายต่อนักเรียน',
            'files' => [UploadedFile::fake()->createWithContent('หลุม.png', $this->png())]])->assertStatus(201);

        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(['pulled' => 1, 'handled' => 1, 'quarantined' => 0, 'failed' => 0], $r);
        $case = DB::table('cmp_cases')->first();
        $this->assertSame('general', $case->category);
        $this->assertSame('received', $case->status);
        $this->assertSame(now('Asia/Bangkok')->addDays(15)->toDateString(), substr((string) $case->due_on, 0, 10));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $case->track_hash);
        $this->assertNotNull($case->envelope_hash);
        $this->assertStringNotContainsString($name, (string) $case->contact, 'contact is encrypted at rest');
        $this->assertStringNotContainsString('real.person@example.test', (string) $case->contact);
        $f = DB::table('cmp_files')->first();
        $this->assertSame('หลุม.png', $f->name);
        $this->assertMatchesRegularExpression('~^complaints/1/[a-f0-9]{32}\.png$~D', $f->path);
        $this->assertSame($this->png(), Storage::disk('local')->get($f->path));
        $this->assertSame(1, DB::table('cmp_actions')->where('case_id', $case->id)->where('action', 'received')->count());

        // pulling again, and the same envelope handled again (a lost ack), never makes a second case
        $this->assertSame(0, $this->puller()->run()['pulled']);
        $inner = $case->envelope_hash;
        $again = (new InboxHandler())->handle(['receipt_no' => $case->receipt_no, 'track_code' => 'AAAAAAAAAAAA', 'category' => 'general', 'anonymous' => true, 'subject' => 'x y z', 'detail' => 'รายละเอียดยาวพอสมควรแล้ว'], [], ['envelope' => $inner]);
        $this->assertSame(['id' => (int) $case->id, 'duplicate' => true], $again);
        $this->assertSame(1, DB::table('cmp_cases')->count());
    }

    public function test_intake_refuses_bad_payloads_and_cannot_overwrite_an_existing_case(): void
    {
        $a = $this->intake([], [], str_repeat('a', 64));
        $base = ['receipt_no' => Complaints::newReceipt(), 'track_code' => Complaints::newTrackingCode(), 'category' => 'general', 'anonymous' => true, 'subject' => 'หัวข้อ', 'detail' => 'รายละเอียดยาวพอสมควรแล้ว'];
        $bad = [
            ['receipt_no' => 'X1'], ['receipt_no' => 'C69-ABCDEFG0'], ['track_code' => 'short'], ['track_code' => 'AAAAAAAAAAA0'], ['category' => 'secret'], ['anonymous' => 'maybe'],
            ['detail' => 'สั้น'], ['subject' => str_repeat('ก', 161)], ['email' => 'not-mail', 'anonymous' => false, 'name' => 'x'],
            ['anonymous' => false], // named without a name and contact
            ['anonymous' => false, 'name' => 'ชื่อ'], // no contact channel
        ];
        foreach ($bad as $i => $over) {
            try { (new InboxHandler())->handle($over + $base, [], []); $this->fail('accepted bad payload #'.$i); }
            catch (InboxRejected $e) { $this->assertSame('invalid', $e->reason); }
        }
        // a different envelope that names an existing receipt number is refused, and the case stays as it was
        $before = (array) DB::table('cmp_cases')->where('id', $a['id'])->first();
        try { (new InboxHandler())->handle(['receipt_no' => $a['receipt'], 'track_code' => 'BBBBBBBBBBBB'] + $base, [], ['envelope' => str_repeat('b', 64)]); $this->fail('hijack accepted'); }
        catch (InboxRejected $e) { $this->assertSame('receipt_taken', $e->reason); }
        $this->assertSame($before, (array) DB::table('cmp_cases')->where('id', $a['id'])->first());
        // an in-process retry of the very same submission (same code) is the same case
        $same = (new InboxHandler())->handle($a['payload'], [], []);
        $this->assertSame(['id' => $a['id'], 'duplicate' => true], $same);
    }

    public function test_intake_ceilings_do_not_depend_on_payload_hints_and_files_ceiling_is_independent(): void
    {
        config(['saan.inbox.complaint_per_minute' => 3]);
        for ($i = 0; $i < 3; $i++) $this->intake(['category' => ['general', 'corruption', 'suggestion'][$i], 'anonymous' => (bool) ($i % 2)], [], hash('sha256', 'env'.$i));
        $n = DB::table('cmp_cases')->count();
        foreach (['general', 'corruption', 'info_request'] as $cat) {
            try { $this->intake(['category' => $cat, 'anonymous' => true], [], hash('sha256', 'over'.$cat)); $this->fail('ceiling dodged by category '.$cat); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('transient', $e->getMessage()); }
        }
        $this->assertSame($n, DB::table('cmp_cases')->count());
        // the same ceiling applies to an in-process submission (no envelope): combined / internal filing cannot skip it
        try { $this->intake([], [], null); $this->fail('direct filing skipped the ceiling'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('flood_ceiling', $e->getMessage()); }
        $this->assertSame($n, DB::table('cmp_cases')->count());

        RateLimiter::clear('complaint-inbox-min');
        config(['saan.inbox.complaint_per_minute' => 100, 'saan.inbox.complaint_files_mb_per_day' => 1]);
        $big = ['name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 700000, 'sha256' => str_repeat('0', 64), 'content' => '%PDF-1.4'.str_repeat('x', 700000)];
        $this->intake([], [$big], hash('sha256', 'f1'));
        try { $this->intake([], [$big], hash('sha256', 'f2')); $this->fail('daily attachment bytes ceiling'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('transient', $e->getMessage()); }
        $this->assertSame(1, DB::table('cmp_files')->count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles('complaints')), 'no file of a refused envelope stays on disk');
    }

    public function test_a_handler_failure_leaves_no_case_and_no_orphan_file(): void
    {
        $file = ['name' => 'a.png', 'mime' => 'image/png', 'size' => strlen($this->png()), 'sha256' => hash('sha256', $this->png()), 'content' => $this->png()];
        $this->ddl(fn () => DB::unprepared("create trigger cmp_files_fail before insert on cmp_files for each row begin if (select count(*) from cmp_files) >= 1 then signal sqlstate '45000' set message_text = 'boom'; end if; end"));
        try {
            $this->intake([], [$file], hash('sha256', 'ok1')); // first file row passes
            $before = DB::table('cmp_cases')->count();
            try { $this->intake([], [$file], hash('sha256', 'ok2')); $this->fail('expected the insert to fail'); } catch (\Throwable $e) { $this->assertStringContainsString('boom', $e->getMessage()); }
            $this->assertSame($before, DB::table('cmp_cases')->count(), 'the case is rolled back with its file rows');
            $this->assertCount(1, Storage::disk('local')->allFiles('complaints'), 'the file written before the failure is removed');
        } finally {
            $this->ddl(fn () => DB::unprepared('drop trigger if exists cmp_files_fail'));
        }
    }

    // ---------------------------------------------------------------- tracking lookup

    public function test_tracking_lookup_shows_only_status_dates_and_reply_and_never_reveals_whether_a_receipt_exists(): void
    {
        DB::table('cmp_cases')->delete();
        $c = $this->intake(['category' => 'corruption', 'subject' => 'หัวข้อลับที่ห้ามแสดง', 'detail' => 'รายละเอียดลับที่ห้ามแสดงบนหน้าสาธารณะ', 'name' => 'ผู้ร้องห้ามแสดงชื่อ']);
        $officer = $this->officer();
        $exec = $this->executive();
        Settings::put('complaints.officer_user_ids', [$officer->id, $exec->id]);
        $this->actingAs($exec);
        $v = $this->version($c['id']);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/reply', ['version' => $v, 'text' => 'โรงเรียนรับเรื่องไว้ตรวจสอบแล้ว'])->assertOk();
        $this->postJson('/api/complaints/cases/'.$c['id'].'/notes', ['body' => 'บันทึกภายในลับสุดยอด'])->assertCreated();
        auth()->logout();
        $this->app['session']->flush();

        $res = $this->post('/portal/complaints/status', ['receipt_no' => strtolower($c['receipt']), 'tracking_code' => implode(' ', str_split(strtolower($c['code']), 4))]);
        $res->assertOk()->assertSee($c['receipt'])->assertSee('ตอบกลับแล้ว')->assertSee('ทุจริตและประพฤติมิชอบ')->assertSee('โรงเรียนรับเรื่องไว้ตรวจสอบแล้ว');
        foreach (['หัวข้อลับที่ห้ามแสดง', 'รายละเอียดลับที่ห้ามแสดง', 'ผู้ร้องห้ามแสดงชื่อ', 'บันทึกภายในลับสุดยอด', 'complainant@demo.school.test', $exec->name, $officer->name] as $secret) $res->assertDontSee($secret);
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame('no-store, private', $res->headers->get('Cache-Control'));

        // wrong code, unknown receipt and malformed input: one and the same answer
        $strip = fn ($html) => preg_replace('/name="_token" value="[^"]*"/', '', (string) $html);
        $wrong = $this->post('/portal/complaints/status', ['receipt_no' => $c['receipt'], 'tracking_code' => 'ZZZZZZZZZZZZ']);
        $unknown = $this->post('/portal/complaints/status', ['receipt_no' => 'C69-ZZZZZZZZ', 'tracking_code' => $c['code']]);
        $malformed = $this->post('/portal/complaints/status', ['receipt_no' => 'hello', 'tracking_code' => 'x']);
        foreach ([$wrong, $unknown, $malformed] as $r) { $r->assertStatus(404)->assertSee(PublicStatus::GENERIC); $r->assertDontSee('ตอบกลับแล้ว'); }
        $this->assertSame($strip($wrong->getContent()), $strip($unknown->getContent()));
        $this->assertSame($strip($wrong->getContent()), $strip($malformed->getContent()));
        // the code only travels by POST: a GET with it in the URL shows the empty form and nothing else
        $this->get('/portal/complaints/status?receipt_no='.$c['receipt'].'&tracking_code='.$c['code'])->assertOk()->assertDontSee('ตอบกลับแล้ว');
        // the receipt of case A with the code of case B is wrong
        $d = $this->intake();
        $this->post('/portal/complaints/status', ['receipt_no' => $c['receipt'], 'tracking_code' => $d['code']])->assertStatus(404);
    }

    public function test_lookup_is_rate_limited_per_network_and_every_try_counts(): void
    {
        $this->openBoundary();
        $c = $this->intake();
        $ip = '2001:db8:1111:2222::';
        for ($i = 1; $i <= PublicStatus::PER_NET; $i++) $this->withServerVariables(['REMOTE_ADDR' => $ip.$i])->post('/portal/complaints/status', ['receipt_no' => 'C69-ZZZZZZZZ', 'tracking_code' => 'ZZZZZZZZZZZZ'])->assertStatus(404);
        // the 21st try inside the same /64 is refused even with the right pair, and says nothing about the pair
        $r = $this->withServerVariables(['REMOTE_ADDR' => $ip.'ffff'])->post('/portal/complaints/status', ['receipt_no' => $c['receipt'], 'tracking_code' => $c['code']]);
        $r->assertStatus(429)->assertSee(PublicStatus::THROTTLED)->assertDontSee($c['receipt']);
        // another network is not affected
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->post('/portal/complaints/status', ['receipt_no' => $c['receipt'], 'tracking_code' => $c['code']])->assertOk();
    }

    public function test_the_public_edition_answers_from_the_projected_status_table_only(): void
    {
        DB::table('cmp_cases')->delete();
        $this->publicSide();
        $this->pairAll();
        $c = $this->intake(['subject' => 'หัวข้อภายในที่ต้องไม่ไปถึงหน้าบ้าน', 'detail' => 'รายละเอียดภายในที่ต้องไม่ไปถึงหน้าบ้าน']);
        $exec = $this->officer();
        Settings::put('complaints.officer_user_ids', [$exec->id]);
        $this->actingAs($exec);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/reply', ['version' => $this->version($c['id']), 'text' => 'ตอบผู้ร้องแล้ว (ข้อความสาธิต)'])->assertOk();
        $this->publish();
        $this->assertSame(1, DB::connection('pubdb')->table('pub_complaint_status')->count());
        $row = (array) DB::connection('pubdb')->table('pub_complaint_status')->first();
        $this->assertSame($c['receipt'], $row['receipt_no']);
        $this->assertSame(Complaints::trackHash($c['receipt'], $c['code']), $row['track_hash']);
        $this->assertNotSame($c['code'], $row['track_hash']);
        $this->assertSame(0, DB::connection('pubdb')->table('cmp_cases')->count());

        auth()->logout();
        $this->as('public', 'pubdb');
        $this->post('/portal/complaints/status', ['receipt_no' => $c['receipt'], 'tracking_code' => $c['code']])->assertOk()->assertSee('ตอบกลับแล้ว')->assertSee('ตอบผู้ร้องแล้ว (ข้อความสาธิต)')->assertDontSee('หัวข้อภายในที่ต้องไม่ไปถึงหน้าบ้าน');
        $this->post('/portal/complaints/status', ['receipt_no' => $c['receipt'], 'tracking_code' => 'ZZZZZZZZZZZZ'])->assertStatus(404)->assertSee(PublicStatus::GENERIC);
        $this->post('/portal/complaints/status', ['receipt_no' => 'C69-ZZZZZZZZ', 'tracking_code' => $c['code']])->assertStatus(404)->assertSee(PublicStatus::GENERIC);
        // the staff API does not exist on the public edition
        $this->getJson('/api/complaints/meta')->assertNotFound();
    }

    // ---------------------------------------------------------------- access: officers, executives, accused

    public function test_corruption_cases_are_visible_only_to_the_configured_executives(): void
    {
        DB::table('cmp_cases')->delete();
        $officer = $this->officer();
        $exec = $this->executive();
        $admin = User::where('role', 'admin')->first();
        $principal = User::where('role', 'principal')->first(); // not in the executive list
        $gen = $this->intake(['category' => 'general', 'subject' => 'เรื่องทั่วไปสำหรับทดสอบ']);
        $cor = $this->intake(['category' => 'corruption', 'subject' => 'เรื่องทุจริตสำหรับทดสอบ']);

                $this->actingAs($officer);
        $subjects = array_column($this->getJson('/api/complaints/cases')->assertOk()->json('data'), 'subject');
        $this->assertSame(['เรื่องทั่วไปสำหรับทดสอบ'], $subjects, 'an officer works the general cases and never sees corruption');
        $this->getJson('/api/complaints/cases/'.$cor['id'])->assertNotFound();
        $this->getJson('/api/complaints/cases?q='.urlencode('ทุจริตสำหรับ'))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/notes', ['body' => 'ลองเขียน'])->assertNotFound();
        $this->assertStringNotContainsString($cor['receipt'], (string) $this->get('/api/complaints/cases/export')->assertOk()->getContent());
        // the officer list is set, so the admin and the principal are not officers (and not executives): they see nothing of the module
        foreach ([$admin, $principal] as $u) {
            $this->actingAs($u);
            $this->getJson('/api/complaints/cases')->assertForbidden();
            $this->getJson('/api/complaints/cases/'.$cor['id'])->assertForbidden();
        }

$this->actingAs($exec);
        $this->getJson('/api/complaints/cases/'.$cor['id'])->assertOk()->assertJsonPath('case.receipt_no', $cor['receipt']);
        $list = $this->getJson('/api/complaints/cases')->assertOk()->json('data');
        $this->assertSame(['เรื่องทุจริตสำหรับทดสอบ'], array_column($list, 'subject'), 'an executive works corruption cases, not the general ones');
        $this->getJson('/api/complaints/cases/'.$gen['id'])->assertNotFound();

        // an officer cannot be assigned a corruption case, an executive can
        $v = $this->version($cor['id']);
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/assign', ['version' => $v, 'assignee_id' => $officer->id])->assertStatus(422);
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/assign', ['version' => $v, 'assignee_id' => $exec->id])->assertOk()->assertJsonPath('case.status', 'in_progress');
        $this->assertSame($cor['id'], (int) DB::table('cmp_cases')->where('assignee_id', $exec->id)->value('id'));
    }

    public function test_with_empty_lists_admin_and_principal_work_general_cases_and_nobody_sees_corruption(): void
    {
        DB::table('cmp_cases')->delete();
        $gen = $this->intake(['category' => 'general']);
        $cor = $this->intake(['category' => 'corruption']);
        foreach (['admin', 'principal'] as $role) {
            $this->actingAs(User::where('role', $role)->first());
            $this->getJson('/api/complaints/cases')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.receipt_no', $gen['receipt']);
            $this->getJson('/api/complaints/cases/'.$cor['id'])->assertNotFound();
        }
        $this->getJson('/api/complaints/meta')->assertOk()->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'ผู้บริหาร'));
        $this->actingAs(User::where('role', 'teacher')->first());
        $this->getJson('/api/complaints/meta')->assertForbidden();
    }

    public function test_an_accused_account_can_never_see_search_export_or_be_assigned_the_case(): void
    {
        DB::table('cmp_cases')->delete();
        $boss = $this->executive('ผู้บริหารหลัก');
        $acc = $this->executive('ผู้ถูกร้องผู้บริหาร'); // holds the executive role AND will be an officer
        Settings::put('complaints.officer_user_ids', [$acc->id, $boss->id]);
        $peer = $this->officer('เพื่อนร่วมงาน');
        $cor = $this->intake(['category' => 'corruption', 'subject' => 'ผู้ถูกร้องต้องไม่เห็นเรื่องนี้']);
        $gen = $this->intake(['category' => 'general', 'subject' => 'เรื่องทั่วไปที่ผู้ถูกร้องยังเห็นได้']);
        $this->assertTrue(Access::worksCategory($acc, 'corruption'));

        $this->actingAs($boss);
        $v = $this->version($cor['id']);
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/assign', ['version' => $v, 'assignee_id' => $acc->id])->assertOk(); // before he is named, the assignment works
        $v = $this->version($cor['id']);
        // naming oneself is refused (a slip would lock the actor out)
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/accused', ['version' => $v, 'user_ids' => [$boss->id]])->assertStatus(422);
        $res = $this->postJson('/api/complaints/cases/'.$cor['id'].'/accused', ['version' => $v, 'user_ids' => [$acc->id]])->assertOk();
        $res->assertJsonPath('accused.0.id', $acc->id)->assertJsonPath('case.assignee', null);
        $this->assertNotContains($acc->id, array_column($res->json('assignable'), 'id'));
        $this->assertTrue(Access::isAccused($acc->id, $cor['id']));
        // an accused person is not offered as assignee and cannot be assigned even by direct request
        $v = $this->version($cor['id']);
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/assign', ['version' => $v, 'assignee_id' => $acc->id])->assertStatus(422);
        // the audit trail carries counts only, never who is accused
        $audit = json_encode(DB::table('audit_events')->where('action', 'like', 'complaints.%')->get());
        $this->assertStringNotContainsString('corruption', $audit, 'no audit row names the kind of case');
        $this->assertStringNotContainsString('"user_id":'.$acc->id, $audit);
        $this->assertStringNotContainsString((string) $acc->email, $audit);

        $this->actingAs($acc);
        $this->getJson('/api/complaints/meta')->assertOk(); // he still works the module for other cases
        $this->getJson('/api/complaints/cases/'.$cor['id'])->assertNotFound();
        $ids = array_column($this->getJson('/api/complaints/cases')->assertOk()->json('data'), 'id');
        $this->assertNotContains($cor['id'], $ids);
        $this->getJson('/api/complaints/cases?q='.urlencode($cor['receipt']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/complaints/cases?q='.urlencode('ผู้ถูกร้องต้องไม่เห็น'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/complaints/cases?category=corruption&status=in_progress&overdue=1')->assertOk()->assertJsonCount(0, 'data');
        $csv = (string) $this->get('/api/complaints/cases/export')->getContent();
        $this->assertStringNotContainsString($cor['receipt'], $csv);
        $this->assertStringNotContainsString('ผู้ถูกร้องต้องไม่เห็น', $csv);
        foreach (['reply' => ['text' => 'x'], 'close' => [], 'reopen' => [], 'assign' => ['assignee_id' => $acc->id], 'due' => ['due_on' => '2030-01-01'], 'accused' => ['user_ids' => []]] as $path => $extra) {
            $this->postJson('/api/complaints/cases/'.$cor['id'].'/'.$path, ['version' => $this->version($cor['id'])] + $extra)->assertNotFound();
        }
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/notes', ['body' => 'x'])->assertNotFound();
        $this->getJson('/api/complaints/cases/'.$cor['id'].'/files/1')->assertNotFound();
        $this->assertSame(1, DB::table('cmp_cases')->where('id', $cor['id'])->where('status', 'in_progress')->count(), 'untouched by the accused');

        // the same person is hidden from a general case once named there, an unrelated general case stays visible
        $this->getJson('/api/complaints/cases/'.$gen['id'])->assertOk();
        $this->actingAs($peer);
        $this->postJson('/api/complaints/cases/'.$gen['id'].'/accused', ['version' => $this->version($gen['id']), 'user_ids' => [$acc->id]])->assertOk();
        $this->actingAs($acc);
        $this->getJson('/api/complaints/cases/'.$gen['id'])->assertNotFound();
        // the account that holds every role (admin, officer list, executive list) is hidden just the same
        $admin = User::where('role', 'admin')->first();
        Settings::put('complaints.officer_user_ids', array_merge(Access::officerIds(), [$admin->id]));
        Settings::put('complaints.executive_user_ids', array_merge(Access::executiveIds(), [$admin->id]));
        $this->actingAs($admin);
        $this->postJson('/api/complaints/cases/'.$gen['id'].'/accused', ['version' => $this->version($gen['id']), 'user_ids' => [$acc->id, $admin->id]])->assertStatus(422);
        $this->actingAs($boss);
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/accused', ['version' => $this->version($cor['id']), 'user_ids' => [$acc->id, $admin->id]])->assertOk();
        $this->actingAs($admin);
        $this->getJson('/api/complaints/cases/'.$cor['id'])->assertNotFound();
        $this->assertNotContains($cor['id'], array_column($this->getJson('/api/complaints/cases')->json('data'), 'id'));
        // removing him from the list brings the case back for him
        $this->actingAs($boss);
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/accused', ['version' => $this->version($cor['id']), 'user_ids' => [$admin->id]])->assertOk();
        $this->actingAs($acc);
        $this->getJson('/api/complaints/cases/'.$cor['id'])->assertOk();
    }

    // ---------------------------------------------------------------- staff workflow, audit, files

    public function test_case_workflow_is_audited_versioned_and_replies_reach_the_status_row(): void
    {
        DB::table('cmp_cases')->delete();
        $officer = $this->officer();
        $other = $this->officer('เจ้าหน้าที่อีกคน');
        $c = $this->intake(['category' => 'info_request']);
        $this->actingAs($officer);

        $show = $this->getJson('/api/complaints/cases/'.$c['id'])->assertOk()->assertJsonPath('case.contact.email', 'complainant@demo.school.test');
        $v = $show->json('case.version');
        $this->postJson('/api/complaints/cases/'.$c['id'].'/assign', ['version' => $v, 'assignee_id' => $other->id])->assertOk()->assertJsonPath('case.status', 'in_progress')->assertJsonPath('case.assignee.id', $other->id);
        // a stale version is a 409 and changes nothing
        $this->postJson('/api/complaints/cases/'.$c['id'].'/assign', ['version' => $v, 'assignee_id' => $officer->id])->assertStatus(409);
        $v = $this->version($c['id']);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/due', ['version' => $v, 'due_on' => '2020-01-01'])->assertStatus(422); // before it was received
        $this->postJson('/api/complaints/cases/'.$c['id'].'/due', ['version' => $v, 'due_on' => now()->addDays(40)->toDateString()])->assertOk();
        $this->postJson('/api/complaints/cases/'.$c['id'].'/notes', ['body' => 'โทรสอบถามเพิ่มเติมแล้ว'], ['Idempotency-Key' => 'note-1'])->assertCreated();
        $this->postJson('/api/complaints/cases/'.$c['id'].'/notes', ['body' => 'โทรสอบถามเพิ่มเติมแล้ว'], ['Idempotency-Key' => 'note-1'])->assertCreated();
        $this->assertSame(1, DB::table('cmp_notes')->where('case_id', $c['id'])->count(), 'an idempotent retry adds one note');
        $this->postJson('/api/complaints/cases/'.$c['id'].'/reply', ['version' => $this->version($c['id']), 'text' => '  '])->assertStatus(422);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/reply', ['version' => $this->version($c['id']), 'text' => 'ส่งสำเนาแผนให้ทางอีเมลแล้ว'])->assertOk()->assertJsonPath('case.status', 'replied');
        $this->postJson('/api/complaints/cases/'.$c['id'].'/close', ['version' => $this->version($c['id'])])->assertOk()->assertJsonPath('case.status', 'closed');
        $this->postJson('/api/complaints/cases/'.$c['id'].'/close', ['version' => $this->version($c['id'])])->assertStatus(422);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/reply', ['version' => $this->version($c['id']), 'text' => 'ตอบหลังปิด'])->assertStatus(422);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/reopen', ['version' => $this->version($c['id'])])->assertOk()->assertJsonPath('case.status', 'in_progress');
        $this->postJson('/api/complaints/cases/'.$c['id'].'/reopen', ['version' => $this->version($c['id'])])->assertStatus(422);

        $actions = DB::table('cmp_actions')->where('case_id', $c['id'])->where('action', '!=', 'viewed')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['received', 'assigned', 'due_changed', 'note', 'replied', 'closed', 'reopened'], $actions);
        // every write is in audit_events with the actor, and no audit row carries complaint text or contact
        $audit = DB::table('audit_events')->where('actor_id', $officer->id)->where('action', 'like', 'complaints.%')->get();
        $this->assertSame(['complaints.case_updated'], $audit->pluck('action')->unique()->values()->all(), 'one neutral action, no step names');
        $this->assertGreaterThanOrEqual(8, $audit->count());
        $blob = json_encode($audit, JSON_UNESCAPED_UNICODE);
        foreach (['โทรสอบถามเพิ่มเติม', 'ส่งสำเนาแผน', 'complainant@demo.school.test', 'ผู้ร้องตัวอย่าง'] as $leak) $this->assertStringNotContainsString($leak, $blob);
        // an assignee that is not an officer is refused
        $teacher = $this->addUser('teacher', 'ครูทั่วไป');
        $this->postJson('/api/complaints/cases/'.$c['id'].'/assign', ['version' => $this->version($c['id']), 'assignee_id' => $teacher->id])->assertStatus(422);
        // only the status moved the public "updated" date: internal work (assignment, notes) did not
        $row = DB::table('cmp_cases')->find($c['id']);
        $this->assertNotNull($row->status_changed_at);
    }

    public function test_attachments_download_only_through_the_authorised_controller_from_private_storage(): void
    {
        DB::table('cmp_cases')->delete();
        $officer = $this->officer();
        $stranger = $this->addUser('editor', 'บรรณาธิการ');
        $file = ['name' => 'หลักฐาน.png', 'mime' => 'image/png', 'size' => strlen($this->png()), 'sha256' => hash('sha256', $this->png()), 'content' => $this->png()];
        $c = $this->intake([], [$file]);
        $f = DB::table('cmp_files')->first();
        $this->assertFalse(is_file(public_path($f->path)), 'not under public/');
        $this->get('/storage/'.$f->path)->assertNotFound();
        $this->get('/api/complaints/cases/'.$c['id'].'/files/'.$f->id)->assertRedirect('/login');

        $this->actingAs($officer);
        $r = $this->get('/api/complaints/cases/'.$c['id'].'/files/'.$f->id)->assertOk();
        $this->assertSame($this->png(), $r->streamedContent());
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $r->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('attachment', (string) $r->headers->get('Content-Disposition'));
        $this->assertTrue(DB::table('cmp_actions')->where('case_id', $c['id'])->where('action', 'file_opened')->exists(), 'opening an attachment is logged');
        $this->getJson('/api/complaints/cases/'.$c['id'].'/files/9999')->assertNotFound();
        // the file id of another case does not open through this case
        $other = $this->intake([], [$file]);
        $otherFile = DB::table('cmp_files')->where('case_id', $other['id'])->first();
        $this->getJson('/api/complaints/cases/'.$c['id'].'/files/'.$otherFile->id)->assertNotFound();
        // a staff account without a role in the module gets nothing
        $this->actingAs($stranger);
        $this->getJson('/api/complaints/cases/'.$c['id'].'/files/'.$f->id)->assertForbidden();
        $this->assertSame(1, preg_match('/storage_tables/', (string) file_get_contents(base_path('modules/complaints/module.json'))));
    }

    // ---------------------------------------------------------------- SLA and overdue

    public function test_sla_defaults_per_category_settings_override_and_overdue_filter(): void
    {
        DB::table('cmp_cases')->delete();
        $officer = $this->officer();
        $exec = $this->executive();
        $today = CarbonImmutable::now('Asia/Bangkok');
        $g = $this->intake(['category' => 'general']);
        $k = $this->intake(['category' => 'corruption']);
        $this->assertSame($today->addDays(15)->toDateString(), substr((string) DB::table('cmp_cases')->find($g['id'])->due_on, 0, 10));
        $this->assertSame($today->addDays(30)->toDateString(), substr((string) DB::table('cmp_cases')->find($k['id'])->due_on, 0, 10));
        $this->assertSame(['general' => 15, 'corruption' => 30, 'suggestion' => 15, 'info_request' => 15], array_combine(array_keys(Complaints::CATEGORIES), array_map(fn ($c) => Complaints::slaDays($c), array_keys(Complaints::CATEGORIES))));
        Settings::put('complaints.sla_suggestion', 7);
        Settings::put('complaints.sla_info_request', 'bad');
        $s = $this->intake(['category' => 'suggestion']);
        $this->assertSame($today->addDays(7)->toDateString(), substr((string) DB::table('cmp_cases')->find($s['id'])->due_on, 0, 10));
        $this->assertSame(15, Complaints::slaDays('info_request'), 'an invalid setting falls back to the default');

        // age two cases: one overdue, one closed after its due date (not overdue)
        $late = $this->intake(['category' => 'general']);
        $closed = $this->intake(['category' => 'general']);
        DB::table('cmp_cases')->where('id', $late['id'])->update(['due_on' => $today->subDay()->toDateString()]);
        DB::table('cmp_cases')->where('id', $closed['id'])->update(['due_on' => $today->subDays(5)->toDateString(), 'status' => 'closed', 'closed_at' => now()]);
        DB::table('cmp_cases')->where('id', $g['id'])->update(['due_on' => $today->toDateString()]); // due today is not overdue yet
        $this->actingAs($officer);
        $over = $this->getJson('/api/complaints/cases?overdue=1')->assertOk()->json('data');
        $this->assertSame([$late['id']], array_column($over, 'id'));
        $this->assertTrue($over[0]['overdue']);
        $all = collect($this->getJson('/api/complaints/cases')->json('data'));
        $this->assertFalse($all->firstWhere('id', $closed['id'])['overdue']);
        $this->assertFalse($all->firstWhere('id', $g['id'])['overdue']);
        $this->assertSame($closed['id'], $all->last()['id'], 'closed cases are listed last');
        $this->assertSame([$closed['id']], array_column($this->getJson('/api/complaints/cases?status=closed')->json('data'), 'id'));
        $this->assertSame([$s['id']], array_column($this->getJson('/api/complaints/cases?category=suggestion')->json('data'), 'id'));
        $this->actingAs($exec);
        $this->assertSame([$k['id']], array_column($this->getJson('/api/complaints/cases')->json('data'), 'id'));
    }

    // ---------------------------------------------------------------- fiscal year report

    public function test_fiscal_year_boundaries_buddhist_era_labels_and_report_numbers(): void
    {
        $this->assertSame(2569, Complaints::fiscalYear(CarbonImmutable::parse('2025-10-01 00:00:00', 'Asia/Bangkok')));
        $this->assertSame(2568, Complaints::fiscalYear(CarbonImmutable::parse('2025-09-30 23:59:59', 'Asia/Bangkok')));
        $this->assertSame(2569, Complaints::fiscalYear(CarbonImmutable::parse('2026-09-30 23:59:59', 'Asia/Bangkok')));
        $this->assertSame(2570, Complaints::fiscalYear(CarbonImmutable::parse('2026-10-01 00:00:00', 'Asia/Bangkok')));
        $this->assertSame(2569, Complaints::fiscalYear(CarbonImmutable::parse('2025-09-30 17:30:00', 'UTC')), 'time zone aware: 30 Sep 17:30 UTC is 1 Oct 00:30 in Bangkok');
        $this->assertSame('ปีงบประมาณ พ.ศ. 2569 (1 ตุลาคม 2568 ถึง 30 กันยายน 2569)', Complaints::fiscalLabel(2569));
        [$from, $to] = Complaints::fiscalRange(2569);
        $this->assertSame('2025-10-01 00:00:00', $from);
        $this->assertSame('2026-09-30 23:59:59', $to);

        DB::table('cmp_cases')->delete();
        $mk = function (string $cat, string $at, ?string $closedAt = null, string $status = 'received') {
            $c = $this->intake(['category' => $cat]);
            DB::table('cmp_cases')->where('id', $c['id'])->update(['received_at' => $at, 'status' => $closedAt ? 'closed' : $status, 'closed_at' => $closedAt]);
            return $c;
        };
        $mk('general', '2025-09-30 23:00:00', '2025-10-02 23:00:00'); // FY 2568
        $mk('general', '2025-10-01 00:30:00', '2025-10-03 00:30:00'); // FY 2569, 2 days
        $mk('general', '2026-03-01 09:00:00', '2026-03-05 09:00:00'); // FY 2569, 4 days
        $mk('general', '2026-04-01 09:00:00', null, 'in_progress');
        $mk('corruption', '2026-05-01 09:00:00', '2026-05-02 09:00:00', 'closed'); // 1 day
        $mk('suggestion', '2026-09-30 22:00:00', null, 'replied');
        $mk('info_request', '2026-10-01 01:00:00'); // FY 2570
        $r = Complaints::report(2569);
        $this->assertSame(5, $r['totals']['total']);
        $this->assertSame(['received' => 0, 'in_progress' => 1, 'replied' => 1, 'closed' => 3], $r['totals']['by_status']);
        $this->assertSame(3, $r['rows']['general']['total']);
        $this->assertSame(3.0, $r['rows']['general']['avg_days']);
        $this->assertSame(1.0, $r['rows']['corruption']['avg_days']);
        $this->assertNull($r['rows']['suggestion']['avg_days']);
        $this->assertSame(2.3, $r['totals']['avg_days']);
        $this->assertSame(1, Complaints::report(2568)['totals']['total']);
        $this->assertSame(1, Complaints::report(2570)['totals']['info_request'] ?? Complaints::report(2570)['rows']['info_request']['total']);
        $this->assertContains(2570, Complaints::fiscalYears());

        // the staff CSV: BOM, Thai headers, numbers only, no personal data of any case
        $officer = $this->officer();
        $this->actingAs($officer);
        $csv = (string) $this->get('/api/complaints/reports/2569/csv')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('ปีงบประมาณ พ.ศ. 2569', $csv);
        $this->assertStringContainsString('เรื่องร้องเรียนทั่วไป,3,0,1,0,2,2,3.0', str_replace("\r", '', $csv));
        $this->assertStringContainsString('รวมทุกประเภท,4,0,1,1,2,2,3.0', str_replace("\r", '', $csv));
        foreach (DB::table('cmp_cases')->get() as $row) foreach ([$row->subject, $row->detail, $row->receipt_no, 'complainant@demo.school.test', 'ผู้ร้องตัวอย่าง'] as $leak) $this->assertStringNotContainsString($leak, $csv);
        $this->getJson('/api/complaints/reports')->assertOk()->assertJsonPath('data.0.fiscal_year', 2570);
        $this->getJson('/api/complaints/reports/2569')->assertOk()->assertJsonPath('totals.total', 4); // an officer who is not an executive does not count the corruption case
        $this->getJson('/api/complaints/reports/1999')->assertNotFound();
    }

    public function test_publishing_a_report_freezes_a_counts_only_snapshot_that_the_public_page_and_ita_use(): void
    {
        DB::table('cmp_cases')->delete();
        $officer = $this->officer();
        $c = $this->intake(['category' => 'general', 'subject' => 'หัวข้อเฉพาะบุคคลที่ห้ามหลุด']);
        DB::table('cmp_cases')->where('id', $c['id'])->update(['received_at' => '2026-02-01 09:00:00']);
        $this->actingAs($officer);
        $this->get('/portal/complaints/reports/2569')->assertNotFound();
        $this->postJson('/api/complaints/reports/2569/publish', ['action' => 'publish'])->assertOk()->assertJsonPath('status', 'published');
        $this->postJson('/api/complaints/reports/2569/publish', ['action' => 'publish'])->assertOk(); // update in place
        $this->postJson('/api/complaints/reports/1/publish', ['action' => 'publish'])->assertNotFound();
        $this->assertSame(1, DB::table('cmp_reports')->count());
        // a later case does not change the published figures
        $late = $this->intake();
        DB::table('cmp_cases')->where('id', $late['id'])->update(['received_at' => '2026-02-02 09:00:00']);
        auth()->logout();
        $page = $this->get('/portal/complaints/reports/2569')->assertOk()->assertSee('ปีงบประมาณ พ.ศ. 2569')->assertSee('เรื่องร้องเรียนทั่วไป');
        $page->assertDontSee('หัวข้อเฉพาะบุคคลที่ห้ามหลุด');
        $this->assertSame(1, Reports::find(2569)['report']['totals']['total']);
        $csv = $this->get('/portal/complaints/reports/2569.csv')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringNotContainsString($c['receipt'], $csv->getContent());
        // a stray string in a stored snapshot never reaches the public rows
        DB::table('cmp_reports')->update(['snapshot' => json_encode(['fiscal_year' => 2569, 'rows' => ['general' => ['label' => 'ชื่อบุคคลลับ', 'total' => 3, 'extra' => 'ชื่อบุคคลลับ']], 'totals' => ['total' => 3], 'note' => 'ชื่อบุคคลลับ'], JSON_UNESCAPED_UNICODE)]);
        $rows = Reports::projectedRows();
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('ชื่อบุคคลลับ', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->assertNull(Complaints::cleanSnapshot('{"fiscal_year":"x"}'));
        // unpublish removes it
        $this->actingAs($officer);
        $this->postJson('/api/complaints/reports/2569/publish', ['action' => 'unpublish'])->assertOk()->assertJsonPath('status', 'draft');
        auth()->logout();
        $this->get('/portal/complaints/reports/2569')->assertNotFound();
        $this->assertSame([], Reports::projectedRows());
    }

    public function test_ita_o13_and_o14_link_themselves_to_the_form_and_the_latest_published_report(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer);
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin);
        $y = $this->postJson('/api/ita/years', ['fiscal_year' => 2569])->assertCreated()->json();
        $topics = [
            ['code' => 'O13', 'title' => 'ช่องทางแจ้งเรื่องร้องเรียนการทุจริตและประพฤติมิชอบ', 'items' => []],
            ['code' => 'o 14', 'title' => 'ข้อมูลเชิงสถิติเรื่องร้องเรียน', 'items' => []],
            ['code' => 'O2', 'title' => 'ช่องทางติดต่อ', 'items' => []],
            ['code' => '', 'title' => 'หัวข้อที่ไม่มีรหัส', 'items' => [['title' => 'O13 แบบฟอร์มของโรงเรียน', 'url' => null, 'media_id' => null, 'note' => ''], ['title' => 'O130 ไม่ใช่', 'url' => null, 'media_id' => null, 'note' => '']]],
        ];
        $this->putJson('/api/ita/years/'.$y['year']['id'], ['version' => $y['year']['version'], 'title' => 'ITA 2569', 'topics' => $topics])->assertOk();
        $v = DB::table('ita_years')->where('id', $y['year']['id'])->value('version');
        $this->postJson('/api/ita/years/'.$y['year']['id'].'/status', ['version' => $v, 'action' => 'publish'])->assertOk();
        auth()->logout();

        $page = $this->get('/portal/ita')->assertOk();
        $html = $page->getContent();
        $form = url('/portal/complaints');
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'href="'.$form.'"'), 'O13, O14 and the item that starts with O13 link to the form');
        $this->assertStringContainsString('href="'.url('/portal/complaints/status').'"', $html);
        $this->assertStringNotContainsString('/portal/complaints/reports/', $html, 'no report is published yet, so there is nothing to link');

        // publish two reports: O14 links to the LATEST one only
        $this->actingAs($officer);
        $this->postJson('/api/complaints/reports/2568/publish', ['action' => 'publish'])->assertOk();
        $this->postJson('/api/complaints/reports/2569/publish', ['action' => 'publish'])->assertOk();
        auth()->logout();
        $html = $this->get('/portal/ita')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.url('/portal/complaints/reports/2569').'"', $html);
        $this->assertStringContainsString('href="'.url('/portal/complaints/reports/2569.csv').'"', $html);
        $this->assertStringNotContainsString('/portal/complaints/reports/2568', $html);
        $this->assertStringNotContainsString('tonthan', (string) file_get_contents(base_path('modules/complaints/src/ItaLinks.php')));
        // the module switched off: the ITA page shows no automatic links
        config(['saan.modules.disable' => 'complaints']);
        app(ModuleRegistry::class)->flush();
        $this->assertStringNotContainsString('/portal/complaints', $this->get('/portal/ita')->getContent());
    }

    // ---------------------------------------------------------------- projection

    public function test_the_projection_never_carries_personal_or_internal_fields(): void
    {
        DB::table('cmp_cases')->delete();
        $officer = $this->officer();
        $marker = 'MARK'.bin2hex(random_bytes(4));
        $c = $this->intake(['category' => 'corruption', 'name' => 'ชื่อ'.$marker, 'email' => $marker.'@demo.school.test', 'phone' => '0812345678', 'subject' => 'หัวข้อ'.$marker, 'detail' => 'รายละเอียด'.$marker.' ยาวพอ'],
            [['name' => $marker.'.png', 'mime' => 'image/png', 'size' => strlen($this->png()), 'sha256' => hash('sha256', $this->png()), 'content' => $this->png()]]);
        Settings::put('complaints.executive_user_ids', [$officer->id]);
        $this->actingAs($officer);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/notes', ['body' => 'บันทึก'.$marker])->assertCreated();
        $this->postJson('/api/complaints/cases/'.$c['id'].'/assign', ['version' => $this->version($c['id']), 'assignee_id' => $officer->id])->assertOk();
        $this->postJson('/api/complaints/cases/'.$c['id'].'/reply', ['version' => $this->version($c['id']), 'text' => 'ตอบสาธารณะอย่างปลอดภัย'])->assertOk();
        $this->postJson('/api/complaints/reports/'.Complaints::currentFiscalYear().'/publish', ['action' => 'publish'])->assertOk();

        $decl = collect(Projection::declared(true))->keyBy('table');
        $this->assertSame(['receipt_no', 'track_hash', 'category_label', 'status', 'received_at', 'updated_at', 'public_reply'], array_keys($decl['pub_complaint_status']['columns']));
        $this->assertSame('complaint_status', $decl['pub_complaint_status']['derived']);
        $this->assertSame(['fiscal_year', 'snapshot', 'published_at'], array_keys($decl['pub_complaint_reports']['columns']));
        $this->assertSame('complaints', $decl['pub_complaint_status']['source']);
        foreach (['cmp_cases', 'cmp_actions', 'cmp_notes', 'cmp_files', 'cmp_accused', 'cmp_reports'] as $t) {
            $this->assertTrue(Projection::isForbiddenTable($t), $t.' must never be exported');
            $this->assertNull($decl[$t] ?? null);
        }
        // a declaration that tries to export a case table is refused by the validator
        foreach (['cmp_cases', 'cmp_notes'] as $t) {
            try { Projection::validate([['table' => $t, 'columns' => ['id' => 'keep']]], 'complaints', false); $this->fail($t.' accepted'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('ห้ามส่งออก', $e->getMessage()); }
        }
        // the derived rows built by the real builder
        $b = new Builder();
        $rows = $b->rows('pub_complaint_status');
        $this->assertCount(1, $rows);
        $row = $rows[$c['receipt']];
        $this->assertSame(['category_label', 'public_reply', 'receipt_no', 'received_at', 'status', 'track_hash', 'updated_at'], array_keys($row));
        $this->assertSame('ทุจริตและประพฤติมิชอบ', $row['category_label']);
        $this->assertSame(hash_hmac('sha256', 'saan-complaint-track-v1|'.$c['receipt'].'|'.$c['code'], \App\Support\Saan\HashKey::publicUnlock()), $row['track_hash']);
        $blob = json_encode([$rows, $b->rows('pub_complaint_reports')], JSON_UNESCAPED_UNICODE);
        foreach ([$marker, '0812345678', $c['code'], 'complainant', 'assignee', $officer->name, 'detail', 'note'] as $leak) $this->assertStringNotContainsString($leak, $blob);
        $this->assertContains('pub_complaint_status', $b->streams());
        // a change of a case marks only its own stream for the publisher
        config(['saan.public_push_url' => self::PUSH]);
        Outbox::forget();
        Outbox::watch();
        DB::table('pub_outbox')->delete();
        $this->postJson('/api/complaints/cases/'.$c['id'].'/close', ['version' => $this->version($c['id'])])->assertOk();
        $this->assertContains('pub_complaint_status', DB::table('pub_outbox')->pluck('stream')->all());
    }

    public function test_projection_through_the_real_publisher_puts_only_the_status_rows_on_the_public_side(): void
    {
        DB::table('cmp_cases')->delete();
        $this->publicSide();
        $this->pairAll();
        $marker = 'MARK'.bin2hex(random_bytes(4));
        $this->intake(['name' => 'ชื่อ'.$marker, 'email' => $marker.'@demo.school.test', 'subject' => 'หัวข้อ'.$marker, 'detail' => 'รายละเอียด'.$marker.' ยาวพอ'], [['name' => $marker.'.png', 'mime' => 'image/png', 'size' => strlen($this->png()), 'sha256' => hash('sha256', $this->png()), 'content' => $this->png()]]);
        $this->publish();
        $pub = DB::connection('pubdb');
        $this->assertSame(1, $pub->table('pub_complaint_status')->count());
        foreach (['cmp_cases', 'cmp_actions', 'cmp_notes', 'cmp_files', 'cmp_accused', 'cmp_reports'] as $t) $this->assertSame(0, $pub->table($t)->count(), $t);
        $file = $this->tempDbDump((string) $pub->getConfig('database'));
        $this->assertStringNotContainsString($marker, $file);
        $this->assertStringContainsString('pub_complaint_status', $file);
        $this->assertTrue(Publisher::status()['queue'] === 0);
    }

    // ---------------------------------------------------------------- review follow-ups (audit, idempotency, dates, SLA, quarantine, codes, double submit)

    public function test_audit_rows_are_neutral_and_no_audit_feed_lists_complaint_rows_for_the_accused(): void
    {
        DB::table('cmp_cases')->delete();
        $admin = User::where('role', 'admin')->first();
        $boss = $this->executive('ผู้บริหารหลัก');
        Settings::put('complaints.executive_user_ids', [$boss->id, $admin->id]);
        Settings::put('complaints.officer_user_ids', [$admin->id, $boss->id]);
        $cor = $this->intake(['category' => 'corruption', 'subject' => 'เรื่องทุจริตลับ']);
        $this->actingAs($boss);
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/accused', ['version' => $this->version($cor['id']), 'user_ids' => [$admin->id]])->assertOk();
        $this->postJson('/api/complaints/cases/'.$cor['id'].'/notes', ['body' => 'บันทึกสอบสวน'])->assertCreated();
        $rows = DB::table('audit_events')->where('actor_id', $boss->id)->where(fn ($q) => $q->where('action', 'like', 'complaints.%')->orWhereIn('entity', ['cmp_cases', 'cmp_accused', 'cmp_notes', 'cmp_actions', 'cmp_files']))->get();
        $this->assertGreaterThanOrEqual(4, $rows->count());
        $this->assertSame(['complaints.case_updated'], $rows->pluck('action')->unique()->values()->all());
        foreach ($rows as $r) $this->assertSame('[]', (string) $r->detail, 'no category, count or id in the audit detail');
        $this->assertStringNotContainsString('corruption', json_encode($rows));

        // the accused admin (also an officer and an executive) reads both feeds and sees none of it, and still sees other audit rows
        DB::table('audit_events')->insert(['school_id' => 1, 'actor_id' => $boss->id, 'action' => 'notices.deleted', 'entity' => 'web_notices', 'entity_id' => 1, 'detail' => '{}', 'created_at' => now()]);
        $this->actingAs($admin);
        $this->getJson('/api/complaints/cases/'.$cor['id'])->assertNotFound();
        $site = $this->getJson('/api/site/audit')->assertOk()->json('data');
        $this->assertContains('notices.deleted', array_column($site, 'action'));
        foreach ($site as $r) { $this->assertStringStartsNotWith('complaints.', $r['action']); $this->assertStringStartsNotWith('cmp_', $r['entity']); }
        $state = $this->getJson('/api/state')->assertOk()->json('audit');
        $this->assertContains('notices.deleted', array_column($state, 'action'));
        foreach ($state as $r) { $this->assertStringStartsNotWith('complaints.', $r['action']); $this->assertStringStartsNotWith('cmp_', $r['entity']); }
    }

    public function test_opening_a_case_and_exporting_the_list_are_logged_without_flooding_the_log(): void
    {
        DB::table('cmp_cases')->delete();
        $a = $this->officer('เจ้าหน้าที่หนึ่ง');
        $b = $this->officer('เจ้าหน้าที่สอง');
        $c = $this->intake();
        $this->actingAs($a);
        $this->getJson('/api/complaints/cases/'.$c['id'])->assertOk();
        $this->getJson('/api/complaints/cases/'.$c['id'])->assertOk();
        $this->assertSame(1, DB::table('cmp_actions')->where('case_id', $c['id'])->where('action', 'viewed')->where('actor_id', $a->id)->count(), 'one entry per user and case per hour');
        $this->actingAs($b);
        $this->getJson('/api/complaints/cases/'.$c['id'])->assertOk();
        $this->assertSame(2, DB::table('cmp_actions')->where('case_id', $c['id'])->where('action', 'viewed')->count());
        $this->getJson('/api/complaints/cases/9999')->assertNotFound();
        $this->assertSame(2, DB::table('cmp_actions')->where('action', 'viewed')->count(), 'a refused open leaves no entry');
        $this->get('/api/complaints/cases/export')->assertOk();
        $e = DB::table('audit_events')->where('action', 'complaints.exported')->where('actor_id', $b->id)->first();
        $this->assertNotNull($e);
        $this->assertSame(1, json_decode($e->detail, true)['rows']);
        $this->assertTrue(DB::table('audit_events')->where('action', 'complaints.case_updated')->where('actor_id', $a->id)->exists(), 'the open itself is an audit row too');
    }

    public function test_note_idempotency_keeps_no_plaintext_in_the_cache_and_a_replay_is_checked_again(): void
    {
        DB::table('cmp_cases')->delete();
        config(['cache.default' => 'database']);
        app('cache')->forgetDriver('database');
        $a = $this->officer('เจ้าหน้าที่ก');
        $b = $this->officer('เจ้าหน้าที่ข');
        $c = $this->intake(['category' => 'general', 'name' => 'ผู้ร้องแคชลับ (สมมติ)', 'email' => 'cache.leak@demo.school.test']);
        $this->actingAs($a);
        $h = ['Idempotency-Key' => 'note-cache-1'];
        $this->postJson('/api/complaints/cases/'.$c['id'].'/notes', ['body' => 'บันทึกที่หนึ่ง'], $h)->assertCreated()->assertJsonPath('case.contact.email', 'cache.leak@demo.school.test');
        $cache = json_encode(DB::table('cache')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('idem', $cache, 'the idempotency entry exists');
        foreach (['cache.leak@demo.school.test', 'ผู้ร้องแคชลับ', 'รายละเอียดทดสอบ', 'บันทึกที่หนึ่ง'] as $leak) $this->assertStringNotContainsString($leak, $cache);
        $this->assertStringNotContainsString(base64_encode('cache.leak@demo.school.test'), $cache);
        $same = $this->postJson('/api/complaints/cases/'.$c['id'].'/notes', ['body' => 'บันทึกที่หนึ่ง'], $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $same->assertJsonPath('case.contact.email', 'cache.leak@demo.school.test'); // rebuilt through Access
        $this->assertSame(1, DB::table('cmp_notes')->where('case_id', $c['id'])->count());

        // named accused afterwards: the same request with the same key is a 404, not the cached body
        $this->actingAs($b);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/accused', ['version' => $this->version($c['id']), 'user_ids' => [$a->id]])->assertOk();
        $this->actingAs($a);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/notes', ['body' => 'บันทึกที่หนึ่ง'], $h)->assertNotFound();
    }

    public function test_the_received_date_and_the_due_date_start_when_the_visitor_sent_it(): void
    {
        DB::table('cmp_cases')->delete();
        $sent = time() - 3 * 86400;
        $a = (new InboxHandler())->handle(['receipt_no' => Complaints::newReceipt(), 'track_code' => Complaints::newTrackingCode(), 'category' => 'corruption', 'anonymous' => true, 'subject' => 'เรื่องที่ค้างอยู่', 'detail' => 'รายละเอียดยาวพอสมควรแล้ว'],
            [], ['envelope' => str_repeat('c', 64), 'sent_at' => $sent]);
        $row = DB::table('cmp_cases')->find($a['id']);
        $when = CarbonImmutable::createFromTimestamp($sent);
        $this->assertSame($when->setTimezone(config('app.timezone'))->format('Y-m-d H:i'), substr((string) $row->received_at, 0, 16));
        $this->assertSame((string) $row->received_at, (string) $row->status_changed_at);
        $this->assertSame($when->setTimezone('Asia/Bangkok')->addDays(30)->toDateString(), substr((string) $row->due_on, 0, 10), 'the 30 day SLA of a corruption case counts from the send time');
        $this->assertSame(1, DB::table('cmp_actions')->where('case_id', $a['id'])->where('action', 'received')->where('created_at', $row->received_at)->count());
        // a timestamp in the future never moves the date past now; a missing or odd one falls back to now
        foreach ([time() + 5 * 86400, 'x', null] as $i => $odd) {
            $r = (new InboxHandler())->handle(['receipt_no' => Complaints::newReceipt(), 'track_code' => Complaints::newTrackingCode(), 'category' => 'general', 'anonymous' => true, 'subject' => 'เรื่องเวลา '.$i, 'detail' => 'รายละเอียดยาวพอสมควรแล้ว'],
                [], ['envelope' => hash('sha256', 'odd'.$i), 'sent_at' => $odd]);
            $this->assertLessThanOrEqual(now()->addMinute()->timestamp, strtotime((string) DB::table('cmp_cases')->find($r['id'])->received_at.' '.config('app.timezone')));
            $this->assertSame(now('Asia/Bangkok')->addDays(15)->toDateString(), substr((string) DB::table('cmp_cases')->find($r['id'])->due_on, 0, 10));
        }
    }

    public function test_a_case_that_was_answered_is_not_overdue_and_not_in_the_overdue_filter(): void
    {
        DB::table('cmp_cases')->delete();
        $officer = $this->officer();
        $today = CarbonImmutable::now('Asia/Bangkok');
        $open = $this->intake(['category' => 'general']);
        $replied = $this->intake(['category' => 'general']);
        $repliedClosed = $this->intake(['category' => 'general']);
        foreach ([$open, $replied, $repliedClosed] as $x) DB::table('cmp_cases')->where('id', $x['id'])->update(['due_on' => $today->subDays(3)->toDateString()]);
        DB::table('cmp_cases')->where('id', $repliedClosed['id'])->update(['status' => 'closed', 'closed_at' => now(), 'public_reply_at' => now()->subDays(9)]);
        DB::table('cmp_cases')->where('id', $replied['id'])->update(['status' => 'replied', 'public_reply' => 'ตอบแล้ว', 'public_reply_at' => now()->subDays(10)]);
        $this->assertTrue(Complaints::isOverdue(DB::table('cmp_cases')->find($open['id'])));
        $this->assertFalse(Complaints::isOverdue(DB::table('cmp_cases')->find($replied['id'])), 'answered in time: open, but not late');
        $this->assertFalse(Complaints::isOverdue(['status' => 'replied', 'due_on' => $today->subDay()->toDateString(), 'public_reply_at' => '2026-01-01 00:00:00']));
        $this->actingAs($officer);
        $this->assertSame([$open['id']], array_column($this->getJson('/api/complaints/cases?overdue=1')->assertOk()->json('data'), 'id'));
        $list = collect($this->getJson('/api/complaints/cases')->json('data'));
        $this->assertTrue($list->firstWhere('id', $open['id'])['overdue']);
        $this->assertFalse($list->firstWhere('id', $replied['id'])['overdue']);
        $csv = (string) $this->get('/api/complaints/cases/export?overdue=1')->getContent();
        $this->assertStringContainsString($open['receipt'], $csv);
        $this->assertStringNotContainsString($replied['receipt'], $csv);
        // the answer made through the workflow clears the flag too
        $this->postJson('/api/complaints/cases/'.$open['id'].'/reply', ['version' => $this->version($open['id']), 'text' => 'ตอบกลับผู้ร้อง'])->assertOk()->assertJsonPath('case.overdue', false);
        $this->assertSame([], $this->getJson('/api/complaints/cases?overdue=1')->json('data'));
    }

    public function test_a_refused_complaint_in_the_quarantine_holds_no_tracking_code_and_no_plaintext(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $receipt = Complaints::newReceipt();
        $code = Complaints::newTrackingCode();
        // a newer public version with a category this version does not know: the public side seals it, the internal side refuses it
        Inbox::submit('complaint', ['receipt_no' => $receipt, 'track_code' => $code, 'category' => 'brand_new', 'anonymous' => false, 'name' => 'ผู้ร้องกักกัน (สมมติ)', 'email' => 'quarantine.person@example.test',
            'phone' => '081-000-0000', 'subject' => 'หัวข้อทดสอบกักกัน', 'detail' => 'รายละเอียดทดสอบการกักกันที่ยาวพอสมควร'], []);
        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(1, $r['quarantined'], json_encode($r));
        $q = DB::table('saan_inbox_quarantine')->first();
        $blob = json_encode((array) $q, JSON_UNESCAPED_UNICODE);
        foreach ([$code, Complaints::formatCode($code), 'quarantine.person@example.test', 'ผู้ร้องกักกัน', 'หัวข้อทดสอบกักกัน', 'รายละเอียดทดสอบการกักกัน', '081-000-0000', 'track_code'] as $leak) $this->assertStringNotContainsString($leak, $blob);
        $kept = json_decode((string) $q->payload, true);
        $this->assertTrue($kept['redacted']);
        $this->assertSame($receipt, $kept['receipt_no']);
        // an admin who must follow the case up can still read it back through the app key
        $sealed = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($kept['sealed']), true);
        $this->assertSame('quarantine.person@example.test', $sealed['email']);
        $this->assertSame('รายละเอียดทดสอบการกักกันที่ยาวพอสมควร', $sealed['detail']);
        // a survey keeps no value, only its shape; unknown kinds are kept as they were
        $this->assertSame(['redacted' => true, 'kind' => 'survey', 'fields' => ['a' => 1]], Handlers::redact('survey', ['a' => 1]));
        $this->assertSame(['a' => 1], Handlers::redact('weird.kind', ['a' => 1]));
    }

    public function test_every_demo_tracking_code_fits_the_alphabet_and_finds_its_case(): void
    {
        $demo = ['C69-DEM2K7XQ' => 'TNTHAN4ME6QP', 'C69-DEM3P8WR' => 'DEMQST3CDEXY', 'C69-DEM4N5VT' => 'DEMQST4CDEXY', 'C69-DEM5M6UK' => 'DEMQST5CDEXY'];
        $this->assertSame(array_keys($demo), DB::table('cmp_cases')->orderBy('id')->pluck('receipt_no')->all());
        foreach ($demo as $receipt => $code) {
            $this->assertSame(1, preg_match(Complaints::CODE, $code), $code);
            $this->assertSame(1, preg_match(Complaints::RECEIPT, $receipt), $receipt);
            $found = PublicStatus::lookup($receipt, $code);
            $this->assertNotNull($found, $receipt.' must be found with its demo code');
            $this->assertSame($receipt, $found['receipt_no']);
            $this->assertNull(PublicStatus::lookup($receipt, 'ZZZZZZZZZZZZ'));
        }
        $this->post('/portal/complaints/status', ['receipt_no' => 'C69-DEM3P8WR', 'tracking_code' => 'demq-st3c-dexy'])->assertOk()->assertSee('อยู่ระหว่างดำเนินการ');
    }

    public function test_a_real_unlock_rotation_keeps_issued_tracking_codes_working_on_both_sides(): void
    {
        DB::table('cmp_cases')->delete();
        $c = $this->intake();
        $this->as('internal');
        $before = \App\Support\Saan\HashKey::publicUnlock();
        Keys::rotate(['unlock']);
        $this->assertNotSame($before, \App\Support\Saan\HashKey::publicUnlock());
        $this->assertNotNull(PublicStatus::lookup($c['receipt'], $c['code']), 'internal: the retired key still checks codes issued before the rotation');
        $this->assertNull(PublicStatus::lookup($c['receipt'], 'ZZZZZZZZZZZZ'));
        // the pairing bundle carries the retired keys; the public side stores them next to the current one and replaces the set on the next pairing
        $b = Keys::bundle();
        $this->assertSame([$before], $b['unlock_old']);
        $pubKeys = $this->tempDir();
        file_put_contents($pubKeys.'/peer-unlock.old.stale000000.key', str_repeat('a', 64));
        Keys::applyBundle($b, $pubKeys);
        $this->assertCount(1, glob($pubKeys.'/peer-unlock.old.*.key'));
        config(['saan.keys_dir' => $pubKeys]);
        $this->as('public');
        $this->assertSame([$b['unlock_key'], $before], \App\Support\Saan\HashKey::publicUnlockHistory());
        $this->assertTrue(\Saan\Modules\Complaints\Complaints::trackMatches((string) DB::table('cmp_cases')->where('id', $c['id'])->value('track_hash'), $c['receipt'], $c['code']));
        // a malformed retired list is refused as a whole
        foreach ([['x'], array_fill(0, 21, str_repeat('b', 64)), 'nope'] as $bad) {
            try { Keys::applyBundle(['unlock_old' => $bad] + $b, $pubKeys); $this->fail('bad unlock_old accepted'); } catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_staff_can_issue_a_new_tracking_code_after_the_unlock_key_changed(): void
    {
        DB::table('cmp_cases')->delete();
        $officer = $this->officer();
        $c = $this->intake();
        $this->assertNotNull(PublicStatus::lookup($c['receipt'], $c['code']));
        config(['saan.public_unlock_key' => hash('sha256', 'rotated-unlock-key')]); // what a rotation does to every hash made before it
        $this->assertNull(PublicStatus::lookup($c['receipt'], $c['code']), 'the issued code stops matching: the situation this action recovers from');
        $this->actingAs($officer);
        $res = $this->postJson('/api/complaints/cases/'.$c['id'].'/track-code', ['version' => $this->version($c['id'])])->assertOk()->assertHeader('Cache-Control');
        $new = (string) $res->json('new_tracking_code');
        $this->assertSame(1, preg_match('/^[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){2}$/D', $new));
        $this->assertNotNull(PublicStatus::lookup($c['receipt'], $new));
        $this->assertNull(PublicStatus::lookup($c['receipt'], $c['code']), 'the old code is dead');
        // shown once: nothing keeps it, the case view has no code, the log says only that one was issued
        $this->assertStringNotContainsString(str_replace('-', '', $new), json_encode(DB::table('cmp_cases')->get()).json_encode(DB::table('cmp_actions')->get()).json_encode(DB::table('audit_events')->get()));
        $this->assertArrayNotHasKey('new_tracking_code', $this->getJson('/api/complaints/cases/'.$c['id'])->json());
        $this->assertSame(1, DB::table('cmp_actions')->where('case_id', $c['id'])->where('action', 'code_reissued')->count());
        $this->postJson('/api/complaints/cases/'.$c['id'].'/track-code', ['version' => 1])->assertStatus(409); // optimistic version
        // not for a case the user may not see
        $acc = $this->officer('ผู้ถูกร้อง');
        $other = $this->officer('คนอื่น');
        $this->actingAs($other);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/accused', ['version' => $this->version($c['id']), 'user_ids' => [$acc->id]])->assertOk();
        $this->actingAs($acc);
        $this->postJson('/api/complaints/cases/'.$c['id'].'/track-code', ['version' => $this->version($c['id'])])->assertNotFound();
    }

    public function test_a_double_submit_is_one_case_and_a_refused_form_keeps_what_was_typed(): void
    {
        DB::table('cmp_cases')->delete();
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $this->openBoundary();
        $form = $this->get('/portal/complaints')->assertOk();
        $form->assertSee('value="corruption"', false)->assertSee('<title>ร้องเรียนและแจ้งเรื่อง', false); // the complaint categories, not the news categories of the site context
        $this->assertSame(1, preg_match('/name="form_nonce" value="([a-f0-9]{32})"/', $form->getContent(), $nm));
        $this->assertNotSame($nm[1], $this->get('/portal/complaints')->getContent() && preg_match('/name="form_nonce" value="([a-f0-9]{32})"/', $this->get('/portal/complaints')->getContent(), $n2) ? $n2[1] : '', 'a fresh nonce per page view');
        $post = ['form_nonce' => $nm[1], 'category' => 'general', 'anonymous' => '1', 'subject' => 'ดับเบิลคลิกส่งซ้ำ', 'detail' => 'รายละเอียดสำหรับทดสอบการกดส่งซ้ำสองครั้ง'];
        $first = $this->post('/portal/complaints', $post)->assertStatus(201);
        $second = $this->post('/portal/complaints', $post)->assertStatus(201);
        $pick = fn ($html) => [preg_match('/C\d{2}-[A-HJ-NP-Z2-9]{8}/', $html, $r) ? $r[0] : null, preg_match('/<dd><code>([A-Z2-9-]{14})<\/code><\/dd>/', $html, $c) ? $c[1] : null];
        $this->assertSame($pick($first->getContent()), $pick($second->getContent()), 'the same form gives the same receipt and code');
        $this->assertSame(2, DB::connection('pubdb')->table('pub_inbox')->count());
        // changed text under the same nonce is another complaint
        $third = $this->post('/portal/complaints', ['detail' => 'รายละเอียดที่แก้ไขแล้วก่อนส่งอีกครั้งหนึ่ง'] + $post)->assertStatus(201);
        $this->assertNotSame($pick($first->getContent())[0], $pick($third->getContent())[0]);

        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(3, $r['pulled']);
        $this->assertSame(3, $r['handled'], json_encode($r));
        $this->assertSame(0, $r['quarantined']);
        $this->assertSame(2, DB::table('cmp_cases')->count(), 'two sends of the same form, one case; the edited text is a second case');
        [$receipt, $code] = $pick($first->getContent());
        $this->assertNotNull(PublicStatus::lookup($receipt, $code), 'the code on the page works');

        // a refused try puts the typed values back in the same response, nothing in the session
        $this->as('public', 'pubdb');
        $bad = ['form_nonce' => $nm[1], 'category' => 'suggestion', 'name' => 'สมศรี ตัวอย่าง', 'email' => 'somsri@example.test', 'subject' => 'หัวข้อที่พิมพ์ไว้', 'detail' => 'รายละเอียดที่พิมพ์ไว้ยาวพอสมควร'];
        $res = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.50'])->post('/portal/complaints', $bad)->assertStatus(422)->assertSee('กรุณายืนยันการให้โรงเรียนใช้ข้อมูลติดต่อ');
        foreach (['สมศรี ตัวอย่าง', 'somsri@example.test', 'หัวข้อที่พิมพ์ไว้', 'รายละเอียดที่พิมพ์ไว้ยาวพอสมควร', 'value="'.$nm[1].'"'] as $kept) $res->assertSee($kept, false);
        $this->assertMatchesRegularExpression('/value="suggestion"[^>]*checked/', $res->getContent());
        $this->assertSame([], (array) session()->get('_old_input', []));
        $this->assertSame([], array_diff(array_keys(session()->all()), ['_token', '_previous', '_flash']));
    }

    public function test_a_full_file_quota_in_the_combined_edition_answers_503_in_thai_not_a_500(): void
    {
        DB::table('cmp_cases')->delete();
        $big = ['name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1300000, 'sha256' => str_repeat('0', 64), 'content' => '%PDF-1.4'.str_repeat('x', 1300000)];
        $this->intake([], [$big]);
        config(['saan.storage.quota_mb' => 1, 'saan.edition' => 'combined']);
        $this->openBoundary();
        $png = UploadedFile::fake()->createWithContent('หลักฐาน.png', $this->png());
        $res = $this->post('/portal/complaints', ['category' => 'general', 'anonymous' => '1', 'subject' => 'แนบไฟล์ตอนโควตาเต็ม', 'detail' => 'รายละเอียดสำหรับทดสอบโควตาไฟล์แนบเต็ม', 'files' => [$png]]);
        $res->assertStatus(503)->assertSee('ระบบรับไฟล์แนบไม่ได้ชั่วคราว')->assertSee('แนบไฟล์ตอนโควตาเต็ม', false);
        $this->assertSame(1, DB::table('cmp_cases')->count(), 'nothing was created');
        // without the file the same complaint goes through
        $this->post('/portal/complaints', ['category' => 'general', 'anonymous' => '1', 'subject' => 'ไม่แนบไฟล์', 'detail' => 'รายละเอียดสำหรับทดสอบโควตาไฟล์แนบเต็ม'])->assertStatus(201);
        $this->assertSame(2, DB::table('cmp_cases')->count());
    }

    public function test_direct_filing_counts_against_the_daily_byte_ceiling_and_complaints_have_a_quota_of_their_own(): void
    {
        DB::table('cmp_cases')->delete();
        config(['saan.inbox.complaint_per_minute' => 100, 'saan.inbox.complaint_files_mb_per_day' => 1]);
        $big = ['name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 700000, 'sha256' => str_repeat('0', 64), 'content' => '%PDF-1.4'.str_repeat('x', 700000)];
        $this->intake([], [$big]); // direct, no envelope
        try { $this->intake([], [$big]); $this->fail('direct filing dodged the attachment byte ceiling'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('flood_ceiling', $e->getMessage()); }
        $this->assertSame(1, DB::table('cmp_files')->count());
        // own sub-quota: attachments of complaints alone may not exceed it, whatever the school quota is
        RateLimiter::clear('complaint-inbox-bytes');
        config(['saan.inbox.complaint_files_mb_per_day' => 500, 'saan.complaints.files_quota_mb' => 1]);
        try { $this->intake([], [$big]); $this->fail('complaint sub-quota not enforced'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('complaint_quota', $e->getMessage()); }
        $this->assertSame(1, DB::table('cmp_files')->count());
        $this->assertSame(1, DB::table('cmp_cases')->count());
    }

    public function test_attachments_from_one_network_are_capped_per_hour_on_the_public_form(): void
    {
        DB::table('cmp_cases')->delete();
        config(['saan.edition' => 'combined', 'saan.complaints.net_mb_per_hour' => 1]);
        $this->openBoundary();
        $png = fn () => UploadedFile::fake()->createWithContent('หลักฐาน.png', $this->png());
        $this->post('/portal/complaints', ['category' => 'general', 'anonymous' => '1', 'subject' => 'แนบไฟล์เล็ก', 'detail' => 'รายละเอียดสำหรับทดสอบเพดานไฟล์แนบต่อเครือข่าย', 'files' => [$png()]])->assertStatus(201);
        $big = UploadedFile::fake()->createWithContent('ใหญ่.pdf', "%PDF-1.4\n".str_repeat('x', 1100000)."\n%%EOF");
        $this->post('/portal/complaints', ['category' => 'general', 'anonymous' => '1', 'subject' => 'แนบไฟล์ใหญ่', 'detail' => 'รายละเอียดสำหรับทดสอบเพดานไฟล์แนบต่อเครือข่าย', 'files' => [$big]])->assertStatus(429);
        $this->assertSame(1, DB::table('cmp_cases')->count());
    }

    // ---------------------------------------------------------------- audit fixes (2026-11-07)

    public function test_an_anonymous_complaint_from_a_signed_in_staff_member_leaves_no_actor_in_the_audit_trail(): void
    {
        config(['saan.edition' => 'combined']);
        $this->openBoundary();
        $teacher = User::where('role', 'teacher')->first();
        $this->actingAs($teacher);
        $this->post('/portal/complaints', ['category' => 'corruption', 'anonymous' => '1', 'subject' => 'ร้องเรียนนิรนามจากครู', 'detail' => 'รายละเอียดสำหรับทดสอบการไม่ผูกบัญชีผู้ส่ง',
            'files' => [UploadedFile::fake()->createWithContent('a.png', $this->png())]])->assertStatus(201);
        $case = DB::table('cmp_cases')->where('subject', 'ร้องเรียนนิรนามจากครู')->first();
        $this->assertNotNull($case);
        $this->assertSame(1, (int) $case->anonymous);
        $this->assertSame(0, DB::table('audit_events')->where('actor_id', $teacher->id)->where('entity', 'like', 'cmp_%')->count(), 'intake carries no actor');
        $this->assertSame($teacher->id, auth()->id(), 'the signed-in user is untouched by the handler');

        // a real session login (the guard reloads the user from the session, which actingAs does not exercise)
        $this->app['auth']->forgetGuards();
        $this->withSession([auth()->guard()->getName() => $teacher->id]);
        $this->post('/portal/complaints', ['category' => 'general', 'anonymous' => '1', 'subject' => 'ร้องเรียนนิรนามผ่าน session', 'detail' => 'รายละเอียดสำหรับทดสอบ session guard ที่โหลดผู้ใช้ซ้ำ'])->assertStatus(201);
        $this->assertNotNull(DB::table('cmp_cases')->where('subject', 'ร้องเรียนนิรนามผ่าน session')->first());
        $this->assertSame(0, DB::table('audit_events')->where('actor_id', $teacher->id)->where('entity', 'like', 'cmp_%')->count(), 'no actor with a session-backed login either');
    }

    public function test_live_report_counts_and_report_list_only_cover_cases_the_user_may_see(): void
    {
        DB::table('cmp_cases')->delete();
        $boss = $this->executive('ผู้บริหารรายงาน');
        $acc = $this->officer('เจ้าหน้าที่ถูกร้อง');
        $other = $this->officer('เจ้าหน้าที่อื่น');
        $a = $this->intake(['category' => 'general']);
        $this->intake(['category' => 'general']);
        $this->intake(['category' => 'corruption']);
        DB::table('cmp_accused')->insert(['school_id' => Complaints::school(), 'case_id' => $a['id'], 'user_id' => $acc->id, 'created_at' => now()]);
        $fy = Complaints::currentFiscalYear();
        $this->actingAs($acc);
        $this->assertSame(1, $this->getJson('/api/complaints/cases?category=general')->json('total') ?? count($this->getJson('/api/complaints/cases?category=general')->json('data') ?? []));
        $rep = $this->getJson('/api/complaints/reports/'.$fy)->assertOk();
        $this->assertSame(1, $rep->json('rows.general.total'), 'the accused officer does not count the hidden case');
        $this->assertSame(0, $rep->json('rows.corruption.total'), 'a general officer does not count corruption cases');
        $this->assertSame(1, $this->getJson('/api/complaints/reports')->assertOk()->json('data.0.total'));
        // publishing answers the accused exactly like anyone else (a refusal would be the oracle), with their own scoped row
        $acc = $this->postJson('/api/complaints/reports/'.$fy.'/publish', ['action' => 'publish'])->assertOk();
        $this->assertSame(1, $acc->json('total'));
        $this->postJson('/api/complaints/reports/'.$fy.'/publish', ['action' => 'unpublish'])->assertOk();
        $this->actingAs($other);
        $this->assertSame(2, $this->getJson('/api/complaints/reports/'.$fy)->json('rows.general.total'));
        $this->actingAs($boss);
        $this->assertSame(1, $this->getJson('/api/complaints/reports/'.$fy)->json('totals.total'));
        // a user who is not accused publishes the full-school snapshot but gets his own scoped row back
        $this->actingAs($other);
        $res = $this->postJson('/api/complaints/reports/'.$fy.'/publish', ['action' => 'publish'])->assertOk();
        $this->assertSame(2, $res->json('total'));
        $snap = json_decode((string) DB::table('cmp_reports')->where('fiscal_year', $fy)->value('snapshot'), true);
        $this->assertSame(3, $snap['totals']['total']);
    }

    public function test_anonymous_attachments_lose_metadata_and_the_original_name_and_risky_formats_are_refused(): void
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start(); imagejpeg($im, null, 90); $jpg = (string) ob_get_clean();
        $exif = "\xFF\xE1\x00\x1CExif\x00\x00MM\x00*SECRETGPS-HOME\x00\x00\x00\x00\x00\x00\x00\x00";
        $dirty = substr($jpg, 0, 2).$exif.substr($jpg, 2);
        $this->assertStringContainsString('SECRETGPS-HOME', $dirty);
        $zipPath = tempnam(sys_get_temp_dir(), 'dx'); $z = new \ZipArchive(); $z->open($zipPath, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $z->addFromString('_rels/.rels', '<Relationships/>');
        $z->addFromString('word/document.xml', '<w:document/>');
        $z->addFromString('docProps/core.xml', '<cp:coreProperties xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>SecretAuthorName</dc:creator></cp:coreProperties>');
        foreach ([0, 1, 2] as $k) $z->setCompressionIndex($k, \ZipArchive::CM_STORE); // so that finfo recognises the .docx layout
        $z->close(); $docx = (string) file_get_contents($zipPath); @unlink($zipPath);
        $files = [
            ['name' => 'home-photo.jpg', 'content' => $dirty], ['name' => 'evidence.docx', 'content' => $docx],
        ];
        $prepared = \App\Support\Saan\Inbox::prepareFiles($files);
        $clean = \App\Support\Saan\Inbox\FileScrub::anonymous($prepared);
        $this->assertSame(['attachment-1.jpg', 'attachment-2.docx'], array_column($clean, 'name'));
        $this->assertStringNotContainsString('SECRETGPS-HOME', $clean[0]['content']);
        $this->assertSame('image/jpeg', $clean[0]['mime']);
        $zip = new \ZipArchive(); $p = tempnam(sys_get_temp_dir(), 'dy'); file_put_contents($p, $clean[1]['content']); $zip->open($p);
        $this->assertStringNotContainsString('SecretAuthorName', (string) $zip->getFromName('docProps/core.xml'));
        $this->assertNotFalse($zip->getFromName('word/document.xml'));
        $zip->close(); @unlink($p);
        $this->assertSame(hash('sha256', $clean[0]['content']), $clean[0]['sha256']);
        $this->expectException(InboxRejected::class);
        \App\Support\Saan\Inbox\FileScrub::anonymous([['name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 8, 'sha256' => str_repeat('0', 64), 'content' => '%PDF-1.4']]);
    }
}