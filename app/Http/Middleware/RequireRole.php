<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Usage: ->middleware('role:admin,principal'). Run after auth + CurrentSchoolAccount. */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, $roles, true), 403, 'บัญชีนี้ไม่มีสิทธิ์ใช้งานส่วนนี้');
        return $next($request);
    }
}