<?php

namespace App\Http\Controllers;

use App\Domain\SchoolAccounts;
use App\Domain\Updater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REL-02 web updater for hosts without SSH: the admin uploads a signed package with the hosting File Manager to
 * storage/app/private/updates/incoming, then checks and installs it here (current password required).
 */
class UpdateController extends Controller
{
    private function incoming(): string
    {
        $dir = storage_path('app/private/updates/incoming'); if (!is_dir($dir)) @mkdir($dir, 0700, true); return $dir;
    }

    public function index()
    {
        app(SchoolAccounts::class)->authorize();
        $updater = new Updater; $packages = [];
        foreach (glob($this->incoming().'/saan-*.zip') ?: [] as $file) {
            $row = ['file' => basename($file), 'bytes' => filesize($file)];
            try { $m = $updater->inspect($file); $row += ['valid' => true, 'type' => $m['type'], 'id' => $m['id'], 'version' => $m['version'], 'current_version' => $m['current_version'], 'problems' => $updater->preflight($m)]; }
            catch (\Throwable $e) { $row += ['valid' => false, 'error' => $e->getMessage()]; }
            $packages[] = $row;
        }
        try { Updater::syncHistory(); } catch (\Throwable) {} // the process that swaps files writes no row afterwards: bring the table up to date from the receipts
        $history = Schema::hasTable('system_updates') ?DB::table('system_updates')->orderByDesc('id')->limit(20)->get(['package_id', 'from_version', 'to_version', 'status', 'created_at']) : [];
        return response()->json(['incoming' => 'storage/app/private/updates/incoming', 'version' => config('saan.version'), 'both_required' => \App\Domain\UpdateBoth::required(), 'feed' => \App\Support\Modules\UpdateFeed::status(), 'packages' => $packages, 'history' => $history,
            'backups' => ['count' => count($b = Updater::backups()), 'bytes' => array_sum(array_column($b, 'bytes')), 'keep' => Updater::KEEP_BACKUPS, 'files' => $b],
            'unfinished' => array_map(fn ($u) => ['id' => $u['receipt']['id'] ?? null, 'from' => $u['receipt']['from'] ?? null, 'to' => $u['receipt']['to'] ?? null, 'started_at' => $u['receipt']['started_at'] ?? null, 'hint' => 'php artisan saan:update --recover'], Updater::unfinished())]);
    }

    /** Admin removes pre-update archives (each is a full copy of the school's data); keeps the newest `keep` (default 0). */
    public function purgeBackups(Request $request)
    {
        app(SchoolAccounts::class)->authorize();
        $d = $request->validate(['keep' => 'nullable|integer|min:0|max:10', 'current_password' => 'required|string|current_password']);
        $removed = Updater::pruneBackups((int) ($d['keep'] ?? 0));
        return response()->json(['ok' => true, 'removed' => $removed, 'remaining' => count(Updater::backups())]);
    }

