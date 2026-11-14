<?php

namespace App\Support\Saan\Inbox;

use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Wire;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Public edition side of the pull channel (S5): GET /_saan/inbox?after=<id> lists sealed envelopes, POST /_saan/inbox/ack deletes the ones the
 * internal edition has stored. Every request is signed by the internal PULL key (Ed25519, peer-pull.pub here) over method, path, query,
 * timestamp, nonce and body hash; timestamp skew is limited and every nonce is accepted once (replay -> 401). The public side never learns
 * where the internal side lives; it only answers.
 *
 * Envelopes older than MAX_AGE_DAYS are deleted whether they were pulled or not (purge); the count of those that nobody pulled is kept
 * in the inbox.purged counter for the doctor. Works on $connection (default connection when null).
 */
class PullServer
{
    public const MAX_AGE_DAYS = 7;
    public const PAGE = 50;
    public const PAGE_BYTES = 8388608;
    public const LIST = '/_saan/inbox';
    public const ACK = '/_saan/inbox/ack';

    public function __construct(private ?string $connection = null, private ?string $peerKey = null, private ?int $now = null)
    {
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    private function now(): int
    {
        return $this->now ?? time();
    }

    /** @return array{0:int,1:array} HTTP status and JSON reply */
    public function handle(string $method, string $path, array $query, string $body, array $headers): array
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $h = array_map(fn ($v) => is_array($v) ? (string) ($v[0] ?? '') : (string) $v, $h);
        $method = strtoupper($method);
        $route = $method === 'GET' && $path === self::LIST ? 'list' : ($method === 'POST' && $path === self::ACK ? 'ack' : null);
        if ($route === null) return [404, ['error' => 'not_found']];
        if (strlen($body) > 65536) return [413, ['error' => 'too_large']];
        $peer = $this->peerKey ?? Keys::peer('pull.pub');
        if ($peer === null) return [503, ['error' => 'not_paired']];
        $ts = $h['x-saan-timestamp'] ?? '';
        $nonce = $h['x-saan-nonce'] ?? '';
        if (!ctype_digit($ts) || abs($this->now() - (int) $ts) > Wire::SKEW) return [401, ['error' => 'stale_timestamp']];
        if (!preg_match('/^[a-f0-9]{32}$/D', $nonce) || !Keys::pullVerify($method, $path, $query, (int) $ts, $nonce, $body, $h['x-saan-signature'] ?? '', $peer)) {
            State::mark($this->connection, 'inbox.sigfail', $this->now(), 1);
            return [401, ['error' => 'bad_signature']];
        }
        // the signature is valid: this exact request may be served once
        $this->db()->table('pub_inbox_nonces')->where('seen_at', '<', date('Y-m-d H:i:s', $this->now() - 2 * Wire::SKEW - 60))->delete();
        try { $this->db()->table('pub_inbox_nonces')->insert(['nonce' => $nonce, 'seen_at' => date('Y-m-d H:i:s', $this->now())]); }
        catch (UniqueConstraintViolationException) {
            State::mark($this->connection, 'inbox.sigfail', $this->now(), 1);
            return [401, ['error' => 'replay']];
        }
        $this->purge();
        return $route === 'list' ? $this->list($query) : $this->ack($body);
    }

    private function list(array $query): array
    {
        $after = max(0, (int) ($query['after'] ?? 0));
        $limit = max(1, min(self::PAGE, (int) ($query['limit'] ?? self::PAGE)));
        $rows = $this->db()->table('pub_inbox')->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
        $out = [];
        $bytes = 0;
        $more = false;
        foreach ($rows as $i => $r) {
            if (count($out) >= $limit || ($out && $bytes + (int) $r->size > self::PAGE_BYTES)) { $more = true; break; }
            $bytes += (int) $r->size;
            $out[] = ['id' => (int) $r->id, 'kind' => $r->kind, 'received_at' => $r->received_at, 'dedupe_hash' => $r->dedupe_hash, 'key_id' => $r->key_id,
                'ciphertext' => $r->ciphertext, 'attachments' => $r->attachments ? json_decode($r->attachments, true) : []];
        }
        State::mark($this->connection, 'inbox.pull', $this->now());
        $peerInbox = Keys::peer('inbox.pub');
        return [200, ['ok' => true, 'envelopes' => $out, 'has_more' => $more, 'current_kid' => $peerInbox ? Keys::keyId($peerInbox) : null, 'waiting' => (int) $this->db()->table('pub_inbox')->count()]];
    }

    private function ack(string $body): array
    {
        $j = json_decode($body, true, 8);
        $ids = is_array($j) && is_array($j['ids'] ?? null) ? $j['ids'] : null;
        if ($ids === null || count($ids) > 500 || array_filter($ids, fn ($i) => !is_int($i) || $i < 1)) return [422, ['error' => 'bad_ack']];
        $deleted = $ids ? $this->db()->table('pub_inbox')->whereIn('id', array_values($ids))->delete() : 0;
        return [200, ['ok' => true, 'deleted' => $deleted]];
    }

    /** Deletes envelopes older than MAX_AGE_DAYS and counts them. Also scheduled hourly (saan:inbox-purge) for installs nobody pulls from. */
    public function purge(): int
    {
        $cut = date('Y-m-d H:i:s', $this->now() - self::MAX_AGE_DAYS * 86400);
        $n = $this->db()->table('pub_inbox')->where('received_at', '<', $cut)->delete();
        if ($n > 0) State::mark($this->connection, 'inbox.purged', $this->now(), $n);
        return (int) $n;
    }
}
