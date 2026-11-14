<?php
namespace App\Http\Controllers;
use App\Domain\Cms\DemoLibrary;

class DemoLibraryController extends Controller
{
    public function index(DemoLibrary $library)
    {
        $catalog=$library->catalog();
        $media=\Illuminate\Support\Facades\DB::table('cms_media')->where('school_id',(int)config('school.school_id'))->get(['id','path','source_media_id','width','mime']);
        $preview=function(string$key)use($catalog,$media){$file=$catalog['files'][$key]??null;if(!$file)return null;$source=$media->firstWhere('path','cms/'.(int)config('school.school_id').'/demo-'.$file['sha256'].'.'.pathinfo($file['path'],PATHINFO_EXTENSION));if(!$source)return null;return $media->where('source_media_id',$source->id)->where('mime','image/webp')->filter(fn($m)=>$m->width<=720)->sortByDesc('width')->first()?->id;};
        foreach($catalog['staff']as&$person)$person['preview_media_id']=$preview($person['image_key']);unset($person);
        foreach($catalog['modules']as&$module)$module['preview_media_id']=$preview($module['image_key']);unset($module);
        return response()->view('demo-library',['catalog'=>$catalog])->header('Cache-Control','private, no-store')->header('X-Robots-Tag','noindex, nofollow');
    }
    public function file(DemoLibrary $library,string $key)
    {
        $file=$library->file($key);$headers=['Content-Type'=>$file['mime'],'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow'];
        if(str_starts_with($file['mime'],'image/'))return response()->file($file['absolute'],$headers);
        $headers['Content-Security-Policy']="sandbox; default-src 'none'";
        return response()->download($file['absolute'],basename($file['path']),$headers);
    }
}
