<?php

namespace App\Support\Saan\Publish;

use App\Support\Saan\Edition;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Internal edition publisher (S3): builds batches from the declared projection, queues them in pub_outbox, signs and delivers them in order.
 * In the combined edition there is nothing to push (same database) and every entry point is a no-op.
 *
 * Queue order is strictly the outbox id: all upserts in dependency order (parents first), then all deletes in reverse order, so foreign
 * keys on the public side hold. A failed batch blocks the ones behind it and is retried with backoff; a gap reported by the
 * receiver turns into a full rebuild.
 */
class Publisher
{
    /** @var null|callable(string $body, array $headers): array{0:int,1:array} */
    private $transport;
    private Builder $builder;
    /** A batch that failed this many times is reported as stuck and its stream is rebuilt from scratch (at most once per hour). */
    public const STUCK_AFTER = 5;
    private const LOCK = 'saan:publish';
    private ?Lock $lock = null;
    /** @var array<string,true> files the receiver confirmed during this run (not asked about again, not re-uploaded) */
    private array $okFiles = [];

    public function __construct(?Builder $builder = null, ?callable $transport = null)
    {
        $this->builder = $builder ?? new Builder();
        $this->transport = $transport;
    }

    public function builder(): Builder
    {
        return $this->builder;
    }

    /** True where pushing makes sense: the internal edition only. */
    public static function active(): bool
    {
        return Edition::isInternal();
    }

    /** The configured push endpoint, or '' when it is empty or not acceptable: https only (http only for localhost during development). */
    public static function url(): string
    {
        return self::acceptable(self::rawUrl());
    }

    /** $u when it is an acceptable push URL (https, or http for localhost during development), otherwise ''. */
    public static function acceptable(string $u): string
    {
        $p = parse_url($u);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if ($scheme === 'https' && $host !== '') return $u;
        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return $u;
        return '';
    }

    public static function rawUrl(): string
    {
        $u = trim((string) config('saan.public_push_url', ''));
        if ($u !== '' || !self::active()) return $u;
        // S6: the address that the public side registered while pairing (the .env value, when set, wins)
        try { return trim((string) \App\Support\Saan\Settings::get('saan.public_push_url', '')); } catch (\Throwable) { return ''; }
    }

    /** Request end hook: a cheap synchronous attempt when something was marked changed during this request. */
    public static function afterRequest(): void
    {
        try {
            if (!self::active() || self::url() === '' || !Outbox::pendingMarkers()) return;
            (new self())->run(4); // answers at once with a note when the scheduler or another request holds the lock
        } catch (\Throwable) { /* the scheduler retries */ }
    }

    /**
     * Runs $fn while holding the single publish lock (scheduler, request-end hook and admin button must never build or deliver at the same time).
     * Re-entrant for the same instance. Returns null when the lock is held elsewhere.
     */
    private function withLock(callable $fn, int $waitSeconds = 0): mixed
    {
        if ($this->lock) return $fn();
        $lock = Cache::lock(self::LOCK, 300);
        try { $got = $waitSeconds > 0 ? $lock->block($waitSeconds) : $lock->get(); } catch (LockTimeoutException) { $got = false; }
        if (!$got) return null;
        $this->lock = $lock;
        try { return $fn(); } finally { $this->lock = null; $lock->release(); }
    }

    // ---------------------------------------------------------------- build

    /**
     * Turns markers, time-based streams and (when $all) every stream into queued batches. Returns stream => number of queued batches.
     * @return array<string,int>
     */
    public function reconcile(bool $all = false, bool $dryRun = false): array
    {
        if ($dryRun) return $this->reconcileNow($all, true);
        return $this->withLock(fn () => $this->reconcileNow($all, false)) ?? [];
    }

