<?php
namespace Tests\Feature;

use App\Domain\Cms\ContentTypes;
use App\Domain\Cms\SiteSettings;
use App\Models\User;
use App\Support\WebsiteCoverage;
use App\Support\WebsiteView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThaiCompactCoverageTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();config(['school.demo'=>true]);$this->seed();$this->actingAs(User::where('role','admin')->first());}
    private function entry(string $type,string $intent='publish',array $metadata=[]):array
    {
        return $this->postJson('/api/site/content',['title'=>'ทดสอบหน้าบ้าน '.$type.' '.$intent,'body'=>'เนื้อหาทดสอบภาษาไทย','excerpt'=>'คำโปรยตัวอย่าง','content_type'=>$type,'category'=>ContentTypes::TYPES[$type]['category'],'is_pinned'=>false,'intent'=>$intent,'metadata'=>$metadata])->assertCreated()->json();
    }
    public function test_all_twelve_content_types_have_discoverable_public_list_and_detail():void
    {
        $this->get('/portal/directory')->assertOk()->assertSee('ข้อมูลและบริการทั้งหมด')->assertSee('thai-compact.css?v=',false);
        foreach(ContentTypes::TYPES as $key=>$type){
            $meta=$key==='video'?['video_url'=>'https://www.youtube.com/watch?v=abcdefghijk']:($key==='newsletter'?['issue_no'=>'1/2569','issued_on'=>'2026-11-05']:[]);
            $entry=$this->entry($key,'publish',$meta);
            $this->get('/portal/'.$type['route'])->assertOk()->assertSee($entry['draft']['title']);
            $this->get('/portal/'.$type['route'].'/'.$entry['slug'])->assertOk()->assertSee($entry['draft']['title'])->assertSee('lang="th"',false);
            $this->get('/portal/directory')->assertSee('data-public-section="'.$key.'"',false)->assertSee(url('/portal/'.$type['route']));
        }
    }
    public function test_directory_counts_exclude_drafts_trash_and_other_schools():void
    {
        $baseline=DB::table('posts')->where('school_id',1)->where('content_type','article')->where('status','published')->whereNull('deleted_at')->count();
        $this->entry('article');$this->entry('article','draft');$foreign=$this->entry('article');$trashed=$this->entry('article');
        DB::table('posts')->where('id',$foreign['id'])->update(['school_id'=>2]);DB::table('posts')->where('id',$trashed['id'])->update(['deleted_at'=>now()]);
        $this->get('/portal/directory')->assertOk()->assertViewHas('sectionCounts',fn($counts)=>(int)$counts['article']===$baseline+1);
    }
    public function test_coverage_classifies_every_menu_and_does_not_label_school_plans_as_working_frontend():void
    {
        $rows=WebsiteCoverage::navigation();$this->assertGreaterThan(60,count($rows));$this->assertSame([],array_values(array_filter($rows,fn($row)=>$row['kind']==='unknown')));
        $modules=collect(WebsiteCoverage::modules())->keyBy('id');$this->assertCount(24,$modules);$this->assertSame('design',$modules['M03']['kind']);$this->assertSame('plan',$modules['M20']['kind']);
        $this->get('/website-coverage')->assertOk()->assertSee('ยังไม่เสร็จครบ')->assertSee('เฉพาะเจ้าหน้าที่')->assertSee('/student-registry-design/index.html',false);
    }
    public function test_coverage_is_staff_scoped_and_never_exposes_it_to_guests_or_teacher_role():void
    {
        User::factory()->create(['school_id'=>1,'role'=>'editor','is_demo'=>false,'is_active'=>true]);
        foreach(['admin','principal','editor'] as $role){$this->actingAs(User::where('role',$role)->first());$this->get('/website-coverage')->assertOk();}
        $this->actingAs(User::where('role','teacher')->first());$this->get('/website-coverage')->assertForbidden();
        $this->app['auth']->forgetGuards();$this->get('/website-coverage')->assertRedirect('/login');
    }
    public function test_home_pairs_only_adjacent_news_and_notices_and_preserves_configured_order():void
    {
        $settings=SiteSettings::defaults();$settings['homepage_sections']=['news','notices','services'];
        $this->putJson('/api/site/settings',['settings'=>$settings,'version'=>0,'intent'=>'publish','section'=>'all'])->assertOk();
        $this->get('/portal')->assertOk()->assertSee('class="school-updates-row"',false)->assertSeeInOrder(['id="news"','id="notices"'],false);
        $settings['homepage_sections']=['notices','services','news'];
        $this->putJson('/api/site/settings',['settings'=>$settings,'version'=>1,'intent'=>'publish','section'=>'all'])->assertOk();
        $this->get('/portal')->assertOk()->assertDontSee('class="school-updates-row"',false)->assertSeeInOrder(['id="notices"','id="news"'],false);
    }
    public function test_mission_is_visible_only_after_publication():void
    {
        $settings=SiteSettings::defaults();$settings['mission']='พันธกิจทดสอบที่ยังไม่เผยแพร่';
        $this->putJson('/api/site/settings',['settings'=>$settings,'version'=>0,'intent'=>'draft'])->assertOk();
        $this->get('/portal')->assertOk()->assertDontSee($settings['mission']);
        $this->putJson('/api/site/settings',['settings'=>$settings,'version'=>1,'intent'=>'publish','section'=>'all'])->assertOk();
        $this->get('/portal')->assertOk()->assertSee($settings['mission'])->assertSee('class="school-mission"',false);
    }
    public function test_event_time_displays_bangkok_time_and_arabic_buddhist_year():void
    {
        $entry=$this->entry('event','publish',['starts_at'=>'2026-11-06T01:30:00Z','ends_at'=>'2026-11-06T03:00:00Z','location'=>'ห้องประชุมตัวอย่าง']);
        $this->get('/portal/events/'.$entry['slug'])->assertOk()->assertSee('6 พ.ย. 2569 เวลา 08:30 น.')->assertSee('6 พ.ย. 2569 เวลา 10:00 น.');
        $this->assertSame('6 พ.ย. 2569 เวลา 08:30 น.',WebsiteView::datetime('2026-11-06T01:30:00Z'));
        $this->assertSame('6 พ.ย. 2569',WebsiteView::datetime('2026-11-06'));
    }
    public function test_directory_is_in_sitemap_and_internal_link_checker_recognizes_it():void
    {
        $settings=SiteSettings::defaults();$settings['navigation']=[['label'=>'ข้อมูลทั้งหมด','url'=>'/portal/directory']];
        $this->putJson('/api/site/settings',['settings'=>$settings,'version'=>0,'intent'=>'publish','section'=>'all'])->assertOk();
        $this->getJson('/api/site/links')->assertOk()->assertJsonPath('data.0.status','ok');
        $this->get('/sitemap.xml')->assertOk()->assertSee('/portal/directory');
    }
}
