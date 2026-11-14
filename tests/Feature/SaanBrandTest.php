<?php

namespace Tests\Feature;

use App\Domain\Cms\DeploymentHealth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaanBrandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();config(['school.demo'=>true]);$this->seed();
    }

    public function test_version_has_single_source_and_health_reports_it(): void
    {
        $version=trim(file_get_contents(base_path('VERSION')));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/',$version);
        $this->assertSame($version,config('saan.version'));
        $this->assertSame($version,app(DeploymentHealth::class)->inspect()['release']);
    }

    public function test_platform_brand_assets_exist_and_are_transparent_png(): void
    {
        foreach (config('saan.assets') as $path) $this->assertFileExists(public_path($path));
        foreach (['logo_light','logo_dark','mark_light','mark_dark'] as $key) {
            $info=getimagesize(public_path(config('saan.assets.'.$key)));
            $this->assertSame(IMAGETYPE_PNG,$info[2]);
            $this->assertSame(6,ord(file_get_contents(public_path(config('saan.assets.'.$key)),false,null,25,1)),'PNG colour type must be RGBA');
        }
    }

    public function test_staff_pages_show_saan_brand_and_favicon(): void
    {
        $this->get('/login')->assertOk()->assertSee('assets/saan/saan-logo-light.png',false)->assertSee('assets/saan/favicon.svg',false)->assertSee('สาน (Saan)')->assertDontSee('SCHOOL<br>ATELIER',false);
        $this->actingAs(User::where('role','admin')->first());
        foreach (['/cms','/operations','/account'] as $path) {
            $this->get($path)->assertOk()->assertSee('assets/saan/favicon.svg',false)->assertSee('saan-brand',false)->assertDontSee('School Atelier');
        }
    }

    public function test_public_website_keeps_school_identity_not_platform_brand(): void
    {
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringNotContainsString('assets/saan/favicon.svg',$html);
        $this->assertStringNotContainsString('saan-logo-light.png',$html);
    }
}