    /** @return array<string,int> */
    private function reconcileNow(bool $all, bool $dryRun): array
    {
        Outbox::forget();
        if (!$dryRun) $this->ensureCaps();
        $order = $this->builder->streams();
        // The markers are CLAIMED first (stamped sent_at in a short committed statement), then the diffs are built. A write that commits while the diff is
        // being built finds no unclaimed marker, so Outbox::touch inserts a new one and the next tick picks it up; the old code deleted the marker it had read
        // earlier and so lost that write until the hourly full run. A failure puts the claim back.
        $markerIds = DB::table('pub_outbox')->whereNull('payload')->whereNull('sent_at')->orderBy('id')->get(['id', 'stream']);
        $markers = $markerIds->pluck('id', 'stream')->all();
        $claimed = $markerIds->pluck('id')->all();
        if (!$dryRun && $claimed) DB::table('pub_outbox')->whereIn('id', $claimed)->whereNull('payload')->whereNull('sent_at')->update(['sent_at' => now(), 'last_error' => 'claimed']);
        try {
            return $this->reconcileClaimed($all, $dryRun, $order, $markers, $claimed);
        } catch (\Throwable $e) {
            if (!$dryRun && $claimed) DB::table('pub_outbox')->whereIn('id', $claimed)->whereNull('payload')->where('last_error', 'claimed')->update(['sent_at' => null, 'last_error' => null]);
            throw $e;
        }
    }

    /** @return array<string,int> */
    private function reconcileClaimed(bool $all, bool $dryRun, array $order, array $markers, array $claimed): array
    {
        $dirty = $all ? $order : array_values(array_unique(array_merge(array_keys($markers), $this->builder->timedStreams())));
        $dirty = array_values(array_intersect($order, $dirty));
        $diffs = [];
        foreach ($dirty as $s) {
            $d = $this->diff($s);
            if ($d['up'] || $d['del']) $diffs[$s] = $d;
        }
        $counts = [];
        foreach ($diffs as $s => $d) $counts[$s] = count($d['up']) + count($d['del']);
        if ($dryRun) return $counts;
        DB::transaction(function () use ($diffs, $claimed, $order) {
            foreach ($diffs as $s => $d) if ($d['up']) $this->queueRows($s, $d['up'], 'upsert');
            foreach (array_reverse($order) as $s) if (isset($diffs[$s]) && $diffs[$s]['del']) $this->queueDeletes($s, $diffs[$s]['del']);
            foreach ($diffs as $s => $d) $this->recordSent($s, array_intersect_key($d['hash'], $d['up']), $d['del']);
            if ($claimed) DB::table('pub_outbox')->whereIn('id', $claimed)->whereNull('payload')->delete();
        });
        return $counts;
    }

    /** @return array{up:array<string,array>,del:list<string>,hash:array<string,string>} */
    private function diff(string $stream): array
    {
        $cur = $this->builder->rows($stream);
        $hash = array_map([Wire::class, 'rowHash'], $cur);
        $sent = DB::table('pub_sent_rows')->where('stream', $stream)->pluck('row_hash', 'row_key')->all();
        $up = [];
        foreach ($cur as $k => $row) if (($sent[(string) $k] ?? null) !== $hash[$k]) $up[(string) $k] = $row;
        $del = array_values(array_map('strval', array_keys(array_diff_key($sent, $cur))));
        return ['up' => $up, 'del' => $del, 'hash' => $hash];
    }

    private function recordSent(string $stream, array $hash, array $deleted): void
    {
        if ($deleted) foreach (array_chunk($deleted, 500) as $c) DB::table('pub_sent_rows')->where('stream', $stream)->whereIn('row_key', $c)->delete();
        foreach (array_chunk($hash, 300, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $k => $h) $rows[] = ['stream' => $stream, 'row_key' => (string) $k, 'row_hash' => $h];
            DB::table('pub_sent_rows')->upsert($rows, ['stream', 'row_key'], ['row_hash']);
        }
    }

    /** @return list<list<array>> rows split so that each batch stays below the configured size */
    private function chunks(array $rows): array
    {
        $max = max(64, (int) config('saan.push.max_batch_kb', 900)) * 1024;
        $out = [];
        $cur = [];
        $size = 0;
        foreach ($rows as $row) {
            $len = strlen(Wire::json($row));
            if ($cur && $size + $len > $max) { $out[] = $cur; $cur = []; $size = 0; }
            $cur[] = $row;
            $size += $len;
        }
        if ($cur) $out[] = $cur;
        return $out;
    }

