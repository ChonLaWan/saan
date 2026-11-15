<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** tools/release/check-upstream.php fed with fixture files only (no network, no composer). */
class CheckUpstreamTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/tools/release/check-upstream.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/saan-upstream-'.bin2hex(random_bytes(4)); mkdir($this->dir);
        file_put_contents($this->dir.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.42.0'], ['name' => 'other/pkg', 'version' => '1.0.0']]]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    private function packagist(array $versions): string
    {
        $f = $this->dir.'/packagist.json';
        file_put_contents($f, json_encode(['packages' => ['laravel/framework' => array_map(fn ($v) => ['version' => $v], $versions)]]));
        return $f;
    }

    /** @return array{0:int,1:string} */
    private function check(array $extra): array
    {
        $out = [];
        $code = check_upstream(array_merge(['check-upstream.php', '--lock='.$this->dir.'/composer.lock', '--no-composer'], $extra), function (string $m) use (&$out) { $out[] = $m; }, $this->dir);
        return [$code, implode("\n", $out)];
    }

    public function test_a_newer_patch_release_inside_the_locked_line_means_a_saan_patch_is_due(): void
    {
        [$code, $out] = $this->check(['--packagist='.$this->packagist(['v13.42.0', 'v13.42.3', 'v13.42.1', 'v13.45.1', 'v13.45.1-RC1', 'dev-master'])]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[OK] locked laravel/framework 13.42.0', $out);
        $this->assertStringContainsString('[WARN] laravel/framework 13.42.0 -> 13.42.3', $out);
        $this->assertStringContainsString('newest laravel/framework release is 13.45.1', $out);
    }

    public function test_nothing_newer_in_the_line_is_ok_and_a_newer_minor_only_warns(): void
    {
        [$code, $out] = $this->check(['--packagist='.$this->packagist(['v13.42.0', 'v13.41.9'])]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('[OK] no newer laravel/framework release inside the line 13.42', $out);
        [$code, $out] = $this->check(['--packagist='.$this->packagist(['v13.42.0', 'v13.45.1', 'v14.0.0'])]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('newest laravel/framework release is 14.0.0', $out);
        $this->assertSame(['patch' => null, 'newest' => '14.0.0'], upstream_newer('13.42.0', ['14.0.0', '13.45.1', '13.42.0']));
        $this->assertSame(['patch' => '13.42.7', 'newest' => '13.45.1'], upstream_newer('13.42.0', ['13.45.1', '13.42.7', '13.42.1']));
    }

    public function test_an_advisory_exits_1_and_offline_only_warns(): void
    {
        file_put_contents($this->dir.'/audit.json', json_encode(['advisories' => ['laravel/framework' => [['advisoryId' => 'PKSA-test', 'cve' => 'CVE-0000-0000', 'title' => 'harmless test advisory', 'affectedVersions' => '<13.42.1']]]]));
        [$code, $out] = $this->check(['--audit='.$this->dir.'/audit.json', '--packagist='.$this->packagist(['v13.42.0'])]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[WARN] advisory laravel/framework: PKSA-test CVE-0000-0000', $out);
        file_put_contents($this->dir.'/clean.json', json_encode(['advisories' => []]));
        [$code, $out] = $this->check(['--audit='.$this->dir.'/clean.json', '--packagist='.$this->packagist(['v13.42.0'])]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('[OK] composer audit: no advisory', $out);
        // offline: Packagist gives nothing (a missing fixture stands for an unreachable host)
        [$code, $out] = $this->check(['--packagist='.$this->dir.'/missing.json']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('[WARN] Packagist could not be reached', $out);
        // no laravel/framework in the lock: a usage error, not a pass
        file_put_contents($this->dir.'/composer.lock', json_encode(['packages' => []]));
        [$code, $out] = $this->check(['--packagist='.$this->packagist(['v13.42.0'])]);
        $this->assertSame(2, $code);
    }
}
