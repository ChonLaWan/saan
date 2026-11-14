<?php

namespace App\Http\Controllers;

use App\Domain\Cms\ContentTypes;
use App\Domain\Cms\SiteSettings;
use App\Domain\Cms\WebsiteContent;
use App\Domain\Cms\SafeContent;
use App\Domain\SchoolOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WebsiteAdminController extends Controller
{
    public function __construct(private WebsiteContent $content,private SchoolOperations $ops,private SiteSettings $settings) {}
    public function state()
    {
        $this->content->authorize();$trashed='CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END';$sched='CASE WHEN scheduled_at IS NULL THEN 0 ELSE 1 END';$rows=$this->ops->query('posts')->selectRaw("content_type, status, $trashed AS trashed, $sched AS sched, COUNT(*) AS n")->groupBy('content_type','status',DB::raw($trashed),DB::raw($sched))->get();
        return response()->json(['types'=>ContentTypes::TYPES,'categories'=>$this->ops->query('cms_categories')->orderBy('label')->get(),
            'settings'=>$this->settings->read($this->ops->school()),'counts'=>['published'=>(int)$rows->where('trashed',0)->where('status','published')->sum('n'),'draft'=>(int)$rows->where('trashed',0)->where('status','draft')->sum('n'),'scheduled'=>(int)$rows->where('trashed',0)->where('sched',1)->sum('n'),'trash'=>(int)$rows->where('trashed',1)->sum('n'),'media'=>$this->ops->query('cms_media')->count(),'messages'=>$this->ops->query('cms_messages')->where('status','unread')->count()],
            'by_type'=>$rows->where('trashed',0)->groupBy('content_type')->map(fn($g)=>(int)$g->sum('n')),'demo'=>(bool)config('school.demo'),'directory_sources'=>['manual',...(app(\App\Support\Modules\ModuleRegistry::class)->active('personnel')?['personnel']:[])]]);
    }
    public function listing(Request $r)
    {
        $this->content->authorize();$d=$r->validate(['type'=>['nullable',Rule::in(array_keys(ContentTypes::TYPES))],'placement'=>'nullable|in:hero,notice,popup,sidebar','status'=>'nullable|in:draft,published,scheduled','trash'=>'nullable|boolean','q'=>'nullable|string|max:100','page'=>'nullable|integer|min:1']);
        $query=$this->ops->query('posts')->when($r->boolean('trash'),fn($q)=>$q->whereNotNull('deleted_at'),fn($q)=>$q->whereNull('deleted_at'))
            ->when($d['type']??null,fn($q,$type)=>$q->where('content_type',$type))->when($d['status']??null,fn($q,$status)=>$status==='scheduled'?$q->whereNotNull('scheduled_at'):$q->where('status',$status))
            ->when($d['q']??null,fn($q,$search)=>$q->where(fn($q)=>$q->where('title','like','%'.$search.'%')
                // MySQL compares a JSON column as utf8mb4_bin (case-sensitive), MariaDB as LONGTEXT (case-insensitive): pin one collation for both
                ->orWhereRaw('CAST(draft_payload AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?',['%'.$search.'%'])))
            ->when($d['placement']??null,fn($q,$placement)=>$q->where(fn($q)=>$q->where(fn($q)=>$q->whereNotNull('draft_payload')->where('draft_payload->metadata->placement',$placement))->orWhere(fn($q)=>$q->whereNull('draft_payload')->where('metadata->placement',$placement))))
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(18);
        $query->setCollection($query->getCollection()->map(function($row){$draft=$row->draft_payload?json_decode($row->draft_payload,true):[];return ['id'=>$row->id,'title'=>$draft['title']??$row->title,'excerpt'=>$draft['excerpt']??$row->excerpt,'content_type'=>$row->content_type,'category'=>$draft['category']??$row->category,'status'=>$row->status,'slug'=>$row->slug,'version'=>$row->version,'cover_media_id'=>array_key_exists('cover_media_id',$draft)?$draft['cover_media_id']:$row->cover_media_id,'published_at'=>$row->published_at,'updated_at'=>$row->updated_at,'deleted_at'=>$row->deleted_at,'scheduled_at'=>$row->scheduled_at,'has_draft'=>(bool)$row->draft_payload];}));
        return response()->json($query);
    }
    public function read(int $id){return response()->json($this->content->read($id));}
    public function revision(int $id,int $revision)
    {
        $this->content->authorize();$this->ops->record('posts',$id);$row=$this->ops->query('cms_post_revisions')->where('id',$revision)->where('post_id',$id)->first()??abort(404);$snapshot=json_decode($row->snapshot,true);if(($snapshot['metadata']['body_format']??'plain')==='html')$snapshot['body']=SafeContent::html($snapshot['body']);return response()->json(['version'=>$row->version,'created_at'=>$row->created_at,'snapshot'=>$snapshot]);
    }
    public function save(Request $r,?int $id=null)
    {
        $this->content->authorize();$d=$r->validate([
            'title'=>'required|string|max:180','body'=>'present|nullable|string|max:50000','excerpt'=>'nullable|string|max:300',
            'content_type'=>['required',Rule::in(array_keys(ContentTypes::TYPES))],
            'category'=>['required','string','max:30',Rule::exists('cms_categories','slug')->where('school_id',$this->ops->school())],
            'cover_media_id'=>'nullable|integer|min:1','is_pinned'=>'required|boolean','slug'=>'nullable|regex:/^[a-z0-9-]+$/D|max:140',
            'intent'=>'required|in:draft,publish,schedule','version'=>$id?'required|integer|min:1':'sometimes|integer|min:0',
            'scheduled_at'=>'nullable|required_if:intent,schedule|date_format:Y-m-d\TH:i',
        ]+ContentTypes::metadataRules(),['title.required'=>'กรุณากรอกหัวข้อ','category.exists'=>'กรุณาเลือกหมวดที่มีอยู่ในโรงเรียนนี้','scheduled_at.required_if'=>'กรุณาเลือกวันและเวลาเผยแพร่']);
        $d['body']=$d['body']??'';
        if($id)abort_unless($this->ops->record('posts',$id)->content_type===$d['content_type'],422,'ประเภทเนื้อหาเปลี่ยนไม่ได้ กรุณาสร้างรายการใหม่');
        return response()->json($this->content->save($d,$id),$id?200:201);
    }
    public function action(Request $r,int $id)
    {
        $this->content->authorize();$d=$r->validate(['version'=>'required|integer|min:1','action'=>'required|in:trash,restore,unpublish,revision','revision_id'=>'nullable|required_if:action,revision|integer|min:1']);
        return response()->json($this->content->action($id,(int)$d['version'],$d['action'],$d['revision_id']??null));
    }
    public function settings(Request $r)
    {
        $this->content->authorize();$d=$r->validate([
            'version'=>'required|integer|min:0','intent'=>'required|in:draft,publish','section'=>['required_if:intent,publish','nullable',Rule::in([...array_keys(\App\Domain\Cms\SiteSettings::SECTIONS),'all'])],
            'settings'=>'required|array:badge,intro_heading,intro_text,mission,email,office_hours,levels,directory_source,footer_text,statistics,navigation,social_links,homepage_sections,default_seo_description,footer_navigation,utility_navigation,hero_autoplay,hero_interval,hero_search,favicon_media_id,color_mode,observance,branding,header,hero_design,extras',
            'settings.directory_source'=>'sometimes|in:manual,personnel','settings.extras'=>['sometimes','array',fn($a,$v,$fail)=>($problem=\App\Domain\Cms\SiteExtras::problem($v))===null?null:$fail($problem)],'settings.branding'=>'sometimes|array','settings.header'=>'sometimes|array','settings.hero_design'=>'sometimes|array',
            'settings.favicon_media_id'=>'sometimes|nullable|integer|min:1','settings.color_mode'=>'sometimes|in:light,dark,system,light-high,dark-high',
            'settings.observance'=>'sometimes|array:'.\App\Domain\Cms\Observance::keys(),
        ]+\App\Domain\Cms\Observance::rules('settings.observance')+[
            'settings.hero_autoplay'=>'sometimes|boolean','settings.hero_interval'=>'sometimes|integer|between:4000,15000','settings.hero_search'=>'sometimes|boolean',
            'settings.footer_navigation'=>['sometimes','array',fn($a,$v,$fail)=>SiteSettings::validateNavigation($v)?:$fail('เมนูส่วนท้ายไม่ถูกต้อง')],
            'settings.utility_navigation'=>['sometimes','array',fn($a,$v,$fail)=>SiteSettings::validateNavigation($v)?:$fail('เมนูแถบบนไม่ถูกต้อง')],
            'settings.badge'=>'present|string|max:100','settings.intro_heading'=>'required|string|max:160',
            'settings.intro_text'=>'present|nullable|string|max:1000','settings.mission'=>'present|nullable|string|max:500',
            'settings.email'=>'present|nullable|email|max:254','settings.office_hours'=>'present|nullable|string|max:200',
            'settings.levels'=>'present|nullable|string|max:180','settings.footer_text'=>'present|nullable|string|max:250',
            'settings.default_seo_description'=>'present|nullable|string|max:300',
            'settings.statistics'=>'present|array|max:6','settings.statistics.*'=>'array:label,value','settings.statistics.*.label'=>'required|string|max:80','settings.statistics.*.value'=>'required|string|max:50',
            'settings.navigation'=>['present','array',fn($a,$v,$fail)=>SiteSettings::validateNavigation($v)?:$fail('เมนูต้องมีชื่อและลิงก์ถูกต้อง รองรับไม่เกิน 3 ระดับ')],
            'settings.social_links'=>'present|array|max:5','settings.social_links.*'=>'array:label,url','settings.social_links.*.label'=>'required|string|max:60','settings.social_links.*.url'=>'required|url:https|max:1000',
            'settings.homepage_sections'=>'required|array|min:1|max:15','settings.homepage_sections.*'=>['required','string','distinct',function($a,$v,$fail){if(in_array($v,['services','news','notices','albums','events','popular','map'],true))return;if(preg_match('/^module:([a-z][a-z0-9-]{1,39})$/D',$v,$mod)){if(!app(\App\Support\Modules\ModuleRegistry::class)->active($mod[1])||!view()->exists($mod[1].'::home-section'))$fail('บล็อกจากโมดูลนี้ใช้ไม่ได้');return;}if(!preg_match('/^page:([1-9][0-9]*)$/D',$v,$match)||!$this->ops->query('posts')->where('id',(int)($match[1]??0))->where('content_type','page')->whereNull('deleted_at')->exists())$fail('ส่วนเนื้อหาต้องอ้างอิงหน้าโรงเรียนที่มีอยู่');}],
        ]);
        $extra=\Illuminate\Support\Facades\Validator::make($r->all(),\App\Domain\Cms\PresentationSchema::settingsRules())->validate();
        foreach(['branding','header','hero_design']as$key)if(isset($extra['settings'][$key]))$d['settings'][$key]=$extra['settings'][$key];
        $s=$d['settings'];foreach(['intro_text','mission','email','office_hours','levels','footer_text','default_seo_description']as$key)$s[$key]=$s[$key]??'';
        if(isset($s['extras']))$s['extras']=\App\Domain\Cms\SiteExtras::normalize($s['extras']);if(isset($s['observance'])){if($problem=\App\Domain\Cms\Observance::problem($s['observance']))throw \Illuminate\Validation\ValidationException::withMessages(['settings.observance'=>[$problem]]);$s['observance']=\App\Domain\Cms\Observance::normalize($s['observance']);}
        return response()->json($this->settings->save($s,(int)$d['version'],$d['intent']==='publish',$d['section']??null));
    }
    public function category(Request $r,?int $id=null)
    {
        $this->content->authorize();$d=$r->validate(['label'=>'required|string|max:80','slug'=>['required','regex:/^[a-z][a-z0-9-]{0,29}$/D',Rule::unique('cms_categories')->where('school_id',$this->ops->school())->ignore($id)],'version'=>$id?'required|integer|min:1':'sometimes|integer']);
        $result=DB::transaction(function()use($d,$id){
            if($id){$row=$this->ops->query('cms_categories')->where('id',$id)->lockForUpdate()->first()??abort(404);abort_if((int)$row->version!==(int)$d['version'],409);abort_if($row->slug!==$d['slug']&&$this->categoryUsed($row->slug),422,'รหัสหมวดที่ถูกใช้แล้วเปลี่ยนไม่ได้');$this->ops->query('cms_categories')->where('id',$id)->update(['label'=>$d['label'],'slug'=>$d['slug'],'version'=>$row->version+1,'updated_at'=>now()]);}
            else $id=DB::table('cms_categories')->insertGetId(['school_id'=>$this->ops->school(),'label'=>$d['label'],'slug'=>$d['slug'],'created_at'=>now(),'updated_at'=>now()]);
            $this->ops->audit('category.saved','cms_categories',$id);return $this->ops->record('cms_categories',$id);
        });return response()->json($result,$id?200:201);
    }
    public function removeCategory(Request $r,int $id)
    {
        $this->content->authorize();$d=$r->validate(['version'=>'required|integer|min:1']);
        DB::transaction(function()use($id,$d){$row=$this->ops->query('cms_categories')->where('id',$id)->lockForUpdate()->first()??abort(404);abort_if((int)$row->version!==(int)$d['version'],409);abort_if($this->categoryUsed($row->slug),422,'หมวดนี้มีเนื้อหา ร่าง หรือประวัติใช้อยู่ กรุณาเปลี่ยนเฉพาะชื่อหมวด');$this->ops->query('cms_categories')->where('id',$id)->delete();$this->ops->audit('category.deleted','cms_categories',$id);});
        return response()->json(['ok'=>true]);
    }
    private function categoryUsed(string $slug): bool
    {
        return $this->ops->query('posts')->where(fn($q)=>$q->where('category',$slug)->orWhere('draft_payload->category',$slug))->exists()||$this->ops->query('cms_post_revisions')->where('snapshot->category',$slug)->exists();
    }
    public function messages(Request $r)
    {
        $this->content->authorize();return response()->json($this->ops->query('cms_messages')->orderByDesc('created_at')->paginate(20));
    }
    public function message(Request $r,int $id)
    {
        $this->content->authorize();$d=$r->validate(['status'=>'required|in:read,unread,archived','version'=>'required|integer|min:1']);
        return DB::transaction(function()use($id,$d){$row=$this->ops->query('cms_messages')->where('id',$id)->lockForUpdate()->first()??abort(404);abort_if((int)$row->version!==(int)$d['version'],409);$this->ops->query('cms_messages')->where('id',$id)->update(['status'=>$d['status'],'version'=>$row->version+1,'updated_at'=>now()]);$this->ops->audit('message.updated','cms_messages',$id);return response()->json(['ok'=>true]);});
    }
    public function audit()
    {
        $this->content->authorize();return response()->json(\App\Support\Saan\AuditFeed::visible($this->ops->query('audit_events'))->orderByDesc('id')->simplePaginate(30,['id','action','entity','entity_id','actor_id','created_at']));
    }
    public function publishDue(){ $this->ops->role(['admin']);return response()->json(['published'=>$this->content->publishDue($this->ops->school())]); }
}
