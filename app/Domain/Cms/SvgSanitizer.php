<?php
namespace App\Domain\Cms;

/**
 * Rebuilds an uploaded SVG from an allowlist of shapes and presentation attributes. Nothing is copied that is not listed, so scripts,
 * event handlers, foreignObject, style blocks, links (href / xlink:href) and any external or data: reference cannot survive.
 * A DOCTYPE or entity declaration refuses the file outright. Returns null when the file is not a usable SVG.
 */
class SvgSanitizer
{
    private const NS='http://www.w3.org/2000/svg';
    private const MAX_BYTES=262144;
    private const MAX_NODES=1500;
    private const ELEMENTS=['svg','g','path','rect','circle','ellipse','line','polyline','polygon','defs','lineargradient','radialgradient','stop','clippath','title','desc'];
    private const ATTRIBUTES=['viewbox','width','height','x','y','cx','cy','r','rx','ry','x1','y1','x2','y2','points','d','fill','fill-opacity','fill-rule','clip-rule','stroke','stroke-width','stroke-linecap','stroke-linejoin','stroke-miterlimit','stroke-opacity','stroke-dasharray','stroke-dashoffset','opacity','transform','offset','stop-color','stop-opacity','gradientunits','gradienttransform','clip-path','clippathunits','preserveaspectratio','id','fx','fy','spreadmethod'];

    /** @return array{svg:string,width:int,height:int}|null */
    public static function clean(string $source): ?array
    {
        $source=preg_replace('/^\xEF\xBB\xBF/','',$source);
        // UTF-8 only: a UTF-16/32 file (BOM or NUL bytes) would hide DOCTYPE/ENTITY from the byte check below
        if($source===''||strlen($source)>self::MAX_BYTES||str_contains($source,"\0")||preg_match('/^(\xFF\xFE|\xFE\xFF)/',$source)||!mb_check_encoding($source,'UTF-8')||preg_match('/<!\s*(DOCTYPE|ENTITY)/i',$source))return null;
        $previous=libxml_use_internal_errors(true);
        try{
            $in=new \DOMDocument();
            if(!$in->loadXML($source,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)||!$in->documentElement||$in->doctype!==null)return null;
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $root=$in->documentElement;if($root->localName!=='svg'||$root->namespaceURI!==self::NS)return null;
        $out=new \DOMDocument('1.0','UTF-8');$count=0;$clean=self::copy($root,$out,$count);
        if(!$clean||$count>self::MAX_NODES)return null;
        $out->appendChild($clean);
        $box=preg_split('/[\s,]+/',trim($clean->getAttribute('viewBox')));
        if(count($box)===4&&is_numeric($box[2])&&is_numeric($box[3])&&$box[2]>0&&$box[3]>0){$width=(int)round((float)$box[2]);$height=(int)round((float)$box[3]);}
        elseif(is_numeric($clean->getAttribute('width'))&&is_numeric($clean->getAttribute('height'))&&$clean->getAttribute('width')>0&&$clean->getAttribute('height')>0){$width=(int)round((float)$clean->getAttribute('width'));$height=(int)round((float)$clean->getAttribute('height'));}
        else return null;
        if($width<1||$height<1||$width>6000||$height>6000)return null;
        $markup=$out->saveXML($out->documentElement);
        return $markup?['svg'=>$markup,'width'=>$width,'height'=>$height]:null;
    }
    private static function copy(\DOMElement $element,\DOMDocument $out,int &$count): ?\DOMElement
    {
        if(++$count>self::MAX_NODES)return null;
        $name=strtolower($element->localName);
        if($element->namespaceURI!==self::NS||!in_array($name,self::ELEMENTS,true))return null;
        $copy=$out->createElementNS(self::NS,$element->localName);
        foreach($element->attributes as $attribute){
            if($attribute->namespaceURI!==null)continue;
            $key=strtolower($attribute->nodeName);$value=$attribute->nodeValue;
            if(!in_array($key,self::ATTRIBUTES,true)||strlen($value)>4000||self::unsafe($value))continue;
            $copy->setAttribute($attribute->nodeName,$value);
        }
        foreach($element->childNodes as $child){
            if($child instanceof \DOMElement){if($node=self::copy($child,$out,$count))$copy->appendChild($node);}
            elseif(($child instanceof \DOMText)&&in_array($name,['title','desc'],true))$copy->appendChild($out->createTextNode(mb_substr($child->nodeValue,0,200)));
        }
        return $copy;
    }
    /** Any scheme, any url() that is not a same-document #id, expression() and control characters. */
    private static function unsafe(string $value): bool
    {
        $plain=preg_replace('/[\x00-\x20]+/','',html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if(preg_match('/(?:^|[^a-z0-9])(?:javascript|vbscript|data|https?|ftp|file|blob):/i',$plain)||stripos($plain,'expression(')!==false||str_contains($plain,'<')||str_contains($plain,'//'))return true;
        return (bool)preg_match('/url\((?!["\']?#)/i',$plain);
    }
}
