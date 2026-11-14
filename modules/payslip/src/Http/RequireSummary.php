<?php

namespace Saan\Modules\Payslip\Http;

use Closure;
use Illuminate\Http\Request;
use Saan\Modules\Payslip\Support;

/** Summaries (counts and totals, never an individual slip): payroll officers, admin and principal. */
class RequireSummary
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Support::canSummary($request->user()), 403, 'ไม่มีสิทธิ์ดูสรุปสลิปเงินเดือน');
        return $next($request);
    }
}
