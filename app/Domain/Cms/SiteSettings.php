<?php

namespace App\Domain\Cms;

use App\Domain\CmsPublishing;
use App\Domain\SchoolOperations;
use Illuminate\Support\Facades\DB;

class SiteSettings
{
    public function __construct(private SchoolOperations $ops,private CmsPublishing $publishing) {}
    /** Which settings keys each CMS screen owns. Publishing a screen publishes only its keys (CMS-002). */
    public const SECTIONS=[
        'profile'=>['badge','intro_heading','intro_text','mission','levels','default_seo_description','footer_text','email','office_hours','statistics','social_links','directory_source','extras'],
        'menus'=>['navigation','footer_navigation','utility_navigation'],
        'homepage'=>['homepage_sections','hero_autoplay','hero_interval','hero_search'],
        'identity'=>['favicon_media_id','color_mode'],
        'observance'=>['observance'],
    ];
    public static function defaults(): array
    {
        return ['badge'=>'เว็บไซต์โรงเรียน','intro_heading'=>'พื้นที่แห่งการเรียนรู้ของทุกคน','intro_text'=>'ร่วมสร้างโอกาสในการเรียนรู้ ผ่านความร่วมมือของโรงเรียน ครอบครัว และชุมชน',
            'mission'=>'เรียนรู้ด้วยความเข้าใจ เติบโตอย่างมีคุณค่า','email'=>'','office_hours'=>'วันจันทร์–ศุกร์ 08.00–16.30 น.',
            'levels'=>'ประถมศึกษาและมัธยมศึกษา','directory_source'=>'manual','footer_text'=>'โรงเรียน ชุมชน และอนาคตที่เราเติบโตไปด้วยกัน',
            'statistics'=>[],'navigation'=>[],'social_links'=>[],'extras'=>SiteExtras::defaults(),
            'homepage_sections'=>['services','news','notices','albums','events'],
            'footer_navigation'=>[],'utility_navigation'=>[],
            'hero_autoplay'=>true,'hero_interval'=>7000,'hero_search'=>true,
            'favicon_media_id'=>null,'color_mode'=>'light','observance'=>Observance::defaults(),
            'default_seo_description'=>'ข่าวสาร กิจกรรม และข้อมูลบริการของโรงเรียน']+PresentationSchema::defaults();
    }
    public function read(int $school): array
    {
        $row=DB::table('cms_site_settings')->where('school_id',$school)->first();
        $read=function($payload){$s=array_replace(self::defaults(),$payload?json_decode($payload,true):[]);$s['observance']=Observance::normalize($s['observance']??[]);$s['extras']=SiteExtras::normalize($s['extras']??null);return PresentationSchema::merge($s);};
        return ['draft'=>$read($row?->draft),'published'=>$read($row?->published),'version'=>$row?->version??0,'published_version'=>$row?->published_version??0];
    }
    public function save(array $settings,int $version,bool $publish,?string $section=null): array
    {
        $this->publishing->authorize();
        foreach(Observance::media($settings)as$id){$image=$this->ops->record('cms_media',(int)$id);abort_unless(str_starts_with($image->mime,'image/'),422,'favicon และ banner ต้องเป็นภาพ');}
        return DB::transaction(function()use($settings,$version,$publish,$section){
            $ids=Observance::media($settings);sort($ids);foreach($ids as$id)$this->ops->query('cms_media')->where('id',$id)->lockForUpdate()->first()??abort(404);
            $school=$this->ops->school();DB::table('cms_site_settings')->insertOrIgnore(['school_id'=>$school,'draft'=>json_encode(self::defaults()),'published'=>json_encode(self::defaults()),'version'=>0,'published_version'=>0,'created_at'=>now(),'updated_at'=>now()]);
            $row=DB::table('cms_site_settings')->where('school_id',$school)->lockForUpdate()->first();abort_if((int)$row->version!==$version,409,'มีการเปลี่ยนการตั้งค่าแล้ว กรุณาโหลดใหม่');
            $previousDraft=PresentationSchema::merge(array_replace(self::defaults(),json_decode($row->draft,true)));if(!array_key_exists('directory_source',$settings))$settings['directory_source']=$previousDraft['directory_source']??'manual';if(!array_key_exists('extras',$settings))$settings['extras']=SiteExtras::normalize($previousDraft['extras']??null);$previousPublished=PresentationSchema::merge(array_replace(self::defaults(),json_decode($row->published,true)));$presentationPublished=[];
            foreach(PresentationSchema::defaults()as$key=>$defaults){$settings[$key]=array_replace($previousDraft[$key],$settings[$key]??[]);$delta=array_filter($settings[$key],fn($value,$field)=>!array_key_exists($field,$previousDraft[$key])||$value!==$previousDraft[$key][$field],ARRAY_FILTER_USE_BOTH);$presentationPublished[$key]=array_replace($previousPublished[$key],$delta);}
            $changes=['draft'=>json_encode($settings,JSON_UNESCAPED_UNICODE),'version'=>$version+1,'updated_at'=>now()];
            if($publish){
                // Publish only the keys of the screen that pressed publish ('all' is an explicit whole-draft publish).
                $plain=array_diff_key($settings,PresentationSchema::defaults());
                $owned=$section==='all'?$plain:array_flip(self::SECTIONS[$section]??[]);
                $published=array_replace($previousPublished,array_intersect_key($plain,$owned),$presentationPublished);
                $changes+=['published'=>json_encode($published,JSON_UNESCAPED_UNICODE),'published_version'=>$version+1];
            }
            DB::table('cms_site_settings')->where('id',$row->id)->update($changes);$this->ops->audit($publish?'site.published':'site.drafted','cms_site_settings',$row->id);
            return $this->read($school);
        });
    }
    public static function validateNavigation(array $items,int $depth=0): bool
    {
        if($depth>2||count($items)>12)return false;
        foreach($items as $item){
            if(!is_array($item)||array_diff(array_keys($item),['label','url','children'])||!is_string($item['label']??null)||!is_string($item['url']??null)||!trim($item['label'])||mb_strlen($item['label'])>60||mb_strlen($item['url'])>1000||!SafeContent::url($item['url']))return false;
            if(isset($item['children'])&&(!is_array($item['children'])||!self::validateNavigation($item['children'],$depth+1)))return false;
        }
        return true;
    }
}
