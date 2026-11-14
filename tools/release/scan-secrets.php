<?php
/**
 * Saan secret / PII scan (REL-07). Scans the tree that would be committed (honours .gitignore, including nested ones;
 * works without git installed). Run from anywhere with the CI/APM PHP:
 *   php tools/release/scan-secrets.php [--root=<dir>] [--json=<file>] [--fail] [--max-bytes=1048576] [--allow=<file>] [--all-lines] [--list]
 * Exit code: 0 clean, 1 findings of severity "high" (only with --fail), 2 usage error.
 * Findings never print full secrets or full Thai ID numbers (masked).
 * Allow file (default tools/release/scan-secrets.allow, optional): one rule per line "<category>:<path-glob>", # comments.
 */
declare(strict_types=1);

$opt = ['root' => realpath(__DIR__.'/../..'), 'json' => null, 'fail' => false, 'max-bytes' => 1048576, 'allow' => __DIR__.'/scan-secrets.allow', 'all-lines' => false, 'list' => false];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--fail') { $opt['fail'] = true; continue; }
    if ($a === '--list') { $opt['list'] = true; continue; }
    if ($a === '--all-lines') { $opt['all-lines'] = true; continue; }
    if (preg_match('~^--(root|json|max-bytes|allow)=(.+)$~', $a, $m)) { $opt[$m[1]] = $m[1] === 'max-bytes' ? (int) $m[2] : $m[2]; continue; }
    fwrite(STDERR, "unknown option $a\n"); exit(2);
}
$root = realpath((string) $opt['root']);
if (!$root || !is_dir($root)) { fwrite(STDERR, "bad root\n"); exit(2); }

// ---------------------------------------------------------------- gitignore
function ignore_rules(string $dirAbs, string $baseRel): array
{
    $f = $dirAbs.'/.gitignore';
    if (!is_file($f)) return [];
    $rules = [];
    foreach (preg_split('~\R~', (string) file_get_contents($f)) as $line) {
        $line = rtrim($line);
        if ($line === '' || $line[0] === '#') continue;
        $neg = false;
        if ($line[0] === '!') { $neg = true; $line = substr($line, 1); }
        $dirOnly = str_ends_with($line, '/');
        $line = rtrim($line, '/');
        $anchored = str_contains($line, '/');
        $line = ltrim($line, '/');
        $re = '';
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $c = $line[$i];
            if ($c === '*') {
                if (($line[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($line[$i + 1] ?? '') === '/') { $i++; $re .= '(?:.*/)?'; } else { $re .= '.*'; }
                } else { $re .= '[^/]*'; }
            } elseif ($c === '?') { $re .= '[^/]'; }
            elseif ($c === '[') { $j = strpos($line, ']', $i + 1); if ($j === false) { $re .= '\['; } else { $re .= '['.str_replace('\\', '\\\\', substr($line, $i + 1, $j - $i - 1)).']'; $i = $j; } }
            elseif ($c === '\\' && $i + 1 < $n) { $re .= preg_quote($line[++$i], '~'); }
            else { $re .= preg_quote($c, '~'); }
        }
        $rules[] = ['neg' => $neg, 'dirOnly' => $dirOnly, 'anchored' => $anchored, 're' => $re, 'base' => $baseRel];
    }
    return $rules;
}

function is_ignored(string $rel, bool $isDir, array $ruleSets): bool
{
    $ignored = false;
    foreach ($ruleSets as $r) {
        if ($r['dirOnly'] && !$isDir) continue;
        $sub = $r['base'] === '' ? $rel : (str_starts_with($rel, $r['base'].'/') ? substr($rel, strlen($r['base']) + 1) : null);
        if ($sub === null) continue;
        $pat = $r['anchored'] ? '~^'.$r['re'].'$~u' : '~(?:^|/)'.$r['re'].'$~u';
        if (preg_match($pat, $sub)) $ignored = !$r['neg'];
    }
    return $ignored;
}

