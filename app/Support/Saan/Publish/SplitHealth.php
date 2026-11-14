<?php

namespace App\Support\Saan\Publish;

use App\Support\Saan\Edition;
use App\Support\Saan\Inbox\PullServer;
use App\Support\Saan\Inbox\State;
use App\Support\Saan\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Doctor checks of the split install (S6): paired or not, last successful push and pull, envelopes waiting or expired, rejected signatures,
 * key file permissions and key files inside the web root. Not used by the combined edition (nothing to pair).
 * Each check is [key, label, ok, detail, errorWhenFailing] for DeploymentHealth::inspect.
 */
final class SplitHealth
{
    /**
     * S7: identity of this install, own side only. Whether the two database users of a standard install are isolated from each other can be proven
     * only by the installer (the internal side must never hold the public credentials), so this reports what the installer recorded.
     * @return list<array{0:string,1:string,2:bool,3:string,4:bool}>
     */
    public static function identityChecks(): array
    {
        $id = (string) config('saan.install_id');
        $ed = Edition::current();
        if ($id === '') return Edition::isCombined() ? [] : [['install_id', 'รหัสการติดตั้ง (install id)', false, 'ยังไม่ได้ตั้ง SAAN_INSTALL_ID (ติดตั้งก่อน S7) คุกกี้/แคชของสองฝั่งอาจชนกันบนเครื่องเดียว', false]];
        require_once base_path('installer/Identity.php');
        require_once base_path('installer/Roots.php');
        $out = [];
        $ok = \SchoolAtelier\Installer\Identity::valid($id);
        $out[] = ['install_id', 'รหัสการติดตั้ง (install id)', $ok, $ok ? $id.' · edition '.$ed : 'รูปแบบไม่ถูกต้อง (ต้องเป็น 6 ตัว a-z 2-7)', false];
        if (!$ok) return $out;
        $d = \SchoolAtelier\Installer\Identity::derive($id, $ed);
        $cookie = (string) config('session.cookie');
        // the internal edition on https uses the __Host- form of the same name (see config/session.php)
        $cookieOk = $cookie === $d['session_cookie'] || $cookie === '__Host-'.$d['session_cookie'];
        $out[] = ['install_cookie', 'ชื่อคุกกี้เซสชันแยกตามการติดตั้งและ edition', $cookieOk, $cookieOk ? $cookie : 'ตั้งเป็น '.$cookie.' ควรเป็น '.$d['session_cookie'], false];
        $prefix = (string) config('cache.prefix');
        $out[] = ['install_cache_prefix', 'prefix ของแคช/ล็อก/คิวมี install id', str_contains($prefix, $id), $prefix, false];
        $conn = config('database.connections.'.config('database.default'));
        if (in_array($conn['driver'] ?? '', ['mysql', 'mariadb'], true) && $ed !== 'combined') {
            $want = $ed === 'public' ? [$d['db_public'], $d['user_public']] : [$d['db_internal'], $d['user_internal']];
            $named = ($conn['database'] ?? '') === $want[0] && ($conn['username'] ?? '') === $want[1];
            $out[] = ['install_db_names', 'ชื่อฐานข้อมูลและผู้ใช้ DB ตรงกับ install id', $named, $named ? $want[0].' / '.$want[1] : 'ใช้ชื่ออื่น (เช่น แผง hosting เติมคำนำหน้า) ตรวจสิทธิ์แยกฝั่งด้วยตัวเอง', false];
        }
        $info = is_file(storage_path('installed.json')) ? json_decode((string) @file_get_contents(storage_path('installed.json')), true) : [];
        if ($ed === 'internal') foreach (self::originChecks(is_array($info) ? $info : [], (string) config('app.url'), (string) config('session.cookie'), (string) config('session.path'), (string) config('session.domain')) as $c) $out[] = $c;
        $web = realpath(public_path()) ?: public_path();
        $mine = realpath(storage_path()) ?: storage_path();
        $inside = str_starts_with(str_replace('\\', '/', strtolower($mine)).'/', str_replace('\\', '/', strtolower($web)).'/');
        $detail = 'storage: '.$mine;
        $same = false;
        $info = is_array($info) ? $info : [];
        $peer = is_string($info['public_root'] ?? null) ? realpath($info['public_root']) : false;
        if ($peer) {
            // same helper as the installer: top-level paths, the sensitive sub-folders (private, saan-keys, sessions, logs) and any link inside the public storage
            $why = \SchoolAtelier\Installer\Roots::conflict(base_path(), $peer);
            $same = $why !== null;
            $detail = $same ? $why : $detail.' | หน้าบ้าน: '.$peer;
        }
        $out[] = ['install_storage', 'storage และ bootstrap/cache แยกจากอีกฝั่ง ไม่อยู่ใน public/', !$inside && !$same, $inside ? 'storage อยู่ใต้ public/' : $detail, $same];
        if (($info['layout'] ?? '') === 'standard') $out[] = ['install_isolation', 'ผู้ใช้ DB สองฝั่งแยกกัน (ตรวจโดยตัวติดตั้ง)', !empty($info['db_isolation_verified_at']), !empty($info['db_isolation_verified_at']) ? 'ตัวติดตั้งตรวจเมื่อ '.$info['db_isolation_verified_at'] : 'ไม่มีบันทึกว่าตรวจแล้ว', false];
        // OS level (POSIX only: Windows reports no usable owner or mode)
        $windows = DIRECTORY_SEPARATOR === '\\';
        $env = base_path('.env');
        $out = array_merge($out, self::osChecks(
            $ed, $info, (string) ini_get('open_basedir'),
            !$windows && is_file($env) ? (int) fileowner($env) : null,
            !$windows && $peer && is_file($peer.'/.env') ? (int) fileowner($peer.'/.env') : null,
            !$windows && is_file($env) ? fileperms($env) : null,
            !$windows && is_file(storage_path('installed.json')) ? fileperms(storage_path('installed.json')) : null,
            self::webObservation(), base_path(), PHP_SAPI === 'cli',
        ));
        return $out;
    }

