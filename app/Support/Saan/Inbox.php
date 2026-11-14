<?php

namespace App\Support\Saan;

use App\Support\Saan\Inbox\Handlers;
use App\Support\Saan\Inbox\InboxRejected;
use App\Support\Saan\Inbox\PullServer;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Wire;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * S5: the one door through which a public form hands data to the school.
 *
 *   public edition    the data is sealed (libsodium crypto_box_seal) to the internal inbox public key (peer-inbox.pub) and stored in pub_inbox.
 *                     The public side keeps no plaintext, no copy and no log of the payload; only the internal edition can open the envelope,
 *                     after it pulled it (App\Support\Saan\Inbox\Puller).
 *   internal/combined the handler of the kind runs in this process, nothing is encrypted or pulled (behaviour as before the split).
 *
 * Inbox::submit('contact', ['name' => ..., ...], [['name' => 'a.pdf', 'path' => '/tmp/x']]) returns ['mode' => 'sealed'|'direct', 'id' => ?int, 'result' => ?array].
 * Attachments are checked (extension, size, MIME sniffed from the content) BEFORE they are sealed, and each one is sealed on its own.
 */
final class Inbox
{
    public const KIND = '/^[a-z][a-z0-9_.-]{1,31}$/D';
    public const MAX_FILES = 5;
    public const SKEW = 300;
    /** crypto_box_seal adds a 32 byte ephemeral key and a 16 byte tag. */
    private const SEAL_OVERHEAD = 48;

    /** Connection of the inbox tables (default connection when empty). Lets tests drive a second database. */
    public static function connection(): ?string
    {
        $c = config('saan.inbox_connection');
        return is_string($c) && $c !== '' ? $c : null;
    }

    private static function limit(string $key, int $default): int
    {
        return max(1, (int) config('saan.inbox.'.$key, $default));
    }

    /** @param list<array{name:string,path?:string,content?:string}> $files */
    public static function submit(string $kind, array $payload, array $files = []): array
    {
        if (!preg_match(self::KIND, $kind)) throw new InboxRejected('ชนิดข้อมูลไม่ถูกต้อง', 422);
        $prepared = self::prepareFiles($files);
        // an anonymous complaint must not leak the sender through the files (EXIF/GPS, document author, original file name)
        if ($kind === 'complaint' && !empty($payload['anonymous']) && $prepared) $prepared = Inbox\FileScrub::anonymous($prepared);
        $json = Wire::json($payload);
        if (strlen($json) > self::limit('max_payload_kb', 256) * 1024) throw new InboxRejected('ข้อมูลที่ส่งมีขนาดใหญ่เกินไป', 413);
        if (!Edition::isPublic()) {
            return ['mode' => 'direct', 'id' => null, 'result' => Handlers::dispatch($kind, $payload, $prepared)];
        }
        return self::seal($kind, $payload, $prepared);
    }

