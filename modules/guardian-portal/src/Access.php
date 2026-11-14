<?php

namespace Saan\Modules\GuardianPortal;

use App\Support\Saan\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a guardian session may see. The browser session only holds {kind, guardian_id, links|account} (no student list):
 * the students are recomputed from the database on every request, so a revoked link, a deactivated account, a changed
 * guardian-student relation, a student who left school or the school switching off "restricted guardians may view"
 * takes effect immediately.
 *
 * Session key 'gp.session' lives in the normal Laravel session next to (never instead of) a staff login:
 * config/auth.php is untouched because a guardian is not a users row.
 */
final class Access
{
    public const SESSION = 'gp.session';
    public const SESSION_HOURS = 8;
    public const RESTRICTED_SETTING = 'guardian.restricted_can_view';

    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    /** Pickup-restricted guardians (reg_student_guardians.can_pick_up = false) may view only when the school allows it. Default: no. */
    public static function restrictedCanView(): bool
    {
        return Settings::get(self::RESTRICTED_SETTING, false) === true;
    }

    public static function ipHash(?Request $request = null): string
    {
        $request ??= request();
        return hash_hmac('sha256', (string) $request->ip(), \App\Support\Saan\HashKey::secret());
    }

    /**
     * Students this guardian may view right now, optionally limited to $candidates. Never reads or returns pickup text.
     * @param int[]|null $candidates
     * @return int[]
     */
    public static function viewable(int $guardianId, ?array $candidates = null): array
    {
        if (!Schema::hasTable('reg_student_guardians')) return [];
        if ($candidates !== null && !$candidates) return [];
        $q = DB::table('reg_student_guardians as sg')->join('students as s', 's.id', '=', 'sg.student_id')
            ->where('sg.school_id', self::school())->where('s.school_id', self::school())->where('sg.guardian_id', $guardianId);
        if ($candidates !== null) $q->whereIn('sg.student_id', $candidates);
        if (Schema::hasColumn('students', 'status')) $q->where('s.status', 'studying');
        if (!self::restrictedCanView()) $q->where('sg.can_pick_up', true);
        return $q->orderBy('s.id')->pluck('sg.student_id')->map(fn ($v) => (int) $v)->all();
    }

    public static function mayView(int $guardianId, int $studentId): bool
    {
        return in_array($studentId, self::viewable($guardianId, [$studentId]), true);
    }

    /** Signed-in guardian of this request, or null. @return array{guardian_id:int,kind:string,student_ids:int[],must_change:bool,account_id:int}|null */
    public static function resolve(Request $request): ?array
    {
        $s = $request->hasSession() ? $request->session()->get(self::SESSION) : null;
        if (!is_array($s) || (int) ($s['until'] ?? 0) < time() || empty($s['guardian_id'])) return null;
        $g = (int) $s['guardian_id'];
        if (($s['kind'] ?? '') === 'account') {
            $acc = DB::table('gp_accounts')->where('school_id', self::school())->where('id', (int) ($s['account_id'] ?? 0))
                ->where('guardian_id', $g)->where('is_active', true)->first(['auth_version', 'must_change']);
            if (!$acc || (int) $acc->auth_version !== (int) ($s['auth_version'] ?? -1)) return null;
            // Decision: an account sees only the children for whom staff issued an invite link that was used and not revoked
            // (the teacher of that child vouched for this guardian). A guardian linked to other children in the registry does not
            // get them until their own teacher issues an invite too, and revoking that invite takes the child away again.
            $candidates = DB::table('gp_links')->where('school_id', self::school())->where('guardian_id', $g)->where('purpose', 'invite')
                ->whereNotNull('used_at')->whereNull('revoked_at')->pluck('student_id')->map(fn ($v) => (int) $v)->all();
            $mustChange = (bool) $acc->must_change;
        } elseif (($s['kind'] ?? '') === 'link') {
            $ids = array_values(array_filter(array_map('intval', (array) ($s['links'] ?? []))));
            $candidates = $ids ? DB::table('gp_links')->where('school_id', self::school())->where('guardian_id', $g)->whereIn('id', $ids)
                ->where('purpose', 'view')->whereNull('revoked_at')->pluck('student_id')->map(fn ($v) => (int) $v)->all() : [];
        } else {
            return null;
        }
        return ['guardian_id' => $g, 'kind' => (string) $s['kind'], 'student_ids' => self::viewable($g, $candidates), 'must_change' => $mustChange ?? false, 'account_id' => (int) ($s['account_id'] ?? 0)];
    }

    /** Audit of a guardian action (a guardian is not a users row, so audit_events cannot hold it). */
    public static function event(string $action, ?int $guardianId, ?int $studentId, array $detail = [], ?Request $request = null): void
    {
        DB::table('gp_events')->insert([
            'school_id' => self::school(), 'guardian_id' => $guardianId, 'student_id' => $studentId, 'action' => $action,
            'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, 'ip_hash' => self::ipHash($request), 'created_at' => now(),
        ]);
    }

    /** Start (or extend, for another child of the same guardian) a link session. */
    public static function startLinkSession(Request $request, int $guardianId, int $linkId): void
    {
        $cur = $request->session()->get(self::SESSION);
        $links = is_array($cur) && ($cur['kind'] ?? '') === 'link' && (int) ($cur['guardian_id'] ?? 0) === $guardianId ? (array) ($cur['links'] ?? []) : [];
        $links[] = $linkId;
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, ['kind' => 'link', 'guardian_id' => $guardianId, 'links' => array_values(array_unique($links)),
            'until' => time() + self::SESSION_HOURS * 3600]);
    }

    public static function startAccountSession(Request $request, object $account): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, ['kind' => 'account', 'guardian_id' => (int) $account->guardian_id, 'account_id' => (int) $account->id,
            'auth_version' => (int) $account->auth_version, 'until' => time() + self::SESSION_HOURS * 3600]);
    }
}
