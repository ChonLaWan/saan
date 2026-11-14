<?php
namespace App\Http\Controllers;
use App\Domain\CmsPublishing;
use App\Domain\SchoolOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CmsController extends Controller {
    public const THEME_FIELDS=['preset','school_name','tagline','hero_title','hero_description','accent','background','layout','font','logo_media_id','hero_media_id','show_hero','show_contact','phone','address','menus'];
    public function __construct(private CmsPublishing $cms,private SchoolOperations $ops){}
    public function index(){
        $this->cms->authorize();return view('cms',['boot'=>['base'=>url('/'),'user'=>['id'=>auth()->id(),'school_id'=>auth()->user()->school_id,'name'=>auth()->user()->name,'role'=>auth()->user()->role]]]);
    }
    public function state(Request $r){
        $this->cms->authorize();
        // CORE-PERF: never ship the whole archive; list the newest PER_PAGE posts (?page=N for more), without the long body column
        $per=min(200,max(1,(int)$r->query('per_page',100)));$page=max(1,(int)$r->query('page',1));
        $base=$this->ops->query('posts')->whereNull('deleted_at');$total=(clone $base)->count();
        $posts=$base->orderByDesc('updated_at')->orderByDesc('id')->forPage($page,$per)->get(['id','title','excerpt','category','cover_media_id','is_pinned','status','draft_payload','slug','version','updated_at','published_at'])->map(function($p){$draft=$p->draft_payload?json_decode($p->draft_payload,true):null;return ['id'=>$p->id,'title'=>$draft['title']??$p->title,'excerpt'=>$draft['excerpt']??$p->excerpt,'category'=>$draft['category']??$p->category,'cover_media_id'=>$draft?$draft['cover_media_id']:$p->cover_media_id,'is_pinned'=>(bool)($draft['is_pinned']??$p->is_pinned),'status'=>$p->status,'has_draft'=>$p->draft_payload!==null,'slug'=>$p->slug,'version'=>$p->version,'updated_at'=>$p->updated_at,'published_at'=>$p->published_at];});
        return response()->json(['theme'=>$this->cms->theme($this->ops->school()),'posts'=>$posts,'posts_total'=>$total,'page'=>$page,'per_page'=>$per,'media'=>$this->ops->query('cms_media')->where('mime','like','image/%')->orderByDesc('id')->limit(80)->get(['id','alt','width','height','created_at'])]);
    }
    public function theme(Request $r){
        $this->cms->authorize();
        $d=$r->validate([
            'version'=>'required|integer|min:0','intent'=>['required',Rule::in(['draft','publish'])],
            'fields'=>'required_if:intent,publish|array|min:1|max:20','fields.*'=>['string',Rule::in(self::THEME_FIELDS)],
            'settings'=>'required|array:preset,school_name,tagline,hero_title,hero_description,accent,background,layout,font,logo_media_id,hero_media_id,show_hero,show_contact,phone,address,menus',
            'settings.preset'=>['required',Rule::in(array_keys(\App\Domain\Cms\ThemeRegistry::all()))],
            'settings.school_name'=>'required|string|max:140','settings.tagline'=>'present|nullable|string|max:160',
            'settings.hero_title'=>'required|string|max:200','settings.hero_description'=>'present|nullable|string|max:500',
            'settings.accent'=>['required','regex:/^#[0-9a-fA-F]{6}$/D'],'settings.background'=>['required','regex:/^#[0-9a-fA-F]{6}$/D',fn($a,$v,$fail)=>CmsPublishing::lightBackground($v)?:$fail('เลือกสีพื้นหลังอ่อนเพื่อให้ข้อความอ่านชัด หากต้องการพื้นเข้มให้เลือกโหมดมืด')],
            'settings.layout'=>['required',Rule::in(['cards','list'])],'settings.font'=>['required',Rule::in(['friendly','formal'])],
            'settings.logo_media_id'=>'present|nullable|integer|min:1','settings.hero_media_id'=>'present|nullable|integer|min:1',
            'settings.show_hero'=>'required|boolean','settings.show_contact'=>'required|boolean',
            'settings.phone'=>'present|nullable|string|max:50','settings.address'=>'present|nullable|string|max:500',
            'settings.menus'=>'required|array|min:1|max:6','settings.menus.*'=>'required|array:label,url',
            'settings.menus.*.label'=>'required|string|max:60',
            'settings.menus.*.url'=>['required','string','max:500',function($attribute,$value,$fail){if(!preg_match('/^#[a-zA-Z][a-zA-Z0-9_-]*$/D',$value)&&!preg_match('~^/(?!/)[^\s]*$~D',$value)&&!(str_starts_with($value,'https://')&&filter_var($value,FILTER_VALIDATE_URL)))$fail('ลิงก์เมนูต้องเป็นหัวข้อในหน้า เส้นทางเว็บไซต์ หรือ https://');}],
        ]);
        $s=$d['settings'];foreach(['tagline','hero_description','phone','address']as$key)$s[$key]=$s[$key]??'';
        return response()->json($this->cms->themeSave($s,$d['version'],$d['intent']==='publish',$d['fields']??null));
    }
    public function getPost(int $id){return response()->json($this->cms->post($id));}
    public function savePost(Request $r,?int $id=null){
        $this->cms->authorize();$d=$r->validate(['title'=>'required|string|max:180','body'=>'required|string|max:30000','excerpt'=>'nullable|string|max:300','category'=>['sometimes',Rule::in(['news','activity','admission','procurement'])],'cover_media_id'=>'nullable|integer|min:1','is_pinned'=>'sometimes|boolean','version'=>$id?'required|integer|min:1':'sometimes|integer','intent'=>['required',Rule::in(['draft','publish'])]]);
        $d['excerpt']=$d['excerpt']??'';return response()->json($this->cms->savePost($d,$id,$d['version']??0,$d['intent']==='publish'),$id?200:201);
    }
    public function upload(Request $r){
        $this->cms->authorize();$d=$r->validate(['image'=>'required|file|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=6000,max_height=6000','alt'=>'nullable|string|max:200']);
        $file=$d['image'];\App\Support\StorageQuota::assertRoom(app(\App\Domain\SchoolOperations::class)->school(),(int)$file->getSize());$mime=$file->getMimeType();$extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??abort(422,'ชนิดภาพไม่รองรับ');
        [$width,$height]=getimagesize($file->getRealPath());
        $path=$file->storeAs('cms/'.$this->ops->school(),Str::uuid().'.'.$extension,'local');abort_unless($path,500,'บันทึกรูปไม่สำเร็จ');
        try{$id=DB::transaction(function()use($file,$path,$mime,$width,$height,$d){$id=DB::table('cms_media')->insertGetId(['school_id'=>$this->ops->school(),'uploaded_by'=>auth()->id(),'path'=>$path,'mime'=>$mime,'bytes'=>$file->getSize(),'width'=>$width,'height'=>$height,'alt'=>$d['alt']??'','created_at'=>now(),'updated_at'=>now()]);$this->ops->audit('media.uploaded','cms_media',$id);return $id;});}
        catch(\Throwable $e){Storage::disk('local')->delete($path);throw $e;}
        return response()->json(['id'=>$id,'url'=>url('/media/'.$id),'width'=>$width,'height'=>$height,'alt'=>$d['alt']??''],201);
    }
    public function media(int $id){
        $m=DB::table('cms_media')->where('id',$id)->first()??abort(404);
        $viewer=auth()->check()?\App\Models\User::find(auth()->id()):null;
        $editor=$viewer&&$viewer->is_active&&(int)$viewer->school_id===(int)$m->school_id&&in_array($viewer->role,['admin','principal','editor'])&&(!$viewer->is_demo||config('school.demo'))&&request()->session()->get('auth_version')!==null&&(int)request()->session()->get('auth_version')===(int)$viewer->auth_version;
        $public=false;
        if($m->school_id===(int)config('school.school_id')){
            $theme=$this->cms->theme($m->school_id)['published'];
            $public=in_array($id,[(int)($theme['logo_media_id']??0),(int)($theme['hero_media_id']??0)],true)||DB::table('posts')->where('school_id',$m->school_id)->where('status','published')->where('cover_media_id',$id)->exists();
        }
        abort_unless($editor||$public,404);$disk=Storage::disk('local');abort_unless($disk->exists($m->path),404);
        return response()->file($disk->path($m->path),['Content-Type'=>$m->mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }
    private function publicTheme(bool $preview=false): array {
        return $this->cms->theme($preview?$this->ops->school():(int)config('school.school_id'))[$preview?'draft':'published'];
    }
    public function portal(Request $r){
        $category=$r->query('category');abort_if($category!==null&&(!is_string($category)||!in_array($category,['news','activity','admission','procurement'])),404);
        $posts=DB::table('posts')->where('school_id',config('school.school_id'))->where('status','published')->when($category,fn($q)=>$q->where('category',$category))->orderByDesc('is_pinned')->orderByDesc('published_at')->paginate(12)->withQueryString();
        return view('portal',['theme'=>$this->publicTheme(),'posts'=>$posts,'preview'=>false,'article'=>null]);
    }
    public function themePreview(){
        $this->cms->authorize();$posts=$this->ops->query('posts')->where('status','published')->orderByDesc('is_pinned')->orderByDesc('published_at')->limit(6)->get();
        return view('portal',['theme'=>$this->publicTheme(true),'posts'=>$posts,'preview'=>true,'article'=>null]);
    }
    public function article(string $slug){
        $article=DB::table('posts')->where('school_id',config('school.school_id'))->where('status','published')->where('slug',$slug)->first()??abort(404);
        return view('portal',['theme'=>$this->publicTheme(),'posts'=>collect(),'preview'=>false,'article'=>$article]);
    }
}
