<?php

namespace Saan\Modules\StudentPortal\Http;

use App\Support\Saan\NetKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Saan\Modules\ApiGateway\Pins;
use Saan\Modules\GuardianPortal\Contacts;
use Saan\Modules\GuardianPortal\GuardianData;
use Saan\Modules\GuardianPortal\Http\PortalAccountController;
use Saan\Modules\GuardianPortal\Learning;
use Saan\Modules\GuardianPortal\SecretPolicy;
use Saan\Modules\StudentPortal\Access;
use Saan\Modules\StudentPortal\Credentials;

/**
 * Student pages (/student) and student JSON (/student/api/*). Read-only own-data scope: no endpoint takes a student id, every read uses
 * the student of the session, so there is nothing to tamper with. The only writes are the student's own secret and contact e-mail.
 */
class StudentController
{
    /** One text for every failed sign-in: unknown code, no secret yet, wrong secret, locked account, left school. */
    public const NET_FAILS = 300;
    public const SCHOOL_FAILS = 400;
    public const HOT_NETWORK_MIN_FAILS = 3;

    public const LOGIN_FAILED = 'รหัสนักเรียนหรือรหัสผ่านไม่ถูกต้อง หรือบัญชีถูกพักการใช้งานชั่วคราว ถ้าเข้าไม่ได้ให้แจ้งครูประจำชั้น';

    private function view(string $mode, array $data = [], int $status = 200)
    {
        return response()->view('student-portal::page', ['mode' => $mode] + $data, $status);
    }

    public function shell(Request $r)
    {
        return Access::resolve($r) ? $this->view('app') : $this->view('login');
    }

