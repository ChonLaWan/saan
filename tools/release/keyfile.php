<?php
/**
 * Signing key file helpers shared by build-release.php and encrypt-key.php.
 *
 * Two formats:
 *  - plain JSON  {"public_key": b64, "secret_key": b64, "test_only"?: true}   still readable, with a [WARN]
 *  - encrypted   {"format": "saan-key-v1", "kdf": "argon2id13", "opslimit": n, "memlimit": n, "salt": b64, "nonce": b64,
 *                 "ciphertext": b64, "public_key": b64, "test_only"?: true}
 *    ciphertext = sodium secretbox of the base64 secret key; the secretbox key = sodium_crypto_pwhash(passphrase, salt).
 *    The passphrase comes from env SAAN_SIGNING_PASSPHRASE, or from the first line of the file named by SAAN_SIGNING_PASSPHRASE_FILE. It is never a CLI argument.
 */
declare(strict_types=1);

function key_passphrase(): ?string
{
    $p = getenv('SAAN_SIGNING_PASSPHRASE');
    if (is_string($p) && $p !== '') return $p;
    $f = getenv('SAAN_SIGNING_PASSPHRASE_FILE');
    if (is_string($f) && $f !== '' && is_file($f)) {
        $line = rtrim((string) strtok((string) file_get_contents($f), "\r\n"));
        if ($line !== '') return $line;
    }
    return null;
}

/** @return array{public_key:string,secret_key:string,test_only:bool,encrypted:bool} or throws RuntimeException */
function load_signing_key(string $file, ?callable $warn = null): array
{
    $warn ??= fn (string $m) => fwrite(STDERR, $m."\n");
    $raw = @file_get_contents($file);
    $j = $raw === false ? null : json_decode($raw, true);
    if (!is_array($j)) throw new RuntimeException('cannot read signing key file');
    if (($j['format'] ?? null) === 'saan-key-v1') {
        $pass = key_passphrase();
        if ($pass === null) throw new RuntimeException('encrypted key: set SAAN_SIGNING_PASSPHRASE (or SAAN_SIGNING_PASSPHRASE_FILE)');
        $salt = base64_decode((string) ($j['salt'] ?? ''), true); $nonce = base64_decode((string) ($j['nonce'] ?? ''), true); $box = base64_decode((string) ($j['ciphertext'] ?? ''), true);
        if (!$salt || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || !$nonce || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || !$box) throw new RuntimeException('encrypted key file is damaged');
        $ops = (int) ($j['opslimit'] ?? 0); $mem = (int) ($j['memlimit'] ?? 0);
        if ($ops < SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE || $mem < SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE) throw new RuntimeException('encrypted key uses weak KDF limits, re-encrypt it');
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $pass, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) throw new RuntimeException('wrong passphrase or damaged encrypted key');
        return ['public_key' => (string) ($j['public_key'] ?? ''), 'secret_key' => $plain, 'test_only' => !empty($j['test_only']), 'encrypted' => true];
    }
    if (!isset($j['secret_key'])) throw new RuntimeException('key file has no secret_key');
    $warn('[WARN] signing key is stored as plain JSON: encrypt it with tools/release/encrypt-key.php (passphrase from SAAN_SIGNING_PASSPHRASE) and delete the plain file');
    return ['public_key' => (string) ($j['public_key'] ?? ''), 'secret_key' => (string) $j['secret_key'], 'test_only' => !empty($j['test_only']), 'encrypted' => false];
}

/** Encrypts a plain key array into the saan-key-v1 structure. */
function encrypt_signing_key(array $plain, string $passphrase): array
{
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES); $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE; $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
    $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $box = sodium_crypto_secretbox((string) $plain['secret_key'], $nonce, $key);
    sodium_memzero($key);
    $out = ['format' => 'saan-key-v1', 'kdf' => 'argon2id13', 'opslimit' => $ops, 'memlimit' => $mem, 'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce),
        'ciphertext' => base64_encode($box), 'public_key' => (string) $plain['public_key'], 'created_at' => gmdate('c')];
    if (!empty($plain['test_only'])) $out['test_only'] = true;
    return $out;
}