<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CurrentSchoolAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = User::find(Auth::id());
        $invalid = !$user || !$user->is_active
            || (int) $user->school_id !== (int) config('school.school_id')
            || ($user->is_demo && (!config('school.demo') || !in_array($request->ip(), ['127.0.0.1', '::1'], true)))
            || $request->session()->get('auth_version') === null
            || (int) $request->session()->get('auth_version') !== (int) $user?->auth_version;

        if ($invalid) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return $request->expectsJson()
                ? response()->json(['message' => 'บัญชีหรือสิทธิ์เปลี่ยนแล้ว กรุณาเข้าระบบใหม่'], 401)
                : redirect('/login')->withErrors(['email' => 'กรุณาเข้าระบบใหม่']);
        }

        Auth::setUser($user);
        $request->session()->put('auth_version', $user->auth_version);
        return $next($request);
    }
}
