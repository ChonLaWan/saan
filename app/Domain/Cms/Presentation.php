<?php
namespace App\Domain\Cms;

use App\Domain\{CmsPublishing,SchoolOperations};
use Illuminate\Support\Facades\DB;

class Presentation
{
    public function __construct(private SiteSettings $site,private CmsPublishing $themes,private SchoolOperations $ops){}
    public function read():array
    {
        $this->themes->authorize();return['schema'=>PresentationSchema::all(),'settings'=>$this->site->read($this->ops->school()),'theme'=>$this->themes->theme($this->ops->school())];
    }
    public function assertImages(array $data):void
    {
        $this->themes->authorize();$ids=array_merge(PresentationSchema::media(['branding'=>$data['section']==='branding'?$data['values']:[]]),array_filter([$data['theme']['logo_media_id']??null,$data['theme']['hero_media_id']??null]));
        foreach($ids as$id)abort_unless(str_starts_with($this->ops->record('cms_media',(int)$id)->mime,'image/'),422,'รูปโลโก้และ hero ต้องเป็นไฟล์ภาพ');
    }
    public function save(array $data):array
    {
        $this->assertImages($data);$section=PresentationSchema::section($data['section']);$school=$this->ops->school();$publish=$data['intent']==='publish';
        return DB::transaction(function()use($data,$section,$school,$publish){
            $ids=array_merge(PresentationSchema::media(['branding'=>$data['section']==='branding'?$data['values']:[]]),array_filter([$data['theme']['logo_media_id']??null,$data['theme']['hero_media_id']??null]));sort($ids);foreach(array_unique($ids)as$id)$this->ops->query('cms_media')->where('id',$id)->lockForUpdate()->first()??abort(404);
            foreach(['cms_site_settings'=>SiteSettings::defaults(),'cms_themes'=>$this->themes->defaults()]as$table=>$defaults)DB::table($table)->insertOrIgnore(['school_id'=>$school,'draft'=>json_encode($defaults),'published'=>json_encode($defaults),'version'=>0,'published_version'=>0,'created_at'=>now(),'updated_at'=>now()]);
            $site=DB::table('cms_site_settings')->where('school_id',$school)->lockForUpdate()->first();$theme=DB::table('cms_themes')->where('school_id',$school)->lockForUpdate()->first();
            abort_if((int)$site->version!==(int)$data['settings_version']||(int)$theme->version!==(int)$data['theme_version'],409,'มีการแก้ไขการตั้งค่าแล้ว กรุณาโหลดข้อมูลล่าสุดก่อนบันทึก');
            $draft=PresentationSchema::merge(array_replace(SiteSettings::defaults(),json_decode($site->draft,true)));$published=PresentationSchema::merge(array_replace(SiteSettings::defaults(),json_decode($site->published,true)));
            $key=$section['storage'];$draft[$key]=array_replace($draft[$key],$data['values']);$draft=array_replace($draft,$data['shared']??[]);
            $changes=['draft'=>json_encode($draft,JSON_UNESCAPED_UNICODE),'version'=>$site->version+1,'updated_at'=>now()];
            // Publish this section only. Other unfinished site/theme drafts must remain private.
            if($publish){$published[$key]=array_replace($published[$key],$data['values']);$published=array_replace($published,$data['shared']??[]);$changes+=['published'=>json_encode($published,JSON_UNESCAPED_UNICODE),'published_version'=>$site->version+1];}
            DB::table('cms_site_settings')->where('id',$site->id)->update($changes);
            if($patch=$data['theme']??[]){$td=array_replace($this->themes->defaults(),json_decode($theme->draft,true),$patch);$tc=['draft'=>json_encode($td,JSON_UNESCAPED_UNICODE),'version'=>$theme->version+1,'updated_at'=>now()];if($publish)$tc+=['published'=>json_encode(array_replace($this->themes->defaults(),json_decode($theme->published,true),$patch),JSON_UNESCAPED_UNICODE),'published_version'=>$theme->version+1];DB::table('cms_themes')->where('id',$theme->id)->update($tc);}
            $this->ops->audit($publish?'presentation.published':'presentation.drafted','cms_site_settings',$site->id,['section'=>$data['section'],'version'=>$site->version+1,'theme_keys'=>array_keys($data['theme']??[])]);
            return$this->read();
        });
    }
    public function previewData(array $data):array
    {
        $this->assertImages($data);$state=$this->read();$site=$state['settings']['published'];$theme=$state['theme']['published'];$key=PresentationSchema::section($data['section'])['storage'];
        $site[$key]=$state['settings']['draft'][$key];
        $site[$key]=array_replace($site[$key],$data['values']);$site=array_replace($site,$data['shared']??[]);$theme=array_replace($theme,$data['theme']??[]);$site['observance']['mode']='off';
        return['site'=>$site,'theme'=>$theme];
    }
}
