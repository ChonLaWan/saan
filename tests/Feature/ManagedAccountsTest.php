<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ManagedAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Fictional-Password-2026';

    protected function setUp(): void
    {
        parent::setUp(); config(['school.demo'=>true, 'school.local_only'=>true]); $this->seed();
    }

    private function user(string $role='admin', array $extra=[]): User
    {
        $user = new User;
        foreach (array_replace(['name'=>'บัญชีทดสอบ','email'=>uniqid('qa-').'@school.test','school_id'=>1,'role'=>$role,'password'=>self::PASSWORD,'is_active'=>true,'is_demo'=>false],$extra) as $key=>$value) $user->{$key}=$value;
        $user->save(); return $user->fresh();
    }

    private function input(array $extra=[]): array
    {
        return array_replace(['name'=>'ผู้ดูแลเว็บไซต์ทดสอบ','email'=>'editor@school.test','role'=>'editor','is_active'=>true,'password'=>self::PASSWORD,'password_confirmation'=>self::PASSWORD],$extra);
    }

    private function edit(User $user, array $extra=[]): array
    {
        return array_replace(['name'=>$user->name,'email'=>$user->email,'role'=>$user->role,'is_active'=>$user->is_active,'teacher_id'=>$user->teacher_id,'classroom_id'=>$user->classroom_id,'version'=>$user->version],$extra);
    }

    public function test_password_login_works_without_demo_and_rotates_session(): void
    {
        config(['school.demo'=>false]);$user=$this->user();$this->get('/login')->assertOk()->assertSee('name="password"',false)->assertDontSee('action="http://localhost/demo-login"',false);
        $this->post('/login',['email'=>mb_strtoupper($user->email),'password'=>self::PASSWORD])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);$this->get('/')->assertRedirect('/cms');$this->get('/cms')->assertOk();
        $this->assertNotNull($user->fresh()->last_login_at);$this->assertDatabaseHas('audit_events',['action'=>'auth.login']);
    }

    public function test_bad_password_inactive_demo_and_foreign_school_accounts_are_rejected(): void
    {
        foreach ([['is_active'=>false],['is_demo'=>true],['school_id'=>2]] as $extra) {
            $user=$this->user('editor',$extra);
            $this->post('/login',['email'=>$user->email,'password'=>self::PASSWORD])->assertSessionHasErrors('email');$this->assertGuest();
        }
        $user=$this->user();$this->post('/login',['email'=>$user->email,'password'=>'wrong-marker'])->assertSessionHasErrors('email');$this->assertGuest();
    }

    public function test_login_rate_limit_counts_failed_attempts(): void
    {
        $user=$this->user();for($i=0;$i<5;$i++)$this->post('/login',['email'=>$user->email,'password'=>'wrong-marker'])->assertSessionHasErrors('email');
        $this->post('/login',['email'=>$user->email,'password'=>self::PASSWORD])->assertSessionHasErrors(['email'=>'ลองเข้าสู่ระบบหลายครั้งแล้ว กรุณารอสักครู่แล้วลองใหม่']);$this->assertGuest();
    }

    public function test_editor_has_cms_access_without_student_or_user_access(): void
    {
        $editor=$this->user('editor');$this->actingAs($editor)->get('/')->assertRedirect('/cms');$this->get('/cms')->assertOk();$this->getJson('/api/cms/state')->assertOk();
        $this->get('/admin/users')->assertForbidden();$this->get('/operations')->assertForbidden();$this->getJson('/api/state')->assertForbidden();
        $this->postJson('/api/cms/posts',['title'=>'ข่าวจากผู้ดูแลเว็บ','body'=>'เนื้อหาสมมติ','intent'=>'publish'])->assertCreated();
    }

    public function test_editor_role_cannot_mutate_operational_data_even_with_teacher_links(): void
    {
        $editor=$this->user('editor',['teacher_id'=>1,'classroom_id'=>1]);$this->actingAs($editor);
        foreach (['/api/attendance','/api/grades','/api/leaves','/api/lessons','/api/duties','/api/posts'] as $path) {
            $this->postJson($path,[])->assertForbidden();
        }
        $this->getJson('/api/duty-suggestions?date=2026-11-05&starts_at=08:00&ends_at=08:20')->assertForbidden();
    }

    public function test_admin_creates_account_with_hashed_password_and_audit(): void
    {
        $this->actingAs($this->user())->postJson('/admin/users',$this->input())->assertCreated()->assertJsonPath('version',1);
        $user=User::where('email','editor@school.test')->firstOrFail();$this->assertTrue(Hash::check(self::PASSWORD,$user->password));$this->assertNotSame(self::PASSWORD,$user->password);$this->assertFalse($user->is_demo);
        $this->assertDatabaseHas('audit_events',['action'=>'account.created','entity_id'=>$user->id]);$this->get('/admin/users')->assertOk()->assertSee('editor@school.test');
    }

    public function test_account_form_accepts_string_version_and_rejects_stale_version(): void
    {
        $admin=$this->user();$user=$this->user('editor');$this->actingAs($admin)->put('/admin/users/'.$user->id,$this->edit($user,['version'=>'1','name'=>'ชื่อใหม่']))->assertRedirect('/admin/users');
        $this->assertSame('ชื่อใหม่',$user->fresh()->name);$this->putJson('/admin/users/'.$user->id,$this->edit($user))->assertConflict();
    }

    public function test_teacher_and_principal_cannot_manage_accounts(): void
    {
        foreach (['teacher','principal'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/users')->assertForbidden();$this->postJson('/admin/users',$this->input())->assertForbidden();
        }
    }

    public function test_account_scope_and_teacher_references_are_checked(): void
    {
        $admin=$this->user();$foreign=$this->user('editor',['school_id'=>2]);$this->actingAs($admin)->putJson('/admin/users/'.$foreign->id,$this->edit($foreign))->assertNotFound();
        DB::table('teachers')->where('id',1)->update(['school_id'=>2]);
        $this->postJson('/admin/users',$this->input(['role'=>'teacher','teacher_id'=>1,'classroom_id'=>1]))->assertNotFound();
        $this->postJson('/admin/users',$this->input(['role'=>'teacher']))->assertUnprocessable();
    }

    public function test_last_admin_and_self_demotion_are_refused(): void
    {
        $admin=$this->user();$this->actingAs($admin)->putJson('/admin/users/'.$admin->id,$this->edit($admin,['is_active'=>false]))->assertUnprocessable();
        $this->putJson('/admin/users/'.$admin->id,$this->edit($admin,['role'=>'editor']))->assertUnprocessable();
        $demo=User::where('email','admin@demo.school.test')->firstOrFail();$this->actingAs($demo)->putJson('/admin/users/'.$admin->id,$this->edit($admin,['role'=>'editor']))->assertUnprocessable();
    }

    public function test_disabled_account_loses_its_existing_session(): void
    {
        $user=$this->user('editor');$this->actingAs($user)->get('/cms')->assertOk();User::whereKey($user->id)->update(['is_active'=>false]);$this->getJson('/api/cms/state')->assertUnauthorized();$this->assertGuest();
    }

    public function test_account_changes_invalidate_previous_auth_version(): void
    {
        $user=$this->user('editor');$this->actingAs($user)->withSession(['auth_version'=>1])->get('/cms')->assertOk();User::whereKey($user->id)->update(['auth_version'=>2]);$this->getJson('/api/cms/state')->assertUnauthorized();
    }

    public function test_private_image_checks_fresh_account_before_any_protected_page_is_requested(): void
    {
        Storage::fake('local');$user=$this->user('editor');$this->actingAs($user)->withSession(['auth_version'=>1]);
        $raw=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jYuoAAAAASUVORK5CYII=');
        $r=$this->postJson('/api/cms/media',['image'=>UploadedFile::fake()->createWithContent('test.png',$raw)])->assertCreated();
        User::whereKey($user->id)->update(['auth_version'=>2]);
        $this->get('/media/'.$r->json('id'))->assertNotFound();
        User::whereKey($user->id)->update(['is_active'=>false]);
        $this->withSession(['auth_version'=>2])->get('/media/'.$r->json('id'))->assertNotFound();
    }

    public function test_password_change_requires_old_password_and_invalidates_other_versions(): void
    {
        $user=$this->user('editor');$this->actingAs($user)->withSession(['auth_version'=>1]);
        $this->putJson('/account/password',['current_password'=>'wrong-marker','password'=>'Another-Fictional-2026','password_confirmation'=>'Another-Fictional-2026'])->assertUnprocessable();
        $this->put('/account/password',['current_password'=>self::PASSWORD,'password'=>'Another-Fictional-2026','password_confirmation'=>'Another-Fictional-2026'])->assertRedirect();
        $user=$user->fresh();$this->assertTrue(Hash::check('Another-Fictional-2026',$user->password));$this->assertSame(2,$user->auth_version);$this->get('/cms')->assertOk();
        $this->withSession(['auth_version'=>1])->getJson('/api/cms/state')->assertUnauthorized();
    }

    public function test_demo_users_cannot_change_password_or_be_edited(): void
    {
        $demo=User::where('email','admin@demo.school.test')->firstOrFail();$this->actingAs($demo)->putJson('/account/password',[])->assertForbidden();$this->putJson('/admin/users/'.$demo->id,$this->edit($demo))->assertUnprocessable();
    }

    public function test_weak_passwords_and_unknown_roles_are_refused(): void
    {
        $this->actingAs($this->user())->postJson('/admin/users',$this->input(['password'=>'short1','password_confirmation'=>'short1']))->assertUnprocessable();
        $this->postJson('/admin/users',$this->input(['role'=>'unknown']))->assertUnprocessable();
    }

    public function test_bcrypt_byte_limit_and_null_bytes_are_validated_before_hashing(): void
    {
        $this->actingAs($this->user());
        foreach ([str_repeat('Long1',15),str_repeat('ก',25).'a1',"Fictional-Password-2026\0"] as $password) {
            $this->postJson('/admin/users',$this->input(['password'=>$password,'password_confirmation'=>$password]))->assertUnprocessable();
        }
        $this->assertDatabaseMissing('users',['email'=>'editor@school.test']);
    }

    public function test_logout_ends_password_session(): void
    {
        $user=$this->user();$this->post('/login',['email'=>$user->email,'password'=>self::PASSWORD])->assertRedirect();$this->post('/logout')->assertRedirect('/login');$this->get('/cms')->assertRedirect('/login');
    }
}
