<?php
/**
 * Maintainer check (not shipped): is a patch release of Saan due because of the framework underneath it?
 *   php tools/release/check-upstream.php [--lock=composer.lock] [--packagist=<url or json file>] [--audit=<composer audit json file>] [--no-composer]
 * 1. reads laravel/framework from composer.lock (the version every school gets with the next release);
 * 2. runs `composer audit --locked --format=json` when composer is available (or reads --audit=<file>), prints every advisory;
 * 3. asks Packagist (plain GET of https://repo.packagist.org/p2/laravel/framework.json, nothing identifying is sent) for newer releases:
 *    a newer PATCH release inside the locked major.minor, and the newest release of any minor.
 * Output is [OK]/[WARN] lines. Exit 1 when an advisory exists or a newer patch release of the framework is out (a Saan patch release is due);
 * 0 otherwise. Offline (Packagist or composer unreachable): [WARN] and exit 0 with a clear line, never a failure.
 */
declare(strict_types=1);

/** Highest-first list of final versions ("13.45.1") found in Packagist's p2 metadata. @return list<string> */
function upstream_versions(array $json): array
{
    $out = [];
    foreach ((array) ($json['packages']['laravel/framework'] ?? []) as $r) {
        $v = ltrim((string) ($r['version'] ?? ''), 'vV');
        if (preg_match('/^\d+\.\d+\.\d+$/D', $v)) $out[$v] = true;
    }
    $list = array_keys($out);
    usort($list, 'version_compare');
    return array_reverse($list);
}

/** @return array{patch:?string,newest:?string} newest patch release inside the locked major.minor, newest final release of all */
function upstream_newer(string $locked, array $versions): array
{
    [$maj, $min] = array_map('intval', explode('.', $locked));
    $patch = null; $newest = null;
    foreach ($versions as $v) { // highest first
        if (version_compare($v, $locked, '<=')) break;
        $newest ??= $v;
        [$a, $b] = array_map('intval', explode('.', $v));
        if ($a === $maj && $b === $min) { $patch = $v; break; }
    }
    return ['patch' => $patch, 'newest' => $newest];
}

/** @return list<string> advisory lines of a `composer audit --format=json` document */
function upstream_advisories(array $audit): array
{
    $out = [];
    foreach ((array) ($audit['advisories'] ?? []) as $pkg => $list) {
        foreach ((array) $list as $a) $out[] = $pkg.': '.($a['advisoryId'] ?? '?').' '.($a['cve'] ?? '').' '.($a['title'] ?? '').' '.($a['affectedVersions'] ?? '');
    }
    return $out;
}

function upstream_fetch(string $url): ?string
{
    if (!str_starts_with($url, 'https://')) return is_file($url) ? (string) file_get_contents($url) : null; // a fixture file
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 15, 'header' => "Accept: application/json\r\nUser-Agent: saan-release-check\r\n", 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) && $body !== '' ? $body : null;
}

/** @param list<string> $argv @param callable(string):void $say */
function check_upstream(array $argv, callable $say, string $root): int
{
    $opt = ['lock' => $root.'/composer.lock', 'packagist' => 'https://repo.packagist.org/p2/laravel/framework.json', 'audit' => null];
    foreach (array_slice($argv, 1) as $a) if (preg_match('/^--(lock|packagist|audit)=(.+)$/s', $a, $m)) $opt[$m[1]] = $m[2];
    $lock = json_decode((string) @file_get_contents($opt['lock']), true);
    $locked = null;
    foreach ((array) ($lock['packages'] ?? []) as $p) if (($p['name'] ?? '') === 'laravel/framework') $locked = ltrim((string) ($p['version'] ?? ''), 'vV');
    if (!is_string($locked) || !preg_match('/^\d+\.\d+\.\d+$/D', $locked)) { $say('[ERR] laravel/framework not found in '.$opt['lock']); return 2; }
    $say('[OK] locked laravel/framework '.$locked);
    $due = false;

    // 1. advisories
    $audit = null;
    if ($opt['audit'] !== null) $audit = json_decode((string) @file_get_contents($opt['audit']), true);
    elseif (!in_array('--no-composer', $argv, true)) {
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        @exec('composer --version 2>'.$null, $o, $c);
        if (($c ?? 1) === 0) {
            $out = []; @exec('cd '.escapeshellarg($root).' && composer audit --locked --format=json --no-interaction 2>'.$null, $out, $code);
            $audit = json_decode(implode("\n", $out), true);
            if (!is_array($audit)) $say('[WARN] composer audit gave no readable answer (offline?): advisories not checked');
        } else $say('[WARN] composer is not available here: advisories not checked (run composer audit --locked elsewhere)');
    }
    if (is_array($audit)) {
        $adv = upstream_advisories($audit);
        foreach ($adv as $line) { $say('[WARN] advisory '.trim($line)); $due = true; }
        if (!$adv) $say('[OK] composer audit: no advisory for the locked packages');
    }

    // 2. newer framework releases
    $body = upstream_fetch($opt['packagist']);
    $json = $body !== null ? json_decode($body, true) : null;
    if (!is_array($json) || !upstream_versions($json)) {
        $say('[WARN] Packagist could not be reached or answered nothing usable (offline?): newer framework releases not checked');
    } else {
        $n = upstream_newer($locked, upstream_versions($json));
        if ($n['patch'] !== null) { $say('[WARN] laravel/framework '.$locked.' -> '.$n['patch'].' is out inside the locked line: a Saan patch release is due'); $due = true; }
        else $say('[OK] no newer laravel/framework release inside the line '.implode('.', array_slice(explode('.', $locked), 0, 2)));
        if ($n['newest'] !== null && $n['newest'] !== $n['patch']) $say('[WARN] newest laravel/framework release is '.$n['newest'].' (a newer minor or major: plan it as a normal Saan release, schools never get it automatically)');
    }
    return $due ? 1 : 0;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(check_upstream($argv, static function (string $m): void { echo $m, "\n"; }, realpath(__DIR__.'/../..') ?: getcwd()));
}
