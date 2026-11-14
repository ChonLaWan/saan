<?php

namespace App\Support\Saan\Inbox;

use App\Support\Saan\Edition;
use App\Support\Saan\Inbox;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Publish\Wire;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Internal edition inbox puller (S5), run every minute by saan:inbox-pull. Asks the public edition for sealed envelopes with a signed GET,
 * opens each with the inbox secret of its key id, validates it, dispatches it to the handler of its kind and acknowledges it (the public side
 * then deletes it). The internal side always calls out; the public side knows nothing about it. Nothing that the public side sends is trusted:
 * the reply size, every size inside the envelope and the idempotency key (the nonce INSIDE the sealed envelope) are checked here.
 *
 * Two passes per run: new envelopes after a persistent cursor (inbox.cursor), then one page of envelopes that were left behind (a handler that
 * failed, a lost ack), walking from a second cursor (inbox.retry). A pile of failing envelopes therefore cannot starve new ones.
 *
 * Per envelope:
 *   the nonce inside it is already in saan_inbox_done          -> acknowledged without running the handler again (a different outer hash means a replay: counted)
 *   cannot be opened / malformed / unknown kind / refused      -> saved in saan_inbox_quarantine with the reason (payload cut at 64 KB), acknowledged
 *   handler failed for another reason (database, mail)         -> NOT acknowledged, tried again by the retry pass
 *   no secret for its key id                                   -> NOT acknowledged (reported), the public side purges it after 7 days
 * A retired inbox secret is deleted only after the public side has reported the new key for at least an hour and nothing is left waiting.
 */
class Puller
{
    private const LOCK = 'saan:inbox-pull';
    private const MAX_PAGES = 20;
    private const QUARANTINE_PER_RUN = 100;
    private const QUARANTINE_PER_HOUR = 500;
    private const PAYLOAD_KEEP = 65536;
    private const KEY_GRACE = 3600;
    /** @var null|callable(string $method, string $path, array $query, string $body, array $headers): array{0:int,1:array} */
    private $transport;
    /** @var array<string,int> kind => quarantine places left in this run (one kind cannot use up the others' share) */
    private array $quarantineBudget = [];
    /** Longest a run keeps going (the lock lives 120 s) and the lock it holds: checked before every page. */
    private const RUN_SECONDS = 90;
    private ?\Illuminate\Contracts\Cache\Lock $lock = null;
    private int $deadline = 0;

    public function __construct(?callable $transport = null, private ?int $now = null)
    {
        $this->transport = $transport;
    }

    private function now(): int
    {
        return $this->now ?? time();
    }

    public static function active(): bool
    {
        return Edition::isInternal();
    }

    /** Inbox endpoint derived from the push URL (https://public/_saan/push -> https://public/_saan/inbox), or '' when none is acceptable. */
    public static function baseUrl(): string
    {
        $u = Publisher::url();
        return $u === '' ? '' : (string) preg_replace('~/push/?$~', '/inbox', $u);
    }

    /** Largest reply read from the public side: a page of PAGE_BYTES, or one envelope at the configured maximum, in base64 and JSON. */
    public static function maxBody(): int
    {
        $one = (int) ceil(((int) config('saan.inbox.max_payload_kb', 256) * 1024 + (int) config('saan.inbox.max_total_kb', 20480) * 1024 + 16384) * 4 / 3 * 1.2);
        return max(PullServer::PAGE_BYTES * 2, $one) + 1048576;
    }

