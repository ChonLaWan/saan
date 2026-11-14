<?php

namespace Saan\Modules\GuardianPortal\Http;

use App\Support\Saan\NetKey;
use App\Support\Saan\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Saan\Modules\GuardianPortal\Access;
use Saan\Modules\GuardianPortal\Contacts;
use Saan\Modules\GuardianPortal\Links;
use Saan\Modules\GuardianPortal\SecretPolicy;

/**
 * F8 guardian account self-service: own account page (password change, optional verified e-mail) and the public pages
 * forgot password / reset / verify e-mail. Link-only sessions have no account, so they can do none of this.
 */
class PortalAccountController
{
    public static function schoolName(): string
    {
        return (string) (Settings::get('school.name') ?: config('saan.display', 'โรงเรียน'));
    }

    private function page(string $mode, array $data = [], int $status = 200)
    {
        return response()->view('guardian-portal::page', ['mode' => $mode] + $data, $status);
    }

    private function account(Request $r): object
    {
        $ctx = $r->attributes->get('gp');
        if (($ctx['kind'] ?? '') !== 'account') abort(403, 'ใช้ได้เฉพาะบัญชีผู้ปกครองที่ตั้งรหัสผ่านไว้');
        $acc = DB::table('gp_accounts')->where('school_id', Access::school())->where('id', (int) $ctx['account_id'])->where('is_active', true)->first();
        if (!$acc) abort(403);
        return $acc;
    }

    public function me(Request $r): JsonResponse
    {
        $ctx = $r->attributes->get('gp');
        $out = ['kind' => $ctx['kind'], 'must_change' => (bool) $ctx['must_change'], 'password_min' => SecretPolicy::min(), 'username' => null, 'contact' => null];
        if ($ctx['kind'] === 'account') {
            $acc = $this->account($r);
            $out['username'] = $acc->username;
            $out['contact'] = Contacts::status('guardian', (int) $acc->guardian_id);
        }
        return response()->json($out)->header('Cache-Control', 'no-store');
    }