    /**
     * Pure part of the OS-level checks (values are read by identityChecks): the two sides of a standard install share one machine, so without a second
     * OS user or open_basedir a compromised public site can read the internal .env and keys; .env and installed.json must be 0600.
     * @return list<array{0:string,1:string,2:bool,3:string,4:bool}>
     */
    public static function osChecks(string $ed, array $info, string $openBasedir, ?int $selfOwner, ?int $peerOwner, ?int $envPerms, ?int $installedPerms, ?array $web = null, ?string $ownRoot = null, bool $cli = false): array
    {
        $out = [];
        $standard = ($info['layout'] ?? '') === 'standard';
        $ob = trim($openBasedir);
        if ($ed === 'public' && $standard) {
            // The value that matters is the one the www pool (PHP-FPM) runs with: school:doctor in a shell reads another php.ini and cannot see php_admin_value of the pool.
            // A web request of the public site records it (webObservation); without that a CLI run says it cannot verify instead of passing.
            if ($web !== null) $ob = trim((string) ($web['open_basedir'] ?? ''));
            $label = 'open_basedir จำกัด PHP ของหน้าบ้านให้อยู่ในโฟลเดอร์ตัวเอง (ค่าของ PHP-FPM)';
            if ($cli && $web === null) $out[] = ['install_open_basedir', $label, false, 'ตรวจจากบรรทัดคำสั่งไม่ได้ (ค่าของ PHP-FPM pool ไม่เห็นจาก CLI): เปิดหน้าเว็บของหน้าบ้านสักหน้า แล้วรัน school:doctor อีกครั้ง', false];
            else {
                // an entry that is a PARENT of this app's folder also covers its sibling folders, where the internal app of a one-machine install lives (the public side is told no path of it)
                $covers = $ob !== '' && $ownRoot !== null && $ownRoot !== '' && self::basedirCovers($ob, $ownRoot, true);
                $ok = $ob !== '' && !$covers;
                $out[] = ['install_open_basedir', $label, $ok, ($covers ? 'open_basedir ('.$ob.') ครอบโฟลเดอร์ที่อยู่เหนือหน้าบ้านขึ้นไป จึงครอบโฟลเดอร์ข้างเคียง (ระบบภายใน) ด้วย ต้องจำกัดให้เหลือเฉพาะโฟลเดอร์หน้าบ้านและ /tmp' : ($ob !== '' ? $ob : 'ไม่ได้ตั้ง open_basedir: ถ้าหน้าบ้านถูกเจาะ จะอ่านไฟล์ของระบบภายในบนเครื่องเดียวกันได้ ตั้ง open_basedir=<โฟลเดอร์หน้าบ้าน>:/tmp ใน PHP-FPM pool ของ www หรือแยกผู้ใช้ระบบ')), false];
            }
        }
        if ($ed === 'internal' && $standard && $selfOwner !== null && $peerOwner !== null) $out[] = ['install_os_user', 'หน้าบ้านและระบบภายในรันด้วยผู้ใช้ระบบคนละคน', $selfOwner !== $peerOwner, $selfOwner !== $peerOwner ? 'เจ้าของไฟล์ .env ต่างกัน (uid '.$selfOwner.' / '.$peerOwner.')' : 'สองฝั่งเป็นผู้ใช้ระบบคนเดียวกัน (uid '.$selfOwner.'): หน้าบ้านที่ถูกเจาะอ่าน .env และกุญแจของระบบภายในได้ ให้ใช้ PHP-FPM pool ของ www เป็นผู้ใช้อีกคน หรืออย่างน้อยตั้ง open_basedir', false];
        if ($envPerms !== null) {
            $ok = ($envPerms & 0077) === 0 && ($installedPerms === null || ($installedPerms & 0077) === 0);
            $out[] = ['install_env_perms', 'สิทธิ์ไฟล์ .env และ storage/installed.json (0600)', $ok, $ok ? 'ปลอดภัย' : 'หลวมเกินไป: chmod 600 .env storage/installed.json', false];
        }
        return $out;
    }

