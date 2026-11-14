<?php
namespace Tests\Feature;

use App\Domain\Cms\ContentTypes;
use App\Domain\Cms\Observance;
use App\Domain\Cms\SiteSettings;
use App\Domain\Cms\ThemeRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CmsReleaseTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void {parent::setUp();config(['school.demo'=>true]);$this->seed();Storage::fake('local');$this->actingAs(User::where('role','admin')->first());}
    private function add(array $extra=[]): array {return $this->postJson('/api/site/content',array_replace(['title'=>'เรื่องราวตัวอย่าง','body'=>'ข้อความสมมติ','excerpt'=>'คำอธิบาย','content_type'=>'banner','category'=>'news','is_pinned'=>false,'metadata'=>['placement'=>'hero'],'intent'=>'publish'],$extra))->assertCreated()->json();}
    private function settings(array $extra=[],int $version=0,string $intent='publish',string $section='all'){return $this->putJson('/api/site/settings',['version'=>$version,'intent'=>$intent,'section'=>$section,'settings'=>array_replace(SiteSettings::defaults(),$extra)]);}
    private function picture(): int {return $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->image('release.png',300,200)])->assertCreated()->json('id');}
    public function test_root_is_a_public_school_website_for_guests(): void {$this->app['auth']->forgetGuards();$this->get('/')->assertOk()->assertSee('data-campus-carousel',false);}
    public function test_observance_is_off_by_default_and_drafts_do_not_publish(): void
    {
        $this->get('/portal')->assertDontSee('data-observance=',false);$o=array_replace(Observance::defaults(),['mode'=>'celebration','title'=>'วันแห่งความยินดี','body'=>'ข้อความตัวอย่าง']);
        $this->settings(['observance'=>$o],0,'draft')->assertOk();$this->get('/portal')->assertDontSee('วันแห่งความยินดี');$this->get('/theme-preview')->assertSee('วันแห่งความยินดี')->assertSee('data-close-observance',false);
        $this->settings(['observance'=>$o],1)->assertOk();$this->get('/portal')->assertSee('วันแห่งความยินดี')->assertSee('data-frequency="session"',false);
    }
    public function test_observance_windows_use_bangkok_and_expire(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-05 12:00:00','Asia/Bangkok'));$o=array_replace(Observance::defaults(),['mode'=>'mourning','starts_at'=>'2026-11-05T12:01','ends_at'=>'2026-11-05T12:03','grayscale'=>true]);$this->settings(['observance'=>$o])->assertOk();$this->get('/portal')->assertDontSee('data-observance=',false);$this->travel(2)->minutes();$this->get('/portal')->assertSee('data-observance-mode="mourning"',false)->assertSee('data-grayscale="true"',false);$this->travel(2)->minutes();$this->get('/portal')->assertDontSee('data-observance=',false);$this->travelBack();
    }
    public function test_settings_reject_invalid_mode_frequency_and_date_order(): void
    {
        $this->settings(['color_mode'=>'broken'])->assertUnprocessable();$this->settings(['observance'=>array_replace(Observance::defaults(),['frequency'=>'forever'])])->assertUnprocessable();$this->settings(['observance'=>array_replace(Observance::defaults(),['starts_at'=>'2026-11-06T12:00','ends_at'=>'2026-11-05T12:00'])])->assertUnprocessable();
    }
    public function test_favicon_media_is_public_only_after_publication_and_cannot_be_deleted(): void
    {
        $id=$this->picture();$this->settings(['favicon_media_id'=>$id,'color_mode'=>'dark'],0,'draft')->assertOk();$this->get('/portal')->assertDontSee('rel="icon" href="http://localhost/media/'.$id,false);$this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertNotFound();$this->actingAs(User::where('role','admin')->first());$this->settings(['favicon_media_id'=>$id,'color_mode'=>'dark'],1)->assertOk();$this->get('/portal')->assertSee('rel="icon" href="http://localhost/media/'.$id,false)->assertSee('name="school-color-mode" content="dark"',false);$this->deleteJson('/api/site/media/'.$id,['version'=>1])->assertUnprocessable();$this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertOk();
    }
    public function test_hero_order_is_versioned_and_draft_content_is_not_exposed(): void
    {
        $a=$this->add(['title'=>'สไลด์แรก']);$b=$this->add(['title'=>'สไลด์ที่สอง']);$this->putJson('/api/site/content/'.$a['id'],['version'=>$a['version'],'title'=>'ความลับในร่าง','body'=>'ข้อความ','excerpt'=>'สรุป','content_type'=>'banner','category'=>'news','is_pinned'=>false,'metadata'=>['placement'=>'hero'],'intent'=>'draft'])->assertOk();$fresh=$this->getJson('/api/site/slides')->assertOk()->json('data');$items=collect($fresh)->reverse()->map(fn($s)=>['id'=>(string)$s['id'],'version'=>$s['version']])->values()->all();$this->putJson('/api/site/slides/order',['items'=>$items])->assertOk();$this->get('/portal')->assertDontSee('ความลับในร่าง')->assertSee('สไลด์แรก')->assertSee('data-campus-next',false);$this->get('/hero-preview/'.$a['id'])->assertSee('ความลับในร่าง');$this->putJson('/api/site/slides/order',['items'=>$items])->assertConflict();$this->putJson('/api/site/slides/order',['items'=>array_slice($items,0,1)])->assertConflict();
    }
    public function test_private_mobile_hero_image_is_referenced_and_scoped(): void
    {
        $id=$this->picture();$p=$this->add(['metadata'=>['placement'=>'hero','mobile_media_id'=>$id]]);$this->get('/portal')->assertSee('srcset="http://localhost/media/'.$id.'"',false);$this->deleteJson('/api/site/media/'.$id,['version'=>1])->assertUnprocessable();$this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertOk();
    }
    public function test_three_menu_positions_are_rendered_with_nested_links(): void
    {
        $this->settings(['navigation'=>[['label'=>'หลักทดสอบ','url'=>'/portal','children'=>[['label'=>'ชั้นสอง','url'=>'/portal/news','children'=>[['label'=>'ชั้นสาม','url'=>'/portal/events']]]]]],'footer_navigation'=>[['label'=>'ท้ายทดสอบ','url'=>'/portal/contact']],'utility_navigation'=>[['label'=>'บนทดสอบ','url'=>'/portal/downloads']]])->assertOk();$this->get('/portal')->assertSee('หลักทดสอบ')->assertSee('ชั้นสาม')->assertSee('ท้ายทดสอบ')->assertSee('บนทดสอบ');$this->getJson('/api/site/links')->assertOk()->assertJsonPath('data.0.status','ok');
    }
    public function test_theme_registry_accepts_new_local_packages_and_rejects_traversal(): void
    {
        $themes=config('school-themes');$themes['new-campus']=array_replace($themes['atelier'],['name'=>'New Campus']);config(['school-themes'=>$themes]);$this->assertArrayHasKey('new-campus',ThemeRegistry::all());$this->getJson('/api/cms/state')->assertOk()->assertJsonPath('theme.presets.new-campus.name','New Campus');$themes['new-campus']['stylesheet']='assets/themes/../secret.css';config(['school-themes'=>$themes]);$this->expectException(\RuntimeException::class);ThemeRegistry::all();
    }
    public function test_image_variant_preserves_original_and_requires_own_school(): void
    {
        $id=$this->picture();$source=DB::table('cms_media')->find($id);$before=hash('sha256',Storage::disk('local')->get($source->path));$options=['width'=>120,'ratio'=>'3:1','format'=>'webp','quality'=>82,'focal_x'=>50,'focal_y'=>50];$out=$this->postJson('/api/site/media/'.$id.'/variant',$options)->assertCreated()->assertJsonPath('source_media_id',$id)->assertJsonPath('width',120)->assertJsonPath('height',40)->json();$this->assertSame($before,hash('sha256',Storage::disk('local')->get($source->path)));$this->deleteJson('/api/site/media/'.$id,['version'=>1])->assertUnprocessable();DB::table('cms_media')->where('id',$id)->update(['school_id'=>2]);$this->postJson('/api/site/media/'.$id.'/variant',$options)->assertNotFound();
    }
    public function test_mismatched_and_executable_uploads_are_rejected_but_text_is_downloadable(): void
    {
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('fake.jpg','harmless text marker')])->assertUnprocessable();$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('file.php','harmless text marker')])->assertUnprocessable();$id=$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('readme.txt','harmless text marker')])->assertCreated()->json('id');$this->add(['content_type'=>'download','category'=>'general','metadata'=>['attachments'=>[$id]]]);$this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertOk()->assertHeader('X-Content-Type-Options','nosniff')->assertHeader('Content-Security-Policy',"sandbox; default-src 'none'");
    }
    public function test_redirects_normalize_query_reject_cycles_and_reserve_system_paths(): void
    {
        $make=fn($from,$to)=>$this->postJson('/api/site/redirects',['source_path'=>$from,'target_path'=>$to,'status_code'=>301,'is_active'=>true]);$a=$make('/old-page/','/portal/news')->assertCreated()->json();$this->get('/old-page/')->assertRedirect('/portal/news');$make('/?p=123','/portal/pages')->assertCreated();$this->get('/?p=123')->assertRedirect('/portal/pages');$make('/circle-a','/circle-b')->assertCreated();$make('/circle-b','/circle-a')->assertUnprocessable();$make('/login','/portal')->assertUnprocessable();$make('//evil.example','/portal')->assertUnprocessable();$this->putJson('/api/site/redirects/'.$a['id'],['version'=>9,'source_path'=>'/old-page','target_path'=>'/portal','status_code'=>302,'is_active'=>false])->assertConflict();
    }
    public function test_video_host_validation_tags_and_newsletter_public_views(): void
    {
        $this->postJson('/api/site/content',['title'=>'ไม่ผ่าน','body'=>'ข้อความ','content_type'=>'video','category'=>'activity','is_pinned'=>false,'metadata'=>['video_url'=>'https://youtube.com.attacker.example/watch?v=abcdefghijk'],'intent'=>'publish'])->assertUnprocessable();$v=$this->add(['title'=>'วิดีโอทดสอบ','content_type'=>'video','category'=>'activity','metadata'=>['video_url'=>'https://www.youtube.com/watch?v=abcdefghijk','tags'=>['วิทยาศาสตร์']]]);$this->get('/portal/videos/'.$v['slug'])->assertSee('youtube-nocookie.com/embed/abcdefghijk')->assertSee('data-play-video',false)->assertDontSee('<iframe',false);$this->get('/portal/videos?tag=วิทยาศาสตร์')->assertSee('วิดีโอทดสอบ');$this->get('/portal/videos?tag=อื่น')->assertDontSee('วิดีโอทดสอบ');$n=$this->add(['title'=>'ฉบับตัวอย่าง','content_type'=>'newsletter','metadata'=>['issue_no'=>'11/2569','issued_on'=>'2026-11-05']]);$this->get('/portal/newsletters/'.$n['slug'])->assertSee('11/2569');
    }
    public function test_new_tools_are_denied_to_teacher_and_guests(): void
    {
        $this->actingAs(User::where('role','teacher')->first());foreach(['/api/site/slides','/api/site/redirects','/api/site/links']as$url)$this->getJson($url)->assertForbidden();$this->app['auth']->forgetGuards();$this->getJson('/api/site/slides')->assertUnauthorized();
    }
    public function test_revision_read_is_scoped_and_contains_a_published_snapshot(): void
    {
        $p=$this->add();$revision=DB::table('cms_post_revisions')->where('post_id',$p['id'])->first();$this->getJson('/api/site/content/'.$p['id'].'/revisions/'.$revision->id)->assertOk()->assertJsonPath('snapshot.title','เรื่องราวตัวอย่าง');$this->getJson('/api/site/content/'.$p['id'].'/revisions/999999')->assertNotFound();
    }
}
