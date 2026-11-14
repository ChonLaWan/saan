<?php

namespace App\Http\Controllers;

use App\Support\Saan\Edition;
use App\Support\Saan\Publish\Pairing;
use Illuminate\Http\Request;

/**
 * GET/POST /_saan/setup (S6): one-time pairing form for a public install on a host without SSH. It exists only while the owner has put a token
 * file at storage/app/private/saan-pair.token (32+ characters of [A-Za-z0-9_-] with at least 16 different ones; `php artisan saan:pair-token` makes one);
 * the file is deleted after a successful pairing; 10 wrong tokens from one network lock that network out for 15 minutes (the file stays: anybody could otherwise delete it on purpose), and the doctor warns when it has been lying there for over an hour.
 * Without the file the page answers 404, so a finished install exposes nothing here.
 */
class SaanSetupController extends Controller
{
    public static function tokenFile(): string
    {
        return storage_path('app/private/saan-pair.token');
    }

    public const MAX_WRONG = 10;

    /** A token that is long enough and not a repeated character or a short pattern: 32+ characters of [A-Za-z0-9_-], at least 16 different ones. */
    public static function strongToken(string $t): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{32,200}$/D', $t) && count(array_unique(str_split($t))) >= 16;
    }

    private function token(): ?string
    {
        abort_unless(Edition::isPublic(), 404);
        $f = self::tokenFile();
        $t = is_file($f) ? trim((string) @file_get_contents($f)) : '';
        abort_unless(self::strongToken($t), 404);
        return $t;
    }

    private function lockKey(Request $request): string
    {
        return 'saan-setup-wrong:'.\App\Support\Saan\NetKey::rate($request->ip());
    }

    /** Wrong tokens are counted per network (/64 for IPv6): a temporary lockout, never a deletion of the token file (that would be a lever for anyone). */
    private function wrongToken(Request $request): void
    {
        \Illuminate\Support\Facades\RateLimiter::hit($this->lockKey($request), 900);
    }

    private function page(string $message = '', int $status = 200)
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>เชื่อมกับระบบภายใน</title>'
            .'<style>body{font-family:system-ui,sans-serif;max-width:34rem;margin:2rem auto;padding:0 1rem;line-height:1.6}label{display:block;margin-top:1rem}input{width:100%;padding:.5rem;box-sizing:border-box}button{margin-top:1.2rem;padding:.6rem 1.2rem}.msg{padding:.6rem;border:1px solid #888}</style></head><body>'
            .'<h1>เชื่อมกับระบบภายใน</h1><p>ใช้ครั้งเดียวหลังติดตั้งหน้าบ้าน ขอรหัสจับคู่ได้ที่หน้า "เชื่อมเว็บไซต์หน้าบ้าน" ในระบบภายใน (รหัสใช้ได้ 15 นาที ใช้ได้ครั้งเดียว)</p>'
            .($message !== '' ? '<p class="msg" role="status">'.$e($message).'</p>' : '')
            .'<form method="post" action="'.$e(url('/_saan/setup')).'">'.csrf_field()
            .'<label>รหัสเจ้าของเครื่อง (จากไฟล์ saan-pair.token)<input name="owner_token" type="password" autocomplete="off" required></label>'
            .'<label>ที่อยู่ระบบภายใน (https://app.โรงเรียน.ac.th)<input name="internal_url" type="url" required></label>'
            .'<label>รหัสจับคู่<input name="code" autocomplete="off" required></label>'
            .'<button type="submit">จับคู่</button></form></body></html>';
        return response($html, $status)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function show()
    {
        $this->token();
        return $this->page();
    }

    public function pair(Request $request)
    {
        $token = $this->token();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($this->lockKey($request), self::MAX_WRONG)) return $this->page('ลองผิดบ่อยเกินไป รอ 15 นาทีแล้วลองใหม่', 429);
        $r = $request->validate(['owner_token' => 'required|string|max:200', 'internal_url' => 'required|string|max:200', 'code' => 'required|string|max:80']);
        if (!hash_equals($token, trim($r['owner_token']))) { $this->wrongToken($request); return $this->page('รหัสเจ้าของเครื่องไม่ถูกต้อง', 403); }
        try {
            Pairing::pairWith($r['internal_url'], $r['code'], Pairing::ownPushUrl());
        } catch (\Throwable $e) {
            return $this->page('จับคู่ไม่สำเร็จ: '.$e->getMessage(), 422);
        }
        @unlink(self::tokenFile());
        \Illuminate\Support\Facades\RateLimiter::clear($this->lockKey($request));
        return $this->page('จับคู่สำเร็จ ระบบภายในจะส่งข้อมูลหน้าเว็บชุดเต็มมาภายใน 1-2 นาที ไฟล์รหัสเจ้าของเครื่องถูกลบแล้ว');
    }
}
