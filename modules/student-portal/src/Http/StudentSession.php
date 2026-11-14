<?php

namespace Saan\Modules\StudentPortal\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\StudentPortal\Access;

/** Student API gate: the session is recomputed from the database on every request (see Access). Marks every response noindex and no-store. */
class StudentSession
{
    public function handle(Request $request, Closure $next)
    {
        $ctx = Access::resolve($request);
        if ($ctx === null) {
            $res = response()->json(['message' => 'หมดเวลาหรือไม่มีสิทธิ์ กรุณาเข้าสู่ระบบอีกครั้ง'], 401);
        } elseif (RateLimiter::tooManyAttempts($k = 'student-api:'.$ctx['student_id'], 120)) {
            // budget per signed-in student, never per address: a whole class behind one school address shares the address, not the budget
            $res = response()->json(['message' => 'ใช้งานถี่เกินไป กรุณารอสักครู่'], 429)->header('Retry-After', (string) RateLimiter::availableIn($k));
        } elseif ($ctx['must_change'] && !$request->is('student/api/me', 'student/api/secret')) {
            // a temporary secret issued by staff must be replaced before anything else opens
            $res = response()->json(['message' => 'กรุณาตั้งรหัสผ่านใหม่ก่อนใช้งาน', 'must_change' => true], 403);
        } else {
            RateLimiter::hit('student-api:'.$ctx['student_id'], 60);
            $request->attributes->set('sp', $ctx);
            $res = $next($request);
        }
        $res->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }
}