    private function nextSeq(string $stream): int
    {
        // increment in the database (the row stays locked until the surrounding transaction ends) and read the value back from it:
        // two writers can never both read N and both write N+1. The unique (stream, seq) index on pub_outbox is the last line of defence.
        $touched = DB::table('pub_sync_state')->where('stream', $stream)->update(['last_seq' => DB::raw('last_seq + 1')]);
        if ($touched === 0) {
            try { DB::table('pub_sync_state')->insert(['stream' => $stream, 'last_seq' => 1]); }
            catch (\Illuminate\Database\UniqueConstraintViolationException) { DB::table('pub_sync_state')->where('stream', $stream)->update(['last_seq' => DB::raw('last_seq + 1')]); }
        }
        return (int) DB::table('pub_sync_state')->where('stream', $stream)->lockForUpdate()->value('last_seq');
    }

    private function enqueue(string $stream, string $op, array $payload): void
    {
        $payload = ['v' => 1, 'stream' => $stream, 'seq' => $this->nextSeq($stream), 'op' => $op, 'key' => Wire::keyColumn($stream)] + $payload;
        $json = Wire::json($payload);
        DB::table('pub_outbox')->insert(['stream' => $stream, 'seq' => $payload['seq'], 'op' => $op, 'payload' => $json, 'payload_hash' => hash('sha256', $json),
            'created_at' => now(), 'available_at' => null, 'attempts' => 0]);
    }

    private function queueRows(string $stream, array $rows, string $op, bool $begin = false): void
    {
        $chunks = $this->chunks(array_values($rows)) ?: [[]];
        foreach ($chunks as $i => $chunk) {
            $extra = $op === 'full' ? ['begin' => $begin && $i === 0, 'final' => false] : [];
            $this->enqueue($stream, $op, $extra + ['rows' => $chunk, 'deletes' => [], 'files' => $this->builder->filesFor($stream, $chunk)]);
        }
    }

    private function queueDeletes(string $stream, array $keys): void
    {
        foreach (array_chunk($keys, 2000) as $c) $this->enqueue($stream, 'delete', ['rows' => [], 'deletes' => $c, 'files' => []]);
    }

    /** Rebuilds everything: used after a breach and when the receiver is new. Pending batches are dropped (superseded). */
    public function full(bool $dryRun = false): array
    {
        if ($dryRun) return $this->fullStreams($this->builder->streams(), true);
        return $this->withLock(fn () => $this->fullStreams($this->builder->streams(), false), 5)
            ?? throw new RuntimeException('กำลังมีการส่งข้อมูลอยู่ ลองใหม่อีกครั้งในอีกสักครู่');
    }

    /** The admin button: rebuild everything and try to deliver, in one lock so the scheduler cannot interleave. @return array{queued:int,sent:int,failed:int,error?:string} */
    public function fullAndDeliver(int $budgetSeconds = 20): array
    {
        return $this->withLock(function () use ($budgetSeconds) {
            $n = array_sum($this->fullStreams($this->builder->streams(), false));
            return ['queued' => $n] + $this->deliver($budgetSeconds);
        }, 5) ?? throw new RuntimeException('กำลังมีการส่งข้อมูลอยู่ ลองใหม่อีกครั้งในอีกสักครู่');
    }

    /**
     * Full rebuild of the given streams (all of them, or one stuck stream). Only those streams' unsent batches are superseded.
     * @param list<string> $streams any subset of $this->builder->streams()
     * @return array<string,int> stream => rows
     */
    private function fullStreams(array $streams, bool $dryRun): array
    {
        Outbox::forget();
        if (!$dryRun) $this->ensureCaps();
        $order = array_values(array_intersect($this->builder->streams(), $streams));
        $all = [];
        foreach ($order as $s) { $rows = $this->builder->rows($s); $all[$s] = ['rows' => $rows, 'hash' => array_map([Wire::class, 'rowHash'], $rows)]; }
        $counts = array_map(fn ($a) => count($a['rows']), $all);
        if ($dryRun) return $counts;
        DB::transaction(function () use ($order, $all) {
            DB::table('pub_outbox')->whereIn('stream', $order)->whereNull('sent_at')->update(['sent_at' => now(), 'last_error' => 'superseded by full']);
            DB::table('pub_sent_rows')->whereIn('stream', $order)->delete();
            foreach ($order as $s) $this->queueRows($s, $all[$s]['rows'], 'full', true);
            foreach (array_reverse($order) as $s) $this->enqueue($s, 'full', ['begin' => false, 'final' => true, 'rows' => [], 'deletes' => [], 'files' => [], 'keys' => array_map('strval', array_keys($all[$s]['rows']))]);
            foreach ($order as $s) $this->recordSent($s, $all[$s]['hash'], []);
        });
        return $counts;
    }

