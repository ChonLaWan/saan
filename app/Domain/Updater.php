<?php

namespace App\Domain;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * REL-02: install a signed Saan package (core or one module) in place, with rollback.
 *
 * A large update (a new framework release, thousands of vendor files, many migrations) must not be able to hurt a running school, so the
 * work is split by which code runs it:
 *
 *   1. START, the installed release (this process): verify signature + hashes + requirements -> preflight -> database backup ->
 *      STAGE every changed file under the update folder and re-verify it there. The site is still up and nothing of it has changed.
 *   2. SWAP, same process, maintenance mode: each live file is moved into snapshot/ and its staged replacement is moved in (rename, so the
 *      window is seconds even for a whole vendor tree), files the release dropped are removed, compiled caches are deleted.
 *   3. FINISH, a fresh PHP process or request that has loaded ONLY the new release (`saan:update --finish=<folder>` or POST /_saan/update-finish):
 *      migrate (expand-only) -> key collation -> clear caches -> health check. The outcome is written to finish.json.
 *   4. SETTLE: the site comes up on the new release, or every file goes back from snapshot/ and it comes up on the old one.
 *
 * The process of step 1 keeps classes of the old release in memory; running migrations there would mix two framework versions in one process.
 * After the swap it therefore loads no class at all: only plain file functions, proc_open/curl and objects resolved before the swap.
 *
 * The contract between the release that starts and the release that finishes is the update folder (receipt.json with step "finish_started",
 * manifest.json, finish.token, finish.lock, finish.json {ok, error}) plus the command/route above. Version 1 must keep its meaning forever:
 * the starting side of every future update is an already installed, older release.
 *
 * If the starting process dies, the finisher settles the update itself; `saan:update --recover` (also run by the scheduler every minute)
 * settles anything left over. Any failure before the swap leaves the site untouched.
 */
class Updater
{
    private const CORE_PREFIXES = ['app/', 'bootstrap/', 'config/', 'database/', 'docs/', 'installer/', 'lang/', 'public/', 'resources/', 'routes/', 'modules/', 'vendor/'];
    private const CORE_FILES = ['artisan', 'composer.json', 'composer.lock', 'VERSION', 'install.php', 'README.md', 'LICENSE', 'SECURITY.md', 'CONTRIBUTING.md', 'CHANGELOG.md', 'RUNBOOK.txt', 'THIRD-PARTY-NOTICES.txt', '.env.example'];

    /** Version of the start/finish contract described above. */
    public const CONTRACT = 1;
    /** Folder (inside the update folder) holding the verified new files before the swap. */
    private const STAGE = 'new';

    /** @var callable(string):void */
    private $log;
    /** @var null|callable(string):void Runs the finish step for the given update folder on new code; tests replace it. */
    private $finisher;
    /** @var list<string>|null PHP CLI command prefix chosen for this update */
    private ?array $cli = null;

    public function __construct(?callable $log = null, ?callable $finisher = null) { $this->log = $log ?? fn () => null; $this->finisher = $finisher; }

    private function say(string $line): void { ($this->log)($line); }