    /**
     * Extension must be allowed, size within limits and the sniffed MIME must be one that the extension stands for.
     * @return list<array{name:string,mime:string,size:int,sha256:string,content:string}>
     */
    public static function prepareFiles(array $files): array
    {
        if (count($files) > self::MAX_FILES) throw new InboxRejected('แนบไฟล์ได้ไม่เกิน '.self::MAX_FILES.' ไฟล์', 422);
        $out = [];
        $total = 0;
        $each = self::limit('max_file_kb', 8192) * 1024;
        $all = self::limit('max_total_kb', 20480) * 1024;
        foreach ($files as $f) {
            $name = self::cleanName((string) ($f['name'] ?? ''));
            $content = isset($f['content']) ? (string) $f['content'] : (isset($f['path']) && is_file((string) $f['path']) ? (string) file_get_contents((string) $f['path']) : null);
            if ($name === '' || $content === null) throw new InboxRejected('ไฟล์แนบไม่ถูกต้อง', 422);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset(Wire::FILE_EXT[$ext])) throw new InboxRejected('ไม่รับไฟล์ชนิดนี้: .'.$ext, 422);
            $size = strlen($content);
            $total += $size;
            if ($size === 0 || $size > $each || $total > $all) throw new InboxRejected('ไฟล์แนบมีขนาดใหญ่เกินกำหนด', 413);
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
            if (!in_array($mime, Wire::FILE_EXT[$ext], true)) throw new InboxRejected('เนื้อไฟล์ไม่ตรงกับนามสกุล .'.$ext, 422);
            $out[] = ['name' => $name, 'mime' => $mime, 'size' => $size, 'sha256' => hash('sha256', $content), 'content' => $content];
        }
        return $out;
    }

    private static function seal(string $kind, array $payload, array $files): array
    {
        $pub = Keys::peer('inbox.pub');
        if ($pub === null) throw new InboxRejected('ยังไม่ได้จับคู่กับระบบภายใน ไม่สามารถรับข้อมูลได้', 503);
        $db = DB::connection(self::connection());
        if ((int) $db->table('pub_inbox')->count() >= self::limit('max_waiting', 5000)) throw new InboxRejected('กล่องรับเรื่องเต็มชั่วคราว กรุณาลองใหม่ภายหลัง', 503);
        // the contact form is open to anybody: it has a share of its own, so a flood of messages cannot make survey answers (or any other kind) bounce
        if ($kind === 'contact' && (int) $db->table('pub_inbox')->where('kind', 'contact')->count() >= min(self::limit('max_waiting', 5000), self::limit('max_waiting_contact', 1000))) throw new InboxRejected('กล่องรับข้อความติดต่อเต็มชั่วคราว กรุณาลองใหม่ภายหลัง', 503);
        // same for complaints (F1): a share of its own, so a flood of complaints cannot make survey answers or contact messages bounce
        if ($kind === 'complaint' && (int) $db->table('pub_inbox')->where('kind', 'complaint')->count() >= min(self::limit('max_waiting', 5000), self::limit('max_waiting_complaint', 1000))) throw new InboxRejected('กล่องรับเรื่องร้องเรียนเต็มชั่วคราว กรุณาลองใหม่ภายหลัง', 503);
        $raw = base64_decode($pub, true);
        $nonce = bin2hex(random_bytes(16));
        $env = ['v' => 1, 'kind' => $kind, 'ts' => time(), 'nonce' => $nonce, 'kid' => Keys::keyId($pub), 'payload' => $payload,
            'files' => array_map(fn ($f) => ['name' => $f['name'], 'mime' => $f['mime'], 'size' => $f['size'], 'sha256' => $f['sha256']], $files)];
        $ct = base64_encode(sodium_crypto_box_seal(Wire::json($env), $raw));
        $att = [];
        foreach ($files as $f) $att[] = ['c' => base64_encode(sodium_crypto_box_seal($f['content'], $raw))];
        $row = ['kind' => $kind, 'received_at' => date('Y-m-d H:i:s'), 'dedupe_hash' => hash('sha256', 'saan-inbox-1|'.$nonce), 'key_id' => $env['kid'],
            'ciphertext' => $ct, 'attachments' => $att ? Wire::json($att) : null, 'size' => strlen($ct) + array_sum(array_map(fn ($a) => strlen($a['c']), $att))];
        for ($try = 0; ; $try++) {
            try { $id = (int) $db->table('pub_inbox')->insertGetId($row); break; }
            catch (UniqueConstraintViolationException $e) {
                if ($try >= 2) throw $e;
                $row['dedupe_hash'] = hash('sha256', 'saan-inbox-1|'.bin2hex(random_bytes(16)));
            }
        }
        return ['mode' => 'sealed', 'id' => $id, 'result' => null];
    }

    /** Seals nothing, opens nothing: opens one stored envelope with the secret of its key id (internal edition). @return array{env:array,files:list<array>} */
    public static function open(string $ciphertextB64, ?string $attachmentsJson, string $keyId): array
    {
        $secrets = Keys::inboxSecrets();
        if (!isset($secrets[$keyId])) throw new InboxRejected('ไม่มีกุญแจสำหรับซองนี้ (key id '.$keyId.')', 409);
        $sk = $secrets[$keyId];
        $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($sk, sodium_crypto_box_publickey_from_secretkey($sk));
        // The public side is outside our trust: every size is checked here again, before anything is decoded or decrypted.
        $maxPayload = self::limit('max_payload_kb', 256) * 1024 + 4096;
        $maxFile = self::limit('max_file_kb', 8192) * 1024;
        $maxTotal = self::limit('max_total_kb', 20480) * 1024;
        if (strlen($ciphertextB64) > (int) ceil(($maxPayload + self::SEAL_OVERHEAD) * 4 / 3) + 64) throw new InboxRejected('ซองมีขนาดใหญ่เกินกำหนด', 413);
        $ct = base64_decode($ciphertextB64, true);
        $plain = $ct === false ? false : @sodium_crypto_box_seal_open($ct, $kp);
        if ($plain === false) throw new InboxRejected('ถอดรหัสซองไม่ได้ (ข้อมูลถูกแก้ไขหรือส่งมาจากแหล่งอื่น)', 422);
        if (strlen($plain) > $maxPayload) throw new InboxRejected('ข้อมูลในซองมีขนาดใหญ่เกินกำหนด', 413);
        $env = json_decode($plain, true, 64);
        // ts must be recent: an envelope that the public side keeps for 7 days at most cannot be older than that (plus a day), so an old one cannot be put back
        if (!is_array($env) || ($env['v'] ?? null) !== 1 || !is_string($env['kind'] ?? null) || !preg_match(self::KIND, $env['kind'])
            || !is_string($env['nonce'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $env['nonce']) || !is_array($env['payload'] ?? null)
            || ($env['kid'] ?? null) !== $keyId || !is_int($env['ts'] ?? null) || $env['ts'] > time() + self::SKEW || $env['ts'] < time() - (PullServer::MAX_AGE_DAYS + 1) * 86400) {
            throw new InboxRejected('รูปแบบซองไม่ถูกต้อง หรือซองเก่าเกินกำหนด', 422);
        }
        $meta = is_array($env['files'] ?? null) ? $env['files'] : [];
        $sealed = $attachmentsJson ? (json_decode($attachmentsJson, true, 8) ?: []) : [];
        if (!is_array($sealed) || count($sealed) !== count($meta) || count($meta) > self::MAX_FILES) throw new InboxRejected('ไฟล์แนบไม่ครบหรือเกินกำหนด', 422);
        $files = [];
        $total = 0;
        foreach ($meta as $i => $m) {
            // the attachment list and its metadata come from the public side: a wrong type is refused before any cast (an array cast to string is a warning, which would abort the whole pull)
            if (!is_array($m) || !is_array($sealed[$i] ?? null) || !is_string($sealed[$i]['c'] ?? null) || !is_string($m['name'] ?? null) || !is_string($m['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $m['sha256'])) throw new InboxRejected('ข้อมูลไฟล์แนบมีรูปแบบไม่ถูกต้อง', 422);
            $b64 = $sealed[$i]['c'];
            if (strlen($b64) > (int) ceil(($maxFile + self::SEAL_OVERHEAD) * 4 / 3) + 64) throw new InboxRejected('ไฟล์แนบมีขนาดใหญ่เกินกำหนด', 413);
            $bytes = base64_decode($b64, true);
            $content = $bytes === false ? false : @sodium_crypto_box_seal_open($bytes, $kp);
            if ($content === false || !is_array($m) || strlen($content) !== (int) ($m['size'] ?? -1) || !hash_equals((string) ($m['sha256'] ?? ''), hash('sha256', $content))) throw new InboxRejected('ไฟล์แนบเสียหายหรือถูกแก้ไข', 422);
            $total += strlen($content);
            if ($content === '' || strlen($content) > $maxFile || $total > $maxTotal) throw new InboxRejected('ไฟล์แนบมีขนาดใหญ่เกินกำหนด', 413);
            // the name is only a label: no directories, no control characters (a handler that writes a file must still build its own storage name)
            $name = self::cleanName((string) ($m['name'] ?? ''));
            if ($name === '' || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new InboxRejected('ชื่อไฟล์แนบไม่ถูกต้อง', 422);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
            if (!isset(Wire::FILE_EXT[$ext]) || !in_array($mime, Wire::FILE_EXT[$ext], true)) throw new InboxRejected('ชนิดไฟล์แนบไม่ผ่านการตรวจ', 422);
            $files[] = ['name' => $name, 'mime' => $mime, 'size' => strlen($content), 'sha256' => (string) $m['sha256'], 'content' => $content];
        }
        return ['env' => $env, 'files' => $files];
    }

    /** Label for a file: no directories, at most 120 characters, the extension is kept when the name is cut. */
    public static function cleanName(string $raw): string
    {
        $name = basename(str_replace('\\', '/', $raw));
        if (mb_strlen($name) <= 120) return $name;
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if ($ext === '' || strlen($ext) > 10) return mb_substr($name, 0, 120);
        return mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 119 - strlen($ext)).'.'.$ext;
    }

    /** Hash of what is inside the envelope (its random nonce): the idempotency key that the public side cannot choose. */
    public static function innerHash(array $env): string
    {
        return hash('sha256', 'saan-inbox-n|'.(string) ($env['nonce'] ?? ''));
    }
}
