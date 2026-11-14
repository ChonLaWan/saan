<?php

namespace Saan\Modules\Finance\Http;

use Closure;
use Illuminate\Http\Request;
use Saan\Modules\Finance\Support;

/** Finance staff = admin, principal, or a user listed in Settings finance.staff_user_ids. Teachers otherwise get 403. */
class RequireFinance
{
    public const MANAGERS = ['admin', 'principal'];

    public static function allowed(?object $user): bool
    {
        if (!$user) return false;
        return in_array($user->role, self::MANAGERS, true) || in_array((int) $user->id, Support::staffIds(), true);
    }

    public function handle(Request $request, Closure $next)
    {
        abort_unless(self::allowed($request->user()), 403, 'บัญชีนี้ไม่ได้รับสิทธิ์งานการเงิน');
        return $next($request);
    }
}
