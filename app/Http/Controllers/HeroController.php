<?php
namespace App\Http\Controllers;
use App\Domain\Cms\WebsiteContent;
use App\Domain\SchoolOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HeroController extends Controller
{
    public function __construct(private WebsiteContent $content,private SchoolOperations $ops){}
    private function query(){return $this->ops->query('posts')->whereNull('deleted_at')->where('content_type','banner')->where(fn($q)=>$q->where('metadata->placement','hero')->orWhere('draft_payload->metadata->placement','hero'));}
    public function index(){$this->content->authorize();return response()->json(['data'=>\App\Support\JsonSort::numeric($this->query())->orderBy('id')->get(['id'])->map(fn($row)=>$this->content->read($row->id))]);}
    public function order(Request $r)
    {
        $this->content->authorize();$d=$r->validate(['items'=>'required|array|min:1|max:'.WebsiteContent::HERO_LIMIT,'items.*'=>'array:id,version','items.*.id'=>'required|integer|distinct','items.*.version'=>'required|integer|min:1']);
        DB::transaction(function()use($d){
            $rows=$this->query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');$expected=$rows->keys()->sort()->values()->all();$given=collect($d['items'])->pluck('id')->map(fn($id)=>(int)$id)->sort()->values()->all();abort_unless($expected===$given,409,'รายการสไลด์เปลี่ยนแล้ว กรุณาเปิดใหม่');
            foreach($d['items']as$position=>$item){$row=$rows[$item['id']];abort_if((int)$row->version!==(int)$item['version'],409,'สไลด์มีการแก้ไข กรุณาโหลดข้อมูลใหม่');$meta=json_decode($row->metadata??'{}',true);$meta['sort_order']=$position;$draft=$row->draft_payload?json_decode($row->draft_payload,true):null;if($draft)$draft['metadata']['sort_order']=$position;
                $this->ops->query('posts')->where('id',$row->id)->update(['metadata'=>json_encode($meta,JSON_UNESCAPED_UNICODE),'draft_payload'=>$draft?json_encode($draft,JSON_UNESCAPED_UNICODE):null,'version'=>$row->version+1,'updated_at'=>now()]);
                if($row->status==='published'){$snapshot=$this->content->read($row->id)['published'];DB::table('cms_post_revisions')->insert(['school_id'=>$this->ops->school(),'post_id'=>$row->id,'version'=>$row->version+1,'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'published_by'=>auth()->id(),'created_at'=>now()]);}
            }
            $this->ops->audit('hero.reordered','posts',null,['ids'=>array_column($d['items'],'id')]);
        });return $this->index();
    }
}
