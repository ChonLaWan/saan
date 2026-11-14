<?php
namespace App\Domain\Cms;
use Carbon\CarbonImmutable;

class Observance
{
    public const CORNERS=['top-left','top-right','bottom-left','bottom-right'];
    /** Ribbon edge length in px (S/M/L). The band covers 62% of it; observance.css additionally caps a top ribbon so the band ends before the header padding (x + y) set in the presentation settings. */
    public const SIZES=['s'=>40,'m'=>52,'l'=>64];
    /** Below 1100px the header shows the menu button 14px from the right edge and 10px from the top: a top ribbon is capped so its band (62% of the box) ends before 14+10px. */
    public const TOP_MOBILE_MAX=36;
    public const BOTTOM_MOBILE_MAX=52;
    public const EDGES=['none'=>null,'white'=>'#ffffff','grey'=>'#9a9a9a'];
    public const LABEL='ร่วมไว้อาลัย';
    public static function defaults(): array {return ['mode'=>'off','title'=>'ประกาศจากโรงเรียน','body'=>'','image_media_id'=>null,'mobile_media_id'=>null,'button_label'=>'เข้าสู่เว็บไซต์','starts_at'=>null,'ends_at'=>null,'frequency'=>'session','grayscale'=>false,'image_fit'=>'contain',
        'popup'=>true,'ribbon'=>true,'ribbon_corner'=>'top-right','ribbon_source'=>'builtin','ribbon_color'=>'#000000','ribbon_edge'=>'none','ribbon_size'=>'m','ribbon_offset'=>0,'ribbon_opacity'=>100,'ribbon_media_id'=>null,'ribbon_mobile_media_id'=>null,'ribbon_logo'=>false,'ribbon_label'=>self::LABEL,'ribbon_note'=>'','ribbon_target'=>'none','ribbon_url'=>''];}
    /** Stored or submitted settings filled with defaults. An announcement saved before the ribbon existed (no 'ribbon' key AND mode mourning/celebration) keeps its old behaviour: popup on, no ribbon. A row with mode off (or no mode), such as the installed demo content, has nothing configured yet and takes the new defaults (ribbon on). Unknown keys are dropped. */
    public static function merge(?array $stored): array
    {
        $stored??=[];$legacy=!array_key_exists('ribbon',$stored)&&in_array($stored['mode']??null,['mourning','celebration'],true);$o=array_replace(self::defaults(),array_intersect_key($stored,self::defaults()));
        if($legacy){$o['ribbon']=false;$o['popup']=true;}
        $o['body']=$o['body']??'';return $o;
    }
    /** merge() plus a clamp of every value the public render prints, so stale or hand-edited rows can never produce unsafe markup. */
    public static function normalize(?array $stored): array
    {
        $d=self::defaults();$o=self::merge($stored);
        // every value is type-checked first: an array or object in a hand-edited row must never reach isset()/(string) (TypeError / warning = 500 on every page)
        $str=static fn(mixed $v,string $f='')=>is_string($v)?$v:((is_int($v)||is_float($v))?(string)$v:$f);
        $pick=static fn(mixed $v,array $allowed,string $f)=>is_string($v)&&in_array($v,$allowed,true)?$v:$f;
        $num=static fn(mixed $v,int $f)=>is_numeric($v)?(int)$v:$f;
        $o['mode']=$pick($o['mode'],['off','mourning','celebration'],'off');
        $o['title']=$str($o['title'],$d['title']);$o['body']=$str($o['body']);$o['button_label']=$str($o['button_label'],$d['button_label']);
        $o['frequency']=$pick($o['frequency'],['session','day','visit'],$d['frequency']);$o['image_fit']=$pick($o['image_fit'],['contain','cover'],$d['image_fit']);
        foreach(['starts_at','ends_at']as$k)$o[$k]=self::stamp($o[$k]);
        foreach(['image_media_id','mobile_media_id']as$k)$o[$k]=is_numeric($o[$k])&&(int)$o[$k]>0?(int)$o[$k]:null;
        $o['ribbon']=(bool)$o['ribbon'];$o['popup']=(bool)$o['popup'];$o['grayscale']=(bool)$o['grayscale'];$o['ribbon_logo']=(bool)$o['ribbon_logo'];
        $o['ribbon_corner']=$pick($o['ribbon_corner'],self::CORNERS,$d['ribbon_corner']);
        $o['ribbon_source']=$pick($o['ribbon_source'],['builtin','image'],$d['ribbon_source']);
        $o['ribbon_color']=self::hex($o['ribbon_color']);
        $o['ribbon_edge']=$pick($o['ribbon_edge'],array_keys(self::EDGES),'none');
        $o['ribbon_size']=$pick($o['ribbon_size'],array_keys(self::SIZES),$d['ribbon_size']);
        $o['ribbon_offset']=max(0,min(48,$num($o['ribbon_offset'],0)));$o['ribbon_opacity']=max(40,min(100,$num($o['ribbon_opacity'],100)));
        foreach(['ribbon_media_id','ribbon_mobile_media_id']as$k)$o[$k]=is_numeric($o[$k])&&(int)$o[$k]>0?(int)$o[$k]:null;
        if($o['ribbon_source']==='image'&&!$o['ribbon_media_id'])$o['ribbon_source']='builtin';
        $o['ribbon_label']=mb_substr(trim($str($o['ribbon_label'])),0,60)?:self::LABEL;$o['ribbon_note']=mb_substr(trim($str($o['ribbon_note'])),0,160);
        $o['ribbon_target']=$pick($o['ribbon_target'],['none','popup','url'],'none');
        $o['ribbon_url']=is_string($o['ribbon_url'])&&self::safeTarget($o['ribbon_url'])?$o['ribbon_url']:'';
        if($o['ribbon_target']==='url'&&$o['ribbon_url']==='')$o['ribbon_target']='none';
        return $o;
    }
    /** A date-time the window check can parse (Y-m-d\TH:i or with seconds), else null. */
    private static function stamp(mixed $v): ?string
    {
        if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/D',$v))return null;
        try{CarbonImmutable::parse($v,'Asia/Bangkok');}catch(\Throwable){return null;}
        return $v;
    }
    /** Identity of the popup for the "already closed" memory of a visitor: only what the popup shows, so ribbon changes never bring a closed popup back. */
    public static function identity(array $o): string
    {
        // exactly the 11 keys of the pre-ribbon settings, in their old order: an announcement already published keeps the hash its visitors stored
        // when they closed the popup (the old view hashed array_replace(old defaults, stored)), and ribbon-only changes never bring the popup back
        $o=self::normalize($o);$keys=['mode','title','body','image_media_id','mobile_media_id','button_label','starts_at','ends_at','frequency','grayscale','image_fit'];
        $legacy=[];foreach($keys as $k)$legacy[$k]=$o[$k];
        return substr(hash('sha256',json_encode($legacy)),0,20);
    }
    /** Media the public pages really print: the popup picture only when the popup shows, the ribbon picture only for a mourning image ribbon. (media() stays the wider list that blocks deleting a file.) */
    public static function renderedMedia(array $o): array
    {
        $o=self::normalize($o);$ids=[];
        if(self::popupShown($o,true))array_push($ids,$o['image_media_id'],$o['mobile_media_id']);
        if($o['mode']==='mourning'&&$o['ribbon']&&$o['ribbon_source']==='image')array_push($ids,$o['ribbon_media_id'],$o['ribbon_mobile_media_id']);
        return array_values(array_unique(array_map('intval',array_filter($ids))));
    }
    public static function hex(mixed $color): string {return is_string($color)&&preg_match('/^#[0-9a-fA-F]{6}$/D',$color)?strtolower($color):'#000000';}
    /** A page the ribbon may open: a path on this site, or an absolute https URL. No other scheme, no protocol-relative address, no credentials, no whitespace or quote characters. */
    public static function safeTarget(string $url): bool
    {
        if($url===''||strlen($url)>1000||preg_match('/[\x00-\x20\x7F\\\\"\'<>`]/',$url))return false;
        if(str_starts_with($url,'/'))return !str_starts_with($url,'//');
        if(!str_starts_with($url,'https://')||filter_var($url,FILTER_VALIDATE_URL)===false)return false;
        $p=parse_url($url);return is_array($p)&&!isset($p['user'])&&!isset($p['pass'])&&!empty($p['host']);
    }
    /** The window check alone (Asia/Bangkok), for settings that are saved or published. */
    public static function active(array $settings): bool {return self::live($settings,false);}
    /** $force skips the date window: used only by the signed admin preview. */
    public static function live(array $settings,bool $force=false): bool
    {
        if(!in_array($settings['mode']??'off',['mourning','celebration'],true))return false;
        if($force)return true;
        $now=CarbonImmutable::now('Asia/Bangkok');
        if(!empty($settings['starts_at'])&&CarbonImmutable::parse($settings['starts_at'],'Asia/Bangkok')->greaterThan($now))return false;
        if(!empty($settings['ends_at'])&&CarbonImmutable::parse($settings['ends_at'],'Asia/Bangkok')->lessThan($now))return false;
        return true;
    }
    public static function popupShown(array $o,bool $force=false): bool {return self::live($o,$force)&&($o['mode']==='celebration'||!empty($o['popup']));}
    public static function ribbonShown(array $o,bool $force=false): bool {return self::live($o,$force)&&$o['mode']==='mourning'&&!empty($o['ribbon']);}
    public static function grayscaleShown(array $o,bool $force=false): bool {return self::live($o,$force)&&$o['mode']==='mourning'&&!empty($o['grayscale']);}
    public static function logoRibbonShown(array $o,bool $force=false): bool {return self::live($o,$force)&&$o['mode']==='mourning'&&!empty($o['ribbon_logo']);}
    /** ribbon | grayscale | both | none (none = popup only, how settings saved before the ribbon behave). */
    public static function display(array $o): string {return !empty($o['ribbon'])?(!empty($o['grayscale'])?'both':'ribbon'):(!empty($o['grayscale'])?'grayscale':'none');}
    public static function text(array $o): string {return trim($o['ribbon_label'].($o['ribbon_note']!==''?' — '.$o['ribbon_note']:''));}
    public static function targetHref(array $o): ?string
    {
        if(($o['ribbon_target']??'none')!=='url'||!self::safeTarget((string)($o['ribbon_url']??'')))return null;
        return str_starts_with($o['ribbon_url'],'/')?url($o['ribbon_url']):$o['ribbon_url'];
    }
    /** Built-in ribbon: a diagonal band across one corner. Every printed value is validated or escaped. */
    public static function ribbonSvg(array $o): string
    {
        $o=self::normalize($o);$flip=['top-left'=>'','top-right'=>' transform="translate(100 0) scale(-1 1)"','bottom-left'=>' transform="translate(0 100) scale(1 -1)"','bottom-right'=>' transform="translate(100 100) scale(-1 -1)"'][$o['ribbon_corner']];
        $svg='<svg class="observance-ribbon-art" viewBox="0 0 100 100" width="100%" height="100%" aria-hidden="true" focusable="false"><g'.$flip.'><path d="M0 30L30 0H62L0 62Z" fill="'.e($o['ribbon_color']).'"/>';
        if($edge=self::EDGES[$o['ribbon_edge']])$svg.='<path d="M0 34L34 0M0 58L58 0" fill="none" stroke="'.e($edge).'" stroke-width="1.4"/>';
        return $svg.'</g></svg>';
    }
    /** What goes inside the ribbon control: the built-in SVG or the uploaded picture (with an optional small-screen source). */
    public static function ribbonArt(array $o): string
    {
        $o=self::normalize($o);
        if($o['ribbon_source']!=='image')return self::ribbonSvg($o);
        $mobile=$o['ribbon_mobile_media_id']?'<source media="(max-width:640px)" srcset="'.e(url('/media/'.$o['ribbon_mobile_media_id'])).'">':'';
        return '<picture class="observance-ribbon-art">'.$mobile.'<img src="'.e(url('/media/'.$o['ribbon_media_id'])).'" alt="" decoding="async"></picture>';
    }
    public static function media(array $settings): array{$o=$settings['observance']??[];return array_values(array_filter(array_merge([$settings['favicon_media_id']??null,$o['image_media_id']??null,$o['mobile_media_id']??null,$o['ribbon_media_id']??null,$o['ribbon_mobile_media_id']??null],PresentationSchema::media($settings))));}
    /** Validation rules for the observance object, used by the settings endpoint ('settings.observance') and the preview endpoint ('observance'). */
    public static function rules(string $p): array
    {
        $hex='/^#[0-9a-fA-F]{6}$/D';
        return [
            $p.'.mode'=>'required_with:'.$p.'|in:off,mourning,celebration',
            $p.'.title'=>'required_with:'.$p.'|string|max:180',$p.'.body'=>'present_with:'.$p.'|nullable|string|max:2000',
            $p.'.image_media_id'=>'nullable|integer|min:1',$p.'.mobile_media_id'=>'nullable|integer|min:1',
            $p.'.button_label'=>'required_with:'.$p.'|string|max:60',
            $p.'.starts_at'=>'nullable|date_format:Y-m-d\TH:i',$p.'.ends_at'=>'nullable|date_format:Y-m-d\TH:i|after_or_equal:'.$p.'.starts_at',
            $p.'.frequency'=>'required_with:'.$p.'|in:session,day,visit',$p.'.grayscale'=>'required_with:'.$p.'|boolean',
            $p.'.image_fit'=>'required_with:'.$p.'|in:contain,cover',
            $p.'.popup'=>'sometimes|boolean',$p.'.ribbon'=>'sometimes|boolean',$p.'.ribbon_logo'=>'sometimes|boolean',
            $p.'.ribbon_corner'=>'sometimes|in:'.implode(',',self::CORNERS),$p.'.ribbon_source'=>'sometimes|in:builtin,image',
            $p.'.ribbon_color'=>['sometimes','string','regex:'.$hex],$p.'.ribbon_edge'=>'sometimes|in:'.implode(',',array_keys(self::EDGES)),
            $p.'.ribbon_size'=>'sometimes|in:'.implode(',',array_keys(self::SIZES)),
            $p.'.ribbon_offset'=>'sometimes|integer|between:0,48',$p.'.ribbon_opacity'=>'sometimes|integer|between:40,100',
            $p.'.ribbon_media_id'=>'nullable|integer|min:1',$p.'.ribbon_mobile_media_id'=>'nullable|integer|min:1',
            $p.'.ribbon_label'=>'sometimes|string|min:1|max:60',$p.'.ribbon_note'=>'sometimes|nullable|string|max:160',
            $p.'.ribbon_target'=>'sometimes|in:none,popup,url',
            $p.'.ribbon_url'=>['sometimes','nullable','string','max:1000',fn($a,$v,$fail)=>($v===null||$v===''||self::safeTarget((string)$v))?null:$fail('ลิงก์ต้องเป็นเส้นทางในเว็บไซต์ หรือ https เท่านั้น')],
        ];
    }
    public static function keys(): string {return implode(',',array_keys(self::defaults()));}
    /** Cross-field problems of a validated submission (null when fine). Runs on merge(), before values are clamped. */
    public static function problem(array $input): ?string
    {
        $o=self::merge($input);
        if($o['ribbon_target']==='url'&&!self::safeTarget((string)$o['ribbon_url']))return 'กรุณาระบุลิงก์ปลายทางของแถบ (เส้นทางในเว็บไซต์ หรือ https)';
        if($o['ribbon_target']==='popup'&&$o['mode']!=='celebration'&&!$o['popup'])return 'เลือกให้แถบเปิดประกาศแบบหน้าต่าง ต้องเปิดการแสดงประกาศแบบหน้าต่างด้วย';
        if($o['ribbon_source']==='image'&&!$o['ribbon_media_id'])return 'กรุณาเลือกรูปแถบจากคลังสื่อ หรือใช้แถบในตัว';
        if($o['mode']==='mourning'&&!$o['popup']&&!$o['ribbon']&&!$o['grayscale']&&!$o['ribbon_logo'])return 'โหมดไว้ทุกข์ต้องเลือกรูปแบบการแสดงอย่างน้อยหนึ่งอย่าง';
        return null;
    }
}
