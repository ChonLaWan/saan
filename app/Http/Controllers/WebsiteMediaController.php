<?php

namespace App\Http\Controllers;

use App\Domain\Cms\WebsiteContent;
use App\Domain\Cms\SiteSettings;
use App\Domain\CmsPublishing;
use App\Domain\SchoolOperations;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebsiteMediaController extends Controller
{
    public function __construct(private SchoolOperations $ops,private WebsiteContent $content,private CmsPublishing $publishing){}
    public function index(Request $r)
    {
        $this->content->authorize();$d=$r->validate(['q'=>'nullable|string|max:100','kind'=>'nullable|in:image,document,video','folder'=>'nullable|string|max:80']);
        $page=$this->ops->query('cms_media')->when(($d['kind']??null)==='image',fn($q)=>$q->where('mime','like','image/%'))->when(($d['kind']??null)==='document',fn($q)=>$q->where('mime','not like','image/%')->where('mime','not like','video/%'))->when(($d['kind']??null)==='video',fn($q)=>$q->where('mime','video/mp4'))->when($d['q']??null,fn($q,$v)=>$q->where(fn($q)=>$q->where('alt','like','%'.$v.'%')->orWhere('original_name','like','%'.$v.'%')))->when($d['folder']??null,fn($q,$v)=>$q->where('folder',$v))->orderByDesc('id')->paginate(48,['id','mime','bytes','width','height','alt','original_name','folder','version','source_media_id','created_at']);
        return response()->json(array_merge($page->toArray(),['storage'=>\App\Support\StorageQuota::summary($this->ops->school())]));
    }
    public function upload(Request $r)
    {
        $this->content->authorize();
        // F7: SVG is accepted only as a mourning-ribbon picture (purpose=ribbon) and is rebuilt by SvgSanitizer before it is stored
        if($r->input('purpose')==='ribbon'&&$r->hasFile('file')&&strtolower((string)$r->file('file')->getClientOriginalExtension())==='svg')return $this->uploadSvg($r);
        $d=$r->validate(['file'=>'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip,mp4|max:'.(max(1,(int)config('saan.storage.max_upload_mb'))*1024),'alt'=>'nullable|string|max:200','folder'=>'nullable|string|max:80']);
        $file=$d['file'];\App\Support\StorageQuota::assertRoom($this->ops->school(),(int)$file->getSize());$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());$mapping=['video/mp4'=>'mp4','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','application/vnd.ms-powerpoint'=>'ppt','application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx','application/zip'=>'zip','application/x-zip-compressed'=>'zip','text/plain'=>'txt','text/csv'=>'csv'];
        $extension=$mapping[$mime]??abort(422,'ชนิดไฟล์ไม่รองรับ');
        $allowed=['mp4'=>['video/mp4'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp'],'pdf'=>['application/pdf'],'doc'=>['application/msword'],'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],'xls'=>['application/vnd.ms-excel'],'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],'ppt'=>['application/vnd.ms-powerpoint'],'pptx'=>['application/vnd.openxmlformats-officedocument.presentationml.presentation'],'csv'=>['text/plain','text/csv'],'txt'=>['text/plain'],'zip'=>['application/zip','application/x-zip-compressed']];
        abort_unless(in_array($mime,$allowed[strtolower($file->getClientOriginalExtension())]??[],true),422,'ชนิดและนามสกุลไฟล์ไม่ตรงกัน');
        $width=0;$height=0;if(str_starts_with($mime,'image/')){$dimensions=@getimagesize($file->getRealPath());abort_unless($dimensions,422,'อ่านข้อมูลภาพไม่ได้');[$width,$height]=$dimensions;abort_if($width>6000||$height>6000,422,'ภาพต้องไม่เกิน 6000 × 6000 จุด');}
        $path=$file->storeAs('cms/'.$this->ops->school(),Str::uuid().'.'.$extension,'local');abort_unless($path,500);
        $name=mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u','_',$file->getClientOriginalName()),0,180);
        try{$id=DB::transaction(function()use($d,$file,$mime,$path,$width,$height,$name){$id=DB::table('cms_media')->insertGetId(['school_id'=>$this->ops->school(),'uploaded_by'=>auth()->id(),'path'=>$path,'mime'=>$mime,'bytes'=>$file->getSize(),'width'=>$width,'height'=>$height,'alt'=>$d['alt']??'','original_name'=>$name,'folder'=>$d['folder']??'ทั่วไป','created_at'=>now(),'updated_at'=>now()]);$this->ops->audit('media.uploaded','cms_media',$id);return $id;});}
        catch(\Throwable $e){Storage::disk('local')->delete($path);throw $e;}
        return response()->json(['id'=>$id,'url'=>url('/media/'.$id),'mime'=>$mime,'alt'=>$d['alt']??'','width'=>$width,'height'=>$height,'bytes'=>$file->getSize(),'original_name'=>$name],201);
    }
    private function uploadSvg(Request $r)
    {
        $d=$r->validate(['file'=>'required|file|max:512','alt'=>'nullable|string|max:200','folder'=>'nullable|string|max:80','purpose'=>'in:ribbon']);
        $file=$d['file'];$raw=(string)file_get_contents($file->getRealPath());
        abort_unless(in_array((new \finfo(FILEINFO_MIME_TYPE))->buffer($raw),['image/svg+xml','text/xml','application/xml','text/plain'],true),422,'ชนิดไฟล์ไม่รองรับ');
        $clean=\App\Domain\Cms\SvgSanitizer::clean($raw)??abort(422,'ไฟล์ SVG ใช้ไม่ได้ ต้องเป็น SVG ล้วนไม่มีสคริปต์หรือลิงก์ภายนอก');
        \App\Support\StorageQuota::assertRoom($this->ops->school(),strlen($clean['svg']));
        $path='cms/'.$this->ops->school().'/'.Str::uuid().'.svg';abort_unless(Storage::disk('local')->put($path,$clean['svg']),500);
        $name=mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u','_',$file->getClientOriginalName()),0,180);
        try{$id=DB::transaction(function()use($d,$clean,$path,$name){$id=DB::table('cms_media')->insertGetId(['school_id'=>$this->ops->school(),'uploaded_by'=>auth()->id(),'path'=>$path,'mime'=>'image/svg+xml','bytes'=>strlen($clean['svg']),'width'=>$clean['width'],'height'=>$clean['height'],'alt'=>$d['alt']??'','original_name'=>$name,'folder'=>$d['folder']??'ทั่วไป','created_at'=>now(),'updated_at'=>now()]);$this->ops->audit('media.uploaded','cms_media',$id);return $id;});}
        catch(\Throwable $e){Storage::disk('local')->delete($path);throw $e;}
        return response()->json(['id'=>$id,'url'=>url('/media/'.$id),'mime'=>'image/svg+xml','alt'=>$d['alt']??'','width'=>$clean['width'],'height'=>$clean['height'],'bytes'=>strlen($clean['svg']),'original_name'=>$name],201);
    }
    public function variant(Request $r,int $id)
    {
        $this->content->authorize();$d=$r->validate(['width'=>'required|integer|between:16,2400','ratio'=>'required|in:original,1:1,4:3,16:9,3:1,9:16','format'=>'required|in:webp,jpeg,png','quality'=>'required|integer|between:50,95','focal_x'=>'required|integer|between:0,100','focal_y'=>'required|integer|between:0,100']);return response()->json(app(\App\Domain\Cms\ImageVariant::class)->create($id,$d),201);
    }
    public function update(Request $r,int $id)
    {
        $this->content->authorize();$d=$r->validate(['alt'=>'present|nullable|string|max:200','folder'=>'required|string|max:80','version'=>'required|integer|min:1']);
        return DB::transaction(function()use($id,$d){$row=$this->ops->query('cms_media')->where('id',$id)->lockForUpdate()->first()??abort(404);abort_if((int)$row->version!==(int)$d['version'],409);$this->ops->query('cms_media')->where('id',$id)->update(['alt'=>$d['alt']??'','folder'=>$d['folder'],'version'=>$row->version+1,'updated_at'=>now()]);$this->ops->audit('media.updated','cms_media',$id);return response()->json(['ok'=>true]);});
    }
    public function used(int $id,int $school,bool $publishedOnly): bool
    {
        if($publishedOnly)return in_array($id,$this->publicIds($school),true)||$this->observanceUses($id,$school);
        $theme=$this->publishing->theme($school);
        foreach([$theme['draft'],$theme['published']] as $s)if(in_array($id,[(int)($s['logo_media_id']??0),(int)($s['hero_media_id']??0)],true))return true;
        $site=app(SiteSettings::class)->read($school);
        foreach([$site['draft'],$site['published']]as$settings){
            if((int)($settings['favicon_media_id']??0)===$id||in_array($id,array_map('intval',\App\Domain\Cms\PresentationSchema::media($settings)),true))return true;
            if(in_array($id,array_map('intval',\App\Domain\Cms\Observance::media($settings)),true))return true;
        }
        if(DB::table('cms_media')->where('school_id',$school)->where('source_media_id',$id)->exists())return true;
        if(\Illuminate\Support\Facades\Schema::hasTable('ita_items')&&\Saan\Modules\WebIta\ItaRepository::usesMedia($school,$id))return true;
        // F4: a file of a group-hub document (published or not) is in use; the hub screen removes it together with the document
        if(\Illuminate\Support\Facades\Schema::hasTable('cms_hub_documents')&&DB::table('cms_hub_documents')->where('school_id',$school)->where('media_id',$id)->exists())return true;
        // CORE-PERF: 200 rows at a time, a DB-side superset prefilter (the id digits must appear somewhere), stop at the first hit
        $needle='%'.$id.'%';$found=false;
        DB::table('posts')->where('school_id',$school)->where(fn($q)=>$q->where('cover_media_id',$id)->orWhere('metadata','like',$needle)->orWhere('body','like','%/media/'.$id.'%')->orWhere('draft_payload','like',$needle))->select(['id','cover_media_id','metadata','body','draft_payload'])
            ->chunkById(200,function($rows)use($id,&$found){
                foreach($rows as$row){
                    $meta=$row->metadata?json_decode($row->metadata,true):[];
                    if(in_array($id,$this->content->allMediaReferences(['cover_media_id'=>$row->cover_media_id,'metadata'=>is_array($meta)?$meta:[],'body'=>$row->body]),true)||($row->draft_payload&&in_array($id,$this->content->allMediaReferences(json_decode($row->draft_payload,true)?:[]),true))){$found=true;return false;}
                }
            },'id','id');
        if($found)return true;
        DB::table('cms_post_revisions')->where('school_id',$school)->where('snapshot','like',$needle)->select(['id','snapshot'])
            ->chunkById(200,function($rows)use($id,&$found){
                foreach($rows as$row)if(in_array($id,$this->content->allMediaReferences(json_decode($row->snapshot,true)?:[]),true)){$found=true;return false;}
            },'id','id');
        return $found;
    }
    /**
     * CMS-010: ids a guest may open, computed once per content change instead of scanning every post per request.
     * The cache key is a fingerprint of everything that can change publication (post versions/count, site and theme published versions).
     */
    public function publicIds(int $school): array
    {
        // only published, live rows can change what a guest may open; (school_id,status,deleted_at,version) is a covering index (see 2026_11_10_200001)
        $posts=DB::table('posts')->where('school_id',$school)->where('status','published')->whereNull('deleted_at')->selectRaw('COUNT(*) c, COALESCE(SUM(version),0) v, COALESCE(MAX(id),0) m, COALESCE(SUM(id),0) si, COALESCE(SUM(id*version),0) sv, COALESCE(SUM(id*id),0) sq')->first();
        // COUNT/SUM(version)/MAX(id) alone collide when one post leaves and another with the same version total joins; SUM(id), SUM(id*version) and SUM(id*id) tell the member sets apart (still index-only)
        $site=DB::table('cms_site_settings')->where('school_id',$school)->value('published_version')??0;$themeVersion=DB::table('cms_themes')->where('school_id',$school)->value('published_version')??0;
        $ita=$this->itaActive()?\Saan\Modules\WebIta\ItaRepository::fingerprint($school):'off';
        $key='saan:public-media:'.$school.':'.sha1(implode('|',[$posts->c,$posts->v,$posts->m,$posts->si,$posts->sv,$posts->sq,$site,$themeVersion,$ita]));
        $cache=\Illuminate\Support\Facades\Cache::store();
        if(($hit=$cache->get($key))!==null)return $hit;
        // one request rebuilds, the others wait for it instead of each loading every published body
        try{$lock=$cache->getStore() instanceof \Illuminate\Contracts\Cache\LockProvider?$cache->getStore()->lock($key.':build',30):null;}catch(\Throwable){$lock=null;}
        $build=function()use($school,$key,$cache){
            if(($hit=$cache->get($key))!==null)return $hit;
            $ids=$this->buildPublicIds($school);$cache->put($key,$ids,now()->addDay());return $ids;
        };
        if(!$lock)return $build();
        try{return $lock->block(10,$build);}catch(\Illuminate\Contracts\Cache\LockTimeoutException){return $this->buildPublicIds($school);}
    }
    private function buildPublicIds(int $school): array
    {
        return (function()use($school){
            $ids=[];$theme=$this->publishing->theme($school)['published'];$ids[]=(int)($theme['logo_media_id']??0);$ids[]=(int)($theme['hero_media_id']??0);
            $settings=app(SiteSettings::class)->read($school)['published'];$ids[]=(int)($settings['favicon_media_id']??0);$ids=array_merge($ids,array_map('intval',\App\Domain\Cms\PresentationSchema::media($settings)));
            DB::table('posts')->where('school_id',$school)->whereNull('deleted_at')->where('status','published')->select(['id','cover_media_id','metadata','body'])->chunkById(200,function($rows)use(&$ids){
                foreach($rows as$row)$ids=array_merge($ids,$this->content->allMediaReferences(['cover_media_id'=>$row->cover_media_id,'metadata'=>$row->metadata?json_decode($row->metadata,true):[],'body'=>$row->body]));
            });
            if($this->itaActive())$ids=array_merge($ids,\Saan\Modules\WebIta\ItaRepository::publicMediaIds($school));
            return array_values(array_unique(array_filter($ids)));
        })();
    }
    private function itaActive(): bool
    {
        return app(\App\Support\Modules\ModuleRegistry::class)->active('web-ita')&&\Illuminate\Support\Facades\Schema::hasTable('ita_years');
    }
    private function observanceUses(int $id,int $school): bool
    {
        $settings=app(SiteSettings::class)->read($school)['published'];
        return \App\Domain\Cms\Observance::active($settings['observance'])&&in_array($id,\App\Domain\Cms\Observance::renderedMedia($settings['observance']),true);
    }
    public function remove(Request $r,int $id)
    {
        $this->content->authorize();$d=$r->validate(['version'=>'required|integer|min:1']);$path=DB::transaction(function()use($id,$d){$row=$this->ops->query('cms_media')->where('id',$id)->lockForUpdate()->first()??abort(404);abort_if((int)$row->version!==(int)$d['version'],409);abort_if($this->used($id,$this->ops->school(),false),422,'ไฟล์ถูกใช้อยู่ในเนื้อหา ร่าง หรือธีม กรุณานำออกก่อน');$this->ops->query('cms_media')->where('id',$id)->delete();$this->ops->audit('media.deleted','cms_media',$id);return $row->path;});Storage::disk('local')->delete($path);return response()->json(['ok'=>true]);
    }
    public function show(int $id)
    {
        $media=DB::table('cms_media')->where('id',$id)->first()??abort(404);$viewer=auth()->check()?User::find(auth()->id()):null;
        $editor=$viewer&&$viewer->is_active&&(int)$viewer->school_id===(int)$media->school_id&&in_array($viewer->role,['admin','principal','editor'])&&(!$viewer->is_demo||config('school.demo'))&&request()->session()->get('auth_version')!==null&&(int)request()->session()->get('auth_version')===(int)$viewer->auth_version;
        $guest=!$editor;abort_unless($editor||((int)$media->school_id===(int)config('school.school_id')&&$this->used($id,(int)$media->school_id,true)),404);
        $disk=Storage::disk('local');abort_unless($disk->exists($media->path),404);
        // an SVG is only ever shown as an <img>; if someone opens it directly it still cannot run anything
        $svg=$media->mime==='image/svg+xml'?['Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; sandbox"]:[];
        // guests only reach published media: let browsers/CDN keep images for a short time (revalidated by ETag); editors always see fresh files
        if($guest&&str_starts_with($media->mime,'image/')){
            $etag='"'.sha1($media->id.'|'.$media->version.'|'.$media->bytes.'|'.$media->path).'"';
            $resp=response()->file($disk->path($media->path),['Content-Type'=>$media->mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'public, max-age=300, must-revalidate','X-Saan-Public-Cache'=>'1']+$svg);
            $resp->setEtag(trim($etag,'"'));if($media->updated_at)$resp->setLastModified(\Illuminate\Support\Carbon::parse($media->updated_at));
            $resp->isNotModified(request());return $resp;
        }
        if($media->mime==='video/mp4')return response()->file($disk->path($media->path),['Content-Type'=>'video/mp4','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
        if(!str_starts_with($media->mime,'image/'))return response()->download($disk->path($media->path),$media->original_name?:'document.'.pathinfo($media->path,PATHINFO_EXTENSION),['Content-Type'=>$media->mime,'X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"sandbox; default-src 'none'",'Cache-Control'=>'private, no-store']);
        return response()->file($disk->path($media->path),['Content-Type'=>$media->mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']+$svg);
    }
}
