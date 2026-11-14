<?php
namespace Tests\Feature;
use App\Domain\CmsPublishing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EasyCmsTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void {parent::setUp();config(['school.demo'=>true]);$this->seed();Storage::fake('local');}
    private function asRole(string $role='admin'):static{$this->actingAs(User::where('role',$role)->first());return $this;}
    private function settings():array{return app(CmsPublishing::class)->defaults();}
    private function image():int{
        $raw=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jYuoAAAAASUVORK5CYII=');
        $r=$this->postJson('/api/cms/media',['image'=>UploadedFile::fake()->createWithContent('test.png',$raw),'alt'=>'Test picture'])->assertCreated();return $r->json('id');
    }
    private function write(array $extra=[]):array{return ['title'=>'ข่าวตัวอย่างทดสอบ','body'=>'เนื้อหาตัวอย่างที่โรงเรียนเขียนได้เอง','category'=>'activity','intent'=>'draft']+$extra;}
    public function test_admin_lands_on_cms_and_teacher_cannot_edit_site():void{
        $this->asRole()->get('/')->assertRedirect('/cms');$this->get('/cms')->assertOk()->assertSee('เขียนข่าวใหม่');$this->asRole('teacher')->get('/cms')->assertForbidden();$this->getJson('/api/cms/state')->assertForbidden();
    }
    public function test_theme_draft_does_not_change_published_site():void{
        $s=$this->settings();$s['school_name']='ชื่อที่ยังเป็นร่าง';$this->asRole()->putJson('/api/cms/theme',['version'=>0,'intent'=>'draft','settings'=>$s])->assertOk()->assertJsonPath('version',1);
        $this->get('/portal')->assertDontSee('ชื่อที่ยังเป็นร่าง');$this->get('/theme-preview')->assertSee('ชื่อที่ยังเป็นร่าง');
        $this->putJson('/api/cms/theme',['version'=>1,'intent'=>'publish','settings'=>$s,'fields'=>['school_name']])->assertOk()->assertJsonPath('published_version',2);$this->get('/portal')->assertSee('ชื่อที่ยังเป็นร่าง');$this->assertDatabaseHas('audit_events',['action'=>'theme.published']);
    }
    public function test_stale_theme_update_refused():void{
        $s=$this->settings();$this->asRole()->putJson('/api/cms/theme',['version'=>0,'intent'=>'publish','fields'=>\App\Http\Controllers\CmsController::THEME_FIELDS,'settings'=>$s])->assertOk();$s['school_name']='Lost update';$this->putJson('/api/cms/theme',['version'=>0,'intent'=>'publish','fields'=>\App\Http\Controllers\CmsController::THEME_FIELDS,'settings'=>$s])->assertConflict();$this->get('/portal')->assertDontSee('Lost update');
    }
    public function test_invalid_theme_color_and_script_link_refused():void{
        $s=$this->settings();$s['accent']='red;background:url(x)';$this->asRole()->putJson('/api/cms/theme',['version'=>0,'intent'=>'publish','fields'=>\App\Http\Controllers\CmsController::THEME_FIELDS,'settings'=>$s])->assertUnprocessable();$s=$this->settings();$s['menus'][0]['url']='javascript:harmless_marker';$this->putJson('/api/cms/theme',['version'=>0,'intent'=>'publish','fields'=>\App\Http\Controllers\CmsController::THEME_FIELDS,'settings'=>$s])->assertUnprocessable();$this->assertDatabaseCount('cms_themes',0);
    }
    public function test_theme_escapes_text_and_preserves_safe_menu_links():void{
        $s=$this->settings();$s['school_name']='<script>school_marker</script>';$this->asRole()->putJson('/api/cms/theme',['version'=>0,'intent'=>'publish','fields'=>\App\Http\Controllers\CmsController::THEME_FIELDS,'settings'=>$s])->assertOk();$this->get('/portal')->assertSee('&lt;script&gt;school_marker&lt;/script&gt;',false)->assertDontSee('<script>school_marker</script>',false);
    }
    public function test_post_can_publish_from_a_single_request_and_has_a_readable_page():void{
        $p=$this->write();$p['intent']='publish';$r=$this->asRole()->postJson('/api/cms/posts',$p)->assertCreated()->assertJsonPath('status','published');$this->get('/portal/news/'.$r->json('slug'))->assertOk()->assertSee($p['title'])->assertSee($p['body']);$this->assertDatabaseCount('cms_post_revisions',1);
    }
    public function test_editing_published_news_keeps_live_copy_until_publish():void{
        $p=$this->write();$p['intent']='publish';$r=$this->asRole()->postJson('/api/cms/posts',$p)->assertCreated();$id=$r->json('id');$slug=$r->json('slug');$p['title']='ร่างแก้ไขที่ยังไม่เผยแพร่';$p['intent']='draft';$p['version']=$r->json('version');
        $r=$this->putJson('/api/cms/posts/'.$id,$p)->assertOk()->assertJsonPath('has_draft',true);$this->get('/portal')->assertDontSee($p['title']);$this->get('/portal/news/'.$slug)->assertDontSee($p['title']);
        $this->asRole('teacher')->getJson('/api/state')->assertJsonMissing(['title'=>$p['title']])->assertJsonMissingPath('posts.0.draft_payload');
        $this->asRole();$p['intent']='publish';$p['version']=$r->json('version');$this->putJson('/api/cms/posts/'.$id,$p)->assertOk()->assertJsonPath('has_draft',false);$this->get('/portal/news/'.$slug)->assertSee($p['title']);$this->assertDatabaseCount('cms_post_revisions',2);
    }
    public function test_stale_post_update_and_teacher_submission_refused():void{
        $r=$this->asRole()->postJson('/api/cms/posts',$this->write())->assertCreated();$p=$this->write();$p['version']=99;$this->putJson('/api/cms/posts/'.$r->json('id'),$p)->assertConflict();$this->asRole('teacher')->postJson('/api/cms/posts',$this->write())->assertForbidden();
    }
    public function test_draft_media_is_private_until_explicit_publication():void{
        $this->asRole();$id=$this->image();$path=DB::table('cms_media')->find($id)->path;Storage::disk('local')->assertExists($path);$this->get('/media/'.$id)->assertOk();$this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertNotFound();
        $this->asRole();$p=$this->write(['cover_media_id'=>$id]);$p['intent']='publish';$this->postJson('/api/cms/posts',$p)->assertCreated();$this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
    }
    public function test_invalid_image_and_teacher_upload_refused():void{
        $this->asRole()->postJson('/api/cms/media',['image'=>UploadedFile::fake()->createWithContent('fake.jpg','harmless_marker')])->assertUnprocessable();$this->asRole('teacher')->postJson('/api/cms/media',['image'=>UploadedFile::fake()->createWithContent('fake.jpg','harmless_marker')])->assertForbidden();$this->assertDatabaseCount('cms_media',0);
    }
    public function test_cross_school_media_and_post_refused():void{
        $this->asRole();$id=$this->image();DB::table('cms_media')->where('id',$id)->update(['school_id'=>2]);$p=$this->write(['cover_media_id'=>$id]);$this->postJson('/api/cms/posts',$p)->assertNotFound();$s=$this->settings();$s['hero_media_id']=$id;$this->putJson('/api/cms/theme',['version'=>0,'intent'=>'publish','fields'=>\App\Http\Controllers\CmsController::THEME_FIELDS,'settings'=>$s])->assertNotFound();
        $post=DB::table('posts')->first();DB::table('posts')->where('id',$post->id)->update(['school_id'=>2]);$this->getJson('/api/cms/posts/'.$post->id)->assertNotFound();
    }
    public function test_category_filter_and_pin_are_persisted():void{
        $p=$this->write(['is_pinned'=>true]);$p['intent']='publish';$this->asRole()->postJson('/api/cms/posts',$p)->assertCreated();$this->get('/portal?category=activity')->assertSee($p['title']);$this->get('/portal?category=admission')->assertDontSee($p['title']);$this->get('/portal?category[]=activity')->assertNotFound();$this->assertDatabaseHas('posts',['title'=>$p['title'],'is_pinned'=>1]);
    }
    public function test_button_text_color_adapts_to_light_and_dark_brand_colors():void{$this->assertSame('#ffffff',CmsPublishing::buttonInk('#245747'));$this->assertSame('#000000',CmsPublishing::buttonInk('#ffffff'));}
}
