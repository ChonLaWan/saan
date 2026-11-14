<?php
namespace Tests\Feature;

use App\Domain\Cms\{PresentationSchema,SiteSettings};
use App\Domain\CmsPublishing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Tests\TestCase;

class PresentationTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void {parent::setUp();config(['school.demo'=>true]);$this->seed();Storage::fake('local');$this->actingAs(User::where('role','admin')->first());}
    private function state():array {return $this->getJson('/api/site/presentation')->assertOk()->json();}
    private function payload(string$section,array$values=[],array$theme=[],string$intent='publish'):array {$s=$this->state();return['section'=>$section,'settings_version'=>$s['settings']['version'],'theme_version'=>$s['theme']['version'],'values'=>$values,'theme'=>$theme,'shared'=>[],'intent'=>$intent];}
    private function image():int {return $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->image('school-logo.png',100,100)])->assertCreated()->json('id');}
    public function test_high_contrast_previews_render_without_scripts_and_do_not_publish_settings():void
    {
        $before=$this->state();foreach(['light-high'=>'light','dark-high'=>'dark']as$mode=>$appearance){$p=$this->payload('header');$p['preview_mode']=$mode;$html=$this->postJson('/api/site/presentation/preview',$p)->assertOk()->json('html');$this->assertStringContainsString('data-appearance="'.$appearance.'"',$html);$this->assertStringContainsString('data-contrast="high"',$html);$this->assertStringContainsString('accessibility.css',$html);$this->assertStringNotContainsString('<script',$html);}
        $this->assertSame($before['settings'],$this->state()['settings']);
    }
    public function test_site_default_high_contrast_is_published_through_existing_identity_settings():void
    {
        foreach(['light-high','dark-high']as$mode){$state=$this->state();$settings=$state['settings']['draft'];$settings['color_mode']=$mode;$this->putJson('/api/site/settings',['settings'=>$settings,'version'=>$state['settings']['version'],'intent'=>'publish','section'=>'all'])->assertOk();$this->get('/portal')->assertOk()->assertSee('data-contrast="high"',false)->assertSee('value="'.$mode.'" selected',false);}
    }
    public function test_native_marks_and_typed_presentation_defaults_render_on_public_pages():void
    {
        $this->get('/portal')->assertOk()->assertSee('school-brand-mark',false)->assertSee('assets/brand/book.svg',false)->assertSee('presentation.css?v=',false)->assertDontSee("@include('website.brand-mark')",false)->assertSee('data-loop="true"',false);
        $this->get('/portal/people')->assertOk()->assertSee('school-brand-mark',false);
    }
    public function test_publishing_branding_preserves_other_unpublished_settings_and_theme_fields():void
    {
        $site=app(SiteSettings::class);$initial=$site->read(1);$draft=$initial['draft'];$draft['observance']['mode']='celebration';$draft['header']['cta_label']='ปุ่มที่ยังไม่พร้อม';$site->save($draft,$initial['version'],false);
        $themes=app(CmsPublishing::class);$t=$themes->theme(1);$themes->themeSave(array_replace($t['draft'],['preset'=>'sky','accent'=>'#245a82']),$t['version'],false);
        $p=$this->payload('branding',['mark'=>'circle','width'=>72],['school_name'=>'โรงเรียนสมมติที่แก้ชื่อ']);$result=$this->putJson('/api/site/presentation',$p)->assertOk()->json();
        $this->assertSame('circle',$result['settings']['published']['branding']['mark']);$this->assertSame('off',$result['settings']['published']['observance']['mode']);$this->assertSame('celebration',$result['settings']['draft']['observance']['mode']);$this->assertSame('',$result['settings']['published']['header']['cta_label']);$this->assertSame('ปุ่มที่ยังไม่พร้อม',$result['settings']['draft']['header']['cta_label']);$this->assertSame('atelier',$result['theme']['published']['preset']);$this->assertSame('sky',$result['theme']['draft']['preset']);$this->assertSame('โรงเรียนสมมติที่แก้ชื่อ',$result['theme']['published']['school_name']);
    }
    public function test_draft_is_private_and_stale_site_or_theme_versions_cannot_overwrite():void
    {
        $p=$this->payload('header',['cta_label'=>'ร่างส่วนหัว']);$old=$p;$p['intent']='draft';$r=$this->putJson('/api/site/presentation',$p)->assertOk()->json();$this->assertSame('',$r['settings']['published']['header']['cta_label']);$this->assertSame('ร่างส่วนหัว',$r['settings']['draft']['header']['cta_label']);$events=DB::table('audit_events')->count();$this->putJson('/api/site/presentation',$old)->assertConflict();$this->assertSame($events,DB::table('audit_events')->count());
        $p=$this->payload('header',['shadow'=>true]);$t=app(CmsPublishing::class);$s=$t->theme(1);$t->themeSave($s['draft'],$s['version'],false);$this->putJson('/api/site/presentation',$p)->assertConflict();
    }
    public function test_all_schema_fields_round_trip_and_hero_playback_changes_are_scoped():void
    {
        foreach(['branding','header','hero']as$section){$state=$this->state();$definition=PresentationSchema::section($section);$p=$this->payload($section);foreach($definition['fields']as$f)$p[$f['scope']][$f['key']]=match($f['scope']){'values'=>$state['settings']['draft'][$definition['storage']][$f['key']],'theme'=>$state['theme']['draft'][$f['key']],'shared'=>$state['settings']['draft'][$f['key']]};$this->putJson('/api/site/presentation',$p)->assertOk();}
        $p=$this->payload('hero',['height'=>510,'mobile_height'=>620,'loop'=>false,'arrows'=>false,'title_color'=>'#ffffff']);$p['shared']=['hero_autoplay'=>false,'hero_interval'=>10000,'hero_search'=>false];$r=$this->putJson('/api/site/presentation',$p)->assertOk()->json();$this->assertSame(510,$r['settings']['published']['hero_design']['height']);$this->assertFalse($r['settings']['published']['hero_autoplay']);$this->get('/portal')->assertOk()->assertSee('--hero-height:510px',false)->assertSee('data-loop="false"',false)->assertSee('data-autoplay="false"',false)->assertDontSee('class="campus-hero-search"',false);
    }
    public function test_ranges_css_injection_unknown_keys_and_cross_section_theme_fields_are_rejected():void
    {
        foreach([['header',['padding_x'=>-10],[]],['hero',['height'=>9000],[]],['branding',['mark_color'=>'red;background:url(https://example.invalid)'],[]],['branding',['raw_css'=>'body{}'],[]],['header',['layout'=>'anything'],[]],['branding',[],['accent'=>'#ffffff']],['branding',[],['school_name'=>null]]]as[$section,$values,$theme])$this->putJson('/api/site/presentation',$this->payload($section,$values,$theme))->assertUnprocessable();
    }
    public function test_playback_only_publish_does_not_publish_saved_hero_design_draft():void
    {
        $this->putJson('/api/site/presentation',$this->payload('hero',['title_size'=>58],[],'draft'))->assertOk();$p=$this->payload('hero');$p['shared']=['hero_interval'=>10000];$r=$this->putJson('/api/site/presentation',$p)->assertOk()->json();$this->assertSame(58,$r['settings']['draft']['hero_design']['title_size']);$this->assertSame(36,$r['settings']['published']['hero_design']['title_size']);$this->assertSame(10000,$r['settings']['published']['hero_interval']);
    }
    public function test_dark_mobile_logo_files_are_private_as_drafts_and_reference_protected():void
    {
        $id=$this->image();$p=$this->payload('branding',['dark_media_id'=>$id,'mobile_media_id'=>$id],[],'draft');$this->putJson('/api/site/presentation',$p)->assertOk();$version=DB::table('cms_media')->find($id)->version;$this->deleteJson('/api/site/media/'.$id,['version'=>$version])->assertUnprocessable();$this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertNotFound();$this->actingAs(User::where('role','admin')->first());$this->putJson('/api/site/presentation',$this->payload('branding',['dark_media_id'=>$id,'mobile_media_id'=>$id]))->assertOk();$this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertOk();
    }
    public function test_foreign_school_media_and_document_as_logo_are_rejected_before_updates():void
    {
        $id=$this->image();DB::table('cms_media')->where('id',$id)->update(['school_id'=>2]);$this->putJson('/api/site/presentation',$this->payload('branding',['dark_media_id'=>$id]))->assertNotFound();
        $id=$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('guide.pdf',"%PDF-1.4\nexample")])->assertCreated()->json('id');$this->putJson('/api/site/presentation',$this->payload('branding',[],['logo_media_id'=>$id]))->assertUnprocessable();
    }
    public function test_preview_is_read_only_escaped_script_free_and_uses_requested_mode():void
    {
        $p=$this->payload('branding',['mark'=>'shield'],['school_name'=>'โรงเรียน <script>harmless-marker</script>']);$p['preview_mode']='dark';$before=$this->state();$audit=DB::table('audit_events')->count();$html=$this->postJson('/api/site/presentation/preview',$p)->assertOk()->json('html');$this->assertStringContainsString('data-appearance="dark"',$html);$this->assertStringNotContainsString('<script',$html);$this->assertStringContainsString('&lt;script&gt;',$html);$this->assertSame($before['settings'],$this->state()['settings']);$this->assertSame($audit,DB::table('audit_events')->count());
    }
    public function test_presentation_endpoints_require_current_cms_account():void
    {
        $p=$this->payload('header',['shadow'=>true]);$this->actingAs(User::where('role','teacher')->first());$this->getJson('/api/site/presentation')->assertForbidden();$this->putJson('/api/site/presentation',$p)->assertForbidden();$this->postJson('/api/site/presentation/preview',$p)->assertForbidden();$this->app['auth']->forgetGuards();$this->getJson('/api/site/presentation')->assertUnauthorized();
    }
    public function test_general_settings_endpoint_accepts_new_groups_but_validates_them():void
    {
        $s=$this->state()['settings'];$settings=$s['draft'];$settings['branding']['width']=61;$this->putJson('/api/site/settings',['settings'=>$settings,'version'=>$s['version'],'intent'=>'draft'])->assertOk()->assertJsonPath('draft.branding.width',61);
        $s=$this->state()['settings'];$settings=$s['draft'];$settings['header']['background']='bad';$this->putJson('/api/site/settings',['settings'=>$settings,'version'=>$s['version'],'intent'=>'publish','section'=>'all'])->assertUnprocessable();
    }
    public function test_profile_publish_does_not_publish_an_unrelated_hero_design_draft():void
    {
        $this->putJson('/api/site/presentation',$this->payload('hero',['title_size'=>58],[],'draft'))->assertOk();$s=$this->state()['settings'];$settings=$s['draft'];$settings['mission']='พันธกิจที่พร้อมเผยแพร่';$r=$this->putJson('/api/site/settings',['settings'=>$settings,'version'=>$s['version'],'intent'=>'publish','section'=>'all'])->assertOk()->json();$this->assertSame(58,$r['draft']['hero_design']['title_size']);$this->assertSame(36,$r['published']['hero_design']['title_size']);$this->assertSame('พันธกิจที่พร้อมเผยแพร่',$r['published']['mission']);
    }
    public function test_per_slide_details_are_preserved_in_public_render_and_revisions():void
    {
        $meta=['placement'=>'hero','mobile_focal_x'=>18,'mobile_focal_y'=>75,'vertical_align'=>'bottom','text_align'=>'center','title_size'=>42,'mobile_title_size'=>28,'description_size'=>17,'text_color'=>'#ffffff','overlay_color'=>'#183e32','mobile_overlay'=>.8,'image_alt'=>'ภาพโรงเรียนสมมติ','button_style'=>'outline','button_new_tab'=>true,'hide_kicker'=>true,'hide_description'=>false,'url'=>'/portal/news','button_label'=>'ดูข่าว'];
        $p=['title'=>'เรื่องเล่าสมมติ','body'=>'เนื้อหา','excerpt'=>'คำอธิบาย','content_type'=>'banner','category'=>'news','is_pinned'=>false,'intent'=>'publish','metadata'=>$meta,'cover_media_id'=>$this->image()];$entry=$this->postJson('/api/site/content',$p)->assertCreated()->json();$this->assertSame($meta['mobile_focal_x'],$entry['published']['metadata']['mobile_focal_x']);$this->get('/portal')->assertOk()->assertSee('data-hero-align="center"',false)->assertSee('--hero-mobile-x:18%',false)->assertSee('data-hide-kicker="true"',false)->assertSee('alt="ภาพโรงเรียนสมมติ"',false)->assertSee('target="_blank" rel="noopener noreferrer"',false);
        $p['metadata']['overlay_color']='invalid';$this->postJson('/api/site/content',$p)->assertUnprocessable();
    }
}
