<?php
namespace App\Domain\Cms;

class VideoLink
{
    public static function embed(?string $url): ?string
    {
        if(!$url)return null;$parts=parse_url($url);if(($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['port']))return null;
        $host=strtolower($parts['host']??'');$path=$parts['path']??'';$id=null;
        if(in_array($host,['youtube.com','www.youtube.com','m.youtube.com'],true)){
            if($path==='/watch'){parse_str($parts['query']??'',$query);$id=$query['v']??null;}
            elseif(preg_match('~^/(?:shorts|embed)/([\w-]{11})/?$~D',$path,$match))$id=$match[1];
        }elseif($host==='youtu.be')$id=trim($path,'/');
        if(is_string($id)&&preg_match('/^[A-Za-z0-9_-]{11}$/D',$id))return 'https://www.youtube-nocookie.com/embed/'.$id;
        if(in_array($host,['vimeo.com','www.vimeo.com'],true)&&preg_match('~^/([0-9]{6,12})/?$~D',$path,$match))return 'https://player.vimeo.com/video/'.$match[1];
        return null;
    }
}
