<?php

namespace App\Domain;

use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\UpdateFeed;
use App\Support\Saan\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The system fetches its own signed packages (back office page /admin/updates, `saan:self-update`, the scheduler).
 *
 * It sits in front of Updater and never changes how an update is applied: it decides WHAT would be installed (plan), brings the packages
 * into storage/app/private/updates/incoming (download) and hands them to the existing apply mechanism (web: one package per request through
 * POST /api/site/updates/apply; CLI and scheduler: `saan:update --all=<folder>` in a PHP CLI child, with --both on the two-app layout).
 *
 * Trust: nothing is believed that the signed feed (UpdateFeed, Ed25519) did not say. A package is fetched only from the folder of the
 * feed's own host (scheme https, same host, under <feed folder>/<base>/), is limited to the announced size, must match the announced
 * SHA-256 and must still pass Updater::inspect() (the package's own signature) or it is deleted. No user-supplied URL exists.
 *
 * Download: ONE package per call, RESUMABLE. A call works for at most $budget seconds; what has arrived stays in <zip>.part and the next
 * call continues with an HTTP Range request (a server that ignores Range sends the whole file and the download restarts from zero). The
 * answer says "partial" or "ready", so a short web request can never be the reason for a failed update.
 *
 * Modes (Settings, per school): off, notify (the old feed notice), auto_patch (the scheduler installs a PATCH release by itself, inside a
 * night window, one attempt per release). "off" is the existing feed switch (updates.feed_enabled false): the two can never disagree.
 */
class SelfUpdate
{
    /** A feed record older than this is checked again before anything is planned for installation. */
    public const STALE_AFTER = 604800;
    public const DEFAULT_WINDOW = ['start' => '02:00', 'end' => '05:00'];
    public const MODES = ['off', 'notify', 'auto_patch'];
    private const PART_MAX_AGE = 259200;

    /** @var callable(list<string>,int):?array{0:int,1:string} runs `php artisan <args>` in a CLI child; null = this host cannot */
    private $runner;

    private bool $defaultRunner;

    public function __construct(?callable $runner = null)
    {
        $this->defaultRunner = $runner === null;
        $this->runner = $runner ?? fn (array $args, int $timeout) => Updater::artisanCli($args, $timeout);
    }

    public static function incoming(): string
    {
        $dir = storage_path('app/private/updates/incoming');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new SelfUpdateException('สร้างโฟลเดอร์รับแพ็กเกจไม่ได้: storage/app/private/updates/incoming', 'disk', 500);
        return $dir;
    }

    // ------------------------------------------------------------------ mode and window

    public static function mode(): string
    {
        if (!UpdateFeed::enabled()) return 'off';
        $m = Settings::get('updates.mode');
        return in_array($m, ['notify', 'auto_patch'], true) ? $m : 'notify';
    }

    /** @return array{start:string,end:string} site time (config app.timezone), HH:MM */
    public static function window(): array
    {
        $w = Settings::get('updates.window');
        $ok = fn ($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $v);
        return is_array($w) && $ok($w['start'] ?? null) && $ok($w['end'] ?? null) && $w['start'] !== $w['end'] ? ['start' => $w['start'], 'end' => $w['end']] : self::DEFAULT_WINDOW;
    }

    public static function setMode(string $mode, ?string $start = null, ?string $end = null): void
    {
        if (!in_array($mode, self::MODES, true)) throw new SelfUpdateException('โหมดไม่ถูกต้อง');
        $re = '/^([01]\d|2[0-3]):[0-5]\d$/D';
        if ($start !== null || $end !== null) {
            if (!is_string($start) || !is_string($end) || !preg_match($re, $start) || !preg_match($re, $end) || $start === $end) throw new SelfUpdateException('ช่วงเวลาต้องเป็นรูปแบบ HH:MM และเวลาเริ่มต้องไม่ตรงกับเวลาสิ้นสุด');
            Settings::put('updates.window', ['start' => $start, 'end' => $end]);
        }
        Settings::put('updates.feed_enabled', $mode !== 'off');
        if ($mode !== 'off') Settings::put('updates.mode', $mode);
        self::clearAttempts();
    }

    public static function minutes(string $hhmm): int
    {
        return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
    }

    public static function inWindow(?\DateTimeInterface $now = null): bool
    {
        $now = $now ? \Carbon\Carbon::instance($now)->setTimezone(config('app.timezone')) : now(config('app.timezone'));
        $m = $now->hour * 60 + $now->minute; $w = self::window(); $s = self::minutes($w['start']); $e = self::minutes($w['end']);
        return $s < $e ? ($m >= $s && $m < $e) : ($m >= $s || $m < $e);
    }

    /** ISO time at which the automatic window next opens (now when it is open). */
    public static function nextWindow(?\DateTimeInterface $now = null): string
    {
        $t = $now ? \Carbon\Carbon::instance($now)->setTimezone(config('app.timezone')) : now(config('app.timezone'));
        if (self::inWindow($t)) return $t->toIso8601String();
        $s = self::minutes(self::window()['start']);
        $start = $t->copy()->startOfDay()->addMinutes($s);
        if ($start->lte($t)) $start = $start->addDay();
        return $start->toIso8601String();
    }

    // ------------------------------------------------------------------ plan

    public static function sameMinor(string $a, string $b): bool
    {
        return preg_match('/^v?(\d+)\.(\d+)\./', $a, $x) === 1 && preg_match('/^v?(\d+)\.(\d+)\./', $b, $y) === 1 && $x[1] === $y[1] && $x[2] === $y[2];
    }

    public static function laravelVersion(): string
    {
        return \Illuminate\Foundation\Application::VERSION;
    }

    /** Free bytes where packages are stored (tests replace it). */
    protected function freeSpace(): ?float
    {
        $free = @disk_free_space(storage_path('app/private'));
        return $free === false ? null : (float) $free;
    }

    private function blocker(string $code, string $message, bool $blocksDownload = true, ?string $package = null): array
    {
        return ['code' => $code, 'message' => $message, 'download' => $blocksDownload, 'package' => $package];
    }

    private function fileState(?string $zip, ?int $bytes): array
    {
        if ($zip === null) return ['downloaded' => false, 'part_bytes' => 0];
        $dir = storage_path('app/private/updates/incoming');
        $f = $dir.'/'.$zip;
        return ['downloaded' => $bytes !== null && is_file($f) && filesize($f) === $bytes, 'part_bytes' => is_file($f.'.part') ? (int) filesize($f.'.part') : 0];
    }

    private function entry(string $type, string $id, string $name, ?string $from, array $r): array
    {
        $to = (string) $r['version'];
        $zip = isset($r['zip'], $r['bytes'], $r['sha256']) ? (string) $r['zip'] : null;
        return ['key' => $type === 'core' ? 'core' : $id, 'type' => $type, 'id' => $id, 'name' => $name, 'from' => $from, 'to' => $to,
            'same_minor' => $from !== null && self::sameMinor($from, $to), 'released' => $r['released'] ?? null, 'summary' => $r['summary'] ?? '', 'notes_url' => $r['notes_url'] ?? null,
            'zip' => $zip, 'bytes' => $zip ? (int) $r['bytes'] : null, 'sha256' => $zip ? (string) $r['sha256'] : null, 'laravel' => $r['laravel'] ?? null, 'requires' => $r['requires'] ?? null]
            + $this->fileState($zip, $zip ? (int) $r['bytes'] : null);
    }

    public static function isStale(?string $checkedAt): bool
    {
        $t = $checkedAt ? strtotime($checkedAt) : false;
        return $t === false || $t < time() - self::STALE_AFTER;
    }

    /**
     * What would be installed, in install order, and what stops it. $order = true also reads the manifests of packages that are already
     * downloaded, so the order follows the real module requirements (otherwise: core first, then modules by id).
     */
    public function plan(bool $order = false): array
    {
        $st = UpdateFeed::status();
        $a = $st['available'] ?? null;
        $current = (string) config('saan.version');
        $packages = []; $blockers = [];
        if (!$st['enabled']) $blockers[] = $this->blocker('feed_off', 'การตรวจรุ่นใหม่ปิดอยู่ เลือกโหมด "แจ้งเตือนรุ่นใหม่" หรือ "อัปเดตแพตช์อัตโนมัติ" ก่อน ระบบจะไม่เชื่อมต่อภายนอกเมื่อปิดอยู่');
        else {
            if (!is_array($a)) $blockers[] = $this->blocker('stale', 'ยังไม่เคยตรวจรุ่นใหม่ กด "ตรวจรุ่นใหม่" ก่อน');
            else {
                if (self::isStale($a['checked_at'] ?? null)) $blockers[] = $this->blocker('stale', 'ผลตรวจรุ่นใหม่เก่ากว่า 7 วัน กด "ตรวจรุ่นใหม่" อีกครั้งก่อน');
                if (is_array($a['core'] ?? null)) $packages[] = $this->entry('core', 'core', 'สาน (Saan)', $current, $a['core']);
                $mods = (array) ($a['modules'] ?? []); usort($mods, fn ($x, $y) => strcmp((string) $x['id'], (string) $y['id'])); // a stable order for modules that do not depend on each other
                foreach ($mods as $m) $packages[] = $this->entry('module', (string) $m['id'], (string) ($m['name'] ?? $m['id']), (string) $m['current'], $m);
            }
        }
        $downloadable = $packages !== [] && count(array_filter($packages, fn ($p) => $p['zip'] !== null)) === count($packages);
        if ($packages) $blockers = array_merge($blockers, $this->packageBlockers($packages, $downloadable));
        $orderBy = 'feed';
        if ($order && $downloadable && count(array_filter($packages, fn ($p) => $p['downloaded'])) === count($packages)) {
            $m = []; foreach ($packages as $p) { $man = $this->manifestOf($p); if ($man === null) { $m = null; break; } $m[$p['key']] = $man; }
            if ($m !== null) {
                try { $keys = Updater::installOrder($m); $pos = array_flip($keys); usort($packages, fn ($x, $y) => $pos[$x['key']] <=> $pos[$y['key']]); $orderBy = 'manifest'; }
                catch (\Throwable $e) { $blockers[] = $this->blocker('order', $e->getMessage()); }
            } else $blockers[] = $this->blocker('bad_file', 'ไฟล์ที่ดาวน์โหลดไว้อ่านไม่ได้หรือไม่ผ่านการตรวจ ดาวน์โหลดใหม่');
        }
        $core = null; foreach ($packages as $p) if ($p['type'] === 'core') $core = $p;
        $from = self::laravelVersion(); $to = $core['laravel'] ?? null;
        return ['packages' => $packages, 'any' => $packages !== [], 'downloadable' => $downloadable, 'manual_only' => $packages !== [] && !$downloadable, 'order_by' => $orderBy,
            'laravel' => ['from' => $from, 'to' => $to, 'changes' => $to !== null && $to !== $from],
            'download_bytes' => array_sum(array_map(fn ($p) => $p['downloaded'] ? 0 : (int) $p['bytes'], $packages)), 'total_bytes' => array_sum(array_column($packages, 'bytes')),
            'blockers' => $blockers, 'ready' => $packages !== [] && $downloadable && !array_filter($blockers, fn ($b) => $b['download'])];
    }

    /** Facts known before anything is downloaded. Core-only facts carry package 'core': the automatic run ignores them when it does not touch the core. */
    private function packageBlockers(array $packages, bool $downloadable): array
    {
        $out = [];
        foreach ($packages as $p) {
            if ($p['type'] !== 'core') continue;
            $php = $p['requires']['php'] ?? null;
            if ($php && version_compare(PHP_VERSION, ltrim($php, '>='), '<')) $out[] = $this->blocker('php', 'รุ่นใหม่ต้องใช้ PHP '.ltrim($php, '>=').' ขึ้นไป แต่เครื่องนี้ใช้ PHP '.PHP_VERSION.' ให้ผู้ดูแลโฮสต์เปลี่ยนรุ่น PHP ก่อน', true, 'core');
            $from = $p['requires']['from'] ?? null;
            if ($from && $p['from'] !== null && Updater::compareVersions((string) $p['from'], $from) < 0) $out[] = $this->blocker('from', 'รุ่นที่ติดตั้ง ('.$p['from'].') เก่าเกินไปที่จะข้ามไปรุ่น '.$p['to'].' ในขั้นเดียว ต้องอัปเดตเป็นรุ่น '.$from.' ก่อน', true, 'core');
        }
        if (Updater::unfinished()) $out[] = $this->blocker('unfinished', 'มีการอัปเดตที่ค้างอยู่ ต้องปิดงานนั้นก่อน (php artisan saan:update --recover หรือรอให้ระบบปิดงานเอง)');
        if (self::running()) $out[] = $this->blocker('running', 'มีการอัปเดตกำลังทำงานอยู่ (อีกหน้าต่างหรืองานอัตโนมัติ) รอให้เสร็จก่อน', false);
        if ($downloadable) {
            $remaining = 0; $largest = 0;
            foreach ($packages as $p) { $largest = max($largest, (int) $p['bytes']); if (!$p['downloaded']) $remaining += max(0, (int) $p['bytes'] - (int) $p['part_bytes']); }
            // a lower bound: what the download takes plus what the updater stages for the biggest package (compressed size x3) and 50 MB; the exact figure is checked after the download
            $need = $remaining + 3 * $largest + 52428800; $free = $this->freeSpace();
            if ($free !== null && $free < $need) $out[] = $this->blocker('disk', 'พื้นที่ดิสก์ว่างไม่พอ ว่าง '.round($free / 1048576).' MB ต้องการอย่างน้อยประมาณ '.round($need / 1048576).' MB (ดาวน์โหลด + พื้นที่พักไฟล์) ลบไฟล์สำรองเก่าหรือขอพื้นที่เพิ่ม');
        }
        if (UpdateBoth::required() && config('saan.update_finish') !== 'inline' && !$this->cliAvailable()) $out[] = $this->blocker('both_cli', 'การติดตั้งแบบมาตรฐานมีสองโฟลเดอร์แอป ต้องอัปเดตทั้งสองฝั่งผ่าน PHP CLI แต่โฮสต์นี้ไม่ให้เว็บเริ่ม PHP CLI (proc_open ถูกปิดหรือไม่พบ php) ใช้คำสั่ง php artisan saan:self-update --install --yes จาก SSH หรือ cron แทน', false);
        return $out;
    }

    private function cliAvailable(): bool
    {
        return Cache::remember('saan.selfupdate.cli', 600, fn () => Updater::phpCli() !== null ? 1 : 0) === 1;
    }

    /** {type,id,requires} of a downloaded package, cached by its (signed) SHA-256 so that polling never re-reads a big archive. */
    private function manifestOf(array $p): ?array
    {
        $key = 'saan.selfupdate.manifest.'.$p['sha256'];
        $hit = Cache::get($key);
        if (is_array($hit)) return $hit;
        try { $m = (new Updater)->inspect(storage_path('app/private/updates/incoming/'.$p['zip'])); }
        catch (\Throwable) { return null; }
        $out = ['type' => $m['type'], 'id' => $m['id'], 'version' => $m['version'], 'requires' => ['modules' => (array) ($m['requires']['modules'] ?? [])]];
        Cache::put($key, $out, 3600);
        return $out;
    }

    // ------------------------------------------------------------------ check

    /** Fetch the signed feed now (only when the feed is switched on: nothing leaves this site otherwise). $retryAuto lets the scheduler try again after a failed attempt. */
    public function check(bool $retryAuto = false): array
    {
        if (!UpdateFeed::enabled()) throw new SelfUpdateException('ยังไม่ได้เปิดการตรวจรุ่นใหม่ เลือกโหมด "แจ้งเตือนรุ่นใหม่" ก่อน', 'feed_off');
        (new UpdateFeed)->check();
        if ($retryAuto) self::clearAttempts();
        $this->sweepIncoming();
        return (array) Settings::get('updates.last_check');
    }

    // ------------------------------------------------------------------ download

    private function entryFor(string $package, array $plan): array
    {
        foreach ($plan['packages'] as $p) if ($p['key'] === $package) return $p;
        throw new SelfUpdateException('ไม่มีแพ็กเกจ "'.$package.'" ในรายการรุ่นใหม่ ตรวจรุ่นใหม่อีกครั้ง', 'not_planned', 404);
    }

    /** https://<feed host>/<feed folder>/<base><zip>: built from the feed address and two validated names, then checked once more. */
    public function urlFor(array $entry): string
    {
        $feed = parse_url((string) config('saan.update_feed'));
        if (!is_array($feed) || ($feed['scheme'] ?? '') !== 'https' || empty($feed['host']) || isset($feed['user']) || isset($feed['pass']) || isset($feed['query']) || isset($feed['fragment']) || !str_ends_with((string) ($feed['path'] ?? ''), '/feed.json')) {
            throw new SelfUpdateException('ที่อยู่ฟีดต้องเป็น https และลงท้ายด้วย /feed.json', 'feed_url');
        }
        $a = UpdateFeed::status()['available'] ?? null;
        $base = is_array($a) ? UpdateFeed::base($a['base'] ?? null) : null;
        $zip = (string) ($entry['zip'] ?? '');
        if ($base === null || !preg_match('/^saan-[A-Za-z0-9._-]{1,150}\.zip$/D', $zip)) throw new SelfUpdateException('ฟีดนี้ไม่มีข้อมูลดาวน์โหลด ใช้การอัปโหลดไฟล์เอง', 'manual_only');
        $folder = substr((string) $feed['path'], 0, -strlen('feed.json'));
        $url = 'https://'.$feed['host'].(isset($feed['port']) ? ':'.$feed['port'] : '').$folder.$base.$zip;
        $u = parse_url($url);
        if (!is_array($u) || $u['host'] !== $feed['host'] || !str_starts_with((string) $u['path'], $folder.$base) || str_contains((string) $u['path'], '..') || basename((string) $u['path']) !== $zip) throw new SelfUpdateException('ที่อยู่ไฟล์อยู่นอกโฟลเดอร์ของฟีด ไม่ดาวน์โหลด', 'feed_url');
        return $url;
    }

    private function downloadLock()
    {
        $dir = storage_path('app/private/updates');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new SelfUpdateException('สร้างโฟลเดอร์อัปเดตไม่ได้: '.$dir, 'disk', 500);
        $h = @fopen($dir.'/.download.lock', 'ce');
        if (!$h) throw new SelfUpdateException('เปิดไฟล์ล็อกการดาวน์โหลดไม่ได้ (สิทธิ์ของโฟลเดอร์ storage)', 'disk', 500);
        if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); throw new SelfUpdateException('มีการดาวน์โหลดอยู่ในอีกหน้าต่างหรือโดยงานอัตโนมัติ รอสักครู่แล้วลองใหม่', 'busy', 409); }
        return $h;
    }

    /** Remove half-downloaded files that nobody continued for days, and chunk files a killed request left behind. */
    public function sweepIncoming(): int
    {
        $n = 0; $dir = self::incoming();
        foreach (array_merge(glob($dir.'/*.part') ?: [], glob($dir.'/*.part.chunk') ?: []) as $f) if (is_file($f) && (int) @filemtime($f) < time() - self::PART_MAX_AGE && @unlink($f)) $n++;
        // finished packages that the web button already installed stay in incoming/ (the CLI path deletes them): an hour after their version
        // is installed (or older than what is installed) they are only 79 MB of disk each
        foreach (glob($dir.'/saan-*.zip') ?: [] as $f) {
            if (!preg_match('/^saan-(core|mod-([a-z0-9-]+?))-(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)\.zip$/', basename($f), $m)) continue;
            $have = $m[1] === 'core' ? (string) config('saan.version') : (string) (app(ModuleRegistry::class)->get($m[2])['version'] ?? '');
            if ($have !== '' && Updater::compareVersions($have, $m[3]) >= 0 && (int) @filemtime($f) < time() - 3600 && @unlink($f)) $n++;
        }
        return $n;
    }

    /** Seconds one download call may spend: well inside the web server's time limit. */
    public static function defaultBudget(): int
    {
        if (PHP_SAPI === 'cli') return 1800;
        $max = (int) ini_get('max_execution_time');
        return $max > 0 ? max(5, min(25, $max - 8)) : 25;
    }

    /** @return array{status:string,file:string,bytes:int,received:int,skipped:bool} */
    public function download(string $package, ?int $budget = null): array
    {
        $plan = $this->plan();
        $entry = $this->entryFor($package, $plan);
        if ($entry['zip'] === null) throw new SelfUpdateException('ฟีดนี้ไม่มีข้อมูลดาวน์โหลดของแพ็กเกจนี้ ใช้การอัปโหลดไฟล์เอง', 'manual_only');
        foreach ($plan['blockers'] as $b) if ($b['download'] && ($b['package'] === null || $b['package'] === $entry['key'])) throw new SelfUpdateException($b['message'], $b['code']);
        $url = $this->urlFor($entry);
        $budget = max(3, $budget ?? self::defaultBudget());
        $bytes = (int) $entry['bytes']; $sha = (string) $entry['sha256'];
        $final = self::incoming().'/'.$entry['zip']; $part = $final.'.part'; $chunk = $part.'.chunk';
        $lock = $this->downloadLock();
        try {
            $this->sweepIncoming();
            if (is_file($final)) {
                if (filesize($final) === $bytes && hash_equals($sha, (string) hash_file('sha256', $final))) {
                    $this->accept($final, $entry); // still a valid, signed package: nothing to do
                    return ['status' => 'ready', 'file' => $entry['zip'], 'bytes' => $bytes, 'received' => $bytes, 'skipped' => true];
                }
                @unlink($final);
            }
            $before = is_file($part) ? (int) filesize($part) : 0;
            if ($before > $bytes) { @unlink($part); $before = 0; }
            $free = $this->freeSpace();
            if ($free !== null && $free < ($bytes - $before) + 20971520) throw new SelfUpdateException('พื้นที่ดิสก์ไม่พอสำหรับดาวน์โหลด ว่าง '.round($free / 1048576).' MB ต้องการประมาณ '.round((($bytes - $before) + 20971520) / 1048576).' MB', 'disk');
            @unlink($chunk);
            $meta = null; $over = false; $cap = $bytes - $before + 1;
            $headers = ['Accept' => 'application/zip, application/octet-stream;q=0.9, */*;q=0.1', 'Accept-Encoding' => 'identity'];
            if ($before > 0) $headers['Range'] = 'bytes='.$before.'-';
            $options = [
                'sink' => $chunk, 'allow_redirects' => false,
                // before the body: remember what the server answered (also when the call dies in the middle) and never let an error page reach the file
                'on_headers' => function ($r) use (&$meta) {
                    $meta = ['status' => $r->getStatusCode(), 'type' => $r->getHeaderLine('Content-Type'), 'range' => $r->getHeaderLine('Content-Range'), 'length' => $r->getHeaderLine('Content-Length')];
                    if (!in_array($meta['status'], [200, 206], true)) throw new \RuntimeException('http '.$meta['status']);
                },
                'progress' => function ($total, $now) use (&$over, $cap) { if ($now > $cap) { $over = true; throw new \RuntimeException('size'); } },
            ];
            $res = null; $err = null;
            try { $res = Http::timeout($budget)->connectTimeout(10)->withHeaders($headers)->withOptions($options)->get($url); }
            catch (\Throwable $e) { $err = $e; }
            $status = $res ? $res->status() : ($meta['status'] ?? null);
            $type = strtolower($res ? (string) $res->header('Content-Type') : (string) ($meta['type'] ?? ''));
            $range = $res ? (string) $res->header('Content-Range') : (string) ($meta['range'] ?? '');
            $length = $res ? (string) $res->header('Content-Length') : (string) ($meta['length'] ?? '');
            $got = is_file($chunk) ? (int) filesize($chunk) : 0;
            $fail = function (string $message, string $reason, bool $dropPart = false) use ($chunk, $part): never { @unlink($chunk); if ($dropPart) @unlink($part); throw new SelfUpdateException($message, $reason); };
            if ($over) $fail('ไฟล์ที่ได้ใหญ่กว่าขนาดที่ฟีดที่ลงลายเซ็นประกาศ ('.$bytes.' ไบต์) ลบไฟล์ที่โหลดมาแล้ว', 'size', true);
            if ($status === null) {
                if ($err !== null && ($f2 = $this->freeSpace()) !== null && $f2 < 1048576) $fail('พื้นที่ดิสก์เต็มระหว่างดาวน์โหลด ลบไฟล์เก่าแล้วลองใหม่ ไฟล์ที่ได้บางส่วนยังเก็บไว้', 'disk');
                $fail('เชื่อมต่อเซิร์ฟเวอร์โครงการไม่ได้ ตรวจว่าเครื่องนี้ออกอินเทอร์เน็ตแบบ HTTPS ไปที่ '.parse_url($url, PHP_URL_HOST).' ได้ (ไฟร์วอลล์หรือโฮสต์อาจปิดการเชื่อมต่อขาออก)', 'network');
            }
            $head = $got > 0 ? (string) @file_get_contents($chunk, false, null, 0, 64) : '';
            $looksHtml = str_contains($type, 'html') || ($head !== '' && preg_match('/^\s*<(!doctype|html|head|body|\?xml)/i', $head));
            if ($status >= 300 && $status < 400) $fail('เซิร์ฟเวอร์สั่งเปลี่ยนที่อยู่ (HTTP '.$status.') ระบบไม่ตาม redirect เพื่อความปลอดภัย ตรวจว่าที่อยู่ฟีดชี้ไปยังโฟลเดอร์ที่ถูกต้อง', 'redirect');
            if ($status === 404) $fail('ไม่พบไฟล์ '.$entry['zip'].' บนเซิร์ฟเวอร์โครงการ (HTTP 404) ฟีดอาจประกาศก่อนอัปโหลดไฟล์เสร็จ ลองใหม่ภายหลัง', 'not_found');
            if ($status === 416) $fail('เซิร์ฟเวอร์ปฏิเสธช่วงไฟล์ที่ขอ ลบไฟล์ที่โหลดไว้แล้ว กดดาวน์โหลดอีกครั้งเพื่อเริ่มใหม่จากต้นไฟล์', 'range', true);
            if ($looksHtml) $fail('ได้หน้าเว็บ (HTML) แทนไฟล์แพ็กเกจ'.($status >= 400 ? ' (HTTP '.$status.')' : '').' มักเป็นหน้าตรวจสอบของ Cloudflare หรือหน้าแจ้งปิดปรับปรุง ไม่ใช้ข้อมูลนี้ ลองใหม่ภายหลัง', 'html', $before === 0);
            if ($status >= 400) $fail('เซิร์ฟเวอร์โครงการตอบ HTTP '.$status.' ลองใหม่ภายหลัง', 'http');
            if ($status === 200) {
                if ($length !== '' && ctype_digit($length) && (int) $length !== $bytes) $fail('ขนาดไฟล์ที่เซิร์ฟเวอร์ประกาศ ('.$length.' ไบต์) ไม่ตรงกับฟีดที่ลงลายเซ็น ('.$bytes.' ไบต์) ไม่ใช้ไฟล์นี้', 'size', true);
                // the whole file (the first call, or a server that ignores Range): it replaces what was there
                if (!@rename($chunk, $part) && (!@copy($chunk, $part) || !@unlink($chunk))) $fail('เขียนไฟล์ดาวน์โหลดไม่ได้ (สิทธิ์ของโฟลเดอร์ storage หรือดิสก์เต็ม)', 'disk', true);
                $startedAt = 0;
            } else {
                if (!preg_match('~^bytes (\d+)-(\d+)/(\d+|\*)$~', trim($range), $mm) || (int) $mm[1] !== $before || ($mm[3] !== '*' && (int) $mm[3] !== $bytes)) $fail('เซิร์ฟเวอร์ตอบช่วงไฟล์ไม่ถูกต้อง เริ่มดาวน์โหลดใหม่จากต้นไฟล์ในครั้งถัดไป', 'range', true);
                if (!$this->append($chunk, $part)) $fail('เขียนไฟล์ดาวน์โหลดไม่ได้ (ดิสก์เต็มหรือสิทธิ์ไม่พอ)', 'disk');
                @unlink($chunk);
                $startedAt = $before;
            }
            clearstatcache(true, $part);
            $size = (int) filesize($part);
            if ($startedAt === 0 && $size >= 4 && (string) @file_get_contents($part, false, null, 0, 4) !== "PK\x03\x04") { @unlink($part); throw new SelfUpdateException('ไฟล์ที่ได้ไม่ใช่ไฟล์ ZIP (ไม่ใช่แพ็กเกจของสาน) ลบแล้ว', 'html'); }
            if ($size > $bytes) { @unlink($part); throw new SelfUpdateException('ไฟล์ที่ได้ใหญ่กว่าขนาดที่ฟีดที่ลงลายเซ็นประกาศ ('.$bytes.' ไบต์) ลบไฟล์ที่โหลดมาแล้ว', 'size'); }
            if ($size < $bytes) {
                if ($err === null && $status === 200) { @unlink($part); throw new SelfUpdateException('ไฟล์ที่ได้เล็กกว่าขนาดที่ฟีดประกาศ ('.$size.' จาก '.$bytes.' ไบต์) ลบไฟล์แล้ว ลองใหม่', 'size'); }
                if ($size <= $before && $before > 0 && $status === 206 && $err === null) throw new SelfUpdateException('เซิร์ฟเวอร์ไม่ส่งข้อมูลเพิ่ม ลองใหม่ภายหลัง', 'network');
                return ['status' => 'partial', 'file' => $entry['zip'], 'bytes' => $bytes, 'received' => $size, 'skipped' => false];
            }
            if (!hash_equals($sha, (string) hash_file('sha256', $part))) { @unlink($part); throw new SelfUpdateException('ค่า SHA-256 ของไฟล์ที่ได้ไม่ตรงกับฟีดที่ลงลายเซ็น ลบไฟล์แล้ว (ไฟล์เสียระหว่างทางหรือถูกสลับ)', 'checksum'); }
            @unlink($final);
            if (!@rename($part, $final)) { @unlink($part); throw new SelfUpdateException('ย้ายไฟล์เข้าที่ไม่ได้ (สิทธิ์ของโฟลเดอร์ storage)', 'disk'); }
            $this->accept($final, $entry);
            return ['status' => 'ready', 'file' => $entry['zip'], 'bytes' => $bytes, 'received' => $bytes, 'skipped' => false];
        } finally {
            @unlink($chunk);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function append(string $from, string $to): bool
    {
        $in = @fopen($from, 'rb'); $out = @fopen($to, 'ab');
        if (!$in || !$out) { if ($in) fclose($in); if ($out) fclose($out); return false; }
        $ok = stream_copy_to_stream($in, $out) !== false; fclose($in);
        return fclose($out) && $ok;
    }

    /** The finished file must be the package the feed announced AND carry the project's own signature, or it is deleted. */
    private function accept(string $file, array $entry): void
    {
        try { $m = (new Updater)->inspect($file); }
        catch (\Throwable $e) { @unlink($file); throw new SelfUpdateException('ไฟล์ที่ดาวน์โหลดไม่ผ่านการตรวจแพ็กเกจ ('.$e->getMessage().') ลบไฟล์แล้ว', 'signature'); }
        if ($m['version'] !== $entry['to'] || $m['id'] !== $entry['id'] || $m['type'] !== $entry['type']) {
            @unlink($file);
            throw new SelfUpdateException('แพ็กเกจที่ได้ ('.$m['type'].' '.$m['id'].' '.$m['version'].') ไม่ตรงกับที่ฟีดประกาศ ('.$entry['id'].' '.$entry['to'].') ลบไฟล์แล้ว', 'mismatch');
        }
    }

    // ------------------------------------------------------------------ install (CLI path)

    public static function running(): bool
    {
        try { $h = Updater::acquireLock(); Updater::releaseLock($h); return false; }
        catch (UpdateBusyException) { return true; }
        catch (\Throwable) { return false; }
    }

    /**
     * Install downloaded packages through `saan:update --all=<folder>` in a PHP CLI child (every package in a process of its own, dependencies
     * first, --both on the two-app layout). The files move into a private run folder so that nothing else lying in incoming/ is installed.
     * @param list<array> $entries plan entries (all downloaded) @return array{ok:bool,code:int,output:string}
     */
    public function install(array $entries): array
    {
        if (!$entries) throw new SelfUpdateException('ไม่มีแพ็กเกจให้ติดตั้ง');
        $run = self::incoming().'/run-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
        if (!@mkdir($run, 0700)) throw new SelfUpdateException('สร้างโฟลเดอร์ติดตั้งไม่ได้', 'disk', 500);
        $moved = [];
        try {
            foreach ($entries as $e) {
                $src = self::incoming().'/'.$e['zip'];
                if (!is_file($src)) throw new SelfUpdateException('ไม่พบไฟล์ '.$e['zip'].' ดาวน์โหลดใหม่', 'missing');
                if (!@rename($src, $run.'/'.$e['zip'])) throw new SelfUpdateException('ย้ายไฟล์ไปโฟลเดอร์ติดตั้งไม่ได้: '.$e['zip'], 'disk', 500);
                $moved[$e['zip']] = true;
            }
            $args = ['saan:update', '--all='.$run];
            if (UpdateBoth::required()) $args[] = '--both';
            $r = ($this->runner)($args, (int) config('saan.update_finish_timeout', 1800) * max(1, count($entries)) + 3600);
            if ($r === null) throw new SelfUpdateException('เครื่องนี้เริ่ม PHP CLI ไม่ได้ (proc_open ถูกปิดหรือไม่พบ php) ติดตั้งอัตโนมัติไม่ได้ ใช้การติดตั้งทีละแพ็กเกจจากหน้า /admin/updates หรือกำหนด SAAN_PHP_CLI', 'no_cli');
            [$code, $out] = $r;
            return ['ok' => $code === 0, 'code' => $code, 'output' => trim($out)];
        } finally {
            // a failed package goes back to incoming/ so that the page can retry it without downloading again
            foreach (array_keys($moved) as $zip) {
                if (is_file($run.'/'.$zip)) { if (($r ?? null) !== null && ($r[0] ?? 1) === 0) @unlink($run.'/'.$zip); else @rename($run.'/'.$zip, self::incoming().'/'.$zip); }
            }
            @rmdir($run);
        }
    }

    // ------------------------------------------------------------------ automatic patch updates

    private static function auto(): array
    {
        $a = Settings::get('updates.auto');
        return ['attempts' => is_array($a['attempts'] ?? null) ? $a['attempts'] : [], 'notice' => is_array($a['notice'] ?? null) ? $a['notice'] : null];
    }

    private static function saveAuto(array $a): void
    {
        Settings::put('updates.auto', $a);
    }

    public static function clearAttempts(): void
    {
        try { $a = self::auto(); if ($a['attempts']) { $a['attempts'] = []; self::saveAuto($a); } } catch (\Throwable) {}
    }

    private static function notice(string $kind, string $text): void
    {
        $a = self::auto();
        if (($a['notice']['text'] ?? null) === $text && ($a['notice']['kind'] ?? null) === $kind) return; // a blocker that stays does not rewrite the page every ten minutes
        $a['notice'] = ['kind' => $kind, 'text' => $text, 'at' => now(config('app.timezone'))->toIso8601String()];
        self::saveAuto($a);
    }

    /** An attempt that started and never reported (its process died): decided by what is installed now. */
    private function reconcile(): void
    {
        $a = self::auto(); $changed = false;
        foreach ($a['attempts'] as $key => $t) {
            if (($t['state'] ?? '') !== 'started' || strtotime((string) ($t['at'] ?? '')) > time() - 600 || self::running()) continue;
            [$id, $to] = array_pad(explode('@', (string) $key, 2), 2, '');
            $have = $id === 'core' ? (string) config('saan.version') : (string) (app(ModuleRegistry::class)->get($id)['version'] ?? '');
            $a['attempts'][$key]['state'] = $have !== '' && $to !== '' && Updater::compareVersions($have, $to) >= 0 ? 'ok' : 'failed';
            $changed = true;
        }
        if ($changed) self::saveAuto($a);
    }

    /**
     * One tick of the scheduler (every ten minutes). Does something only when the mode is auto_patch, the night window is open, nothing is
     * unfinished or running and a PATCH release is waiting; a release is tried once. Minor and major releases are never installed here.
     * @return array{action:string,message:string} action: none | blocked | skipped | installed | failed
     */
    public function runAuto(?\DateTimeInterface $now = null): array
    {
        $res = fn (string $a, string $m) => ['action' => $a, 'message' => $m];
        if (self::mode() !== 'auto_patch') return $res('none', 'mode is not auto_patch');
        if (!self::inWindow($now)) return $res('none', 'outside the window');
        if (Updater::unfinished() || self::running()) return $res('none', 'an update is unfinished or running');
        $this->reconcile();
        $last = Settings::get('updates.last_check');
        if (!is_array($last) || (strtotime((string) ($last['at'] ?? '')) ?: 0) < time() - 6 * 3600) (new UpdateFeed)->check();
        $plan = $this->plan();
        if (!$plan['any']) return $res('none', 'nothing new');
        if (!$plan['downloadable']) { self::notice('skipped', 'มีรุ่นใหม่แต่ฟีดนี้ไม่มีข้อมูลดาวน์โหลด ต้องติดตั้งเองจากหน้า อัปเดตระบบ'); return $res('skipped', 'feed has no download data'); }
        $attempts = self::auto()['attempts']; $eligible = []; $manual = [];
        foreach ($plan['packages'] as $p) {
            if (!$p['same_minor']) { $manual[] = $p; continue; }
            if (isset($attempts[$p['key'].'@'.$p['to']])) continue; // one attempt per release
            $eligible[] = $p;
        }
        $names = fn (array $l) => implode(', ', array_map(fn ($p) => $p['name'].' '.$p['to'], $l));
        if (!$eligible) {
            if ($manual) { self::notice('skipped', 'มีรุ่นใหม่ที่ไม่ใช่แพตช์ ('.$names($manual).') ระบบไม่อัปเดตรุ่นหลักหรือรุ่นรองเอง กรุณาตรวจและติดตั้งจากหน้า อัปเดตระบบ'); return $res('skipped', 'only minor or major releases'); }
            return $res('none', 'every release was tried already');
        }
        $keys = array_column($eligible, 'key');
        foreach ($plan['blockers'] as $b) {
            if ($b['code'] === 'running' || ($b['package'] !== null && !in_array($b['package'], $keys, true))) continue;
            if (!$b['download'] && $b['code'] !== 'both_cli') continue;
            self::notice('blocked', 'ยังไม่ได้อัปเดตอัตโนมัติเป็นรุ่น '.$names($eligible).': '.$b['message']);
            return $res('blocked', $b['code']);
        }
        // a host that cannot start a PHP CLI child is not an attempt: nothing was tried, and it will not work on the next tick either
        if ($this->defaultRunner && !$this->cliAvailable()) { self::notice('blocked', 'ยังไม่ได้อัปเดตอัตโนมัติเป็นรุ่น '.$names($eligible).': เครื่องนี้เริ่ม PHP CLI ไม่ได้ (proc_open ถูกปิดหรือไม่พบ php) กำหนด SAAN_PHP_CLI หรือติดตั้งเองจากหน้า อัปเดตระบบ'); return $res('blocked', 'no_cli'); }
        $a = self::auto(); $stamp = now(config('app.timezone'))->toIso8601String();
        foreach ($eligible as $p) $a['attempts'][$p['key'].'@'.$p['to']] = ['at' => $stamp, 'state' => 'started'];
        self::saveAuto($a);
        $finish = function (string $state, string $error = '') use ($eligible, $names): void {
            $a = self::auto();
            foreach ($eligible as $p) $a['attempts'][$p['key'].'@'.$p['to']] = ['at' => $a['attempts'][$p['key'].'@'.$p['to']]['at'] ?? now()->toIso8601String(), 'state' => $state] + ($error !== '' ? ['error' => mb_substr($error, 0, 300)] : []);
            self::saveAuto($a);
        };
        try {
            foreach ($eligible as $p) $this->download($p['key'], 1800);
            $fresh = array_values(array_filter($this->plan(true)['packages'], fn ($p) => in_array($p['key'], $keys, true)));
            $r = $this->install($fresh);
        } catch (\Throwable $e) {
            $finish('failed', $e->getMessage());
            self::notice('failed', 'อัปเดตอัตโนมัติเป็นรุ่น '.$names($eligible).' ไม่สำเร็จ: '.$e->getMessage().' ระบบไม่ลองซ้ำเอง ตรวจที่หน้า อัปเดตระบบ');
            return $res('failed', $e->getMessage());
        }
        // from here on this process may be running on the old release while the files are the new one: plain bookkeeping only
        $when = date('Y-m-d H:i');
        if ($r['ok']) {
            $finish('ok');
            self::notice('ok', 'อัปเดตอัตโนมัติเป็นรุ่น '.$names($eligible).' เมื่อ '.$when.' น.');
            return $res('installed', $names($eligible));
        }
        $tail = mb_substr(trim((string) (preg_split('/\R/', $r['output'])[count(preg_split('/\R/', $r['output'])) - 1] ?? '')), 0, 200);
        $finish('failed', $tail);
        self::notice('failed', 'อัปเดตอัตโนมัติเป็นรุ่น '.$names($eligible).' ไม่สำเร็จเมื่อ '.$when.' น. ระบบคืนรุ่นเดิมของแพ็กเกจที่ล้มแล้วและไม่ลองซ้ำเอง'.($tail !== '' ? ' ('.$tail.')' : '').' ตรวจที่หน้า อัปเดตระบบ');
        return $res('failed', $tail);
    }

    // ------------------------------------------------------------------ status

    /** Summary of the newest update folder (any state), or null. */
    private function lastUpdate(): ?array
    {
        $dirs = array_values(array_filter(glob(storage_path('app/private/updates/*'), GLOB_ONLYDIR) ?: [], fn ($d) => basename($d) !== 'incoming' && is_file($d.'/receipt.json')));
        rsort($dirs);
        $r = $dirs ? json_decode((string) @file_get_contents($dirs[0].'/receipt.json'), true) : null;
        if (!is_array($r)) return null;
        $steps = array_values(array_map(fn ($s) => is_array($s) ? (string) ($s[0] ?? '') : '', (array) ($r['steps'] ?? [])));
        $state = !empty($r['rolled_back']) ? 'rolled_back' : (isset($r['completed_at']) ? 'completed' : 'running');
        return ['id' => $r['id'] ?? null, 'type' => $r['type'] ?? null, 'from' => $r['from'] ?? null, 'to' => $r['to'] ?? null, 'package' => $r['package'] ?? null, 'started_at' => $r['started_at'] ?? null, 'completed_at' => $r['completed_at'] ?? null,
            'state' => $state, 'steps' => $steps, 'error' => $state === 'rolled_back' ? (string) ($r['error'] ?? '') : null, 'finish_mode' => $r['finish_mode'] ?? null, 'swap_seconds' => $r['swap_seconds'] ?? null];
    }

    private function databaseVersion(): array
    {
        try {
            $driver = DB::connection()->getDriverName();
            $v = (string) (DB::selectOne('select version() as v')->v ?? '');
            return ['driver' => $driver, 'version' => $v];
        } catch (\Throwable) { return ['driver' => '', 'version' => '']; }
    }

    public function status(): array
    {
        try { Updater::syncHistory(); } catch (\Throwable) {}
        $feed = UpdateFeed::status(); $plan = $this->plan(true); $auto = self::auto(); $reg = app(ModuleRegistry::class);
        $downloads = [];
        foreach (array_merge(glob(self::incoming().'/saan-*.zip') ?: [], glob(self::incoming().'/saan-*.zip.part') ?: []) as $f) $downloads[] = ['file' => basename($f), 'bytes' => (int) @filesize($f), 'part' => str_ends_with($f, '.part')];
        $backups = Updater::backups();
        $unfinished = array_map(fn ($u) => ['id' => $u['receipt']['id'] ?? null, 'from' => $u['receipt']['from'] ?? null, 'to' => $u['receipt']['to'] ?? null, 'started_at' => $u['receipt']['started_at'] ?? null, 'hint' => 'php artisan saan:update --recover'], Updater::unfinished());
        $window = self::window();
        return [
            'versions' => ['saan' => (string) config('saan.version'), 'laravel' => self::laravelVersion(), 'php' => PHP_VERSION, 'database' => $this->databaseVersion()],
            'modules' => array_values(array_map(fn ($m) => ['id' => $m['id'], 'name' => $m['name'], 'version' => $m['version'], 'active' => (bool) $m['active']], $reg->all())),
            'feed' => ['enabled' => $feed['enabled'], 'url' => (string) $feed['feed'], 'host' => (string) parse_url((string) $feed['feed'], PHP_URL_HOST), 'last_check' => $feed['last_check'], 'checked_at' => $feed['available']['checked_at'] ?? null,
                'stale' => $feed['enabled'] && self::isStale($feed['available']['checked_at'] ?? null), 'version' => $feed['available']['feed_version'] ?? null],
            'mode' => ['mode' => self::mode(), 'window' => $window, 'in_window' => self::inWindow(), 'next_window' => self::nextWindow()],
            'auto' => ['notice' => $auto['notice'], 'attempts' => $auto['attempts']],
            'plan' => $plan,
            'downloads' => $downloads,
            'running' => self::running(),
            'unfinished' => $unfinished,
            'last_update' => $this->lastUpdate(),
            'both_required' => UpdateBoth::required(),
            'history' => Schema::hasTable('system_updates') ? DB::table('system_updates')->orderByDesc('id')->limit(20)->get(['package_id', 'from_version', 'to_version', 'status', 'created_at']) : [],
            'backups' => ['count' => count($backups), 'bytes' => array_sum(array_column($backups, 'bytes')), 'keep' => Updater::KEEP_BACKUPS, 'files' => $backups],
            'incoming' => 'storage/app/private/updates/incoming',
        ];
    }
}