/** @return list<string> relative paths that would be committed */
function tree(string $root): array
{
    $out = [];
    $walk = function (string $rel, array $rules) use (&$walk, &$out, $root): void {
        $abs = $rel === '' ? $root : $root.'/'.$rel;
        $rules = array_merge($rules, ignore_rules($abs, $rel));
        $names = scandir($abs) ?: [];
        sort($names);
        foreach ($names as $n) {
            if ($n === '.' || $n === '..' || $n === '.git') continue;
            $r = $rel === '' ? $n : $rel.'/'.$n;
            $p = $root.'/'.$r;
            if (is_link($p)) continue;
            if (is_dir($p)) {
                if (!is_ignored($r, true, $rules)) $walk($r, $rules);
            } elseif (!is_ignored($r, false, $rules)) {
                $out[] = $r;
            }
        }
    };
    $walk('', []);
    return $out;
}

// ---------------------------------------------------------------- helpers
function mask(string $s, int $keep = 3): string
{
    $s = trim($s);
    $n = mb_strlen($s); return $n <= $keep * 2 ? str_repeat('*', $n) : mb_substr($s, 0, $keep).str_repeat('*', 3).mb_substr($s, -$keep);
}

function thai_id_valid(string $d): bool
{
    if (!preg_match('~^\d{13}$~', $d) || $d[0] === '0') return false;
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int) $d[$i] * (13 - $i);
    return ((11 - $sum % 11) % 10) === (int) $d[12];
}

function fictional_phone(string $digits): bool
{
    $rest = substr($digits, 1);
    if (preg_match('~(\d)\1{5,}~', $digits)) return true;                        // 0800000000, 0899999999
    if (str_contains($digits, '0000') || str_contains($digits, '1234567')) return true;
    if (preg_match(strlen($digits) === 10 ? '~^0\d{2}(?:555|999|000|123)~' : '~^0\d(?:555|999|000|123)~', $digits)) return true;
    return $rest === '';
}

$allowRules = [];
if (is_file((string) $opt['allow'])) {
    foreach (preg_split('~\R~', (string) file_get_contents((string) $opt['allow'])) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || !str_contains($l, ':')) continue;
        [$cat, $glob] = explode(':', $l, 2);
        $allowRules[] = [trim($cat), trim($glob)];
    }
}
function allowed(array $rules, string $cat, string $path): bool
{
    foreach ($rules as [$c, $g]) if (($c === '*' || $c === $cat) && fnmatch($g, $path)) return true;
    return false;
}

$emailAllowDomain = '~(^|\.)(example\.(com|org|net|edu)|example|test|invalid|localhost|local|demo|school\.test|saan\.test)$|^localhost$|^(demo|test|example|fictional)\.~i';
$emailAllowFull = '~^(noreply|no-reply)@(localhost|example)~i';
$secretWords = '(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|client[_-]?secret|access[_-]?key)';
$placeholder = '~(changeme|change-me|change_me|example|placeholder|your[_-]|xxxx|\*{3,}|null|false|true|env\(|\$|\{\{|<|dummy|sample|secret-here|test|demo|ci-root|password123|password$|fictional|harmless|marker|fake|mock)~i';

// private terms: optional, untracked (see .gitignore), one PCRE fragment per line, lines starting with # are comments
$privateTerms = '';
$termsFile = __DIR__.'/scan-secrets.terms';
if (is_file($termsFile)) {
    $terms = array_values(array_filter(array_map('trim', file($termsFile, FILE_IGNORE_NEW_LINES) ?: []), fn ($l) => $l !== '' && $l[0] !== '#'));
    if ($terms) {
        $privateTerms = '~(?i)(?:'.implode('|', array_map(fn ($t) => str_replace('~', '\~', $t), $terms)).')~u';
        if (@preg_match($privateTerms, '') === false) { fwrite(STDERR, "[ERR] tools/release/scan-secrets.terms holds an invalid pattern\n"); exit(2); }
    }
}

$findings = [];
$add = function (string $sev, string $cat, string $path, int $line, string $note) use (&$findings, $allowRules): void {
    if (allowed($allowRules, $cat, $path)) return;
    if ($cat === 'credential-literal' && str_starts_with($path, 'tests/')) { $sev = 'medium'; $cat = 'test-credential'; }   // fixture passwords: review, do not block
    $findings[] = ['severity' => $sev, 'category' => $cat, 'file' => $path, 'line' => $line, 'note' => $note];
};

