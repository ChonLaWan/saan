<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\SchoolNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();config(['school.demo'=>true]);$this->seed();
    }

    private function realUser(string $role): User
    {
        $user=new User;$user->name='Real '.$role;$user->email='real-'.$role.'@school.test';$user->password='Fictional-Password-2026';$user->role=$role;$user->school_id=1;
        if($role==='teacher'){$demo=User::where('role','teacher')->first();$user->teacher_id=$demo->teacher_id;$user->classroom_id=$demo->classroom_id;}
        $user->save();return $user->fresh();
    }

    private function production(string $enable=''): ModuleRegistry
    {
        config(['school.demo'=>false,'saan.modules.enable'=>$enable,'saan.modules.disable'=>'']);
        $registry=app(ModuleRegistry::class);$registry->flush();return $registry;
    }

    public function test_manifests_are_valid_and_cover_existing_parts(): void
    {
        $modules=app(ModuleRegistry::class)->all();
        $ids=array_keys($modules);$sorted=$ids;sort($sorted);$this->assertSame($sorted,$ids);
        $this->assertSame([],array_values(array_diff(['academic','demo-library','operations','registry','web-cms','web-ita','web-social'],$ids)));
        $this->assertSame('beta',$modules['academic']['readiness']);$this->assertSame('beta',$modules['registry']['readiness']);
        $this->assertSame('experimental',$modules['operations']['readiness']);
        $this->assertSame('beta',$modules['web-cms']['readiness']);
    }

    public function test_version_constraints(): void
    {
        $this->assertTrue(ModuleRegistry::satisfies('0.6.0-dev','>=0.6.0'));
        $this->assertFalse(ModuleRegistry::satisfies('0.5.9','>=0.6.0'));
        $this->assertTrue(ModuleRegistry::satisfies('0.6.4','^0.6'));
        $this->assertFalse(ModuleRegistry::satisfies('0.7.0','^0.6'));
        $this->assertTrue(ModuleRegistry::satisfies('1.9.0','^1.2'));
        $this->assertFalse(ModuleRegistry::satisfies('2.0.0','^1.2'));
    }

    public function test_production_disables_experimental_operations_but_keeps_website(): void
    {
        $registry=$this->production();
        $this->assertFalse($registry->active('operations'));$this->assertTrue($registry->active('web-cms'));
        $teacher=$this->realUser('teacher');$admin=$this->realUser('admin');
        $this->actingAs($teacher)->getJson('/api/state')->assertNotFound();
        $this->postJson('/api/attendance',[])->assertNotFound();
        $this->get('/operations')->assertOk()->assertSee('ระบบงานนี้ยังไม่เปิดใช้งาน')->assertDontSee('id="boot"',false);
        $this->get('/')->assertRedirect('/work'); // N8: teachers land on the new work shell, not the retired overview
        $this->actingAs($admin)->postJson('/api/posts',['title'=>'x'])->assertNotFound();
        $this->get('/cms')->assertOk();$this->getJson('/api/site/state')->assertOk();
        $statuses=collect(SchoolNavigation::groups('admin'))->flatMap(fn($g)=>$g['items'])->filter(fn($i)=>str_starts_with($i['path'],'/operations'))->pluck('status')->unique()->values()->all();
        $this->assertSame(['off'],$statuses);
        $this->get('/cms')->assertSee('ตารางสอน')->assertSee('<small>ปิด</small>',false);
    }

    public function test_admin_can_enable_operations_explicitly(): void
    {
        $registry=$this->production('operations');
        $this->assertTrue($registry->active('operations'));
        $this->actingAs($this->realUser('teacher'))->getJson('/api/state')->assertOk();
    }

    public function test_core_required_module_cannot_be_disabled_and_health_lists_modules(): void
    {
        config(['saan.modules.disable'=>'web-cms,demo-library']);$registry=app(ModuleRegistry::class);$registry->flush();
        $this->assertTrue($registry->active('web-cms'));$this->assertFalse($registry->active('demo-library'));
        $this->assertSame(array_keys($registry->all()),array_column(app(\App\Domain\Cms\DeploymentHealth::class)->inspect()['modules'],'id'));
    }
}
