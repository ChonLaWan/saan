<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SchoolNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();config(['school.demo'=>true]);$this->seed();
    }

    public function test_six_school_menus_are_present_in_all_authenticated_admin_shells(): void
    {
        $this->actingAs(User::where('role','admin')->first());
        foreach (['/cms','/operations','/account','/admin/users','/development-plan'] as $path) {
            $response=$this->get($path)->assertOk()->assertSee('id="school-navigation"',false);
            foreach (['ภาพรวมโรงเรียน','ตารางสอน','ตารางครูเวร','ครูและภาระงาน','งานรออนุมัติ','แผนระบบหลังบ้าน'] as $label) $response->assertSee($label);
        }
    }

    public function test_internal_development_pages_are_listed_only_in_demo_installs(): void
    {
        $dev = ['/development-plan', '/website-coverage'];
        foreach (['admin', 'principal', 'editor', 'teacher'] as $role) {
            config(['school.demo' => false]);
            $items = collect(SchoolNavigation::groups($role))->flatMap(fn ($group) => $group['items']);
            foreach ($items->pluck('path') as $path) foreach ($dev as $d) $this->assertFalse(str_starts_with($path, $d), $role.' must not see '.$path.' outside demo');
            $this->assertFalse(collect(SchoolNavigation::groups($role))->contains('label', 'ทุกระบบใน checklist'), $role);

            config(['school.demo' => true]);
            $items = collect(SchoolNavigation::groups($role))->flatMap(fn ($group) => $group['items']);
            $this->assertTrue($items->pluck('path')->contains(fn ($p) => str_starts_with($p, '/development-plan')), $role.' demo');
        }
        config(['school.demo' => true]);
        $this->assertTrue(collect(SchoolNavigation::groups('admin'))->flatMap(fn ($g) => $g['items'])->pluck('path')->contains('/website-coverage'));
    }

    public function test_teacher_menu_keeps_own_requests_without_admin_or_cms_actions(): void
    {
        $groups=SchoolNavigation::groups('teacher');$paths=collect($groups)->flatMap(fn($group)=>$group['items'])->pluck('path');
        $this->assertFalse($paths->contains('/admin/users'));$this->assertFalse($paths->contains('/cms#compose'));
        $this->assertFalse($paths->contains('/operations#approvals'));$this->assertTrue($paths->contains('/operations#people'));
        $this->actingAs(User::where('role','teacher')->first())->get('/development-plan')->assertOk();
    }

    public function test_editor_menu_has_cms_without_operational_data_links(): void
    {
        $groups=SchoolNavigation::groups('editor');$paths=collect($groups)->flatMap(fn($group)=>$group['items'])->pluck('path');
        $this->assertTrue($paths->contains('/cms#theme'));$this->assertTrue($paths->contains('/cms#compose'));
        $this->assertFalse($paths->contains('/admin/users'));$this->assertFalse($paths->contains(fn($path)=>str_starts_with($path,'/operations')));
        $user=new User;$user->name='Website editor';$user->email='editor-nav@school.test';$user->password='Fictional-Password-2026';$user->role='editor';$user->school_id=1;$user->save();
        $this->actingAs($user->fresh())->get('/cms')->assertOk();$this->get('/development-plan')->assertOk();$this->get('/operations')->assertForbidden();
    }

    public function test_checklist_covers_all_24_modules_and_foundations_without_claiming_release_ready(): void
    {
        $plan=json_decode(file_get_contents(resource_path('data/development-checklist.json')),true);
        $this->assertSame(array_map(fn($number)=>sprintf('M%02d',$number),range(1,24)),array_column($plan['modules'],'id'));
        $this->assertCount(8,$plan['foundations']);$tasks=collect($plan['modules'])->concat($plan['foundations'])->flatMap(fn($module)=>$module['tasks']);
        $this->assertSame($tasks->count(),$tasks->pluck('id')->unique()->count());$this->assertGreaterThan(150,$tasks->count());
        foreach ($plan['modules'] as $module) {
            $this->assertFalse($module['release_ready']);$this->assertNotEmpty($module['source_acceptance']);
            $this->assertTrue(collect($module['tasks'])->contains('status','pending'));
        }
        $items=collect(SchoolNavigation::groups('admin'))->flatMap(fn($group)=>$group['items'])->keyBy('key');
        foreach (array_merge($plan['modules'],$plan['foundations']) as $module) $this->assertSame('/development-plan#'.$module['id'],$items[$module['id']]['path']);
    }

    public function test_checklist_and_export_require_authentication_and_download_the_draft(): void
    {
        $this->get('/development-plan')->assertRedirect('/login');$this->get('/development-plan/export')->assertRedirect('/login');
        $this->actingAs(User::where('role','admin')->first())->get('/development-plan')->assertOk()->assertSee('Checklist พัฒนาระบบโรงเรียน');
        $response=$this->get('/development-plan/export')->assertOk()->assertDownload('school-full-system-checklist-2026-11-05.txt');
        $file=$response->baseResponse->getFile()->getPathname();$this->assertSame(hash_file('sha256',resource_path('data/full-system-checklist.txt')),hash_file('sha256',$file));
    }
}
