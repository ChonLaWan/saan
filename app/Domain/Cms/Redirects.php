<?php
namespace App\Domain\Cms;
use Illuminate\Support\Facades\DB;

class Redirects
{
    public static function normalize(string $uri): ?string
    {
        if(!str_starts_with($uri,'/')||str_starts_with($uri,'//')||str_contains($uri,'\\')||preg_match('/[\x00-\x20]/',$uri))return null;$parts=parse_url($uri);if($parts===false||isset($parts['host'])||isset($parts['fragment']))return null;
        $path=rawurldecode($parts['path']??'/');if(str_starts_with($path,'//')||preg_match('/[\x00-\x1F\x7F]/',$path)||str_contains($path,'\\')||preg_match('~(?:^|/)\.\.(?:/|$)~',$path))return null;$path=rtrim($path,'/')?:'/';$query=[];parse_str($parts['query']??'',$query);if(count($query)>16)return null;foreach($query as$value)if(!is_scalar($value))return null;ksort($query);return $path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
    }
    public static function targetSource(string $target): ?string
    {
        if(str_starts_with($target,'/'))return self::normalize($target);$base=parse_url(url('/'));$parts=parse_url($target);if(($parts['host']??'')!==($base['host']??'')||($parts['port']??null)!==($base['port']??null))return null;$path=$parts['path']??'/';$basePath=rtrim($base['path']??'','/');if($basePath&&str_starts_with($path,$basePath.'/'))$path=substr($path,strlen($basePath));return self::normalize($path.(isset($parts['query'])?'?'.$parts['query']:''));
    }
    public static function assertNoCycle(int $school,array $replacement,?int $ignore=null): void
    {
        $map=[];foreach(DB::table('cms_redirects')->where('school_id',$school)->where('is_active',true)->when($ignore,fn($q)=>$q->where('id','!=',$ignore))->get()as$row)$map[$row->source_path]=$row->target_path;
        if($replacement['is_active'])$map[$replacement['source_path']]=$replacement['target_path'];
        foreach(array_keys($map)as$source){$visited=[];$current=$source;for($i=0;$i<50;$i++){if(!isset($map[$current])){$plain=explode('?',$current,2)[0];if(!isset($map[$plain]))break;$current=$plain;}if(isset($visited[$current]))abort(422,'redirect วนกลับมาที่เดิม กรุณาเปลี่ยนปลายทาง');$visited[$current]=true;$next=self::targetSource($map[$current]);if($next===null)break;$current=$next;if($i===49)abort(422,'redirect ต่อกันยาวเกินไป');}}
    }
    public static function match(string $path,string $query=''): ?object
    {
        $exact=self::normalize($path.($query?'?'.$query:''));$plain=self::normalize($path);if($exact===null)return null;
        return DB::table('cms_redirects')->where('school_id',config('school.school_id'))->where('is_active',true)->whereIn('source_path',array_unique([$exact,$plain]))->orderByRaw('CASE WHEN source_path = ? THEN 0 ELSE 1 END',[$exact])->first();
    }
}
