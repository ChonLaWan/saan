<?php

namespace App\Http\Controllers;

use App\Domain\Cms\ContentTypes;
use App\Domain\Cms\SiteSettings;
use App\Domain\Cms\WebsiteContent;
use App\Domain\CmsPublishing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchoolWebsiteController extends Controller
{
    public function __construct(private CmsPublishing $publishing,private SiteSettings $settings,private WebsiteContent $content) {}
    public function entry(Request $r)
    {
        if(!auth()->check())return$this->home($r);
        return app(\App\Http\Middleware\CurrentSchoolAccount::class)->handle($r,fn()=>app(SchoolController::class)->index());
    }
    private function query(?string $type=null)
    {
        return DB::table('posts')->where('school_id',config('school.school_id'))->whereNull('deleted_at')->where('status','published')->when($type,fn($q)=>$q->where('content_type',$type));
    }
    /** Listing columns: everything except the long body and the draft copy (cards never print them). */
    private function card($query)
    {
        static $columns=null;$columns??=array_values(array_diff(\Illuminate\Support\Facades\Schema::getColumnListing('posts'),['body','draft_payload']));
        return $query->select($columns);
    }
    /** Public LIKE search scans bodies: allow 30 searches a minute per client (IPv6 by /64), then 429. Returns $this for chaining. */
    private function throttleSearch(Request $r): static
    {
        $key='portal-search:'.\App\Support\Saan\NetKey::rate($r->ip());
        if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key,30))abort(429,'ค้นหาถี่เกินไป กรุณารอสักครู่',['Retry-After'=>\Illuminate\Support\Facades\RateLimiter::availableIn($key)]);
        \Illuminate\Support\Facades\RateLimiter::hit($key,60);
        return $this;
    }
    private function jsonText(string $key): string
    {
        if(!preg_match('/^[a-z_]+$/D',$key))throw new \InvalidArgumentException('json key');
        return "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.$key')),'null'),'')";
    }
    public function context(bool $preview=false): array
    {
        return ['theme'=>$this->publishing->theme((int)config('school.school_id'))[$preview?'draft':'published'],
            'site'=>$this->settings->read((int)config('school.school_id'))[$preview?'draft':'published'],
            'categories'=>DB::table('cms_categories')->where('school_id',config('school.school_id'))->when(!$preview&&class_exists(\Saan\Modules\WebCms\Hubs::class),fn($q)=>$q->whereNotIn('slug',\Saan\Modules\WebCms\Hubs::draftNewsCategories()))->pluck('label','slug')->all(),
            'types'=>ContentTypes::TYPES,'preview'=>$preview,'article'=>null,'collection'=>null,'pageTitle'=>null,'posts'=>collect()];
    }
    public function heroPreview(int $id)
    {
        $this->content->authorize();abort_unless((int)auth()->user()->school_id===(int)config('school.school_id'),403);$entry=$this->content->read($id);abort_unless(!$entry['deleted_at']&&$entry['draft']['content_type']==='banner'&&($entry['draft']['metadata']['placement']??null)==='hero',404);
        $this->staffPreview=true;$data=$this->home(request())->getData();$data=array_replace($data,$this->context(true));$slide=(object)array_merge($entry['draft'],['id'=>$id,'slug'=>$entry['slug'],'version'=>$entry['version'],'published_at'=>$entry['published_at'],'metadata'=>json_encode($entry['draft']['metadata'])]);$data['banners']=collect([$slide])->concat($data['banners']->reject(fn($b)=>$b->id===$id));return view('website.home',$data);
    }
    /** True while a staff preview builds the home data: the draft section list counts then, not the published one. */
    private bool $staffPreview=false;
    public function home(Request $r)
    {
        $category=$r->query('category');abort_if($category!==null&&!is_string($category),404);
        if($category!==null)return $this->collection($r,'news');
        $data=$this->context();$data['news']=$this->card($this->query('article'))->orderByDesc('is_pinned')->orderByDesc('published_at')->limit(6)->get();
        $data['notices']=$this->card($this->query())->whereIn('content_type',['job','procurement'])->orderByDesc('published_at')->limit(6)->get();
        $data['albums']=$this->card($this->query('album'))->orderByDesc('published_at')->limit(3)->get();
        // PUBLIC-001: upcoming and ongoing events first (soonest first), then the most recent past events to fill the block.
        // CORE-PERF: the selection and the ordering run in SQL (JSON text of starts_at/ends_at) and only the 5 rows that are shown are fetched, without body
        $today=now('Asia/Bangkok')->toDateString();$starts=$this->jsonText('starts_at');$ends=$this->jsonText('ends_at');
        $upcoming=$this->card($this->query('event'))->whereRaw("($starts >= ? OR $ends >= ?)",[$today,$today])->orderByRaw("$starts ASC")->orderBy('id')->limit(5)->get();
        $past=$upcoming->count()>=5?collect():$this->card($this->query('event'))->whereRaw("NOT ($starts >= ? OR $ends >= ?)",[$today,$today])->orderByRaw("$starts DESC")->orderBy('id')->limit(5-$upcoming->count())->get();
        $data['events']=$upcoming->concat($past)->values();
        $data['services']=\App\Support\JsonSort::numeric($this->card($this->query('service')))->get();
        $data['banners']=\App\Support\JsonSort::numeric($this->card($this->query('banner')))->get()->filter(function($banner){$meta=\App\Support\WebsiteView::meta($banner);$now=\Carbon\CarbonImmutable::now('Asia/Bangkok');return (empty($meta['starts_at'])||\Carbon\CarbonImmutable::parse($meta['starts_at'],'Asia/Bangkok')->lessThanOrEqualTo($now))&&(empty($meta['ends_at'])||\Carbon\CarbonImmutable::parse($meta['ends_at'],'Asia/Bangkok')->greaterThanOrEqualTo($now));});
        $both=$this->settings->read((int)config('school.school_id'));$pageIds=collect(array_merge($both['published']['homepage_sections']??[],$both['draft']['homepage_sections']??[]))->filter(fn($s)=>is_string($s)&&str_starts_with($s,'page:'))->map(fn($s)=>(int)substr($s,5))->filter()->values()->all();
        $data['pageBlocks']=$pageIds?$this->query('page')->whereIn('id',$pageIds)->get()->keyBy('id'):collect();
        // F5a: computed only when the section list this request renders holds the block: the published list for visitors, the draft list only in the staff preview
        $shown=$this->staffPreview?($both['draft']['homepage_sections']??[]):($both['published']['homepage_sections']??[]);
        $data['popular']=in_array('popular',$shown,true)?$this->popularNews($data['site']):collect();
        return view('website.home',$data);
    }
    public function collection(Request $r,string $section)
    {
        $type=array_search($section,array_column(ContentTypes::TYPES,'route'),true);
        $keys=array_keys(ContentTypes::TYPES);abort_if($type===false,404);$type=$keys[$type];
        $d=$r->validate(['q'=>'nullable|string|max:100','category'=>'nullable|string|max:30','source'=>'nullable|in:original,mock,reference','tag'=>'nullable|string|max:40','fy'=>'nullable|integer|between:2560,2700','stage'=>'nullable|in:results,tor,invitation,award,cancelled,contract,information','page'=>'nullable|integer|min:1']);
        if(isset($d['category']))abort_unless(DB::table('cms_categories')->where('school_id',config('school.school_id'))->where('slug',$d['category'])->exists(),404);
        // PUBLIC-002: '0' is a real search term; test for presence, not truthiness.
        $has=fn($key)=>isset($d[$key])&&$d[$key]!=='';
        $posts=$this->card($this->query($type))->when($has('category'),fn($q)=>$q->where('category',$d['category']))->when($has('source'),fn($q)=>$q->where('metadata->source_kind',$d['source']))
            ->when($has('tag'),fn($q)=>$q->whereJsonContains('metadata->tags',$d['tag']))
            ->when($type==='procurement'&&$has('stage'),fn($q)=>$q->whereIn('metadata->stage',$d['stage']==='results'?['award','contract']:[$d['stage']]))
            ->when($has('fy'),function($q)use($d){[$from,$to]=\App\Support\Fiscal::range((int)$d['fy']);return$q->where('published_at','>=',$from)->where('published_at','<',$to);})
            ->when($has('q'),fn($q)=>$q->where(fn($q)=>$q->where('title','like','%'.$d['q'].'%')->orWhere('body','like','%'.$d['q'].'%')))->when($type==='person',fn($q)=>\App\Support\JsonSort::numeric($q))->orderByDesc('is_pinned')->orderByDesc('published_at');
        if($has('q'))$this->throttleSearch($r);
        $posts=($has('q')?$posts->simplePaginate(12):$posts->paginate($type==='person'?300:12))->withQueryString();
        $context=$this->context();$extra=[];
        // G3: the staff page may read the personnel register instead of hand-written person posts (site setting directory_source)
        if($type==='person'&&$this->directorySource($context['site'])==='personnel')$extra['personnelStaff']=\Saan\Modules\Personnel\Staff::directory();
        return view('website.collection',$context)->with(['posts'=>$posts,'collection'=>$type,'pageTitle'=>ContentTypes::TYPES[$type]['label']]+$extra);
    }
    /** 'personnel' only when chosen AND the personnel module is active with its tables; otherwise always 'manual'. */
    private function directorySource(array $site):string
    {
        if(($site['directory_source']??'manual')!=='personnel')return'manual';
        $registry=app(\App\Support\Modules\ModuleRegistry::class);
        return $registry->active('personnel')&&\Illuminate\Support\Facades\Schema::hasTable('per_profiles')?'personnel':'manual';
    }
    public function article(Request $r,string $section,string $slug)
    {
        $article=$this->query()->where('slug',$slug)->first()??abort(404);
        abort_unless((ContentTypes::TYPES[$article->content_type]['route']??null)===$section,404);
        // F5a: one aggregate count per reader per post per day (no personal data kept, bots and prefetches ignored); only the published page route counts, never a preview
        $context=$this->context();$views=null;
        if(class_exists(\Saan\Modules\WebCms\PostViews::class)){
            \Saan\Modules\WebCms\PostViews::record($r,$article);
            if($article->content_type==='article'&&($context['site']['extras']['show_views']??false)===true)$views=\Saan\Modules\WebCms\PostViews::total((int)$article->id);
        }
        return view('website.article',$context)->with(['article'=>$article,'pageTitle'=>$article->title,'viewCount'=>$views]);
    }
    /** F5a "ข่าวยอดนิยม": cards of the most read published news of the last 7 or 30 days (setting extras.popular), in rank order. Empty when nothing was read. */
    private function popularNews(array $site)
    {
        if(!class_exists(\Saan\Modules\WebCms\PostViews::class))return collect();
        $p=$site['extras']['popular'];$days=(int)$p['days'];$limit=(int)$p['limit'];
        // the grouped sum over the view rows is not run for every visitor: the ranking is kept 5 minutes per school, window and size (the staff preview always reads fresh)
        $ranks=$this->staffPreview?\Saan\Modules\WebCms\PostViews::popular($days,$limit):\Illuminate\Support\Facades\Cache::remember('popular:'.config('school.school_id').':'.$days.':'.$limit,300,fn()=>\Saan\Modules\WebCms\PostViews::popular($days,$limit));
        if(!$ranks)return collect();
        $cards=$this->card($this->query('article'))->whereIn('id',array_keys($ranks))->get()->keyBy('id');
        return collect(array_keys($ranks))->map(fn($id)=>$cards->get($id))->filter()->values();
    }
    public function preview(?int $id=null)
    {
        $this->content->authorize();abort_unless((int)auth()->user()->school_id===(int)config('school.school_id'),403);
        if($id){$entry=$this->content->read($id);abort_if($entry['deleted_at'],404);$article=(object)array_merge($entry['draft'],['id'=>$entry['id'],'slug'=>$entry['slug'],'status'=>'draft','metadata'=>json_encode($entry['draft']['metadata']),'published_at'=>$entry['published_at']??now()]);return view('website.article',$this->context(true))->with(['article'=>$article,'pageTitle'=>$article->title]);}
        $this->staffPreview=true;$response=$this->home(request());$data=$response->getData();return view('website.home',array_replace($data,$this->context(true),['news'=>$data['news'],'notices'=>$data['notices'],'albums'=>$data['albums'],'events'=>$data['events'],'services'=>$data['services'],'banners'=>$data['banners']]));
    }
    public function contact()
    {
        return view('website.contact',$this->context())->with('pageTitle','ติดต่อโรงเรียน');
    }
    public function directory()
    {
        $counts=$this->query()->select('content_type',DB::raw('count(*) as total'))->groupBy('content_type')->pluck('total','content_type')->all();
        return view('website.directory',$this->context())->with(['pageTitle'=>'ข้อมูลและบริการทั้งหมด','sections'=>\App\Support\WebsiteSections::all(),'sectionCounts'=>$counts]);
    }
    public function receive(Request $r)
    {
        // The route throttle counts one full address; an IPv6 visitor rotates inside its /64 (and a hosting customer inside a /48). Every try counts here, valid or not.
        $k64 = 'portal-contact:'.\App\Support\Saan\NetKey::rate($r->ip());
        $k48 = 'portal-contact-wide:'.\App\Support\Saan\NetKey::wide($r->ip());
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($k64, 5) || \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($k48, 30)) abort(429, 'ส่งข้อความถี่เกินไป กรุณารอสักครู่แล้วลองใหม่');
        \Illuminate\Support\Facades\RateLimiter::hit($k64, 60);
        \Illuminate\Support\Facades\RateLimiter::hit($k48, 3600);
        $d=$r->validate(['name'=>'required|string|max:100','email'=>'required|email|max:254','subject'=>'required|string|max:160','message'=>'required|string|min:10|max:4000','consent'=>'accepted','website'=>'nullable|max:0'],[
            'name.required'=>'กรุณากรอกชื่อ','email.email'=>'กรุณาตรวจสอบอีเมล','subject.required'=>'กรุณากรอกเรื่องที่ติดต่อ','message.min'=>'กรุณาระบุรายละเอียดอย่างน้อย 10 ตัวอักษร','consent.accepted'=>'กรุณายืนยันการส่งข้อมูลเพื่อให้โรงเรียนติดต่อกลับ']);
        // S5: the public edition only seals the message for the internal edition (no plaintext is kept here); combined/internal write cms_messages as before.
        try{$sent=\App\Support\Saan\Inbox::submit('contact',collect($d)->only(['name','email','subject','message'])->all());}
        catch(\App\Support\Saan\Inbox\InboxRejected $e){
            if($r->expectsJson())return response()->json(['message'=>$e->getMessage()],$e->status>=500?503:422);
            // the public edition keeps no plaintext of a visitor, not even in the session store: only the subject is flashed back
            return redirect('/portal/contact')->withInput(\App\Support\Saan\Edition::isPublic()?$r->only(['subject']):$r->input())->withErrors(['message'=>$e->getMessage()]);
        }
        if($sent['mode']==='sealed'){
            if($r->expectsJson())return response()->json(['message'=>'ได้รับข้อความแล้ว','queued'=>true],202);
            return redirect('/portal/contact')->with('contact_sent','ได้รับข้อความแล้ว โรงเรียนจะตอบกลับทางอีเมลที่ท่านให้ไว้');
        }
        $id=$sent['result']['id'];
        if($r->expectsJson())return response()->json(['message'=>'ได้รับข้อความแล้ว','id'=>$id],201);
        return redirect('/portal/contact')->with('contact_sent','ได้รับข้อความแล้ว เลขอ้างอิง '.$id);
    }
    public function search(Request $r)
    {
        $d=$r->validate(['q'=>'nullable|string|max:100']);$q=trim($d['q']??'');
        if($q!=='')$this->throttleSearch($r);
        $posts=$this->card($this->query())->whereNotIn('content_type',['banner','service'])->when($q!=='',fn($query)=>$query->where(fn($query)=>$query->where('title','like','%'.$q.'%')->orWhere('body','like','%'.$q.'%')))->orderByDesc('published_at')->simplePaginate(12)->withQueryString();
        // F4: group hub pages and their documents are not posts; the first page of results lists matching ones above the posts
        $hubHits=($q!==''&&(int)$r->query('page',1)<=1&&class_exists(\Saan\Modules\WebCms\Hubs::class))?\Saan\Modules\WebCms\Hubs::search($q):['hubs'=>[],'documents'=>[]];
        return view('website.collection',$this->context())->with(['posts'=>$posts,'collection'=>'search','pageTitle'=>'ค้นหาข้อมูล','searchQuery'=>$q,'hubHits'=>$hubHits]);
    }
    public function sitemap()
    {
        $urls=[url('/portal'),url('/portal/directory')];if(\Illuminate\Support\Facades\Schema::hasTable('cms_hubs')){$urls[]=url('/portal/groups');foreach(DB::table('cms_hubs')->where('school_id',config('school.school_id'))->where('status','published')->orderBy('id')->pluck('slug')as$hubSlug)$urls[]=url('/portal/groups/'.$hubSlug);}foreach($this->query()->whereNotIn('content_type',['banner','service'])->get(['content_type','slug'])as$post)$urls[]=\App\Support\WebsiteView::url($post);
        $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($urls as$url)$xml.='<url><loc>'.htmlspecialchars($url,ENT_XML1,'UTF-8').'</loc></url>';return response($xml.'</urlset>',200,['Content-Type'=>'application/xml; charset=utf-8']);
    }
}
