<?php

namespace Saan\Modules\GuardianPortal;

use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Staff-issued one-time links and optional guardian accounts.
 *
 * Token: 24 random bytes, base64url (32 characters), shown ONCE in the create response; only sha256(token) is stored.
 * A link can be opened once (atomic UPDATE ... WHERE used_at IS NULL), is limited to one guardian + one student, expires
 * (default 14 days) and can be revoked. The opening GET only shows a confirm page: link-preview bots from chat apps must
 * not burn a one-time link, so the session starts on the POST.
 */
final class Links
{
    public const DEFAULT_DAYS = 14;
    public const MAX_DAYS = 60;
    public const PURPOSES = ['view', 'invite'];

    public static function emailKey(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function isUnique(QueryException $e): bool
    {
        $info = (int) ($e->errorInfo[1] ?? 0);
        if ((string) $e->getCode() === '23505' || in_array($info, [1062, 1586], true)) return true;
        return $info === 19 && stripos($e->getMessage(), 'UNIQUE') !== false; // SQLSTATE 23000 alone also covers NOT NULL / FK / CHECK
    }

    /** True when the user is admin/principal (any classroom), the only roles allowed to reset an existing guardian account from an invite. */
    public static function canResetAccount(?object $user): bool
    {
        return $user !== null && in_array($user->role ?? null, TeacherScope::ALL_ROLES, true);
    }


    /** Staff scope: admin/principal any classroom, a teacher only the homeroom. Returns the student row or aborts. */
    public static function scopedStudent(int $studentId, ?object $user = null): object
    {
        $s = DB::table('students')->where('school_id', Access::school())->where('id', $studentId)->first();
        if (!$s) abort(404, 'ไม่พบนักเรียน');
        $ids = TeacherScope::homeroomIds($user);
        if ($ids !== null && !in_array((int) $s->classroom_id, $ids, true)) abort(403, 'เป็นนักเรียนนอกห้องประจำชั้นของคุณ');
        return $s;
    }

    /** @return array{link:object,token:string,url:string} */
    public static function create(object $user, int $studentId, int $guardianId, string $purpose, ?int $days): array
    {
        if (!in_array($purpose, self::PURPOSES, true)) abort(422, 'ชนิดลิงก์ไม่ถูกต้อง');
        $student = self::scopedStudent($studentId, $user);
        if (property_exists($student, 'status') && $student->status !== 'studying') abort(422, 'นักเรียนไม่ได้อยู่ในสถานะกำลังศึกษา');
        $rel = DB::table('reg_student_guardians')->where('school_id', Access::school())->where('student_id', $studentId)->where('guardian_id', $guardianId)->first(['id', 'can_pick_up']);
        if (!$rel) abort(404, 'ผู้ปกครองรายนี้ไม่ได้ผูกกับนักเรียน');
        if (!$rel->can_pick_up && !Access::restrictedCanView()) abort(422, 'ผู้ปกครองรายนี้ถูกจำกัดสิทธิ์ โรงเรียนไม่อนุญาตให้ดูข้อมูลนักเรียน');
        $days = max(1, min(self::MAX_DAYS, $days ?? self::DEFAULT_DAYS));
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        $link = DB::transaction(function () use ($user, $studentId, $guardianId, $purpose, $days, $token) {
            // serialise concurrent creates for the same pair: lock the relation row first, then read the old links with a locking read
            DB::table('reg_student_guardians')->where('school_id', Access::school())->where('student_id', $studentId)->where('guardian_id', $guardianId)->lockForUpdate()->get(['id']);
            // a new link replaces every unused link of the same kind for the same pair
            $old = DB::table('gp_links')->where('school_id', Access::school())->where('student_id', $studentId)->where('guardian_id', $guardianId)
                ->where('purpose', $purpose)->whereNull('used_at')->whereNull('revoked_at')->lockForUpdate()->get(['id', 'version']);
            foreach ($old as $o) Mutation::update('gp_links', (int) $o->id, (int) $o->version, ['revoked_at' => now()->toDateTimeString()], 'guardian_portal.link.revoked', ['reason' => 'replaced']);
            return Mutation::insert('gp_links', [
                'guardian_id' => $guardianId, 'student_id' => $studentId, 'token_hash' => hash('sha256', $token), 'purpose' => $purpose,
                'expires_at' => now()->addDays($days)->toDateTimeString(), 'created_by' => (int) $user->id,
            ], 'guardian_portal.link.created', ['student_id' => $studentId, 'guardian_id' => $guardianId, 'purpose' => $purpose, 'days' => $days]);
        });
        return ['link' => $link, 'token' => $token, 'url' => url('/guardian/l/'.$token)];
    }

    public static function revoke(int $linkId, int $version): object
    {
        $link = DB::table('gp_links')->where('school_id', Access::school())->where('id', $linkId)->first();
        if (!$link) abort(404);
        self::scopedStudent((int) $link->student_id);
        if ($link->revoked_at !== null) abort(422, 'ลิงก์นี้ถูกยกเลิกไปแล้ว');
        return DB::transaction(function () use ($link, $linkId, $version) {
            $out = Mutation::update('gp_links', $linkId, $version, ['revoked_at' => now()->toDateTimeString()], 'guardian_portal.link.revoked', ['reason' => 'manual', 'student_id' => (int) $link->student_id]);
            if ($link->purpose === 'invite') {
                // access belongs to the guardian+student pair: cut every other open or used invite of the pair too
                $n = DB::table('gp_links')->where('school_id', Access::school())->where('guardian_id', $link->guardian_id)->where('student_id', $link->student_id)
                    ->where('purpose', 'invite')->whereNull('revoked_at')->where('id', '!=', $linkId)
                    ->update(['revoked_at' => now(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                if ($n) Access::event('guardian.invite.pair_revoked', (int) $link->guardian_id, (int) $link->student_id, ['extra_links' => $n]);
            }
            return $out;
        });
    }

    /**
     * True when the guardian looks after a studying child outside $rooms (the teacher's homeroom ids): by an activated invite OR by the registry link.
     * A teacher may disable, reset or unlock a guardian account only when this is false, since the account also opens those other children.
     */
    public static function hasChildrenOutside(int $guardianId, array $rooms): bool
    {
        $outside = fn ($w) => $w->whereNull('s.classroom_id')->orWhereNotIn('s.classroom_id', $rooms ?: [0]); // a child without a classroom is not the homeroom either (NOT IN alone is NULL for it)
        $viaInvite = DB::table('gp_links as l')->join('students as s', 's.id', '=', 'l.student_id')->where('l.school_id', Access::school())->where('l.guardian_id', $guardianId)
            ->where('l.purpose', 'invite')->whereNotNull('l.used_at')->whereNull('l.revoked_at')->where('s.status', 'studying')->where($outside)->exists();
        if ($viaInvite) return true;
        return DB::table('reg_student_guardians as sg')->join('students as s', 's.id', '=', 'sg.student_id')->where('sg.school_id', Access::school())->where('s.school_id', Access::school())
            ->where('sg.guardian_id', $guardianId)->where('s.status', 'studying')->where($outside)->exists();
    }

    /**
     * Switch a guardian account off: is_active=false and auth_version+1 (open sessions end on their next request).
     * admin/principal: any guardian; a teacher: only a guardian linked in the registry to a student of the homeroom.
     */
    public static function disableAccount(object $user, int $guardianId, int $version): object
    {
        $acc = DB::table('gp_accounts')->where('school_id', Access::school())->where('guardian_id', $guardianId)->first();
        if (!$acc) abort(404, 'ไม่พบบัญชีผู้ปกครอง');
        $rooms = TeacherScope::homeroomIds($user);
        if ($rooms !== null) {
            $own = DB::table('reg_student_guardians as sg')->join('students as s', 's.id', '=', 'sg.student_id')->where('sg.school_id', Access::school())
                ->where('s.school_id', Access::school())->where('sg.guardian_id', $guardianId)->where('s.status', 'studying')->whereIn('s.classroom_id', $rooms ?: [0])->exists();
            if (!$own) abort(403, 'ผู้ปกครองรายนี้ไม่ได้ผูกกับนักเรียนในห้องประจำชั้นของคุณ');
            if (self::hasChildrenOutside($guardianId, $rooms)) abort(403, 'บัญชีนี้ดูแลนักเรียนนอกห้องประจำชั้นของคุณ ให้ผู้ดูแลระบบหรือผู้อำนวยการเป็นผู้ดำเนินการ');
        }
        if (!$acc->is_active) abort(422, 'บัญชีนี้ปิดใช้งานอยู่แล้ว');
        return DB::transaction(function () use ($acc, $guardianId, $version) {
            $cur = DB::table('gp_accounts')->where('id', $acc->id)->lockForUpdate()->first(['auth_version']);
            return Mutation::update('gp_accounts', (int) $acc->id, $version, ['is_active' => false, 'auth_version' => (int) $cur->auth_version + 1],
                'guardian_portal.account.disabled', ['guardian_id' => $guardianId]);
        });
    }

    /** Link that can still be opened, or null. Same answer for unknown, expired, used and revoked tokens. */
    public static function peek(string $token): ?object
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/D', $token)) return null;
        $link = DB::table('gp_links')->where('school_id', Access::school())->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if (!$link || !Access::mayView((int) $link->guardian_id, (int) $link->student_id)) return null;
        return $link;
    }

    /** Atomically burns the link. False when somebody (or another tab) opened it first. */
    public static function consume(object $link): bool
    {
        return DB::table('gp_links')->where('id', $link->id)->where('school_id', Access::school())->whereNull('used_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->update(['used_at' => now(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]) === 1;
    }

    /** True when this invite attaches a child to an account that has a username but no e-mail: the holder then signs the invite with that username (and the old password). */
    public static function usernameOnlyAttach(object $link): bool
    {
        $acc = DB::table('gp_accounts')->where('school_id', Access::school())->where('guardian_id', $link->guardian_id)->first(['email_key', 'username_key']);
        if (!$acc || $acc->email_key !== null || $acc->username_key === null) return false;
        $creator = $link->created_by ? DB::table('users')->where('id', $link->created_by)->first(['id', 'role']) : null;
        return !self::canResetAccount($creator);
    }

    /**
     * A teacher-issued invite on an EXISTING account is a password check of that account: it goes through Links::attempt (failed counter, escalating lock, per-network cap),
     * outside the transaction below so that the counters survive the 422 that rolls it back, and a locked account is refused even with the right password.
     */
    private static function proveOwnership(object $link, string $email, string $key, string $password, ?string $net): void
    {
        $existing = DB::table('gp_accounts')->where('school_id', Access::school())->where('guardian_id', $link->guardian_id)->first();
        if (!$existing || !$existing->is_active) return; // no account: nothing to prove; a switched-off one is refused inside with its own message
        $creator = $link->created_by ? DB::table('users')->where('id', $link->created_by)->first(['id', 'role']) : null;
        if (self::canResetAccount($creator)) return;
        $who = $existing->email_key === null ? ($existing->username_key !== null && $existing->username_key === $key) : $existing->email_key === $key;
        if (!$who) return; // the transaction answers with the usual message (and burns nothing)
        $ok = self::attempt($email, $password, $net);
        if (!$ok || (int) $ok->id !== (int) $existing->id) {
            abort(422, $existing->email_key === null ? 'ผู้ปกครองรายนี้มีบัญชีอยู่แล้ว กรุณากรอกชื่อผู้ใช้และรหัสผ่านเดิมเพื่อเพิ่มนักเรียนคนนี้' : 'ผู้ปกครองรายนี้มีบัญชีอยู่แล้ว กรุณากรอกอีเมลและรหัสผ่านเดิมเพื่อเพิ่มนักเรียนคนนี้');
        }
    }

    /** Creates or resets the guardian account behind an invite link (the link is burned in the same transaction). */
    public static function setupAccount(object $link, string $email, string $password, ?string $net = null): object
    {
        $key = self::emailKey($email);
        self::proveOwnership($link, $email, $key, $password, $net);
        $taken = DB::table('gp_accounts')->where('school_id', Access::school())->where('email_key', $key)->where('guardian_id', '!=', $link->guardian_id)->exists();
        if ($taken) abort(409, 'อีเมลนี้ถูกใช้กับบัญชีผู้ปกครองอื่นแล้ว');
        try {
            return DB::transaction(function () use ($link, $email, $key, $password) {
                $existing = DB::table('gp_accounts')->where('school_id', Access::school())->where('guardian_id', $link->guardian_id)->lockForUpdate()->first();
                $attach = false;
                if ($existing) {
                    // A teacher-issued invite must never overwrite live credentials (a teacher of one child could take over a guardian who also
                    // sees other classrooms). The holder of the link has to prove they own the existing account; the child is then attached
                    // (link burned) and the e-mail/password stay as they were. Only admin/principal-issued invites may reset an account.
                    $creator = $link->created_by ? DB::table('users')->where('id', $link->created_by)->first(['id', 'role']) : null;
                    if (!self::canResetAccount($creator)) {
                        // a switched-off account may only be re-opened by admin/principal, never by a teacher-issued link
                        if (!$existing->is_active) abort(422, 'บัญชีผู้ปกครองรายนี้ถูกปิดใช้งาน กรุณาติดต่อโรงเรียนเพื่อเปิดใช้งานอีกครั้ง');
                        // a username-only account (issued by a teacher, no e-mail yet) is proven with that username; an account with an e-mail with the e-mail
                        $who = $existing->email_key === null ? ($existing->username_key !== null && $existing->username_key === $key) : $existing->email_key === $key;
                        if (!$who || !$existing->password || !Hash::check($password, $existing->password)) {
                            abort(422, $existing->email_key === null ? 'ผู้ปกครองรายนี้มีบัญชีอยู่แล้ว กรุณากรอกชื่อผู้ใช้และรหัสผ่านเดิมเพื่อเพิ่มนักเรียนคนนี้' : 'ผู้ปกครองรายนี้มีบัญชีอยู่แล้ว กรุณากรอกอีเมลและรหัสผ่านเดิมเพื่อเพิ่มนักเรียนคนนี้');
                        }
                        $attach = true;
                    }
                }
                if (!self::consume($link)) abort(404);
                if ($attach) {
                    Access::event('guardian.account.child_attached', (int) $link->guardian_id, (int) $link->student_id);
                    return DB::table('gp_accounts')->where('id', $existing->id)->first();
                }
                $hash = Hash::make($password);
                if ($existing) {
                    DB::table('gp_accounts')->where('id', $existing->id)->update(['email' => trim($email), 'email_key' => $key, 'password' => $hash, 'is_active' => true,
                        'auth_version' => DB::raw('auth_version + 1'), 'must_change' => false, 'failed_count' => 0, 'lock_count' => 0, 'locked_until' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                    $id = (int) $existing->id;
                    Contacts::revoke('guardian', (int) $link->guardian_id); // credentials were replaced: an old verified e-mail is no way back in
                } else {
                    $id = (int) DB::table('gp_accounts')->insertGetId(['school_id' => Access::school(), 'guardian_id' => $link->guardian_id, 'email' => trim($email), 'email_key' => $key,
                        'password' => $hash, 'is_active' => true, 'auth_version' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
                Access::event($existing ? 'guardian.account.reset' : 'guardian.account.created', (int) $link->guardian_id, (int) $link->student_id);
                return DB::table('gp_accounts')->where('id', $id)->first();
            });
        } catch (QueryException $e) {
            if (self::isUnique($e)) abort(409, 'อีเมลนี้ถูกใช้กับบัญชีผู้ปกครองอื่นแล้ว');
            throw $e;
        }
    }

    public const MAX_FAILED = 10;
    public const MAX_PER_NETWORK = 5;

    /** Escalating lock after MAX_FAILED wrong tries: 30 min, 1 h, 2 h ... at most 24 h (every lock expires on its own; a teacher unlock or reset clears it). */
    public static function lockMinutes(int $lockCount): int
    {
        return min(1440, 30 * (2 ** max(0, $lockCount - 1)));
    }

    /**
     * Account by sign-in name: an e-mail (contains @) or the username a teacher issued.
     * $viaContact (forgot-password only): an e-mail that is not the account's own sign-in e-mail may still be the VERIFIED contact e-mail of exactly one guardian
     * (gp_contacts), which is how a username-only account gets its reset link. Sign-in itself keeps using the account e-mail or the username.
     */
    public static function accountByLogin(string $identifier, bool $viaContact = false): ?object
    {
        $key = self::emailKey($identifier);
        if ($key === '') return null;
        $q = DB::table('gp_accounts')->where('school_id', Access::school())->where('is_active', true);
        if (!str_contains($key, '@')) return $q->where('username_key', $key)->first();
        $acc = (clone $q)->where('email_key', $key)->first();
        if ($acc || !$viaContact) return $acc;
        $ids = DB::table('gp_contacts')->where('school_id', Access::school())->where('kind', 'guardian')->where('email_key', $key)->whereNotNull('verified_at')->pluck('subject_id')->all();
        return count($ids) === 1 ? $q->where('guardian_id', (int) $ids[0])->first() : null; // two guardians sharing one address: ambiguous, so nobody
    }

    /**
     * Verified account for an e-mail or username + password, or null (same answer and about the same work for unknown name, wrong password,
     * locked and switched-off accounts). F8: MAX_FAILED wrong tries lock the account with a growing delay; one network may add at most
     * MAX_PER_NETWORK wrong tries per 30 minutes, so a single address cannot lock a guardian out.
     */
    public static function attempt(string $identifier, string $password, ?string $net = null): ?object
    {
        $acc = self::accountByLogin($identifier);
        // one bcrypt on every branch (Hash::make costs what Hash::check costs); no static dummy hash, which PHP-FPM would rebuild per request
        if (!$acc) { Hash::make('saan-dummy-account'); return null; }
        if (!$acc->password) { Hash::make('saan-dummy-account'); return null; }
        $hash = $acc->password;
        $netKey = $net === null ? null : 'gp-acct-net:'.$acc->id.':'.sha1($net.'|'.$acc->password);
        if ($netKey !== null && RateLimiter::tooManyAttempts($netKey, self::MAX_PER_NETWORK)) { Hash::check($password, $hash); return null; }
        if ($acc->locked_until !== null) {
            if (strtotime((string) $acc->locked_until) > time()) { Hash::check($password, $hash); return null; }
            DB::table('gp_accounts')->where('id', $acc->id)->where('locked_until', $acc->locked_until)->update(['locked_until' => null, 'failed_count' => 0]);
        }
        $reserved = DB::table('gp_accounts')->where('id', $acc->id)->whereNull('locked_until')->where('failed_count', '<', self::MAX_FAILED)->update(['failed_count' => DB::raw('failed_count + 1')]);
        if ($reserved === 0) { self::lock($acc); Hash::check($password, $hash); return null; }
        if ($acc->password && Hash::check($password, $hash)) {
            DB::table('gp_accounts')->where('id', $acc->id)->update(['failed_count' => 0, 'lock_count' => 0, 'locked_until' => null]);
            if ($netKey !== null) RateLimiter::clear($netKey);
            return DB::table('gp_accounts')->where('id', $acc->id)->first();
        }
        if ($netKey !== null) RateLimiter::hit($netKey, 1800);
        if ((int) DB::table('gp_accounts')->where('id', $acc->id)->value('failed_count') >= self::MAX_FAILED) self::lock($acc);
        return null;
    }

    private static function lock(object $acc): void
    {
        $next = (int) $acc->lock_count + 1;
        DB::table('gp_accounts')->where('id', $acc->id)->whereNull('locked_until')->update(['locked_until' => now()->addMinutes(self::lockMinutes($next))->toDateTimeString(), 'lock_count' => $next]);
    }
}