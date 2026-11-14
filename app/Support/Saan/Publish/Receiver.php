<?php

namespace App\Support\Saan\Publish;

use App\Support\Saan\Projection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Public edition receiver (S4). Everything the sender says is checked again here and nothing is trusted:
 *   - Ed25519 signature of the exact body against the paired internal key (peer-push.pub), timestamp skew <= 5 minutes
 *   - payload hash, strictly increasing sequence per stream (replay -> 409, gap -> 409)
 *   - stream must be a table that Projection::declared() of this code base declares (any module, active or not) or the module list
 *   - the column list is intersected with the declaration and every column rule is applied again (null, const, mask); row filters that can be
 *     tested on the row (status, dates) are re-checked, a failing row is never stored and removes an older copy
 *   - rows are written in one transaction; files are written only after SHA-256, size, extension and sniffed MIME all agree
 * Forbidden tables (users, students, ...) are refused whatever the sender names. Works on $connection (default connection when null).
 */
class Receiver
{
    private const MAX_ROWS = 20000;
    private const MAX_FILE_BYTES = 67108864;

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

    private function disk()
    {
        return Storage::disk((string) config('saan.push.receive_disk', 'local'));
    }

    /** @return array{0:int,1:array} HTTP status and JSON reply */
    public function handle(string $body, array $headers): array
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $h = array_map(fn ($v) => is_array($v) ? (string) ($v[0] ?? '') : (string) $v, $h);
        if (strlen($body) > max(64, (int) config('saan.push.max_body_kb', 8192)) * 1024) return [413, ['error' => 'too_large']];
        $peer = $this->peerKey ?? Keys::peerPublic();
        if ($peer === null) return [503, ['error' => 'not_paired']];
        $ts = $h['x-saan-timestamp'] ?? '';
        if (!ctype_digit($ts) || abs($this->now() - (int) $ts) > Wire::SKEW) return [401, ['error' => 'stale_timestamp']];
        if (!Keys::verify((int) $ts, $body, $h['x-saan-signature'] ?? '', $peer)) {
            try { \App\Support\Saan\Inbox\State::mark($this->connection, 'push.sigfail', $this->now(), 1); } catch (\Throwable) { /* a counter must never change the answer */ }
            return [401, ['error' => 'bad_signature']];
        }
        $env = json_decode($body, true, 64);
        if (!is_array($env) || (int) ($env['ts'] ?? 0) !== (int) $ts || !is_array($env['payload'] ?? null)) return [400, ['error' => 'bad_request']];
        $kind = $env['kind'] ?? '';
        $payload = $env['payload'];
        if (!in_array($kind, ['batch', 'files', 'chunk', 'verify', 'caps'], true)) return [400, ['error' => 'bad_kind']];
        if (!in_array($kind, ['verify', 'caps'], true) && !hash_equals((string) ($env['payload_hash'] ?? ''), Wire::payloadHash($payload))) return [422, ['error' => 'payload_hash']];
        try {
            return match ($kind) {
                'verify' => [200, ['ok' => true, 'checksums' => $this->checksums(), 'caps' => $this->caps()]],
                'caps' => [200, ['ok' => true, 'caps' => $this->caps()]],
                'files' => $this->filesCheck($payload),
                'chunk' => $this->chunk($payload),
                'batch' => $this->batch($payload, (string) $env['payload_hash']),
            };
        } catch (\Throwable $e) {
            Log::error('[ERR] saan push apply: '.$e->getMessage());
            return [500, ['error' => 'apply_failed']];
        }
    }

    /**
     * What this (public) side can do, announced to the internal publisher. notice_full_name_until: the table has the column and the receiver
     * masks expired cells; turnstile: the anti-bot step is configured here. The publisher sends full-name cells only when both are true.
     */
    private function caps(): array
    {
        $s = $this->db()->getSchemaBuilder();
        return ['notice_full_name_until' => class_exists(\Saan\Modules\WebNotices\NoticeRepository::class) && $s->hasTable('pub_notice_rows') && $s->hasColumn('pub_notice_rows', 'full_name_until'),
            'notice_search_only' => class_exists(\Saan\Modules\WebNotices\NoticeRepository::class) && $s->hasTable('notice_lists') && $s->hasColumn('notice_lists', 'search_only'),
            'turnstile' => \App\Support\Saan\Turnstile::enabled()];
    }

    // ---------------------------------------------------------------- allowlist

    /** @return array<string,array> declared entries by table (any module, active or not) */
    private function entries(): array
    {
        $out = [];
        foreach (Projection::declared(false, true) as $e) $out[$e['table']] = $e;
        return $out;
    }

    private function columns(string $table): array
    {
        return array_column($this->db()->getSchemaBuilder()->getColumns($table), 'name');
    }

    // ---------------------------------------------------------------- batch

    private function batch(array $p, string $hash): array
    {
        $stream = $p['stream'] ?? null;
        $op = $p['op'] ?? null;
        $seq = $p['seq'] ?? null;
        $entries = $this->entries();
        if (!is_string($stream) || ($stream !== Wire::MODULES && !isset($entries[$stream]))) return [403, ['error' => 'forbidden_stream']];
        if ($stream !== Wire::MODULES && Projection::isForbiddenTable($stream) && $stream !== 'saan_settings') return [403, ['error' => 'forbidden_stream']];
        if (!is_int($seq) || $seq < 1 || !in_array($op, ['upsert', 'delete', 'full'], true)) return [422, ['error' => 'bad_batch']];
        $rows = $p['rows'] ?? [];
        $deletes = $p['deletes'] ?? [];
        if (!is_array($rows) || !is_array($deletes) || count($rows) > self::MAX_ROWS || count($deletes) > self::MAX_ROWS) return [422, ['error' => 'bad_batch']];

        $db = $this->db();
        $this->orphans = [];
        $result = $db->transaction(function () use ($db, $stream, $op, $seq, $hash, $p, $rows, $deletes, $entries) {
            $state = $db->table('pub_sync_state')->where('stream', $stream)->lockForUpdate()->first();
            $last = $state ? (int) $state->last_seq : 0;
            if ($seq <= $last) return [409, ['error' => 'replay', 'last_seq' => $last, 'last_hash' => $state->last_hash]];
            $begin = $op === 'full' && !empty($p['begin']);
            if (!$begin && $seq !== $last + 1) return [409, ['error' => 'gap', 'last_seq' => $last]];

            $this->ensurePlaceholder();
            $key = Wire::keyColumn($stream);
            $applied = 0;
            $rejected = 0;
            if ($deletes) $this->deleteKeys($stream, $key, array_map('strval', $deletes));
            if ($op !== 'delete' && $rows) [$applied, $rejected] = $this->upsert($stream, $key, $rows, $entries[$stream] ?? null);
            if ($op === 'full' && !empty($p['final'])) {
                $keep = array_flip(array_map('strval', (array) ($p['keys'] ?? [])));
                $gone = [];
                foreach ($this->scoped($stream)->pluck($key) as $k) if (!isset($keep[(string) $k])) $gone[] = (string) $k;
                if ($gone) $this->deleteKeys($stream, $key, $gone);
            }
            $row = ['last_seq' => $seq, 'last_hash' => $hash, 'applied_at' => date('Y-m-d H:i:s', $this->now())];
            if ($state) $db->table('pub_sync_state')->where('stream', $stream)->update($row);
            else $db->table('pub_sync_state')->insert(['stream' => $stream] + $row);
            return [200, ['ok' => true, 'stream' => $stream, 'seq' => $seq, 'applied' => $applied, 'rejected' => $rejected]];
        });
        $orphans = $this->orphans;
        $this->orphans = [];
        if ($result[0] !== 200) return $result;
        if ($stream === Wire::MODULES) $this->modulesChanged();
        // files that no remaining row points to are removed after the commit (withdrawn media, consent withdrawn, replaced photos)
        $this->sweepOrphans($orphans);
        if ($op === 'full' && !empty($p['final']) && isset(self::FILE_COLUMNS[$stream])) $this->sweepUnreferenced($stream);
        // the whole-table checksum is computed only when the sender asks for it (the verify call is the normal way)
        if (!empty($p['checksum'])) $result[1]['checksums'] = [$stream => $this->streamChecksum($stream)];
        return $result;
    }

    /** Stream => [path column, directory]: the streams whose rows own files on the receiving disk. */
    private const FILE_COLUMNS = ['cms_media' => ['path', 'cms'], 'per_profiles' => ['photo_path', 'personnel']];
    /** @var list<string> file paths that rows being deleted or replaced in the running transaction pointed to */
    private array $orphans = [];

    /**
     * The rows of $stream that the internal side owns. saan_settings is shared with the public edition's own settings (update feed state, last
     * check ...): only the whitelisted keys belong to the sender, so deletes and the final sweep of a full rebuild never reach the others.
     */
    private function scoped(string $stream)
    {
        $q = $this->db()->table($stream);
        if ($stream === 'saan_settings') $q->whereIn('key', array_keys(Projection::SETTINGS_KEY_WHITELIST));
        return $q;
    }

    private function deleteKeys(string $stream, string $key, array $keys): void
    {
        $col = self::FILE_COLUMNS[$stream][0] ?? null;
        foreach (array_chunk(array_values(array_unique($keys)), 400) as $c) {
            if ($col) foreach ($this->scoped($stream)->whereIn($key, $c)->pluck($col) as $p) $this->orphans[] = (string) $p;
            $this->scoped($stream)->whereIn($key, $c)->delete();
        }
    }

    /** saan_settings: a row of this edition that already sits under an id the sender is about to use moves to a free id first (the sender's id is what the checksums compare). */
    private function freeSettingIds(array $ids): void
    {
        $db = $this->db();
        $next = max((int) $db->table('saan_settings')->max('id'), $ids ? max(array_map('intval', $ids)) : 0);
        foreach (array_chunk(array_map('intval', $ids), 400) as $c) {
            foreach ($db->table('saan_settings')->whereIn('id', $c)->whereNotIn('key', array_keys(Projection::SETTINGS_KEY_WHITELIST))->pluck('id') as $id) {
                $db->table('saan_settings')->where('id', $id)->update(['id' => ++$next]);
            }
        }
    }

    private function referenced(string $path): bool
    {
        foreach (self::FILE_COLUMNS as $table => [$col]) if ($this->db()->table($table)->where($col, $path)->exists()) return true;
        return false;
    }

    private function sweepOrphans(array $paths): void
    {
        foreach (array_unique($paths) as $p) {
            if ($p === '' || !preg_match(Wire::FILE_PATH, $p) || str_contains($p, '..') || $this->referenced($p)) continue;
            try { $this->disk()->delete($p); } catch (\Throwable) { /* a file that cannot be removed is retried by the next full rebuild */ }
        }
    }

    /** After a full rebuild of a file-owning stream: every file in its directory that no row references goes, and old unfinished uploads too. */
    private function sweepUnreferenced(string $stream): void
    {
        $dir = self::FILE_COLUMNS[$stream][1];
        $disk = $this->disk();
        try {
            $this->sweepOrphans($disk->allFiles($dir));
            foreach (glob($disk->path('saan-incoming').'/*.part') ?: [] as $part) if (@filemtime($part) < $this->now() - 86400) @unlink($part);
        } catch (\Throwable) { /* best effort */ }
    }

    /** @return array{0:int,1:int} applied, rejected */
    private function upsert(string $stream, string $key, array $rows, ?array $entry): array
    {
        $db = $this->db();
        if ($stream === Wire::MODULES) {
            $ids = [];
            foreach ($rows as $r) if (is_array($r) && is_string($r['module_id'] ?? null) && preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $r['module_id'])) $ids[] = ['module_id' => $r['module_id']];
            foreach (array_chunk($ids, 200) as $c) $db->table($stream)->insertOrIgnore($c);
            return [count($ids), count($rows) - count($ids)];
        }
        $have = array_flip($this->columns($stream));
        $rules = array_intersect_key($entry['columns'], $have);
        if (!isset($rules[$key])) return [0, count($rows)];
        $good = [];
        $drop = [];
        $rejected = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) { $rejected++; continue; }
            $row = [];
            foreach ($rules as $c => $rule) {
                $v = $r[$c] ?? null;
                if (is_array($v) || is_object($v)) { $row = null; break; }
                $row[$c] = match (true) {
                    // mask_name cannot be re-derived here (the original name is not on this side); it is stored as received, like keep
                    $rule === 'keep', $rule === 'mask_name' => $v === null ? null : (string) $v,
                    // the answer key is removed here again: a sender that forgot (or a breached internal side) must not put quiz answers into this database
                    $rule === 'scrub_answer_keys' => Wire::scrubAnswerKeys($v === null ? null : (string) $v),
                    $rule === 'null' => null,
                    default => substr($rule, 6),
                };
            }
            // F9: a notice row whose full-name window has already passed is stored masked, whatever the sender claims
            if ($row !== null && $stream === 'pub_notice_rows' && class_exists(\Saan\Modules\WebNotices\NoticeRepository::class)) {
                if (!isset($have['full_name_until'])) {
                    // the table has no full_name_until column (migration not run): a row that carries one holds a FULL name and cannot be timed here, so it is masked now and the key never stored
                    if (($r['full_name_until'] ?? null) !== null) $row = \Saan\Modules\WebNotices\NoticeRepository::guardReceivedRow(['full_name_until' => '1970-01-01 00:00:00'] + $row);
                    unset($row['full_name_until']);
                } else {
                    $row = \Saan\Modules\WebNotices\NoticeRepository::guardReceivedRow($row);
                }
            }
            if ($row === null || !isset($row[$key]) || $row[$key] === '' || strlen($row[$key]) > 80) { $rejected++; continue; }
            if (!Builder::rowPasses($entry['where'], $row)) { $rejected++; $drop[] = $row[$key]; continue; }
            $good[$row[$key]] = $row;
        }
        if ($drop) $this->deleteKeys($stream, $key, $drop);
        if ($good) {
            if ($stream === 'saan_settings') $this->freeSettingIds(array_keys($good));
            $this->clearUniqueClashes($stream, $key, $good);
            $col = self::FILE_COLUMNS[$stream][0] ?? null;
            if ($col && isset($rules[$col])) {
                // a replaced file (the row keeps its id but points to another path) is orphaned too
                foreach (array_chunk(array_keys($good), 400) as $ids) {
                    foreach ($db->table($stream)->whereIn($key, $ids)->pluck($col, $key) as $k => $old) if ($old !== null && $old !== ($good[(string) $k][$col] ?? null)) $this->orphans[] = (string) $old;
                }
            }
            $update = array_values(array_diff(array_keys($rules), [$key]));
            foreach (array_chunk(array_values($good), max(1, intdiv(900, max(1, count($rules))))) as $c) $db->table($stream)->upsert($c, [$key], $update);
        }
        return [count($good), $rejected];
    }

    /**
     * The public tables keep their secondary unique indexes (posts slug, notice_lists slug, lrn_links url, ...). A row that arrives under a
     * new primary key but with the unique value of a stale row (an old post deleted and a new one published under the same slug in the same
     * batch window) would violate the index, or on MySQL silently overwrite the stale row's id. The stale row is removed first.
     */
    private function clearUniqueClashes(string $stream, string $key, array $good): void
    {
        $db = $this->db();
        $uniques = [];
        foreach ($db->getSchemaBuilder()->getIndexes($stream) as $ix) {
            if (!empty($ix['unique']) && empty($ix['primary']) && $ix['columns']) $uniques[] = $ix['columns'];
        }
        if (!$uniques) return;
        foreach ($uniques as $cols) {
            foreach ($good as $id => $row) {
                $match = [];
                foreach ($cols as $c) { if (!array_key_exists($c, $row) || $row[$c] === null) continue 2; $match[$c] = $row[$c]; } // a NULL part never clashes
                $q = $db->table($stream)->where($match)->where($key, '!=', $id);
                $col = self::FILE_COLUMNS[$stream][0] ?? null;
                if ($col) foreach ((clone $q)->pluck($col) as $p) $this->orphans[] = (string) $p;
                $q->delete();
            }
        }
    }

    /** posts.author_id and uploaded_by point at user 1: a placeholder with no usable password and no sign-in on this edition. */
    private function ensurePlaceholder(): void
    {
        $db = $this->db();
        if ($db->table('users')->where('id', 1)->exists()) return;
        $have = array_flip($this->columns('users'));
        $row = ['id' => 1, 'name' => 'Saan public', 'email' => 'public-placeholder@invalid.invalid', 'password' => bin2hex(random_bytes(32)),
            'school_id' => (int) config('school.school_id'), 'role' => 'placeholder', 'is_active' => 0, 'created_at' => date('Y-m-d H:i:s', $this->now()), 'updated_at' => date('Y-m-d H:i:s', $this->now())];
        $db->table('users')->insertOrIgnore(array_intersect_key($row, $have));
    }

    private function modulesChanged(): void
    {
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        // A cached route table would freeze the old module set: drop it so the next request registers the routes of the published list.
        if (app()->routesAreCached()) { try { Artisan::call('route:clear'); } catch (\Throwable) {} }
    }

    // ---------------------------------------------------------------- checksums

    /** @return array{count:int,sha256:string} */
    private function streamChecksum(string $stream): array
    {
        $db = $this->db();
        if ($stream === Wire::MODULES) $cols = ['module_id'];
        else {
            $entry = $this->entries()[$stream] ?? null;
            $cols = $entry ? array_values(array_intersect(array_keys($entry['columns']), $this->columns($stream))) : [];
        }
        $key = Wire::keyColumn($stream);
        $hashes = [];
        foreach ($this->scoped($stream)->get($cols) as $r) $hashes[(string) $r->{$key}] = Wire::rowHash((array) $r);
        return Wire::checksum($hashes);
    }

    private function checksums(): array
    {
        $out = [];
        foreach (array_merge([Wire::MODULES], array_keys($this->entries())) as $s) {
            if ($this->db()->getSchemaBuilder()->hasTable($s)) $out[$s] = $this->streamChecksum($s);
        }
        return $out;
    }

    // ---------------------------------------------------------------- files

    private function validFile(mixed $f): ?array
    {
        if (!is_array($f)) return null;
        $path = $f['path'] ?? null;
        $sha = $f['sha256'] ?? null;
        $size = $f['size'] ?? null;
        if (!is_string($path) || !preg_match(Wire::FILE_PATH, $path) || str_contains($path, '..')) return null;
        if (!is_string($sha) || !preg_match('/^[0-9a-f]{64}$/D', $sha) || !is_int($size) || $size < 0 || $size > self::MAX_FILE_BYTES) return null;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(Wire::FILE_EXT[$ext])) return null;
        if ($ext === 'svg' && !str_starts_with($path, 'cms/')) return null;
        return ['path' => $path, 'sha256' => $sha, 'size' => $size, 'ext' => $ext];
    }

    private function filesCheck(array $p): array
    {
        $files = $p['files'] ?? null;
        if (!is_array($files) || count($files) > 500) return [422, ['error' => 'bad_files']];
        $need = [];
        $bad = [];
        foreach ($files as $f) {
            $v = $this->validFile($f);
            // one unacceptable entry must not hold back the others (or the rows): it is reported and the sender skips just that file
            if (!$v) { $bad[] = is_array($f) && is_string($f['path'] ?? null) ? $f['path'] : '?'; continue; }
            if (!$this->disk()->exists($v['path']) || !hash_equals($v['sha256'], (string) hash_file('sha256', $this->disk()->path($v['path'])))) $need[] = $v['path'];
        }
        return [200, ['ok' => true, 'need' => $need] + ($bad ? ['rejected' => $bad] : [])];
    }

    private function chunk(array $p): array
    {
        $v = $this->validFile($p);
        $offset = $p['offset'] ?? null;
        $data = is_string($p['data'] ?? null) ? base64_decode($p['data'], true) : false;
        if (!$v || !is_int($offset) || $offset < 0 || $data === false || strlen($data) > 4194304) return [422, ['error' => 'bad_chunk']];
        $disk = $this->disk();
        $partRel = 'saan-incoming/'.$v['sha256'].'.part';
        $disk->makeDirectory('saan-incoming');
        $part = $disk->path($partRel);
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($offset === 0) foreach (glob($disk->path('saan-incoming').'/*.part') ?: [] as $old) if ($old !== $part && @filemtime($old) < $this->now() - 86400) @unlink($old); // abandoned uploads
        if ($offset === 0 && $have > 0) { @unlink($part); $have = 0; }
        if ($offset !== $have) return [409, ['error' => 'offset', 'have' => $have]];
        if ($have + strlen($data) > $v['size']) { @unlink($part); return [422, ['error' => 'size']]; }
        if (file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) return [500, ['error' => 'write_failed']];
        if ($have + strlen($data) < $v['size']) return [200, ['ok' => true, 'complete' => false]];
        // complete: the content must be what was announced, and must be what its extension says it is
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($part);
        if (!hash_equals($v['sha256'], (string) hash_file('sha256', $part))) { @unlink($part); return [422, ['error' => 'sha256']]; }
        if (!in_array($mime, Wire::FILE_EXT[$v['ext']], true) || (is_string($p['mime'] ?? null) && !in_array($p['mime'], Wire::FILE_EXT[$v['ext']], true))) { @unlink($part); return [422, ['error' => 'mime']]; }
        // an SVG is accepted only if rebuilding it from the allowlist gives back exactly the bytes that were sent (defence in depth: the internal side already sanitised it)
        if ($v['ext'] === 'svg') {
            $raw = (string) file_get_contents($part);
            $clean = \App\Domain\Cms\SvgSanitizer::clean($raw);
            if ($clean === null || !hash_equals($clean['svg'], $raw)) { @unlink($part); return [422, ['error' => 'svg']]; }
        }
        $disk->makeDirectory(dirname($v['path']));
        $dest = $disk->path($v['path']);
        if (!@rename($part, $dest)) { if (!@copy($part, $dest)) return [500, ['error' => 'write_failed']]; @unlink($part); }
        return [200, ['ok' => true, 'complete' => true]];
    }
}
