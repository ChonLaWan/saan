<?php

namespace Saan\Modules\GuardianPortal;

use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\GuardianPortal\Mail\PortalLink;

/**
 * F8: optional contact e-mail of a student or guardian account (table gp_contacts). Only a VERIFIED e-mail enables self-service reset.
 * - verification: random token mailed as a link (sha256 stored, 60 minutes, opened with a confirm page + POST so link previews cannot burn it)
 * - reset: the existing Laravel password broker token store (config auth.passwords.users: 60 minutes, one token per identity, throttled),
 *   keyed by a synthetic identity "<kind>-<id>@portal.invalid" so it can never collide with a staff e-mail; links are built with MailUrl
 * Nothing here reveals whether an account exists: callers answer the same text either way.
 */
final class Contacts
{
    public const KINDS = ['student', 'guardian'];
    public const VERIFY_MINUTES = 60;
    public const GENERIC_RESET = 'ถ้าบัญชีนี้มีอีเมลที่ยืนยันแล้ว ระบบได้ส่งลิงก์ตั้งรหัสผ่านใหม่ไปให้แล้ว (ลิงก์ใช้ได้ 60 นาที) ถ้าไม่ได้รับอีเมล หรือไม่เคยเพิ่มอีเมล ให้แจ้งครูประจำชั้นหรือผู้ดูแลระบบของโรงเรียนเพื่อตั้งรหัสผ่านใหม่ให้';

    public static function row(string $kind, int $id): ?object
    {
        return DB::table('gp_contacts')->where('school_id', Access::school())->where('kind', $kind)->where('subject_id', $id)->first();
    }

    public static function verified(string $kind, int $id): ?object
    {
        $r = self::row($kind, $id);
        return $r && $r->verified_at !== null ? $r : null;
    }

    /** @param int[] $ids @return array<int,bool> subject id => has a verified e-mail */
    public static function verifiedMap(string $kind, array $ids): array
    {
        if (!$ids) return [];
        $have = DB::table('gp_contacts')->where('school_id', Access::school())->where('kind', $kind)->whereIn('subject_id', $ids)->whereNotNull('verified_at')->pluck('subject_id')->map(fn ($v) => (int) $v)->all();
        return array_fill_keys($have, true);
    }

    public static function mask(string $email): string
    {
        [$u, $d] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($u, 0, 1).str_repeat('*', max(2, mb_strlen($u) - 1)).'@'.$d;
    }

    /** What the account page shows. Never the full address of somebody else (own account only). */
    public static function status(string $kind, int $id): array
    {
        $r = self::row($kind, $id);
        return ['has_email' => (bool) $r, 'verified' => (bool) ($r && $r->verified_at !== null), 'email_masked' => $r ? self::mask((string) $r->email) : null];
    }