    public function apply(Request $request)
    {
        app(SchoolAccounts::class)->authorize();
        $d = $request->validate(['file' => ['required', 'string', 'max:120', 'regex:/^saan-[a-z0-9.-]+\.zip$/'], 'current_password' => 'required|string|current_password']);
        $path = $this->incoming().'/'.$d['file']; abort_unless(is_file($path), 404);
        // standard install (two app roots): updating only this side would leave the public site on the old release, so the web button defers to the CLI that updates both
        // the browser or a proxy giving up must not stop the update half way; the time limit covers backup + swap + the finish step
        @set_time_limit((int) config('saan.update_finish_timeout', 1800) * 2 + 1800); ignore_user_abort(true);
        if (\App\Domain\UpdateBoth::required()) {
            // Standard install (two app roots): updating only this side would leave the public site on the old release. The update of both
            // roots is done by `saan:update --both` in a PHP CLI child started from here (it keeps running if this request is cut); where the
            // host cannot start one, the button explains the two ways that work. Never in tests (the child would update the real tree).
            $r = config('saan.update_finish') === 'inline' ? null : Updater::artisanCli(['saan:update', '--both', $path], (int) config('saan.update_finish_timeout', 1800) * 2 + 1500);
            if ($r === null) return response()->json(['ok' => false, 'log' => [], 'message' => 'การติดตั้งแบบมาตรฐานมีสองโฟลเดอร์แอป ต้องอัปเดตทั้งสองฝั่งพร้อมกัน แต่โฮสต์นี้ไม่ให้เว็บเริ่มคำสั่ง PHP (proc_open ถูกปิดหรือไม่พบ php) ให้ใช้คำสั่ง php artisan saan:update --both '.$path.' จาก SSH หรือเพิ่มเป็น cron ชั่วคราวหนึ่งครั้งในแผงควบคุมโฮสต์แล้วลบออกเมื่อเสร็จ (อัปเดตระบบภายในแล้วหน้าบ้าน และคืนทั้งสองฝั่งถ้าฝั่งใดล้ม)'], 409);
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $r[1]) ?: [])));
            $status = $r[0] === 0 ? 200 : 422;
            $body = $r[0] === 0 ? ['ok' => true, 'log' => $lines, 'to' => null] : ['ok' => false, 'log' => $lines, 'message' => (string) (end($lines) ?: 'อัปเดตไม่สำเร็จ')];
        } else {
            $log = []; $updater = new Updater(function ($line) use (&$log) { $log[] = $line; });
            try { $receipt = $updater->apply($path); $status = 200; $body = ['ok' => true, 'log' => $log, 'to' => $receipt['to']]; }
            catch (\App\Domain\UpdateBusyException $e) { $status = 409; $body = ['ok' => false, 'log' => $log, 'message' => $e->getMessage()]; }
            catch (\Throwable $e) { $status = 422; $body = ['ok' => false, 'log' => $log, 'message' => $e->getMessage()]; }
        }
        if (app()->runningUnitTests()) return response()->json($body, $status);
        // This process still holds classes of the release it started on while the files may be the new release now: answer with plain PHP and
        // end here, so the kernel does not load response, cookie, session or terminate classes from the other release.
        while (ob_get_level() > 0) @ob_end_clean();
        http_response_code($status); header('Content-Type: application/json'); header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Finish step of an update over HTTP (Updater contract 1), called by the process that swapped the files when it cannot start a PHP CLI child.
     * No login: the site is in maintenance mode and the caller is the server itself, proven by the one-time token stored in the update folder.
     * `probe=1` only tells the caller that this route is reachable.
     */
    public function finish(Request $request)
    {
        // probe: proves that this route is reachable AND that it is served by the install that asked (hash of a nonce it just wrote into its storage)
        if ($request->boolean('probe')) return response()->json(['saan_update' => Updater::CONTRACT, 'install' => hash('sha256', (string) @file_get_contents(storage_path('app/private/updates/.probe')))]);
        $name = (string) $request->input('work'); $token = (string) $request->input('token');
        try { $work = Updater::workDir($name); } catch (\Throwable) { abort(404); }
        $want = trim((string) @file_get_contents($work.'/finish.token'));
        abort_unless(strlen($want) === 64 && hash_equals($want, $token), 404);
        @set_time_limit(0); ignore_user_abort(true);
        try { $result = (new Updater)->finish($name); }
        catch (\Throwable $e) { $result = ['ok' => false, 'error' => $e->getMessage()]; }
        return response()->json($result);
    }

    /** Optional update feed (off by default). Admin switches it on/off; check runs daily via saan:update-check or on demand. */
    public function feed()
    {
        app(SchoolAccounts::class)->authorize();
        return response()->json(\App\Support\Modules\UpdateFeed::status());
    }

    public function feedSwitch(Request $request)
    {
        app(SchoolAccounts::class)->authorize();
        $d = $request->validate(['enabled' => 'required|boolean', 'current_password' => 'required|string|current_password']);
        \App\Support\Saan\Settings::put('updates.feed_enabled', (bool) $d['enabled']);
        \App\Support\Saan\Mutation::audit($d['enabled'] ? 'updates.feed_enabled' : 'updates.feed_disabled', 'updates', null, ['feed' => (string) config('saan.update_feed')]);
        return response()->json(\App\Support\Modules\UpdateFeed::status());
    }

    public function feedCheck()
    {
        app(SchoolAccounts::class)->authorize();
        abort_unless(\App\Support\Modules\UpdateFeed::enabled(), 422, 'ยังไม่ได้เปิดการตรวจรุ่นใหม่');
        (new \App\Support\Modules\UpdateFeed)->check();
        \App\Support\Saan\Mutation::audit('updates.feed_checked', 'updates', null);
        return response()->json(\App\Support\Modules\UpdateFeed::status());
    }
}