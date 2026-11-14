<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Tests\TestCase;

class BootstrapAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_admin_is_created_without_demo_seed_or_plaintext_storage(): void
    {
        config(['school.demo'=>false]);
        $this->artisan('school:admin')->expectsQuestion('ชื่อผู้ดูแลระบบ','ผู้ดูแลโรงเรียน')
            ->expectsQuestion('อีเมล','owner@school.test')
            ->expectsQuestion('รหัสผ่าน (อย่างน้อย 12 ตัว มีตัวอักษรและตัวเลข)','Fictional-Password-2026')
            ->expectsQuestion('ยืนยันรหัสผ่าน','Fictional-Password-2026')->assertSuccessful();
        $user=User::where('email','owner@school.test')->firstOrFail();$this->assertFalse($user->is_demo);$this->assertSame('admin',$user->role);$this->assertTrue(Hash::check('Fictional-Password-2026',$user->password));
        $this->assertDatabaseHas('audit_events',['action'=>'account.bootstrapped','actor_id'=>$user->id]);
        $this->post('/login',['email'=>'owner@school.test','password'=>'Fictional-Password-2026'])->assertRedirect('/');
        $this->getJson('/api/cms/state')->assertOk();
        $this->artisan('school:admin')->assertFailed();
    }
}
