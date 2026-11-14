<?php

namespace App\Support;

use App\Domain\Cms\ContentTypes;
use Carbon\CarbonImmutable;

class WebsiteView
{
    /** Position of a school executive (acting heads included); the staff page anchor and the ITA O2 source count with this one rule. */
    public const EXEC='/^(รักษา(ราชการแทน|การ(ในตำแหน่ง)?))?\s*(รอง)?(ผู้อำนวยการ|ครูใหญ่|ผู้บริหาร)/u';
    public static function isExecutive(?string $position): bool {return preg_match(self::EXEC,trim((string)$position))===1;}

    public static function asset(string $path): string
    {
        $file=public_path($path);return asset($path).(is_file($file)?'?v='.filemtime($file).'-'.filesize($file):'');
    }

    public static function meta(object $entry): array {return is_array($entry->metadata??null)?$entry->metadata:json_decode($entry->metadata??'{}',true)??[];}
    public static function url(object $entry): string {return url('/portal/'.(ContentTypes::TYPES[$entry->content_type]['route']??'news').'/'.$entry->slug);}
    public static function link(string $url): string {return str_starts_with($url,'/')?url($url):(str_starts_with($url,'#')?url('/portal').$url:$url);}
    public static function body(string $html): string {return preg_replace_callback('/(<a\b[^>]*\bhref=")(\/(?!\/)[^"]*)(")/i',fn($m)=>$m[1].htmlspecialchars(url(html_entity_decode($m[2],ENT_QUOTES,'UTF-8')),ENT_QUOTES,'UTF-8').$m[3],\App\Domain\Cms\SafeContent::html($html));}
    public static function date(?string $date): string {if(!$date)return 'ไม่ระบุวันที่';$d=CarbonImmutable::parse($date,'Asia/Bangkok')->timezone('Asia/Bangkok');return $d->locale('th')->translatedFormat('j M').' '.($d->year+543);}
    public static function closed(array $meta): bool {return !empty($meta['deadline'])&&CarbonImmutable::parse($meta['deadline'],'Asia/Bangkok')->endOfDay()->isPast();}
    public static function datetime(?string $value): string {if(!$value)return 'ไม่ระบุวันเวลา';if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',trim($value)))return self::date($value);$d=CarbonImmutable::parse($value,'Asia/Bangkok')->timezone('Asia/Bangkok');return self::date($value).' เวลา '.$d->format('H:i').' น.';}
}