    /** True when one entry of an open_basedir list is $root or one of its parents ($parentsOnly: only a strict parent, which also covers the sibling folders). */
    public static function basedirCovers(string $openBasedir, string $internalRoot, bool $parentsOnly = false): bool
    {
        require_once base_path('installer/Roots.php');
        $root = realpath($internalRoot) ?: $internalRoot;
        foreach (explode(PATH_SEPARATOR, $openBasedir) as $e) {
            $e = trim($e);
            if ($e === '' || $e === '/tmp' || $e === '/tmp/') continue;
            $e = realpath($e) ?: $e;
            if ((!$parentsOnly && \SchoolAtelier\Installer\Roots::norm($root) === \SchoolAtelier\Installer\Roots::norm($e)) || (\SchoolAtelier\Installer\Roots::norm($root) !== \SchoolAtelier\Installer\Roots::norm($e) && \SchoolAtelier\Installer\Roots::inside($root, $e))) return true;
        }
        return false;
    }

    /** Facts about this install that can only be seen from a web request of the public site (an hourly sample is stored by observeWeb). */
    public static function webObservation(): ?array
    {
        try { $v = \Illuminate\Support\Facades\Cache::get('saan:web-env'); } catch (\Throwable) { return null; }
        return is_array($v) && (int) ($v['at'] ?? 0) > time() - 7 * 86400 ? $v : null;
    }

    /** Called from the web (EditionGate) on a sample of public requests: records open_basedir of the FPM pool. */
    public static function observeWeb(): void
    {
        if (PHP_SAPI === 'cli' || random_int(1, 50) !== 1) return;
        try {
            \Illuminate\Support\Facades\Cache::put('saan:web-env', ['at' => time(), 'open_basedir' => (string) ini_get('open_basedir')], 7 * 86400);
        } catch (\Throwable) { /* a sample only */ }
    }

    /** Remembers that a request carried CF-Connecting-IP while TRUSTED_PROXIES is empty (every per-address limiter then sees the proxy, not the visitor). */
    public static function noteForwarded(\Illuminate\Http\Request $request): void
    {
        if (!$request->headers->has('CF-Connecting-IP') || trim((string) config('saan.trusted_proxies', '')) !== '') return;
        try { \Illuminate\Support\Facades\Cache::add('saan:cf-untrusted', time(), 7 * 86400); } catch (\Throwable) { /* a signal only */ }
    }

