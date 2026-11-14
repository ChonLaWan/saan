<?php

namespace App\Http\Controllers;

use App\Domain\SchoolOperations;
use App\Domain\AccountPassword;
use App\Models\User;
use App\Support\Saan\NetKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function loginPage()
    {
        return auth()->check() ? redirect('/') : view('login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:1024'], [
            'email.required' => 'กรุณากรอกอีเมล', 'email.email' => 'กรุณาตรวจสอบรูปแบบอีเมล',
            'password.required' => 'กรุณากรอกรหัสผ่าน',
        ]);
        $email = mb_strtolower(trim($data['email']));
        $key = 'login:'.hash('sha256', $email.'|'.NetKey::rate($request->ip()));
        $ipKey = 'login-ip:'.hash('sha256', NetKey::wide($request->ip()));
        // Per-account ceiling: 20 failures per hour from every network together. It refuses only networks that failed on this account
        // themselves (3 times in the hour), so someone who knows a staff e-mail cannot lock its owner out from a network with no failures;
        // a /48 (or one IPv4) is held to 30 failures per hour per account, which bounds guessing by rotating addresses.
        $acctKey = 'login-acct:'.hash('sha256', $email);
        $acctNetKey = 'login-acct-net:'.hash('sha256', $email.'|'.NetKey::rate($request->ip()));
        $acctWideKey = 'login-acct-wide:'.hash('sha256', $email.'|'.NetKey::wide($request->ip()));
        $acctBlocked = RateLimiter::tooManyAttempts($acctKey, 20) && RateLimiter::attempts($acctNetKey) >= 3;
        foreach ([[$key, 5, false], [$ipKey, 30, false], [$acctWideKey, 30, false], [$acctKey, 20, true]] as [$k, $max, $account]) {
            if ($account ? $acctBlocked : RateLimiter::tooManyAttempts($k, $max)) {
                $msg = 'ลองเข้าสู่ระบบหลายครั้งแล้ว กรุณารอสักครู่แล้วลองใหม่';
                $e = ValidationException::withMessages(['email' => $msg]);
                $e->response = back()->withInput($request->only('email'))->withErrors(['email' => $msg])->header('Retry-After', (string) max(1, RateLimiter::availableIn($k)));
                throw $e;
            }
        }

        if (!Auth::attempt(['email' => $email, 'password' => $data['password'], 'school_id' => config('school.school_id'), 'is_active' => true, 'is_demo' => false])) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($acctKey, 3600);
            RateLimiter::hit($acctNetKey, 3600);
            RateLimiter::hit($acctWideKey, 3600);
            throw ValidationException::withMessages(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง หรือบัญชียังไม่พร้อมใช้งาน']);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($acctNetKey); // this network proved it knows the password: its own failure history no longer counts against it
        if (auth()->user()->mfa_enabled_at) {
            // Second factor: forget the password-only login and ask for the 6-digit code.
            $pending = ['id' => auth()->id(), 'auth_version' => auth()->user()->auth_version, 'at' => time()];
            Auth::logout(); $request->session()->regenerate(); $request->session()->put('mfa_login', $pending);
            return redirect('/login/mfa');
        }
        $request->session()->regenerate();
        $request->session()->put('auth_version', auth()->user()->auth_version);
        DB::transaction(function () {
            User::whereKey(auth()->id())->update(['last_login_at' => now()]);
            app(SchoolOperations::class)->audit('auth.login', 'users', auth()->id());
        });
        return redirect('/');
    }

    public function show()
    {
        return view('account');
    }

    public function password(Request $request)
    {
        abort_if(auth()->user()->is_demo, 403, 'บัญชีสาธิตเปลี่ยนรหัสผ่านไม่ได้');
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => AccountPassword::rules(),
        ], [
            'current_password.required' => 'กรุณากรอกรหัสผ่านปัจจุบัน',
            'password.required' => 'กรุณากรอกรหัสผ่านใหม่', 'password.confirmed' => 'รหัสผ่านใหม่ทั้งสองช่องไม่ตรงกัน',
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร', 'password.letters' => 'รหัสผ่านต้องมีตัวอักษร',
            'password.numbers' => 'รหัสผ่านต้องมีตัวเลข',
        ]);
        $nextVersion = DB::transaction(function () use ($data) {
            $user = User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            if (!Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
            }
            $user->password = $data['password'];
            $user->auth_version++;
            $user->version++;
            $user->save();
            app(SchoolOperations::class)->audit('account.password_changed', 'users', $user->id);
            return $user->auth_version;
        });
        $request->session()->regenerate();
        $request->session()->put('auth_version', $nextVersion);
        return back()->with('status', 'เปลี่ยนรหัสผ่านแล้ว อุปกรณ์อื่นต้องเข้าสู่ระบบใหม่');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