    /** Rebuilds one stream after a gap or repeated failures. At most once an hour per stream: a faulty receiver must not make us rebuild every minute. */
    private function rebuildStream(string $stream, string $why): bool
    {
        $k = 'saan:publish:rebuild:'.$stream;
        if (cache()->get($k)) return false;
        cache()->put($k, time(), 3600);
        Log::warning('[WARN] saan publish: rebuilding stream '.$stream.' ('.$why.')');
        $this->fullStreams([$stream], false);
        return true;
    }

    // ---------------------------------------------------------------- deliver

    /** One scheduler tick: queue changes, then deliver until the queue is empty, only blocked streams remain or the time budget is used. */
    public function run(int $budgetSeconds = 50): array
    {
        if (!self::active()) return ['sent' => 0, 'failed' => 0, 'note' => 'ไม่ต้องส่ง'];
        return $this->withLock(function () use ($budgetSeconds) {
            $last = (int) (cache()->get('saan:publish:reconcile') ?? 0);
            $all = time() - $last > 3600;
            // a module switched off in the .env (SAAN_DISABLE_MODULES) writes no database row, so nothing would mark its streams: the set of active modules is compared every minute
            $fp = self::moduleFingerprint();
            if (!$all && (string) cache()->get('saan:publish:modules') !== $fp) Outbox::touch(Outbox::moduleStreams(), false);
            $this->reconcileNow($all, false);
            if ($all) cache()->put('saan:publish:reconcile', time(), 7200);
            cache()->put('saan:publish:modules', $fp, 2592000);
            $r = $this->deliver($budgetSeconds);
            DB::table('pub_outbox')->whereNotNull('sent_at')->where('sent_at', '<', now()->subDays(7))->delete();
            return $r;
        }) ?? ['sent' => 0, 'failed' => 0, 'note' => 'มีอีกกระบวนการกำลังส่งข้อมูลอยู่'];
    }

    /** Fingerprint of the modules that are active on this side (what the public edition may serve changes with it). */
    public static function moduleFingerprint(): string
    {
        $ids = [];
        foreach (app(\App\Support\Modules\ModuleRegistry::class)->all() as $id => $m) if (!empty($m['active'])) $ids[] = (string) $id;
        sort($ids);
        return sha1(implode(',', $ids));
    }

    public function deliver(int $budgetSeconds = 50): array
    {
        return $this->withLock(fn () => $this->deliverNow($budgetSeconds)) ?? ['sent' => 0, 'failed' => 0, 'note' => 'มีอีกกระบวนการกำลังส่งข้อมูลอยู่'];
    }

    private function deliverNow(int $budgetSeconds): array
    {
        $start = time();
        $sent = 0;
        $failed = 0;
        $firstError = null;
        $this->okFiles = [];
        while (time() - $start < max(1, $budgetSeconds)) {
            $item = $this->nextItem();
            if (!$item) break;
            try {
                $this->send($item);
                DB::table('pub_outbox')->where('id', $item->id)->update(['sent_at' => now(), 'last_error' => null]);
                $sent++;
            } catch (RateLimited $e) {
                // the receiver asks us to slow down: wait as told, this is not a failure of the batch and does not count as an attempt
                DB::table('pub_outbox')->where('id', $item->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 500),
                    'available_at' => now()->addSeconds(max(1, min(300, $e->retryAfter)))->toDateTimeString()]);
                break;
            } catch (\Throwable $e) {
                $n = (int) $item->attempts + 1;
                DB::table('pub_outbox')->where('id', $item->id)->update(['attempts' => $n, 'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'available_at' => now()->addSeconds(min(3600, 30 * (2 ** min($n, 7))))->toDateTimeString()]);
                $failed++;
                $firstError ??= $e->getMessage();
                if (str_contains($e->getMessage(), self::GAP_MARK)) $this->rebuildStream($item->stream, 'ลำดับไม่ต่อเนื่อง');
                elseif ($n >= self::STUCK_AFTER) $this->rebuildStream($item->stream, 'ส่งไม่สำเร็จ '.$n.' ครั้ง');
            }
        }
        return ['sent' => $sent, 'failed' => $failed] + ($firstError !== null ? ['error' => $firstError] : []);
    }