    public function inspect(string $zipPath): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) throw new RuntimeException('เปิดไฟล์แพ็กเกจไม่ได้');
        $json = $zip->getFromName('manifest.json'); $sig = $zip->getFromName('manifest.sig');
        if ($json === false || $sig === false) throw new RuntimeException('แพ็กเกจไม่มี manifest หรือลายเซ็น');
        $public = base64_decode((string) config('saan.release_public_key'), true);
        $raw = base64_decode(trim($sig), true);
        if (!$public || !$raw || !sodium_crypto_sign_verify_detached($raw, $json, $public)) throw new RuntimeException('ลายเซ็นแพ็กเกจไม่ถูกต้อง ไม่ติดตั้ง');
        $m = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!in_array($m['type'] ?? '', ['core', 'module'], true) || !preg_match('/^[a-z][a-z0-9-]{1,39}$/', (string) ($m['id'] ?? '')) || !preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', (string) ($m['version'] ?? ''))) throw new RuntimeException('manifest ไม่ถูกต้อง');
        foreach ($m['files'] as $f) {
            $path = (string) $f['path'];
            if (!$this->allowed($m, $path)) throw new RuntimeException('แพ็กเกจพยายามเขียนนอกพื้นที่ที่อนุญาต: '.$path);
            $data = $zip->getFromName('payload/'.$path);
            if ($data === false || strlen($data) !== (int) $f['bytes'] || !hash_equals((string) $f['sha256'], hash('sha256', $data))) throw new RuntimeException('ไฟล์ในแพ็กเกจไม่ครบหรือถูกแก้: '.$path);
        }
        $zip->close();
        $current = $m['type'] === 'core' ? config('saan.version') : (app(ModuleRegistry::class)->get($m['id'])['version'] ?? null);
        return $m + ['current_version' => $current, 'manifest_sha256' => hash('sha256', $json)];
    }

    private function allowed(array $m, string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('~(^|/)\.env$~', $path)) return false;
        if (str_starts_with($path, 'storage/')) return $m['type'] === 'core' && preg_match('~^storage/([a-z]+/)*\.gitignore$~', $path) === 1;
        if ($m['type'] === 'module') return str_starts_with($path, 'modules/'.$m['id'].'/') || str_starts_with($path, 'public/assets/modules/'.$m['id'].'/');
        if (in_array($path, self::CORE_FILES, true)) return true;
        foreach (self::CORE_PREFIXES as $p) if (str_starts_with($path, $p)) return true;
        return false;
    }

    /** Semver order: 1.2.0-rc.1 < 1.2.0; pre-release identifiers compare numerically when both are numbers, else as text. */
    public static function compareVersions(string $a, string $b): int
    {
        [$coreA, $preA] = array_pad(explode('-', ltrim($a, 'vV'), 2), 2, null);
        [$coreB, $preB] = array_pad(explode('-', ltrim($b, 'vV'), 2), 2, null);
        $c = version_compare($coreA, $coreB);
        if ($c !== 0) return $c;
        if ($preA === null && $preB === null) return 0;
        if ($preA === null) return 1;
        if ($preB === null) return -1;
        $x = explode('.', $preA); $y = explode('.', $preB);
        for ($i = 0, $n = max(count($x), count($y)); $i < $n; $i++) {
            if (!isset($x[$i])) return -1;
            if (!isset($y[$i])) return 1;
            $nx = ctype_digit($x[$i]); $ny = ctype_digit($y[$i]);
            if ($nx && $ny) { if ((int) $x[$i] !== (int) $y[$i]) return (int) $x[$i] <=> (int) $y[$i]; }
            elseif ($nx !== $ny) return $nx ? -1 : 1;
            elseif ($x[$i] !== $y[$i]) return strcmp($x[$i], $y[$i]) <=> 0;
        }
        return 0;
    }

    /** Receipts of updates that neither completed nor rolled back (the request died mid-update). Newest first. */
    public static function unfinished(): array
    {
        $out = [];
        foreach (glob(storage_path('app/private/updates/*/receipt.json')) ?: [] as $file) {
            $r = json_decode((string) @file_get_contents($file), true);
            if (!is_array($r) || isset($r['completed_at']) || !empty($r['rolled_back']) || !empty($r['recovered_at'])) continue;
            $out[] = ['dir' => dirname($file), 'receipt' => $r];
        }
        usort($out, fn ($p, $q) => strcmp((string) ($q['receipt']['started_at'] ?? ''), (string) ($p['receipt']['started_at'] ?? '')));
        return $out;
    }

    /** Non-blocking exclusive lock: two applies, or an apply and a recover, must never run at the same time. */
    public static function acquireLock()
    {
        // Before anything is created: another system user (root from a shell) would leave the update folder and its lock file behind as its
        // own, and from then on the site's user, the finisher and the maintenance stub could not open them any more.
        if (function_exists('posix_geteuid') && ($owner = @fileowner(base_path('artisan'))) !== false && $owner !== posix_geteuid()) {
            throw new RuntimeException('ผู้ใช้ระบบที่รันคำสั่งนี้ (uid '.posix_geteuid().') ไม่ใช่เจ้าของไฟล์ของเว็บ (uid '.$owner.') ให้รันด้วยผู้ใช้ของเว็บ ยังไม่ได้สร้างหรือแก้ไฟล์ใด');
        }
        $dir = storage_path('app/private/updates');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('สร้างโฟลเดอร์อัปเดตไม่ได้: '.$dir);
        $h = @fopen($dir.'/.lock', 'ce'); // e = close-on-exec: the finish child must not inherit this lock, or it could never see that the starter died
        if (!$h) {
            $o = @fileowner($dir.'/.lock');
            throw new RuntimeException('เปิดไฟล์ล็อกอัปเดตไม่ได้: '.$dir.'/.lock'.($o !== false && function_exists('posix_geteuid') && $o !== posix_geteuid() ? ' เป็นของผู้ใช้ระบบ uid '.$o.' (มักเกิดจากเคยรันคำสั่งด้วย root) ให้แก้เจ้าของโฟลเดอร์ storage กลับเป็นผู้ใช้ของเว็บ เช่น chown -R <ผู้ใช้ของเว็บ> storage' : ''));
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); throw new UpdateBusyException('มีการอัปเดตหรือการกู้คืนกำลังทำงานอยู่ ลองใหม่เมื่อเสร็จ'); }
        return $h;
    }

    public static function releaseLock($h): void
    {
        if (is_resource($h)) { @flock($h, LOCK_UN); @fclose($h); }
    }

    /** Clear the compiled caches an update changes. NOT cache:clear (optimize:clear runs it): that would wipe rate-limit counters, PIN lockouts, idempotency records and locks. Finish step only (new code). */
    private static function clearCaches(): void
    {
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear', 'clear-compiled'] as $cmd) {
            try { Artisan::call($cmd); } catch (\Throwable) {}
        }
    }

    /** The same caches removed with plain file functions: the starting process must not load a command class after the swap. */
    private static function dropBootCaches(): void
    {
        $app = app();
        foreach ([$app->getCachedConfigPath(), $app->getCachedRoutesPath(), $app->getCachedEventsPath(), $app->getCachedServicesPath(), $app->getCachedPackagesPath()] as $f) if (is_string($f) && is_file($f)) @unlink($f);
        $views = (string) config('view.compiled');
        // forget() first: Blade writes the new compiled view under the same name, and with opcache.validate_timestamps=0 PHP-FPM would keep the old one
        if ($views !== '' && is_dir($views)) foreach (glob($views.'/*.php') ?: [] as $f) { self::forget($f); @unlink($f); }
    }

    /**
     * OPcache of the web server as far as this process can know it: {enabled, usable, validate}. A web starter reads its own settings; a CLI
     * starter asks the maintenance stub (site closed, stub installed), null when that is not possible. "usable" is false when the host
     * forbids the site to use the OPcache API (opcache.restrict_api); "validate" says whether the server notices changed files by itself.
     */
    private static function webOpcache(?string $work = null, ?string $token = null): ?array
    {
        if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            $on = function_exists('opcache_invalidate') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
            return self::opcacheState($on, !$on || is_array(@opcache_get_status(false)) || @opcache_invalidate(__FILE__, false) === true,
                !$on || filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN), (int) ini_get('opcache.revalidate_freq'));
        }
        if ($work === null || $token === null) return null;
        $r = self::httpPost(self::finishUrl(), ['work' => basename($work), 'token' => $token, 'opcache' => 1], 60);
        return is_array($r) && isset($r['saan_opcache']) ? self::opcacheState(!empty($r['enabled']), ($r['usable'] ?? true) !== false, ($r['validate'] ?? true) !== false, (int) ($r['freq'] ?? 2), (int) $r['saan_opcache']) : null;
    }

    /**
     * A server that looks at changed files only every opcache.revalidate_freq seconds (2 by default, 60 on some shared hosts) picks a new
     * release up after that long: "wait" is how long the site has to stay closed for it. An interval above MAX_REVALIDATE_WAIT counts as
     * "does not validate": the site is not kept closed for minutes, the same rules as for opcache.validate_timestamps=0 apply.
     */
    private static function opcacheState(bool $enabled, bool $usable, bool $validate, int $freq, int $flushed = 0): array
    {
        $freq = max(0, $freq);
        return ['enabled' => $enabled, 'usable' => $usable, 'validate' => $validate && $freq <= self::MAX_REVALIDATE_WAIT, 'wait' => min($freq, self::MAX_REVALIDATE_WAIT) + 1, 'flushed' => $flushed];
    }

    /** The finish step would run inside a web server that keeps the old release's code in OPcache and cannot be made to drop it. */
    private static function staleFinisherMessage(): string
    {
        return 'โฮสต์นี้ไม่ให้เว็บล้าง OPcache เอง (opcache.restrict_api) และไม่ตรวจไฟล์ที่เปลี่ยนในเวลาอันสั้น (opcache.validate_timestamps=0 หรือ opcache.revalidate_freq เกิน '.self::MAX_REVALIDATE_WAIT.' วินาที) ขั้นตอนหลังสลับไฟล์ที่ทำผ่านเว็บจะรันด้วยโค้ดชุดเก่า จึงไม่อัปเดตทางนี้ ให้รัน php artisan saan:update จาก SSH หรือ cron ที่ PHP CLI เริ่ม process ได้แล้ว restart PHP หรือให้ผู้ดูแลโฮสต์เปิด opcache.validate_timestamps';
    }

    /**
     * A CLI process cannot reach PHP-FPM's OPcache, and on Linux the site is closed for less than OPcache's revalidation interval, so after a
     * CLI update (or rollback) the web server would go on with files of the other release for a moment - for ever with
     * opcache.validate_timestamps=0. The maintenance stub runs inside the web server before the autoloader: one token-guarded request to it
     * drops every PHP file of the site from OPcache while the site is still closed. Without a reachable loopback the admin is told, and the
     * site stays closed a little longer than the default revalidation interval.
     */
    private function flushWebOpcache(string $work, array $receipt): void
    {
        if (config('saan.update_finish') === 'inline') return;
        if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            // a web starter invalidated every file itself (swap(), restore(), dropBootCaches()) - unless the host forbids it, and then only a
            // server that validates timestamps will pick the new files up
            $oc = self::webOpcache();
            if ($oc['enabled'] && !$oc['usable']) {
                if (!$oc['validate']) $this->say('[WARN] โฮสต์นี้ไม่ให้เว็บล้าง OPcache เอง (opcache.restrict_api) และไม่ตรวจไฟล์ที่เปลี่ยนในเวลาอันสั้น (opcache.validate_timestamps=0 หรือ opcache.revalidate_freq เกิน '.self::MAX_REVALIDATE_WAIT.' วินาที) เว็บจะยังรันโค้ดชุดก่อนจนกว่าจะ restart PHP (ปุ่ม restart PHP ในแผงควบคุมโฮสต์)');
                elseif (!$this->waited) { $this->say('[WARN] โฮสต์นี้ไม่ให้เว็บล้าง OPcache เอง (opcache.restrict_api) รอ '.$oc['wait'].' วินาทีให้ PHP ของเว็บตรวจไฟล์ใหม่ก่อนเปิดเว็บ'); sleep($oc['wait']); $this->waited = true; }
            }
            return;
        }
        if (!in_array('maintenance_on', array_column((array) ($receipt['steps'] ?? []), 0), true)) return; // no file was swapped
        $token = trim((string) @file_get_contents($work.'/finish.token'));
        $oc = $token !== '' ? self::webOpcache($work, $token) : null;
        if (is_array($oc)) {
            if ($oc['usable']) { $this->webFlushed = true; $this->say('[OK] ล้าง OPcache ของเว็บแล้ว ('.$oc['flushed'].' ไฟล์)'); return; }
            // the host forbids this site to touch OPcache (opcache.restrict_api): nothing was emptied
            if ($oc['validate'] && $this->waited) return; // the wait before the finish step already covered the interval: say and do nothing twice
            if ($oc['validate']) { $this->say('[WARN] โฮสต์นี้ไม่ให้เว็บล้าง OPcache เอง (opcache.restrict_api) รอ '.$oc['wait'].' วินาทีให้ PHP ของเว็บตรวจไฟล์ใหม่ก่อนเปิดเว็บ'); sleep($oc['wait']); $this->waited = true; return; }
            $this->say('[WARN] โฮสต์นี้ไม่ให้เว็บล้าง OPcache เอง (opcache.restrict_api) และไม่ตรวจไฟล์ที่เปลี่ยนในเวลาอันสั้น (opcache.validate_timestamps=0 หรือ opcache.revalidate_freq เกิน '.self::MAX_REVALIDATE_WAIT.' วินาที) เว็บจะยังรันโค้ดชุดก่อนจนกว่าจะ reload PHP-FPM (เช่น systemctl reload php-fpm หรือปุ่ม restart PHP ใน DirectAdmin) ให้ทำทันทีหลังคำสั่งนี้จบ');
            return;
        }
        $this->say('[WARN] ล้าง OPcache ของ PHP-FPM จากคำสั่งนี้ไม่ได้ (เรียกเว็บของตัวเองไม่สำเร็จ) ถ้าโฮสต์ตั้ง opcache.validate_timestamps=0 เว็บจะยังรันโค้ดชุดก่อนจนกว่าจะ reload PHP-FPM (เช่น systemctl reload php-fpm หรือปุ่ม restart PHP ใน DirectAdmin)');
        // the web server cannot be asked: stay closed for one revalidation interval, guessed from this PHP's own setting (the same php.ini on
        // most hosts; 2 seconds is PHP's default)
        sleep(min(self::MAX_REVALIDATE_WAIT, max(2, (int) ini_get('opcache.revalidate_freq'))) + 1);
    }

    /**
     * Drop one swapped or restored PHP file from this SAPI's OPcache. Not opcache_reset(): a pending restart makes OPcache kill a process that
     * holds it open for longer than opcache.force_restart_timeout, and the web starter waits for the whole finish step.
     */
    private static function forget(string $file): void
    {
        if (str_ends_with($file, '.php') && function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    }

    private function up(): void
    {
        try { app()->maintenanceMode()->deactivate(); } catch (\Throwable) {}
        @unlink(storage_path('framework/maintenance.php'));
    }

    private static function readJson(string $file): mixed
    {
        return is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    }

    private static function writeJson(string $file, array $data): void
    {
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('เขียนบันทึกอัปเดตไม่ได้: '.basename($file)); }
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) { if (is_link($dir) || is_file($dir)) @unlink($dir); return; }
        foreach (scandir($dir) ?: [] as $f) if ($f !== '.' && $f !== '..') self::removeTree($dir.DIRECTORY_SEPARATOR.$f);
        @rmdir($dir);
    }

    /** An update folder by its name: only a folder directly under storage/app/private/updates that holds a receipt. */
    public static function workDir(string $name): string
    {
        $base = realpath(storage_path('app/private/updates'));
        // a bare folder name only: anything with a separator or dots-only is refused, not trimmed
        $dir = $base && $name !== 'incoming' && preg_match('/^[0-9A-Za-z][0-9A-Za-z.-]{5,120}$/', $name) && !str_contains($name, '..') ? realpath($base.DIRECTORY_SEPARATOR.$name) : false;
        if (!$dir || dirname($dir) !== $base || !is_file($dir.'/receipt.json')) throw new RuntimeException('ไม่พบบันทึกอัปเดต');
        return $dir;
    }

    /** Restore the snapshot of an unfinished update, drop files it created, clear caches and bring the site up. Refuses while an update is running. */
    public function recover(?string $dir = null, bool $completed = false): array
    {
        $lock = self::acquireLock();
        try { return $this->recoverLocked($dir, $completed); }
        finally { self::releaseLock($lock); }
    }

    /** Same as recover() for a caller that already holds acquireLock() (saan:update --both keeps one lock from the first write to the last). */
    public function recoverLocked(?string $dir, bool $completed = false): array
    {
        $list = self::unfinished();
        $hit = $dir === null ? ($list[0] ?? null) : (collect($list)->firstWhere('dir', $dir));
        // S7 (saan:update --both): a COMPLETED update of this install is taken back when the paired install failed. Only a directory directly under updates/ qualifies.
        if ($completed && $dir !== null) {
            $base = realpath(storage_path('app/private/updates'));
            $real = realpath($dir);
            $r = $real && is_file($real.'/receipt.json') ? json_decode((string) file_get_contents($real.'/receipt.json'), true) : null;
            if (!$base || !$real || dirname($real) !== $base || !is_array($r)) throw new RuntimeException('ไม่พบบันทึกอัปเดตที่จะย้อนคืน');
            $hit = ['dir' => $real, 'receipt' => $r];
        }
        if (!$hit) throw new RuntimeException('ไม่มีการอัปเดตที่ค้างอยู่');
        $work = $hit['dir'];
        // a finisher (child process or request) may still be migrating: never pull the files from under it
        $fl = @fopen($work.'/finish.lock', 'ce');
        if ($fl && !flock($fl, LOCK_EX | LOCK_NB)) { fclose($fl); throw new UpdateBusyException('ขั้นตอนหลังสลับไฟล์ของการอัปเดตนี้ยังทำงานอยู่ ลองใหม่เมื่อเสร็จ'); }
        try {
            $done = self::readJson($work.'/finish.json');
            $rollingBack = in_array('rollback_started', array_column((array) ($hit['receipt']['steps'] ?? []), 0), true);
            if (!$completed && !$rollingBack && is_array($done) && !empty($done['ok'])) {
                // every step passed and only the last bookkeeping was lost (the starting process died): going forward is the safe direction
                $receipt = $this->settle($work, true);
                $this->say('[OK] การอัปเดตนี้ผ่านทุกขั้นตอนแล้ว เปิดเว็บและบันทึกว่าเสร็จ');
                return $receipt;
            }
            $receipt = $this->settle($work, false, (string) ($hit['receipt']['error'] ?? 'recovered after interrupted update'), $completed, true);
        } finally { if ($fl) { @flock($fl, LOCK_UN); @fclose($fl); } }
        $this->say('[OK] คืนไฟล์ '.(int) ($receipt['restored'] ?? 0).' ไฟล์จาก snapshot และเปิดเว็บแล้ว');
        // opcache_reset() in a CLI process cannot clear PHP-FPM's OPcache: with opcache.validate_timestamps=0 FPM keeps running the half-written new files
        if (PHP_SAPI === 'cli' && !$this->webFlushed) $this->say('[WARN] รันจาก CLI: OPcache ของ PHP-FPM ยังไม่ถูกล้าง ให้ reload PHP-FPM (เช่น systemctl reload php-fpm หรือปุ่ม restart PHP ใน DirectAdmin) ก่อนเปิดใช้งาน หากตั้ง opcache.validate_timestamps=0');
        return $receipt;
    }

    private function allowedRecoverPath(string $rel): bool
    {
        return $rel !== '' && !str_contains($rel, '..') && !str_contains($rel, '\\') && !str_starts_with($rel, '/') && !preg_match('~(^|/)\.env$~', $rel);
    }

    public function preflight(array $m): array
    {
        $problems = [];
        if (version_compare(PHP_VERSION, ltrim((string) ($m['requires']['php'] ?? '8.3'), '>='), '<')) $problems[] = 'PHP '.PHP_VERSION.' ต่ำกว่าที่แพ็กเกจต้องการ ('.($m['requires']['php'] ?? '').')';
        foreach ((array) ($m['requires']['extensions'] ?? []) as $ext) if (is_string($ext) && $ext !== '' && !extension_loaded($ext)) $problems[] = 'ไม่มีส่วนขยาย PHP ที่แพ็กเกจต้องการ: '.$ext;
        if ($m['type'] === 'module') {
            $reg = app(ModuleRegistry::class);
            if (!\App\Support\Modules\ModuleRegistry::satisfies((string) config('saan.version'), (string) ($m['requires']['core'] ?? '*'))) $problems[] = 'ต้องอัปเดตแกนก่อน: โมดูลต้องการ core '.($m['requires']['core'] ?? '');
            foreach ($m['requires']['modules'] ?? [] as $dep => $constraint) { $d = $reg->get($dep); if (!$d || !\App\Support\Modules\ModuleRegistry::satisfies($d['version'], $constraint)) $problems[] = 'ต้องมีโมดูล '.$dep.' '.$constraint; }
        }
        if ($m['current_version'] && self::compareVersions((string) $m['version'], (string) $m['current_version']) < 0) $problems[] = 'แพ็กเกจเก่ากว่ารุ่นที่ติดตั้ง ('.$m['current_version'].') ไม่ติดตั้งย้อนรุ่น';
        if ($m['type'] === 'core') {
            // a release that cannot be reached in one step says so (requires.from): the school installs the stepping-stone release first
            $from = (string) ($m['requires']['from'] ?? '');
            if ($from !== '' && $m['current_version'] && self::compareVersions((string) $m['current_version'], $from) < 0) $problems[] = 'รุ่นที่ติดตั้ง ('.$m['current_version'].') เก่าเกินไปสำหรับแพ็กเกจนี้ ให้อัปเดตเป็นรุ่น '.$from.' ก่อน';
            // separately packaged modules that are switched on must accept the new core, otherwise they would switch themselves off after the update
            $shipped = array_flip(array_column($m['files'], 'path'));
            try {
                foreach (app(ModuleRegistry::class)->all() as $id => $mod) {
                    if (empty($mod['active']) || isset($shipped['modules/'.$id.'/module.json'])) continue;
                    $need = (string) ($mod['requires']['core'] ?? '*');
                    if (!\App\Support\Modules\ModuleRegistry::satisfies((string) $m['version'], $need)) $problems[] = 'โมดูล '.$id.' ที่เปิดใช้อยู่รองรับ core '.$need.' เท่านั้น ให้อัปเดตโมดูลนี้ก่อน';
                }
            } catch (\Throwable) {}
            // new libraries need a fresh PHP process for the finish step: say so now, not after the backup
            if (self::lockChanges($m) && !$this->finisher && self::finishMode() === null) $problems[] = self::noFinisherMessage();
        }
        // a web server that may not empty OPcache and does not revalidate soon would run the finish step on the old code: list it on the
        // update page, before the backup is made (a CLI starter learns the web server's settings only later, from the maintenance stub)
        if (!$this->finisher && is_array($oc = self::webOpcache()) && $oc['enabled'] && !$oc['usable'] && !$oc['validate'] && self::finishMode() === 'http') $problems[] = self::staleFinisherMessage();
        $bytes = array_sum(array_column($m['files'], 'bytes'));
        $free = @disk_free_space(base_path());
        if ($free !== false && $free < $bytes * 3 + 52428800) $problems[] = 'พื้นที่ว่างไม่พอ ต้องการประมาณ '.round(($bytes * 3) / 1048576).' MB';
        foreach (array_unique(array_map(fn ($f) => dirname(base_path($f['path'])), $m['files'])) as $dir) {
            $probe = $dir; while (!is_dir($probe) && $probe !== dirname($probe)) $probe = dirname($probe);
            if (!is_writable($probe)) { $problems[] = 'เขียนไม่ได้: '.$probe; break; }
        }
        if (!is_writable(storage_path('app/private'))) $problems[] = 'เขียน storage/app/private ไม่ได้';
        // the maintenance stub and the rollback work on the file driver's storage/framework/down
        if ((string) config('app.maintenance.driver', 'file') !== 'file') $problems[] = 'ต้องใช้โหมดปิดปรับปรุงแบบไฟล์ (APP_MAINTENANCE_DRIVER=file) ระหว่างอัปเดต';
        // files written by another system user (root from a shell) would no longer belong to the site
        if (function_exists('posix_geteuid') && ($owner = @fileowner(base_path('artisan'))) !== false && $owner !== posix_geteuid()) $problems[] = 'ผู้ใช้ระบบที่รันอัปเดต (uid '.posix_geteuid().') ไม่ใช่เจ้าของไฟล์ของเว็บ (uid '.$owner.') ให้รันด้วยผู้ใช้ของเว็บ';
        // doctor: any [ERR] refuses the update
        try { foreach (app(\App\Domain\Cms\DeploymentHealth::class)->errors((int) $bytes) as $e) $problems[] = '[ERR] '.$e; }
        catch (\Throwable $t) { $problems[] = '[ERR] doctor ตรวจไม่สำเร็จ: '.$t->getMessage(); }
        return $problems;
    }

    /** Cheap predictor of "this package replaces libraries": its composer.lock differs from the installed one. */
    private static function lockChanges(array $m): bool
    {
        foreach ($m['files'] as $f) if (($f['path'] ?? '') === 'composer.lock') return !is_file(base_path('composer.lock')) || !hash_equals((string) $f['sha256'], (string) hash_file('sha256', base_path('composer.lock')));
        return false;
    }

    private static function noFinisherMessage(): string
    {
        return 'แพ็กเกจนี้เปลี่ยนไลบรารีหลัก (vendor) ขั้นตอนหลังสลับไฟล์ต้องทำด้วย PHP ที่เริ่มใหม่ แต่เครื่องนี้เรียก PHP CLI ไม่ได้ (proc_open ถูกปิดหรือไม่พบ php) และเรียกเว็บของตัวเองไม่ได้ จึงไม่อัปเดตเพื่อไม่ให้เว็บเสี่ยง ให้รัน php artisan saan:update <ไฟล์> จาก SSH หรือ cron หรือกำหนด SAAN_PHP_CLI เป็นที่อยู่ของ php';
    }

    /**
     * How the finish step can run on new code only: 'process' (a PHP CLI child), 'http' (a request to this site), 'inline' (forced by
     * SAAN_UPDATE_FINISH=inline: same process, no isolation) or null when neither isolated way works on this host.
     */
    public static function finishMode(): ?string
    {
        static $memo = [];
        $want = (string) config('saan.update_finish', 'auto');
        if (array_key_exists($want, $memo)) return $memo[$want];
        if ($want === 'inline') return $memo[$want] = 'inline';
        if (in_array($want, ['auto', 'process'], true) && self::phpCli() !== null) return $memo[$want] = 'process';
        if (in_array($want, ['auto', 'http'], true) && self::loopbackWorks()) return $memo[$want] = 'http';
        return $memo[$want] = null;
    }

    /** Command prefix of a PHP CLI with the same major.minor as this process, or null (proc_open disabled, no binary found). @return list<string>|null */
    public static function phpCli(): ?array
    {
        if (!function_exists('proc_open')) return null;
        $exe = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
        $candidates = [(string) config('saan.php_cli', ''), PHP_SAPI === 'cli' ? PHP_BINARY : '', PHP_BINDIR.DIRECTORY_SEPARATOR.$exe, PHP_BINARY !== '' ? dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$exe : ''];
        // the same php.ini as this CLI process (a custom -c file would otherwise be lost and with it the extensions)
        $ini = PHP_SAPI === 'cli' ? php_ini_loaded_file() : false;
        foreach (array_unique(array_filter($candidates)) as $bin) {
            if (!@is_file($bin)) continue;
            $cmd = $ini ? [$bin, '-c', $ini] : [$bin];
            [$code, $out] = self::runProcess(array_merge($cmd, ['-r', 'echo PHP_VERSION_ID, ":", PHP_SAPI;']), null, 20);
            if ($code === 0 && preg_match('/(\d{5,6}):cli\s*$/', trim($out), $mm) && intdiv((int) $mm[1], 100) === intdiv(PHP_VERSION_ID, 100)) return $cmd;
        }
        return null;
    }

    /** Run a command without a shell and without loading any class. @return array{0:int,1:string} exit code (124 = timed out) and combined output */
    private static function runProcess(array $cmd, ?string $cwd, int $timeout): array
    {
        $out = tempnam(sys_get_temp_dir(), 'saan');
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $p = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']], $pipes, $cwd);
        if (!is_resource($p)) { @unlink($out); return [1, 'proc_open failed']; }
        $end = microtime(true) + $timeout; $code = 1;
        while (true) {
            $s = proc_get_status($p);
            if (!$s['running']) { $code = (int) $s['exitcode']; break; }
            if (microtime(true) > $end) { @proc_terminate($p); $code = 124; break; }
            usleep(100000);
        }
        @proc_close($p);
        $text = (string) @file_get_contents($out); @unlink($out);
        return [$code, $text];
    }

    /**
     * Run an artisan command of this install in a PHP CLI child. The web updater uses it where the work must not run inside the web process
     * (the two-app update), and `saan:update --all` uses it so that every package is applied by a process that booted on the files of the
     * package before it. @return array{0:int,1:string}|null exit code and output, or null when this host cannot start a PHP CLI
     */
    public static function artisanCli(array $args, int $timeout): ?array
    {
        $cli = self::phpCli();
        if ($cli === null) return null;
        return self::runProcess(array_merge($cli, ['artisan'], $args, ['--no-interaction']), base_path(), $timeout);
    }

    /**
     * Order in which a set of inspected packages must be installed: the core first, then modules so that every module comes after the
     * modules it requires (requires.modules). A requirement that is not in the set is left to the preflight of that package (it may be
     * installed already). @param array<string,array> $manifests keyed by anything (file name) @return list<string> keys in install order
     */
    public static function installOrder(array $manifests): array
    {
        $core = []; $mods = [];
        foreach ($manifests as $key => $m) { if (($m['type'] ?? '') === 'core') $core[] = $key; else $mods[(string) ($m['id'] ?? $key)] = $key; }
        ksort($mods); // a stable order for packages that do not depend on each other
        $out = $core; $state = [];
        $visit = function (string $id) use (&$visit, &$out, &$state, $mods, $manifests): void {
            if (($state[$id] ?? 0) === 2) return;
            if (($state[$id] ?? 0) === 1) throw new RuntimeException('แพ็กเกจโมดูลต้องการกันเป็นวง: '.$id);
            $state[$id] = 1;
            foreach (array_keys((array) ($manifests[$mods[$id]]['requires']['modules'] ?? [])) as $dep) if (isset($mods[$dep])) $visit((string) $dep);
            $state[$id] = 2; $out[] = $mods[$id];
        };
        foreach (array_keys($mods) as $id) $visit($id);
        return $out;
    }

    private static function finishUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/_saan/update-finish';
    }

    /** POST without any class (curl or the http stream wrapper). @return array|null decoded JSON object */
    private static function httpPost(string $url, array $fields, int $timeout): ?array
    {
        $body = http_build_query($fields); $raw = false;
        if (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            $raw = curl_exec($c);
        } elseif (ini_get('allow_url_fopen')) {
            $raw = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n", 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]));
        }
        $j = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($j) ? $j : null;
    }

    /** True when this site can call its own finish route (a second web worker answers and nothing in front blocks it). */
    private static function loopbackWorks(): bool
    {
        if (!preg_match('~^https?://~i', (string) config('app.url'))) return false;
        // the answer must come from THIS install (a staging copy whose APP_URL names production would otherwise send its finish token there):
        // the route returns the hash of a nonce that only a process reading the same storage folder can know
        $dir = storage_path('app/private/updates');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
        $nonce = bin2hex(random_bytes(16));
        if (@file_put_contents($dir.'/.probe', $nonce) === false) return false;
        try { $r = self::httpPost(self::finishUrl(), ['probe' => 1], 8); } finally { @unlink($dir.'/.probe'); }
        return is_array($r) && (int) ($r['saan_update'] ?? 0) === self::CONTRACT && hash_equals(hash('sha256', $nonce), (string) ($r['install'] ?? ''));
    }

    public function apply(string $zipPath, bool $backupDatabase = true): array
    {
        $lock = self::acquireLock();
        try { return $this->applyLocked($zipPath, $backupDatabase); }
        finally { self::releaseLock($lock); }
    }

    /** Same as apply() for a caller that already holds acquireLock(). */
    public function applyLocked(string $zipPath, bool $backupDatabase): array
    {
        // An update that was never settled still owns the snapshot of the files it changed: starting another one on top of it would let a later
        // recover put stale files back. It is settled here first (this process holds the update lock, so its starter is gone).
        foreach (self::unfinished() as $u) {
            if ((int) ($u['receipt']['contract'] ?? 0) < 1) continue; // written by the pre-release updater: never touched automatically
            $old = $this->recoverLocked($u['dir']);
            if (!empty($old['rolled_back'])) throw new RuntimeException('พบการอัปเดตก่อนหน้าที่ค้างอยู่และคืนไฟล์รุ่นเดิมให้แล้ว ยังไม่ได้เริ่มอัปเดตใหม่ กรุณาสั่งอัปเดตอีกครั้ง');
        }
        $m = $this->inspect($zipPath);
        $this->say('[OK] ลายเซ็นและไฟล์ถูกต้อง: '.$m['type'].' '.$m['id'].' '.($m['current_version'] ?? '-').' -> '.$m['version']);
        if ($problems = $this->preflight($m)) throw new RuntimeException("ตรวจความพร้อมไม่ผ่าน:\n- ".implode("\n- ", $problems));
        $stamp = gmdate('Ymd\THis\Z'); $work = storage_path('app/private/updates/'.$stamp.'-'.$m['id'].'-'.bin2hex(random_bytes(3)));
        if (!mkdir($work.'/snapshot', 0700, true)) throw new RuntimeException('สร้างพื้นที่ทำงานอัปเดตไม่ได้');
        $receipt = ['package' => basename($zipPath), 'manifest_sha256' => $m['manifest_sha256'], 'type' => $m['type'], 'id' => $m['id'], 'from' => $m['current_version'], 'to' => $m['version'], 'contract' => self::CONTRACT, 'started_at' => gmdate('c'), 'steps' => [], 'work' => $work,
            'was_down' => app()->isDownForMaintenance()]; // known from the first write: a recover never opens a site its admin had closed
        $step = function (string $name) use (&$receipt, $work) { $receipt['steps'][] = [$name, gmdate('c')]; self::writeJson($work.'/receipt.json', $receipt); };
        // nothing of the site has changed yet: a failure up to the swap only closes this folder
        $abandon = function (\Throwable $e, string $message) use (&$receipt, $step, $work) {
            self::removeTree($work.'/'.self::STAGE);
            $receipt['rolled_back'] = true; $receipt['error'] = $e->getMessage(); $step('abandoned_before_swap');
            // remember the history row: syncHistory() would otherwise insert a second one for this receipt
            try { if (($id = $this->record($receipt, 'rolled_back')) !== null) { $receipt['row_id'] = $id; self::writeJson($work.'/receipt.json', $receipt); } } catch (\Throwable) {}
            return new RuntimeException($message.': '.$e->getMessage(), 0, $e);
        };
        $step('started');
        if ($backupDatabase && !in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // never skip the backup silently: say so on screen and keep it in the receipt
            $receipt['warnings'][] = 'database_backup_skipped: driver '.DB::connection()->getDriverName().' is not supported by school:archive';
            $this->say('[WARN] ข้ามการสำรองฐานข้อมูลก่อนอัปเดต: driver '.DB::connection()->getDriverName().' ไม่รองรับ school:archive');
        }
        if ($backupDatabase && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            try {
                self::sweepStaleBackupTemp();
                $archive = storage_path('app/private/backups/pre-update-'.(config('saan.install_id') ? config('saan.install_id').'-'.\App\Support\Saan\Edition::current().'-' : '').$stamp.'.zip'); if (!is_dir(dirname($archive))) mkdir(dirname($archive), 0700, true);
                if (Artisan::call('school:archive', ['action' => 'backup', 'path' => $archive]) !== 0) throw new RuntimeException(trim(Artisan::output()));
            } catch (\Throwable $e) { throw $abandon($e, 'สำรองข้อมูลก่อนอัปเดตไม่สำเร็จ'); }
            $receipt['backup'] = $archive; $step('database_backup'); $this->say('[OK] สำรองข้อมูลแล้ว');
        }

        // 1. stage: the slow part (thousands of files) happens while the site is still serving the old release
        try {
            $zip = new \ZipArchive; if ($zip->open($zipPath) !== true) throw new RuntimeException('เปิดไฟล์แพ็กเกจไม่ได้');
            $manifestJson = (string) $zip->getFromName('manifest.json');
            if (!hash_equals((string) $m['manifest_sha256'], hash('sha256', $manifestJson))) throw new RuntimeException('ไฟล์แพ็กเกจถูกเปลี่ยนหลังตรวจลายเซ็น');
            file_put_contents($work.'/manifest.json', $manifestJson);
            $plan = $this->stage($zip, $m, $work); $zip->close();
            $mode = $this->finisher ? 'custom' : self::finishMode();
            if ($mode === null) {
                if (array_filter($plan, fn ($p) => str_starts_with($p, 'vendor/'))) throw new RuntimeException(self::noFinisherMessage());
                $mode = 'inline';
            }
            // a web starter knows its own server already: refuse the hopeless combination before anything is closed or moved
            if ($mode === 'http' && is_array($oc = self::webOpcache()) && $oc['enabled'] && !$oc['usable'] && !$oc['validate']) throw new RuntimeException(self::staleFinisherMessage());
            if ($mode === 'inline') $this->say('[WARN] ขั้นตอนหลังสลับไฟล์จะทำใน process เดิม (ไม่มี PHP CLI และเรียกเว็บตัวเองไม่ได้ หรือกำหนด SAAN_UPDATE_FINISH=inline)');
            if ($mode === 'process') $this->cli = self::phpCli();
            $token = bin2hex(random_bytes(32)); file_put_contents($work.'/finish.token', $token); @chmod($work.'/finish.token', 0600);
        } catch (\Throwable $e) { throw $abandon($e, 'เตรียมไฟล์ไม่สำเร็จ ยังไม่ได้แก้ไฟล์ของเว็บ'); }
        $receipt['finish_mode'] = $mode; $receipt['staged'] = count($plan);
        $wasDown = (bool) $receipt['was_down'];
        // with a separate finisher this process must not load a class after the swap, so it writes no database row then (syncHistory() does, later)
        $this->mayLoad = in_array($mode, ['inline', 'custom'], true);
        // files this package will create are listed up front so a recover can remove them if the process dies mid-swap
        $receipt['created'] = array_values(array_filter($plan, fn ($p) => !is_file(base_path($p))));
        $step('staged'); $this->say('[OK] เตรียมไฟล์ใหม่ '.count($plan).' ไฟล์ (เว็บยังเปิดอยู่)');
        // everything the starting process needs after the swap is resolved now, on the old release
        try { $receipt['row_id'] = $this->record($receipt, 'started'); } catch (\Throwable) {}
        // classes the console/HTTP kernel touches after this method returns, loaded now from the old release (the callers also end the process themselves)
        foreach (self::PRELOAD as $class) { try { class_exists($class); } catch (\Throwable) {} }
        app()->maintenanceMode();

        // 2. swap, in maintenance mode. The 503 page is rendered now; storage/framework/maintenance.php (included by public/index.php before the
        // autoloader) is replaced by the updater's own stub, so no visitor request ever loads a half-swapped vendor tree and a dead starter is healed.
        try {
            if (!$wasDown) {
                try { $down = Artisan::call('down', ['--retry' => 60, '--render' => 'errors::503']); } catch (\Throwable) { $down = 1; }
                if ($down !== 0 || !app()->isDownForMaintenance()) Artisan::call('down', ['--retry' => 60]);
                if (!app()->isDownForMaintenance()) throw new RuntimeException('เข้าโหมดปิดปรับปรุงไม่ได้');
            }
            $this->installStub($work);
        } catch (\Throwable $e) {
            if (!$wasDown) $this->up();
            throw $abandon($e, 'ปิดเว็บเพื่อสลับไฟล์ไม่ได้ ยังไม่ได้แก้ไฟล์ของเว็บ');
        }
        $step('maintenance_on'); $t0 = microtime(true);
        $error = null; $finishLock = null; $finisherBusy = false;
        try {
            $this->swap($plan, $work);
            $step('files_written'); $this->say('[OK] เขียนไฟล์ใหม่ '.count($plan).' ไฟล์');
            // files the previous release shipped and this one dropped (commands, modules and migrations are loaded by glob, so they would keep running)
            $removed = $this->removeStale($m, $work);
            if ($removed) { $receipt['removed'] = $removed; $step('stale_files_removed'); $this->say('[OK] ลบไฟล์ที่รุ่นใหม่เอาออกแล้ว '.count($removed).' ไฟล์'); }
            self::dropBootCaches();
            $receipt['swap_seconds'] = round(microtime(true) - $t0, 2);
            // 3. finish on new code only
            $step('finish_started');
            // HTTP finish on a host that forbids the site to empty OPcache: the stub could not drop the old files, so the finish request
            // would run the old release's classes. A server that validates timestamps picks the new files up after its revalidation
            // interval: wait for it. One that does not would run the new migrations on old code: refuse (the catch below rolls back).
            if ($mode === 'http' && is_array($oc = self::webOpcache($work, $token)) && $oc['enabled'] && !$oc['usable']) {
                if (!$oc['validate']) throw new RuntimeException(self::staleFinisherMessage());
                $this->say('[WARN] โฮสต์นี้ไม่ให้เว็บล้าง OPcache เอง (opcache.restrict_api) รอ '.$oc['wait'].' วินาทีให้ PHP ของเว็บตรวจไฟล์ใหม่ก่อนทำขั้นตอนหลังสลับไฟล์'); sleep($oc['wait']); $this->waited = true;
            }
            $this->runFinisher($mode, $work, $token);
            [$result, $finishLock, $finisherBusy] = self::finishResult($work, (int) config('saan.update_finish_timeout', 1800));
            if (empty($result['ok'])) throw new RuntimeException((string) ($result['error'] ?? 'ขั้นตอนหลังสลับไฟล์ไม่รายงานผล'));
        } catch (\Throwable $e) { $error = $e; }

        // 4. settle, still holding finish.lock so a late finisher cannot start while files move (an orphaned finisher may already have settled:
        // settle() then only reads the receipt)
        try {
            if ($error) {
                try { self::writeJson($work.'/receipt.json', $receipt); } catch (\Throwable) {}
                // a finisher that still holds its lock is migrating right now: the files are never pulled from under it. It settles the update
                // itself once this process is gone; the scheduler and the maintenance stub do so otherwise.
                if ($finisherBusy) throw new RuntimeException('ขั้นตอนหลังสลับไฟล์ยังทำงานอยู่เกินเวลาที่กำหนด จึงยังไม่คืนไฟล์ เมื่อขั้นตอนนั้นจบจะปิดงานเอง (ตรวจด้วย php artisan saan:update --recover)', 0, $error);
                $this->say('[ERR] '.$error->getMessage().' -> กำลังคืนไฟล์รุ่นเดิม');
                $this->settle($work, false, $error->getMessage());
                throw new RuntimeException('อัปเดตไม่สำเร็จ คืนไฟล์รุ่นเดิมแล้ว: '.$error->getMessage(), 0, $error);
            }
            $receipt = $this->settle($work, true);
        } finally {
            if ($finishLock) { @flock($finishLock, LOCK_UN); @fclose($finishLock); }
        }
        $this->say('[OK] ปิดเว็บชั่วคราว '.round(microtime(true) - $t0, 1).' วินาที');
        try { $gone = self::pruneBackups(self::KEEP_BACKUPS); if ($gone) $this->say('[OK] ลบสำรองก่อนอัปเดตเก่า '.$gone.' ไฟล์ (เก็บ '.self::KEEP_BACKUPS.' รุ่นล่าสุด)'); } catch (\Throwable) {}
        try { self::pruneWork(self::KEEP_SNAPSHOTS); } catch (\Throwable) {}
        $this->say('[OK] อัปเดตเสร็จ '.$m['id'].' '.$m['version']);
        return $receipt;
    }

    /** Write every file that differs from the installed one under <work>/new and verify it there. @return list<string> paths to swap */
    private function stage(\ZipArchive $zip, array $m, string $work): array
    {
        $plan = [];
        foreach ($m['files'] as $f) {
            $path = (string) $f['path']; $target = base_path($path);
            // the install's own storage is kept; identical files are not touched
            if (is_file($target) && (str_starts_with($path, 'storage/') || hash_file('sha256', $target) === $f['sha256'])) continue;
            $tmp = $work.'/'.self::STAGE.'/'.$path;
            if (!is_dir(dirname($tmp)) && !@mkdir(dirname($tmp), 0700, true) && !is_dir(dirname($tmp))) throw new RuntimeException('สร้างโฟลเดอร์พักไฟล์ไม่ได้: '.$path);
            $in = $zip->getStream('payload/'.$path); $out = $in ? @fopen($tmp, 'wb') : false;
            if (!$in || !$out) throw new RuntimeException('พักไฟล์ไม่ได้: '.$path);
            stream_copy_to_stream($in, $out); fclose($in);
            if (!fclose($out) || filesize($tmp) !== (int) $f['bytes'] || !hash_equals((string) $f['sha256'], (string) hash_file('sha256', $tmp))) throw new RuntimeException('ไฟล์ที่พักไว้ไม่ตรงกับแพ็กเกจ (ดิสก์เต็มหรือเขียนไม่ครบ): '.$path);
            $plan[] = $path;
        }
        return $plan;
    }

    /** Move each live file into the snapshot and its staged replacement into place. Rename keeps the window short; copy is the fallback across filesystems. */
    private function swap(array $plan, string $work): void
    {
        foreach ($plan as $path) {
            $target = base_path($path); $src = $work.'/'.self::STAGE.'/'.$path; $perms = false;
            if (is_file($target)) {
                $perms = @fileperms($target);
                $snap = $work.'/snapshot/'.$path;
                if (!is_dir(dirname($snap)) && !@mkdir(dirname($snap), 0700, true) && !is_dir(dirname($snap))) throw new RuntimeException('สำรองไฟล์เดิมไม่ได้: '.$path);
                if (!@rename($target, $snap) && !@copy($target, $snap)) throw new RuntimeException('สำรองไฟล์เดิมไม่ได้: '.$path);
            } elseif (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) throw new RuntimeException('สร้างโฟลเดอร์ไม่ได้: '.$path);
            if (!@rename($src, $target)) { if (!@copy($src, $target)) throw new RuntimeException('เขียนไฟล์ไม่สำเร็จ: '.$path); @unlink($src); }
            if ($perms !== false) @chmod($target, $perms & 0777);
            self::forget($target);
        }
    }

    private function runFinisher(string $mode, string $work, string $token): void
    {
        $name = basename($work);
        if ($this->finisher) { ($this->finisher)($work); return; }
        if ($mode === 'process') {
            [$code, $out] = self::runProcess(array_merge((array) $this->cli, ['artisan', 'saan:update', '--finish='.$name, '--no-interaction']), base_path(), (int) config('saan.update_finish_timeout', 1800));
            @file_put_contents($work.'/finish.out', "exit=$code\n".$out);
            return;
        }
        if ($mode === 'http') { $r = self::httpPost(self::finishUrl(), ['work' => $name, 'token' => $token], (int) config('saan.update_finish_timeout', 1800)); @file_put_contents($work.'/finish.out', json_encode($r)); return; }
        try { $this->finish($name, false); } catch (\Throwable $e) { @file_put_contents($work.'/finish.out', $e->getMessage()); }
    }

    /**
     * The finisher's answer. Waits until no finisher holds finish.lock and then KEEPS the lock (the caller releases it after settling), so a slow or
     * late finisher can neither be rolled back from under its feet nor start while files are being restored.
     * @return array{0:array,1:resource|null,2:bool} answer, held lock, true when a finisher still held the lock after $wait seconds
     */
    private static function finishResult(string $work, int $wait): array
    {
        $h = @fopen($work.'/finish.lock', 'ce'); $end = time() + max(5, $wait); $held = false;
        if ($h) { while (!($held = flock($h, LOCK_EX | LOCK_NB)) && time() < $end) usleep(250000); }
        if ($h && !$held) { fclose($h); return [['ok' => false, 'error' => 'ขั้นตอนหลังสลับไฟล์ยังทำงานอยู่'], null, true]; }
        $r = self::readJson($work.'/finish.json');
        if (is_array($r)) return [$r, $h ?: null, false];
        $tail = trim((string) @file_get_contents($work.'/finish.out'));
        return [['ok' => false, 'error' => 'โค้ดรุ่นใหม่เริ่มทำงานไม่ได้หรือไม่รายงานผล'.($tail !== '' ? ': '.mb_substr($tail, -700) : '')], $h ?: null, false];
    }

    /**
     * FINISH step, run by the NEW release in a fresh process or request (contract 1): migrate, repair key collation, clear caches, health check,
     * then write finish.json. $fresh = false only for the inline fallback, where this process still holds the old configuration.
     * When the process that started the update is gone (its lock is free) the outcome is settled here, so the site never stays in maintenance mode.
     */
    public function finish(string $name, bool $fresh = true): array
    {
        $work = self::workDir($name);
        $fl = @fopen($work.'/finish.lock', 'ce');
        if (!$fl || !flock($fl, LOCK_EX | LOCK_NB)) { if ($fl) fclose($fl); throw new UpdateBusyException('ขั้นตอนนี้กำลังทำงานอยู่แล้ว'); }
        try {
            $receipt = self::readJson($work.'/receipt.json');
            $steps = is_array($receipt) ? array_column((array) ($receipt['steps'] ?? []), 0) : [];
            // rollback_started: the files are going back (or went back half way): nothing may migrate any more
            if (!is_array($receipt) || isset($receipt['completed_at']) || !empty($receipt['rolled_back']) || !in_array('finish_started', $steps, true) || in_array('rollback_started', $steps, true)) throw new RuntimeException('ไม่มีการอัปเดตที่รอขั้นตอนนี้');
            $result = self::readJson($work.'/finish.json'); // answered once: a retried call never migrates twice, it only settles an orphan below
            if (!is_array($result)) try {
                if ($fresh && ($receipt['type'] ?? '') === 'core' && (string) config('saan.version') !== (string) ($receipt['to'] ?? '')) throw new RuntimeException('โค้ดที่ทำงานอยู่ยังเป็นรุ่น '.config('saan.version').' ไม่ใช่ '.($receipt['to'] ?? '').' (OPcache หรือ config cache ค้าง)');
                // modules that arrived with this package were not present when an inline process booted: register their migrations now
                foreach (glob(base_path('modules/*/database/migrations'), GLOB_ONLYDIR) ?: [] as $dir) app('migrator')->path($dir);
                if (Artisan::call('migrate', ['--force' => true]) !== 0) throw new RuntimeException('migrate ไม่สำเร็จ: '.trim(Artisan::output()));
                // modules installed after the core migration ran never got utf8mb4_bin on their *_key columns: repair is idempotent and expand-only
                try { $fixed = \App\Support\Saan\KeyCollation::repair(); if ($fixed) $this->say('[OK] ตั้ง collation แบบ binary ให้คอลัมน์ key '.$fixed.' คอลัมน์'); } catch (\Throwable $e) { $this->say('[WARN] ตรวจ collation ไม่สำเร็จ: '.$e->getMessage()); }
                self::clearCaches();
                $this->healthCheck();
                $result = ['ok' => true, 'contract' => self::CONTRACT, 'version' => (string) config('saan.version'), 'finished_at' => gmdate('c')];
                self::writeJson($work.'/finish.json', $result);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'contract' => self::CONTRACT, 'error' => $e->getMessage(), 'finished_at' => gmdate('c')];
                self::writeJson($work.'/finish.json', $result);
            }
            // orphan: the starting process is gone when its lock can be taken (it is opened close-on-exec, so this process never holds a copy)
            $h = @fopen(dirname($work).'/.lock', 'ce');
            if ($h && flock($h, LOCK_EX | LOCK_NB)) {
                try { $this->settle($work, !empty($result['ok']), (string) ($result['error'] ?? '')); $this->say('[WARN] process ที่เริ่มอัปเดตไม่อยู่แล้ว ขั้นตอนนี้ปิดงานเอง'); }
                finally { @flock($h, LOCK_UN); }
            }
            if ($h) @fclose($h);
            return $result;
        } finally { @flock($fl, LOCK_UN); @fclose($fl); }
    }

    /**
     * Close an update whose files were swapped: the site comes up on the new release ($ok), or every file goes back from the snapshot.
     * The receipt on disk is the source of truth, and a settled receipt is returned unchanged, so the starter, an orphaned finisher and
     * `--recover` can all end here. Uses no class that was not loaded before the swap. $takeBack: also undo an update that had completed.
     */
    private function settle(string $work, bool $ok, string $error = '', bool $takeBack = false, bool $recovered = false): array
    {
        $receipt = self::readJson($work.'/receipt.json');
        if (!is_array($receipt)) throw new RuntimeException('อ่านบันทึกอัปเดตไม่ได้');
        if (!empty($receipt['rolled_back']) || (isset($receipt['completed_at']) && !$takeBack)) return $receipt;
        if ($ok && in_array('rollback_started', array_column((array) ($receipt['steps'] ?? []), 0), true)) $ok = false; // a rollback that began is finished, never reversed
        if ($ok) {
            $this->flushWebOpcache($work, $receipt); // while the site is still closed and the stub still answers
            $this->removeStub($work, $receipt);
            if (empty($receipt['was_down'])) $this->up();
            @unlink($work.'/finish.token');
            $receipt['steps'][] = ['health_ok', gmdate('c')]; $receipt['steps'][] = ['maintenance_off', gmdate('c')];
            try {
                $m = self::readJson($work.'/manifest.json');
                if (is_array($m) && isset($m['type'], $m['id'], $m['files'])) {
                    $prev = $this->installedFile($m); $receipt['installed_prev'] = is_file($prev);
                    if ($receipt['installed_prev']) @copy($prev, $work.'/installed.prev.json');
                    $this->rememberInstalled($m);
                }
            } catch (\Throwable) {}
            $receipt['completed_at'] = gmdate('c'); $receipt['steps'][] = ['completed', gmdate('c')];
            self::writeJson($work.'/receipt.json', $receipt);
            if ($this->mayLoad) { try { self::syncHistory(); } catch (\Throwable) {} }
            self::removeTree($work.'/'.self::STAGE);
            return $receipt;
        }
        // from here on nothing may migrate: the marker and the removed answer stop a late finisher and tell every later recover to go back, not forward
        $receipt['steps'][] = ['rollback_started', gmdate('c')];
        try { self::writeJson($work.'/receipt.json', $receipt); } catch (\Throwable) {}
        @unlink($work.'/finish.json');
        // An update that had completed is taken back while the site is OPEN (saan:update --both when the public side failed, or
        // --recover --completed): close it and put the stub in front before a single file moves, exactly as the forward swap does.
        $closedHere = false;
        if (!is_file(storage_path('framework/down'))) {
            @file_put_contents(storage_path('framework/down'), json_encode(['except' => ['_saan/update-finish'], 'redirect' => null, 'retry' => 60, 'refresh' => null, 'secret' => null, 'status' => 503, 'template' => null]));
            try { @file_put_contents($work.'/finish.token', bin2hex(random_bytes(32))); @chmod($work.'/finish.token', 0600); $this->installStub($work); } catch (\Throwable) {}
            $closedHere = true;
        }
        [$receipt['restored'], $failed] = $this->restore($work, $receipt);
        if ($failed) {
            // a file could not be put back (held by another program, permissions): the site stays closed and the update stays unfinished, so the
            // maintenance stub, the scheduler or `saan:update --recover` try again. Opening the site on a mixed tree would be worse.
            $receipt['restore_failed'] = array_slice($failed, 0, 20);
            try { self::writeJson($work.'/receipt.json', $receipt); } catch (\Throwable) {}
            throw new RuntimeException('คืนไฟล์รุ่นเดิมไม่ครบ '.count($failed).' ไฟล์ (เช่น '.$failed[0].') เว็บยังปิดอยู่ ระบบจะลองคืนอีกครั้งเอง หรือรัน php artisan saan:update --recover');
        }
        unset($receipt['restore_failed']);
        // a completed update also moved installed-<type>-<id>.json to the new release: put the previous list back so the next removeStale works from the restored release
        if ($takeBack && isset($receipt['type'], $receipt['id']) && array_key_exists('installed_prev', $receipt)) {
            $list = $this->installedFile($receipt);
            if (!empty($receipt['installed_prev'])) { if (is_file($work.'/installed.prev.json')) @copy($work.'/installed.prev.json', $list); }
            elseif (is_file($list)) @unlink($list);
        }
        self::dropBootCaches();
        $receipt['rolled_back'] = true; $receipt['error'] = $error !== '' ? $error : (string) ($receipt['error'] ?? 'rolled back');
        if ($recovered) $receipt['recovered_at'] = gmdate('c');
        $receipt['steps'][] = ['rolled_back', gmdate('c')];
        self::writeJson($work.'/receipt.json', $receipt);
        $this->flushWebOpcache($work, $receipt); // the old files are back on disk: the web server must not go on with the failed release's
        @unlink($work.'/finish.token');
        $this->removeStub($work, $receipt);
        if (empty($receipt['was_down']) || $closedHere) $this->up();
        if ($this->mayLoad) { try { self::syncHistory(); } catch (\Throwable) {} }
        self::removeTree($work.'/'.self::STAGE);
        return $receipt;
    }

    /** Classes the console and HTTP kernels load when a command or request ends: loaded before the swap so the old process never reads them from the new tree. */
    private const PRELOAD = [
        \Illuminate\Console\Events\CommandFinished::class, \Illuminate\Foundation\Events\Terminating::class, \Illuminate\Http\JsonResponse::class,
        \Illuminate\Foundation\Http\Events\RequestHandled::class, \Illuminate\Routing\Events\ResponsePrepared::class, \Illuminate\Database\QueryException::class,
        \Illuminate\Database\LostConnectionDetector::class, \Symfony\Component\HttpFoundation\Cookie::class, \Illuminate\Session\Middleware\StartSession::class,
    ];

    /** False in the process that swapped files for a separate finisher: it then writes no database row (syncHistory() brings the table up to date later). */
    private bool $mayLoad = true;
    /** True once the web server confirmed that its OPcache was emptied for this update or rollback. */
    private bool $webFlushed = false;
    /** True once this update has waited for the web server's revalidation interval (hosts where OPcache cannot be emptied): never twice. */
    private bool $waited = false;

    /**
     * Bring system_updates in line with the receipts: one row per update, "started" before the swap, then its final status. The starting
     * process cannot write it after the swap, so the finisher, `--recover`, the scheduler tick and the admin page call this. Returns rows changed.
     */
    public static function syncHistory(): int
    {
        if (!Schema::hasTable('system_updates')) return 0;
        $n = 0;
        foreach (glob(storage_path('app/private/updates/*/receipt.json')) ?: [] as $file) {
            $r = self::readJson($file);
            if (!is_array($r) || !isset($r['type'], $r['id'], $r['to'], $r['manifest_sha256'])) continue;
            // rolled_back first: an update that completed and was taken back (saan:update --both) keeps its completed_at
            $status = !empty($r['rolled_back']) ? 'rolled_back' : (isset($r['completed_at']) ? 'completed' : 'started');
            $row = ['status' => $status, 'receipt' => json_encode($r, JSON_UNESCAPED_UNICODE)];
            $id = (int) ($r['row_id'] ?? 0);
            if ($id > 0) { $n += DB::table('system_updates')->where('id', $id)->where('status', '!=', $status)->update($row); continue; }
            if ($status === 'started') continue;
            $r['row_id'] = (int) DB::table('system_updates')->insertGetId($row + ['package_type' => $r['type'], 'package_id' => $r['id'], 'from_version' => $r['from'] ?? null, 'to_version' => $r['to'], 'manifest_sha256' => $r['manifest_sha256'], 'created_at' => now()]);
            try { self::writeJson($file, $r); } catch (\Throwable) {}
            $n++;
        }
        return $n;
    }

    /**
     * storage/framework/maintenance.php for the time between "site closed" and "update settled". public/index.php includes that file before the
     * autoloader, so it works while vendor/ is half swapped. It answers 503 by itself (only the finish route goes on to the framework). When the
     * update lock and the finish lock are both free, the starting process died and no finisher runs: the stub then puts the snapshot back with
     * plain file functions and opens the site, or, when every step had passed, only opens it. A school without cron or SSH is therefore never
     * left closed or on a mixed tree; the next visitor's request heals it.
     */
    private function installStub(string $work): void
    {
        $file = storage_path('framework/maintenance.php');
        if (is_file($file)) @copy($file, $work.'/maintenance.prev.php');
        $app = app();
        $vars = ['work' => $work, 'base' => base_path(), 'down' => storage_path('framework/down'), 'self' => $file, 'views' => (string) config('view.compiled'),
            'caches' => [$app->getCachedConfigPath(), $app->getCachedRoutesPath(), $app->getCachedEventsPath(), $app->getCachedServicesPath(), $app->getCachedPackagesPath()]];
        $code = "<?php\n// Saan updater: maintenance stub of one update (Updater contract ".self::CONTRACT."). Generated; removed when the update is settled.\n\$u = ".var_export($vars, true).";\n".self::STUB;
        if (@file_put_contents($file, $code) === false) throw new RuntimeException('เขียนไฟล์ปิดปรับปรุงไม่ได้: storage/framework/maintenance.php');
        self::forget($file);
    }

    /** Take the updater's stub away: the site's own maintenance file comes back when the admin had closed the site before the update. */
    private function removeStub(string $work, array $receipt): void
    {
        $file = storage_path('framework/maintenance.php');
        if (!empty($receipt['was_down']) && is_file($work.'/maintenance.prev.php')) @copy($work.'/maintenance.prev.php', $file);
        elseif (is_file($file) && str_contains((string) @file_get_contents($file, false, null, 0, 200), 'Saan updater: maintenance stub')) @unlink($file);
        self::forget($file);
    }

    /** Body of the maintenance stub (plain PHP, no class of the application or of vendor/). $u is defined by installStub(). */
    private const STUB = <<<'PHP'
