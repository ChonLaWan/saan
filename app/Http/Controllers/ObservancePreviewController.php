<?php

namespace App\Http\Controllers;

use App\Domain\Cms\Observance;
use App\Domain\Cms\WebsiteContent;
use App\Domain\SchoolOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * F7: preview of the observance ribbon on the real home page, before saving and before the start date.
 * The form values are validated like a save, parked in the cache for 10 minutes for this staff user only, and shown through a signed
 * URL that also needs a staff sign-in. It is an internal route (never registered in the public edition), answers private/no-store
 * and noindex, and nothing here reaches the published settings.
 */
class ObservancePreviewController extends Controller
{
    public function __construct(private WebsiteContent $content,private SchoolOperations $ops) {}
    private static function cacheKey(int $user,string $key): string {return 'observance-preview:'.$user.':'.$key;}
    private function staff(): void
    {
        $this->content->authorize();abort_unless((int)auth()->user()->school_id===(int)config('school.school_id'),403);
    }
    public function create(Request $r)
    {
        $this->staff();
        $d=$r->validate(['observance'=>'required|array:'.Observance::keys()]+Observance::rules('observance'));
        if($problem=Observance::problem($d['observance']))throw ValidationException::withMessages(['observance'=>[$problem]]);
        foreach(Observance::media(['observance'=>$d['observance']])as$id){$image=$this->ops->record('cms_media',(int)$id);abort_unless(str_starts_with($image->mime,'image/'),422,'รูปแถบต้องเป็นภาพ');}
        $key=Str::random(40);Cache::put(self::cacheKey((int)auth()->id(),$key),Observance::normalize($d['observance']),now()->addMinutes(10));
        return response()->json(['url'=>URL::temporarySignedRoute('observance.preview',now()->addMinutes(10),['key'=>$key]),'expires_in'=>600],201);
    }
    public function show(Request $r,string $key)
    {
        $this->staff();abort_unless(preg_match('/^[A-Za-z0-9]{40}$/D',$key),404);
        $observance=Cache::get(self::cacheKey((int)auth()->id(),$key))??abort(404);
        $site=app(SchoolWebsiteController::class);$data=$site->home($r)->getData();$context=$site->context(true);$context['site']['observance']=$observance;
        return response()->view('website.home',array_replace($data,$context,['observanceForce'=>true,'observanceAutoPopup'=>false]))
            ->header('Cache-Control','private, no-store, max-age=0')->header('X-Robots-Tag','noindex, nofollow');
    }
}