    private const GAP_MARK = 'gap';

    /**
     * Next batch to send. Order is the outbox id, but each stream is its own line: a stream whose head batch is waiting for a retry holds
     * back only the streams that depend on it through foreign keys (children wait for a parent's upserts, a parent's deletes wait for the
     * children's deletes), so one stuck media or profile batch cannot hold back takedowns in unrelated streams.
     */
    private function nextItem(): ?object
    {
        $heads = [];
        $rows = DB::table('pub_outbox')->whereNotNull('payload')->whereNull('sent_at')->orderBy('id')->get(['id', 'stream', 'op', 'attempts', 'available_at']);
        foreach ($rows as $r) $heads[$r->stream] ??= $r;
        if (!$heads) return null;
        $now = now()->toDateTimeString();
        $held = [];
        foreach ($heads as $stream => $h) {
            $hold = $h->available_at && $h->available_at > $now;
            if (!$hold) {
                $mustWaitFor = match ($h->op) {
                    'delete' => $this->builder->descendants($stream),
                    'full' => array_merge($this->builder->ancestors($stream), $this->builder->descendants($stream)),
                    default => $this->builder->ancestors($stream),
                };
                $hold = (bool) array_intersect($mustWaitFor, array_keys($held));
            }
            if ($hold) { $held[$stream] = true; continue; }
            return DB::table('pub_outbox')->where('id', $h->id)->first();
        }
        return null;
    }

    private function send(object $item): void
    {
        $payload = json_decode((string) $item->payload, true, 512, JSON_THROW_ON_ERROR);
        $files = (array) ($payload['files'] ?? []);
        if ($files) $this->sendFiles($files); // never fails the batch: a file that cannot go is skipped, the rows still must
        [$status, $reply] = $this->post('batch', (string) $item->payload, (string) $item->payload_hash);
        if ($status === 200) return;
        if ($status === 409 && ($reply['error'] ?? '') === 'replay' && ($reply['last_hash'] ?? null) === $item->payload_hash) return; // the reply of an earlier try was lost
        $gap = $status === 409 && ($reply['error'] ?? '') === 'gap';
        throw new RuntimeException('ฝั่งรับตอบ '.$status.' '.Wire::code($reply['error'] ?? '').' '.Wire::safe($reply['message'] ?? '', 120).($gap ? ' ('.self::GAP_MARK.')' : ''));
    }

    private function skip(string $why): void
    {
        $this->builder->skipped[] = $why;
        try {
            $log = (array) cache()->get('saan:publish:skipped', []);
            $log[] = date('Y-m-d H:i:s').' '.$why;
            cache()->put('saan:publish:skipped', array_slice($log, -20), 604800);
        } catch (\Throwable) { /* status page only */ }
    }

