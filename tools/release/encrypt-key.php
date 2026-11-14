<?php
/**
 * Encrypts a plain release signing key (JSON with secret_key) with a passphrase (sodium argon2id + secretbox).
 *   SAAN_SIGNING_PASSPHRASE=... php tools/release/encrypt-key.php <plain-key.json> <encrypted-key.json>
 * The passphrase comes from the environment (or SAAN_SIGNING_PASSPHRASE_FILE), never from an argument, so it stays out of the process list and shell history.
 * build-release.php reads the encrypted file with the same variable. Afterwards keep the plain file offline or delete it.
 */
declare(strict_types=1);

require __DIR__.'/keyfile.php';

[$_, $in, $out] = $argv + [null, null, null];
if (!$in || !$out) { fwrite(STDERR, "usage: encrypt-key.php <plain-key.json> <encrypted-key.json>  (passphrase in SAAN_SIGNING_PASSPHRASE)\n"); exit(2); }
if (!function_exists('sodium_crypto_pwhash')) { fwrite(STDERR, "ext-sodium missing\n"); exit(2); }
if (is_file($out)) { fwrite(STDERR, "refusing to overwrite $out\n"); exit(2); }
$pass = key_passphrase();
if ($pass === null || strlen($pass) < 12) { fwrite(STDERR, "set SAAN_SIGNING_PASSPHRASE (at least 12 characters)\n"); exit(2); }
try { $plain = load_signing_key($in, fn () => null); } catch (RuntimeException $e) { fwrite(STDERR, '[ERR] '.$e->getMessage()."\n"); exit(2); }
if ($plain['encrypted']) { fwrite(STDERR, "input is already encrypted\n"); exit(2); }
$secret = base64_decode($plain['secret_key'], true);
if (!$secret || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { fwrite(STDERR, "bad secret_key in input\n"); exit(2); }
file_put_contents($out, json_encode(encrypt_signing_key($plain, $pass), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@chmod($out, 0600);
// prove it opens before telling the operator it is safe to retire the plain file
try { $check = load_signing_key($out); } catch (RuntimeException $e) { @unlink($out); fwrite(STDERR, '[ERR] self-check failed: '.$e->getMessage()."\n"); exit(1); }
if (!hash_equals($plain['secret_key'], $check['secret_key'])) { @unlink($out); fwrite(STDERR, "[ERR] self-check mismatch\n"); exit(1); }
echo "[OK] encrypted key written to $out (public ".substr($plain['public_key'], 0, 12)."...). Delete or move the plain file offline.\n";