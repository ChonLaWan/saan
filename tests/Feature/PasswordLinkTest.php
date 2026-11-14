<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordLinkTest extends TestCase
{
    use RefreshDatabase;

    private function real(string $role = 'editor', string $email = 'real-editor@school.test'): User
    {
        return User::forceCreate(['name' => 'Real '.$role, 'email' => $email, 'password' => 'Original-Password-2026', 'role' => $role, 'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1]);
    }

    private function tokenFrom(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, PasswordLinkNotification::class, function ($n) use (&$token) { $token = (fn () => $this->token)->call($n); return true; });
        return $token;
    }

    public function test_forgot_password_sends_link_without_revealing_accounts_and_reset_revokes_sessions(): void
    {
        config(['school.demo' => true]); $this->seed(); Notification::fake();
        $user = $this->real();
        $this->post('/forgot-password', ['email' => 'nobody@school.test'])->assertSessionHas('status', \App\Domain\PasswordLinks::GENERIC);
        $this->post('/forgot-password', ['email' => 'REAL-EDITOR@school.test'])->assertSessionHas('status', \App\Domain\PasswordLinks::GENERIC);
        $demo = User::where('is_demo', true)->first(); $this->post('/forgot-password', ['email' => $demo->email]);
        Notification::assertSentTo($user, PasswordLinkNotification::class); Notification::assertNotSentTo($demo, PasswordLinkNotification::class);
        Notification::assertCount(1);
        $token = $this->tokenFrom($user);
        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk()->assertSee('ตั้งรหัสผ่าน');
        $this->post('/reset-password', ['token' => 'x'.str_repeat('a', 40), 'email' => $user->email, 'password' => 'Brand-New-Pass-2026', 'password_confirmation' => 'Brand-New-Pass-2026'])->assertSessionHasErrors('email');
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Brand-New-Pass-2026', 'password_confirmation' => 'Brand-New-Pass-2026'])->assertRedirect('/login');
        $fresh = $user->fresh(); $this->assertTrue(Hash::check('Brand-New-Pass-2026', $fresh->password)); $this->assertSame(2, $fresh->auth_version);
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Another-Pass-2026', 'password_confirmation' => 'Another-Pass-2026'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_events', ['action' => 'account.password_reset', 'entity_id' => $user->id]);
    }

    public function test_admin_invites_new_user_by_email_and_can_resend(): void
    {
        config(['school.demo' => false]); $this->seed(); Notification::fake();
        $admin = $this->real('admin', 'real-admin@school.test');
        $this->actingAs($admin)->post('/admin/users', ['name' => 'Invited Editor', 'email' => 'invited@school.test', 'role' => 'editor', 'is_active' => 1, 'invite' => 1])->assertRedirect('/admin/users');
        $invited = User::where('email', 'invited@school.test')->firstOrFail();
        Notification::assertSentTo($invited, PasswordLinkNotification::class, fn ($n) => (fn () => $this->purpose)->call($n) === 'invite');
        $mail = (new PasswordLinkNotification('t0ken', 'invite'))->toMail($invited);
        $this->assertStringContainsString('72', implode(' ', array_merge($mail->introLines, $mail->outroLines)));
        $this->post('/admin/users/'.$invited->id.'/password-link')->assertRedirect();
        Notification::assertSentToTimes($invited, PasswordLinkNotification::class, 2);
        $this->post('/admin/users', ['name' => 'Bad', 'email' => 'bad@school.test', 'role' => 'editor', 'is_active' => 1])->assertSessionHasErrors('password');
    }
}
