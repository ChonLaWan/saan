<?php
namespace App\Http\Controllers;

use App\Domain\Cms\{Presentation,PresentationSchema,WebsiteContent};
use Illuminate\Http\Request;

class PresentationController extends Controller
{
    public function read(Presentation $presentation){return response()->json($presentation->read());}
    private function validated(Request $request,bool $saving):array
    {
        $request->validate(['section'=>'required|in:branding,header,hero']);
        return$request->validate(PresentationSchema::requestRules($request->input('section'))+['section'=>'required|in:branding,header,hero','settings_version'=>($saving?'required':'sometimes').'|integer|min:0','theme_version'=>($saving?'required':'sometimes').'|integer|min:0','intent'=>($saving?'required':'sometimes').'|in:draft,publish','preview_mode'=>'sometimes|in:light,dark,light-high,dark-high','slide_id'=>'sometimes|nullable|integer|min:1']);
    }
    public function save(Request $request,Presentation $presentation){$presentation->read();return response()->json($presentation->save($this->validated($request,true)));}
    public function preview(Request $request,Presentation $presentation,WebsiteContent $content)
    {
        $presentation->read();$data=$this->validated($request,false);$public=app(SchoolWebsiteController::class)->home($request)->getData();$public=array_replace($public,$presentation->previewData($data),['presentationPreviewMode'=>$data['preview_mode']??'light','preview'=>false]);
        if(!empty($data['slide_id'])){$entry=$content->read((int)$data['slide_id']);abort_unless(!$entry['deleted_at']&&$entry['draft']['content_type']==='banner'&&($entry['draft']['metadata']['placement']??'')==='hero',404);$public['banners']=collect([(object)array_replace($entry['draft'],['id'=>$entry['id'],'metadata'=>json_encode($entry['draft']['metadata'])])]);}
        $html=view('website.home',$public)->render();$html=preg_replace('~<script\b[^>]*>.*?</script>~is','',$html);$html=str_replace('</head>','<style>a,button,input,select,summary{pointer-events:none!important}.will-enter{opacity:1!important;transform:none!important}</style></head>',$html);
        return response()->json(['html'=>$html])->header('Cache-Control','private, no-store');
    }
}
