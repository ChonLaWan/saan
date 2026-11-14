<?php

namespace Saan\Modules\Payslip\Http;

use Closure;
use Illuminate\Http\Request;
use Saan\Modules\Payslip\Support;

/** Payroll officer = an account the admin listed in Settings payslip.officer_user_ids. Everybody else (teachers, principal, admin) gets 403 on officer endpoints. */
class RequirePayroll
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Support::isOfficer($request->user()), 403, 'บัญชีนี้ไม่ได้รับสิทธิ์จัดการสลิปเงินเดือน');
        return $next($request);
    }
}

