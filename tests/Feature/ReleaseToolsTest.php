<?php

namespace Tests\Feature;

use Tests\TestCase;

/** tools/release: dev vendor refusal, test-key refusal, bootstrap/cache and *.tmp exclusion, secret scan, encrypted signing key. Runs the real scripts against a throw-away tree. */
class ReleaseToolsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saan-release-test-'.bin2hex(random_bytes(5));
        mkdir($this->dir.'/root/app', 0777, true);
        mkdir($this->dir.'/root/bootstrap/cache', 0777, true);
        mkdir($this->dir.'/root/config', 0777, true);
        mkdir($this->dir.'/root/vendor/composer', 0777, true);
        file_put_contents($this->dir.'/root/VERSION', "9.9.9\n");
        file_put_contents($this->dir.'/root/app/Hello.php', "<?php\n// harmless marker\n");
        file_put_contents($this->dir.'/root/bootstrap/cache/.gitignore', "*\n!.gitignore\n");
        file_put_contents($this->dir.'/root/bootstrap/cache/con1234.tmp', "<?php return ['key' => 'base64:".base64_encode(random_bytes(32))."'];");
        file_put_contents($this->dir.'/root/bootstrap/cache/config.php', '<?php return [];');
        file_put_contents($this->dir.'/root/app/ser1.saan-ab12cd34.tmp', 'leftover');
        file_put_contents($this->dir.'/root/config/saan.php', "<?php\nreturn ['release_public_key' => env('SAAN_RELEASE_PUBLIC_KEY', 'AAAA'),];\n");
        $this->vendor(false);
        $kp = sodium_crypto_sign_keypair();
        file_put_contents($this->dir.'/plain.json', json_encode(['public_key' => base64_encode(sodium_crypto_sign_publickey($kp)), 'secret_key' => base64_encode(sodium_crypto_sign_secretkey($kp))]));
        file_put_contents($this->dir.'/test.json', json_encode(['test_only' => true, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp)), 'secret_key' => base64_encode(sodium_crypto_sign_secretkey($kp))]));
    }

    protected function tearDown(): void
    {
        $this->rm($this->dir);
        parent::tearDown();
    }

    private function rm(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($d);
    }

    private function vendor(bool $dev): void
    {
        file_put_contents($this->dir.'/root/vendor/composer/installed.json', json_encode(['packages' => [], 'dev' => $dev, 'dev-package-names' => $dev ? ['phpunit/phpunit'] : []]));
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private function php(string $script, array $args, array $env = []): array
    {
        $cmd = escapeshellarg(PHP_BINARY).' -c '.escapeshellarg((string) php_ini_loaded_file()).' '.escapeshellarg(base_path('tools/release/'.$script)).' '.implode(' ', array_map('escapeshellarg', $args));
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv() ?: [], ['SAAN_RELEASE_ROOT' => $this->dir.'/root'], $env));
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($proc), $out];
    }

    private function entries(string $zip): array
    {
        $z = new \ZipArchive; $z->open($zip);
        $m = json_decode($z->getFromName('manifest.json'), true); $z->close();
        return array_column($m['files'], 'path');
    }

    public function test_build_refuses_dev_vendor_and_test_keys_and_excludes_cache_and_temp_files(): void
    {
        $out = $this->dir.'/out';
        $this->vendor(true);
        [$code, $log] = $this->php('build-release.php', [$out, $this->dir.'/plain.json']);
        $this->assertSame(2, $code, $log);
        $this->assertStringContainsString('dev packages', $log);
        $this->assertStringContainsString('[WARN] signing key is stored as plain JSON', $log);
        $this->vendor(false);
        [$code, $log] = $this->php('build-release.php', [$out, $this->dir.'/test.json']);
        $this->assertSame(2, $code, $log);
        $this->assertStringContainsString('test_only', $log);
        // allowed test key: the index key differs from the app's trusted default, so the self-verification rejects the build and removes the index
        [$code, $log] = $this->php('build-release.php', [$out, $this->dir.'/test.json', '--allow-test-key']);
        $this->assertSame(0, $code, $log);
        $zip = $out.'/saan-core-9.9.9.zip';
        $entries = $this->entries($zip);
        $this->assertContains('app/Hello.php', $entries);
        $this->assertContains('bootstrap/cache/.gitignore', $entries);
        $this->assertNotContains('bootstrap/cache/config.php', $entries);
        $this->assertSame([], array_values(array_filter($entries, fn ($p) => str_ends_with($p, '.tmp'))));
        $z = new \ZipArchive; $z->open($zip); $this->assertTrue((bool) json_decode($z->getFromName('manifest.json'), true)['test_signed']); $z->close();
        $this->assertFileExists($out.'/release-index.json');
    }

    public function test_verify_pins_the_apps_public_key_and_rejects_test_signed_dev_cache_and_secret_payloads(): void
    {
        $out = $this->dir.'/out';
        $this->php('build-release.php', [$out, $this->dir.'/test.json', '--allow-test-key']);
        // 1. no --public-key: the app's own key (AAAA in this fake tree) is trusted, so a different index key is an error
        [$code, $log] = $this->php('verify-release.php', [$out]);
        $this->assertSame(1, $code, $log);
        $this->assertStringContainsString('differs from the trusted key', $log);
        // 2. the right key but a test_signed package is refused without --allow-test-key
        $pub = json_decode((string) file_get_contents($this->dir.'/test.json'), true)['public_key'];
        [$code, $log] = $this->php('verify-release.php', [$out, '--public-key='.$pub]);
        $this->assertSame(1, $code, $log);
        $this->assertStringContainsString('test key', $log);
        [$code, $log] = $this->php('verify-release.php', [$out, '--public-key='.$pub, '--allow-test-key']);
        $this->assertSame(0, $code, $log);
        // 3. hand-made bad package: signed by a valid key but carrying bootstrap/cache, a dev package and key material
        $kp = json_decode((string) file_get_contents($this->dir.'/test.json'), true);
        $secret = base64_decode($kp['secret_key']);
        $bad = $this->dir.'/bad'; mkdir($bad);
        $files = ['bootstrap/cache/packages.php' => '<?php return [];', 'vendor/phpunit/phpunit/x.php' => '<?php', 'app/Dump.php' => '<?php return ["APP_KEY=base64:'.base64_encode(random_bytes(32)).'"];', 'app/Fine.php' => '<?php'];
        $manifest = ['type' => 'core', 'id' => 'core', 'version' => '9.9.9', 'files' => []];
        foreach ($files as $p => $c) $manifest['files'][] = ['path' => $p, 'bytes' => strlen($c), 'sha256' => hash('sha256', $c)];
        $json = json_encode($manifest);
        $z = new \ZipArchive; $z->open($bad.'/saan-core-9.9.9.zip', \ZipArchive::CREATE);
        $z->addFromString('manifest.json', $json); $z->addFromString('manifest.sig', base64_encode(sodium_crypto_sign_detached($json, $secret)));
        foreach ($files as $p => $c) $z->addFromString('payload/'.$p, $c);
        $z->close();
        file_put_contents($bad.'/release-index.json', json_encode(['version' => '9.9.9', 'public_key' => $pub]));
        [$code, $log] = $this->php('verify-release.php', [$bad, '--public-key='.$pub]);
        $this->assertSame(1, $code, $log);
        $this->assertStringContainsString('cache/temp file bootstrap/cache/packages.php', $log);
        $this->assertStringContainsString('dev package in vendor', $log);
        $this->assertStringContainsString('secret material in app/Dump.php', $log);
        $this->assertStringNotContainsString('app/Fine.php', $log);
    }

    public function test_encrypted_signing_key_round_trips_and_plain_key_still_works(): void
    {
        $pass = 'correct horse battery staple';
        [$code, $log] = $this->php('encrypt-key.php', [$this->dir.'/plain.json', $this->dir.'/enc.json'], ['SAAN_SIGNING_PASSPHRASE' => $pass]);
        $this->assertSame(0, $code, $log);
        $enc = json_decode((string) file_get_contents($this->dir.'/enc.json'), true);
        $this->assertSame('saan-key-v1', $enc['format']);
        $this->assertStringNotContainsString(json_decode((string) file_get_contents($this->dir.'/plain.json'), true)['secret_key'], (string) file_get_contents($this->dir.'/enc.json'));
        $out = $this->dir.'/out';
        [$code, $log] = $this->php('build-release.php', [$out, $this->dir.'/enc.json']);
        $this->assertSame(2, $code, $log); // no passphrase
        $this->assertStringContainsString('SAAN_SIGNING_PASSPHRASE', $log);
        [$code, $log] = $this->php('build-release.php', [$out, $this->dir.'/enc.json'], ['SAAN_SIGNING_PASSPHRASE' => 'wrong passphrase here']);
        $this->assertSame(2, $code, $log);
        $this->assertStringContainsString('wrong passphrase', $log);
        // right passphrase signs; the app's trusted key in the fake tree differs, so only the self-verification step rejects it (key loading itself worked)
        [$code, $log] = $this->php('build-release.php', [$out, $this->dir.'/enc.json'], ['SAAN_SIGNING_PASSPHRASE' => $pass]);
        $this->assertStringNotContainsString('[WARN] signing key is stored as plain JSON', $log);
        $this->assertStringContainsString('differs from the trusted key', $log);
        $this->assertSame(1, $code, $log);
        $this->assertFileDoesNotExist($out.'/release-index.json');
    }
    public function test_core_manifest_and_index_record_the_laravel_version_and_each_entry_its_type(): void
    {
        $out = $this->dir.'/out';
        // without composer.lock nothing is claimed (older trees, this fake root)
        [$code, $log] = $this->php('build-release.php', [$out, $this->dir.'/test.json', '--allow-test-key']);
        $this->assertSame(0, $code, $log);
        $z = new \ZipArchive; $z->open($out.'/saan-core-9.9.9.zip'); $m = json_decode($z->getFromName('manifest.json'), true); $z->close();
        $this->assertArrayNotHasKey('provides', $m);
        // with it, the framework version of THIS release is signed into the core manifest and listed in the index (the feed builder never opens a zip)
        file_put_contents($this->dir.'/root/composer.lock', json_encode(['packages' => [['name' => 'x/y', 'version' => '1.0.0'], ['name' => 'laravel/framework', 'version' => 'v13.45.1']]]));
        [$code, $log] = $this->php('build-release.php', [$out, $this->dir.'/test.json', '--allow-test-key']);
        $this->assertSame(0, $code, $log);
        $z = new \ZipArchive; $z->open($out.'/saan-core-9.9.9.zip'); $m = json_decode($z->getFromName('manifest.json'), true); $z->close();
        $this->assertSame(['laravel' => '13.45.1'], $m['provides']);
        $index = json_decode((string) file_get_contents($out.'/release-index.json'), true);
        $core = collect($index['packages'])->firstWhere('zip', 'saan-core-9.9.9.zip');
        $this->assertSame(['laravel' => '13.45.1'], $core['provides']);
        $this->assertSame(['core', 'core', '9.9.9'], [$core['type'], $core['id'], $core['version']]);
        $this->assertSame('>=8.4.1', $core['requires']['php']);
        $this->assertSame(hash_file('sha256', $out.'/saan-core-9.9.9.zip'), $core['sha256']);
    }
}