    public function login(Request $r)
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:40'], 'secret' => ['required', 'string', 'max:200']], [], ['code' => 'รหัสนักเรียน', 'secret' => 'รหัสผ่าน']);
        $net = NetKey::rate($r->ip());
        // Counters of FAILURES only. The network ceiling (NET_FAILS) is high: a class behind one school address makes typos without locking itself.
        // The school-wide ceiling never blocks a network with a clean record (a flood from elsewhere must not stop students who type the right
        // secret); it only refuses networks that already failed HOT_NETWORK_MIN_FAILS times. A refused request feeds no counter, so a flood cannot keep itself hot.
        // The per-student, per-network cap inside Pins::attempt is the real brute-force guard (the same design as the OAuth sign-in).
        $netKey = 'stu-login-net:'.$net;
        $tooMany = fn (string $k) => ValidationException::withMessages(['code' => 'ลองเข้าสู่ระบบหลายครั้งเกินไป กรุณารอ '.RateLimiter::availableIn($k).' วินาที']);
        if (RateLimiter::tooManyAttempts($netKey, self::NET_FAILS)) throw $tooMany($netKey);
        // A hot network (school ceiling reached AND this network already failing) is never refused up front: a student with the right secret
        // still gets in. It gets one check in flight at a time, a wrong secret is answered "too many", and it no longer feeds the school counter.
        $hot = RateLimiter::tooManyAttempts('stu-login-school', self::SCHOOL_FAILS) && RateLimiter::attempts($netKey) >= self::HOT_NETWORK_MIN_FAILS;
        $slot = $hot ? self::hotSlot('stu-hot-slot:'.$net) : null;
        if ($slot === false) throw $tooMany('stu-login-school');
        try {
            // Pins::attempt: reserve-before-check counter, escalating lock, per-student-per-network cap, one bcrypt for unknown codes
            $student = Pins::attempt($d['code'], $d['secret'], $net, true);
        } finally {
            if ($slot) { try { $slot->release(); } catch (\Throwable $e) {} }
        }
        if (!$student) {
            RateLimiter::hit($netKey, 600);
            if (!$hot) RateLimiter::hit('stu-login-school', 600);
            Access::event('student.login.failed', null, [], $r);
            if ($hot) throw $tooMany('stu-login-school');
            throw ValidationException::withMessages(['code' => self::LOGIN_FAILED]);
        }
        $row = DB::table('api_student_pins')->where('school_id', Access::school())->where('student_id', $student->id)->first(['auth_version']);
        DB::table('api_student_pins')->where('school_id', Access::school())->where('student_id', $student->id)->update(['last_login_at' => now()]);
        Access::start($r, (int) $student->id, (int) $row->auth_version);
        Access::event('student.login', (int) $student->id, [], $r);
        return redirect('/student');
    }

    /**
     * One bcrypt check in flight per hot network (no server-side sleep, which would hold a PHP-FPM worker). Returns the held lock, false when
     * another request of the same network holds it, or null when the cache store has no locks (the other limits still apply).
     */
    public static function hotSlot(string $key): \Illuminate\Contracts\Cache\Lock|false|null
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
        if ($ctx) Access::event('student.logout', $ctx['student_id'], [], $r);
        $r->session()->forget(Access::SESSION);
        $r->session()->regenerate();
        return redirect('/student');
    }

    // ---- forgot / reset / verify (public) ----

    public function forgotPage()
    {
        return $this->view('forgot');
    }

    public function forgot(Request $r)
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:40']], [], ['code' => 'รหัสนักเรียน']);
        if (!Contacts::throttleForgot(NetKey::rate($r->ip()))) throw ValidationException::withMessages(['code' => 'ขอหลายครั้งเกินไป กรุณารอสักครู่แล้วลองใหม่']);
        $student = Pins::studentByCode($d['code']);
        if ($student) {
            Contacts::sendReset('student', (int) $student->id, PortalAccountController::schoolName());
            Access::event('student.password.reset_requested', (int) $student->id, [], $r);
        } else {
            Contacts::dummy(); // sendReset does one bcrypt on every branch (no e-mail, token just issued, token created): the same work for every code
        }
        return redirect('/student/forgot')->with('status', Contacts::GENERIC_RESET); // the same text for every code
    }

    public function resetPage(Request $r, string $token)
    {
        return $this->view('reset', ['token' => $token, 'who' => (int) $r->query('i', 0)]);
    }

    public function reset(Request $r)
    {
        $d = $r->validate(['token' => ['required', 'string', 'max:200'], 'i' => ['required', 'integer', 'min:1'], 'password' => ['required', 'string', 'max:200', 'confirmed']], [], ['password' => 'รหัสผ่านใหม่']);
        $net = 'stu-reset-net:'.NetKey::rate($r->ip());
        if (RateLimiter::tooManyAttempts($net, 20)) throw ValidationException::withMessages(['password' => 'ลองหลายครั้งเกินไป กรุณารอสักครู่']);
        RateLimiter::hit($net, 900);
        $student = DB::table('students')->where('school_id', Access::school())->where('id', (int) $d['i'])->where('status', 'studying')->first(['id', 'code']);
        if (!$student || !Contacts::resetTokenValid('student', (int) $student->id, $d['token'])) throw ValidationException::withMessages(['password' => 'ลิงก์หมดอายุหรือไม่ถูกต้อง กรุณาขอลิงก์ใหม่']);
        $bad = Credentials::check($d['password'], $student);
        if ($bad) throw ValidationException::withMessages(['password' => $bad]);
        Credentials::set((int) $student->id, $d['password']);
        Contacts::resetDone('student', (int) $student->id);
        Access::event('student.password.reset', (int) $student->id, ['via' => 'email'], $r);
        return redirect('/student')->with('status', 'ตั้งรหัสผ่านใหม่แล้ว เข้าสู่ระบบด้วยรหัสผ่านใหม่ได้เลย');
    }

    public function verifyPage(string $token)
    {
        return Contacts::peek('student', $token) ? $this->view('verify', ['token' => $token]) : $this->view('invalid', [], 404);
    }

    public function verify(string $token)
    {
        $c = Contacts::confirm('student', $token);
        if (!$c) return $this->view('invalid', [], 404);
        Access::event('student.email.verified', (int) $c->subject_id);
        return $this->view('verified');
    }

    // ---- JSON (own data only) ----

    private function sid(Request $r): int
    {
        return (int) $r->attributes->get('sp')['student_id'];
    }

    public function me(Request $r): JsonResponse
    {
        $sid = $this->sid($r);
        $s = DB::table('students as s')->leftJoin('classrooms as c', 'c.id', '=', 's.classroom_id')->where('s.school_id', Access::school())->where('s.id', $sid)
            ->first(['s.name', 's.first_name', 's.last_name', 'c.name as classroom']);
        $full = trim(trim((string) ($s->first_name ?? '')).' '.trim((string) ($s->last_name ?? '')));
        return response()->json(['name' => $full !== '' ? $full : (string) $s->name, 'classroom' => $s->classroom, 'must_change' => (bool) $r->attributes->get('sp')['must_change'],
            'password_min' => SecretPolicy::min(), 'contact' => Contacts::status('student', $sid)]);
    }

    /** Grades, credits, GPA, GPAX, ร/มผ/มส, attributes, rank when enabled; announced terms and closed courses only. Every view is audited (once per 10 minutes). */
    public function report(Request $r): JsonResponse
    {
        $sid = $this->sid($r);
        Learning::auditOnce('sp:'.$sid.':report', fn () => Access::event('student.results.viewed', $sid, [], $r));
        return response()->json(Learning::report($sid));
    }

    public function timetable(Request $r): JsonResponse
    {
        return response()->json(Learning::timetable($this->sid($r)));
    }

    public function attendance(Request $r): JsonResponse
    {
        $month = GuardianData::validMonth($r->query('month') === null ? null : (string) $r->query('month'));
        if ($month === null) abort(422, 'เดือนไม่ถูกต้อง');
        return response()->json(GuardianData::attendance(0, $this->sid($r), $month));
    }

    public function behavior(Request $r): JsonResponse
    {
        return response()->json(GuardianData::behaviorForStudent($this->sid($r)));
    }

    public function secret(Request $r): JsonResponse
    {
        $sid = $this->sid($r);
        $d = $r->validate(['current' => ['required', 'string', 'max:200'], 'new' => ['required', 'string', 'max:200']], [], ['current' => 'รหัสผ่านปัจจุบัน', 'new' => 'รหัสผ่านใหม่']);
        $student = DB::table('students')->where('school_id', Access::school())->where('id', $sid)->first(['id', 'code']);
        // the current secret is checked through Pins::attempt: same counter, lock and network cap as a sign-in, so a stolen session cannot guess it freely
        if (!Pins::attempt((string) $student->code, $d['current'], NetKey::rate($r->ip()), true)) throw ValidationException::withMessages(['current' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        if ($d['new'] === $d['current']) throw ValidationException::withMessages(['new' => 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสเดิม']);
        $bad = Credentials::check($d['new'], $student);
        if ($bad) throw ValidationException::withMessages(['new' => $bad]);
        $version = Credentials::set($sid, $d['new']);
        Access::start($r, $sid, $version); // other devices end, this one continues
        Access::event('student.password.changed', $sid, [], $r);
        return response()->json(['ok' => true]);
    }

    public function email(Request $r): JsonResponse
    {
        $sid = $this->sid($r);
        $d = $r->validate(['email' => ['required', 'email:rfc', 'max:190'], 'current' => ['required', 'string', 'max:200']], [], ['email' => 'อีเมล', 'current' => 'รหัสผ่านปัจจุบัน']);
        $key = 'stu-contact:'.$sid;
        if (RateLimiter::tooManyAttempts($key, 3)) throw ValidationException::withMessages(['email' => 'ขอยืนยันอีเมลหลายครั้งเกินไป กรุณารอสักครู่']);
        // the e-mail is the way back into the account, so binding or changing it needs the current secret (a session left open on a shared computer is not enough);
        // checked through Pins::attempt: same counter, lock and network cap as a sign-in
        $student = DB::table('students')->where('school_id', Access::school())->where('id', $sid)->first(['id', 'code']);
        if (!Pins::attempt((string) $student->code, $d['current'], NetKey::rate($r->ip()), true)) throw ValidationException::withMessages(['current' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        RateLimiter::hit($key, 3600);
        Contacts::begin('student', $sid, $d['email'], PortalAccountController::schoolName());
        Access::event('student.email.requested', $sid, [], $r);
        return response()->json(['contact' => Contacts::status('student', $sid)]);
    }
}
