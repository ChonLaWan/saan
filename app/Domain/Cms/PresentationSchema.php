<?php
namespace App\Domain\Cms;

class PresentationSchema
{
    public static function all():array
    {
        static $schema;return $schema??=json_decode(file_get_contents(resource_path('data/presentation-schema.json')),true,512,JSON_THROW_ON_ERROR);
    }
    public static function section(string $key):array {return self::all()[$key]??abort(422,'ไม่พบส่วนที่ต้องการปรับ');}
    public static function defaults():array
    {
        $result=[];foreach(['branding','header','hero']as$key){$section=self::section($key);$result[$section['storage']]=[];foreach($section['fields']as$field)if($field['scope']==='values')$result[$section['storage']][$field['key']]=$field['default'];}return$result;
    }
    public static function merge(array $settings):array
    {
        foreach(self::defaults()as$key=>$defaults)$settings[$key]=array_replace($defaults,$settings[$key]??[]);return$settings;
    }
    public static function rulesForField(array $field):array
    {
        $rules=['sometimes'];$nonempty=in_array($field['key'],['school_name','hero_title','alt','staff_label','directory_label','search_placeholder']);if(!$nonempty&&($field['nullable']??in_array($field['type'],['text','textarea','color'],true)))$rules[]='nullable';
        return array_merge($rules,match($field['type']){
            'boolean'=>['boolean'],'number'=>['integer','between:'.$field['min'].','.$field['max']],
            'media'=>['nullable','integer','min:1'],'select'=>['in:'.implode(',',array_keys($field['options']))],
            'color'=>['string','regex:/^#[0-9a-fA-F]{6}$/D'],
            'url'=>['string','max:1000',fn($a,$v,$fail)=>SafeContent::url($v)?:$fail('ลิงก์ต้องเป็น https หรือเส้นทางภายในเว็บไซต์')],
            default=>array_merge(['string','max:'.($field['max']??180)],$nonempty?['min:1']:[]),
        });
    }
    public static function requestRules(string $section):array
    {
        $groups=['values'=>[],'theme'=>[],'shared'=>[]];$rules=[];
        foreach(self::section($section)['fields']as$field){$groups[$field['scope']][]=$field['key'];$rules[$field['scope'].'.'.$field['key']]=self::rulesForField($field);}
        foreach($groups as$scope=>$keys)$rules[$scope]=($scope==='values'?'present':'sometimes').'|array'.($keys?':'.implode(',',$keys):'|max:0');
        return$rules;
    }
    public static function settingsRules(string $prefix='settings'):array
    {
        $rules=[];foreach(['branding','header','hero']as$key){$section=self::section($key);$base=$prefix.'.'.$section['storage'];$keys=[];foreach($section['fields']as$field)if($field['scope']==='values'){$keys[]=$field['key'];$rules[$base.'.'.$field['key']]=self::rulesForField($field);}$rules[$base]='sometimes|array:'.implode(',',$keys);}return$rules;
    }
    public static function media(array $settings):array
    {
        return array_values(array_unique(array_filter([$settings['branding']['dark_media_id']??null,$settings['branding']['mobile_media_id']??null,$settings['branding']['mobile_dark_media_id']??null])));
    }
}
