<?php

namespace Saan\Modules\Payslip;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Saan\Modules\Payslip\Notifications\PayslipNotification;

/** Optional e-mail when a period is published. The mail names the period and links to the sign-in page; it never carries an amount. A mail failure never undoes the publish. */
final class Notify
{
    /** @return int mails handed to the mailer */
    public static function published(object $period): int
    {
        $userIds = DB::table('psl_slips as s')->join('psl_payees as p', 'p.id', '=', 's.payee_id')->where('s.school_id', Support::school())->where('s.period_id', (int) $period->id)->where('s.status', 'active')
            ->whereNotNull('p.user_id')->distinct()->pluck('p.user_id')->map(fn ($v) => (int) $v)->all();
        if (!$userIds) return 0;
        $users = User::where('school_id', Support::school())->where('is_active', true)->whereIn('id', $userIds)->get()->filter(fn ($u) => trim((string) $u->email) !== '')->values();
        if ($users->isEmpty()) return 0;
        try {
            Notification::send($users->all(), new PayslipNotification('สลิปเงินเดือน '.Support::periodTitle($period).' เผยแพร่แล้ว', Support::periodTitle($period)));
            return $users->count();
        } catch (\Throwable $e) {
            Log::warning('[WARN] payslip notification failed: '.$e->getMessage());
            return 0;
        }
    }
}