    public function secret(Request $r): JsonResponse
    {
        $acc = $this->account($r);
        $d = $r->validate(['current' => ['required', 'string', 'max:200'], 'new' => ['required', 'string', 'max:200']], [], ['current' => 'รหัสผ่านปัจจุบัน', 'new' => 'รหัสผ่านใหม่']);
        $key = 'gp-secret:'.$acc->id;
        if (RateLimiter::tooManyAttempts($key, 5)) throw ValidationException::withMessages(['current' => 'ลองหลายครั้งเกินไป กรุณารอ '.RateLimiter::availableIn($key).' วินาที']);
        if (!$acc->password || !Hash::check($d['current'], $acc->password)) {
            RateLimiter::hit($key, 600);
            throw ValidationException::withMessages(['current' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        }
        if ($d['new'] === $d['current']) throw ValidationException::withMessages(['new' => 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสเดิม']);
        $bad = SecretPolicy::check($d['new'], [$acc->username, strstr((string) $acc->email, '@', true) ?: '']);
        if ($bad) throw ValidationException::withMessages(['new' => $bad]);
        DB::table('gp_accounts')->where('id', $acc->id)->update(['password' => Hash::make($d['new']), 'must_change' => false, 'failed_count' => 0, 'lock_count' => 0, 'locked_until' => null,
            'auth_version' => DB::raw('auth_version + 1'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        RateLimiter::clear($key);
        Contacts::resetDone('guardian', (int) $acc->guardian_id); // a changed password ends every reset link that is still out
        Access::startAccountSession($r, DB::table('gp_accounts')->where('id', $acc->id)->first()); // other devices end, this one continues
        Access::event('guardian.password.changed', (int) $acc->guardian_id, null, [], $r);
        return response()->json(['ok' => true]);
    }

    public function email(Request $r): JsonResponse
    {
        $acc = $this->account($r);
        $d = $r->validate(['email' => ['required', 'email:rfc', 'max:190'], 'current' => ['required', 'string', 'max:200']], [], ['email' => 'อีเมล', 'current' => 'รหัสผ่านปัจจุบัน']);
        $key = 'gp-contact:'.$acc->id;
        if (RateLimiter::tooManyAttempts($key, 3)) throw ValidationException::withMessages(['email' => 'ขอยืนยันอีเมลหลายครั้งเกินไป กรุณารอสักครู่']);
        // the e-mail is the way back into the account: binding or changing it needs the current password (same guess counter as the password change)
        $pwKey = 'gp-secret:'.$acc->id;
        if (RateLimiter::tooManyAttempts($pwKey, 5)) throw ValidationException::withMessages(['current' => 'ลองหลายครั้งเกินไป กรุณารอ '.RateLimiter::availableIn($pwKey).' วินาที']);
        if (!$acc->password || !Hash::check($d['current'], $acc->password)) {
            RateLimiter::hit($pwKey, 600);
            throw ValidationException::withMessages(['current' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        }
        RateLimiter::hit($key, 3600);
        Contacts::begin('guardian', (int) $acc->guardian_id, $d['email'], self::schoolName());
        Access::event('guardian.email.requested', (int) $acc->guardian_id, null, [], $r);
        return response()->json(['contact' => Contacts::status('guardian', (int) $acc->guardian_id)]);
    }

    // ---- public pages ----

    public function forgotPage()
    {
        return $this->page('forgot');
    }

    public function forgot(Request $r)
    {
        $d = $r->validate(['login' => ['required', 'string', 'max:190']], [], ['login' => 'ชื่อผู้ใช้หรืออีเมล']);
        if (!Contacts::throttleForgot(NetKey::rate($r->ip()))) throw ValidationException::withMessages(['login' => 'ขอหลายครั้งเกินไป กรุณารอสักครู่แล้วลองใหม่']);
        $acc = Links::accountByLogin($d['login'], true); // also a verified contact e-mail of a username-only account
        if ($acc) {
            Contacts::sendReset('guardian', (int) $acc->guardian_id, self::schoolName());
            Access::event('guardian.password.reset_requested', (int) $acc->guardian_id, null, [], $r);
        } else {
            Contacts::dummy(); // sendReset does one bcrypt on every branch: the same work for every name
        }
        return redirect('/guardian/forgot')->with('status', Contacts::GENERIC_RESET);
    }

    public function resetPage(Request $r, string $token)
    {
        return $this->page('reset', ['token' => $token, 'who' => (int) $r->query('i', 0)]);
    }

    public function reset(Request $r)
    {
        $d = $r->validate(['token' => ['required', 'string', 'max:200'], 'i' => ['required', 'integer', 'min:1'], 'password' => ['required', 'string', 'max:200', 'confirmed']], [], ['password' => 'รหัสผ่านใหม่']);
        $net = 'gp-reset-net:'.NetKey::rate($r->ip());
        if (RateLimiter::tooManyAttempts($net, 20)) throw ValidationException::withMessages(['password' => 'ลองหลายครั้งเกินไป กรุณารอสักครู่']);
        RateLimiter::hit($net, 900);
        $acc = DB::table('gp_accounts')->where('school_id', Access::school())->where('guardian_id', (int) $d['i'])->where('is_active', true)->first();
        if (!$acc || !Contacts::resetTokenValid('guardian', (int) $acc->guardian_id, $d['token'])) throw ValidationException::withMessages(['password' => 'ลิงก์หมดอายุหรือไม่ถูกต้อง กรุณาขอลิงก์ใหม่']);
        $bad = SecretPolicy::check($d['password'], [$acc->username, strstr((string) $acc->email, '@', true) ?: '']);
        if ($bad) throw ValidationException::withMessages(['password' => $bad]);
        DB::table('gp_accounts')->where('id', $acc->id)->update(['password' => Hash::make($d['password']), 'must_change' => false, 'failed_count' => 0, 'lock_count' => 0, 'locked_until' => null,
            'auth_version' => DB::raw('auth_version + 1'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        Contacts::resetDone('guardian', (int) $acc->guardian_id);
        Access::event('guardian.password.reset', (int) $acc->guardian_id, null, ['via' => 'email'], $r);
        return redirect('/guardian')->with('status', 'ตั้งรหัสผ่านใหม่แล้ว เข้าสู่ระบบด้วยรหัสผ่านใหม่ได้เลย');
    }

    public function verifyPage(string $token)
    {
        return Contacts::peek('guardian', $token) ? $this->page('verify', ['token' => $token]) : $this->page('invalid', [], 404);
    }

    public function verify(string $token)
    {
        $c = Contacts::confirm('guardian', $token);
        if (!$c) return $this->page('invalid', [], 404);
        Access::event('guardian.email.verified', (int) $c->subject_id, null);
        return $this->page('verified');
    }
}
