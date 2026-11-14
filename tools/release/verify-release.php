<?php
/**
 * Verifies release packages produced by build-release.php (REL-07):
 *   php tools/release/verify-release.php <out-dir> [--public-key=<b64> | --key-file=<json>] [--allow-test-key]
 * For every saan-*.zip: manifest.sig is a valid Ed25519 signature over manifest.json for the public key in
 * release-index.json, and every payload file exists with the recorded size and SHA-256. Also checks that no
 * .env, key, database, log, archive or temp file, no bootstrap/cache file, no dev vendor package and no file carrying
 * a config dump / key material is inside a package.
 * The trusted key is the one given on the command line, else the release_public_key default in config/saan.php (the key every
 * school trusts); a different key in the index is an error (exit 1). test_signed packages are refused without --allow-test-key.
 * Exit 0 = ok, 1 = failure.
 */
declare(strict_types=1);

$dir = $argv[1] ?? null;
if (!$dir || !is_file($dir.'/release-index.json')) { fwrite(STDERR, "usage: verify-release.php <out-dir with release-index.json>\n"); exit(2); }
$index = json_decode((string) file_get_contents($dir.'/release-index.json'), true);
$trusted = null;
foreach (array_slice($argv, 2) as $a) {
    if (str_starts_with($a, '--public-key=')) $trusted = substr($a, 13);
    elseif (str_starts_with($a, '--key-file=')) { $k = json_decode((string) @file_get_contents(substr($a, 11)), true); $trusted = is_array($k) ? ($k['public_key'] ?? null) : null; if (!$trusted) { fwrite(STDERR, "cannot read public_key from key file\n"); exit(2); } }
}
$allowTest = in_array('--allow-test-key', $argv, true);
// no key given: trust the one the app itself ships (config/saan.php), so a package signed with any other key is caught before publishing
if ($trusted === null) {
    $cfg = (string) @file_get_contents((getenv('SAAN_RELEASE_ROOT') ?: __DIR__.'/../..').'/config/saan.php');
    if (preg_match("~'release_public_key'\s*=>\s*env\('SAAN_RELEASE_PUBLIC_KEY',\s*'([^']+)'\)~", $cfg, $mm)) $trusted = $mm[1];
    else { fwrite(STDERR, "[ERR] no --public-key/--key-file and config/saan.php has no release_public_key default\n"); exit(2); }
}
// the trusted key must match the one in the index
if (!hash_equals((string) $trusted, (string) ($index['public_key'] ?? ''))) { fwrite(STDERR, "[ERR] index public_key differs from the trusted key: schools would reject these packages\n"); exit(1); }
if (!empty($index['test_signed']) && !$allowTest) { fwrite(STDERR, "[ERR] packages are signed with a test key (use --allow-test-key only in CI)\n"); exit(1); }
$pub = base64_decode((string) ($index['public_key'] ?? ''), true);
if (!$pub || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) { fwrite(STDERR, "bad public key in index\n"); exit(1); }

$bad = 0;
$zips = glob($dir.'/saan-*.zip') ?: [];
if (!$zips) { fwrite(STDERR, "no packages\n"); exit(1); }
foreach ($zips as $path) {
    $name = basename($path);
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) { echo "[ERR] $name: cannot open\n"; $bad++; continue; }
    $json = (string) $zip->getFromName('manifest.json');
    $sig = base64_decode((string) $zip->getFromName('manifest.sig'), true);
    if (!$sig || !sodium_crypto_sign_verify_detached($sig, $json, $pub)) { echo "[ERR] $name: signature invalid\n"; $bad++; $zip->close(); continue; }
    $m = json_decode($json, true);
    $problems = 0;
    if (!empty($m['test_signed']) && !$allowTest) { echo "[ERR] $name: test_signed package\n"; $problems++; }
    foreach ($m['files'] as $f) {
        $data = $zip->getFromName('payload/'.$f['path']);
        if ($data === false) { echo "[ERR] $name: missing {$f['path']}\n"; $problems++; continue; }
        if (strlen($data) !== $f['bytes'] || hash('sha256', $data) !== $f['sha256']) { echo "[ERR] $name: hash/size mismatch {$f['path']}\n"; $problems++; }
        if (preg_match('~(^|/)\.env(\..*)?$~', $f['path']) && !str_ends_with($f['path'], '.example')) { echo "[ERR] $name: env file {$f['path']}\n"; $problems++; }
        if (preg_match('~\.(sqlite3?|sqlite-[a-z]+|db|sql|dump|ppk|pem|key|p12|pfx|jks|kdbx|zip|bak|log)$~i', $f['path'])) { echo "[ERR] $name: forbidden file type {$f['path']}\n"; $problems++; }
        if (preg_match('~^(storage/(?!.*\.gitignore$)|tests/|\.git/|\.github/)~', $f['path'])) { echo "[ERR] $name: should not ship {$f['path']}\n"; $problems++; }
        if (preg_match('~^bootstrap/cache/(?!\.gitignore$)~', $f['path']) || preg_match('~\.tmp$~i', $f['path'])) { echo "[ERR] $name: cache/temp file {$f['path']}\n"; $problems++; }
        if (preg_match('~^vendor/(phpunit/|mockery/|filp/whoops/|fakerphp/|laravel/pail/|nunomaduro/collision/|laravel/pint/)~', $f['path'])) { echo "[ERR] $name: dev package in vendor {$f['path']}\n"; $problems++; }
        // a config dump or key material, whatever the file is called (bootstrap/cache leftovers, copied .env, key json)
        if ($f['bytes'] <= 2097152 && !str_starts_with($f['path'], 'vendor/') && preg_match('~(APP_KEY=base64:[A-Za-z0-9+/=]{40,}|[\'"]key[\'"]\s*=>\s*[\'"]base64:[A-Za-z0-9+/=]{40,}|"secret_key"\s*:\s*"[A-Za-z0-9+/=]{60,}|DB_PASSWORD=[^\s"\'$<{(&]+)~', $data)) { echo "[ERR] $name: secret material in {$f['path']}\n"; $problems++; }
    }
    if ($zip->numFiles !== count($m['files']) + 2) { echo "[ERR] $name: archive has entries not listed in the manifest\n"; $problems++; }
    $zip->close();
    echo $problems ? "[ERR] $name: $problems problem(s)\n" : "[OK] $name: ".count($m['files'])." files, signature valid\n";
    $bad += $problems ? 1 : 0;
}
exit($bad ? 1 : 0);
