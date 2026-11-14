<?php

namespace App\Support\Saan\Publish;

use RuntimeException;

/**
 * Ed25519 keys of the push channel (S3/S4; S6 adds the pairing wizard).
 *   push.secret       internal edition: signing key (base64 of the 64 byte libsodium secret key), generated on first use, never leaves this server
 *   push.pub          internal edition: public half, exported for pairing (base64, 32 bytes)
 *   peer-push.pub     public edition: the paired internal public key that every batch must be signed with
 */
final class Keys
{
    /** Key directory. Refused when it lies inside the web root: key files must never be reachable by URL. */
    public static function dir(): string
    {
        $d = rtrim((string) (config('saan.keys_dir') ?: storage_path('app/private/saan-keys')), '/\\');
        $norm = fn (string $p) => rtrim(strtolower(str_replace('\\', '/', $p)), '/').'/';
        $web = $norm((string) (realpath(public_path()) ?: public_path()));
        $mine = $norm((string) (realpath($d) ?: $d));
        if (str_starts_with($mine, $web) || str_starts_with($norm($d), $web)) throw new RuntimeException('keys_dir ต้องอยู่นอก public/ (โฟลเดอร์ที่เว็บเข้าถึงได้): '.$d);
        return $d;
    }

    public static function ensureDir(): void
    {
        $d = self::dir();
        if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) throw new RuntimeException('สร้างโฟลเดอร์กุญแจไม่ได้: '.$d);
        @chmod($d, 0700); // an existing directory is tightened too
    }

    private static function readSecret(string $file): string
    {
        $raw = base64_decode(trim((string) file_get_contents($file)), true);
        if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) return $raw;
        throw new RuntimeException('push.secret เสียหาย: ลบแล้วจับคู่กุญแจใหม่');
    }

    /** Signing secret key (raw bytes). Generated on first use; creation is exclusive and atomic, the file is never readable by others. */
    public static function secret(): string
    {
        $file = self::dir().'/push.secret';
        if (is_file($file)) return self::readSecret($file);
        self::ensureDir();
        $old = umask(0077);
        try {
            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            sodium_memzero($pair);
            $tmp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
            $h = @fopen($tmp, 'x');
            if (!$h) throw new RuntimeException('เขียน push.secret ไม่ได้');
            $ok = fwrite($h, base64_encode($secret)) !== false;
            fclose($h);
            @chmod($tmp, 0600);
            if (!$ok) { @unlink($tmp); throw new RuntimeException('เขียน push.secret ไม่ได้'); }
            // link() fails when another process created the file first: then that file wins and ours is discarded (never overwritten)
            if (@link($tmp, $file)) @unlink($tmp);
            elseif (is_file($file)) { @unlink($tmp); return self::readSecret($file); }
            elseif (!@rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('เขียน push.secret ไม่ได้'); }
        } finally { umask($old); }
        self::publicKey(); // push.pub is always derived from the secret that is on disk
        return self::readSecret($file);
    }

    /** Public half to give to the public edition (base64). The exported push.pub is rewritten whenever it differs from the secret's own public key. */
    public static function publicKey(): string
    {
        $pub = base64_encode(sodium_crypto_sign_publickey_from_secretkey(self::secret()));
        if (self::read('push.pub') !== $pub) self::put('push.pub', $pub); // atomic and 0600 (same as pull.pub / inbox.pub)
        return $pub;
    }

    /** Public edition: paired internal key (base64) or null when not paired yet. */
    public static function peerPublic(): ?string
    {
        $file = self::dir().'/peer-push.pub';
        if (!is_file($file)) return null;
        $v = trim((string) file_get_contents($file));
        $raw = base64_decode($v, true);
        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $v : null;
    }

    /** What is signed: the timestamp and the SHA-256 of the exact request body. */
    public static function message(int $ts, string $body): string
    {
        return "SAAN-PUSH-1\n".$ts."\n".hash('sha256', $body);
    }

    public static function sign(int $ts, string $body): string
    {
        return base64_encode(sodium_crypto_sign_detached(self::message($ts, $body), self::secret()));
    }

    public static function verify(int $ts, string $body, string $signatureB64, string $publicKeyB64): bool
    {
        $sig = base64_decode($signatureB64, true);
        $pub = base64_decode($publicKeyB64, true);
        if ($sig === false || $pub === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
        return sodium_crypto_sign_verify_detached($sig, self::message($ts, $body), $pub);
    }

    // ---------------------------------------------------------------- S5/S6: more key files
    //   pull.secret / pull.pub      internal: Ed25519 pair that signs the inbox pull requests
    //   inbox.secret / inbox.pub    internal: Curve25519 box pair; the public side seals envelopes to inbox.pub (base64, 32 bytes)
    //   inbox.old.<keyid>.secret    internal: retired inbox secret, kept until every envelope sealed with it has been pulled
    //   unlock.key (+ unlock.old.*) internal: dedicated unlock HMAC key after a rotation (before that the derived key of HashKey is used)
    //   peer-push.pub, peer-pull.pub, peer-inbox.pub, peer-unlock.key   public: what pairing stored

    /** Raw file content or null. */
    public static function read(string $name): ?string
    {
        $f = self::dir().'/'.$name;
        if (!is_file($f)) return null;
        $v = @file_get_contents($f);
        return $v === false ? null : trim($v);
    }

    /** Writes a key file (0600) and replaces an existing one atomically. */
    public static function put(string $name, string $content, ?string $dir = null): void
    {
        // $dir: another install's key directory (the standard installer pairs both apps of one machine without a network round trip)
        if ($dir === null) self::ensureDir();
        elseif (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('สร้างโฟลเดอร์กุญแจไม่ได้: '.$dir);
        $file = ($dir === null ? self::dir() : rtrim($dir, '/\\')).'/'.$name;
        $old = umask(0077);
        try {
            $tmp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (@file_put_contents($tmp, $content, LOCK_EX) === false) throw new RuntimeException('เขียนไฟล์กุญแจไม่ได้: '.$name);
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('เขียนไฟล์กุญแจไม่ได้: '.$name); }
        } finally { umask($old); }
    }

    /** Content of $name; created from $make() on first use. Creation is exclusive: when two processes race, the file that landed first wins. */
    private static function lazy(string $name, callable $make): string
    {
        $v = self::read($name);
        if ($v !== null && $v !== '') return $v;
        self::ensureDir();
        $file = self::dir().'/'.$name;
        $old = umask(0077);
        try {
            $tmp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (@file_put_contents($tmp, $make(), LOCK_EX) === false) throw new RuntimeException('เขียนไฟล์กุญแจไม่ได้: '.$name);
            @chmod($tmp, 0600);
            if (@link($tmp, $file)) @unlink($tmp);
            elseif (is_file($file)) @unlink($tmp);
            elseif (!@rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('เขียนไฟล์กุญแจไม่ได้: '.$name); }
        } finally { umask($old); }
        return (string) self::read($name);
    }

    /** Pull-signing secret (raw 64 bytes). */
    public static function pullSecret(): string
    {
        $raw = base64_decode(self::lazy('pull.secret', fn () => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('pull.secret เสียหาย: หมุนกุญแจแล้วจับคู่ใหม่');
        return $raw;
    }

    public static function pullPublic(): string
    {
        $pub = base64_encode(sodium_crypto_sign_publickey_from_secretkey(self::pullSecret()));
        if (self::read('pull.pub') !== $pub) self::put('pull.pub', $pub);
        return $pub;
    }

    /** What an inbox pull request signs: method, path, canonical query, timestamp, nonce and the SHA-256 of the body. */
    public static function pullMessage(string $method, string $path, array $query, int $ts, string $nonce, string $body): string
    {
        ksort($query);
        return "SAAN-PULL-1\n".strtoupper($method)."\n".$path."\n".http_build_query($query, '', '&', PHP_QUERY_RFC3986)."\n".$ts."\n".$nonce."\n".hash('sha256', $body);
    }

    public static function pullSign(string $method, string $path, array $query, int $ts, string $nonce, string $body): string
    {
        return base64_encode(sodium_crypto_sign_detached(self::pullMessage($method, $path, $query, $ts, $nonce, $body), self::pullSecret()));
    }

    public static function pullVerify(string $method, string $path, array $query, int $ts, string $nonce, string $body, string $signatureB64, string $publicKeyB64): bool
    {
        $sig = base64_decode($signatureB64, true);
        $pub = base64_decode($publicKeyB64, true);
        if ($sig === false || $pub === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
        return sodium_crypto_sign_verify_detached($sig, self::pullMessage($method, $path, $query, $ts, $nonce, $body), $pub);
    }

    private static function readBox(string $file): ?string
    {
        $v = self::read($file);
        $raw = $v === null ? false : base64_decode($v, true);
        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_BOX_SECRETKEYBYTES ? $raw : null;
    }

    /** Current inbox secret key (raw 32 bytes). */
    public static function inboxSecret(): string
    {
        self::lazy('inbox.secret', fn () => base64_encode(sodium_crypto_box_secretkey(sodium_crypto_box_keypair())));
        return self::readBox('inbox.secret') ?? throw new RuntimeException('inbox.secret เสียหาย: หมุนกุญแจแล้วจับคู่ใหม่');
    }

    /** Public half of the current inbox key (base64); inbox.pub is rewritten whenever it differs from the secret on disk. */
    public static function inboxPublic(): string
    {
        $pub = base64_encode(sodium_crypto_box_publickey_from_secretkey(self::inboxSecret()));
        if (self::read('inbox.pub') !== $pub) self::put('inbox.pub', $pub);
        return $pub;
    }

    /** Short identifier of an inbox public key; travels in the envelope header so the right secret is picked after a rotation. */
    public static function keyId(string $publicKeyB64): string
    {
        return substr(hash('sha256', 'saan-inbox-key|'.$publicKeyB64), 0, 16);
    }

    /** @return array<string,string> key id => raw secret: the current key and every retired key that still waits for its envelopes to drain */
    public static function inboxSecrets(): array
    {
        $out = [self::keyId(self::inboxPublic()) => self::inboxSecret()];
        foreach (glob(self::dir().'/inbox.old.*.secret') ?: [] as $f) {
            if (!preg_match('/inbox\.old\.([a-f0-9]{16})\.secret$/D', $f, $m)) continue;
            $raw = self::readBox(basename($f));
            if ($raw !== null) $out[$m[1]] = $raw;
        }
        return $out;
    }

    /** @return list<string> key ids of retired inbox keys that are still on disk */
    public static function retiredInboxKeys(): array
    {
        return array_values(array_diff(array_keys(self::inboxSecrets()), [self::keyId(self::inboxPublic())]));
    }

    public static function dropRetiredInboxKey(string $keyId): void
    {
        if (preg_match('/^[a-f0-9]{16}$/D', $keyId)) @unlink(self::dir().'/inbox.old.'.$keyId.'.secret');
    }

    /** Dedicated unlock key written by a rotation (hex), or null while the derived key of HashKey is in force. Never generates. */
    public static function unlockKey(): ?string
    {
        $v = self::read('unlock.key');
        return $v !== null && preg_match('/^[a-f0-9]{64}$/D', $v) ? $v : null;
    }

    /** @return list<string> unlock keys retired by rotations (still valid for codes issued before) */
    public static function retiredUnlockKeys(): array
    {
        $out = [];
        foreach (glob(self::dir().'/unlock.old.*.key') ?: [] as $f) {
            $v = self::read(basename($f));
            if ($v !== null && preg_match('/^[a-f0-9]{64}$/D', $v)) $out[] = $v;
        }
        return $out;
    }

    /**
     * Public edition: unlock keys the internal side retired by rotations, sent with the pairing bundle so codes issued before a rotation
     * (complaint tracking codes, whose plain value nobody keeps) can still be checked. They were on this side before, so nothing new is exposed.
     * @return list<string>
     */
    public static function peerRetiredUnlockKeys(): array
    {
        $out = [];
        foreach (glob(self::dir().'/peer-unlock.old.*.key') ?: [] as $f) {
            $v = self::read(basename($f));
            if ($v !== null && preg_match('/^[a-f0-9]{64}$/D', $v)) $out[] = $v;
        }
        return $out;
    }

    /** Public edition: a paired value from peer-<name> (validated), or null. */
    public static function peer(string $name): ?string
    {
        $v = self::read('peer-'.$name);
        if ($v === null) return null;
        if ($name === 'unlock.key') return preg_match('/^[a-f0-9]{64}$/D', $v) ? $v : null;
        $raw = base64_decode($v, true);
        $len = $name === 'inbox.pub' ? SODIUM_CRYPTO_BOX_PUBLICKEYBYTES : SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
        return $raw !== false && strlen($raw) === $len ? $v : null;
    }

    /** True when the public edition holds all four paired values. */
    public static function paired(): bool
    {
        foreach (['push.pub', 'pull.pub', 'inbox.pub', 'unlock.key'] as $n) if (self::peer($n) === null) return false;
        return true;
    }

    /** Internal edition: what a public install needs (public halves and the unlock key only; no secret of the internal side). */
    public static function bundle(): array
    {
        $inbox = self::inboxPublic();
        return ['v' => 1, 'push_pub' => self::publicKey(), 'pull_pub' => self::pullPublic(), 'inbox_pub' => $inbox, 'inbox_kid' => self::keyId($inbox),
            'unlock_key' => \App\Support\Saan\HashKey::publicUnlock(), 'unlock_old' => array_slice(self::retiredUnlockKeys(), 0, 20), 'issued_at' => time()];
    }

    /** Public edition: validates and stores a pairing bundle (all or nothing). */
    public static function applyBundle(array $b, ?string $dir = null): void
    {
        $sign = fn ($v) => is_string($v) && ($r = base64_decode($v, true)) !== false && strlen($r) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
        $box = fn ($v) => is_string($v) && ($r = base64_decode($v, true)) !== false && strlen($r) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES;
        if (($b['v'] ?? null) !== 1 || !$sign($b['push_pub'] ?? null) || !$sign($b['pull_pub'] ?? null) || !$box($b['inbox_pub'] ?? null)
            || !is_string($b['unlock_key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $b['unlock_key'])) {
            throw new RuntimeException('ชุดข้อมูลจับคู่ไม่ถูกต้อง');
        }
        $old = $b['unlock_old'] ?? [];
        if (!is_array($old) || count($old) > 20) throw new RuntimeException('ชุดข้อมูลจับคู่ไม่ถูกต้อง');
        foreach ($old as $k) if (!is_string($k) || !preg_match('/^[a-f0-9]{64}$/D', $k)) throw new RuntimeException('ชุดข้อมูลจับคู่ไม่ถูกต้อง');
        self::put('peer-push.pub', $b['push_pub'], $dir);
        self::put('peer-pull.pub', $b['pull_pub'], $dir);
        self::put('peer-inbox.pub', $b['inbox_pub'], $dir);
        self::put('peer-unlock.key', $b['unlock_key'], $dir);
        // the retired set is replaced as a whole (a bundle is the internal side's full current view)
        $base = $dir === null ? self::dir() : rtrim($dir, '/\\');
        foreach (glob($base.'/peer-unlock.old.*.key') ?: [] as $f) @unlink($f);
        foreach (array_unique($old) as $k) if ($k !== $b['unlock_key']) self::put('peer-unlock.old.'.substr(hash('sha256', $k), 0, 12).'.key', $k, $dir);
    }

    /**
     * Internal edition: replaces key pairs. push/pull/unlock files are replaced at once (the public side must be paired again with a new code);
     * the retired inbox secret stays on disk until the puller has drained every envelope sealed with it.
     * @param list<string> $which any of push, pull, inbox, unlock
     * @return list<string> what was rotated
     */
    public static function rotate(array $which): array
    {
        $done = [];
        foreach (array_unique($which) as $w) {
            switch ($w) {
                case 'push':
                    foreach (['push.secret', 'push.pub'] as $f) @unlink(self::dir().'/'.$f);
                    self::publicKey();
                    break;
                case 'pull':
                    foreach (['pull.secret', 'pull.pub'] as $f) @unlink(self::dir().'/'.$f);
                    self::pullPublic();
                    break;
                case 'inbox':
                    $kid = self::keyId(self::inboxPublic());
                    $cur = self::dir().'/inbox.secret';
                    if (!@rename($cur, self::dir().'/inbox.old.'.$kid.'.secret')) throw new RuntimeException('เก็บกุญแจ inbox เดิมไม่ได้');
                    @unlink(self::dir().'/inbox.pub');
                    self::inboxPublic();
                    break;
                case 'unlock':
                    $prev = \App\Support\Saan\HashKey::publicUnlock();
                    self::put('unlock.old.'.substr(hash('sha256', $prev), 0, 12).'.key', $prev);
                    self::put('unlock.key', bin2hex(random_bytes(32)));
                    break;
                default:
                    throw new \InvalidArgumentException('ชนิดกุญแจไม่รู้จัก: '.$w);
            }
            $done[] = $w;
        }
        return $done;
    }
}
