<?php

namespace App\Domain;

use App\Models\User;
use App\Notifications\PasswordLinkNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * REL-05: e-mailed one-time links to set a password.
 * - "reset": requested by the person from the login page (expires in 60 minutes)
 * - "invite": sent by an admin for a new or existing account (expires in 72 hours)
 * Responses never reveal whether an e-mail address has an account.
 */
class PasswordLinks
{
    public const GENERIC = 'ถ้าอีเมลนี้มีบัญชีที่ใช้งานอยู่ ระบบได้ส่งลิงก์ตั้งรหัสผ่านไปแล้ว กรุณาตรวจกล่องจดหมายและ Spam';

    private function eligible(?User $user): bool
    {
        return $user && $user->is_active && !$user->is_demo && (int) $user->school_id === (int) config('school.school_id');
    }

    public function request(string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $key = 'password-link:'.hash('sha256', $email.'|'.$ip);
        if (RateLimiter::tooManyAttempts($key, 3)) return;
        RateLimiter::hit($key, 600);
        $user = User::where('email', $email)->first();
        if (!$this->eligible($user)) return;
        if (Password::broker('users')->getRepository()->recentlyCreatedToken($user)) return;
        $this->send($user, 'reset');
    }

    public function send(User $user, string $purpose): void
    {
        abort_unless($this->eligible($user), 422, 'ส่งลิงก์ได้เฉพาะบัญชีจริงที่เปิดใช้งาน');
        $broker = $purpose === 'invite' ? 'invites' : 'users';
        $token = Password::broker($broker)->createToken($user);
        $user->notify(new PasswordLinkNotification($token, $purpose));
        app(SchoolOperations::class)->auditAs($user->id, 'account.password_link_sent', 'users', $user->id, ['purpose' => $purpose]);
    }

    public function reset(string $email, string $token, string $password): void
    {
        $email = mb_strtolower(trim($email));
        $user = User::where('email', $email)->first();
        $valid = $this->eligible($user) && collect(['users', 'invites'])->contains(fn ($b) => Password::broker($b)->tokenExists($user, $token));
        if (!$valid) throw ValidationException::withMessages(['email' => 'ลิงก์หมดอายุหรือไม่ถูกต้อง กรุณาขอลิงก์ใหม่']);
        DB::transaction(function () use ($user, $password) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $fresh->password = $password; $fresh->auth_version++; $fresh->version++; $fresh->remember_token = null; $fresh->save();
            app(SchoolOperations::class)->auditAs($fresh->id, 'account.password_reset', 'users', $fresh->id);
        });
        foreach (['users', 'invites'] as $b) Password::broker($b)->deleteToken($user);
    }
}
