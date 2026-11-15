<?php
/**
 * Saan release builder (REL-01). Run from the app root with the APM/CI PHP:
 *   php tools/release/build-release.php <out-dir> <signing-key.json> [--vendor-from=<dir>] [--allow-dev] [--allow-test-key]
 * The key file is plain JSON (readable, with a [WARN]) or encrypted with tools/release/encrypt-key.php (passphrase in SAAN_SIGNING_PASSPHRASE).
 * vendor/ must be a --no-dev install (composer install --no-dev ...); the build refuses a vendor tree with dev packages unless --allow-dev.
 * A test_only key is refused unless --allow-test-key (CI). The finished packages are verified (verify-release.php) before release-index.json is kept.
 * Produces, for the version in VERSION and in each separate module.json:
 *   saan-core-<version>.zip                 app code + vendor (no .env, storage data, review folders)
 *   saan-mod-<id>-<version>.zip             modules/<id> + public/assets/modules/<id>
 * Every zip carries manifest.json (files with bytes + SHA-256, requires) and manifest.sig (Ed25519 over manifest.json).
 */
declare(strict_types=1);

require __DIR__.'/keyfile.php';

$root = realpath(getenv('SAAN_RELEASE_ROOT') ?: __DIR__.'/../..'); // SAAN_RELEASE_ROOT is for the test suite only
[$_, $out, $keyFile] = $argv + [null, null, null];
if (!$out || !$keyFile) { fwrite(STDERR, "usage: build-release.php <out-dir> <key.json> [--vendor-from=dir] [--allow-dev] [--allow-test-key]\n"); exit(2); }
$vendorFrom = null; foreach ($argv as $a) if (str_starts_with($a, '--vendor-from=')) $vendorFrom = substr($a, 14);
$allowDev = in_array('--allow-dev', $argv, true);
$allowTestKey = in_array('--allow-test-key', $argv, true);
try { $key = load_signing_key($keyFile); } catch (RuntimeException $e) { fwrite(STDERR, '[ERR] '.$e->getMessage()."\n"); exit(2); }
$secret = base64_decode($key['secret_key'], true);
if (!$secret || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { fwrite(STDERR, "bad signing key\n"); exit(2); }
if ($key['test_only'] && !$allowTestKey) { fwrite(STDERR, "[ERR] this is a test_only key: packages signed with it are rejected by every school. Use the real key, or pass --allow-test-key (CI only).\n"); exit(2); }
$testSigned = (bool) $key['test_only'];
if (!is_dir($out)) mkdir($out, 0700, true);
$version = trim((string) file_get_contents($root.'/VERSION'));

$excludeRe = '~^(\.env(\..*)?$|\.git/|\.github/|node_modules/|storage/|tests/|tools/release/|bootstrap/cache/(?!\.gitignore$)|public/storage|public/(cms-demo|cms-easy|cms-release|compact-review|design|implementation|mockup-review|navigation-review|presentation-review|student-registry-design)/|resources/install/library/|docs/manual/|\.phpunit|phpunit\.xml|\.editorconfig|\.gitattributes|\.gitignore)~';
$separate = [];
foreach (glob($root.'/modules/*/module.json') as $mf) { $m = json_decode((string) file_get_contents($mf), true); if (($m['package'] ?? '') === 'separate') $separate[$m['id']] = $m; }

function files(string $base, string $prefix): array
{
    $list = []; $dir = $base.'/'.$prefix;
    if (is_file($dir)) return [$prefix];
    if (!is_dir($dir)) return [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile() && !$f->isLink()) $list[] = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
    sort($list); return $list;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

function pack_zip(string $zipPath, array $entries, array $meta, string $secret): array
{
    // entries: [relativePath => sourceFile]. Every file is copied ONCE into a private staging dir; the manifest hash and the zip both come from that copy,
    // so a file edited in the working tree while the build runs can never make the signed manifest disagree with the payload.
    $stage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saan-stage-'.bin2hex(random_bytes(6));
    if (!mkdir($stage, 0700, true)) throw new RuntimeException('cannot create staging dir');
    try {
        $manifest = $meta + ['created_at' => gmdate('c'), 'files' => []];
        $staged = [];
        foreach ($entries as $rel => $src) {
            $dst = $stage.DIRECTORY_SEPARATOR.count($staged);
            if (!@copy($src, $dst)) throw new RuntimeException('cannot read '.$src);
            $staged[$rel] = $dst;
            $manifest['files'][] = ['path' => $rel, 'bytes' => filesize($dst), 'sha256' => hash_file('sha256', $dst)];
        }
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sig = base64_encode(sodium_crypto_sign_detached($json, $secret));
        @unlink($zipPath); $zip = new ZipArchive; if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('cannot create '.$zipPath);
        $zip->addFromString('manifest.json', $json); $zip->addFromString('manifest.sig', $sig);
        foreach ($staged as $rel => $dst) if (!$zip->addFile($dst, 'payload/'.$rel)) throw new RuntimeException('cannot add '.$rel);
        if (!$zip->close()) { @unlink($zipPath); throw new RuntimeException('closing '.basename($zipPath).' failed (a file vanished or the disk is full)'); }
    } finally { rrmdir($stage); }
    return ['zip' => basename($zipPath), 'files' => count($entries), 'bytes' => filesize($zipPath), 'sha256' => hash_file('sha256', $zipPath)];
}

$results = [];
// core
$entries = [];
foreach (['app', 'bootstrap', 'config', 'database', 'docs', 'installer', 'lang', 'public', 'resources', 'routes', 'modules', 'artisan', 'composer.json', 'composer.lock', 'VERSION', 'install.php', 'README.md', 'LICENSE', 'SECURITY.md', 'CONTRIBUTING.md', 'CHANGELOG.md', 'RUNBOOK.txt', 'THIRD-PARTY-NOTICES.txt', '.env.example'] as $prefix) {
    foreach (files($root, $prefix) as $rel) {
        if (preg_match($excludeRe, $rel) && !in_array($rel, ['.env.example'], true)) continue;
        if (preg_match('~\.(sqlite(-[a-z]+)?|ppk|pem|key|p12|pfx|zip|bak|log|tmp)$~i', $rel)) continue; // never ship databases, keys, archives, logs or temp files (config:cache / Filesystem::replace leftovers hold secrets)
        foreach (array_keys($separate) as $id) if (str_starts_with($rel, 'modules/'.$id.'/') || str_starts_with($rel, 'public/assets/modules/'.$id.'/')) continue 2;
        $entries[$rel] = $root.'/'.$rel;
    }
}
// empty storage skeleton (only .gitignore files) so a fresh extract boots; the updater never overwrites storage
foreach (files($root, 'storage') as $rel) if (basename($rel) === '.gitignore') $entries[$rel] = $root.'/'.$rel;
$vendorBase = $vendorFrom ?: $root;
// the signed package must carry a production vendor tree: dev packages (phpunit, mockery, pail, collision, ...) would reach every school
$installed = @file_get_contents($vendorBase.'/vendor/composer/installed.json');
$installedJson = $installed === false ? null : json_decode($installed, true);
if (!is_array($installedJson)) { fwrite(STDERR, "[ERR] $vendorBase/vendor/composer/installed.json not found: run composer install --no-dev first (or --vendor-from=<dir of a --no-dev install>)\n"); exit(2); }
if (($installedJson['dev'] ?? false) === true || !empty($installedJson['dev-package-names'] ?? [])) {
    if (!$allowDev) {
        fwrite(STDERR, "[ERR] vendor/ contains dev packages (".implode(', ', array_slice((array) ($installedJson['dev-package-names'] ?? []), 0, 8))."...).\n"
            ."      Build a production tree and point at it:  composer install --no-dev --optimize-autoloader --working-dir=<staging copy>  then  --vendor-from=<staging copy>\n"
            ."      (or pass --allow-dev if you really mean it)\n");
        exit(2);
    }
    fwrite(STDERR, "[WARN] --allow-dev: shipping vendor/ with dev packages\n");
}
foreach (files($vendorBase, 'vendor') as $rel) $entries[$rel] = $vendorBase.'/'.$rel;
// requirements the installed (older) updater checks BEFORE it touches a school: PHP, the PHP extensions of composer.json, and optionally the
// oldest release this one can be reached from in one step (--min-from=<version>, for a release whose migrations assume an earlier stepping stone)
$composer = json_decode((string) @file_get_contents($root.'/composer.json'), true) ?: [];
$extensions = []; foreach (array_keys((array) ($composer['require'] ?? [])) as $name) if (str_starts_with((string) $name, 'ext-')) $extensions[] = substr((string) $name, 4);
sort($extensions);
$requires = ['php' => '>=8.4.1', 'extensions' => $extensions];
foreach ($argv as $a) if (str_starts_with($a, '--min-from=')) { $from = substr($a, 11); if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $from)) { fwrite(STDERR, "[ERR] --min-from needs a version like 0.7.0\n"); exit(2); } $requires['from'] = $from; }
// what the core brings along that an administrator wants to see before installing: the laravel/framework version of this release (from composer.lock)
$provides = [];
$lock = json_decode((string) @file_get_contents($root.'/composer.lock'), true);
foreach ((array) ($lock['packages'] ?? []) as $pkg) if (($pkg['name'] ?? '') === 'laravel/framework' && preg_match('/^v?(\d+\.\d+\.\d+)$/', (string) ($pkg['version'] ?? ''), $lv)) $provides['laravel'] = $lv[1];
$coreMeta = ['type' => 'core', 'id' => 'core', 'version' => $version, 'requires' => $requires] + ($provides ? ['provides' => $provides] : []);
$results[] = pack_zip($out.'/saan-core-'.$version.'.zip', $entries, $coreMeta + ($testSigned ? ['test_signed' => true] : []), $secret) + $coreMeta;
// separate modules
foreach ($separate as $id => $m) {
    $entries = [];
    foreach (array_merge(files($root, 'modules/'.$id), files($root, 'public/assets/modules/'.$id)) as $rel) { if (preg_match('~\.(tmp|sqlite3?|ppk|pem|key|p12|pfx|zip|bak|log)$~i', $rel)) continue; $entries[$rel] = $root.'/'.$rel; }
    $modMeta = ['type' => 'module', 'id' => $id, 'version' => $m['version'], 'requires' => $m['requires']];
    $results[] = pack_zip($out.'/saan-mod-'.$id.'-'.$m['version'].'.zip', $entries, $modMeta + ($testSigned ? ['test_signed' => true] : []), $secret) + $modMeta;
}
file_put_contents($out.'/release-index.json', json_encode(['version' => $version, 'packages' => $results, 'public_key' => $key['public_key']] + ($testSigned ? ['test_signed' => true] : []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
// verify what was just written before anyone can publish it; a failure removes the index
$cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/verify-release.php').' '.escapeshellarg($out).($testSigned ? ' '.escapeshellarg('--public-key='.$key['public_key']).' --allow-test-key' : ''); // a real key is checked against the key the app trusts (config/saan.php)
passthru($cmd, $code);
if ($code !== 0) { @unlink($out.'/release-index.json'); fwrite(STDERR, "[ERR] verify-release failed: release-index.json removed, do not publish these packages\n"); exit(1); }
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
