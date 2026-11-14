<?php

namespace Tests\Feature;

use App\Domain\InstallationContent;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Cross-module checks: academic (rooms, teachers, accounts) and registry (students) share the same core tables. Fictional data only. */
class SaanIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::where('role', 'admin')->first();
    }

    private function login(User $u): static
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($u->fresh());
    }

    public function test_academic_homeroom_drives_registry_teacher_visibility(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        Notification::fake();
        $this->login($this->admin());
        $t = $this->postJson('/api/academic/teachers', ['prefix' => 'ครู', 'first_name' => 'สมมติ', 'last_name' => 'ทดลองสาน'])->assertCreated()->json();
        $room = $this->postJson('/api/academic/classrooms', ['grade_level' => 'ป.6', 'section' => 2, 'homeroom_teacher_id' => $t['id']])->assertCreated()->json();
        $this->assertSame('ป.6/2', $room['name']);
        $this->postJson('/api/academic/teachers/'.$t['id'].'/account', ['email' => 'teacher.integration@school.test'])->assertCreated();
        $student = $this->postJson('/api/registry/students', ['code' => 'INT-01', 'first_name' => 'เด็กสมมติ', 'last_name' => 'ห้องใหม่', 'classroom_id' => $room['id']])
            ->assertCreated()->json('student');
        $this->postJson('/api/registry/students', ['code' => 'int-01', 'first_name' => 'ซ้ำ', 'last_name' => 'รหัส'])->assertStatus(422);
        $this->getJson('/api/academic/classrooms')->assertOk()->assertJsonFragment(['id' => $room['id'], 'student_count' => 1]);

        $account = User::where('email', 'teacher.integration@school.test')->firstOrFail();
        $this->assertSame($room['id'], (int) $account->classroom_id);
        $this->login($account);
        $codes = collect($this->getJson('/api/registry/students')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['INT-01'], $codes);
        $this->getJson('/api/registry/students/'.$student['id'])->assertOk();
        $this->assertSame([$room['id']], collect($this->getJson('/api/registry/classrooms')->assertOk()->json('data'))->pluck('id')->all());

        // moving the homeroom to another teacher moves registry access with it
        $this->login($this->admin());
        $v = DB::table('classrooms')->where('id', $room['id'])->value('version');
        $this->putJson('/api/academic/homerooms', ['assignments' => [['classroom_id' => $room['id'], 'version' => (int) $v, 'teacher_id' => 1]]])->assertOk();
        $this->assertNull($account->fresh()->classroom_id);
        $this->login($account);
        $this->getJson('/api/registry/students')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/registry/students/'.$student['id'])->assertNotFound();
    }

    public function test_classroom_with_registry_students_cannot_be_deleted_and_unplaced_student_does_not_break_operations(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        $this->login($this->admin());
        $room = $this->postJson('/api/academic/classrooms', ['name' => 'ห้องทดลองลบ'])->assertCreated()->json();
        $s = $this->postJson('/api/registry/students', ['code' => 'INT-DEL', 'first_name' => 'ทดลอง', 'last_name' => 'ลบห้อง', 'classroom_id' => $room['id']])->assertCreated()->json('student');
        $this->deleteJson('/api/academic/classrooms/'.$room['id'], ['version' => $room['version']])->assertStatus(422);
        $this->assertDatabaseHas('classrooms', ['id' => $room['id']]);

        $this->putJson('/api/registry/students/'.$s['id'], ['version' => $s['version'], 'classroom_id' => null])->assertOk();
        $this->deleteJson('/api/academic/classrooms/'.$room['id'], ['version' => $room['version']])->assertOk();

        // the experimental operations prototype still reads the expanded tables; the retired grade write answers 410 (N8), never a 500
        $this->getJson('/api/state')->assertOk()->assertJsonStructure(['teachers', 'classrooms', 'students', 'term']);
        $this->postJson('/api/grades', ['student_id' => $s['id'], 'continuous' => 50, 'exam' => 20])->assertStatus(410);
        $this->login(User::where('role', 'teacher')->first());
        $this->getJson('/api/state')->assertOk();
    }

    public function test_work_shell_lists_academic_and_registry_pages_by_role(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        $reg = app(ModuleRegistry::class);
        $teacher = array_column($reg->workMenu('teacher'), 'key');
        $admin = array_column($reg->workMenu('admin'), 'key');
        foreach (['calendar', 'students'] as $k) $this->assertContains($k, $teacher);
        foreach (['setup', 'import'] as $k) { $this->assertNotContains($k, $teacher); $this->assertContains($k, $admin); }
        // editors (web content) never get student or academic pages; a staff-wide page such as the documents inbox is allowed
        $editor = array_column($reg->workMenu('editor'), 'key');
        foreach (['setup', 'calendar', 'students', 'import', 'attendance', 'attendance-report', 'scores', 'courses', 'behavior', 'health', 'timetable', 'leave-approve'] as $k) $this->assertNotContains($k, $editor);
        foreach (['assets/modules/academic/academic.js', 'assets/modules/registry/registry.js'] as $a) $this->assertContains($a, $reg->workAssets());

        $this->login(User::where('role', 'teacher')->first());
        $this->get('/work')->assertOk()->assertSee('/work#students', false)->assertSee('/work#calendar', false)
            ->assertDontSee('/work#setup', false)->assertDontSee('/work#import', false)->assertSee('assets/modules/registry/registry.js', false);
        $this->login($this->admin());
        $this->get('/work')->assertOk()->assertSee('/work#setup', false)->assertSee('/work#import', false);
    }

    public function test_blank_install_then_academic_setup_and_registry_work_on_empty_school(): void
    {
        app(InstallationContent::class)->install(['mode' => 'blank', 'school_name' => 'โรงเรียนสมมติสาน', 'admin_name' => 'ผู้ดูแลสมมติ', 'admin_email' => 'owner@school.example',
            'password' => 'Synthetic-Install-2026', 'password_confirmation' => 'Synthetic-Install-2026', 'app_url' => 'https://school.example', 'public_email' => '', 'phone' => '', 'address' => ''], 'integration');
        config(['school.demo' => false, 'school.local_only' => false]);
        $this->login(User::first());
        $setup = $this->getJson('/api/academic/setup')->assertOk()->json();
        $this->assertIsArray($setup);
        $this->postJson('/api/academic/classrooms/bulk', ['grades' => ['ป.1'], 'sections' => 2])->assertCreated();
        $room = collect($this->getJson('/api/academic/classrooms')->assertOk()->json('data'))->firstWhere('name', 'ป.1/1');
        $this->assertNotNull($room);
        $this->postJson('/api/registry/students', ['code' => 'NEW-01', 'first_name' => 'นักเรียนสมมติ', 'last_name' => 'คนแรก', 'classroom_id' => $room['id']])->assertCreated();
        $this->getJson('/api/registry/students')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/work')->assertOk()->assertSee('/work#setup', false)->assertSee('ปีการศึกษา', false);
    }
}