    /**
     * Cookie and origin checks of the internal edition (pure: values are passed in). Same origin as the public site lets page script of the public site act with the
     * staff cookies (error); a session cookie that is not the __Host- form can be shadowed from a sibling subdomain (warning, https installs only).
     * @return list<array{0:string,1:string,2:bool,3:string,4:bool}>
     */
    public static function originChecks(array $info, string $appUrl, string $cookie, string $path, string $domain): array
    {
        require_once base_path('installer/Roots.php');
        $out = [];
        $pub = (string) ($info['public_url'] ?? '');
        if ($pub !== '' && $appUrl !== '') {
            $same = \SchoolAtelier\Installer\Roots::sameOrigin($pub, $appUrl);
            $out[] = ['install_origin', 'หน้าบ้านและระบบภายในอยู่คนละโฮสต์ (คนละ origin)', !$same, $same ? 'URL หน้าบ้าน ('.$pub.') กับระบบภายใน ('.$appUrl.') อยู่ origin เดียวกัน: สคริปต์ที่แทรกในหน้าบ้านอ่านและสั่งงานระบบภายในด้วยคุกกี้ของเจ้าหน้าที่ได้ ย้ายระบบภายในไปโฮสต์อื่น (เช่น app.โรงเรียน.ac.th)' : 'ต่างโฮสต์', $same];
        }
        if (str_starts_with(strtolower($appUrl), 'https://')) {
            $host = str_starts_with($cookie, '__Host-');
            $why = [];
            if ($path !== '/') $why[] = 'SESSION_PATH ไม่ใช่ /';
            if ($domain !== '') $why[] = 'ตั้ง SESSION_DOMAIN';
            $out[] = ['install_host_cookie', 'คุกกี้เซสชันแบบ __Host- (กันโดเมนย่อยข้างเคียงตั้งคุกกี้ทับ)', $host, $host ? $cookie : 'ยังไม่ใช้ __Host-'.($why ? ' เพราะ '.implode(' และ ', $why).' (ต้องเป็น Path=/ ไม่มี Domain)' : ' (ล้าง config cache แล้วรันใหม่)'), false];
        }
        return $out;
    }