$files = tree($root);
if ($opt['list']) { echo implode(PHP_EOL, $files), PHP_EOL; exit(0); }
$scanned = 0;
foreach ($files as $rel) {
    $abs = $root.'/'.$rel;
    $size = (int) @filesize($abs);
    $base = basename($rel);
    $scanned++;

    if (preg_match('~^\.env(\..*)?$~', $base) && !preg_match('~\.example$~', $base)) $add('high', 'env-file', $rel, 0, 'environment file would be committed');
    if (preg_match('~\.(pem|key|ppk|p12|pfx|jks|kdbx|sqlite3?|db|sql|bak|dump)$~i', $base) || preg_match('~^(id_rsa|id_ed25519|auth\.json|installed\.json|demo-accounts\.txt)$~', $base)) $add('high', 'sensitive-file', $rel, 0, 'sensitive file type: '.$base);
    if ($size > (int) $opt['max-bytes']) $add('low', 'large-file', $rel, 0, number_format($size).' bytes');

    $fh = @fopen($abs, 'rb');
    if (!$fh) continue;
    $head = (string) fread($fh, 8192);
    fclose($fh);
    $binary = str_contains($head, "\0") || preg_match('~\.(pdf|png|jpe?g|gif|webp|mp4|webm|zip|woff2?|ttf|otf|ico)$~i', $base);
    if ($binary) {
        if ($size > 262144 && $size <= (int) $opt['max-bytes']) $add('low', 'large-binary', $rel, 0, number_format($size).' bytes binary');
        continue;
    }
    if ($size > 5 * 1048576) continue;

    $lines = preg_split('~\R~', (string) file_get_contents($abs)) ?: [];
    foreach ($lines as $i => $text) {
        $ln = $i + 1;
        if (strlen($text) > 4000 && !$opt['all-lines']) $text = substr($text, 0, 4000);

        if (preg_match('~-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP |ENCRYPTED )?PRIVATE KEY~', $text)) $add('high', 'private-key', $rel, $ln, 'PEM/OpenSSH private key block');
        if (preg_match('~^\s*APP_KEY\s*=\s*base64:([A-Za-z0-9+/=]{30,})~', $text, $m)) $add('high', 'app-key', $rel, $ln, 'APP_KEY '.mask($m[1]));
        if (preg_match('~"secret_key"\s*:\s*"([A-Za-z0-9+/=]{60,})"~', $text, $m)) $add('high', 'signing-key', $rel, $ln, 'signing secret_key '.mask($m[1]));
        if (preg_match('~(?<![A-Za-z0-9])\d{8,10}:[A-Za-z0-9_-]{35}(?![A-Za-z0-9_-])~', $text, $m)) $add('high', 'token', $rel, $ln, 'Telegram-style bot token '.mask($m[0]));
        if (preg_match('~(?<![A-Z0-9])(AKIA|ASIA)[0-9A-Z]{16}(?![A-Z0-9])~', $text, $m)) $add('high', 'token', $rel, $ln, 'AWS access key id '.mask($m[0]));
        if (preg_match('~\bgh[pousr]_[A-Za-z0-9]{36,}~', $text, $m)) $add('high', 'token', $rel, $ln, 'GitHub token '.mask($m[0]));
        if (preg_match('~\bsk-[A-Za-z0-9]{32,}~', $text, $m)) $add('high', 'token', $rel, $ln, 'API secret key '.mask($m[0]));
        if (preg_match('~\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}~', $text, $m)) $add('high', 'token', $rel, $ln, 'JWT '.mask($m[0]));
        if (preg_match('~(?i)\b[\w.-]*'.$secretWords.'[\w.-]*[\'"]?\s*(?:=>|[:=])\s*[\'"]([\x21\x23-\x26\x28-\x7E]{8,})[\'"]~', $text, $m) && !str_contains($m[1], '|') && !preg_match($placeholder, $m[1]) && !preg_match('~env\(|config\(|\$_(?:ENV|SERVER)|getenv~', $text)) {
            $add('high', 'credential-literal', $rel, $ln, 'literal value for secret-like key '.mask($m[1], 2));
        }
        if (preg_match('~(?i)\b(?:mysql|mariadb|pgsql|postgres(?:ql)?|redis|amqp|mongodb|ftp|smtp)://[^\s:/@]+:([^\s@/]{4,})@~', $text, $m) && !preg_match($placeholder, $m[0])) $add('high', 'credential-literal', $rel, $ln, 'password inside connection URL '.mask($m[1], 1));

        // Thai national ID: 13 digits (plain or d-dddd-ddddd-dd-d) with valid checksum
        if (preg_match_all('~(?<![\d.])(\d)[- ]?(\d{4})[- ]?(\d{5})[- ]?(\d{2})[- ]?(\d)(?![\d])~', $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $d = $m[1].$m[2].$m[3].$m[4].$m[5];
                if (thai_id_valid($d)) $add('high', 'thai-national-id', $rel, $ln, 'valid-checksum 13 digit id '.substr($d, 0, 1).'-****-*****-**-'.substr($d, -1));
            }
        }
        // phone numbers
        if (preg_match_all('~(?<![\w-])(0[689]\d[- ]?\d{3}[- ]?\d{4}|0[2-7]\d?[- ]?\d{3}[- ]?\d{3,4})(?![\w-])~', $text, $mm)) {
            foreach ($mm[1] as $ph) {
                $digits = preg_replace('~\D~', '', $ph);
                if (strlen($digits) < 9 || strlen($digits) > 10) continue;
                if (fictional_phone($digits)) continue;
                if (preg_match('~(?i)(version|migration|timestamp|\bid\b|seed|width|height|epoch)~', $text) && strlen($digits) === 9) continue;
                $add('medium', 'phone', $rel, $ln, 'real-looking phone '.substr($digits, 0, 3).'***'.substr($digits, -2));
            }
        }
        // names that must never be published (a customer, an internal host): one PCRE fragment per line in the untracked file tools/release/scan-secrets.terms
        if ($privateTerms !== '' && preg_match($privateTerms, $text, $m)) $add('medium', 'private-term', $rel, $ln, 'contains a private term '.mask($m[0]));
        if (preg_match_all('~(?<![\w.%+-])([A-Za-z0-9._%+-]+)@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)(?![\w-])~', $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $dom = strtolower($m[2]);
                if (preg_match('~\.(png|jpe?g|gif|svg|webp|css|js|map|woff2?)$~', $dom)) continue;   // asset@2x.png style
                if ($base === 'composer.lock' || preg_match($emailAllowDomain, $dom) || preg_match($emailAllowFull, $m[0])) continue;
                $add('medium', 'email', $rel, $ln, 'email on non-demo domain '.mask($m[1], 1).'@'.$dom);
            }
        }
    }
}