    /** @return array{pulled:int,handled:int,quarantined:int,failed:int,note?:string,error?:string} */
    public function run(): array
    {
        if (!self::active()) return ['pulled' => 0, 'handled' => 0, 'quarantined' => 0, 'failed' => 0, 'note' => 'ไม่ต้องดึง'];
        if (!$this->transport && self::baseUrl() === '') return ['pulled' => 0, 'handled' => 0, 'quarantined' => 0, 'failed' => 0, 'note' => 'ยังไม่ได้ตั้งที่อยู่หน้าบ้าน'];
        $lock = Cache::lock(self::LOCK, 120);
        if (!$lock->get()) return ['pulled' => 0, 'handled' => 0, 'quarantined' => 0, 'failed' => 0, 'note' => 'มีอีกกระบวนการกำลังดึงอยู่'];
        $this->lock = $lock;
        try { return $this->pull(); } finally { $this->lock = null; $lock->release(); }
    }

    private function pull(): array
    {
        $r = ['pulled' => 0, 'handled' => 0, 'quarantined' => 0, 'failed' => 0];
        $this->quarantineBudget = [];
        $this->deadline = time() + self::RUN_SECONDS;
        $seen = [];
        $current = null;
        $left = null;
        try {
            // pass 1: everything after the cursor
            $cursor = State::count(null, 'inbox.cursor');
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                [$reply, $left] = $this->page($cursor, $r, $seen);
                $current = $reply['current_kid'] ?? $current;
                foreach ($seen as $id => $_) $cursor = max($cursor, $id);
                State::mark(null, 'inbox.cursor', $this->now(), 0, $cursor);
                if (empty($reply['has_more'])) break;
            }
            // pass 2: one page of envelopes left behind earlier (a failed handler, a lost ack), fair round robin by id
            $retry = State::count(null, 'inbox.retry');
            for ($try = 0; $try < 2; $try++) {
                [$reply, $left, $maxId, $count] = $this->retryPage($retry, $r, $seen);
                $current = $reply['current_kid'] ?? $current;
                if ($count === 0 && $retry > 0) { $retry = 0; continue; } // reached the end: start again from the oldest
                $retry = !empty($reply['has_more']) ? $maxId : 0;
                break;
            }
            State::mark(null, 'inbox.retry', $this->now(), 0, $retry);
            State::mark(null, 'inbox.pull', $this->now());
            $this->watchKeys($current);
            if ($r['failed'] === 0 && $left === 0) $this->dropDrainedKeys($current);
            $this->prune();
        } catch (\Throwable $e) {
            $r['error'] = Wire::safe($e->getMessage(), 500);
            $msg = Wire::safe($e->getMessage(), 500);
            Log::warning('[WARN] saan inbox pull: '.$msg);
            State::mark(null, 'inbox.error', $this->now(), 1);
        }
        return $r;
    }

    /** One list call after $after, handles every envelope in it and acknowledges. @return array{0:array,1:int} the reply and how many are still waiting */
    private function page(int $after, array &$r, array &$seen, bool $skipSeen = false): array
    {
        // the lock expires after 120 s: a run that lost it (or ran past its own budget) must not start another page next to a fresh run
        if ($this->lock && method_exists($this->lock, 'isOwnedByCurrentProcess') && !$this->lock->isOwnedByCurrentProcess()) throw new RuntimeException('ล็อกการดึงหมดอายุกลางรอบ หยุดรอบนี้');
        if ($this->deadline > 0 && time() > $this->deadline) throw new RuntimeException('รอบการดึงใช้เวลาเกินกำหนด หยุดรอบนี้ (รอบถัดไปทำต่อ)');
        [$st, $reply] = $this->call('GET', PullServer::LIST, ['after' => $after, 'limit' => PullServer::PAGE], '');
        if ($st !== 200) throw new RuntimeException('ฝั่งหน้าบ้านตอบ '.$st.' '.Wire::code($reply['error'] ?? ''));
        $ack = [];
        foreach ((array) ($reply['envelopes'] ?? []) as $env) {
            if (!is_array($env) || !is_int($env['id'] ?? null)) continue;
            if ($skipSeen && isset($seen[$env['id']])) continue;
            $seen[$env['id']] = true;
            $r['pulled']++;
            $out = $this->one($env);
            if ($out === 'done') { $r['handled']++; $ack[] = $env['id']; }
            elseif ($out === 'quarantined') { $r['quarantined']++; $ack[] = $env['id']; }
            elseif ($out === 'skipped') $ack[] = $env['id'];
            else $r['failed']++;
        }
        if ($ack) {
            [$s2, $rp] = $this->call('POST', PullServer::ACK, [], json_encode(['ids' => $ack], JSON_THROW_ON_ERROR));
            if ($s2 !== 200) throw new RuntimeException('ack ไม่สำเร็จ: ฝั่งหน้าบ้านตอบ '.$s2.' '.Wire::code($rp['error'] ?? ''));
        }
        return [$reply, max(0, (int) ($reply['waiting'] ?? 0) - count($ack))];
    }

    /** @return array{0:array,1:int,2:int,3:int} reply, still waiting, highest id on the page, how many envelopes the page held */
    private function retryPage(int $after, array &$r, array &$seen): array
    {
        [$reply, $left] = $this->page($after, $r, $seen, true);
        $ids = array_map(fn ($e) => is_array($e) && is_int($e['id'] ?? null) ? $e['id'] : 0, (array) ($reply['envelopes'] ?? []));
        return [$reply, $left, $ids ? max($ids) : 0, count(array_filter($ids))];
    }

    /** The key id the public side reports: a kid that no internal key matches is the strongest sign of a breach this side can see. */
    private function watchKeys(?string $current): void
    {
        if ($current === null) return;
        $known = Keys::inboxSecrets();
        if (!isset($known[$current])) {
            State::mark(null, 'inbox.foreign_kid', $this->now(), 1);
            Log::warning('[WARN] saan inbox: the public side seals with an inbox key this system does not know');
            return;
        }
        $k = 'inbox.newkid.'.$current;
        if ($current === Keys::keyId(Keys::inboxPublic())) {
            if (State::get(null, $k) === null) State::mark(null, $k, $this->now()); // first time the public side reported the current key
        } else {
            DB::table('pub_sync_state')->where('stream', 'like', 'inbox.newkid.%')->delete(); // it went back to an old key: the clock starts again
        }
    }

    /** Retired inbox keys go when the public side has reported the current key for an hour and nothing at all is waiting on the public side. */
    private function dropDrainedKeys(?string $currentKid): void
    {
        if ($currentKid === null || $currentKid !== Keys::keyId(Keys::inboxPublic())) return; // the public side has not been paired with the new key yet
        $since = State::get(null, 'inbox.newkid.'.$currentKid)->applied_at ?? null;
        if ($since === null || $this->now() - (int) strtotime($since) < self::KEY_GRACE) return; // an envelope sealed with the old key may still be in flight
        foreach (Keys::retiredInboxKeys() as $kid) Keys::dropRetiredInboxKey($kid);
    }

    /** Once a day: idempotency rows older than 30 days (the public side keeps envelopes 7 days, the nonce check covers 8) and quarantine older than 90. */
    private function prune(): void
    {
        $last = State::get(null, 'inbox.prune')->applied_at ?? null;
        if ($last !== null && $this->now() - (int) strtotime($last) < 86400) return;
        DB::table('saan_inbox_done')->where('handled_at', '<', date('Y-m-d H:i:s', $this->now() - 30 * 86400))->delete();
        DB::table('saan_inbox_quarantine')->where('created_at', '<', date('Y-m-d H:i:s', $this->now() - 90 * 86400))->delete();
        State::mark(null, 'inbox.prune', $this->now());
    }

    /** @return 'done'|'quarantined'|'skipped'|'failed' */
    private function one(array $env): string
    {
        $hash = (string) ($env['dedupe_hash'] ?? '');
        $kind = (string) ($env['kind'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || !preg_match(Inbox::KIND, $kind) || !is_string($env['ciphertext'] ?? null) || !preg_match('/^[a-f0-9]{16}$/D', (string) ($env['key_id'] ?? ''))) {
            return $this->quarantine(null, null, null, 'envelope_malformed', null);
        }
        try {
            $opened = Inbox::open($env['ciphertext'], isset($env['attachments']) && $env['attachments'] ? json_encode($env['attachments']) : null, (string) $env['key_id']);
        } catch (InboxRejected $e) {
            if ($e->status === 409) { Log::warning('[WARN] saan inbox: '.$e->getMessage()); return 'failed'; } // no key for it: keep it, do not lose it
            if ($this->isDone($hash)) return 'skipped'; // already put in quarantine once (the ack was lost)
            return $this->quarantine($hash, null, $kind, $e->getMessage(), null);
        } catch (\Throwable) {
            // a poison envelope (array where text belongs, a warning turned into an exception) must not stop every later pull: it is parked like any other malformed one
            if ($this->isDone($hash)) return 'skipped';
            return $this->quarantine($hash, null, $kind, 'envelope_malformed', null);
        }
        $plain = $opened['env'];
        // The idempotency key is the nonce sealed INSIDE the envelope: the public side cannot choose it, so putting an old ciphertext back under a new outer hash does nothing.
        $inner = Inbox::innerHash($plain);
        if ($this->isDone($inner)) {
            if (!$this->isDone($hash)) { // same content, new wrapper: a replay (a lost ack keeps the outer hash)
                State::mark(null, 'inbox.replay', $this->now(), 1);
                Log::warning('[WARN] saan inbox: a replayed envelope was ignored');
                $this->done([$hash], $kind, 'replayed', null);
            }
            return 'skipped';
        }
        if ($plain['kind'] !== $kind) return $this->quarantine($hash, $inner, $kind, 'kind ในซองไม่ตรงกับหัวซอง', null);
        $handler = Handlers::for($kind);
        if ($handler === null) return $this->quarantine($hash, $inner, $kind, 'ไม่รู้จักชนิดข้อมูล '.$kind, $this->safeJson(Handlers::redact($kind, $plain['payload'])));
        try {
            $result = $handler($plain['payload'], $opened['files'], ['envelope' => $inner, 'received_at' => $env['received_at'] ?? null, 'sent_at' => $plain['ts']]);
        } catch (InboxRejected $e) {
            return $this->quarantine($hash, $inner, $kind, $e->getMessage(), $this->safeJson(Handlers::redact($kind, $plain['payload'])));
        } catch (ValidationException $e) {
            return $this->quarantine($hash, $inner, $kind, 'ข้อมูลไม่ผ่านการตรวจ: '.implode(' ', array_map(fn ($m) => implode(' ', (array) $m), $e->errors())), $this->safeJson(Handlers::redact($kind, $plain['payload'])));
        } catch (\Throwable $e) {
            Log::warning('[WARN] saan inbox handler '.$kind.' failed: '.get_class($e).' '.Wire::safe($e->getMessage(), 200));
            return 'failed';
        }
        $this->done([$hash, $inner], $kind, (string) ($result['status'] ?? 'ok'), isset($result['id']) ? (string) $result['id'] : (isset($result['response_id']) ? (string) $result['response_id'] : null));
        return 'done';
    }

    private function isDone(string $hash): bool
    {
        return DB::table('saan_inbox_done')->where('dedupe_hash', $hash)->exists();
    }

    /** @param list<string> $hashes outer hash and/or inner hash */
    private function done(array $hashes, string $kind, string $outcome, ?string $ref): void
    {
        foreach ($hashes as $h) {
            DB::table('saan_inbox_done')->insertOrIgnore(['dedupe_hash' => $h, 'kind' => mb_substr($kind, 0, 32), 'outcome' => mb_substr($outcome, 0, 40), 'handled_at' => date('Y-m-d H:i:s', $this->now()), 'ref' => $ref !== null ? mb_substr($ref, 0, 80) : null]);
        }
    }

    private function safeJson(array $payload): ?string
    {
        try { return mb_strcut(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0, self::PAYLOAD_KEEP); } catch (\Throwable) { return null; }
    }

    /**
     * The audit trail of an envelope that could not be dispatched. The reason is Thai text for the admin; the payload (cut at 64 KB) is kept so nothing is lost.
     * Bounded per run and per hour: past that the envelope stays on the public side ('failed') instead of filling this table.
     */
    private function quarantine(?string $hash, ?string $inner, ?string $kind, string $reason, ?string $payload): string
    {
        // the budget is split by kind (a malformed or unknown envelope has its own share): a flood of rejected survey answers cannot make every other kind stay on the public side
        $bucket = $kind !== null && preg_match(Inbox::KIND, $kind) ? $kind : '-';
        $limiter = 'saan-inbox-quarantine:'.$bucket;
        if (($this->quarantineBudget[$bucket] ?? self::QUARANTINE_PER_RUN) <= 0 || RateLimiter::tooManyAttempts($limiter, self::QUARANTINE_PER_HOUR)) {
            Log::warning('[WARN] saan inbox: quarantine limit reached for '.$bucket.', envelope left on the public side');
            return 'failed';
        }
        $this->quarantineBudget[$bucket] = ($this->quarantineBudget[$bucket] ?? self::QUARANTINE_PER_RUN) - 1;
        RateLimiter::hit($limiter, 3600);
        DB::table('saan_inbox_quarantine')->insert(['envelope_hash' => $hash, 'kind' => $kind !== null ? mb_substr($kind, 0, 40) : null, 'reason' => mb_substr($reason, 0, 190), 'payload' => $payload, 'created_at' => date('Y-m-d H:i:s', $this->now())]);
        $marks = array_values(array_filter([$hash, $inner]));
        if ($marks) $this->done($marks, $kind ?? '-', 'quarantined', null);
        Log::warning('[WARN] saan inbox: envelope quarantined ('.mb_substr($reason, 0, 100).')');
        return 'quarantined';
    }

    // ---------------------------------------------------------------- transport

    /** @return array{0:int,1:array} */
    private function call(string $method, string $path, array $query, string $body): array
    {
        $ts = $this->now();
        $nonce = bin2hex(random_bytes(16));
        $headers = ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Nonce' => $nonce, 'X-Saan-Signature' => Keys::pullSign($method, $path, $query, $ts, $nonce, $body)];
        if ($this->transport) return ($this->transport)($method, $path, $query, $body, $headers);
        $url = self::baseUrl();
        if ($url === '') throw new RuntimeException('ยังไม่ได้ตั้งที่อยู่หน้าบ้าน (SAAN_PUBLIC_PUSH_URL)');
        $url = preg_replace('~/_saan/inbox$~', '', $url).$path;
        // stream=true switches Guzzle's total timeout off, so the body is read with our own deadline (Wire::readCapped) and a short idle timeout
        $req = Http::withHeaders($headers)->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 10])->acceptJson()->connectTimeout(5)->timeout(30);
        $r = $method === 'GET' ? $req->get($url, $query) : $req->withBody($body, 'application/json')->post($url);
        if ($r->status() >= 300 && $r->status() < 400) throw new RuntimeException('ฝั่งหน้าบ้านตอบเปลี่ยนเส้นทาง ('.$r->status().') ซึ่งไม่อนุญาต');
        $json = json_decode(self::readCapped($r->toPsrResponse()->getBody(), self::maxBody()), true, 64);
        return [$r->status(), is_array($json) ? $json : []];
    }

    /** Reads at most $max bytes of a stream; more than that is an error (the public side is not trusted to keep a reply small). */
    public static function readCapped(\Psr\Http\Message\StreamInterface $body, int $max, float $seconds = 30.0): string
    {
        return Wire::readCapped($body, $max, $seconds);
    }
}
