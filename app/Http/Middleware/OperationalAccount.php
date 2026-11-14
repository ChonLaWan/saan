<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OperationalAccount
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'principal', 'teacher'], true), 403, 'บัญชีนี้ดูแลเฉพาะเว็บไซต์โรงเรียน');
        return $next($request);
    }
}