// ---------------------------------------------------------------- report
$order = ['high' => 0, 'medium' => 1, 'low' => 2];
usort($findings, fn ($a, $b) => [$order[$a['severity']], $a['category'], $a['file'], $a['line']] <=> [$order[$b['severity']], $b['category'], $b['file'], $b['line']]);
$summary = [];
foreach ($findings as $f) $summary[$f['severity']][$f['category']] = ($summary[$f['severity']][$f['category']] ?? 0) + 1;
$result = ['root' => $root, 'files_scanned' => $scanned, 'findings_total' => count($findings), 'summary' => $summary, 'findings' => $findings];
if ($opt['json']) file_put_contents((string) $opt['json'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "scan-secrets: $scanned files in tree, ".count($findings)." findings\n";
foreach ($summary as $sev => $cats) foreach ($cats as $c => $n) echo sprintf("  [%s] %-20s %d\n", strtoupper($sev), $c, $n);
$shown = [];
foreach ($findings as $f) {
    $k = $f['category'].'|'.$f['file'];
    $shown[$k] = ($shown[$k] ?? 0) + 1;
    if (!$opt['all-lines'] && $shown[$k] > 3) continue;   // first 3 lines per file/category
    echo sprintf("%s %s %s:%d %s\n", strtoupper($f['severity']), $f['category'], $f['file'], $f['line'], $f['note']);
}
if (!$opt['all-lines']) foreach ($shown as $k => $n) if ($n > 3) echo "  ... $k: ".($n - 3)." more (use --json or --all-lines)\n";

$high = $summary['high'] ?? [];
exit($opt['fail'] && $high ? 1 : 0);