    /**
     * Uploads the files a batch refers to. The list was frozen when the batch was queued, so every file is checked again right before it goes:
     * a file that is gone, replaced or refused by the receiver (sha256, size, MIME) is skipped and reported; a later diff carries the new state.
     */
    private function sendFiles(array $files): void
    {
        $disk = Storage::disk('local');
        $ok = [];
        foreach ($files as $f) {
            if (!is_array($f) || !is_string($f['path'] ?? null)) continue;
            if (isset($this->okFiles[$f['path'].'|'.$f['sha256']])) continue;
            $abs = $disk->path($f['path']);
            if (!is_file($abs) || (int) filesize($abs) !== (int) $f['size']) { $this->skip($f['path'].' (ไฟล์หายหรือถูกแทนที่ก่อนส่ง)'); continue; }
            $ok[$f['path']] = $f;
        }
        if (!$ok) return;
        $meta = Wire::json(['files' => array_values($ok)]);
        [$status, $reply] = $this->post('files', $meta, hash('sha256', $meta));
        if ($status === 422) { foreach ($ok as $f) $this->skip($f['path'].' (ฝั่งรับไม่รับรายการไฟล์)'); return; }
        if ($status !== 200) throw new RuntimeException('ตรวจไฟล์ไม่สำเร็จ '.$status.' '.Wire::code($reply['error'] ?? ''));
        $rejected = array_flip((array) ($reply['rejected'] ?? []));
        $need = array_flip((array) ($reply['need'] ?? []));
        $chunk = max(64, (int) config('saan.push.chunk_kb', 512)) * 1024;
        foreach ($ok as $path => $f) {
            if (isset($rejected[$path])) { $this->skip($path.' (ฝั่งรับไม่รับไฟล์นี้)'); continue; }
            if (!isset($need[$path])) { $this->okFiles[$path.'|'.$f['sha256']] = true; continue; }
            $abs = $disk->path($path);
            if (!hash_equals($f['sha256'], (string) @hash_file('sha256', $abs))) { $this->skip($path.' (เนื้อไฟล์เปลี่ยนก่อนส่ง)'); continue; }
            $h = @fopen($abs, 'rb');
            if (!$h) { $this->skip($path.' (เปิดไฟล์ไม่ได้)'); continue; }
            $refused = null;
            try {
                $offset = 0;
                do {
                    fseek($h, $offset);
                    $data = (string) fread($h, $chunk);
                    $body = Wire::json(['path' => $path, 'sha256' => $f['sha256'], 'size' => $f['size'], 'mime' => $f['mime'], 'offset' => $offset, 'data' => base64_encode($data)]);
                    [$st, $rp] = $this->post('chunk', $body, hash('sha256', $body));
                    if ($st === 422 && in_array($rp['error'] ?? '', ['bad_chunk', 'bad_file', 'mime', 'sha256', 'size'], true)) { $refused = (string) ($rp['error'] ?? ''); break; }
                    if ($st !== 200) throw new RuntimeException('ส่งไฟล์ไม่สำเร็จ '.$path.' '.$st.' '.Wire::code($rp['error'] ?? ''));
                    $offset += strlen($data);
                } while ($offset < $f['size'] && $data !== '');
            } finally { fclose($h); }
            if ($refused !== null) $this->skip($path.' (ฝั่งรับปฏิเสธ: '.$refused.')');
            else $this->okFiles[$path.'|'.$f['sha256']] = true;
        }
    }

    /**
     * Asks the public side what it supports (kind 'caps'), only while a live full-name window exists and only when nothing is cached. An older
     * receiver answers 400 bad_kind, an unreachable one throws: both leave the caps empty for a minute, so full-name cells are not sent (fail closed).
     */
    private function ensureCaps(): void
    {
        if (cache()->has(Builder::CAPS_KEY) || !$this->builder->needsPeerCaps()) return;
        $caps = [];
        try {
            [$st, $rp] = $this->post('caps', '[]', hash('sha256', '[]'));
            if ($st === 200 && is_array($rp['caps'] ?? null)) $caps = $rp['caps'];
        } catch (\Throwable) { /* unknown peer = no capabilities */ }
        Builder::rememberPeerCaps($caps, $caps ? 600 : 60);
    }

