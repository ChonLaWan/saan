<?php

namespace Tests\Feature;

use App\Domain\Cms\ContentTypes;
use App\Domain\Cms\SiteSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Regression tests for the CMS findings of functional-audit-2026-11-06 (the probes there reproduced each bug). */
class CmsRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();config(['school.demo'=>true]);$this->seed();$this->actingAs(User::where('role','admin')->first());
    }

    private function payload(string $type='article',array $extra=[]): array
    {
        return array_replace(['title'=>'REMEDIATION-'.$type,'body'=>'Remediation marker','excerpt'=>'Mock record','content_type'=>$type,'category'=>ContentTypes::TYPES[$type]['category'],'is_pinned'=>false,'metadata'=>['source_kind'=>'original','body_format'=>'html'],'intent'=>'publish'],$extra);
    }

    private function guest(): void { $this->app['auth']->forgetGuards(); }

    private function image(): int { return $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->image('remediation.png',32,32)])->assertCreated()->json('id'); }

    public function test_cms002_profile_publish_keeps_other_section_drafts_private(): void
    {
        $s=SiteSettings::defaults();$s['observance']['mode']='celebration';$s['observance']['title']='UNPUBLISHED-NOTICE';
        $s['navigation']=[['label'=>'UNREADY-MENU','url'=>'/not-ready']];
        $d=$this->putJson('/api/site/settings',['settings'=>$s,'version'=>0,'intent'=>'draft'])->assertOk()->json();
        $next=$d['draft'];$next['intro_heading']='NEW-PROFILE-HEADING';
        $r=$this->putJson('/api/site/settings',['settings'=>$next,'version'=>$d['version'],'intent'=>'publish','section'=>'profile'])->assertOk()->json();
        $this->assertSame('off',$r['published']['observance']['mode']);$this->assertSame('NEW-PROFILE-HEADING',$r['published']['intro_heading']);
        $this->assertSame('celebration',$r['draft']['observance']['mode']);
        $this->guest();$this->get('/portal')->assertOk()->assertDontSee('UNPUBLISHED-NOTICE')->assertDontSee('UNREADY-MENU');
    }

    public function test_cms002_publish_without_section_only_publishes_changed_keys(): void
    {
        $s=SiteSettings::defaults();$s['navigation']=[['label'=>'DRAFT-MENU','url'=>'/draft']];
        $d=$this->putJson('/api/site/settings',['settings'=>$s,'version'=>0,'intent'=>'draft'])->assertOk()->json();
        $next=$d['draft'];$next['badge']='CHANGED-BADGE';
        $this->putJson('/api/site/settings',['settings'=>$next,'version'=>$d['version'],'intent'=>'publish'])->assertUnprocessable();
        $r=$this->putJson('/api/site/settings',['settings'=>$next,'version'=>$d['version'],'intent'=>'publish','section'=>'profile'])->assertOk()->json();
        $this->assertSame('CHANGED-BADGE',$r['published']['badge']);$this->assertSame([],$r['published']['navigation']);
        $this->putJson('/api/site/settings',['settings'=>$r['draft'],'version'=>$r['version'],'intent'=>'publish','section'=>'menus'])->assertOk()->assertJsonPath('published.navigation.0.label','DRAFT-MENU');
        $this->putJson('/api/site/settings',['settings'=>$r['draft'],'version'=>$r['version']+1,'intent'=>'publish','section'=>'unknown'])->assertUnprocessable();
    }

    public function test_cms002_theme_publish_does_not_expose_branding_logo_draft(): void
    {
        $a=$this->image();$b=$this->image();$state=$this->getJson('/api/site/presentation')->assertOk()->json();
        $state=$this->putJson('/api/site/presentation',['section'=>'branding','settings_version'=>$state['settings']['version'],'theme_version'=>$state['theme']['version'],'values'=>['source'=>'uploaded'],'theme'=>['logo_media_id'=>$a],'shared'=>[],'intent'=>'publish'])->assertOk()->json();
        $state=$this->putJson('/api/site/presentation',['section'=>'branding','settings_version'=>$state['settings']['version'],'theme_version'=>$state['theme']['version'],'values'=>[],'theme'=>['logo_media_id'=>$b],'shared'=>[],'intent'=>'draft'])->assertOk()->json();
        $settings=$state['theme']['draft'];$settings['accent']='#336644';
        $fields=['preset','school_name','tagline','hero_title','hero_description','accent','background','layout','font','show_hero','show_contact','phone','address','menus','hero_media_id'];
        $t=$this->putJson('/api/cms/theme',['version'=>$state['theme']['version'],'intent'=>'publish','settings'=>$settings,'fields'=>$fields])->assertOk()->json();
        $this->assertSame($a,$t['published']['logo_media_id']);$this->assertSame('#336644',$t['published']['accent']);$this->assertSame($b,$t['draft']['logo_media_id']);
        $this->guest();$this->get('/media/'.$b)->assertNotFound();$this->get('/portal')->assertOk()->assertDontSee('/media/'.$b,false);
        $this->actingAs(User::where('role','admin')->first());
        $settings=$t['draft'];$settings['accent']='#224466';
        $this->putJson('/api/cms/theme',['version'=>$t['version'],'intent'=>'publish','settings'=>$settings])->assertUnprocessable();
        $t=$this->putJson('/api/cms/theme',['version'=>$t['version'],'intent'=>'publish','settings'=>$settings,'fields'=>['accent']])->assertOk()->json();
        $this->assertSame($a,$t['published']['logo_media_id']);$this->assertSame('#224466',$t['published']['accent']);
    }

    public function test_cms003_legacy_editor_refuses_typed_scheduled_and_trashed_content(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-06T03:00:00Z'));
        $page=$this->postJson('/api/site/content',$this->payload('page',['metadata'=>['source_kind'=>'original','body_format'=>'html','tags'=>['KEEP-TAG']]]))->assertCreated()->json();
        $this->putJson('/api/cms/posts/'.$page['id'],['version'=>$page['version'],'title'=>'LEGACY-CHANGED','body'=>'x','category'=>'news','intent'=>'draft'])->assertUnprocessable();
        $article=$this->postJson('/api/site/content',$this->payload())->assertCreated()->json();
        $scheduled=$this->putJson('/api/site/content/'.$article['id'],$this->payload('article',['version'=>$article['version'],'title'=>'SCHEDULED-TITLE','intent'=>'schedule','scheduled_at'=>'2026-11-06T11:00']))->assertOk()->json();
        $this->putJson('/api/cms/posts/'.$article['id'],['version'=>$scheduled['version'],'title'=>'LEGACY-NOW','body'=>'x','category'=>'news','intent'=>'publish'])->assertConflict();
        $this->travel(5)->hours();$this->artisan('cms:publish-due')->assertExitCode(0);
        $row=DB::table('posts')->find($article['id']);$this->assertNull($row->scheduled_at);$this->assertSame('SCHEDULED-TITLE',$row->title);
        $other=$this->postJson('/api/site/content',$this->payload())->assertCreated()->json();
        $trash=$this->postJson('/api/site/content/'.$other['id'].'/action',['version'=>$other['version'],'action'=>'trash'])->assertOk()->json();
        $this->putJson('/api/cms/posts/'.$other['id'],['version'=>$trash['version'],'title'=>'EDITED-IN-TRASH','body'=>'x','category'=>'news','intent'=>'publish'])->assertConflict();
        $this->assertNotSame('EDITED-IN-TRASH',DB::table('posts')->find($other['id'])->title);
        $this->assertSame('["KEEP-TAG"]',json_encode(json_decode(DB::table('posts')->find($page['id'])->metadata,true)['tags']));
        $this->travelBack();
    }

    public function test_cms005_due_schedule_without_draft_is_cleared_with_audit(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-06T03:00:00Z'));
        $p=$this->postJson('/api/site/content',$this->payload())->assertCreated()->json();
        DB::table('posts')->where('id',$p['id'])->update(['scheduled_at'=>now('UTC')->subMinute(),'scheduled_by'=>auth()->id(),'draft_payload'=>null]);
        $this->artisan('cms:publish-due')->assertExitCode(0);
        $row=DB::table('posts')->find($p['id']);$this->assertNull($row->scheduled_at);$this->assertSame('published',$row->status);
        $this->assertDatabaseHas('audit_events',['action'=>'content.schedule_invalid_cleared','entity_id'=>$p['id']]);
        $this->artisan('cms:publish-due')->assertExitCode(0);
        $this->assertSame(1,DB::table('audit_events')->where('action','content.schedule_invalid_cleared')->count());
        $this->travelBack();
    }

    public function test_cms006_listing_respects_draft_that_removes_cover(): void
    {
        $id=$this->image();$p=$this->postJson('/api/site/content',$this->payload('article',['cover_media_id'=>$id]))->assertCreated()->json();
        $this->putJson('/api/site/content/'.$p['id'],$this->payload('article',['cover_media_id'=>null,'version'=>$p['version'],'intent'=>'draft']))->assertOk();
        $this->getJson('/api/site/content?type=article&q=REMEDIATION')->assertOk()->assertJsonPath('data.0.cover_media_id',null);
    }

    public function test_cms007_hero_slide_limit_is_the_same_for_create_and_reorder(): void
    {
        $existing=DB::table('posts')->whereNull('deleted_at')->where('content_type','banner')->get()->filter(fn($r)=>(json_decode($r->metadata??'{}',true)['placement']??null)==='hero')->count();
        $items=[];
        for($i=$existing+1;$i<=40;$i++){$p=$this->postJson('/api/site/content',$this->payload('banner',['title'=>'HERO-'.$i,'intent'=>'draft','metadata'=>['placement'=>'hero','sort_order'=>$i]]))->assertCreated()->json();}
        $this->postJson('/api/site/content',$this->payload('banner',['title'=>'HERO-41','intent'=>'draft','metadata'=>['placement'=>'hero','sort_order'=>41]]))->assertUnprocessable();
        $slides=$this->getJson('/api/site/slides')->assertOk()->assertJsonCount(40,'data')->json('data');
        $this->putJson('/api/site/slides/order',['items'=>array_map(fn($s)=>['id'=>$s['id'],'version'=>$s['version']],array_reverse($slides))])->assertOk();
    }

    public function test_cms004_rich_text_media_link_is_tracked_for_guests_and_delete_guard(): void
    {
        $pdf=fn()=>$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('doc.pdf',"%PDF-1.4\n% remediation\n%%EOF")])->assertCreated()->json('id');
        $linked=$pdf();$unlinked=$pdf();$absolute=$pdf();
        $p=$this->postJson('/api/site/content',$this->payload('page',['body'=>'<p><a href="/media/'.$linked.'">DOC</a> <a href="https://localhost/media/'.$absolute.'">ABS</a> <a href="https://other.example/media/'.$unlinked.'">X</a></p>']))->assertCreated()->json();
        $this->deleteJson('/api/site/media/'.$linked,['version'=>1])->assertUnprocessable();
        $this->guest();
        $this->get('/media/'.$linked)->assertOk();$this->get('/media/'.$absolute)->assertOk();$this->get('/media/'.$unlinked)->assertNotFound();
        $this->actingAs(User::where('role','admin')->first());
        $d=$this->postJson('/api/site/content/'.$p['id'].'/action',['version'=>$p['version'],'action'=>'unpublish'])->assertOk()->json();
        $this->guest();$this->get('/media/'.$linked)->assertNotFound();
        $this->actingAs(User::where('role','admin')->first());$this->deleteJson('/api/site/media/'.$unlinked,['version'=>1])->assertOk();
    }

    public function test_cms004_body_links_under_install_subpath_are_recognised(): void
    {
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost/school-x');
        $ids=app(\App\Domain\Cms\WebsiteContent::class)->bodyMediaReferences('<a href="/school-x/media/5">a</a><img src="/media/6"><a href="https://localhost/school-x/media/7?download=1">b</a><a href="https://evil.example/school-x/media/8">c</a>');
        \Illuminate\Support\Facades\URL::forceRootUrl(null);
        $this->assertSame([5,6,7],$ids);
    }

    public function test_cms010_public_media_gate_is_cached_until_content_changes(): void
    {
        $id=$this->image();$this->postJson('/api/site/content',$this->payload('article',['cover_media_id'=>$id]))->assertCreated();
        $this->guest();$this->get('/media/'.$id)->assertOk();
        DB::enableQueryLog();$this->get('/media/'.$id)->assertOk();
        $scans=collect(DB::getQueryLog())->filter(fn($q)=>str_contains(str_replace('`','"',$q['query']),'from "posts"')&&str_contains(str_replace('`','"',$q['query']),'"body"'))->count();
        $this->assertSame(0,$scans,'a cached public set must not rescan posts');
    }

    public function test_cms001_long_thai_body_is_saved_completely_or_rejected(): void
    {
        $body='<p>'.str_repeat('ก',49900).'END-MARKER</p><p><a href="https://example.org/a">link</a></p>';
        $this->assertLessThanOrEqual(50000,mb_strlen($body));$this->assertGreaterThan(60000,strlen($body));
        $p=$this->postJson('/api/site/content',$this->payload('page',['body'=>$body]))->assertCreated()->json();
        $saved=DB::table('posts')->where('id',$p['id'])->value('body');
        $this->assertStringContainsString('END-MARKER',$saved);
        $this->assertSame(49900,mb_substr_count($saved,'ก'));
        $this->assertStringContainsString('rel="noopener noreferrer"',$saved);
        $tooLong=str_repeat('ก',50001);
        $this->putJson('/api/site/content/'.$p['id'],$this->payload('page',['body'=>$tooLong,'version'=>$p['version']]))->assertUnprocessable();
        $this->assertStringContainsString('END-MARKER',DB::table('posts')->where('id',$p['id'])->value('body'));
    }
}
