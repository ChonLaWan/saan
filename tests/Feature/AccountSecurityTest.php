<?php

namespace Tests\Feature;

use App\Domain\Totp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_matches_rfc6238_vectors(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // ASCII "12345678901234567890"
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame(intdiv(1111111109, 30), Totp::verify($secret, '081 804', 1111111109));
        $this->assertNull(Totp::verify($secret, '000000', 1111111109));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', Totp::secret());
    }

    public function test_enable_mfa_then_login_needs_code_once_and_recovery_code_once(): void
    {
        config(['school.demo' => false]);
        $user = User::forceCreate(['name' => 'Admin', 'email' => 'mfa-admin@school.test', 'password' => 'Original-Password-2026', 'role' => 'admin', 'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1]);
        $this->actingAs($user)->post('/account/mfa/start')->assertRedirect('/account#mfa');
        $secret = session('mfa_setup'); $this->assertNotNull($secret);
        $this->get('/account')->assertOk()->assertSee(trim(chunk_split($secret, 4, ' ')));
        $this->post('/account/mfa/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/account/mfa/confirm', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertRedirect('/account#mfa');
        $codes = session('mfa_recovery_codes'); $this->assertCount(8, $codes);
        $fresh = $user->fresh(); $this->assertNotNull($fresh->mfa_enabled_at); $this->assertSame($secret, Crypt::decryptString($fresh->mfa_secret));
        $this->assertArrayNotHasKey('mfa_secret', $fresh->toArray());
        $this->post('/logout'); $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'mfa-admin@school.test', 'password' => 'Original-Password-2026'])->assertRedirect('/login/mfa');
        $this->assertGuest(); $this->get('/cms')->assertRedirect('/login');
        $this->post('/login/mfa', ['code' => '123456'])->assertSessionHasErrors('code');
        DB::table('users')->where('id', $user->id)->update(['mfa_last_step' => intdiv(time(), 30) - 5]);
        $this->post('/login/mfa', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertRedirect('/'); $this->assertAuthenticatedAs($user->fresh());
        $this->post('/logout'); $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'mfa-admin@school.test', 'password' => 'Original-Password-2026']);
        $this->post('/login/mfa', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertSessionHasErrors('code');
        $this->post('/login/mfa', ['code' => $codes[0]])->assertRedirect('/'); $this->assertAuthenticated();
        $this->post('/logout'); $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'mfa-admin@school.test', 'password' => 'Original-Password-2026']);
        $this->post('/login/mfa', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertDatabaseHas('audit_events', ['action' => 'account.mfa_recovery_used']);
    }

    public function test_sign_out_other_devices_requires_password_and_bumps_version(): void
    {
        config(['school.demo' => false]);
        $user = User::forceCreate(['name' => 'Editor', 'email' => 'dev-editor@school.test', 'password' => 'Original-Password-2026', 'role' => 'editor', 'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 3]);
        $this->actingAs($user)->post('/account/sessions/revoke', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post('/account/sessions/revoke', ['current_password' => 'Original-Password-2026'])->assertRedirect();
        $this->assertSame(4, $user->fresh()->auth_version); $this->get('/account')->assertOk()->assertSee('อุปกรณ์ที่เข้าสู่ระบบ');
    }
}