    /** @return list<array{0:string,1:string,2:bool,3:string,4:bool}> */
    public static function checks(?int $now = null): array
    {
        if (Edition::isCombined()) return [];
        $now ??= time();
        $out = [];
        $ago = fn (?string $t) => $t ? $t.' ('.max(0, intdiv($now - (int) strtotime($t), 60)).' นาทีที่แล้ว)' : 'ยังไม่เคย';
        $fresh = fn (?string $t, int $sec) => $t && $now - (int) strtotime($t) <= $sec;
        try {
            if (Edition::isInternal()) {
                $paired = Settings::get('saan.paired_at');
                $out[] = ['split_paired', 'จับคู่กับหน้าบ้านแล้ว', (bool) $paired && Publisher::url() !== '', $paired ? 'จับคู่เมื่อ '.$paired.' ปลายทาง '.Publisher::url() : 'ยังไม่ได้จับคู่ (หน้า เชื่อมเว็บไซต์หน้าบ้าน)', false];
                $push = DB::table('pub_outbox')->whereNotNull('sent_at')->whereNull('last_error')->max('sent_at');
                $queue = (int) DB::table('pub_outbox')->whereNotNull('payload')->whereNull('sent_at')->count();
                $out[] = ['split_last_push', 'ส่งข้อมูลไปหน้าบ้านสำเร็จล่าสุด', $push !== null && ($queue === 0 || $fresh($push, 900)), $ago($push).' คิวค้าง '.$queue, false];
                $pull = State::get(null, 'inbox.pull')->applied_at ?? null;
                $out[] = ['split_last_pull', 'ดึงกล่องรับเรื่องสำเร็จล่าสุด', $fresh($pull, 600), $ago($pull).' (ตั้ง schedule:run ทุกนาที)', false];
                $q = (int) DB::table('saan_inbox_quarantine')->count();
                $out[] = ['split_quarantine', 'ซองที่ส่งต่อไม่ได้ (quarantine)', $q === 0, $q === 0 ? 'ไม่มี' : $q.' ซองรอตรวจ', false];
                $foreign = State::get(null, 'inbox.foreign_kid');
                $out[] = ['split_foreign_kid', 'หน้าบ้านปิดผนึกด้วยกุญแจที่ระบบภายในรู้จัก', !($foreign && $fresh($foreign->applied_at, 86400)), $foreign && $fresh($foreign->applied_at, 86400) ? 'หน้าบ้านปิดผนึกด้วยกุญแจที่ระบบภายในไม่รู้จัก ('.$foreign->last_seq.' ครั้ง ล่าสุด '.$foreign->applied_at.') อาจมีการเปลี่ยน peer-inbox.pub โดยผู้อื่น ตรวจหน้าบ้านและจับคู่ใหม่' : 'ปกติ', true];
                $replay = State::get(null, 'inbox.replay');
                $out[] = ['split_inbox_replay', 'ซองซ้ำที่ถูกนำกลับมาใส่ใหม่', !($replay && $fresh($replay->applied_at, 86400)), $replay ? $replay->last_seq.' ครั้ง ล่าสุด '.$replay->applied_at.' (ข้ามไปแล้ว ไม่ได้ประมวลผลซ้ำ)' : 'ไม่มี', false];
                $flood = State::get(null, 'inbox.flood');
                $out[] = ['split_inbox_flood', 'ข้อความติดต่อทะลุเพดานต่อนาที/ต่อวัน (inbox flood)', !($flood && $fresh($flood->applied_at, 86400)), $flood ? $flood->last_seq.' ครั้ง ล่าสุด '.$flood->applied_at.' (ซองถูกพักไว้ที่หน้าบ้าน ไม่สูญหาย) ตรวจว่าหน้าบ้านถูกส่งสแปมหรือถูกเจาะ' : 'ไม่มี', false];
                $guess = State::get(null, 'pair.guess');
                $out[] = ['split_pair_guess', 'มีคนเดารหัสจับคู่', !($guess && $fresh($guess->applied_at, 3600)), $guess ? $guess->last_seq.' ครั้ง ล่าสุด '.$guess->applied_at : 'ไม่มี', false];
                $bad = State::get(null, 'inbox.badcode');
                $out[] = ['split_inbox_badcode', 'รหัสรายชื่อที่ผิดหรือใช้แล้วที่มาทางซอง', !($bad && $fresh($bad->applied_at, 3600)), $bad ? $bad->last_seq.' ครั้ง ล่าสุด '.$bad->applied_at : 'ไม่มี', false];
                $old = count(Keys::retiredInboxKeys());
                $out[] = ['split_retired_keys', 'กุญแจ inbox เดิมที่รอดึงซองให้หมด', $old === 0, $old === 0 ? 'ไม่มี' : $old.' ชุด (ลบเองเมื่อซองเก่าถูกดึงหมดและหน้าบ้านใช้กุญแจใหม่แล้ว)', false];
            } else {
                $paired = Keys::paired();
                $out[] = ['split_paired', 'จับคู่กับระบบภายในแล้ว', $paired, $paired ? 'มีกุญแจครบ (push, pull, inbox, unlock)' : 'ยังไม่ได้จับคู่ (php artisan saan:pair)', false];
                $push = DB::table('pub_sync_state')->where('stream', 'not like', 'inbox.%')->where('stream', 'not like', 'push.%')->max('applied_at');
                $out[] = ['split_last_push', 'รับข้อมูลจากระบบภายในล่าสุด', $push !== null, $ago($push), false];
                $pull = State::get(null, 'inbox.pull')->applied_at ?? null;
                $out[] = ['split_last_pull', 'ระบบภายในมาดึงกล่องรับเรื่องล่าสุด', $fresh($pull, 600), $ago($pull), false];
                $waiting = (int) DB::table('pub_inbox')->count();
                $oldest = DB::table('pub_inbox')->min('received_at');
                $stale = $oldest !== null && $now - (int) strtotime($oldest) > 3600;
                $out[] = ['split_inbox_waiting', 'ซองรอระบบภายในมาดึง', !$stale, $waiting.' ซอง'.($oldest ? ' เก่าสุด '.$ago($oldest).' (เกิน '.PullServer::MAX_AGE_DAYS.' วันจะถูกลบ)' : ''), false];
                $gone = State::count(null, 'inbox.purged');
                $out[] = ['split_inbox_expired', 'ซองหมดอายุถูกลบโดยไม่มีใครดึง', $gone === 0, $gone === 0 ? 'ไม่มี' : $gone.' ซองสูญหาย ตรวจว่าระบบภายในดึงได้ปกติ', false];
                $fail = State::count(null, 'inbox.sigfail') + State::count(null, 'push.sigfail');
                $lastFail = max((string) (State::get(null, 'inbox.sigfail')->applied_at ?? ''), (string) (State::get(null, 'push.sigfail')->applied_at ?? ''));
                $out[] = ['split_sigfail', 'คำขอที่ลายเซ็นไม่ถูกต้อง', $fail === 0 || !$fresh($lastFail, 86400), $fail === 0 ? 'ไม่มี' : $fail.' ครั้ง ล่าสุด '.$lastFail, false];
                $tf = \App\Http\Controllers\SaanSetupController::tokenFile();
                $tokenAge = is_file($tf) ? $now - (int) @filemtime($tf) : null;
                $out[] = ['split_pair_token', 'ไฟล์รหัสเจ้าของเครื่องสำหรับจับคู่ (saan-pair.token)', $tokenAge === null || $tokenAge <= 3600, $tokenAge === null ? 'ไม่มี (ปกติ)' : 'ไฟล์ค้างอยู่ '.intdiv($tokenAge, 60).' นาที ลบด้วย php artisan saan:pair-token --remove ถ้าไม่ได้ใช้แล้ว', false];
            }
        } catch (\Throwable $e) {
            $out[] = ['split_state', 'สถานะการเชื่อมหน้าบ้าน/ระบบภายใน', false, 'อ่านสถานะไม่ได้ (ตรวจว่ารัน migrate แล้ว)', false];
        }
        try {
            $cf = \Illuminate\Support\Facades\Cache::get('saan:cf-untrusted');
            $out[] = ['split_proxy_trust', 'ตัวจำกัดความถี่เห็น IP ผู้เยี่ยมชมจริง (TRUSTED_PROXIES)', !$cf, $cf ? 'พบหัวข้อ CF-Connecting-IP แต่ TRUSTED_PROXIES ว่าง: ทุกตัวจำกัดความถี่ต่อ IP เห็นแต่ IP ของ Cloudflare (ตั้ง TRUSTED_PROXIES ในไฟล์ .env)' : 'ปกติ', false];
        } catch (\Throwable) { /* cache not reachable: nothing to say */ }
        try {
            $dir = Keys::dir();
            $out[] = ['split_keys_webroot', 'ไฟล์กุญแจอยู่นอกโฟลเดอร์เว็บ', true, $dir, true];
            if (DIRECTORY_SEPARATOR === '\\') {
                $out[] = ['split_key_perms', 'สิทธิ์ไฟล์กุญแจ (0600/0700)', true, 'ข้ามบน Windows', false];
            } else {
                $bad = [];
                if (is_dir($dir) && (fileperms($dir) & 0077) !== 0) $bad[] = basename($dir).' (โฟลเดอร์)';
                foreach (glob($dir.'/*') ?: [] as $f) if (is_file($f) && (fileperms($f) & 0077) !== 0) $bad[] = basename($f);
                $out[] = ['split_key_perms', 'สิทธิ์ไฟล์กุญแจ (0600/0700)', !$bad, $bad ? 'หลวมเกินไป: '.implode(', ', $bad).' (chmod 600 / 700)' : 'ปลอดภัย', false];
            }
        } catch (\Throwable $e) {
            $out[] = ['split_keys_webroot', 'ไฟล์กุญแจอยู่นอกโฟลเดอร์เว็บ', false, $e->getMessage(), true];
        }
        return $out;
    }
}
