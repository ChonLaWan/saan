<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();config(['school.demo'=>true]);$this->seed();$this->actingAs(User::where('role','admin')->first());
    }

    public function test_facebook_block_is_click_to_load_and_only_after_homepage_publish(): void
    {
        $this->putJson('/api/social',['version'=>0,'enabled'=>true,'facebook_url'=>'https://www.facebook.com/example.school?access_token=x','heading'=>'FB','height'=>640])->assertUnprocessable();
        $this->putJson('/api/social',['version'=>0,'enabled'=>true,'facebook_url'=>'javascript:alert(1)','heading'=>'FB','height'=>640])->assertUnprocessable();
        $s=$this->putJson('/api/social',['version'=>0,'enabled'=>true,'facebook_url'=>'https://www.facebook.com/example.school','heading'=>'FB-BLOCK-HEADING','height'=>640])->assertOk()->json();
        $this->assertSame(1,$s['version']);
        $this->app['auth']->forgetGuards();$this->get('/portal')->assertOk()->assertDontSee('FB-BLOCK-HEADING');
        $this->actingAs(User::where('role','admin')->first());
        $settings=$this->getJson('/api/site/state')->assertOk()->json('settings.draft');
        $settings['homepage_sections'][]='module:web-social';
        $this->putJson('/api/site/settings',['settings'=>$settings,'version'=>$this->getJson('/api/site/state')->json('settings.version'),'intent'=>'publish','section'=>'homepage'])->assertOk();
        $this->app['auth']->forgetGuards();
        $html=$this->get('/portal')->assertOk()->assertSee('FB-BLOCK-HEADING')->assertSee('data-facebook-load',false)->getContent();
        $this->assertStringNotContainsString('<iframe',substr($html,strpos($html,'FB-BLOCK-HEADING'),2000),'iframe must not load before the visitor clicks');
        $this->assertStringContainsString('facebook.com/plugins/page.php?href=https%3A%2F%2Fwww.facebook.com%2Fexample.school',$html);
    }

    public function test_unknown_module_section_is_rejected_and_teacher_blocked(): void
    {
        $settings=$this->getJson('/api/site/state')->json('settings.draft');$settings['homepage_sections'][]='module:not-installed';
        $this->putJson('/api/site/settings',['settings'=>$settings,'version'=>$this->getJson('/api/site/state')->json('settings.version'),'intent'=>'draft'])->assertUnprocessable();
        $this->actingAs(User::where('role','teacher')->first())->getJson('/api/social')->assertForbidden();
        $this->actingAs(User::where('role','admin')->first())->get('/cms')->assertSee('assets/modules/web-social/social-admin.js',false)->assertSee('ข่าวจากเพจ Facebook');
    }
}
