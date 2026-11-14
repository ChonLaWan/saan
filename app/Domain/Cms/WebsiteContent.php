<?php

namespace App\Domain\Cms;

use App\Domain\CmsPublishing;
use App\Domain\SchoolOperations;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebsiteContent
{
    public const HERO_LIMIT=40;
    public function __construct(private SchoolOperations $ops,private CmsPublishing $publishing) {}
    public function authorize(): void {$this->publishing->authorize();}

    public function read(int $id): array
    {
        $this->authorize();$row=$this->ops->record('posts',$id);
        $base=collect($row)->only(['title','body','excerpt','category','cover_media_id','is_pinned','content_type'])->all();
        $base['is_pinned']=(bool)$base['is_pinned'];$base['metadata']=$row->metadata?json_decode($row->metadata,true):[];
        $draft=$row->draft_payload?array_replace($base,json_decode($row->draft_payload,true)):$base;
        foreach (['base','draft'] as $key) if ((${$key}['metadata']['body_format']??'plain')==='html') ${$key}['body']=SafeContent::html(${$key}['body']);
        return ['id'=>$row->id,'slug'=>$row->slug,'status'=>$row->status,'version'=>$row->version,'deleted_at'=>$row->deleted_at,'published_at'=>$row->published_at,'scheduled_at'=>$row->scheduled_at,
            'draft'=>$draft,'published'=>$row->status==='published'?$base:null,'has_draft'=>$row->draft_payload!==null,
            'media'=>$this->ops->query('cms_media')->whereIn('id',$this->mediaReferences($draft))->get(['id','alt','original_name','mime','bytes']),
            'revisions'=>$this->ops->query('cms_post_revisions')->where('post_id',$id)->orderByDesc('id')->limit(20)->get(['id','version','created_at'])];
    }

    /** CMS-004: /media/{id} links written inside rich text (relative, or absolute to this site) count as references too. */
    public function bodyMediaReferences(?string $body): array
    {
        if(!$body||!str_contains($body,'/media/'))return [];
        // Accept relative links (with or without the install sub-path) and absolute links to this host over http or https.
        $root=parse_url(url('/'));$host=preg_quote(($root['host']??'localhost').(isset($root['port'])?':'.$root['port']:''),'~');$path=preg_quote(rtrim($root['path']??'','/'),'~');
        preg_match_all('~(?:href|src)\s*=\s*["\']?(?:https?://'.$host.')?(?:'.$path.')?/media/(\d+)(?=["\'\s>?#/]|$)~i',$body,$m);
        return array_values(array_unique(array_map('intval',$m[1])));
    }

    public function allMediaReferences(array $data): array
    {
        return array_values(array_unique(array_map('intval',array_merge($this->mediaReferences($data),$this->bodyMediaReferences($data['body']??null)))));
    }

    public function mediaReferences(array $data): array
    {
        return array_values(array_unique(array_filter(array_merge([$data['cover_media_id']??null,$data['metadata']['mobile_media_id']??null,$data['metadata']['video_media_id']??null],$data['metadata']['attachments']??[],$data['metadata']['gallery']??[]))));
    }

    public function save(array $data,?int $id=null): array
    {
        $this->authorize();
        foreach ($this->mediaReferences($data) as $mediaId) $this->ops->record('cms_media',(int)$mediaId);
        foreach (array_merge([$data['cover_media_id']??null,$data['metadata']['mobile_media_id']??null],$data['metadata']['gallery']??[]) as $imageId) {
            if ($imageId) abort_unless(str_starts_with($this->ops->record('cms_media',(int)$imageId)->mime,'image/'),422,'รูปปกและอัลบั้มต้องเป็นรูปภาพ');
        }
        if (!empty($data['metadata']['video_media_id'])) {
            abort_unless(($data['content_type']??null)==='video' && $this->ops->record('cms_media',(int)$data['metadata']['video_media_id'])->mime==='video/mp4',422,'ไฟล์วีดิทัศน์ต้องเป็น MP4 และใช้ในหมวดวีดิทัศน์เท่านั้น');
        }
        if (($data['metadata']['source_kind']??'mock')==='reference') {
            abort_unless(!empty($data['metadata']['source_url'])&&!empty($data['metadata']['source_school']),422,'รายการอ้างอิงต้องระบุโรงเรียนและลิงก์ต้นฉบับ');
        }
        $payload=collect($data)->only(['title','body','excerpt','category','cover_media_id','is_pinned','content_type','metadata'])->all();
        $payload+=['excerpt'=>'','cover_media_id'=>null,'is_pinned'=>false,'metadata'=>[]];
        if (($payload['metadata']['body_format']??'plain')==='html') $payload['body']=SafeContent::html($payload['body']);
        if (!$payload['excerpt']) $payload['excerpt']=Str::limit(trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($payload['body']),ENT_QUOTES,'UTF-8'))),180,'…');
        $intent=$data['intent'];$scheduled=null;
        if ($intent==='schedule') {
            $scheduled=CarbonImmutable::parse($data['scheduled_at'],'Asia/Bangkok')->utc();
            abort_unless($scheduled->isFuture(),422,'เวลาเผยแพร่ต้องอยู่ในอนาคต');
        }
        return DB::transaction(function()use($payload,$data,$id,$intent,$scheduled){
            $mediaIds=$this->mediaReferences($payload);sort($mediaIds);foreach($mediaIds as$mediaId)$this->ops->query('cms_media')->where('id',$mediaId)->lockForUpdate()->first()??abort(404);
            $this->ops->query('cms_categories')->where('slug',$payload['category'])->lockForUpdate()->first()??abort(422,'ไม่พบหมวดเนื้อหา กรุณาเลือกหมวดใหม่');
            // CMS-007: create/index/reorder share one limit for hero slides.
            if(($payload['content_type']??'')==='banner'&&($payload['metadata']['placement']??null)==='hero'){
                $heroes=$this->ops->query('posts')->whereNull('deleted_at')->where('content_type','banner')->where(fn($q)=>$q->where('metadata->placement','hero')->orWhere('draft_payload->metadata->placement','hero'))->when($id,fn($q)=>$q->where('id','!=',$id))->lockForUpdate()->count();
                abort_if($heroes>=self::HERO_LIMIT,422,'สไลด์หน้าแรกมีได้สูงสุด '.self::HERO_LIMIT.' รายการ ย้ายสไลด์เก่าไปถังขยะก่อนเพิ่มใหม่');
            }
            if ($id) {
                $row=$this->ops->query('posts')->where('id',$id)->lockForUpdate()->first()??abort(404);
                abort_if($row->deleted_at,409,'รายการอยู่ในถังขยะ กรุณาคืนเป็นร่างก่อน');
                abort_if((int)$row->version!==(int)$data['version'],409,'มีการแก้ไขจากอีกหน้าจอ กรุณาเปิดข้อมูลล่าสุด');
                $version=$row->version+1;
            } else {
                $base=$payload;$base['metadata']=json_encode($payload['metadata'],JSON_UNESCAPED_UNICODE);
                $id=DB::table('posts')->insertGetId($base+['school_id'=>$this->ops->school(),'author_id'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]);
                $slug=Str::slug($data['slug']??$payload['title']);if($slug==='')$slug=$payload['content_type'];
                DB::table('posts')->where('id',$id)->update(['slug'=>Str::limit($slug,140,'').'-'.$id]);
                $row=$this->ops->record('posts',$id);$version=1;
            }
            $changes=['version'=>$version,'updated_at'=>now(),'draft_payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'scheduled_at'=>$scheduled,'scheduled_by'=>$scheduled?auth()->id():null];
            if ($row->status!=='published') {
                $copy=$payload;$copy['metadata']=json_encode($payload['metadata'],JSON_UNESCAPED_UNICODE);
                $changes=array_merge($changes,$copy,['status'=>$scheduled?'scheduled':'draft']);
            }
            if ($intent==='publish') {
                $copy=$payload;$copy['metadata']=json_encode($payload['metadata'],JSON_UNESCAPED_UNICODE);
                $changes=array_merge($changes,$copy,['status'=>'published','draft_payload'=>null,'published_at'=>$row->published_at??now(),'scheduled_at'=>null,'scheduled_by'=>null]);
                $this->revision($id,$version,$payload,auth()->id());
            }
            $this->ops->query('posts')->where('id',$id)->update($changes);
            $this->ops->audit('content.'.$intent,'posts',$id,['version'=>$version,'type'=>$payload['content_type']]);
            return $this->read($id);
        });
    }

    private function revision(int $id,int $version,array $payload,int $actor): void
    {
        DB::table('cms_post_revisions')->insert(['school_id'=>$this->ops->school(),'post_id'=>$id,'version'=>$version,'snapshot'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'published_by'=>$actor,'created_at'=>now()]);
    }

    public function action(int $id,int $version,string $action,?int $revisionId=null): array
    {
        $this->authorize();
        return DB::transaction(function()use($id,$version,$action,$revisionId){
            $row=$this->ops->query('posts')->where('id',$id)->lockForUpdate()->first()??abort(404);
            abort_if((int)$row->version!==$version,409,'ข้อมูลเปลี่ยนแล้ว กรุณาเปิดใหม่');
            if ($action==='revision') {
                abort_if($row->deleted_at,409);$revision=$this->ops->query('cms_post_revisions')->where('post_id',$id)->where('id',$revisionId)->first()??abort(404);
                $snapshot=array_replace($this->read($id)['draft'],json_decode($revision->snapshot,true));
                $result=$this->save($snapshot+['intent'=>'draft','version'=>$version],$id);
                $this->ops->audit('content.revision_restored','posts',$id,['revision_id'=>$revisionId]);return $result;
            }
            $changes=['version'=>$version+1,'updated_at'=>now(),'scheduled_at'=>null,'scheduled_by'=>null];
            if ($action==='trash') {abort_if($row->deleted_at,409);$changes['deleted_at']=now();}
            if ($action==='restore') {abort_unless($row->deleted_at,409);$changes+=['deleted_at'=>null,'status'=>'draft'];}
            if ($action==='unpublish') {abort_if($row->deleted_at,409);$changes['status']='draft';}
            $this->ops->query('posts')->where('id',$id)->update($changes);$this->ops->audit('content.'.$action,'posts',$id);return $this->read($id);
        });
    }

    public function publishDue(?int $school=null): int
    {
        $count=0;
        // scheduled_at is explicitly stored in UTC; other legacy app timestamps use Bangkok.
        foreach (DB::table('posts')->when($school!==null,fn($q)=>$q->where('school_id',$school))->whereNull('deleted_at')->whereNotNull('scheduled_at')->where('scheduled_at','<=',now('UTC'))->pluck('id') as $id) {
            $count+=DB::transaction(function()use($id){
                $row=DB::table('posts')->where('id',$id)->lockForUpdate()->first();
                if (!$row||$row->deleted_at||!$row->scheduled_at||CarbonImmutable::parse($row->scheduled_at,'UTC')->isFuture())return 0;
                $actor=User::where('id',$row->scheduled_by)->lockForUpdate()->first();
                if (!$actor||!$actor->is_active||(int)$actor->school_id!==(int)$row->school_id||($actor->is_demo&&!config('school.demo'))||!in_array($actor->role,['admin','principal','editor'])) {
                    DB::table('posts')->where('id',$id)->update(['scheduled_at'=>null,'scheduled_by'=>null,'status'=>$row->status==='scheduled'?'draft':$row->status,'version'=>$row->version+1]);return 0;
                }
                $payload=json_decode($row->draft_payload??'{}',true);
                // CMS-005: a due schedule without a draft can never run; clear it and leave an audit trail instead of retrying forever.
                if(!$payload){DB::table('posts')->where('id',$id)->update(['scheduled_at'=>null,'scheduled_by'=>null,'status'=>$row->status==='scheduled'?'draft':$row->status,'version'=>$row->version+1,'updated_at'=>now()]);DB::table('audit_events')->insert(['school_id'=>$row->school_id,'actor_id'=>$row->scheduled_by??$row->author_id,'action'=>'content.schedule_invalid_cleared','entity'=>'posts','entity_id'=>$id,'detail'=>json_encode(['reason'=>'missing_draft','version'=>$row->version+1]),'created_at'=>now()]);return 0;}
                $copy=$payload;$copy['metadata']=json_encode($payload['metadata']??[],JSON_UNESCAPED_UNICODE);
                DB::table('posts')->where('id',$id)->update($copy+['status'=>'published','draft_payload'=>null,'scheduled_at'=>null,'scheduled_by'=>null,'published_at'=>$row->published_at??now(),'updated_at'=>now(),'version'=>$row->version+1]);
                DB::table('cms_post_revisions')->insert(['school_id'=>$row->school_id,'post_id'=>$id,'version'=>$row->version+1,'snapshot'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'published_by'=>$actor->id,'created_at'=>now()]);
                DB::table('audit_events')->insert(['school_id'=>$row->school_id,'actor_id'=>$actor->id,'action'=>'content.scheduled_published','entity'=>'posts','entity_id'=>$id,'detail'=>json_encode(['trigger'=>'scheduler']),'created_at'=>now()]);return 1;
            });
        }
        return $count;
    }
}