$json = static function (string $f): ?array { $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null; return is_array($d) ? $d : null; };
$save = static function (array $r) use ($u): void { $t = $u['work'].'/receipt.json.'.bin2hex(random_bytes(4)).'.tmp'; if (@file_put_contents($t, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) @rename($t, $u['work'].'/receipt.json'); };
$safe = static fn ($rel): bool => is_string($rel) && $rel !== '' && strpos($rel, '..') === false && strpos($rel, '\\') === false && $rel[0] !== '/' && !preg_match('~(^|/)\.env$~', $rel);
$drop = static function (string $f): void { if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true); @unlink($f); };
$heal = static function () use ($u, $json, $save, $safe, $drop): bool { // true = it opened the site (old files back, or the finished new release)
    $lock = @fopen(dirname($u['work']).'/.lock', 'ce');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return false; } // the process that started the update is alive
    $fl = @fopen($u['work'].'/finish.lock', 'ce');
    try {
        if (!$fl || !flock($fl, LOCK_EX | LOCK_NB)) return false; // a finisher is migrating: it settles the update itself
        $r = $json($u['work'].'/receipt.json');
        if (!$r || !empty($r['completed_at']) || !empty($r['rolled_back'])) return false;
        $steps = array_column((array) ($r['steps'] ?? []), 0);
        $fin = $json($u['work'].'/finish.json');
        if ($fin && !empty($fin['ok']) && !in_array('rollback_started', $steps, true)) {
            // every step passed and the tree is the new release: open the site; `saan:update --recover` or the next update records the completion
            if (empty($r['was_down'])) { @unlink($u['down']); @unlink($u['self']); }
            return true;
        }
        if (!in_array('rollback_started', $steps, true)) { $r['steps'][] = ['rollback_started', gmdate('c')]; $save($r); }
        @unlink($u['work'].'/finish.json'); @unlink($u['work'].'/finish.token');
        $snap = $u['work'].'/snapshot'; $files = []; $failed = 0; $restored = 0;
        if (is_dir($snap)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($snap, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $files[] = $f->getPathname();
        foreach ($files as $from) {
            $rel = str_replace('\\', '/', substr($from, strlen($snap) + 1));
            if (!$safe($rel)) continue;
            $to = $u['base'].'/'.$rel;
            if (!is_dir(dirname($to))) @mkdir(dirname($to), 0755, true);
            if (@rename($from, $to) || @copy($from, $to)) { $restored++; @touch($to); if (substr($to, -4) === '.php' && function_exists('opcache_invalidate')) @opcache_invalidate($to, true); }
            else $failed++;
        }
        if ($failed) return false; // the site stays closed; the next request tries again
        foreach ((array) ($r['created'] ?? []) as $rel) if ($safe($rel) && is_file($u['base'].'/'.$rel)) $drop($u['base'].'/'.$rel);
        foreach ($u['caches'] as $c) if (is_string($c) && is_file($c)) $drop($c);
        if ($u['views'] !== '' && is_dir($u['views'])) foreach (glob($u['views'].'/*.php') ?: [] as $v) $drop($v);
        $r['rolled_back'] = true; $r['restored'] = (int) ($r['restored'] ?? 0) + $restored; $r['steps'][] = ['rolled_back', gmdate('c')];
        $r['error'] = (string) ($r['error'] ?? 'the process that started the update died; the previous files were put back by the maintenance stub');
        $save($r);
        if (empty($r['was_down'])) { @unlink($u['down']); @unlink($u['self']); }
        elseif (is_file($u['work'].'/maintenance.prev.php')) @copy($u['work'].'/maintenance.prev.php', $u['self']);
        else @unlink($u['self']);
        return true;
    } finally { if ($fl) { @flock($fl, LOCK_UN); @fclose($fl); } @flock($lock, LOCK_UN); @fclose($lock); }
};
// Every PHP file of the site out of this web server's OPcache. The starter may be a CLI process, which cannot reach it, and the swap is faster
// than the revalidation interval: without this the finish step or the first visitors would run files of the other release.
$flush = static function () use ($u): int {
    if (!function_exists('opcache_invalidate')) return 0;
    $n = 0;
    foreach (['app', 'bootstrap', 'config', 'database', 'installer', 'lang', 'modules', 'public', 'resources', 'routes', 'vendor'] as $d) {
        if (!is_dir($u['base'].'/'.$d)) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($u['base'].'/'.$d, FilesystemIterator::SKIP_DOTS)) as $f) if (substr($f->getFilename(), -4) === '.php') { @opcache_invalidate($f->getPathname(), true); $n++; }
    }
    @opcache_invalidate($u['base'].'/artisan', true);
    foreach ($u['caches'] as $c) if (is_string($c)) @opcache_invalidate($c, true);
    if ($u['views'] !== '' && is_dir($u['views'])) foreach (glob($u['views'].'/*.php') ?: [] as $v) { @opcache_invalidate($v, true); $n++; }
    // Scripts OPcache still holds although their file is gone: compiled views a CLI starter or finisher deleted. Blade writes the new view
    // under the same name, and with opcache.validate_timestamps=0 the old opcodes would be used for ever. (opcache.restrict_api may hide
    // the list: the folder walk above is then all there is.)
    $st = function_exists('opcache_get_status') ? @opcache_get_status(true) : false;
    if (is_array($st)) foreach (array_keys((array) ($st['scripts'] ?? [])) as $p) if (strpos((string) $p, $u['base'].'/') === 0 || strpos((string) $p, $u['base'].DIRECTORY_SEPARATOR) === 0) { @opcache_invalidate((string) $p, true); $n++; }
    return $n;
};
// What the starter needs to know about THIS web server's OPcache (a CLI process sees only its own settings): is there a cache at all, may
// this site empty it (opcache.restrict_api can forbid it), and does the server notice changed files by itself.
$opcache = static function () use ($u): array {
    $on = function_exists('opcache_invalidate') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
    $usable = !$on || is_array(function_exists('opcache_get_status') ? @opcache_get_status(false) : false) || @opcache_invalidate($u['self'], true) === true;
    return ['enabled' => $on, 'usable' => $usable, 'validate' => !$on || filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN), 'freq' => (int) ini_get('opcache.revalidate_freq')];
};
$healed = $heal();
if ($healed) $flush();
$data = $json($u['down']);
if (!$data) return; // not closed any more: the framework answers
$uri = rawurldecode(trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/'));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($uri === '_saan/update-finish' || substr($uri, -20) === '/_saan/update-finish')) {
    // the finish step of this update, or the starter asking for the OPcache to be emptied: both prove themselves with the update's one-time token
    $token = is_file($u['work'].'/finish.token') ? trim((string) @file_get_contents($u['work'].'/finish.token')) : '';
    if ($token !== '' && is_string($_POST['token'] ?? null) && hash_equals($token, $_POST['token']) && ($_POST['work'] ?? '') === basename($u['work'])) {
        $n = $flush(); // before the framework loads a single file
        if (!empty($_POST['opcache'])) { header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode(['saan_opcache' => $n] + $opcache()); exit; }
    }
    return; // the route checks the token again
}
http_response_code((int) ($data['status'] ?? 503));
if (isset($data['retry'])) header('Retry-After: '.(int) $data['retry']);
header('Cache-Control: no-store');
if (strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false) { header('Content-Type: application/json'); echo '{"message":"Service Unavailable"}'; }
else { header('Content-Type: text/html; charset=utf-8'); echo is_string($data['template'] ?? null) ? $data['template'] : '<!doctype html><html lang="th"><meta charset="utf-8"><meta name="robots" content="noindex"><title>กำลังปรับปรุงระบบ</title><p>กำลังปรับปรุงระบบ กรุณารอสักครู่</p></html>'; }
exit;
PHP;

    /**
     * Put every snapshot file back (rename, copy as fallback, a few retries for a file another program holds for a moment) and delete the files
     * the update created. @return array{0:int,1:list<string>} files restored, paths that could not be restored
     */
    private function restore(string $work, array $receipt): array
    {
        $restored = 0; $failed = []; $snapshot = $work.'/snapshot'; $files = [];
        if (is_dir($snapshot)) foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($snapshot, \FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $files[] = $file->getPathname();
        foreach ($files as $from) {
            $rel = str_replace('\\', '/', substr($from, strlen($snapshot) + 1));
            if (!$this->allowedRecoverPath($rel)) continue;
            $target = base_path($rel);
            if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
            $ok = false;
            for ($try = 0; $try < 6 && !$ok; $try++) { if ($try) { usleep(150000); clearstatcache(true, $target); } $ok = @rename($from, $target) || @copy($from, $target); }
            // touch: the compiled view and OPcache of the failed release must look older than the restored source
            if ($ok) { $restored++; @touch($target); self::forget($target); } else $failed[] = $rel;
        }
        // files the update created go only when every old file is back: a second attempt must still find the same picture
        if (!$failed) foreach ((array) ($receipt['created'] ?? []) as $rel) if (is_string($rel) && $this->allowedRecoverPath($rel) && is_file(base_path($rel))) { @unlink(base_path($rel)); self::forget(base_path($rel)); }
        return [(int) ($receipt['restored'] ?? 0) + $restored, $failed];
    }

    private function installedFile(array $m): string
    {
        return storage_path('app/private/updates/installed-'.$m['type'].'-'.$m['id'].'.json');
    }

    /** Remember which files this package installed, so the next release can remove the ones it no longer ships. */
    private function rememberInstalled(array $m): void
    {
        $paths = array_values(array_filter(array_column($m['files'], 'path'), fn ($p) => !str_starts_with((string) $p, 'storage/')));
        file_put_contents($this->installedFile($m), json_encode(['version' => $m['version'], 'paths' => $paths], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * Delete files of the previous release (installed-<type>-<id>.json) and files named in the signed manifest's "removed" list that are not in this release.
     * Only paths this package type may write; every file is copied to the snapshot first so a rollback or `--recover` brings it back. @return string[]
     */
    private function removeStale(array $m, string $work): array
    {
        $old = (array) (json_decode((string) @file_get_contents($this->installedFile($m)), true)['paths'] ?? []);
        $candidates = array_unique(array_merge($old, array_values(array_filter((array) ($m['removed'] ?? []), 'is_string'))));
        $fold = DIRECTORY_SEPARATOR === '\\' ? 'strtolower' : 'strval'; // NTFS: a file whose name changed only in case is the same file
        $keep = array_flip(array_map($fold, array_column($m['files'], 'path')));
        $out = [];
        foreach ($candidates as $p) {
            if (!is_string($p) || isset($keep[$fold($p)]) ||str_starts_with($p, 'storage/') || !$this->allowed($m, $p)) continue;
            $target = base_path($p);
            if (!is_file($target) || is_link($target)) continue;
            $snap = $work.'/snapshot/'.$p;
            if (!is_dir(dirname($snap))) mkdir(dirname($snap), 0700, true);
            if (!copy($target, $snap)) throw new RuntimeException('สำรองไฟล์ที่จะลบไม่ได้: '.$p);
            if (!@unlink($target)) throw new RuntimeException('ลบไฟล์เก่าไม่สำเร็จ: '.$p);
            $out[] = $p;
        }
        return $out;
    }

    /** Pre-update archives kept after a successful update; older ones hold copies of records that may since have been erased. */
    public const KEEP_BACKUPS = 2;
    /** Update folders that keep their file snapshot (a whole vendor tree after a large update); older ones keep only the receipt. */
    public const KEEP_SNAPSHOTS = 2;
    /** Longest opcache.revalidate_freq (seconds) the updater waits out with the site closed on hosts where it may not empty OPcache. */
    public const MAX_REVALIDATE_WAIT = 120;

    /** Pre-update archives, newest first: [{file, bytes, modified}]. */
    public static function backups(): array
    {
        $out = [];
        foreach (glob(storage_path('app/private/backups/pre-update-*.zip')) ?: [] as $f) $out[] = ['file' => basename($f), 'bytes' => (int) @filesize($f), 'modified' => (int) @filemtime($f)];
        usort($out, fn ($a, $b) => [$b['modified'], $b['file']] <=> [$a['modified'], $a['file']]);
        return $out;
    }

    /** Delete all but the newest $keep pre-update archives; returns how many were removed. */
    public static function pruneBackups(int $keep = self::KEEP_BACKUPS): int
    {
        $n = 0;
        foreach (array_slice(self::backups(), max(0, $keep)) as $b) if (@unlink(storage_path('app/private/backups/'.$b['file']))) $n++;
        return $n;
    }

    /** Drop the snapshot and staging folders of settled updates beyond the newest $keep; receipts stay. Returns how many folders were removed. */
    public static function pruneWork(int $keep = self::KEEP_SNAPSHOTS): int
    {
        $dirs = array_values(array_filter(glob(storage_path('app/private/updates/*'), GLOB_ONLYDIR) ?: [], fn ($d) => basename($d) !== 'incoming' && is_file($d.'/receipt.json')));
        rsort($dirs); // folder names start with the UTC start time
        $n = 0;
        foreach (array_slice($dirs, max(0, $keep)) as $d) {
            $r = self::readJson($d.'/receipt.json');
            if (!is_array($r) || (!isset($r['completed_at']) && empty($r['rolled_back']))) continue; // unfinished: its snapshot is the only way back
            foreach (['snapshot', self::STAGE] as $sub) if (is_dir($d.'/'.$sub)) { self::removeTree($d.'/'.$sub); $n++; }
        }
        return $n;
    }

    /** Remove temp dirs and half-written ZIPs left by a backup whose request was killed (older than $maxAge seconds). */
    public static function sweepStaleBackupTemp(int $maxAge = 86400): int
    {
        $n = 0; $cut = time() - $maxAge; $fs = new \Illuminate\Filesystem\Filesystem;
        foreach (glob(storage_path('app/private/archive-*'), GLOB_ONLYDIR) ?: [] as $dir) if (preg_match('~/archive-[0-9a-f]{16}$~', str_replace('\\', '/', $dir)) && (int) @filemtime($dir) < $cut && $fs->deleteDirectory($dir)) $n++;
        foreach (array_merge(glob(storage_path('app/private/backups/*.incomplete-*')) ?: [], glob(storage_path('app/private/*.incomplete-*')) ?: []) as $f) if (is_file($f) && (int) @filemtime($f) < $cut && @unlink($f)) $n++;
        return $n;
    }

    private function healthCheck(): void
    {
        DB::select('select 1');
        $pending = []; $ran = DB::table('migrations')->pluck('migration')->all();
        foreach (array_merge(glob(database_path('migrations/*.php')) ?: [], glob(base_path('modules/*/database/migrations/*.php')) ?: []) as $file) if (!in_array(pathinfo($file, PATHINFO_FILENAME), $ran, true)) $pending[] = basename($file);
        if ($pending) throw new RuntimeException('ยังมี migration ค้าง: '.implode(', ', $pending));
        \Illuminate\Support\Facades\Crypt::decryptString(\Illuminate\Support\Facades\Crypt::encryptString('saan-health'));
    }

    /** One row per update in system_updates: inserted as "started" before the swap, then moved to its final status. @return int|null row id */
    private function record(array $receipt, string $status): ?int
    {
        if (!Schema::hasTable('system_updates')) return null;
        $row = ['status' => $status, 'receipt' => json_encode($receipt, JSON_UNESCAPED_UNICODE)];
        $id = (int) ($receipt['row_id'] ?? 0);
        if ($id > 0 && DB::table('system_updates')->where('id', $id)->update($row)) return $id;
        return (int) DB::table('system_updates')->insertGetId($row + ['package_type' => $receipt['type'], 'package_id' => $receipt['id'], 'from_version' => $receipt['from'], 'to_version' => $receipt['to'], 'manifest_sha256' => $receipt['manifest_sha256'], 'created_at' => now()]);
    }
}
