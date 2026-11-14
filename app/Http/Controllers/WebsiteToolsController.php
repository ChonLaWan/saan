<?php
namespace App\Http\Controllers;
use App\Domain\Cms\ContentTypes;
use App\Domain\Cms\Redirects;
use App\Domain\Cms\SafeContent;
use App\Domain\Cms\SiteSettings;
use App\Domain\Cms\WebsiteContent;
use App\Domain\SchoolOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WebsiteToolsController extends Controller
{
    public function __construct(private WebsiteContent $content,private SchoolOperations $ops,private SiteSettings $settings){}
    public function redirects(){$this->content->authorize();return response()->json($this->ops->query('cms_redirects')->orderByDesc('id')->paginate(30));}
    public function deployment(){$this->ops->role(['admin']);return response()->json(app(\App\Domain\Cms\DeploymentHealth::class)->inspect());}
    public function saveRedirect(Request $r,?int $id=null)
    {
        $this->content->authorize();$d=$r->validate(['source_path'=>'required|string|max:255','target_path'=>['required','string','max:1000',fn($a,$v,$fail)=>((str_starts_with($v,'/')||str_starts_with($v,'https://'))&&SafeContent::url($v))?:$fail('ปลายทางต้องเป็นเส้นทางในเว็บหรือ https')],'status_code'=>'required|integer|in:301,302','is_active'=>'required|boolean','version'=>$id?'required|integer|min:1':'sometimes|integer']);
        $source=Redirects::normalize($d['source_path']);abort_unless($source,422,'เส้นทางต้นทางไม่ถูกต้อง');abort_if(preg_match('~^/(?:api|cms|admin|account|login|logout|demo-login|media|theme-preview|content-preview|hero-preview|up)(?:[/?]|$)~',$source),422,'ไม่อนุญาต redirect เส้นทางระบบหรือบัญชี');$d['source_path']=$source;
        $r->merge(['source_path'=>$source]);$r->validate(['source_path'=>Rule::unique('cms_redirects')->where('school_id',$this->ops->school())->ignore($id)]);
        $saved=DB::transaction(function()use($d,$id){$this->ops->query('users')->where('role','admin')->orderBy('id')->lockForUpdate()->first();if($id){$row=$this->ops->query('cms_redirects')->where('id',$id)->lockForUpdate()->first()??abort(404);abort_if((int)$row->version!==(int)$d['version'],409);}$payload=collect($d)->only(['source_path','target_path','status_code','is_active'])->all();Redirects::assertNoCycle($this->ops->school(),$payload,$id);
            if($id)$this->ops->query('cms_redirects')->where('id',$id)->update($payload+['version'=>$row->version+1,'updated_at'=>now()]);else$id=DB::table('cms_redirects')->insertGetId($payload+['school_id'=>$this->ops->school(),'created_at'=>now(),'updated_at'=>now()]);$this->ops->audit('redirect.saved','cms_redirects',$id);return$this->ops->record('cms_redirects',$id);});return response()->json($saved,$id?200:201);
    }
    public function links()
    {
        $this->content->authorize();$settings=$this->settings->read($this->ops->school())['draft'];$known=['/'=>true,'/portal'=>true,'/portal/contact'=>true,'/portal/search'=>true,'/portal/directory'=>true];foreach(ContentTypes::TYPES as$type)$known['/portal/'.$type['route']]=true;
        foreach($this->ops->query('posts')->whereNull('deleted_at')->where('status','published')->get(['content_type','slug'])as$post)$known['/portal/'.ContentTypes::TYPES[$post->content_type]['route'].'/'.$post->slug]=true;
        foreach($this->ops->query('cms_redirects')->where('is_active',true)->pluck('source_path')as$source)$known[$source]=true;
        $links=[];$walk=function($items,$location)use(&$walk,&$links){foreach($items as$item){$links[]=['label'=>$item['label'],'url'=>$item['url'],'location'=>$location];$walk($item['children']??[],$location);}};foreach(['navigation'=>'เมนูหลัก','footer_navigation'=>'เมนูส่วนท้าย','utility_navigation'=>'เมนูแถบบน']as$key=>$label)$walk($settings[$key],$label);
        $this->ops->query('posts')->whereNull('deleted_at')->select(['id','title','metadata','draft_payload','body'])->chunkById(200,function($chunk)use(&$links){foreach($chunk as$post){$draft=$post->draft_payload?json_decode($post->draft_payload,true):[];$meta=$draft['metadata']??json_decode($post->metadata??'{}',true);foreach(['url','secondary_url','source_url']as$key)if(!empty($meta[$key]))$links[]=['label'=>$draft['title']??$post->title,'url'=>$meta[$key],'location'=>'เนื้อหา'];$body=$draft['body']??$post->body;preg_match_all('/href=["\']([^"\']+)["\']/i',$body,$matches);foreach($matches[1]as$url)$links[]=['label'=>$draft['title']??$post->title,'url'=>html_entity_decode($url,ENT_QUOTES,'UTF-8'),'location'=>'ลิงก์ในเนื้อหา'];}});
        foreach($links as&$link){$local=Redirects::targetSource($link['url']);if(str_starts_with($link['url'],'#'))$link['status']='anchor';elseif($local===null)$link['status']='external';else{$path=explode('?',$local,2)[0];$link['status']=isset($known[$local])||isset($known[$path])?'ok':'missing';}}unset($link);return response()->json(['data'=>$links,'note'=>'ตรวจเส้นทางภายในและสถานะเผยแพร่ ลิงก์ภายนอกแสดงแยกโดยไม่ส่ง request ออกไป']);
    }
}
