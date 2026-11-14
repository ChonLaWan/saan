<?php
/**
 * Generates a THROWAWAY Ed25519 signing key for CI / local build tests (REL-07).
 *   php tools/release/make-test-key.php <out.json>
 * The real release key is never created here and must never be stored in the repository or in CI.
 * Output format matches build-release.php: {"public_key": base64, "secret_key": base64, "test_only": true}
 */
declare(strict_types=1);

$out = $argv[1] ?? null;
if (!$out) { fwrite(STDERR, "usage: make-test-key.php <out.json>\n"); exit(2); }
if (!function_exists('sodium_crypto_sign_keypair')) { fwrite(STDERR, "ext-sodium missing\n"); exit(2); }
$pair = sodium_crypto_sign_keypair();
$json = json_encode([
    'test_only' => true,
    'created_at' => gmdate('c'),
    'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
    'secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($out, $json);
@chmod($out, 0600);
echo "test key written to $out (public ".substr(json_decode($json, true)['public_key'], 0, 12)."...)\n";
