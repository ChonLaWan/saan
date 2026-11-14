<?php

namespace App\Http\Controllers;

use App\Domain\SchoolOperations;
use App\Domain\Totp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** REL-05: signed-in devices, sign out other devices, and optional TOTP second factor. */
class AccountSecurityController extends Controller
{
    public static function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database') return [];
        return DB::table('sessions')->where('user_id', auth()->id())->orderByDesc('last_activity')->limit(20)->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($s) => ['current' => hash_equals($s->id, $request->session()->getId()), 'ip' => preg_replace('/(\d+)\.(\d+)$/', 'x.x', (string) $s->ip_address),
                'device' => Str::limit(preg_replace('/\s+/', ' ', (string) $s->user_agent), 90), 'last_activity' => \Carbon\CarbonImmutable::createFromTimestamp($s->last_activity, 'Asia/Bangkok')->format('Y-m-d H:i')])->all();
    }

    public function revokeOthers(Request $request)
    {
        abort_if(auth()->user()->is_demo, 403);
        $request->validate(['current_password' => 'required|string|current_password']);
        $version = DB::transaction(function () use ($request) {
            $user = User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            $user->auth_version++; $user->version++; $user->remember_token = null; $user->save();
            if (config('session.driver') === 'database') DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            app(SchoolOperations::class)->audit('account.sessions_revoked', 'users', $user->id);
            return $user->auth_version;
        });
        $request->session()->regenerate(); $request->session()->put('auth_version', $version);
        return back()->with('status', 'ออกจากระบบในอุปกรณ์อื่นทั้งหมดแล้ว');
    }

    public function mfaStart(Request $request)
    {
        abort_if(auth()->user()->is_demo || auth()->user()->mfa_enabled_at, 422);
        $request->session()->put('mfa_setup', Totp::secret());
        return redirect('/account#mfa');
    }

    public function mfaConfirm(Request $request)
    {
        $d = $request->validate(['code' => 'required|string|max:12']);
        $secret = $request->session()->get('mfa_setup') ?? abort(422, 'เริ่มตั้งค่าใหม่อีกครั้ง');
        $step = Totp::verify($secret, $d['code']);
        if ($step === null) throw ValidationException::withMessages(['code' => 'รหัส 6 หลักไม่ถูกต้อง ตรวจเวลาในโทรศัพท์แล้วลองใหม่']);
        $codes = collect(range(1, 8))->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))->all();
        DB::transaction(function () use ($secret, $codes, $step) {
            $user = User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            $user->forceFill(['mfa_secret' => Crypt::encryptString($secret), 'mfa_recovery' => Crypt::encryptString(json_encode(array_map(fn ($c) => Hash::make($c), $codes))), 'mfa_enabled_at' => now(), 'mfa_last_step' => $step])->save();
            app(SchoolOperations::class)->audit('account.mfa_enabled', 'users', $user->id);
        });
        $request->session()->forget('mfa_setup');
        return redirect('/account#mfa')->with('mfa_recovery_codes', $codes)->with('status', 'เปิดการยืนยันสองขั้นตอนแล้ว เก็บรหัสสำรองไว้ในที่ปลอดภัย');
    }

    public function mfaDisable(Request $request)
    {
        $request->validate(['current_password' => 'required|string|current_password']);
        DB::transaction(function () {
            $user = User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            $user->forceFill(['mfa_secret' => null, 'mfa_recovery' => null, 'mfa_enabled_at' => null, 'mfa_last_step' => null])->save();
            app(SchoolOperations::class)->audit('account.mfa_disabled', 'users', $user->id);
        });
        return redirect('/account#mfa')->with('status', 'ปิดการยืนยันสองขั้นตอนแล้ว');
    }

    public function challengePage(Request $request)
    {
        $pending = $request->session()->get('mfa_login');
        if (!$pending || $pending['at'] < time() - 300) return redirect('/login');
        return view('login-mfa');
    }

    public function challenge(Request $request)
    {
        $d = $request->validate(['code' => 'required|string|max:20']);
        $pending = $request->session()->get('mfa_login');
        if (!$pending || $pending['at'] < time() - 300) return redirect('/login')->withErrors(['email' => 'หมดเวลา กรุณาเข้าสู่ระบบใหม่']);
        $key = 'mfa:'.$pending['id'];
        $capKey = 'mfa-hour:'.$pending['id']; // cumulative lockout: 10 wrong codes per hour
        if (RateLimiter::tooManyAttempts($capKey, 10)) throw ValidationException::withMessages(['code' => 'ลองผิดหลายครั้งเกินไป กรุณารอสักครู่แล้วลองใหม่']);
        if (RateLimiter::tooManyAttempts($key, 5)) throw ValidationException::withMessages(['code' => 'ลองหลายครั้งแล้ว กรุณารอหนึ่งนาที']);
        $ok = DB::transaction(function () use ($pending, $d) {
            $user = User::whereKey($pending['id'])->lockForUpdate()->first();
            if (!$user || !$user->is_active || !$user->mfa_enabled_at || (int) $user->auth_version !== (int) $pending['auth_version']) return false;
            $step = Totp::verify(Crypt::decryptString($user->mfa_secret), $d['code']);
            if ($step !== null && $step > (int) $user->mfa_last_step) { $user->forceFill(['mfa_last_step' => $step])->save(); return true; }
            $hashes = json_decode(Crypt::decryptString($user->mfa_recovery), true) ?: [];
            foreach ($hashes as $i => $hash) if (Hash::check(strtolower(trim($d['code'])), $hash)) {
                unset($hashes[$i]); $user->forceFill(['mfa_recovery' => Crypt::encryptString(json_encode(array_values($hashes)))])->save();
                app(SchoolOperations::class)->auditAs($user->id, 'account.mfa_recovery_used', 'users', $user->id, ['remaining' => count($hashes)]); return true;
            }
            return false;
        });
        if (!$ok) { RateLimiter::hit($key, 60); RateLimiter::hit($capKey, 3600); throw ValidationException::withMessages(['code' => 'รหัสไม่ถูกต้องหรือใช้ไปแล้ว']); }
        RateLimiter::clear($key);
        $request->session()->forget('mfa_login'); Auth::loginUsingId($pending['id']);
        $request->session()->regenerate(); $request->session()->put('auth_version', auth()->user()->auth_version);
        DB::transaction(function () { User::whereKey(auth()->id())->update(['last_login_at' => now()]); app(SchoolOperations::class)->audit('auth.login_mfa', 'users', auth()->id()); });
        return redirect('/');
    }
}
