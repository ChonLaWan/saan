<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\HashKey;
use App\Support\Saan\Inbox;
use App\Support\Saan\Inbox\Handlers;
use App\Support\Saan\Inbox\InboxRejected;
use App\Support\Saan\Inbox\Puller;
use App\Support\Saan\Inbox\PullServer;
use App\Support\Saan\Inbox\State;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Pairing;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Publish\SplitHealth;
use App\Support\Saan\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Saan\Modules\Surveys\Mail\SurveyVerifyMail;
use Tests\TestCase;

/**
 * S5 + S6: the encrypted inbox and key pairing. Internal side = the suite connection; public side = a second, temporary database
 * (connection "pubdb") that the pull server and the sealing code work on directly (no HTTP unless a test says so). One key directory holds the files of
 * both sides, which is harmless: the public side only ever reads its peer-* files.
 */
class SaanInboxTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->baseConnection, 'saan.inbox_connection' => null]);
        Handlers::forget();
        try { DB::purge('pubdb'); } catch (\Throwable) {}
        @unlink(storage_path('app/private/saan-pair.token'));
        parent::tearDown();
        gc_collect_cycles();
        foreach ($this->tmp as $p) is_dir($p) ? $this->rrmdir($p) : @unlink($p);
    }

    private function tempDir(): string
    {
        $d = sys_get_temp_dir().'/saan-inbox-'.bin2hex(random_bytes(6));
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
        // a fresh copy of a fully migrated database on the suite's MariaDB/MySQL server (Tests\Concerns\TempDatabases); dropped at teardown
        return $this->migratedTempConnection('pubdb');
    }

    /** Which installation the code runs as: edition, default database and inbox connection. */
    private function as(string $edition, ?string $conn = null): void
    {
        config(['saan.edition' => $edition, 'database.default' => $conn ?? $this->baseConnection, 'saan.inbox_connection' => $conn]);
    }

    /** Pairs the (single) public side with the internal side through the real code exchange. */
    private function pairAll(): void
    {
        $this->as('internal');
        $c = Pairing::issue(1, null, self::PUSH);
        [$st, $r] = Pairing::redeem($c['code'], 'https://public.test/_saan/push', '10.0.0.1');
        $this->assertSame(200, $st);
        Keys::applyBundle($r['bundle']);
    }

    private function contact(string $suffix = '', array $files = []): array
    {
        return Inbox::submit('contact', ['name' => 'สมชาย ทดสอบ', 'email' => 'somchai@example.test', 'subject' => 'สอบถามเรื่อง'.$suffix, 'message' => 'ข้อความทดสอบยาวพอสมควร '.$suffix], $files);
    }

    private function puller(?callable $wrap = null, ?int $now = null): Puller
    {
        $server = new PullServer('pubdb', null, $now);
        return new Puller(function ($m, $p, $q, $b, $h) use ($server, $wrap) {
            if ($wrap && ($r = $wrap($m, $p)) !== null) return $r;
            return $server->handle($m, $p, $q, $b, $h);
        }, $now);
    }

    /** @return array{0:string,1:string,2:array,3:string,4:array} method, path, query, body, headers */
    private function signed(string $method, string $path, array $query = [], string $body = '', ?int $ts = null, ?string $nonce = null): array
    {
        $ts ??= time();
        $nonce ??= bin2hex(random_bytes(16));
        return [$method, $path, $query, $body, ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Nonce' => $nonce, 'X-Saan-Signature' => Keys::pullSign($method, $path, $query, $ts, $nonce, $body)]];
    }

    private function pubRows(): int
    {
        return (int) DB::connection('pubdb')->table('pub_inbox')->count();
    }

    // ---------------------------------------------------------------- sealing

    public function test_a_sealed_envelope_cannot_be_opened_with_anything_the_public_side_holds(): void
    {
        $dbFile = $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $png = base64_decode(self::PNG);
        $msg = 'ข้อความลับของผู้ปกครอง-'.bin2hex(random_bytes(4));
        $res = Inbox::submit('contact', ['name' => 'สมชาย ลับมาก', 'email' => 'secret.person@example.test', 'subject' => 'เรื่องลับ', 'message' => $msg], [['name' => 'photo.png', 'content' => $png]]);
        $this->assertSame('sealed', $res['mode']);
        $row = DB::connection('pubdb')->table('pub_inbox')->first();
        $this->assertSame('contact', $row->kind);
        $this->assertSame(Keys::keyId((string) Keys::peer('inbox.pub')), $row->key_id);

        // no plaintext in any row of any table of the public database, in the stored columns or after base64 decoding
        $needles = ['สมชาย ลับมาก', 'secret.person@example.test', 'เรื่องลับ', $msg, 'photo.png', $png, 'image/png', '"subject":'];
        $file = $this->tempDbDump($dbFile);
        $this->assertStringContainsString('pub_inbox', $file, 'the dump really read the public database');
        $stored = json_encode((array) $row, JSON_UNESCAPED_UNICODE).base64_decode($row->ciphertext).implode('', array_map(fn ($a) => base64_decode($a['c']), json_decode($row->attachments, true)));
        foreach ($needles as $n) {
            $this->assertStringNotContainsString($n, $file, 'plaintext in the public db file');
            $this->assertStringNotContainsString($n, $stored, 'plaintext in the stored envelope');
        }

        // everything a breached public side has: its peer files, the database, the app key. None of it opens the envelope.
        $pubKeys = $this->tempDir();
        foreach (glob($this->keysDir.'/peer-*') as $f) copy($f, $pubKeys.'/'.basename($f));
        $this->assertNotEmpty(glob($pubKeys.'/peer-*'));
        $cands = [(string) config('app.key'), $dbFile, (string) Keys::peer('unlock.key')];
        foreach (glob($pubKeys.'/*') as $f) $cands[] = trim((string) file_get_contents($f));
        foreach ($cands as $c) {
            $raw = base64_decode($c, true);
            $sk = $raw !== false && strlen($raw) === 32 ? $raw : hash('sha256', $c, true);
            $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($sk, sodium_crypto_box_publickey_from_secretkey($sk));
            $this->assertFalse(@sodium_crypto_box_seal_open(base64_decode($row->ciphertext), $kp));
        }
        $secretB64 = (string) Keys::read('inbox.secret');
        foreach (glob($pubKeys.'/*') as $f) $this->assertStringNotContainsString($secretB64, (string) file_get_contents($f));
        $this->assertStringNotContainsString($secretB64, $file);

        // the internal side opens it
        $this->as('internal');
        $o = Inbox::open($row->ciphertext, $row->attachments, $row->key_id);
        $this->assertSame($msg, $o['env']['payload']['message']);
        $this->assertSame('contact', $o['env']['kind']);
        $this->assertSame(32, strlen($o['env']['nonce']));
        $this->assertSame($png, $o['files'][0]['content']);
        $this->assertSame('image/png', $o['files'][0]['mime']);
    }

    public function test_attachments_are_checked_before_they_are_sealed(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $png = base64_decode(self::PNG);
        $bad = [
            'wrong content for the extension' => [['name' => 'a.pdf', 'content' => $png]],
            'extension not allowed' => [['name' => 'run.exe', 'content' => 'MZ....']],
            'no extension' => [['name' => 'noext', 'content' => $png]],
            'empty' => [['name' => 'a.png', 'content' => '']],
            'too many' => array_fill(0, 6, ['name' => 'a.png', 'content' => $png]),
        ];
        foreach ($bad as $why => $files) {
            try { $this->contact('', $files); $this->fail($why); } catch (InboxRejected $e) { $this->assertGreaterThanOrEqual(400, $e->status, $why); }
        }
        config(['saan.inbox.max_file_kb' => 1]);
        try { $this->contact('', [['name' => 'big.png', 'content' => $png.str_repeat('x', 2048)]]); $this->fail('oversize'); } catch (InboxRejected $e) { $this->assertSame(413, $e->status); }
        config(['saan.inbox.max_file_kb' => 8192, 'saan.inbox.max_payload_kb' => 1]);
        try { Inbox::submit('contact', ['message' => str_repeat('ก', 1500)]); $this->fail('payload'); } catch (InboxRejected $e) { $this->assertSame(413, $e->status); }
        config(['saan.inbox.max_payload_kb' => 256]);
        $this->assertSame(0, $this->pubRows(), 'nothing refused was stored');
        $this->assertSame('sealed', $this->contact('', [['name' => 'ok.png', 'content' => $png]])['mode']);
        $this->assertSame(1, $this->pubRows());
        $this->expectException(InboxRejected::class);
        Inbox::submit('Bad Kind!', []);
    }

    public function test_the_public_side_refuses_to_seal_before_it_is_paired_or_when_the_box_is_full(): void
    {
        $this->publicSide();
        $this->as('public', 'pubdb');
        try { $this->contact(); $this->fail('not paired'); } catch (InboxRejected $e) { $this->assertSame(503, $e->status); }
        $this->pairAll();
        $this->as('public', 'pubdb');
        config(['saan.inbox.max_waiting' => 2]);
        $this->contact('1');
        $this->contact('2');
        try { $this->contact('3'); $this->fail('full'); } catch (InboxRejected $e) { $this->assertSame(503, $e->status); }
        $this->assertSame(2, $this->pubRows());
    }

    // ---------------------------------------------------------------- pull channel

    public function test_pull_requests_that_are_tampered_old_replayed_or_signed_by_another_key_are_rejected(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $this->contact('a');
        $srv = new PullServer('pubdb');
        $ok = fn (array $t) => $srv->handle(...$t);

        [$st, $r] = $ok($this->signed('GET', '/_saan/inbox', ['after' => 0]));
        $this->assertSame(200, $st);
        $this->assertCount(1, $r['envelopes']);
        $this->assertSame(0, State::count('pubdb', 'inbox.sigfail'));

        $t = $this->signed('GET', '/_saan/inbox', ['after' => 0]);
        $this->assertSame(200, $ok($t)[0]);
        [$st, $r] = $ok($t); // the very same request again
        $this->assertSame([401, 'replay'], [$st, $r['error']]);

        $t = $this->signed('GET', '/_saan/inbox', ['after' => 0]);
        $t[2] = ['after' => 5]; // query changed after signing
        $this->assertSame('bad_signature', $ok($t)[1]['error']);

        $t = $this->signed('POST', '/_saan/inbox/ack', [], '{"ids":[1]}');
        $t[3] = '{"ids":[1,2,3]}'; // body changed after signing
        $this->assertSame('bad_signature', $ok($t)[1]['error']);

        $t = $this->signed('GET', '/_saan/inbox', []);
        $t[1] = '/_saan/inbox/ack'; // a signature for one endpoint is no good for another
        $t[0] = 'POST';
        $this->assertSame('bad_signature', $ok($t)[1]['error']);

        $t = $this->signed('GET', '/_saan/inbox', ['after' => 0], '', time() - 1000); // too old
        $this->assertSame([401, 'stale_timestamp'], [$ok($t)[0], $ok($t)[1]['error']]);
        $t = $this->signed('GET', '/_saan/inbox', ['after' => 0], '', time() + 1000); // from the future
        $this->assertSame('stale_timestamp', $ok($t)[1]['error']);

        // signed by a key that is not the paired pull key (the push key, or a random one)
        $ts = time(); $nonce = bin2hex(random_bytes(16));
        $forged = base64_encode(sodium_crypto_sign_detached(Keys::pullMessage('GET', '/_saan/inbox', ['after' => 0], $ts, $nonce, ''), Keys::secret()));
        $this->assertSame('bad_signature', $srv->handle('GET', '/_saan/inbox', ['after' => 0], '', ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Nonce' => $nonce, 'X-Saan-Signature' => $forged])[1]['error']);
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $forged = base64_encode(sodium_crypto_sign_detached(Keys::pullMessage('GET', '/_saan/inbox', ['after' => 0], $ts, $nonce, ''), $other));
        $this->assertSame('bad_signature', $srv->handle('GET', '/_saan/inbox', ['after' => 0], '', ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Nonce' => $nonce, 'X-Saan-Signature' => $forged])[1]['error']);
        // no nonce / malformed nonce
        $this->assertSame('bad_signature', $srv->handle('GET', '/_saan/inbox', [], '', ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Signature' => 'x'])[1]['error']);
        // unknown route
        $this->assertSame(404, $srv->handle('GET', '/_saan/other', [], '', [])[0]);

        $this->assertGreaterThanOrEqual(6, State::count('pubdb', 'inbox.sigfail'), 'rejected signatures are counted for the doctor');
        $this->assertSame(1, $this->pubRows(), 'a rejected request deletes nothing');

        // not paired: the public side has no pull key to check against
        config(['saan.keys_dir' => $this->tempDir()]);
        $this->assertSame(503, (new PullServer('pubdb'))->handle('GET', '/_saan/inbox', [], '', ['X-Saan-Timestamp' => (string) time(), 'X-Saan-Nonce' => str_repeat('a', 32), 'X-Saan-Signature' => 'x'])[0]);
    }

    public function test_ack_deletes_exactly_the_acknowledged_envelopes_and_old_nonces_are_forgotten(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        foreach (['1', '2', '3'] as $s) $this->contact($s);
        $srv = new PullServer('pubdb');
        [, $list] = $srv->handle(...$this->signed('GET', '/_saan/inbox', ['after' => 0]));
        $ids = array_column($list['envelopes'], 'id');
        $this->assertCount(3, $ids);
        $this->assertSame(3, $list['waiting']);
        [, $page] = $srv->handle(...$this->signed('GET', '/_saan/inbox', ['after' => $ids[0], 'limit' => 1]));
        $this->assertSame([$ids[1]], array_column($page['envelopes'], 'id'));
        $this->assertTrue($page['has_more']);
        [$st, $r] = $srv->handle(...$this->signed('POST', '/_saan/inbox/ack', [], json_encode(['ids' => [$ids[0], $ids[2], 9999]])));
        $this->assertSame([200, 2], [$st, $r['deleted']]);
        $this->assertSame([$ids[1]], DB::connection('pubdb')->table('pub_inbox')->pluck('id')->all());
        $this->assertSame(422, $srv->handle(...$this->signed('POST', '/_saan/inbox/ack', [], '{"ids":["x"]}'))[0]);
        $this->assertSame(422, $srv->handle(...$this->signed('POST', '/_saan/inbox/ack', [], 'not json'))[0]);
        $this->assertSame(1, $this->pubRows());
        // nonces older than the skew window are removed, so the table does not grow forever
        DB::connection('pubdb')->table('pub_inbox_nonces')->insert(['nonce' => str_repeat('b', 32), 'seen_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $srv->handle(...$this->signed('GET', '/_saan/inbox', []));
        $this->assertFalse(DB::connection('pubdb')->table('pub_inbox_nonces')->where('nonce', str_repeat('b', 32))->exists());
    }

    public function test_envelopes_older_than_seven_days_are_purged_counted_and_reported_by_the_doctor(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $this->contact('new');
        $old = ['kind' => 'contact', 'key_id' => str_repeat('a', 16), 'ciphertext' => 'x', 'size' => 1];
        $db = DB::connection('pubdb')->table('pub_inbox');
        $db->insert($old + ['received_at' => date('Y-m-d H:i:s', time() - 8 * 86400), 'dedupe_hash' => str_repeat('1', 64)]);
        $db->insert($old + ['received_at' => date('Y-m-d H:i:s', time() - 6 * 86400), 'dedupe_hash' => str_repeat('2', 64)]);
        $checks = fn () => collect(SplitHealth::checks())->keyBy(0);
        $this->assertSame(1, (new PullServer('pubdb'))->purge());
        $this->assertSame(2, $this->pubRows());
        $this->assertSame(1, State::count('pubdb', 'inbox.purged'));
        $c = $checks();
        $this->assertFalse($c['split_inbox_expired'][2]);
        $this->assertStringContainsString('1 ซอง', $c['split_inbox_expired'][3]);
        // a pull request purges too
        $db->insert($old + ['received_at' => date('Y-m-d H:i:s', time() - 9 * 86400), 'dedupe_hash' => str_repeat('3', 64)]);
        (new PullServer('pubdb'))->handle(...$this->signed('GET', '/_saan/inbox', []));
        $this->assertSame(2, State::count('pubdb', 'inbox.purged'));
        $this->assertSame(2, $this->pubRows());
        // the command is a no-op outside the public edition and deletes inside it
        $db->insert($old + ['received_at' => date('Y-m-d H:i:s', time() - 9 * 86400), 'dedupe_hash' => str_repeat('4', 64)]);
        $this->artisan('saan:inbox-purge')->assertExitCode(0);
        $this->assertSame(3, State::count('pubdb', 'inbox.purged'));
    }

    // ---------------------------------------------------------------- end to end

    public function test_contact_flow_end_to_end_public_to_internal_is_idempotent_and_leaves_nothing_behind(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $sent = $this->contact('e2e');
        $this->assertSame('sealed', $sent['mode']);
        $this->assertSame(0, DB::connection('pubdb')->table('cms_messages')->count(), 'the public side writes no internal table');
        $this->assertSame(0, DB::table('cms_messages')->count());

        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(['pulled' => 1, 'handled' => 1, 'quarantined' => 0, 'failed' => 0], $r);
        $m = DB::table('cms_messages')->first();
        $this->assertSame('somchai@example.test', $m->email);
        $this->assertSame('สอบถามเรื่องe2e', $m->subject);
        $this->assertSame(0, $this->pubRows(), 'acknowledged envelopes are deleted on the public side');
        $this->assertSame(2, DB::table('saan_inbox_done')->count(), 'the outer hash (lost ack) and the nonce inside the envelope (replay)');
        $this->assertNotNull(State::get(null, 'inbox.pull'));

        $this->assertSame(0, $this->puller()->run()['pulled']);
        $this->assertSame(1, DB::table('cms_messages')->count());

        // the same envelope delivered again after a lost ack: skipped by its dedupe hash, handled once
        $this->as('public', 'pubdb');
        $this->contact('again');
        $this->as('internal');
        $this->puller(fn ($m, $p) => $p === PullServer::ACK ? [500, ['error' => 'lost']] : null)->run(); // handled, ack lost
        $this->assertSame(1, $this->pubRows());
        $this->assertSame(2, DB::table('cms_messages')->count());
        $r = $this->puller()->run();
        $this->assertSame(0, $r['handled']);
        $this->assertSame(2, DB::table('cms_messages')->count(), 'handled once');
        $this->assertSame(0, $this->pubRows());

        // the same message sealed twice (a double click) lands once
        $this->as('public', 'pubdb');
        $this->contact('dbl');
        $this->contact('dbl');
        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(2, $r['handled']);
        $this->assertSame(3, DB::table('cms_messages')->count());
    }

    public function test_unknown_kind_tampering_and_a_refusing_handler_go_to_quarantine_and_are_acknowledged(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        Inbox::submit('weird.kind', ['x' => 1]);
        $this->contact('tamper-ct');
        $this->contact('tamper-att', [['name' => 'a.png', 'content' => base64_decode(self::PNG)]]);
        Inbox::submit('contact', ['name' => 'x']); // invalid for the contact handler
        $db = DB::connection('pubdb')->table('pub_inbox');
        $ct = $db->where('kind', 'contact')->orderBy('id')->first();
        $ct2 = DB::connection('pubdb')->table('pub_inbox')->where('kind', 'contact')->whereNotNull('attachments')->first();
        $bytes = base64_decode($ct->ciphertext);
        $bytes[20] = chr(ord($bytes[20]) ^ 1);
        DB::connection('pubdb')->table('pub_inbox')->where('id', $ct->id)->update(['ciphertext' => base64_encode($bytes)]);
        $att = json_decode($ct2->attachments, true);
        $raw = base64_decode($att[0]['c']);
        $raw[10] = chr(ord($raw[10]) ^ 1);
        $att[0]['c'] = base64_encode($raw);
        DB::connection('pubdb')->table('pub_inbox')->where('id', $ct2->id)->update(['attachments' => json_encode($att)]);

        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(['pulled' => 4, 'handled' => 0, 'quarantined' => 4, 'failed' => 0], $r);
        $this->assertSame(0, $this->pubRows());
        $this->assertSame(0, DB::table('cms_messages')->count());
        $all = implode(' | ', DB::table('saan_inbox_quarantine')->pluck('reason')->all());
        $this->assertStringContainsString('ไม่รู้จักชนิดข้อมูล weird.kind', $all);
        $this->assertStringContainsString('ถอดรหัสซองไม่ได้', $all);
        $this->assertStringContainsString('ไฟล์แนบเสียหาย', $all);
        $this->assertStringContainsString('ข้อความติดต่อไม่ถูกต้อง', $all);
        $this->assertSame(['x' => 1], json_decode((string) DB::table('saan_inbox_quarantine')->where('kind', 'weird.kind')->value('payload'), true), 'the payload is kept for review');
    }

    public function test_a_handler_gets_the_attachments_and_a_failing_handler_keeps_the_envelope(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $png = base64_decode(self::PNG);
        Inbox::submit('doc', ['n' => 1], [['name' => 'p.png', 'content' => $png]]);
        $this->as('internal');
        $seen = null;
        $fail = true;
        Handlers::register('doc', function ($payload, $files, $ctx) use (&$seen, &$fail) {
            if ($fail) throw new \RuntimeException('database is busy');
            $seen = [$payload, $files, $ctx];
            return ['id' => 77];
        });
        $r = $this->puller()->run();
        $this->assertSame([1, 0, 0, 1], [$r['pulled'], $r['handled'], $r['quarantined'], $r['failed']]);
        $this->assertSame(1, $this->pubRows(), 'a transient failure keeps the envelope');
        $this->assertSame(0, DB::table('saan_inbox_quarantine')->count());
        $fail = false;
        $r = $this->puller()->run();
        $this->assertSame(1, $r['handled']);
        $this->assertSame(0, $this->pubRows());
        $this->assertSame(['n' => 1], $seen[0]);
        $this->assertSame($png, $seen[1][0]['content']);
        $this->assertSame(hash('sha256', $png), $seen[1][0]['sha256']);
        $this->assertSame('77', DB::table('saan_inbox_done')->value('ref'));
    }

    private function surveyRow(string $mode = 'name_email'): array
    {
        $q = [['key' => 'q1', 'type' => 'single', 'label' => 'ชอบไหม', 'required' => true, 'options' => ['ใช่', 'ไม่']]];
        return ['school_id' => (int) config('school.school_id'), 'slug' => 'survey-sealed', 'title' => 'แบบสอบถามทดสอบ', 'questions' => json_encode($q, JSON_UNESCAPED_UNICODE), 'identity_mode' => $mode, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()];
    }

    public function test_survey_flow_end_to_end_the_mail_comes_from_the_internal_side_and_the_confirm_link_opens_there(): void
    {
        $this->publicSide();
        $this->pairAll();
        $row = $this->surveyRow();
        DB::table('srv_surveys')->insert($row);
        DB::connection('pubdb')->table('srv_surveys')->insert($row); // what the publisher put there
        $this->as('public', 'pubdb');
        Mail::fake();
        $ctl = app(\Saan\Modules\Surveys\Http\SurveyPublicController::class);
        $req = fn (array $d) => \Illuminate\Http\Request::create('/portal/surveys/survey-sealed/submit', 'POST', $d, [], [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9']);

        $res = $ctl->submit($req(['a' => ['q1' => 'ใช่'], 'name' => 'สมหญิง ตอบ', 'email' => 'somying@example.test']), 'survey-sealed');
        $this->assertSame(202, $res->getStatusCode());
        $this->assertStringContainsString('รับคำตอบแล้ว จะตรวจสิทธิ์และยืนยันภายหลัง', $res->getData(true)['message']);
        $this->assertSame('queued', $res->getData(true)['status']);
        Mail::assertNothingSent();
        $this->assertSame(1, $this->pubRows());
        foreach (['srv_pending', 'srv_responses'] as $t) $this->assertSame(0, DB::connection('pubdb')->table($t)->count(), "$t stays empty on the public side");
        $dump = json_encode(DB::connection('pubdb')->table('pub_inbox')->get());
        $this->assertStringNotContainsString('somying', $dump);

        // refused on the public side without any envelope: bad answer, bad e-mail
        $this->assertSame(422, $this->tryStatus(fn () => $ctl->submit($req(['a' => ['q1' => 'อื่น'], 'name' => 'สมหญิง ตอบ', 'email' => 'somying@example.test']), 'survey-sealed')));
        $this->assertSame(422, $this->tryStatus(fn () => $ctl->submit($req(['a' => ['q1' => 'ใช่'], 'name' => 'สมหญิง ตอบ', 'email' => 'not-an-email']), 'survey-sealed')));
        $this->assertSame(1, $this->pubRows());

        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(1, $r['handled'], json_encode($r));
        $sentTo = [];
        Mail::assertSent(SurveyVerifyMail::class, function ($m) use (&$sentTo) { $sentTo[] = $m->url; return $m->hasTo('somying@example.test'); });
        $this->assertStringContainsString('/survey-confirm/survey-sealed/', $sentTo[0], 'the link opens on the internal host');
        $this->assertStringNotContainsString('/portal/', $sentTo[0]);
        $this->assertSame(1, DB::table('srv_pending')->count());
        $this->assertSame(0, DB::table('srv_responses')->count(), 'not counted before the mailbox owner confirms');

        $this->get($sentTo[0])->assertOk()->assertSee('ยืนยัน');
        $this->post($sentTo[0])->assertOk();
        $this->assertSame(1, DB::table('srv_responses')->count());
        $this->assertSame('สมหญิง ตอบ', DB::table('srv_responses')->value('display_name'));
        $this->get($sentTo[0].'x')->assertForbidden(); // a changed signature is refused

        // the same person again: duplicate is decided internally, silently (same reply as a fresh address)
        $this->as('public', 'pubdb');
        $ctl->submit($req(['a' => ['q1' => 'ไม่'], 'name' => 'สมหญิง ตอบ', 'email' => 'somying@example.test']), 'survey-sealed');
        $this->as('internal');
        Mail::fake();
        $r = $this->puller()->run();
        $this->assertSame(1, $r['handled']);
        Mail::assertNothingSent();
        $this->assertSame(1, DB::table('srv_responses')->count());
    }

    private function tryStatus(callable $fn): int
    {
        try { $res = $fn(); return $res->getStatusCode(); }
        catch (\Illuminate\Validation\ValidationException $e) { return 422; }
    }

    public function test_anonymous_survey_flow_and_a_closed_survey(): void
    {
        $this->publicSide();
        $this->pairAll();
        $row = $this->surveyRow('anonymous');
        DB::table('srv_surveys')->insert($row);
        DB::connection('pubdb')->table('srv_surveys')->insert($row);
        $this->as('public', 'pubdb');
        $ctl = app(\Saan\Modules\Surveys\Http\SurveyPublicController::class);
        $mk = fn (array $d, array $cookies = []) => \Illuminate\Http\Request::create('/x', 'POST', $d, $cookies, [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9']);
        $res = $ctl->submit($mk(['a' => ['q1' => 'ใช่']]), 'survey-sealed');
        $this->assertSame(202, $res->getStatusCode());
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'srv_'));
        $this->assertNotNull($cookie, 'the public side issues the anonymous token');
        // the same browser (same token) answers again, and a second browser answers once
        $this->assertSame(202, $ctl->submit($mk(['a' => ['q1' => 'ไม่']], [$cookie->getName() => $cookie->getValue()]), 'survey-sealed')->getStatusCode());
        $this->assertSame(202, $ctl->submit($mk(['a' => ['q1' => 'ไม่']]), 'survey-sealed')->getStatusCode());
        $this->assertSame(3, $this->pubRows());
        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(3, $r['handled']);
        $this->assertSame(2, DB::table('srv_responses')->count(), 'one response per token, decided on the internal side');
        // a survey closed on the public copy is refused before anything is sealed
        DB::connection('pubdb')->table('srv_surveys')->update(['status' => 'closed']);
        $this->as('public', 'pubdb');
        $this->assertSame(410, $this->tryStatus(fn () => $ctl->submit($mk(['a' => ['q1' => 'ใช่']]), 'survey-sealed')));
        $this->assertSame(0, $this->pubRows());
    }

    public function test_combined_edition_dispatches_in_process_without_any_envelope(): void
    {
        $this->as('combined');
        $res = $this->contact('direct');
        $this->assertSame('direct', $res['mode']);
        $this->assertSame(1, DB::table('cms_messages')->count());
        $this->assertSame(0, DB::table('pub_inbox')->count());
        $id = $res['result']['id'];
        $this->post('/portal/contact', ['name' => 'สมชาย', 'email' => 'a@example.test', 'subject' => 'ติดต่อ', 'message' => 'ข้อความที่ยาวพอสมควร', 'consent' => '1'])
            ->assertRedirect('/portal/contact')->assertSessionHas('contact_sent', fn ($v) => str_contains($v, 'เลขอ้างอิง'));
        $this->assertSame(2, DB::table('cms_messages')->count());
        $this->assertGreaterThan($id, (int) DB::table('cms_messages')->max('id'));
        $this->postJson('/portal/contact', ['name' => 'สมชาย', 'email' => 'a@example.test', 'subject' => 'ติดต่อ', 'message' => 'ข้อความที่ยาวพอสมควร', 'consent' => '1'])->assertCreated()->assertJsonStructure(['message', 'id']);
        // an identical message is NOT folded together outside a pulled envelope (unchanged behaviour)
        $this->assertSame(3, DB::table('cms_messages')->count());
        $this->assertSame(0, DB::table('pub_inbox')->count());
        $this->assertSame(0, (new Puller())->run()['pulled']);
        $this->get('/_saan/inbox')->assertNotFound();
        $this->postJson('/saan-pair/redeem', ['code' => 'x', 'push_url' => 'y'])->assertNotFound();
    }

    public function test_the_public_contact_form_and_the_pull_endpoints_over_http(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $this->post('/portal/contact', ['name' => 'สมชาย', 'email' => 'a@example.test', 'subject' => 'ติดต่อ', 'message' => 'ข้อความที่ยาวพอสมควร', 'consent' => '1'])
            ->assertRedirect('/portal/contact')->assertSessionHas('contact_sent', fn ($v) => !str_contains($v, 'เลขอ้างอิง'));
        $this->assertSame(1, $this->pubRows());
        $this->assertSame(0, DB::connection('pubdb')->table('cms_messages')->count());
        $this->postJson('/portal/contact', ['name' => 'สมชาย', 'email' => 'a@example.test', 'subject' => 'ติดต่อ', 'message' => 'ข้อความที่ยาวพอสมควร', 'consent' => '1'])->assertStatus(202)->assertJson(['queued' => true]);
        $this->assertSame(2, $this->pubRows());

        // the pull endpoints: signature is the credential, no session or cookie
        [, , $q, , $h] = $this->signed('GET', '/_saan/inbox', ['after' => 0]);
        $res = $this->withHeaders($h)->get('/_saan/inbox?after=0');
        $res->assertOk()->assertJsonCount(2, 'envelopes');
        $this->assertEmpty($res->baseResponse->headers->getCookies());
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->withHeaders($h)->get('/_saan/inbox?after=0')->assertStatus(401); // replay of the same request
        $ids = array_column($res->json('envelopes'), 'id');
        [, , , $body, $h2] = $this->signed('POST', '/_saan/inbox/ack', [], json_encode(['ids' => [$ids[0]]]));
        $this->call('POST', '/_saan/inbox/ack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SAAN_TIMESTAMP' => $h2['X-Saan-Timestamp'], 'HTTP_X_SAAN_NONCE' => $h2['X-Saan-Nonce'], 'HTTP_X_SAAN_SIGNATURE' => $h2['X-Saan-Signature']], $body)->assertOk()->assertJson(['deleted' => 1]);
        $this->assertSame(1, $this->pubRows());
        $this->get('/_saan/inbox?after=0')->assertStatus(401); // unsigned
        for ($i = 0; $i < 30; $i++) $this->get('/_saan/inbox');
        $this->get('/_saan/inbox')->assertStatus(429); // repeated bad signatures from one address are slowed down
        $this->withHeaders($h)->get('/login')->assertNotFound();
    }

    // ---------------------------------------------------------------- pairing

    public function test_pairing_code_is_single_use_expires_is_rate_limited_and_the_bundle_holds_no_secret(): void
    {
        $this->as('internal');
        $c = Pairing::issue(1, null, self::PUSH);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){7}$/D', $c['code']);
        $this->assertGreaterThan(time() + 800, strtotime($c['expires_at']));
        $this->assertLessThanOrEqual(time() + 900, strtotime($c['expires_at']));
        $this->assertStringNotContainsString(str_replace('-', '', $c['code']), json_encode(DB::table('saan_pairing_codes')->get()), 'only a hash of the code is stored');
        $url = 'https://public.test/_saan/push';

        $this->assertSame(403, Pairing::redeem('AAAA-AAAA-AAAA-AAAA-AAAA-AAAA-AAAA-AAAA', $url, '2.2.2.2')[0], 'wrong code');
        $this->assertSame(403, Pairing::redeem('short', $url, '2.2.2.2')[0]);
        [$st, $r] = Pairing::redeem(strtolower($c['code']), $url, '2.2.2.2');
        $this->assertSame(200, $st, 'case and dashes do not matter');
        $this->assertSame(403, Pairing::redeem($c['code'], $url, '2.2.2.2')[0], 'single use');
        $json = json_encode($r);
        foreach (['push.secret', 'pull.secret', 'inbox.secret'] as $f) $this->assertStringNotContainsString((string) Keys::read($f), $json, $f.' never leaves the internal side');
        $this->assertSame(Keys::publicKey(), $r['bundle']['push_pub']);
        $this->assertSame(Keys::pullPublic(), $r['bundle']['pull_pub']);
        $this->assertSame(Keys::inboxPublic(), $r['bundle']['inbox_pub']);
        $this->assertSame(HashKey::publicUnlock(), $r['bundle']['unlock_key']);
        $this->assertSame($url, Settings::get('saan.public_push_url'));
        $this->assertNotNull(Settings::get('saan.paired_at'));
        $this->assertSame($url, Publisher::rawUrl());

        $c = Pairing::issue(1, time() - 1000, self::PUSH); // expired 100 seconds ago
        $this->assertSame(403, Pairing::redeem($c['code'], $url, '2.2.2.2')[0], 'expired');

        $a = Pairing::issue(1, null, self::PUSH);
        $b = Pairing::issue(1, null, self::PUSH);
        $this->assertSame(403, Pairing::redeem($a['code'], $url, '2.2.2.2')[0], 'a newer code replaces the older one');
        $this->assertSame(200, Pairing::redeem($b['code'], $url, '2.2.2.2')[0]);

        // a correct code used for another address is refused like a wrong code and is not spent (the caller cannot choose the push target)
        $d = Pairing::issue(1, null, self::PUSH);
        $paired = Settings::get('saan.public_push_url');
        foreach (['http://evil.test/_saan/push', 'https://evil.test/_saan/push', 'https://public.test.evil.test/_saan/push', 'https://public.test/other/_saan/push'] as $other) {
            $this->assertSame(403, Pairing::redeem($d['code'], $other, '2.2.2.2')[0], $other);
        }
        $this->assertSame($paired, Settings::get('saan.public_push_url'), 'nothing was changed by the refused attempts');
        $this->assertSame(1, DB::table('saan_pairing_codes')->where('code_hash', hash('sha256', str_replace('-', '', $d['code'])))->whereNull('used_at')->count(), 'not spent by the refused attempts');
        $this->assertSame(200, Pairing::redeem($d['code'], $url.'/', '2.2.2.2')[0], 'the address it was issued for works (trailing slash ignored)');
        $this->assertSame(403, Pairing::redeem($d['code'], $url, '2.2.2.2')[0], 'and the code is spent');
        try { Pairing::issue(1, null, 'http://evil.test/_saan/push'); $this->fail('plain http'); } catch (\RuntimeException) {}
        Settings::put('saan.public_push_url', '');
        try { Pairing::issue(1); $this->fail('no address saved and none given'); } catch (\RuntimeException) {}

        for ($i = 0; $i < 5; $i++) Pairing::redeem('BBBB-BBBB-BBBB-BBBB-BBBB-BBBB-BBBB-BBBB', $url, '9.9.9.9');
        $e = Pairing::issue(1, null, self::PUSH);
        $this->assertSame(429, Pairing::redeem($e['code'], $url, '9.9.9.9')[0], 'guessing from one network is stopped, even with the right code');
        $this->assertSame(200, Pairing::redeem($e['code'], $url, '8.8.8.8')[0], 'other networks are not affected');

        $this->as('combined');
        $this->assertSame(404, Pairing::redeem($e['code'], $url, '7.7.7.7')[0]);
    }

    public function test_the_public_side_pairs_with_a_code_and_uses_the_paired_unlock_key(): void
    {
        $this->publicSide();
        $this->as('internal');
        $code = Pairing::issue(1, null, 'https://www.test/_saan/push')['code'];
        $internalUnlock = HashKey::publicUnlock();
        $this->as('public', 'pubdb');
        $this->assertFalse(Keys::paired());
        try { Pairing::pairWith('http://evil.test', $code, 'https://www.test/_saan/push', fn () => [200, []]); $this->fail('http'); } catch (\RuntimeException) {}
        try { Pairing::pairWith('https://app.test', 'nope', 'https://www.test/_saan/push', fn () => [200, []]); $this->fail('code'); } catch (\RuntimeException) {}
        $calls = 0;
        $transport = function ($url, $body) use (&$calls) {
            $calls++;
            $this->assertSame('https://app.test/saan-pair/redeem', $url);
            $this->as('internal');
            try { return Pairing::redeem($body['code'], $body['push_url'], '3.3.3.3'); } finally { $this->as('public', 'pubdb'); }
        };
        $r = Pairing::pairWith('https://app.test/', strtolower($code), 'https://www.test/_saan/push', $transport);
        $this->assertSame(1, $calls);
        $this->assertTrue(Keys::paired());
        $this->assertSame(Keys::keyId((string) Keys::peer('inbox.pub')), $r['inbox_kid']);
        $this->assertSame($internalUnlock, HashKey::publicUnlock(), 'the public side holds the unlock key it was given');
        // the same code a second time fails and changes nothing
        try { Pairing::pairWith('https://app.test', $code, 'https://www.test/_saan/push', $transport); $this->fail('reuse'); } catch (\RuntimeException $e) { $this->assertStringContainsString('ถูกใช้ไปแล้ว', $e->getMessage()); }
        $this->as('internal');
        $this->assertSame('https://www.test/_saan/push', Publisher::url());
        $this->as('combined');
        try { Pairing::pairWith('https://app.test', $code, 'https://www.test/_saan/push', $transport); $this->fail('edition'); } catch (\RuntimeException) {}
        $this->expectException(\RuntimeException::class);
        Keys::applyBundle(['v' => 1, 'push_pub' => 'x']);
    }

    public function test_the_one_time_web_form_needs_the_owner_token_file_and_removes_it_afterwards(): void
    {
        $this->publicSide();
        $this->as('internal');
        $code = Pairing::issue(1, null, 'https://www.test/_saan/push')['code'];
        $bundle = Pairing::redeem($code, 'https://www.test/_saan/push', '4.4.4.4')[1];
        $code2 = Pairing::issue(1, null, 'https://www.test/_saan/push')['code'];
        $this->as('public', 'pubdb');
        $token = 'Ab3dE5gH7jK9mN1pQ3sT5vW7yZ2bC4eF6hJ8kL0n';
        $file = storage_path('app/private/saan-pair.token');
        @unlink($file);
        $this->get('/_saan/setup')->assertNotFound();
        $this->post('/_saan/setup', ['owner_token' => $token, 'internal_url' => 'https://app.test', 'code' => $code2])->assertNotFound();
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, "short");
        $this->get('/_saan/setup')->assertNotFound();
        file_put_contents($file, $token."\n");
        $this->get('/_saan/setup')->assertOk()->assertSee('รหัสเจ้าของเครื่อง')->assertHeader('X-Robots-Tag', 'noindex');
        $this->post('/_saan/setup', ['owner_token' => 'wrong-token-wrong-token', 'internal_url' => 'https://app.test', 'code' => $code2])->assertStatus(403);
        $this->assertFalse(Keys::paired());
        Http::fake(['https://app.test/saan-pair/redeem' => Http::response(['ok' => true, 'bundle' => $bundle['bundle']], 200)]);
        $this->post('/_saan/setup', ['owner_token' => $token, 'internal_url' => 'http://app.test', 'code' => $code2])->assertStatus(422);
        $this->assertFileExists($file);
        $this->post('/_saan/setup', ['owner_token' => $token, 'internal_url' => 'https://app.test', 'code' => $code2])->assertOk()->assertSee('จับคู่สำเร็จ');
        $this->assertTrue(Keys::paired());
        $this->assertFileDoesNotExist($file);
        $this->get('/_saan/setup')->assertNotFound();
        Http::assertSent(fn ($req) => $req->url() === 'https://app.test/saan-pair/redeem' && $req['push_url'] === rtrim((string) config('app.url'), '/').'/_saan/push');
    }

    public function test_admin_pairing_page_shows_the_code_once_and_only_to_admins(): void
    {
        $this->actingAs(User::where('role', 'admin')->first());
        $this->get('/admin/pairing')->assertOk()->assertSee('ไม่ต้องจับคู่');
        $this->as('internal');
        $this->get('/admin/pairing')->assertOk()->assertSee('สร้างรหัสจับคู่')->assertSee('ยังไม่เคยจับคู่');
        $this->post('/admin/pairing/code', ['public_url' => 'ftp://x'])->assertRedirect('/admin/pairing');
        $this->assertSame(0, DB::table('saan_pairing_codes')->count());
        $this->post('/admin/pairing/code', ['public_url' => 'https://www.school.test/'])->assertRedirect('/admin/pairing');
        $html = $this->get('/admin/pairing')->assertOk()->assertSee('แสดงครั้งนี้ครั้งเดียว')->assertSee('saan:pair')->getContent();
        $this->assertMatchesRegularExpression('/[A-Z2-7]{4}(-[A-Z2-7]{4}){7}/', $html);
        $this->assertStringNotContainsString('แสดงครั้งนี้ครั้งเดียว', $this->get('/admin/pairing')->getContent(), 'a reload does not show it again');
        $this->assertSame('https://www.school.test/_saan/push', Settings::get('saan.public_push_url'));
        $this->actingAs(User::where('role', 'teacher')->first());
        $this->get('/admin/pairing')->assertForbidden();
        $this->post('/admin/pairing/code', ['public_url' => 'https://www.school.test'])->assertForbidden();
    }

    // ---------------------------------------------------------------- rotation

    public function test_rotation_keeps_the_old_inbox_key_until_every_old_envelope_is_pulled(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $this->contact('old-1');
        Inbox::submit('flaky', ['n' => 1]); // sealed with the old key, its handler fails at first
        $this->as('internal');
        $oldKid = Keys::keyId(Keys::inboxPublic());
        $this->assertSame(['inbox'], Keys::rotate(['inbox']));
        $newKid = Keys::keyId(Keys::inboxPublic());
        $this->assertNotSame($oldKid, $newKid);
        $this->assertSame([$oldKid], Keys::retiredInboxKeys());
        $this->assertSame([$newKid, $oldKid], array_keys(Keys::inboxSecrets()), 'both keys can open envelopes');

        // the public side still holds the old public key: new envelopes are sealed with it, so the old key stays needed
        $this->as('public', 'pubdb');
        $this->contact('old-2');
        $this->as('internal');
        $failing = true;
        Handlers::register('flaky', function () use (&$failing) { if ($failing) throw new \RuntimeException('busy'); return ['id' => 5]; });
        $r = $this->puller()->run();
        $this->assertSame(2, $r['handled']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame([$oldKid], Keys::retiredInboxKeys(), 'not dropped: the public side is not paired with the new key and an old envelope is waiting');

        // pair again (new code): the public side now seals with the new key
        $c = Pairing::issue(1, null, self::PUSH);
        Keys::applyBundle(Pairing::redeem($c['code'], 'https://public.test/_saan/push', '10.0.0.2')[1]['bundle']);
        $this->assertSame($newKid, Keys::keyId((string) Keys::peer('inbox.pub')));
        $this->as('public', 'pubdb');
        $this->contact('new-1');
        $this->assertSame([$oldKid, $newKid], DB::connection('pubdb')->table('pub_inbox')->orderBy('id')->pluck('key_id')->all(), 'one old-key envelope is still waiting, the new one uses the new key');
        $this->as('internal');

        // the pull fails altogether: nothing is dropped
        $r = $this->puller(fn () => [500, ['error' => 'down']])->run();
        $this->assertArrayHasKey('error', $r);
        $this->assertSame([$oldKid], Keys::retiredInboxKeys());

        // one envelope (old key) still fails: not dropped
        $r = $this->puller()->run();
        $this->assertSame(1, $r['failed']);
        $this->assertSame([$oldKid], Keys::retiredInboxKeys());
        $this->assertSame(3, DB::table('cms_messages')->count());
        $this->assertSame(1, $this->pubRows());

        // it finally works: everything sealed with the old key is pulled, the public side uses the new key, so the old secret goes
        $failing = false;
        $r = $this->puller()->run();
        $this->assertSame(['pulled' => 1, 'handled' => 1, 'quarantined' => 0, 'failed' => 0], $r);
        $this->assertSame(0, $this->pubRows());
        $this->assertSame([$oldKid], Keys::retiredInboxKeys(), 'still kept: the public side reported the new key for less than an hour (an old-key envelope may be in flight)');
        $this->assertFileExists($this->keysDir.'/inbox.old.'.$oldKid.'.secret');

        // an hour after the public side first reported the new key and with nothing waiting, the old secret goes
        $this->puller(null, time() + 3700)->run();
        $this->assertSame([], Keys::retiredInboxKeys());
        $this->assertFileDoesNotExist($this->keysDir.'/inbox.old.'.$oldKid.'.secret');
    }

    public function test_a_public_side_that_reports_an_unknown_inbox_key_is_flagged_for_the_doctor(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('internal');
        $this->assertTrue(collect(SplitHealth::checks())->keyBy(0)['split_foreign_kid'][2]);
        // a breached public side now seals with a key of its own
        $attacker = base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));
        file_put_contents($this->keysDir.'/peer-inbox.pub', $attacker);
        $this->as('public', 'pubdb');
        $this->contact('foreign');
        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(1, $r['failed'], 'kept, not lost');
        $this->assertSame(1, State::count(null, 'inbox.foreign_kid'));
        $row = collect(SplitHealth::checks())->keyBy(0)['split_foreign_kid'];
        $this->assertFalse($row[2]);
        $this->assertTrue($row[4], 'reported as an error');
    }

    public function test_a_pile_of_failing_envelopes_does_not_starve_new_ones(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        for ($i = 0; $i < 120; $i++) Inbox::submit('stuck', ['n' => $i]);
        $this->as('internal');
        Handlers::register('stuck', fn () => throw new \RuntimeException('smtp down'));
        $r = $this->puller()->run();
        $this->assertSame(120, $r['failed']);
        $this->as('public', 'pubdb');
        $this->contact('fresh');
        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertSame(1, $r['handled'], 'the new envelope is pulled at once, behind the 120 that failed');
        $this->assertSame(PullServer::PAGE, $r['failed'], 'only one page of the failed ones is retried per run');
        $this->assertSame(1, DB::table('cms_messages')->count());
        $this->assertGreaterThan(0, State::count(null, 'inbox.cursor'));
        $this->assertSame(120, $this->pubRows());
        // the walk goes on from where it stopped (fair, round robin) instead of starting at the same 50 every time
        $r = $this->puller()->run();
        $this->assertSame(20, $r['failed']);
        $this->assertSame(120, $this->pubRows());
    }

    public function test_rotating_push_pull_and_unlock_keys_replaces_them_and_the_command_prints_a_code(): void
    {
        $this->as('internal');
        $push = Keys::publicKey();
        $pull = Keys::pullPublic();
        $unlockBefore = HashKey::publicUnlock();
        $this->artisan('saan:keys-rotate')->assertExitCode(1);
        Settings::put('saan.public_push_url', self::PUSH);
        $this->artisan('saan:keys-rotate', ['--all' => true])->expectsOutputToContain('รหัสจับคู่ใหม่')->assertExitCode(0);
        $this->assertSame(self::PUSH, DB::table('saan_pairing_codes')->whereNull('used_at')->value('push_url'), 'the new code is bound to the saved address');
        $this->assertNotSame($push, Keys::publicKey());
        $this->assertNotSame($pull, Keys::pullPublic());
        $this->assertNotSame($unlockBefore, HashKey::publicUnlock());
        $this->assertSame(Keys::unlockKey(), HashKey::publicUnlock());
        $this->assertContains($unlockBefore, HashKey::unlockCandidates(), 'codes issued before the rotation still verify on the internal side');
        $this->assertSame(1, DB::table('saan_pairing_codes')->whereNull('used_at')->count());
        $this->assertSame(base64_encode(sodium_crypto_sign_publickey_from_secretkey(Keys::pullSecret())), Keys::pullPublic());
        $this->artisan('saan:keys-rotate', ['--pull' => true, '--no-code' => true])->doesntExpectOutputToContain('รหัสจับคู่ใหม่')->assertExitCode(0);
        $this->as('public', 'pubdb');
        $this->artisan('saan:keys-rotate', ['--all' => true])->assertExitCode(1);
        $this->expectException(\InvalidArgumentException::class);
        $this->as('internal');
        Keys::rotate(['nope']);
    }

    // ---------------------------------------------------------------- doctor

    public function test_doctor_reports_pairing_pull_envelopes_signature_failures_and_key_files(): void
    {
        $this->publicSide();
        $this->as('combined');
        $this->assertSame([], SplitHealth::checks());
        $this->assertNotContains('split_paired', array_column(app(\App\Domain\Cms\DeploymentHealth::class)->inspect()['checks'], 'key'));

        $this->as('public', 'pubdb');
        $c = collect(SplitHealth::checks())->keyBy(0);
        $this->assertFalse($c['split_paired'][2]);
        $this->assertTrue($c['split_keys_webroot'][2]);
        $this->pairAll();
        $this->as('public', 'pubdb');
        $c = collect(SplitHealth::checks())->keyBy(0);
        $this->assertTrue($c['split_paired'][2]);
        $this->assertFalse($c['split_last_pull'][2], 'nobody pulled yet');
        $this->contact('x');
        (new PullServer('pubdb'))->handle(...$this->signed('GET', '/_saan/inbox', []));
        (new PullServer('pubdb'))->handle('GET', '/_saan/inbox', [], '', ['X-Saan-Timestamp' => (string) time(), 'X-Saan-Nonce' => str_repeat('c', 32), 'X-Saan-Signature' => 'bad']);
        $c = collect(SplitHealth::checks())->keyBy(0);
        $this->assertTrue($c['split_last_pull'][2]);
        $this->assertFalse($c['split_sigfail'][2]);
        $this->assertStringContainsString('1 ครั้ง', $c['split_sigfail'][3]);
        $this->assertStringContainsString('1 ซอง', $c['split_inbox_waiting'][3]);
        $report = app(\App\Domain\Cms\DeploymentHealth::class)->inspect();
        $this->assertContains('split_sigfail', array_column($report['checks'], 'key'));

        // keys inside the web root are an error
        config(['saan.keys_dir' => public_path('keys-test')]);
        $c = collect(SplitHealth::checks())->keyBy(0);
        $this->assertFalse($c['split_keys_webroot'][2]);
        $this->assertTrue($c['split_keys_webroot'][4], 'it blocks updates');
        config(['saan.keys_dir' => $this->keysDir]);

        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($this->keysDir.'/peer-push.pub', 0644);
            $c = collect(SplitHealth::checks())->keyBy(0);
            $this->assertFalse($c['split_key_perms'][2]);
            $this->assertStringContainsString('peer-push.pub', $c['split_key_perms'][3]);
            chmod($this->keysDir.'/peer-push.pub', 0600);
        }

        $this->as('internal');
        $c = collect(SplitHealth::checks())->keyBy(0);
        $this->assertTrue($c['split_paired'][2], 'pairAll() paired it');
        $this->assertFalse($c['split_last_pull'][2], 'the puller has not run');
        State::mark(null, 'inbox.pull', time());
        $c = collect(SplitHealth::checks())->keyBy(0);
        $this->assertTrue($c['split_paired'][2]);
        $this->assertTrue($c['split_last_pull'][2]);
        $this->assertTrue($c['split_quarantine'][2]);
        DB::table('saan_inbox_quarantine')->insert(['kind' => 'x', 'reason' => 'r', 'created_at' => now()]);
        $this->assertFalse(collect(SplitHealth::checks())->keyBy(0)['split_quarantine'][2]);
    }

    public function test_key_files_are_private_and_created_once(): void
    {
        $this->as('internal');
        $a = [Keys::pullSecret(), Keys::inboxSecret(), Keys::pullPublic(), Keys::inboxPublic()];
        $b = [Keys::pullSecret(), Keys::inboxSecret(), Keys::pullPublic(), Keys::inboxPublic()];
        $this->assertSame($a, $b, 'generated once, then read');
        foreach (['pull.secret', 'inbox.secret', 'pull.pub', 'inbox.pub'] as $f) $this->assertFileExists($this->keysDir.'/'.$f);
        if (DIRECTORY_SEPARATOR !== '\\') foreach (['pull.secret', 'inbox.secret'] as $f) $this->assertSame(0600, fileperms($this->keysDir.'/'.$f) & 0777);
        // push.pub is written atomically through put() (0600), also when it is rewritten after it went missing or differs
        Keys::publicKey();
        @unlink($this->keysDir.'/push.pub');
        $pub = Keys::publicKey();
        $this->assertSame($pub, Keys::read('push.pub'));
        file_put_contents($this->keysDir.'/push.pub', 'stale');
        $this->assertSame($pub, Keys::publicKey());
        $this->assertSame($pub, Keys::read('push.pub'));
        if (DIRECTORY_SEPARATOR !== '\\') foreach (glob($this->keysDir.'/*') as $f) $this->assertSame(0600, fileperms($f) & 0777, basename($f));
        config(['saan.keys_dir' => public_path('inside-web-root')]);
        $this->expectException(\RuntimeException::class);
        Keys::pullSecret();
    }

    // ---------------------------------------------------------------- review fixes

    /** An envelope built the way the public side builds one, but with fields the tests control. @return array{ciphertext:string,attachments:?string,key_id:string,env:array} */
    private function forge(array $envOver = [], array $files = []): array
    {
        $pub = (string) Keys::peer('inbox.pub');
        $raw = base64_decode($pub, true);
        $kid = Keys::keyId($pub);
        $meta = [];
        $att = [];
        foreach ($files as $f) {
            $meta[] = ['name' => $f['name'], 'mime' => 'image/png', 'size' => strlen($f['content']), 'sha256' => hash('sha256', $f['content'])];
            $att[] = ['c' => base64_encode(sodium_crypto_box_seal($f['content'], $raw))];
        }
        $env = array_replace(['v' => 1, 'kind' => 'contact', 'ts' => time(), 'nonce' => bin2hex(random_bytes(16)), 'kid' => $kid,
            'payload' => ['name' => 'สมชาย', 'email' => 'forged@example.test', 'subject' => 'เรื่องทดสอบ', 'message' => 'ข้อความทดสอบยาวพอสมควร'], 'files' => $meta], $envOver);
        $ct = base64_encode(sodium_crypto_box_seal(json_encode($env, JSON_UNESCAPED_UNICODE), $raw));
        return ['ciphertext' => $ct, 'attachments' => $att ? json_encode($att) : null, 'key_id' => $kid, 'env' => $env];
    }

    private function insertPub(array $f, ?string $hash = null): int
    {
        return (int) DB::connection('pubdb')->table('pub_inbox')->insertGetId(['kind' => $f['env']['kind'] ?? 'contact', 'received_at' => date('Y-m-d H:i:s'), 'dedupe_hash' => $hash ?? hash('sha256', random_bytes(8)),
            'key_id' => $f['key_id'], 'ciphertext' => $f['ciphertext'], 'attachments' => $f['attachments'], 'size' => strlen($f['ciphertext'])]);
    }

    public function test_a_real_ciphertext_put_back_under_a_new_outer_hash_is_not_handled_again(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        $this->contact('replay');
        $row = DB::connection('pubdb')->table('pub_inbox')->first();
        $this->as('internal');
        $this->assertSame(1, $this->puller()->run()['handled']);
        $this->assertSame(1, DB::table('cms_messages')->count());
        DB::table('cms_messages')->update(['created_at' => now()->subHour()]); // so the ten minute rule of the contact handler is not what stops the replay

        // whoever controls the public side keeps the ciphertext and puts it back, again and again, each time with a new outer hash
        for ($i = 1; $i <= 3; $i++) {
            $this->insertPub(['ciphertext' => $row->ciphertext, 'attachments' => $row->attachments, 'key_id' => $row->key_id, 'env' => ['kind' => 'contact']]);
            $r = $this->puller()->run();
            $this->assertSame(0, $r['handled'], 'replay '.$i);
            $this->assertSame(1, DB::table('cms_messages')->count(), 'no second message');
            $this->assertSame($i, State::count(null, 'inbox.replay'));
            $this->assertSame(0, $this->pubRows(), 'it is acknowledged and gone');
        }
        $c = collect(SplitHealth::checks())->keyBy(0);
        $this->assertFalse($c['split_inbox_replay'][2]);

        // a new envelope whose outer hash was set to a value of an old row is still handled: only the nonce inside the envelope counts
        $this->as('public', 'pubdb');
        $this->contact('genuine');
        DB::connection('pubdb')->table('pub_inbox')->update(['dedupe_hash' => $row->dedupe_hash]);
        $this->as('internal');
        $this->assertSame(1, $this->puller()->run()['handled']);
        $this->assertSame(2, DB::table('cms_messages')->count());

        // a replay of a handled envelope after a lost ack (same outer hash) is a benign skip, not counted
        $this->as('public', 'pubdb');
        $this->contact('lost-ack');
        $this->as('internal');
        $this->puller(fn ($m, $p) => $p === PullServer::ACK ? [500, ['error' => 'lost']] : null)->run();
        $before = State::count(null, 'inbox.replay');
        $this->puller()->run();
        $this->assertSame($before, State::count(null, 'inbox.replay'));
        $this->assertSame(3, DB::table('cms_messages')->count());
    }

    public function test_old_and_oversized_envelopes_and_hostile_file_names_are_refused_on_the_internal_side(): void
    {
        $this->publicSide();
        $this->pairAll();
        $png = base64_decode(self::PNG);
        $this->as('internal');
        $open = fn (array $f) => Inbox::open($f['ciphertext'], $f['attachments'], $f['key_id']);
        $refused = function (array $f, int $status, string $why) use ($open) {
            try { $open($f); $this->fail($why); } catch (InboxRejected $e) { $this->assertSame($status, $e->status, $why.': '.$e->getMessage()); }
        };
        $this->assertSame('contact', $open($this->forge())['env']['kind']);

        // too old: older than the public side keeps anything (7 days) plus a day
        $refused($this->forge(['ts' => time() - 9 * 86400]), 422, 'old');
        $this->assertSame('contact', $open($this->forge(['ts' => time() - 7 * 86400]))['env']['kind'], 'seven days is still fine');
        $refused($this->forge(['ts' => time() + 4000]), 422, 'future');

        // too big, checked before anything is decoded
        $refused(['ciphertext' => str_repeat('A', 600000), 'attachments' => null, 'key_id' => $this->forge()['key_id']], 413, 'ciphertext');
        config(['saan.inbox.max_payload_kb' => 1]);
        $refused($this->forge(['payload' => ['message' => str_repeat('x', 6000)]]), 413, 'payload after decryption or before');
        config(['saan.inbox.max_payload_kb' => 256, 'saan.inbox.max_file_kb' => 1]);
        $refused($this->forge([], [['name' => 'big.png', 'content' => $png.str_repeat('x', 3000)]]), 413, 'file');
        config(['saan.inbox.max_file_kb' => 8192, 'saan.inbox.max_total_kb' => 1]);
        $refused($this->forge([], [['name' => 'a.png', 'content' => $png.str_repeat('x', 700)], ['name' => 'b.png', 'content' => $png.str_repeat('x', 700)]]), 413, 'total');
        config(['saan.inbox.max_total_kb' => 20480]);

        // names are labels: no directories, no control characters
        $o = $open($this->forge([], [['name' => '..\\..\\etc/evil.png', 'content' => $png]]));
        $this->assertSame('evil.png', $o['files'][0]['name']);
        $refused($this->forge([], [['name' => "a\x01b.png", 'content' => $png]]), 422, 'control');
        $refused($this->forge([], [['name' => '/', 'content' => $png]]), 422, 'empty after basename');
        $o = $open($this->forge([], [['name' => str_repeat('n', 300).'.png', 'content' => $png]]));
        $this->assertLessThanOrEqual(120, mb_strlen($o['files'][0]['name']));
    }

    public function test_the_pull_reply_is_read_with_a_ceiling_and_the_quarantine_is_bounded(): void
    {
        $stream = fn (int $n) => \GuzzleHttp\Psr7\Utils::streamFor(str_repeat('a', $n));
        $this->assertSame(100, strlen(Puller::readCapped($stream(100), 200)));
        foreach ([201, 5000] as $n) {
            try { Puller::readCapped($stream($n), 200); $this->fail('over the ceiling'); } catch (\RuntimeException $e) { $this->assertStringContainsString('ใหญ่เกิน', $e->getMessage()); }
        }
        $this->assertGreaterThanOrEqual(PullServer::PAGE_BYTES * 2, Puller::maxBody());
        $this->assertLessThan(64 * 1048576, Puller::maxBody());

        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        Inbox::submit('big.junk', ['x' => str_repeat('a', 200000)]);
        for ($i = 0; $i < 119; $i++) Inbox::submit('junk', ['n' => $i]);
        $this->as('internal');
        $r = $this->puller()->run();
        // the budget is split by kind: 100 of the 119 'junk' rows, and the one 'big.junk' row has a share of its own
        $this->assertSame(101, $r['quarantined'], 'at most 100 rows per kind per run');
        $this->assertSame(19, $r['failed'], 'the rest of the flooded kind stays on the public side');
        $this->assertSame(101, DB::table('saan_inbox_quarantine')->count());
        $this->assertSame(100, DB::table('saan_inbox_quarantine')->where('kind', 'junk')->count());
        $this->assertSame(19, $this->pubRows());
        $kept = (string) DB::table('saan_inbox_quarantine')->where('kind', 'big.junk')->value('payload');
        $this->assertGreaterThan(0, strlen($kept));
        $this->assertLessThanOrEqual(65536, strlen($kept), 'the stored payload is cut');
    }

    public function test_old_idempotency_and_quarantine_rows_are_pruned_once_a_day(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('internal');
        $old = date('Y-m-d H:i:s', time() - 40 * 86400);
        DB::table('saan_inbox_done')->insert([['dedupe_hash' => str_repeat('a', 64), 'kind' => 'contact', 'outcome' => 'ok', 'handled_at' => $old], ['dedupe_hash' => str_repeat('b', 64), 'kind' => 'contact', 'outcome' => 'ok', 'handled_at' => date('Y-m-d H:i:s', time() - 10 * 86400)]]);
        DB::table('saan_inbox_quarantine')->insert([['kind' => 'x', 'reason' => 'old', 'created_at' => date('Y-m-d H:i:s', time() - 100 * 86400)], ['kind' => 'x', 'reason' => 'new', 'created_at' => date('Y-m-d H:i:s', time() - 80 * 86400)]]);
        $this->puller()->run();
        $this->assertSame([str_repeat('b', 64)], DB::table('saan_inbox_done')->pluck('dedupe_hash')->all(), '30 days for idempotency rows (the nonce window is 8)');
        $this->assertSame(['new'], DB::table('saan_inbox_quarantine')->pluck('reason')->all(), '90 days for quarantine');
        DB::table('saan_inbox_done')->insert(['dedupe_hash' => str_repeat('c', 64), 'kind' => 'contact', 'outcome' => 'ok', 'handled_at' => $old]);
        $this->puller()->run();
        $this->assertTrue(DB::table('saan_inbox_done')->where('dedupe_hash', str_repeat('c', 64))->exists(), 'pruned once a day, not every minute');
    }

    public function test_survey_envelopes_cannot_guess_roster_codes_or_flood_an_anonymous_survey_whatever_ip_and_session_they_claim(): void
    {
        $this->as('internal');
        $handler = new \Saan\Modules\Surveys\InboxHandler();
        $row = $this->surveyRow('roster_code');
        $id = DB::table('srv_surveys')->insertGetId($row);
        $mk = fn (int $i, string $code = 'WRONG') => ['slug' => 'survey-sealed', 'input' => ['a' => ['q1' => 'ใช่'], 'code' => $code], 'ip' => '198.51.100.'.($i % 250 + 1), 'cookie' => bin2hex(random_bytes(16)), 'session' => hash('sha256', 'session-'.$i)];
        for ($i = 0; $i < 299; $i++) {
            try { $handler->handle($mk($i), [], []); $this->fail('a wrong code was accepted'); } catch (\Illuminate\Validation\ValidationException) {}
        }
        $this->assertSame(299, State::count(null, 'inbox.badcode'));
        $this->assertFalse(collect(SplitHealth::checks())->keyBy(0)['split_inbox_badcode'][2]);
        // wrong codes count against one hourly ceiling of the survey that no payload hint can change: past it more wrong codes wait (a transient refusal)
        try { $handler->handle($mk(299), [], []); $this->fail('wrong code 300'); } catch (\Illuminate\Validation\ValidationException) {}
        try { $handler->handle($mk(300), [], []); $this->fail('hourly ceiling'); } catch (\RuntimeException $e) { $this->assertStringContainsString('roster_ceiling', $e->getMessage()); }
        // junk from anonymous senders does not stop a real student: with the ceiling full of somebody else's wrong codes a valid unused code still goes through
        $svc = app(\Saan\Modules\Surveys\SurveyService::class);
        DB::table('srv_roster')->insert(['survey_id' => $id, 'display_name' => 'นักเรียน', 'code_hash' => $svc->key($id, 'roster_code', \Saan\Modules\Surveys\SurveyService::normaliseCode('GOOD1234'))]);
        $this->assertSame('done', $handler->handle($mk(1, 'GOOD1234'), [], [])['status']);
        // the same code a second time is a used code: it is a wrong code again and waits behind the ceiling
        try { $handler->handle($mk(3, 'GOOD1234'), [], []); $this->fail('used code'); } catch (\RuntimeException $e) { $this->assertStringContainsString('roster_ceiling', $e->getMessage()); }
        // wrong codes of ONE network are delayed for that network only
        DB::table('srv_surveys')->insert(['slug' => 'survey-net'] + $row);
        $net = fn (string $code, string $ip) => ['slug' => 'survey-net', 'input' => ['a' => ['q1' => 'ใช่'], 'code' => $code], 'ip' => $ip, 'cookie' => bin2hex(random_bytes(16)), 'session' => ''];
        $netS = fn (string $code, string $ip, int $i) => $net($code, $ip) + [] ; // (a new browser session per try: the form's own per-session limiter is not what is tested)
        for ($i = 0; $i < 60; $i++) {
            $x = $net('WRONG', '192.0.2.50'); $x['session'] = 'sess-'.$i;
            try { $handler->handle($x, [], []); $this->fail(); } catch (\Illuminate\Validation\ValidationException) {}
        }
        try { $x = $net('WRONG', '192.0.2.50'); $x['session'] = 'sess-x'; $handler->handle($x, [], []); $this->fail('per network'); } catch (\RuntimeException $e) { $this->assertStringContainsString('code_net', $e->getMessage()); }
        try { $x = $net('WRONG', '192.0.2.51'); $x['session'] = 'sess-y'; $handler->handle($x, [], []); $this->fail(); } catch (\Illuminate\Validation\ValidationException) { $this->addToAssertionCount(1); } // another network is not affected

        // anonymous: a ceiling per survey per hour that does not depend on the claimed network
        DB::table('srv_surveys')->insert(['slug' => 'survey-anon', 'identity_mode' => 'anonymous', 'anon_ip_hour' => 1] + $row);
        $anon = fn (int $i) => ['slug' => 'survey-anon', 'input' => ['a' => ['q1' => 'ใช่']], 'ip' => '203.0.113.'.($i + 1), 'cookie' => bin2hex(random_bytes(16)), 'session' => ''];
        for ($i = 0; $i < 10; $i++) $this->assertSame('done', $handler->handle($anon($i), [], [])['status']);
        try { $handler->handle($anon(11), [], []); $this->fail('anonymous flood'); } catch (\RuntimeException $e) { $this->assertStringContainsString('anon_global', $e->getMessage()); }
        $this->assertSame(11, DB::table('srv_responses')->count(), '10 anonymous answers and the one roster answer');
    }

    public function test_the_real_session_id_never_leaves_the_public_side(): void
    {
        $this->publicSide();
        $this->pairAll();
        $row = $this->surveyRow('anonymous');
        DB::table('srv_surveys')->insert($row);
        DB::connection('pubdb')->table('srv_surveys')->insert($row);
        $this->as('public', 'pubdb');
        $req = \Illuminate\Http\Request::create('/x', 'POST', ['a' => ['q1' => 'ใช่']], [], [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9']);
        $store = app('session.store');
        $store->start();
        $req->setLaravelSession($store);
        $this->assertSame(202, app(\Saan\Modules\Surveys\Http\SurveyPublicController::class)->submit($req, 'survey-sealed')->getStatusCode());
        $pub = DB::connection('pubdb')->table('pub_inbox')->first();
        $this->as('internal');
        $payload = Inbox::open($pub->ciphertext, $pub->attachments, $pub->key_id)['env']['payload'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $payload['session']);
        $this->assertNotSame($store->getId(), $payload['session']);
        $this->assertStringNotContainsString($store->getId(), json_encode($payload));
        $this->assertSame($payload['session'], hash_hmac('sha256', $store->getId(), HashKey::bytes(HashKey::secret())));
    }

    public function test_a_refused_contact_form_does_not_flash_personal_data_into_the_public_session(): void
    {
        $this->publicSide();
        $this->as('public', 'pubdb'); // not paired: the inbox refuses
        $this->post('/portal/contact', ['name' => 'สมชาย ลับ', 'email' => 'secret.person@example.test', 'subject' => 'เรื่องติดต่อ', 'message' => 'ข้อความลับที่ยาวพอสมควร', 'consent' => '1'])
            ->assertRedirect('/portal/contact')->assertSessionHasErrors('message');
        $this->assertSame(['subject' => 'เรื่องติดต่อ'], session()->get('_old_input'));
        $this->as('combined');
        $this->post('/portal/contact', ['name' => 'x', 'email' => 'not-an-email', 'subject' => 's', 'message' => 'short'])->assertSessionHasErrors();
    }

    public function test_pairing_counter_ignores_junk_and_a_new_code_lifts_a_lock(): void
    {
        $this->as('internal');
        // 40 junk requests from different networks never lock pairing for everybody
        for ($i = 0; $i < 40; $i++) Pairing::redeem('junk', self::PUSH, '10.1.'.intdiv($i, 250).'.'.($i % 250));
        $c = Pairing::issue(1, null, self::PUSH);
        $this->assertSame(200, Pairing::redeem($c['code'], self::PUSH, '10.2.0.1')[0], 'junk did not count');

        // well formed guesses from many networks never lock pairing for everybody (a 160 bit code needs no global ceiling); they are only counted for the doctor
        for ($i = 0; $i < 60; $i++) $this->assertSame(403, Pairing::redeem('BBBB-BBBB-BBBB-BBBB-BBBB-BBBB-BBBB-BBBB', self::PUSH, '10.3.'.intdiv($i, 200).'.'.($i % 200 + 1))[0]);
        $this->assertSame(60, State::count(null, 'pair.guess'));
        $d = Pairing::issue(1, null, self::PUSH);
        $this->assertSame(200, Pairing::redeem($d['code'], self::PUSH, '10.4.0.1')[0], 'the real code still works after 60 guesses from other networks');
        // one network is still stopped: per /64 and per /48 of IPv6
        for ($i = 0; $i < 20; $i++) Pairing::redeem('BBBB-BBBB-BBBB-BBBB-BBBB-BBBB-BBBB-BBBB', self::PUSH, '2001:db8:1:'.dechex($i + 1).'::1');
        $this->assertSame(429, Pairing::redeem('CCCC-CCCC-CCCC-CCCC-CCCC-CCCC-CCCC-CCCC', self::PUSH, '2001:db8:1:ff::1')[0], 'rotating /64s inside one /48 is stopped');
    }

    public function test_the_setup_token_must_be_strong_and_the_page_closes_after_ten_wrong_tokens(): void
    {
        $this->assertFalse(\App\Http\Controllers\SaanSetupController::strongToken(str_repeat('a', 40)));
        $this->assertFalse(\App\Http\Controllers\SaanSetupController::strongToken('aaaaaaaabbbbbbbbccccccccdddddddd'));
        $this->assertFalse(\App\Http\Controllers\SaanSetupController::strongToken('Ab3dE5gH7jK9mN1pQ3sT5vW7yZ2bC4e'), '31 characters');
        $this->assertFalse(\App\Http\Controllers\SaanSetupController::strongToken('Ab3dE5gH7jK9mN1pQ3sT5vW7yZ2bC4eF!'), 'only [A-Za-z0-9_-]');
        $this->assertTrue(\App\Http\Controllers\SaanSetupController::strongToken('Ab3dE5gH7jK9mN1pQ3sT5vW7yZ2bC4eF6hJ8'));

        $this->publicSide();
        $this->as('public', 'pubdb');
        $file = storage_path('app/private/saan-pair.token');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, str_repeat('a', 40));
        $this->get('/_saan/setup')->assertNotFound();
        $token = 'Ab3dE5gH7jK9mN1pQ3sT5vW7yZ2bC4eF6hJ8kL0n';
        file_put_contents($file, $token);
        $this->get('/_saan/setup')->assertOk();
        $this->assertTrue(collect(SplitHealth::checks())->keyBy(0)['split_pair_token'][2], 'fresh file');
        touch($file, time() - 7200);
        clearstatcache();
        $c = collect(SplitHealth::checks())->keyBy(0)['split_pair_token'];
        $this->assertFalse($c[2], 'a token file lying around for over an hour is reported');
        $this->assertStringContainsString('saan:pair-token --remove', $c[3]);
        touch($file);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // the route throttle (5 a minute) is a separate layer
        // ten wrong tokens from one network lock THAT network out for a while; the token file is not deleted (anybody could otherwise delete it on purpose)
        for ($i = 0; $i < 10; $i++) $this->post('/_saan/setup', ['owner_token' => 'wrong-token-wrong-token', 'internal_url' => 'https://app.test', 'code' => 'x'])->assertStatus(403);
        $this->post('/_saan/setup', ['owner_token' => $token, 'internal_url' => 'https://app.test', 'code' => 'x'])->assertStatus(429);
        $this->assertFileExists($file);
        $this->assertFalse(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts('saan-setup-wrong:203.0.113.77', 10), 'the lock is per network');
        \Illuminate\Support\Facades\RateLimiter::clear('saan-setup-wrong:127.0.0.1');
        $this->post('/_saan/setup', ['owner_token' => 'wrong-token-wrong-token', 'internal_url' => 'https://app.test', 'code' => 'x'])->assertStatus(403);
        @unlink($file);
        $this->get('/_saan/setup')->assertNotFound();
        $this->post('/_saan/setup', ['owner_token' => $token, 'internal_url' => 'https://app.test', 'code' => 'x'])->assertNotFound();

        // the command writes a token that passes the check
        $this->artisan('saan:pair-token')->assertExitCode(0);
        $this->assertTrue(\App\Http\Controllers\SaanSetupController::strongToken(trim((string) file_get_contents($file))));
        $this->artisan('saan:pair-token', ['--remove' => true])->assertExitCode(0);
        $this->assertFileDoesNotExist($file);
        $this->as('internal');
        $this->artisan('saan:pair-token')->assertExitCode(1);
    }

    // ---------------------------------------------------------------- split review fixes

    public function test_the_contact_form_has_its_own_share_of_the_inbox_and_a_limiter_that_ipv6_rotation_cannot_dodge(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        config(['saan.inbox.max_waiting_contact' => 2, 'school.demo' => false, 'school.local_only' => false]); // the demo boundary only answers loopback addresses
        $this->contact('1');
        $this->contact('2');
        try { $this->contact('3'); $this->fail('the contact share is full'); } catch (InboxRejected $e) { $this->assertSame(503, $e->status); }
        Inbox::submit('survey', ['slug' => 'x-survey', 'input' => []]); // another kind still has room
        $this->assertSame(3, $this->pubRows());
        // five tries from five addresses of one /64 use up the limit of that network; the sixth address of the same /64 is stopped
        for ($i = 1; $i <= 5; $i++) $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:7:1::'.$i])->post('/portal/contact', []);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:7:1::99'])->post('/portal/contact', [])->assertStatus(429);
        // the same /48 is limited as a whole (30 an hour) even when every /64 stays under its own limit
        for ($i = 1; $i <= 24; $i++) $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:8:'.dechex($i).'::1'])->post('/portal/contact', []);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:8:ffff::1'])->post('/portal/contact', [])->assertStatus(302);
        for ($i = 1; $i <= 5; $i++) $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:8:'.dechex(100 + $i).'::1'])->post('/portal/contact', []);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:8:abcd::1'])->post('/portal/contact', [])->assertStatus(429);
    }

    public function test_an_envelope_with_array_typed_file_meta_is_quarantined_and_does_not_stop_the_pull(): void
    {
        $this->publicSide();
        $this->pairAll();
        $bad = $this->forge(['files' => [['name' => ['x'], 'mime' => 'image/png', 'size' => 1, 'sha256' => ['y']]]]);
        $bad['attachments'] = json_encode([['c' => 'AAAA']]);
        $this->insertPub($bad);
        $worse = $this->forge(['files' => [['name' => 'a.png', 'mime' => 'image/png', 'size' => 1, 'sha256' => str_repeat('a', 64)]]]);
        $worse['attachments'] = json_encode(['not-an-array']);
        $this->insertPub($worse);
        $this->insertPub($this->forge());
        $this->as('internal');
        $r = $this->puller()->run();
        $this->assertArrayNotHasKey('error', $r, 'a poison envelope must not abort the run');
        $this->assertSame(2, $r['quarantined']);
        $this->assertSame(1, $r['handled'], 'the envelope behind them is still handled');
        $this->assertSame(0, $this->pubRows(), 'all three were acknowledged');
        $this->assertSame(2, DB::table('saan_inbox_quarantine')->count());
    }

    public function test_a_public_side_that_trickles_bytes_cannot_hold_a_pull_open_and_a_lost_lock_stops_the_run(): void
    {
        // a peer that sends one byte at a time: every read returns at once with a single byte (like fread on a socket), the stream never ends
        $slow = new class implements \Psr\Http\Message\StreamInterface {
            public function __toString(): string { return ''; }
            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return null; }
            public function tell(): int { return 0; }
            public function eof(): bool { return false; }
            public function isSeekable(): bool { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void {}
            public function rewind(): void {}
            public function isWritable(): bool { return false; }
            public function write(string $string): int { return 0; }
            public function isReadable(): bool { return true; }
            public function read(int $length): string { usleep(60000); return 'a'; }
            public function getContents(): string { return ''; }
            public function getMetadata(?string $key = null) { return null; }
        };
        $t0 = microtime(true);
        try { Puller::readCapped($slow, 10000000, 0.3); $this->fail('no deadline'); } catch (\RuntimeException $e) { $this->assertStringContainsString('ช้าเกิน', $e->getMessage()); }
        $this->assertLessThan(5, microtime(true) - $t0);

        $this->publicSide();
        $this->pairAll();
        $this->as('internal');
        $calls = 0;
        $p = new Puller(function () use (&$calls) {
            $calls++;
            \Illuminate\Support\Facades\Cache::lock('saan:inbox-pull')->forceRelease(); // the 120 s lock expired while this page was slow
            return [200, ['ok' => true, 'envelopes' => [], 'has_more' => true, 'current_kid' => null, 'waiting' => 5]];
        });
        $r = $p->run();
        $this->assertSame(1, $calls, 'no second page is started next to a fresh run');
        $this->assertStringContainsString('ล็อก', (string) $r['error']);
    }

    public function test_text_from_the_public_side_cannot_forge_a_log_line(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('internal');
        $r = $this->puller(fn () => [500, ['error' => "bad\nFORGED [ERR] line"]])->run();
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', (string) $r['error']);
        $this->assertStringNotContainsString('FORGED', (string) $r['error'], 'only a [a-z_] code is passed on');
        $this->assertSame('bad_code', \App\Support\Saan\Publish\Wire::code('bad_code'));
        $this->assertSame('?', \App\Support\Saan\Publish\Wire::code("a\nb"));
        $this->assertSame('a b', \App\Support\Saan\Publish\Wire::safe("a\r\nb"));
        $this->assertSame(5, mb_strlen(\App\Support\Saan\Publish\Wire::safe(str_repeat('ก', 50), 5)));
    }

    public function test_a_correctly_signed_pull_is_never_answered_429_and_the_push_and_pull_routes_have_no_address_throttle(): void
    {
        $this->publicSide();
        $this->pairAll();
        $this->as('public', 'pubdb');
        for ($i = 0; $i < 40; $i++) \Illuminate\Support\Facades\RateLimiter::hit('saan-inbox-auth:127.0.0.1', 600); // somebody behind the same address has been guessing
        [, , , , $h] = $this->signed('GET', '/_saan/inbox', ['after' => 0]);
        $this->withHeaders($h)->get('/_saan/inbox?after=0')->assertOk();
        $this->get('/_saan/inbox')->assertStatus(429); // an unsigned one from that address is slowed down
        foreach (app('router')->getRoutes() as $route) {
            if (!in_array($route->uri(), ['_saan/push', '_saan/inbox', '_saan/inbox/ack'], true)) continue;
            foreach ($route->gatherMiddleware() as $m) $this->assertFalse(is_string($m) && str_starts_with($m, 'throttle:'), $route->uri().' must not carry a per-address throttle');
        }
    }

    public function test_envelope_sourced_contacts_have_internal_ceilings_that_leave_the_envelope_waiting(): void
    {
        $this->as('internal');
        config(['saan.inbox.contact_per_minute' => 3]);
        $h = new \App\Support\Saan\Inbox\ContactHandler();
        $p = fn (int $i) => ['name' => 'สมชาย', 'email' => 'flood@example.test', 'subject' => 'เรื่องที่ '.$i, 'message' => 'ข้อความทดสอบยาวพอสมควร '.$i];
        for ($i = 1; $i <= 3; $i++) $h->handle($p($i), [], ['envelope' => 'x']);
        try { $h->handle($p(9), [], ['envelope' => 'x']); $this->fail('ceiling'); } catch (\RuntimeException $e) { $this->assertStringContainsString('flood_ceiling', $e->getMessage()); }
        $this->assertSame(3, DB::table('cms_messages')->where('email', 'flood@example.test')->count());
        $this->assertSame(1, State::count(null, 'inbox.flood'));
        $this->assertFalse(collect(SplitHealth::checks())->keyBy(0)['split_inbox_flood'][2], 'the doctor shows an inbox flood');
        $h->handle($p(10), [], []); // an in-process submit (not an envelope) is not limited here
        $this->assertSame(4, DB::table('cms_messages')->where('email', 'flood@example.test')->count());
    }

    public function test_the_setup_page_lock_is_per_network_and_pair_guess_is_only_a_signal(): void
    {
        $this->as('internal');
        for ($i = 0; $i < 60; $i++) Pairing::redeem('BBBB-BBBB-BBBB-BBBB-BBBB-BBBB-BBBB-BBBB', self::PUSH, '10.9.'.intdiv($i, 200).'.'.($i % 200 + 1));
        $this->assertSame(60, State::count(null, 'pair.guess'));
        $this->assertFalse(collect(SplitHealth::checks())->keyBy(0)['split_pair_guess'][2], 'shown by the doctor');
        $c = Pairing::issue(1, null, self::PUSH);
        $this->assertSame(200, Pairing::redeem($c['code'], self::PUSH, '10.10.0.1')[0], 'nobody can lock pairing for everybody');
    }

    public function test_the_doctor_warns_about_cloudflare_without_trusted_proxies(): void
    {
        $this->publicSide();
        $this->as('public', 'pubdb');
        config(['saan.trusted_proxies' => '']);
        \Illuminate\Support\Facades\Cache::forget('saan:cf-untrusted');
        $this->assertTrue(collect(SplitHealth::checks())->keyBy(0)['split_proxy_trust'][2]);
        $this->withHeaders(['CF-Connecting-IP' => '203.0.113.5'])->get('/portal');
        $this->assertFalse(collect(SplitHealth::checks())->keyBy(0)['split_proxy_trust'][2]);
    }
}