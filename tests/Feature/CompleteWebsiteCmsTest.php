<?php

namespace Tests\Feature;

use App\Domain\Cms\ContentTypes;
use App\Domain\Cms\SiteSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompleteWebsiteCmsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void {parent::setUp();config(['school.demo'=>true]);$this->seed();User::forceCreate(['name'=>'ผู้ดูแลเนื้อหาสมมติ','email'=>'editor@testing.example','password'=>'Test-only-editor-password','school_id'=>1,'role'=>'editor','is_active'=>true]);Storage::fake('local');$this->actingAs(User::where('role','admin')->first());}
    private function payload(string $type='article',array $extra=[]): array{return array_replace(['title'=>'เนื้อหาใหม่ '.$type,'body'=>'<h2>การเรียนรู้</h2><p>ข้อความจากโรงเรียน <strong>ตัวหนา</strong></p>','excerpt'=>'ข้อความสรุป','content_type'=>$type,'category'=>ContentTypes::TYPES[$type]['category'],'is_pinned'=>false,'metadata'=>['body_format'=>'html'],'intent'=>'publish'],$extra);}
    private function add(string $type='article',array $extra=[]): array{return $this->postJson('/api/site/content',$this->payload($type,$extra))->assertCreated()->json();}
    private function guest(): void {$this->app['auth']->forgetGuards();}
    private function pdf(): int{return $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('document.pdf',"%PDF-1.4\n% demonstration\n%%EOF"),'alt'=>'เอกสารตัวอย่าง'])->assertCreated()->json('id');}
    private function image(): int {$raw=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jYuoAAAAASUVORK5CYII=');return $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('picture.png',$raw)])->assertCreated()->json('id');}

    public function test_all_website_types_have_editable_entries_and_correct_public_routes(): void
    {
        foreach(ContentTypes::TYPES as$type=>$definition){$p=$this->add($type);$this->getJson('/api/site/content/'.$p['id'])->assertOk()->assertJsonPath('draft.content_type',$type);$this->get('/portal/'.$definition['route'].'/'.$p['slug'])->assertOk()->assertSee('ตัวหนา')->assertSee('<strong>ตัวหนา</strong>',false);$this->get('/portal/'.$definition['route'])->assertOk()->assertSee($p['draft']['title']);}
        $this->get('/portal/jobs/'.$p['slug'])->assertNotFound();
    }
    public function test_rich_text_removes_active_content_and_unsafe_links_on_save_and_read(): void
    {
        $p=$this->add('page',['body'=>'<p>ข้อความ<strong>ปลอดภัย</strong><script>marker_script</script><img src=x onerror="marker_event"><a href="javascript:marker_link">ลิงก์</a></p>']);
        $this->assertStringNotContainsString('marker_',$p['draft']['body']);$this->get('/portal/pages/'.$p['slug'])->assertSee('<strong>ปลอดภัย</strong>',false)->assertDontSee('marker_');
        DB::table('posts')->where('id',$p['id'])->update(['body'=>'<p>safe<script>read_marker</script></p>']);$this->getJson('/api/site/content/'.$p['id'])->assertDontSee('read_marker');
    }
    public function test_published_copy_is_isolated_and_revision_can_be_restored_as_draft(): void
    {
        $p=$this->add('page');$data=$this->payload('page',['title'=>'ร่างใหม่ยังไม่เผยแพร่','intent'=>'draft','version'=>$p['version']]);
        $r=$this->putJson('/api/site/content/'.$p['id'],$data)->assertOk()->assertJsonPath('has_draft',true)->json();
        $this->get('/portal/pages/'.$p['slug'])->assertDontSee('ร่างใหม่ยังไม่เผยแพร่');$this->get('/content-preview/'.$p['id'])->assertSee('ร่างใหม่ยังไม่เผยแพร่');
        $data['intent']='publish';$data['version']=$r['version'];$r=$this->putJson('/api/site/content/'.$p['id'],$data)->assertOk()->json();
        $revision=$p['revisions'][0]['id'];$r=$this->postJson('/api/site/content/'.$p['id'].'/action',['version'=>$r['version'],'action'=>'revision','revision_id'=>$revision])->assertOk()->assertJsonPath('draft.title',$p['draft']['title'])->json();
        $this->get('/portal/pages/'.$p['slug'])->assertSee('ร่างใหม่ยังไม่เผยแพร่');$this->assertTrue($r['has_draft']);
    }
    public function test_trash_removes_content_and_files_from_public_then_restore_stays_draft(): void
    {
        $image=$this->image();$p=$this->add('album',['cover_media_id'=>$image,'metadata'=>['body_format'=>'html','gallery'=>[$image]]]);
        $this->postJson('/api/site/content/'.$p['id'].'/action',['action'=>'trash','version'=>$p['version']])->assertOk();
        $this->guest();$this->get('/portal/albums/'.$p['slug'])->assertNotFound();$this->get('/media/'.$image)->assertNotFound();
        $this->actingAs(User::where('role','admin')->first());$this->getJson('/api/site/content?trash=1')->assertJsonPath('data.0.id',$p['id']);
        $this->postJson('/api/site/content/'.$p['id'].'/action',['action'=>'restore','version'=>2])->assertOk()->assertJsonPath('status','draft');$this->get('/portal/albums/'.$p['slug'])->assertNotFound();
    }
    public function test_pdf_attachment_is_private_until_publish_and_used_file_cannot_be_removed(): void
    {
        $id=$this->pdf();$p=$this->add('download',['intent'=>'draft','metadata'=>['body_format'=>'html','attachments'=>[$id]]]);
        $this->deleteJson('/api/site/media/'.$id,['version'=>1])->assertUnprocessable();$this->guest();$this->get('/media/'.$id)->assertNotFound();
        $this->actingAs(User::where('role','editor')->first());$data=$this->payload('download',['version'=>1,'metadata'=>['body_format'=>'html','attachments'=>[$id]]]);$this->putJson('/api/site/content/'.$p['id'],$data)->assertOk();
        $this->guest();$this->get('/media/'.$id)->assertOk()->assertHeader('Content-Type','application/pdf')->assertHeader('X-Content-Type-Options','nosniff');$this->get('/portal/downloads/'.$p['slug'])->assertSee('เอกสารตัวอย่าง');
    }
    public function test_references_require_attribution_and_expired_jobs_are_labelled(): void
    {
        $meta=['body_format'=>'html','source_kind'=>'reference','source_school'=>'โรงเรียนต้นทาง','source_url'=>'https://example.edu/notice','source_date'=>'2026-10-01','checked_on'=>'2026-11-05','deadline'=>'2026-10-25'];
        $p=$this->add('job',['metadata'=>$meta]);$this->get('/portal/jobs/'.$p['slug'])->assertSee('ตัวอย่างอ้างอิงจากโรงเรียนจริง')->assertSee('โรงเรียนต้นทาง')->assertSee('พ้นกำหนด')->assertSee('https://example.edu/notice');
        unset($meta['source_url']);$this->postJson('/api/site/content',$this->payload('job',['metadata'=>$meta]))->assertUnprocessable();
    }
    public function test_the_reference_filter_and_its_explanation_appear_only_when_a_published_reference_notice_exists(): void
    {
        $this->add('job');
        $this->get('/portal/jobs')->assertOk()->assertDontSee('อ้างอิงโรงเรียนจริง')->assertDontSee('reference-explainer',false);
        $this->get('/portal/procurement')->assertOk()->assertDontSee('อ้างอิงโรงเรียนจริง');
        $this->add('job',['metadata'=>['body_format'=>'html','source_kind'=>'reference','source_school'=>'โรงเรียนต้นทาง','source_url'=>'https://example.edu/notice','source_date'=>'2026-10-01','checked_on'=>'2026-11-05','deadline'=>'2026-10-25']]);
        $this->get('/portal/jobs')->assertOk()->assertSee('<option value="reference"',false)->assertSee('reference-explainer',false);
        $this->get('/portal/procurement')->assertOk()->assertDontSee('<option value="reference"',false);
    }
    public function test_scheduled_updates_do_not_publish_early_and_command_is_idempotent(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-05T04:00:00Z'));$p=$this->add('page');
        $data=$this->payload('page',['title'=>'เนื้อหาตามกำหนด','intent'=>'schedule','version'=>1,'scheduled_at'=>'2026-11-05T12:00']);
        $this->putJson('/api/site/content/'.$p['id'],$data)->assertOk();$this->assertDatabaseHas('posts',['id'=>$p['id'],'scheduled_at'=>'2026-11-05 05:00:00']);$this->getJson('/api/site/content?status=scheduled')->assertJsonPath('data.0.id',$p['id']);
        $this->artisan('cms:publish-due')->assertExitCode(0);$this->get('/portal/pages/'.$p['slug'])->assertDontSee('เนื้อหาตามกำหนด');
        $this->travel(61)->minutes();$this->artisan('cms:publish-due')->assertExitCode(0);$this->get('/portal/pages/'.$p['slug'])->assertSee('เนื้อหาตามกำหนด');$count=DB::table('cms_post_revisions')->count();$this->artisan('cms:publish-due')->assertExitCode(0);$this->assertSame($count,DB::table('cms_post_revisions')->count());$this->travelBack();
    }
    public function test_scheduler_cancels_publication_when_author_is_disabled(): void
    {
        $p=$this->add('page',['intent'=>'schedule','scheduled_at'=>now('Asia/Bangkok')->addHour()->format('Y-m-d\TH:i')]);DB::table('users')->where('id',auth()->id())->update(['is_active'=>false]);DB::table('posts')->where('id',$p['id'])->update(['scheduled_at'=>now('UTC')->subMinute()]);$this->artisan('cms:publish-due')->assertExitCode(0);$this->assertDatabaseHas('posts',['id'=>$p['id'],'status'=>'draft','scheduled_at'=>null]);
    }
    public function test_site_settings_have_stale_write_guard_and_publication_boundary(): void
    {
        $s=SiteSettings::defaults();$s['badge']='ป้ายร่าง';$s['navigation']=[['label'=>'รู้จักเรา','url'=>'/portal/pages','children'=>[['label'=>'ข่าว','url'=>'/portal/news','children'=>[['label'=>'เอกสาร','url'=>'/portal/downloads']]]]]];
        $this->putJson('/api/site/settings',['version'=>0,'intent'=>'draft','settings'=>$s])->assertOk();$this->get('/portal')->assertDontSee('ป้ายร่าง');$this->get('/theme-preview')->assertSee('ป้ายร่าง');
        $this->putJson('/api/site/settings',['version'=>0,'intent'=>'publish','section'=>'all','settings'=>$s])->assertConflict();$this->putJson('/api/site/settings',['version'=>1,'intent'=>'publish','section'=>'all','settings'=>$s])->assertOk();$this->get('/portal')->assertSee('ป้ายร่าง')->assertSee('รู้จักเรา');
        $s['navigation'][0]['url']='javascript:marker';$this->putJson('/api/site/settings',['version'=>2,'intent'=>'publish','section'=>'all','settings'=>$s])->assertUnprocessable();
    }
    public function test_homepage_can_include_page_blocks_but_never_draft_content_or_other_school(): void
    {
        $p=$this->add('page',['title'=>'บล็อกหน้าแรก']);$s=SiteSettings::defaults();$s['homepage_sections']=['page:'.$p['id'],'news'];$this->putJson('/api/site/settings',['version'=>0,'intent'=>'publish','section'=>'all','settings'=>$s])->assertOk();$this->get('/portal')->assertSee('บล็อกหน้าแรก');
        $this->postJson('/api/site/content/'.$p['id'].'/action',['version'=>1,'action'=>'unpublish'])->assertOk();$this->get('/portal')->assertDontSee('บล็อกหน้าแรก');
        DB::table('posts')->where('id',$p['id'])->update(['school_id'=>99]);$this->putJson('/api/site/settings',['version'=>1,'intent'=>'publish','section'=>'all','settings'=>$s])->assertUnprocessable();
    }
    public function test_category_in_use_cannot_be_renamed_or_deleted_and_metadata_stays_scoped(): void
    {
        $c=$this->postJson('/api/site/categories',['label'=>'หมวดทดลอง','slug'=>'test-topic'])->assertCreated()->json();$p=$this->add('page',['category'=>'test-topic']);
        $this->deleteJson('/api/site/categories/'.$c['id'],['version'=>1])->assertUnprocessable();$this->putJson('/api/site/categories/'.$c['id'],['label'=>'ใหม่','slug'=>'changed','version'=>1])->assertUnprocessable();
        $id=$this->pdf();DB::table('cms_media')->where('id',$id)->update(['school_id'=>99]);$this->postJson('/api/site/content',$this->payload('download',['metadata'=>['attachments'=>[$id]]]))->assertNotFound();
    }
    public function test_contact_message_enters_private_inbox_and_status_updates_are_versioned(): void
    {
        $this->guest();$p=['name'=>'ผู้ปกครองสมมติ','email'=>'family@example.test','subject'=>'ข้อความทดลอง','message'=>'ทดสอบกล่องข้อความติดต่อโรงเรียน','consent'=>'1'];$id=$this->postJson('/portal/contact',$p)->assertCreated()->json('id');$this->getJson('/api/site/messages')->assertUnauthorized();
        $this->actingAs(User::where('role','editor')->first());$this->getJson('/api/site/messages')->assertOk()->assertJsonPath('data.0.id',$id);$this->putJson('/api/site/messages/'.$id,['version'=>1,'status'=>'read'])->assertOk();$this->putJson('/api/site/messages/'.$id,['version'=>1,'status'=>'archived'])->assertConflict();
        unset($p['consent']);$this->postJson('/portal/contact',$p)->assertUnprocessable();$this->assertDatabaseCount('cms_messages',1);
    }
    public function test_teacher_cannot_use_any_new_website_management_endpoint(): void
    {
        $this->actingAs(User::where('role','teacher')->first());foreach(['/api/site/state','/api/site/content','/api/site/media','/api/site/messages','/api/site/audit']as$url)$this->getJson($url)->assertForbidden();$this->postJson('/api/site/content',$this->payload())->assertForbidden();$this->putJson('/api/site/settings',[])->assertForbidden();$this->postJson('/api/site/media',[])->assertForbidden();
    }
    public function test_public_search_sitemap_and_lists_exclude_draft_deleted_and_other_school(): void
    {
        $visible=$this->add('page',['title'=>'ตัวค้นหาเผยแพร่']);$draft=$this->add('page',['title'=>'ตัวค้นหาร่าง','intent'=>'draft']);$other=$this->add('page',['title'=>'ตัวค้นหาโรงเรียนอื่น']);DB::table('posts')->where('id',$other['id'])->update(['school_id'=>99]);
        $this->get('/portal/search?q=ตัวค้นหา')->assertSee('ตัวค้นหาเผยแพร่')->assertDontSee('ตัวค้นหาร่าง')->assertDontSee('ตัวค้นหาโรงเรียนอื่น');$this->get('/sitemap.xml')->assertOk()->assertSee($visible['slug'])->assertDontSee($draft['slug'])->assertDontSee($other['slug']);
    }
    public function test_stale_content_and_media_updates_are_refused_and_unused_file_can_be_deleted(): void
    {
        $p=$this->add();$this->putJson('/api/site/content/'.$p['id'],$this->payload('article',['version'=>9]))->assertConflict();$id=$this->pdf();$this->putJson('/api/site/media/'.$id,['version'=>1,'alt'=>'ชื่อใหม่','folder'=>'เอกสาร'])->assertOk();$this->putJson('/api/site/media/'.$id,['version'=>1,'alt'=>'ชนกัน','folder'=>'ทั่วไป'])->assertConflict();$this->deleteJson('/api/site/media/'.$id,['version'=>2])->assertOk();$this->assertDatabaseMissing('cms_media',['id'=>$id]);
    }
    public function test_banner_placements_have_separate_slide_popup_and_notice_surfaces(): void
    {
        $this->add('banner',['title'=>'แบนเนอร์สไลด์','metadata'=>['placement'=>'hero','url'=>'/portal/news']]);$this->add('banner',['title'=>'แบนเนอร์สไลด์สอง','metadata'=>['placement'=>'hero']]);$this->add('banner',['title'=>'ประกาศป๊อบอัพ','metadata'=>['placement'=>'popup']]);$this->add('banner',['title'=>'ประกาศแถบข้อความ','metadata'=>['placement'=>'notice']]);
        $html=$this->get('/portal')->assertOk()->assertSee('data-campus-next',false)->assertSee('data-school-popup=',false)->assertSee('ประกาศป๊อบอัพ')->assertSee('ประกาศแถบข้อความ')->getContent();$this->assertSame(2,substr_count($html,'data-campus-slide '));$this->assertSame(1,substr_count($html,'class="public-banner"'));
    }
    public function test_rich_text_local_links_and_homepage_blocks_resolve_in_subdirectory_installation(): void
    {
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost/school-atelier-20261105');$this->assertStringContainsString('href="http://localhost/school-atelier-20261105/portal/news"',\App\Support\WebsiteView::body('<p><a href="/portal/news">ข่าว</a></p>'));$this->assertStringContainsString('href="https://example.edu/"',\App\Support\WebsiteView::body('<a href="https://example.edu/">ต้นฉบับ</a>'));
    }
    public function test_school_admin_cannot_trigger_another_schools_scheduled_publication(): void
    {
        $other=User::forceCreate(['name'=>'ผู้ดูแลโรงเรียนอื่น','email'=>'other-school@example.test','password'=>'Test-other-school-password','school_id'=>99,'role'=>'admin','is_active'=>true]);$p=$this->add('page',['intent'=>'schedule','scheduled_at'=>now('Asia/Bangkok')->addHour()->format('Y-m-d\TH:i')]);DB::table('posts')->where('id',$p['id'])->update(['school_id'=>99,'scheduled_by'=>$other->id,'scheduled_at'=>now('UTC')->subMinute()]);
        $this->postJson('/api/site/publish-due')->assertOk()->assertJsonPath('published',0);$this->assertDatabaseHas('posts',['id'=>$p['id'],'status'=>'scheduled']);$this->artisan('cms:publish-due')->assertExitCode(0);$this->assertDatabaseHas('posts',['id'=>$p['id'],'status'=>'published']);
    }
    public function test_categories_used_only_by_draft_or_revision_cannot_be_deleted(): void
    {
        $c=$this->postJson('/api/site/categories',['label'=>'หมวดร่าง','slug'=>'draft-only'])->assertCreated()->json();$p=$this->add('article');$data=$this->payload('article',['category'=>'draft-only','intent'=>'draft','version'=>1]);$this->putJson('/api/site/content/'.$p['id'],$data)->assertOk();$this->deleteJson('/api/site/categories/'.$c['id'],['version'=>1])->assertUnprocessable();
        $data['intent']='publish';$data['version']=2;$this->putJson('/api/site/content/'.$p['id'],$data)->assertOk();$data['category']='news';$data['version']=3;$this->putJson('/api/site/content/'.$p['id'],$data)->assertOk();$this->deleteJson('/api/site/categories/'.$c['id'],['version'=>1])->assertUnprocessable();
    }
}
