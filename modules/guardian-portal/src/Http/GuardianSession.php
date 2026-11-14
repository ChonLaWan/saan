<?php

namespace Saan\Modules\GuardianPortal\Http;

use Closure;
use Illuminate\Http\Request;
use Saan\Modules\GuardianPortal\Access;

/** Guardian API gate: a valid guardian session is recomputed from the database on every request (see Access). Also marks every guardian response noindex. */
class GuardianSession
{
    public function handle(Request $request, Closure $next)
    {
        $ctx = Access::resolve($request);
        if ($ctx === null) {
            $res = response()->json(['message' => 'หมดเวลาหรือไม่มีสิทธิ์ กรุณาเปิดลิงก์จากโรงเรียนหรือเข้าสู่ระบบอีกครั้ง'], 401);
        } elseif (!empty($ctx['must_change']) && !$request->is('guardian/api/me', 'guardian/api/secret')) {
            // F8: a temporary password issued by staff must be replaced before anything else opens
            $res = response()->json(['message' => 'กรุณาตั้งรหัสผ่านใหม่ก่อนใช้งาน', 'must_change' => true], 403);
        } else {
            $request->attributes->set('gp', $ctx);
            $res = $next($request);
        }
        $res->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $res;
    }
}