    /** Stores a pending e-mail (replacing any older one: a changed address is unverified until confirmed) and mails the link. */
    public static function begin(string $kind, int $id, string $email, string $schoolName): void
    {
        $email = trim($email);
        $key = mb_strtolower($email);
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $old = self::verified($kind, $id); // a verified address that is replaced gets a warning at the OLD address (a hijacked session must not rebind the account silently)
        $vals = ['email' => $email, 'email_key' => $key, 'verify_hash' => hash('sha256', $token), 'verify_expires_at' => now()->addMinutes(self::VERIFY_MINUTES)->toDateTimeString(), 'verified_at' => null, 'updated_at' => now()];
        $n = DB::table('gp_contacts')->where('school_id', Access::school())->where('kind', $kind)->where('subject_id', $id)->update($vals + ['version' => DB::raw('version + 1')]);
        if ($n === 0) DB::table('gp_contacts')->insert($vals + ['school_id' => Access::school(), 'kind' => $kind, 'subject_id' => $id, 'version' => 1, 'created_at' => now()]);
        self::resetDone($kind, $id); // a reset link mailed to the previous address must not survive the change (it would work again once the new address is confirmed)
        if ($old && $old->email_key !== $key) {
            self::deferSend((string) $old->email, 'อีเมลของบัญชีพอร์ทัลถูกเปลี่ยน — '.$schoolName, 'อีเมลของบัญชีพอร์ทัลถูกเปลี่ยน', ['มีคำขอเปลี่ยนอีเมลของบัญชีพอร์ทัลจากอีเมลนี้ไปเป็นอีเมลอื่น', 'อีเมลนี้จะไม่ใช้ขอรหัสผ่านใหม่ได้อีกจนกว่าอีเมลใหม่จะยืนยัน ถ้าคุณไม่ได้ทำเอง ให้แจ้งครูประจำชั้นหรือผู้ดูแลระบบของโรงเรียนทันที'],
                'เปิดพอร์ทัล', \App\Support\Saan\MailUrl::to('/'.$kind));
        }
        try {
            self::send($email, 'ยืนยันอีเมลสำหรับพอร์ทัล — '.$schoolName, 'ยืนยันอีเมลของบัญชีพอร์ทัล', ['มีคำขอเพิ่มอีเมลนี้กับบัญชีของพอร์ทัลโรงเรียน', 'กดปุ่มด้านล่างเพื่อยืนยัน ลิงก์ใช้ได้ '.self::VERIFY_MINUTES.' นาที ถ้าคุณไม่ได้ขอ ไม่ต้องทำอะไร'],
                'ยืนยันอีเมล', \App\Support\Saan\MailUrl::to('/'.$kind.'/verify/'.$token));
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'ส่งอีเมลยืนยันไม่สำเร็จ กรุณาลองใหม่ภายหลัง หรือแจ้งผู้ดูแลระบบ');
        }
    }

    /** Pending contact for a verification token that is still valid, or null (same for unknown and expired). */
    public static function peek(string $kind, string $token): ?object
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/D', $token)) return null;
        return DB::table('gp_contacts')->where('school_id', Access::school())->where('kind', $kind)->where('verify_hash', hash('sha256', $token))
            ->whereNull('verified_at')->where('verify_expires_at', '>', now())->first();
    }

    public static function confirm(string $kind, string $token): ?object
    {
        $c = self::peek($kind, $token);
        if (!$c) return null;
        $n = DB::table('gp_contacts')->where('id', $c->id)->whereNull('verified_at')->update(['verified_at' => now(), 'verify_hash' => null, 'verify_expires_at' => null, 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
        if ($n === 1) self::resetDone($kind, (int) $c->subject_id); // safety net: the address that is now verified starts with no outstanding reset link
        return $n === 1 ? $c : null;
    }

    private static function send(string $to, string $subject, string $headline, array $lines, string $action, string $url): void
    {
        Notification::route('mail', $to)->notify(new PortalLink($subject, $headline, $lines, $action, $url));
    }

    /** Same as send(), but after the response is out: the answer of a public form must not wait for SMTP (a slow or missing mail would tell who has an e-mail). Runs once even when the app object serves several requests (tests). */
    private static function deferSend(string $to, string $subject, string $headline, array $lines, string $action, string $url): void
    {
        $done = false;
        app()->terminating(function () use ($to, $subject, $headline, $lines, $action, $url, &$done) {
            if ($done) return;
            $done = true;
            try {
                self::send($to, $subject, $headline, $lines, $action, $url);
            } catch (\Throwable $e) {
                report($e); // a mail failure must look exactly like a mail that was sent
            }
        });
    }

    /**
     * Exactly one bcrypt (Hash::make, the same work as the broker's token create), on every request. No static cache of a dummy hash:
     * under PHP-FPM a static is empty on every request, so "make once then check" would cost two bcrypts per request and open the timing gap again.
     */
    public static function dummy(): void
    {
        Hash::make('saan-portal-dummy');
    }

    /** A staff reset of the account means the person at the keyboard may be somebody else: the e-mail stops being a way in until it is verified again. */
    public static function revoke(string $kind, int $id): void
    {
        DB::table('gp_contacts')->where('school_id', Access::school())->where('kind', $kind)->where('subject_id', $id)->update(['verified_at' => null, 'verify_hash' => null, 'verify_expires_at' => null, 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
        self::resetDone($kind, $id);
    }

    // ---- self-service reset through the password broker ----

    public static function identity(string $kind, int $id): CanResetPassword
    {
        return new class($kind, $id) implements CanResetPassword {
            public function __construct(private string $kind, private int $id) {}
            public function getEmailForPasswordReset() { return $this->kind.'-'.$this->id.'@portal.invalid'; }
            public function sendPasswordResetNotification($token) {}
        };
    }

    /** Sends a reset link when the subject has a verified e-mail. Returns whether a mail was sent (callers must NOT show this). */
    public static function sendReset(string $kind, int $id, string $schoolName): bool
    {
        $c = self::verified($kind, $id);
        if (!$c) { self::dummy(); return false; }
        $repo = Password::broker('users')->getRepository();
        $who = self::identity($kind, $id);
        if ($repo->recentlyCreatedToken($who)) { self::dummy(); return false; }
        $token = $repo->create($who); // one bcrypt (Hash::make)
        self::deferSend((string) $c->email, 'ตั้งรหัสผ่านใหม่ — '.$schoolName, 'ตั้งรหัสผ่านใหม่ของพอร์ทัล', ['มีคำขอตั้งรหัสผ่านใหม่สำหรับบัญชีของคุณ', 'ลิงก์นี้ใช้ได้ 60 นาทีและใช้ได้ครั้งเดียว ถ้าคุณไม่ได้ขอ ไม่ต้องทำอะไร รหัสผ่านเดิมยังใช้ได้'],
                'ตั้งรหัสผ่านใหม่', \App\Support\Saan\MailUrl::to('/'.$kind.'/reset/'.$token).'?'.http_build_query(['i' => $id]));
        return true;
    }

    public static function resetTokenValid(string $kind, int $id, string $token): bool
    {
        if ($token === '' || strlen($token) > 200 || self::verified($kind, $id) === null) return false;
        return Password::broker('users')->getRepository()->exists(self::identity($kind, $id), $token);
    }

    public static function resetDone(string $kind, int $id): void
    {
        Password::broker('users')->getRepository()->delete(self::identity($kind, $id));
    }

    /** Rate limit of the public "forgot" form: per network and per school, success or not (so the response never differs). */
    public static function throttleForgot(string $net): bool
    {
        $keys = ['portal-forgot-net:'.$net => 10, 'portal-forgot-school' => 200];
        foreach ($keys as $k => $max) if (RateLimiter::tooManyAttempts($k, $max)) return false;
        foreach (array_keys($keys) as $k) RateLimiter::hit($k, 900);
        return true;
    }
}
