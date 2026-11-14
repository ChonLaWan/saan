<?php
namespace App\Domain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CmsPublishing {
    public const PRESETS=[
        'atelier'=>['name'=>'Academic Glass','description'=>'กระจกฝ้าและแสงอุ่น เหมาะกับเรื่องราวการเรียนรู้','accent'=>'#285747','background'=>'#f2f0e5','layout'=>'cards','font'=>'friendly'],
        'sky'=>['name'=>'Clear Campus','description'=>'ขาวฟ้า โปร่ง อ่านประกาศและข่าวได้ชัด','accent'=>'#245a82','background'=>'#edf4f8','layout'=>'list','font'=>'friendly'],
        'clay'=>['name'=>'Warm Community','description'=>'สีดินและกระดาษ เหมาะกับโรงเรียนที่ใกล้ชิดชุมชน','accent'=>'#934c32','background'=>'#f7eee3','layout'=>'cards','font'=>'formal'],
    ];
    public function __construct(private SchoolOperations $ops){}
    public static function buttonInk(string $hex): string {
        $rgb=sscanf($hex,'#%02x%02x%02x');$linear=array_map(function($v){$v=$v/255;return $v<=0.04045?$v/12.92:pow(($v+0.055)/1.055,2.4);},$rgb);
        $l=$linear[0]*0.2126+$linear[1]*0.7152+$linear[2]*0.0722;return 1.05/($l+0.05)>=4.5?'#ffffff':'#000000';
    }
    public function authorize(): void {$this->ops->role(['admin','principal','editor']);}
    public static function lightBackground(string $hex): bool {
        if(!preg_match('/^#[0-9a-f]{6}$/iD',$hex))return false;$rgb=sscanf($hex,'#%02x%02x%02x');$linear=array_map(function($v){$v/=255;return$v<=.04045?$v/12.92:pow(($v+.055)/1.055,2.4);},$rgb);return$linear[0]*.2126+$linear[1]*.7152+$linear[2]*.0722>=.72;
    }
    public function defaults(): array {return ['preset'=>'atelier','school_name'=>'โรงเรียนตัวอย่าง','tagline'=>'พื้นที่เล็ก ๆ สำหรับอนาคตที่ยิ่งใหญ่','hero_title'=>'เรียนรู้ เติบโต\nไปด้วยกัน','hero_description'=>'เรื่องราว ข่าวสาร และโอกาสในการเรียนรู้ของทุกคนในโรงเรียน','accent'=>'#285747','background'=>'#f2f0e5','layout'=>'cards','font'=>'friendly','logo_media_id'=>null,'hero_media_id'=>null,'show_hero'=>true,'show_contact'=>true,'phone'=>'','address'=>'','menus'=>[['label'=>'หน้าหลัก','url'=>'#home'],['label'=>'ข่าวโรงเรียน','url'=>'#news'],['label'=>'ติดต่อ','url'=>'#contact']]];}
    public function theme(int $school): array {
        $row=DB::table('cms_themes')->where('school_id',$school)->first();
        return ['draft'=>array_replace($this->defaults(),$row?json_decode($row->draft,true):[]),'published'=>array_replace($this->defaults(),$row?json_decode($row->published,true):[]),'version'=>$row?->version??0,'published_version'=>$row?->published_version??0,'presets'=>\App\Domain\Cms\ThemeRegistry::all()];
    }
    public function assertMedia(?int $id): void {if($id){$media=$this->ops->record('cms_media',$id);abort_unless(str_starts_with($media->mime,'image/'),422,'กรุณาเลือกไฟล์รูปภาพ');}}
    public function themeSave(array $settings,int $version,bool $publish,?array $fields=null): array {
        $this->authorize();$school=$this->ops->school();
        $this->assertMedia($settings['logo_media_id']??null);$this->assertMedia($settings['hero_media_id']??null);
        return DB::transaction(function()use($settings,$version,$publish,$school,$fields){
            $ids=array_filter([$settings['logo_media_id']??null,$settings['hero_media_id']??null]);sort($ids);foreach($ids as$id)$this->ops->query('cms_media')->where('id',$id)->lockForUpdate()->first()??abort(404);
            // insertOrIgnore establishes a unique row before locking, including the first edit.
            DB::table('cms_themes')->insertOrIgnore(['school_id'=>$school,'draft'=>json_encode($this->defaults()),'published'=>json_encode($this->defaults()),'version'=>0,'published_version'=>0,'created_at'=>now(),'updated_at'=>now()]);
            $row=DB::table('cms_themes')->where('school_id',$school)->lockForUpdate()->first();
            abort_if($row->version!==$version,409,'มีผู้แก้ธีมจากอีกหน้าจอ โปรดโหลดข้อมูลใหม่ก่อนบันทึก');
            $next=$version+1;$changes=['draft'=>json_encode($settings,JSON_UNESCAPED_UNICODE),'version'=>$next,'updated_at'=>now()];
            if($publish){
                // CMS-002: publish only the fields this form shows; never another screen's draft (for example a branding logo draft).
                $previousDraft=array_replace($this->defaults(),json_decode($row->draft,true)?:[]);$previousPublished=array_replace($this->defaults(),json_decode($row->published,true)?:[]);
                $owned=array_flip($fields??[]);
                $changes+=['published'=>json_encode(array_replace($previousPublished,array_intersect_key($settings,$owned)),JSON_UNESCAPED_UNICODE),'published_version'=>$next];
            }
            DB::table('cms_themes')->where('id',$row->id)->update($changes);
            $this->ops->audit($publish?'theme.published':'theme.drafted','cms_themes',$row->id,['version'=>$next,'preset'=>$settings['preset']]);
            return $this->theme($school);
        });
    }
    public function post(int $id) {
        $this->authorize();$row=$this->ops->record('posts',$id);$base=collect($row)->only(['title','body','excerpt','category','cover_media_id','is_pinned'])->all();
        $base['is_pinned']=(bool)$base['is_pinned'];$draft=$row->draft_payload?json_decode($row->draft_payload,true):$base;
        return ['id'=>$row->id,'slug'=>$row->slug,'status'=>$row->status,'version'=>$row->version,'published_at'=>$row->published_at,'draft'=>$draft,'published'=>$row->status==='published'?$base:null,'has_draft'=>$row->draft_payload!==null,'revisions'=>$this->ops->query('cms_post_revisions')->where('post_id',$id)->orderByDesc('id')->limit(5)->get(['id','version','created_at'])];
    }
    public function savePost(array $data,?int $id,int $version,bool $publish): array {
        $this->authorize();$this->assertMedia($data['cover_media_id']??null);
        return DB::transaction(function()use($data,$id,$version,$publish){
            if($data['cover_media_id']??null)$this->ops->query('cms_media')->where('id',$data['cover_media_id'])->lockForUpdate()->first()??abort(404);
            $payload=collect($data)->only(['title','body','excerpt','category','cover_media_id','is_pinned'])->all();
            $payload+=['excerpt'=>'','category'=>'news','cover_media_id'=>null,'is_pinned'=>false];
            if($payload['excerpt']==='')$payload['excerpt']=Str::limit(preg_replace('/\s+/u',' ',$payload['body']),180,'…');
            if($id){
                $row=$this->ops->query('posts')->where('id',$id)->lockForUpdate()->first()??abort(404);
                abort_if($row->version!==$version,409,'ข่าวนี้มีการแก้ไขแล้ว ร่างของคุณยังอยู่ในหน้าจอ โปรดโหลดข้อมูลล่าสุดก่อนบันทึก');
                // CMS-003: this quick editor only understands plain articles. Typed content, trash and scheduled items
                // must go through the full content editor so metadata, schedules and trash rules are kept.
                abort_if($row->deleted_at!==null,409,'รายการนี้อยู่ในถังขยะ กู้คืนก่อนแก้ไข');
                abort_unless(($row->content_type??'article')==='article',422,'รายการประเภทนี้แก้ได้จากหน้าจัดการเนื้อหาเท่านั้น');
                abort_if($row->scheduled_at!==null,409,'รายการนี้ตั้งเวลาเผยแพร่ไว้ แก้หรือยกเลิกเวลาจากหน้าจัดการเนื้อหา');
                $next=$row->version+1;
            }else{
                $id=DB::table('posts')->insertGetId($payload+['school_id'=>$this->ops->school(),'author_id'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]);
                $slug=Str::slug($data['title']);if($slug==='')$slug='news';
                $slug=Str::limit($slug,140,'').'-'.$id;
                $this->ops->query('posts')->where('id',$id)->update(['slug'=>$slug]);
                $row=$this->ops->record('posts',$id);$next=1;
            }
            $changes=['version'=>$next,'updated_at'=>now(),'draft_payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE)];
            if($row->status==='draft')$changes=array_merge($changes,$payload);
            if($publish){
                $changes=array_merge($changes,$payload,['status'=>'published','draft_payload'=>null,'published_at'=>$row->published_at??now(),'scheduled_at'=>null,'scheduled_by'=>null]);
                DB::table('cms_post_revisions')->insert(['school_id'=>$this->ops->school(),'post_id'=>$id,'version'=>$next,'snapshot'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'published_by'=>auth()->id(),'created_at'=>now()]);
            }
            $this->ops->query('posts')->where('id',$id)->update($changes);
            $this->ops->audit($publish?'post.published':'post.drafted','posts',$id,['version'=>$next]);
            return $this->post($id);
        });
    }
}
