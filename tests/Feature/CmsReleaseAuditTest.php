<?php
namespace Tests\Feature;
use App\Domain\Cms\Observance;
use App\Domain\Cms\SiteSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CmsReleaseAuditTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();config(['school.demo'=>true]);$this->seed();$this->actingAs(User::where('role','admin')->first());}
    public function test_banner_window_is_in_bangkok_and_multiline_title_is_kept():void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-05 12:00','Asia/Bangkok'));$r=$this->postJson('/api/site/content',['title'=>"บรรทัดแรก\nบรรทัดที่สอง",'body'=>'ข้อความ','excerpt'=>'ช่วงเวลา','category'=>'news','content_type'=>'banner','is_pinned'=>false,'intent'=>'publish','metadata'=>['placement'=>'hero','starts_at'=>'2026-11-05T12:01','ends_at'=>'2026-11-05T12:03']])->assertCreated();$this->assertSame("บรรทัดแรก\nบรรทัดที่สอง",$r->json('draft.title'));$this->get('/portal')->assertDontSee('บรรทัดแรก');$this->travel(2)->minutes();$this->get('/portal')->assertSee("บรรทัดแรก\nบรรทัดที่สอง");$this->travel(2)->minutes();$this->get('/portal')->assertDontSee('บรรทัดแรก');$this->get('/hero-preview/'.$r->json('id'))->assertSee('บรรทัดแรก');$this->travelBack();
    }
    public function test_observance_body_round_trips_and_preview_is_marked():void
    {
        $settings=SiteSettings::defaults();$settings['observance']['mode']='celebration';$this->putJson('/api/site/settings',['settings'=>$settings,'version'=>0,'intent'=>'draft'])->assertOk()->assertJsonPath('draft.observance.body','');$this->get('/theme-preview')->assertSee('data-observance-preview="true"',false);$this->get('/portal')->assertDontSee('data-observance=',false);
    }
    public function test_wrong_school_cannot_reference_favicon_or_observance_or_mobile_photo():void
    {
        \Illuminate\Support\Facades\Storage::fake('local');$id=$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->image('own.png',64,64)])->assertCreated()->json('id');\Illuminate\Support\Facades\DB::table('cms_media')->where('id',$id)->update(['school_id'=>2]);$s=SiteSettings::defaults();$s['favicon_media_id']=$id;$this->putJson('/api/site/settings',['settings'=>$s,'version'=>0,'intent'=>'publish','section'=>'all'])->assertNotFound();$s['favicon_media_id']=null;$s['observance']['image_media_id']=$id;$this->putJson('/api/site/settings',['settings'=>$s,'version'=>0,'intent'=>'publish','section'=>'all'])->assertNotFound();$this->postJson('/api/site/content',['title'=>'ทดสอบ','body'=>'ข้อความ','category'=>'news','content_type'=>'banner','is_pinned'=>false,'intent'=>'publish','metadata'=>['placement'=>'hero','mobile_media_id'=>$id]])->assertNotFound();
    }
    public function test_guest_assets_have_versions_and_new_cms_navigation_is_present():void
    {
        $this->get('/portal')->assertSee('school-sections.css?v=',false)->assertSee('appearance.js?v=',false);$this->get('/cms')->assertSee('/cms#slides',false)->assertSee('/cms#observance',false)->assertSee('/cms#identity',false)->assertSee('/cms#videos',false)->assertSee('/cms#newsletters',false);
    }
    public function test_menu_fourth_level_and_script_urls_are_rejected():void
    {
        $s=SiteSettings::defaults();$s['footer_navigation']=[['label'=>'a','url'=>'/portal','children'=>[['label'=>'b','url'=>'/portal','children'=>[['label'=>'c','url'=>'/portal','children'=>[['label'=>'d','url'=>'/portal']]]]]]]];$this->putJson('/api/site/settings',['settings'=>$s,'version'=>0,'intent'=>'draft'])->assertUnprocessable();$s['footer_navigation']=[['label'=>'a','url'=>'javascript:alert(1)']];$this->putJson('/api/site/settings',['settings'=>$s,'version'=>0,'intent'=>'publish','section'=>'all'])->assertUnprocessable();
    }
}
