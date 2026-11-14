<?php
namespace App\Console\Commands;
use App\Domain\Cms\ImageVariant;
use App\Domain\Cms\WebsiteContent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OptimizeHeroDemo extends Command
{
    protected $signature='cms:optimize-hero-demo';
    protected $description='Create reversible desktop/mobile WebP variants for marked fictional demo slides only';
    public function handle(ImageVariant $images,WebsiteContent $content):int
    {
        if(!config('school.demo')){$this->error('Demo mode is required');return self::FAILURE;}$school=(int)config('school.school_id');$admin=User::where('school_id',$school)->where('role','admin')->where('is_active',true)->firstOrFail();Auth::login($admin);$changed=0;$proof=[];
        foreach(DB::table('posts')->where('school_id',$school)->where('content_type','banner')->whereNull('deleted_at')->where('status','published')->whereNull('draft_payload')->get()as$row){
            $entry=$content->read($row->id);$d=$entry['draft'];if(!str_starts_with($d['metadata']['demo_key']??'','hero-release-v1-'))continue;$source=DB::table('cms_media')->where('school_id',$school)->find($d['cover_media_id']);if(!$source||$source->source_media_id)continue;
            $options=['width'=>1280,'ratio'=>'16:9','format'=>'webp','quality'=>85,'focal_x'=>50,'focal_y'=>50];$desktop=$images->create($source->id,$options);$mobile=$images->create($source->id,array_replace($options,['width'=>720,'ratio'=>'9:16']));$d['cover_media_id']=$desktop['id'];$d['metadata']['mobile_media_id']=$mobile['id'];$content->save($d+['version'=>$entry['version'],'intent'=>'publish'],$row->id);$changed++;$proof[]=['post_id'=>$row->id,'source_media_id'=>$source->id,'source_bytes'=>$source->bytes,'desktop'=>$desktop,'mobile'=>$mobile];
        }
        Auth::logout();$this->line(json_encode(['changed'=>$changed,'variants'=>$proof],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));return self::SUCCESS;
    }
}