    /** @return array{0:int,1:array} */
    private function post(string $kind, string $payloadJson, string $payloadHash): array
    {
        $ts = time();
        $body = '{"ts":'.$ts.',"kind":"'.$kind.'","payload_hash":"'.$payloadHash.'","payload":'.$payloadJson.'}';
        $headers = ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Signature' => Keys::sign($ts, $body)];
        if ($this->transport) {
            [$st, $rp] = ($this->transport)($body, $headers);
            if ($st === 429) throw new RateLimited((int) ($rp['retry_after'] ?? 60));
            return [$st, $rp];
        }
        $url = self::url();
        if ($url === '') throw new RuntimeException(self::rawUrl() === '' ? 'ยังไม่ได้ตั้ง SAAN_PUBLIC_PUSH_URL' : 'SAAN_PUBLIC_PUSH_URL ต้องขึ้นต้นด้วย https://');
        // never follow a redirect: a compromised public site must not be able to aim this server at an address on the school network
        // the reply is read through a size cap and a wall-clock deadline (stream=true turns Guzzle's total timeout off, so Wire::readCapped keeps its own)
        $r = Http::withHeaders($headers)->withBody($body, 'application/json')->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 10])->acceptJson()->connectTimeout(5)->timeout(30)->post($url);
        $st = $r->status();
        if ($st === 429) throw new RateLimited((int) $r->header('Retry-After') ?: 60);
        if ($st >= 300 && $st < 400) throw new RuntimeException('ฝั่งรับตอบเปลี่ยนเส้นทาง ('.$st.') ซึ่งไม่อนุญาต ตรวจ SAAN_PUBLIC_PUSH_URL');
        return [$st, self::decodeReply($r)];
    }

    /** Largest reply accepted from the public side (replies are tiny; erify carries one checksum per stream). */
    public const MAX_REPLY = 262144;

    /** Reply of the public side: capped, depth limited, and an array whatever the peer sent. */
    public static function decodeReply(\Illuminate\Http\Client\Response $r): array
    {
        $j = json_decode(Wire::readCapped($r->toPsrResponse()->getBody(), self::MAX_REPLY, 30.0), true, 16);
        return is_array($j) ? $j : [];
    }

    // ---------------------------------------------------------------- verify and status

    /**
     * Compares per-table checksums of the current internal projection with the receiver's.
     * @return array{ok:bool,streams:array<string,array{match:bool,local:array,remote:?array}>,pending:int}
     */
    public function verify(): array
    {
        [$status, $reply] = $this->post('verify', '[]', hash('sha256', '[]'));
        if ($status !== 200) throw new RuntimeException('ฝั่งรับตอบ '.$status.' '.Wire::code($reply['error'] ?? ''));
        $remote = (array) ($reply['checksums'] ?? []);
        if (is_array($reply['caps'] ?? null)) Builder::rememberPeerCaps($reply['caps']);
        $out = [];
        $ok = true;
        foreach ($this->builder->checksums() as $s => $local) {
            $r = $remote[$s] ?? null;
            $match = is_array($r) && ($r['sha256'] ?? null) === $local['sha256'] && (int) ($r['count'] ?? -1) === $local['count'];
            $ok = $ok && $match;
            $out[$s] = ['match' => $match, 'local' => $local, 'remote' => $r];
        }
        return ['ok' => $ok, 'streams' => $out, 'pending' => (int) DB::table('pub_outbox')->whereNotNull('payload')->whereNull('sent_at')->count()];
    }

    public static function status(): array
    {
        $q = DB::table('pub_outbox');
        $errors = (clone $q)->whereNull('sent_at')->whereNotNull('last_error')->orderByDesc('id')->limit(10)->get(['id', 'stream', 'seq', 'attempts', 'last_error', 'available_at'])->all();
        return [
            'edition' => Edition::current(), 'configured' => self::url() !== '',
            'last_sync' => (clone $q)->whereNotNull('sent_at')->whereNull('last_error')->max('sent_at'),
            'queue' => (int) (clone $q)->whereNotNull('payload')->whereNull('sent_at')->count(),
            'markers' => (int) (clone $q)->whereNull('payload')->whereNull('sent_at')->count(),
            'errors' => $errors,
            // batches that failed STUCK_AFTER times or more (their stream is rebuilt automatically, at most once an hour) and files that were skipped
            'stuck' => (int) (clone $q)->whereNull('sent_at')->whereNotNull('payload')->where('attempts', '>=', self::STUCK_AFTER)->count(),
            'skipped' => (array) cache()->get('saan:publish:skipped', []),
        ];
    }
}
