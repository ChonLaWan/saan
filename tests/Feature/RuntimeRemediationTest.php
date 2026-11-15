<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Domain\SchoolMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Regression tests for runtime findings of functional-audit-2026-11-06. */
class RuntimeRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rt01_cached_config_keeps_env_mail_transport(): void
    {
        $mailFile=require config_path('mail.php');
        $this->assertArrayHasKey('school_fallback',$mailFile,'fallback must be defined in config/mail.php so config:cache keeps it');
        // Replay what a cold boot sees after config:cache: default already replaced by 'school', fallback cached from env.
        config(['mail.default'=>'school','mail.school_fallback'=>'smtp']);
        (new AppServiceProvider($this->app))->boot();
        $this->assertSame('smtp',config('mail.school_fallback'));$this->assertSame('school',config('mail.default'));
        // Boot twice (as tests and queue workers do) must not degrade it either.
        (new AppServiceProvider($this->app))->boot();
        $this->assertSame('smtp',config('mail.school_fallback'));
    }

    private function realAdmin(): User
    {
        config(['school.demo'=>false,'school.local_only'=>true,'school.school_id'=>1]);
        return User::forceCreate(['name'=>'Real admin','email'=>'real-admin@school.test','password'=>'Fictional-Password-2026','role'=>'admin','school_id'=>1,'is_active'=>true,'is_demo'=>false,'auth_version'=>1]);
    }

    public function test_rt02_unreadable_mail_settings_can_be_reset_explicitly(): void
    {
        $this->actingAs($this->realAdmin());
        $settings=array_replace(SchoolMail::defaults(),['provider'=>'local','host'=>'127.0.0.1','port'=>1025,'from_address'=>'sender@school.test']);
        $saved=$this->putJson('/api/site/mail',['version'=>0,'settings'=>$settings])->assertOk()->json();
        DB::table('school_mail_settings')->update(['encrypted_payload'=>'not-a-valid-ciphertext']);
        $state=$this->getJson('/api/site/mail')->assertOk()->assertJsonPath('needs_reset',true)->json();
        $this->assertArrayNotHasKey('password',$state['settings']);
        $this->putJson('/api/site/mail',['version'=>$state['version'],'settings'=>$settings])->assertUnprocessable();
        $this->postJson('/api/site/mail/reset',['version'=>$state['version']])->assertUnprocessable();
        $this->postJson('/api/site/mail/reset',['version'=>$state['version'],'current_password'=>'wrong-password'])->assertUnprocessable();
        $this->assertSame('not-a-valid-ciphertext',DB::table('school_mail_settings')->value('encrypted_payload'));
        $after=$this->postJson('/api/site/mail/reset',['version'=>$state['version'],'current_password'=>'Fictional-Password-2026'])->assertOk()->assertJsonPath('needs_reset',false)->assertJsonPath('settings.provider','env')->json();
        $this->assertSame($saved['version']+1,$after['version']);
        $this->assertDatabaseHas('audit_events',['action'=>'mail.settings.reset']);
        $this->postJson('/api/site/mail/reset',['version'=>$after['version'],'current_password'=>'Fictional-Password-2026'])->assertUnprocessable();
    }

    public function test_n5_each_throttled_route_has_its_own_counter(): void
    {
        foreach (app('router')->getRoutes() as $route) foreach ($route->gatherMiddleware() as $m) if (is_string($m) && str_starts_with($m,'throttle:')) $this->assertCount(3,explode(',',substr($m,9)),$route->uri().' needs a per-route throttle prefix');
        for ($i=0;$i<6;$i++) $this->post('/portal/contact',[]);
        // the visitor gets a Thai page with a way back, not the framework's bare English "429 | Too Many Requests"
        $this->post('/portal/contact',[])->assertStatus(429)->assertSee('ส่งคำขอถี่เกินไป')->assertSee('กลับไปหน้าเดิม')->assertDontSee('Too Many Requests');
        $this->post('/login',['email'=>'nobody@school.test','password'=>'wrong'])->assertStatus(302);
    }

    public function test_rt05_heartbeat_write_failure_is_reported_as_failure(): void
    {
        $content=\Mockery::mock(\App\Domain\Cms\WebsiteContent::class);$content->shouldReceive('publishDue')->once()->andReturn(2);$this->app->instance(\App\Domain\Cms\WebsiteContent::class,$content);
        $disk=\Mockery::mock();$disk->shouldReceive('put')->once()->andReturn(false);
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
        $this->assertSame(1,\Illuminate\Support\Facades\Artisan::call('cms:publish-due'));
        $out=\Illuminate\Support\Facades\Artisan::output();$this->assertStringContainsString('Published 2',$out);$this->assertStringContainsString('[ERR]',$out);
    }

    public function test_rt07_doctor_checks_public_deployment_conditions(): void
    {
        config(['school.local_only'=>true,'session.secure'=>false]);
        $report=app(\App\Domain\Cms\DeploymentHealth::class)->inspect();$checks=collect($report['checks'])->keyBy('key');
        foreach(['key','public_access','cookies','backup','mail','scheduler','admin'] as $key)$this->assertTrue($checks->has($key),$key);
        $this->assertTrue($checks['key']['ok']);$this->assertFalse($checks['public_access']['ok']);$this->assertFalse($checks['cookies']['ok']);
        $this->assertFalse($report['readiness']['cms_public']);$this->assertArrayHasKey('backup',$report['readiness']);
        config(['app.key'=>'base64:'.base64_encode('short')]);app()->forgetInstance('encrypter');\Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');
        $broken=collect(app(\App\Domain\Cms\DeploymentHealth::class)->inspect()['checks'])->keyBy('key');
        $this->assertFalse($broken['key']['ok']);
    }

    public function test_g9_media_quota_blocks_uploads_beyond_school_limit(): void
    {
        config(['school.demo'=>true]);$this->seed();$this->actingAs(User::where('role','admin')->first());
        $used=(int)DB::table('cms_media')->where('school_id',1)->sum('bytes');
        config(['saan.storage.quota_mb'=>(int)ceil(($used+2048)/1048576)]);
        $room=(int)config('saan.storage.quota_mb')*1048576-$used;
        $this->getJson('/api/site/media')->assertOk()->assertJsonPath('storage.used_bytes',$used);
        $big=\Illuminate\Http\UploadedFile::fake()->createWithContent('big.pdf',"%PDF-1.4\n".str_repeat('A',$room+10)."\n%%EOF");
        $msg=$this->postJson('/api/site/media',['file'=>$big])->assertUnprocessable()->json('message');$this->assertStringContainsString('พื้นที่ไฟล์ของโรงเรียนเต็ม',$msg);
        config(['saan.storage.quota_mb'=>0]);
        $this->postJson('/api/site/media',['file'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('ok.pdf',"%PDF-1.4\n%%EOF")])->assertCreated();
        $this->assertTrue(collect(app(\App\Domain\Cms\DeploymentHealth::class)->inspect()['checks'])->contains('key','storage_quota'));
    }
}
