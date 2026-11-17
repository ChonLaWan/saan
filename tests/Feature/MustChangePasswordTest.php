<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Accounts whose password was generated for them (container install, administrator) must pick their own password at the first sign-in. */
class MustChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Generated-Password-2026';
    private const NEW = 'My-Own-Password-2027';

    protected function setUp(): void
    {
        parent::setUp(); config(['school.demo' => false, 'school.local_only' => true]); $this->seed();
    }

    private function user(array $extra = []): User
    {
        $user = new User;
        foreach (array_replace(['name' => 'ผู้ดูแลทดสอบ', 'email' => uniqid('qa-').'@school.test', 'school_id' => 1, 'role' => 'admin', 'password' => self::PASSWORD, 'is_active' => true, 'is_demo' => false], $extra) as $k => $v) $user->{$k} = $v;
        $user->save(); return $user->fresh();
    }

    private function signIn(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_marked_account_is_held_on_the_account_page_until_it_changes_the_password(): void
    {
        $user = $this->user();
        $this->artisan('school:require-password-change', ['email' => [$user->email]])->assertSuccessful();
        $this->assertTrue((bool) DB::table('users')->where('id', $user->id)->value('must_change_password'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'account.password_change_required')->where('entity_id', $user->id)->count());

        $this->signIn($user);
        $this->get('/cms')->assertRedirect('/account');
        $this->get('/admin/users')->assertRedirect('/account');
        $this->getJson('/api/module-settings')->assertStatus(403)->assertJson(['must_change_password' => true]);
        $this->get('/account')->assertOk()->assertSee('ต้องเปลี่ยนรหัสผ่านก่อนใช้งาน');

        // the generated password cannot simply be confirmed again
        $this->put('/account/password', ['current_password' => self::PASSWORD, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertSessionHasErrors('password');
        $this->assertTrue((bool) DB::table('users')->where('id', $user->id)->value('must_change_password'));

        $this->put('/account/password', ['current_password' => self::PASSWORD, 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertSessionHasNoErrors();
        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->getAttributes()['must_change_password']);
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));
        $this->get('/cms')->assertOk();
    }

    public function test_unmarked_accounts_are_not_affected_and_logout_stays_open(): void
    {
        $plain = $this->user();
        $this->signIn($plain);
        $this->get('/cms')->assertOk();
        $this->get('/account')->assertOk()->assertDontSee('ต้องเปลี่ยนรหัสผ่านก่อนใช้งาน');
        $this->post('/logout');
        $this->assertGuest();

        $marked = $this->user(['must_change_password' => true]);
        $this->signIn($marked);
        $this->post('/logout');
        $this->assertGuest();
    }

    public function test_command_all_skips_demo_accounts_and_clear_removes_the_mark(): void
    {
        $a = $this->user(); $b = $this->user(); $demo = $this->user(['is_demo' => true]);
        $this->artisan('school:require-password-change', ['--all' => true])->assertSuccessful();
        $this->assertTrue((bool) DB::table('users')->where('id', $a->id)->value('must_change_password'));
        $this->assertTrue((bool) DB::table('users')->where('id', $b->id)->value('must_change_password'));
        $this->assertFalse((bool) DB::table('users')->where('id', $demo->id)->value('must_change_password'));

        $this->artisan('school:require-password-change', ['email' => [$a->email], '--clear' => true])->assertSuccessful();
        $this->assertFalse((bool) DB::table('users')->where('id', $a->id)->value('must_change_password'));
        $this->artisan('school:require-password-change', ['email' => ['nobody@school.test']])->assertFailed();
        $this->artisan('school:require-password-change')->assertFailed();
    }
}
