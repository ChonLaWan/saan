<?php
namespace App\Support;

use App\Domain\Cms\PresentationSchema;

class PresentationCss
{
    public static function variables(array $site):string
    {
        $site=PresentationSchema::merge($site);$values=[];
        foreach(['branding'=>'logo','header'=>'header','hero_design'=>'hero']as$group=>$prefix){
            $section=PresentationSchema::section($group==='hero_design'?'hero':$group);
            foreach($section['fields']as$field){if($field['scope']!=='values')continue;$key=$field['key'];$value=$site[$group][$key]??null;$var='--'.$prefix.'-'.str_replace('_','-',$key);
                if($field['type']==='number'&&$value!==null){$value=max($field['min'],min($field['max'],(int)$value));$unit=in_array($key,['content_width','overlay_override','opacity'])?'%':($key==='transition_ms'?'ms':'px');$values[$var]=$value.$unit;}
                if($field['type']==='color'&&is_string($value)&&preg_match('/^#[0-9a-fA-F]{6}$/D',$value))$values[$var]=$value;
            }
        }
        $bg=$site['hero_design']['button_background'];if(preg_match('/^#[0-9a-fA-F]{6}$/D',$bg??''))$values['--hero-button-ink']=\App\Domain\CmsPublishing::buttonInk($bg);
        if(($site['hero_design']['overlay_override']??null)!==null)$values['--hero-global-shade']=max(.3,min(.85,$site['hero_design']['overlay_override']/100));
        return implode(';',array_map(fn($key)=>$key.':'.$values[$key],array_keys($values)));
    }
    public static function mark(array $site):string {return in_array($site['branding']['mark']??'', ['book','circle','shield'],true)?$site['branding']['mark']:'book';}
}
