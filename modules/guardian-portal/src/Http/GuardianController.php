<?php

namespace Saan\Modules\GuardianPortal\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Saan\Modules\GuardianPortal\Access;
use Saan\Modules\GuardianPortal\Acks;
use Saan\Modules\GuardianPortal\GuardianData;
use Saan\Modules\GuardianPortal\Links;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Guardian pages (/guardian) and guardian JSON (/guardian/api/*). Public layout; no staff login involved. */
class GuardianController
{
    private function view(string $mode, array $data = [], int $status = 200)
    {
        return response()->view('guardian-portal::page', ['mode' => $mode] + $data, $status);
    }

    public function shell(Request $r)
    {
        return Access::resolve($r) ? $this->view('app') : $this->view('login');
    }

    /** GET only shows a confirm page: chat-app link previews must not burn a one-time link. */
    public function confirm(Request $r, string $token)
    {
        $link = Links::peek($token);
        if (!$link) return $this->view('invalid', [], 404);
        return $this->view($link->purpose === 'invite' ? 'invite' : 'open', ['token' => $token, 'username_only' => $link->purpose === 'invite' && Links::usernameOnlyAttach($link)]);
    }

    public function open(Request $r, string $token)
    {
        $link = Links::peek($token);
        if (!$link) return $this->view('invalid', [], 404);
        if ($link->purpose === 'invite') {
            // 'confirmed' whenever a new password will be written: a new account, or an admin/principal invite that resets an existing one.
            // Only a teacher-issued invite on an existing account (holder re-enters the password they already have) skips it.
            $pw = ['required', 'string', Links::usernameOnlyAttach($link) ? 'min:1' : 'min:10', 'max:200']; // the old teacher-issued password is shorter than a new one may be
            $existing = DB::table('gp_accounts')->where('school_id', Access::school())->where('guardian_id', $link->guardian_id)->exists();
            $creator = $link->created_by ? DB::table('users')->where('id', $link->created_by)->first(['id', 'role']) : null;
            if (!$existing || Links::canResetAccount($creator)) $pw[] = 'confirmed';
            // a child attached to a username-only account: the holder types that username (the field keeps the name "email" for the form and for old clients)
            $d = $r->validate([
                'email' => Links::usernameOnlyAttach($link) ? ['required', 'string', 'max:190'] : ['required', 'email:rfc', 'max:190'],
                'password' => $pw,
            ], [], ['email' => Links::usernameOnlyAttach($link) ? 'ชื่อผู้ใช้' : 'อีเมล', 'password' => 'รหัสผ่าน']);
            try {
                $acc = Links::setupAccount($link, $d['email'], $d['password'], \App\Support\Saan\NetKey::rate($r->ip()));
            } catch (HttpException $e) {
                if ($e->getStatusCode() === 404) return $this->view('invalid', [], 404);
                if ($e->getStatusCode() === 422) throw ValidationException::withMessages(['password' => $e->getMessage()]);
                if ($e->getStatusCode() === 409) throw ValidationException::withMessages(['email' => $e->getMessage()]);
                throw $e;
            }
            Access::startAccountSession($r, $acc);
            Access::event('guardian.login', (int) $link->guardian_id, null, ['via' => 'invite'], $r);
            return redirect('/guardian');
        }
        if (!Links::consume($link)) return $this->view('invalid', [], 404);
        Access::startLinkSession($r, (int) $link->guardian_id, (int) $link->id);
        Access::event('guardian.login', (int) $link->guardian_id, (int) $link->student_id, ['via' => 'link', 'link_id' => (int) $link->id], $r);
        return redirect('/guardian');
    }

    public const LOGIN_FAILED = 'ชื่อผู้ใช้หรืออีเมลหรือรหัสผ่านไม่ถูกต้อง';

    /** Sign-in name = e-mail or the username a teacher issued (field name stays "email" for old clients). */
    public function login(Request $r)
    {
        $d = $r->validate(['email' => ['required', 'string', 'max:190'], 'password' => ['required', 'string', 'max:200']], [], ['email' => 'อีเมลหรือชื่อผู้ใช้', 'password' => 'รหัสผ่าน']);
        $net = \App\Support\Saan\NetKey::rate($r->ip());
        $key = 'gp-login:'.sha1(Links::emailKey($d['email']).'|'.$r->ip());
        // Failures only. Per account+address (tight), per network (high: a classroom behind one school address must not lock itself) and per school.
        // The school ceiling never refuses up front: a network that already failed 3 times while the ceiling is reached is "hot", gets one check
        // in flight at a time, a wrong password is answered "too many" and no longer feeds the school counter; the right password still gets in.
        // Links::attempt has the per-account lock and per-network cap.
        $netKey = 'gp-login-net:'.$net;
        $tooMany = fn (string $k) => ValidationException::withMessages(['email' => 'ลองเข้าสู่ระบบหลายครั้งเกินไป กรุณารอ '.RateLimiter::availableIn($k).' วินาที']);
        if (RateLimiter::tooManyAttempts($key, 5)) throw $tooMany($key);
        if (RateLimiter::tooManyAttempts($netKey, 300)) throw $tooMany($netKey);
        $hot = RateLimiter::tooManyAttempts('gp-login-school', 400) && RateLimiter::attempts($netKey) >= 3;
        $slot = $hot ? self::hotSlot('gp-hot-slot:'.$net) : null;
        if ($slot === false) throw $tooMany('gp-login-school');
        try {
            $acc = Links::attempt($d['email'], $d['password'], $net);
        } finally {
            if ($slot) { try { $slot->release(); } catch (\Throwable $e) {} }
        }
        if (!$acc) {
            foreach ($hot ? [$key, $netKey] : [$key, $netKey, 'gp-login-school'] as $k) RateLimiter::hit($k, 600);
            Access::event('guardian.login.failed', null, null, [], $r);
            if ($hot) throw $tooMany('gp-login-school');
            throw ValidationException::withMessages(['email' => self::LOGIN_FAILED]);
        }
        RateLimiter::clear($key);
        DB::table('gp_accounts')->where('id', $acc->id)->update(['last_login_at' => now()]);
        Access::startAccountSession($r, $acc);
        Access::event('guardian.login', (int) $acc->guardian_id, null, ['via' => 'account'], $r);
        return redirect('/guardian');
    }

    /** One bcrypt check in flight per hot network: the held lock, false when another request holds it, null when the store has no locks. */
    private static function hotSlot(string $key): \Illuminate\Contracts\Cache\Lock|false|null
    {
        try {
            $lock = \Illuminate\Support\Facades\Cache::lock(sha1($key), 5);
            return $lock->get() ? $lock : false;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function logout(Request $r)
    {
        $ctx = Access::resolve($r);
        if ($ctx) Access::event('guardian.logout', $ctx['guardian_id'], null, [], $r);
        $r->session()->forget(Access::SESSION);
        $r->session()->regenerate();
        return redirect('/guardian');
    }

    // ---- JSON ----

    private function ctx(Request $r): array
    {
        return $r->attributes->get('gp');
    }

    /** Student of the signed-in guardian, or 404 (a child that is not linked looks exactly like a child that does not exist). */
    private function child(Request $r, int $id): array
    {
        $ctx = $this->ctx($r);
        if (!in_array($id, $ctx['student_ids'], true)) abort(404);
        return $ctx;
    }

    public function children(Request $r): JsonResponse
    {
        return response()->json(['data' => GuardianData::children($this->ctx($r)['student_ids'])]);
    }

    public function attendance(Request $r, int $id): JsonResponse
    {
        $ctx = $this->child($r, $id);
        $month = GuardianData::validMonth($r->query('month') === null ? null : (string) $r->query('month'));
        if ($month === null) abort(422, 'เดือนไม่ถูกต้อง');
        return response()->json(GuardianData::attendance($ctx['guardian_id'], $id, $month));
    }

    public function behavior(Request $r, int $id): JsonResponse
    {
        $ctx = $this->child($r, $id);
        return response()->json(GuardianData::behavior($ctx['guardian_id'], $id));
    }

    public function results(Request $r, int $id): JsonResponse
    {
        $ctx = $this->child($r, $id);
        // same audit as /report: every view of results leaves a trace, whichever endpoint is called
        \Saan\Modules\GuardianPortal\Learning::auditOnce('gp:'.$ctx['guardian_id'].':'.$id.':results', fn () => Access::event('guardian.results.viewed', $ctx['guardian_id'], $id, [], $r));
        return response()->json(GuardianData::results($ctx['guardian_id'], $id));
    }

    /** F8: grades, credits, GPA, GPAX, ร/มผ/มส, attributes, rank (if enabled) of announced terms; every view is audited (once per 10 minutes). */
    public function report(Request $r, int $id): JsonResponse
    {
        $ctx = $this->child($r, $id);
        \Saan\Modules\GuardianPortal\Learning::auditOnce('gp:'.$ctx['guardian_id'].':'.$id.':report', fn () => Access::event('guardian.results.viewed', $ctx['guardian_id'], $id, [], $r));
        return response()->json(\Saan\Modules\GuardianPortal\Learning::report($id, $ctx['guardian_id']))->header('Cache-Control', 'no-store');
    }

    public function timetable(Request $r, int $id): JsonResponse
    {
        $this->child($r, $id);
        return response()->json(\Saan\Modules\GuardianPortal\Learning::timetable($id));
    }
    public function health(Request $r, int $id): JsonResponse
    {
        $this->child($r, $id);
        return response()->json(GuardianData::health($id));
    }

    public function ack(Request $r, int $id): JsonResponse
    {
        $ctx = $this->child($r, $id);
        $d = $r->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', Acks::KINDS)],
            'ref_id' => ['nullable', 'integer', 'min:1'], 'ref_month' => ['nullable', 'string', 'max:7'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $out = Acks::acknowledge($r, $ctx['guardian_id'], $id, $d['kind'], isset($d['ref_id']) ? (int) $d['ref_id'] : null, $d['ref_month'] ?? null, $d['note'] ?? null);
        return response()->json($out);
    }
}
