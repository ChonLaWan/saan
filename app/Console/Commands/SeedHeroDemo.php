<?php
namespace App\Console\Commands;

use App\Domain\Cms\ContentTypes;
use App\Domain\Cms\WebsiteContent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SeedHeroDemo extends Command
{
    protected $signature='cms:hero-demo';
    protected $description='Add three editable fictional hero slides without replacing existing content';
    public function handle(WebsiteContent $content): int
    {
        if(!config('school.demo')){$this->error('Demo mode is required');return self::FAILURE;}
        $school=(int)config('school.school_id');$admin=User::where('school_id',$school)->where('role','admin')->where('is_active',true)->firstOrFail();Auth::login($admin);ContentTypes::ensureCategories($school);
        $photos=DB::table('cms_media')->where('school_id',$school)->where('mime','like','image/%')->whereNull('source_media_id')->orderBy('id')->limit(3)->pluck('id');
        if($photos->count()<3){$this->error('Three existing school demo photos are required');return self::FAILURE;}
        $items=[['ทุกการเรียนรู้'."\n".'เริ่มจากความเป็นไปได้','ห้องเรียน ธรรมชาติ และชุมชน เชื่อมโยงการเรียนรู้ให้เด็กทุกคนเติบโตอย่างมีความหมาย','A PLACE TO LEARN & GROW','เรื่องราวจากโรงเรียน','/portal/news'],['พื้นที่สำหรับคำถาม'."\n".'และการค้นพบใหม่','เรียนรู้ผ่านการลงมือทำ เปิดพื้นที่ให้ความสงสัยเล็ก ๆ กลายเป็นความเข้าใจที่ยิ่งใหญ่','CURIOUS MINDS, BRIGHT FUTURES','กิจกรรมการเรียนรู้','/portal/albums'],['หนึ่งโรงเรียน'."\n".'หลายเส้นทางการเติบโต','ครอบครัว ครู และชุมชน ร่วมสร้างพื้นที่ปลอดภัยและโอกาสในการเรียนรู้สำหรับทุกคน','TOGETHER WE GROW','รู้จักโรงเรียน','/portal/pages']];
        foreach($items as$i=>$item){$key='hero-release-v1-'.($i+1);if(DB::table('posts')->where('school_id',$school)->where('metadata->demo_key',$key)->exists())continue;
            $content->save(['title'=>$item[0],'body'=>'สไลด์ข้อมูลสมมติสำหรับสาธิต CMS โรงเรียน ภาพประกอบสร้างด้วย AI','excerpt'=>$item[1],'content_type'=>'banner','category'=>'news','cover_media_id'=>$photos[$i],'is_pinned'=>false,'intent'=>'publish','metadata'=>['demo_key'=>$key,'placement'=>'hero','sort_order'=>$i,'kicker'=>$item[2],'button_label'=>$item[3],'url'=>$item[4],'secondary_label'=>'ติดต่อโรงเรียน','secondary_url'=>'/portal/contact','focal_x'=>50,'focal_y'=>50,'overlay'=>.78,'text_align'=>'left']]);
        }
        Auth::logout();$this->info('[OK] Three editable demo slides are available; existing content preserved');return self::SUCCESS;
    }
}
