<?php

namespace App\Console\Commands;

use App\Domain\Cms\ContentTypes;
use App\Domain\Cms\SiteSettings;
use App\Domain\Cms\WebsiteContent;
use App\Domain\CmsPublishing;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SeedWebsiteDemo extends Command
{
    protected $signature='cms:demo {asset-directory : Directory containing assets/ and documents/}';
    protected $description='Add fictional school website demo without replacing existing content';
    public function handle(WebsiteContent $content,CmsPublishing $themes,SiteSettings $settings): int
    {
        if(!config('school.demo')||!config('school.local_only')||!in_array(config('database.default'),['mysql','mariadb'])){$this->error('[ERR] Local demo mode is required');return 1;}
        $actor=User::where('school_id',config('school.school_id'))->where('role','admin')->where('is_active',true)->firstOrFail();
        $directory=realpath($this->argument('asset-directory'));
        if(!$directory||!is_file($directory.'/assets/generation-manifest.json')){$this->error('[ERR] Missing verified demo assets');return 1;}
        Auth::login($actor);ContentTypes::ensureCategories($actor->school_id);$school=$actor->school_id;
        $media=[];$names=['01-campus-hero'=>'อาคารเรียนในสวนของโรงเรียนสมมติ','02-stem-learning'=>'กิจกรรมทดลองวิทยาศาสตร์จำลอง','03-library-reading'=>'มุมอ่านหนังสือในห้องสมุดจำลอง','04-school-community'=>'ครอบครัวโรงเรียนในกิจกรรมชุมชนจำลอง','05-teacher-collaboration'=>'ครูร่วมออกแบบการเรียนรู้จำลอง','06-digital-classroom'=>'การเรียนรู้ดิจิทัลในห้องเรียนจำลอง'];
        foreach($names as $name=>$alt)$media[$name]=$this->import($directory.'/assets/'.$name.'.png',$alt,'ภาพโรงเรียนสมมติ',$school,$actor->id);
        foreach(['family-guide','activity-form','procurement-sample']as$name)$media[$name]=$this->import($directory.'/documents/'.$name.'.pdf','เอกสารตัวอย่างภาษาอังกฤษ '.$name,'เอกสารตัวอย่าง',$school,$actor->id);
        $ids=[];
        $add=function(string $key,string $type,string $title,string $body,array $extra=[])use($content,$school,&$ids){
            $old=DB::table('posts')->where('school_id',$school)->where('metadata->demo_key',$key)->first();
            if($old){$ids[$key]=$old->id;return $old;}
            $meta=array_replace(['body_format'=>'html','source_kind'=>'mock','demo_key'=>$key],$extra['metadata']??[]);unset($extra['metadata']);
            $data=array_replace(['title'=>$title,'body'=>$body,'excerpt'=>'','content_type'=>$type,'category'=>ContentTypes::TYPES[$type]['category'],'is_pinned'=>false,'cover_media_id'=>null,'intent'=>'publish','metadata'=>$meta,'slug'=>$key],$extra);
            $created=$content->save($data);$ids[$key]=$created['id'];return DB::table('posts')->find($created['id']);
        };
        $campus=$media['01-campus-hero'];$stem=$media['02-stem-learning'];$library=$media['03-library-reading'];$community=$media['04-school-community'];$teachers=$media['05-teacher-collaboration'];$digital=$media['06-digital-classroom'];
        $about=$add('about','page','รู้จักโรงเรียนต้นธารวิทยา','<h2>พื้นที่เล็ก ๆ ของการเติบโต</h2><p>โรงเรียนต้นธารวิทยาเป็นโรงเรียนสมมติสำหรับสาธิต CMS เปิดพื้นที่ให้ทุกคนได้ทดลองจัดการข้อมูล โดยไม่เกี่ยวข้องกับโรงเรียนจริง ชื่อบุคลากร ที่ตั้ง และกิจกรรมในหมวดนี้เป็นข้อมูลจำลองทั้งหมด</p><p>แนวคิดการเรียนรู้เชื่อมโยงห้องเรียน ธรรมชาติ และชุมชน ตั้งแต่ประถมศึกษาถึงมัธยมศึกษา</p>',['cover_media_id'=>$campus]);
        $vision=$add('vision','page','วิสัยทัศน์และพันธกิจ','<h2>เรียนรู้อย่างเข้าใจ เติบโตอย่างมีคุณค่า</h2><ul><li>เรียนรู้จากคำถามและการลงมือทำ</li><li>ใช้เทคโนโลยีอย่างสร้างสรรค์และรับผิดชอบ</li><li>ดูแลความแตกต่างของผู้เรียน</li><li>เชื่อมโยงโรงเรียน ครอบครัว และชุมชน</li></ul><p>เอกสารและแนวคิดในหน้า demo นี้เป็นข้อมูลสมมติ</p>');
        $curriculum=$add('curriculum','page','หลักสูตรและการเรียนรู้','<h2>ออกแบบการเรียนรู้ให้เหมาะกับผู้เรียน</h2><p>ตัวอย่างการจัดกลุ่มสาระและโครงงานข้ามวิชา ครูสามารถนำเรื่องราวจากห้องเรียนมาจัดเป็นข่าว อัลบั้ม และเอกสารการเรียนรู้ในเว็บไซต์เดียวกัน</p><h3>พื้นที่ทดลอง</h3><ul><li>ห้องปฏิบัติการ STEM</li><li>ห้องสมุดและการอ่าน</li><li>ห้องเรียนดิจิทัล</li><li>กิจกรรมศิลปะและชุมชน</li></ul>',['cover_media_id'=>$stem]);
        $admission=$add('admission-guide','page','แนวทางรับสมัครนักเรียน','<h2>เตรียมตัวก้าวเข้าสู่รั้วโรงเรียน</h2><p>นี่คือข้อมูลสมมติสำหรับทดลองหน้าแนะแนวการรับสมัคร ไม่เปิดรับสมัครจริงและไม่เก็บข้อมูลนักเรียน</p><ol><li>ศึกษาหลักสูตรและระดับชั้น</li><li>อ่านประกาศของโรงเรียน</li><li>เตรียมเอกสารตามประกาศจริง</li><li>ติดต่อเจ้าหน้าที่ผ่านช่องทางโรงเรียนจริง</li></ol>',['category'=>'admission','cover_media_id'=>$community,'metadata'=>['attachments'=>[$media['family-guide']]]]);
        $privacy=$add('privacy','page','ความเป็นส่วนตัวและการใช้เว็บไซต์','<h2>ข้อมูลที่ส่งผ่านหน้า demo</h2><p>แบบฟอร์มติดต่อบันทึกชื่อ อีเมล เรื่อง และข้อความลงในฐานข้อมูล demo ภายในเครื่อง เพื่อทดลองกล่องข้อความหลังบ้าน ไม่ได้ส่งอีเมลออกภายนอก กรุณาใช้ข้อมูลสมมติในการทดลอง</p><p>ภาพประกอบเป็นภาพที่สร้างด้วย AI สำหรับโรงเรียนสมมติ ไม่มีการอ้างว่าเป็นภาพบุคคลหรือสถานที่จริง</p><p>ประกาศรับสมัครงานและประกาศจัดซื้อจัดจ้างทุกรายการเป็นประกาศจำลองของโรงเรียนสมมติ</p>');
        $add('history','page','เส้นทางของโรงเรียน','<h2>เริ่มจากพื้นที่ที่ทุกคนเรียนรู้ร่วมกัน</h2><p>เรื่องเล่าประวัติโรงเรียนในหน้านี้เป็นตัวอย่างการจัดเนื้อหา สามารถเปลี่ยนเป็นประวัติจริง ลำดับผู้บริหาร และเหตุการณ์สำคัญของแต่ละโรงเรียนผ่านหลังบ้านได้</p>',['cover_media_id'=>$campus]);
        $stories=[
            ['stem-day','เปิดห้องทดลองให้คำถามเล็ก ๆ กลายเป็นการค้นพบ','นักเรียนทดลองสังเกตน้ำ แสง และพืช ฝึกตั้งสมมติฐาน บันทึกผล และอธิบายสิ่งที่เรียนรู้ด้วยภาษาของตนเอง',$stem,'activity'],
            ['reading-corner','หนึ่งเล่ม หนึ่งเรื่องราว มุมอ่านหนังสือของเรา','ห้องสมุดชวนครอบครัวโรงเรียนเลือกหนังสือที่ชอบ แบ่งปันประโยคที่ประทับใจ และอ่านร่วมกันในทุกสัปดาห์',$library,'activity'],
            ['digital-learning','เรียนรู้ดิจิทัลอย่างสร้างสรรค์และรู้เท่าทัน','ห้องเรียนตัวอย่างฝึกค้นข้อมูล ตรวจที่มา และสร้างชิ้นงานดิจิทัล โดยเคารพสิทธิของผู้อื่นและดูแลข้อมูลส่วนบุคคล',$digital,'news'],
            ['community-day','โรงเรียนและชุมชน ร่วมสร้างพื้นที่เรียนรู้','ตัวอย่างกิจกรรมครอบครัวโรงเรียนร่วมดูแลสวนและพื้นที่ส่วนกลาง เรียนรู้การทำงานร่วมกันผ่านสิ่งเล็ก ๆ รอบตัว',$community,'activity'],
            ['teacher-learning','ครูร่วมออกแบบบทเรียน จากคำถามของผู้เรียน','กลุ่มครูแลกเปลี่ยนการสังเกตชั้นเรียน ออกแบบกิจกรรม และสะท้อนผล เพื่อปรับบทเรียนให้ตอบโจทย์ผู้เรียน',$teachers,'news'],
            ['new-term','ต้อนรับภาคเรียนใหม่ ด้วยพื้นที่ที่พร้อมสำหรับทุกคน','ตัวอย่างข่าวแจ้งเตรียมความพร้อมภาคเรียน 2 ครอบครัวสามารถอ่านปฏิทิน ดาวน์โหลดคู่มือ และติดตามประกาศในเว็บไซต์ได้',$campus,'news'],
        ];
        foreach($stories as[$key,$title,$body,$cover,$category])$add($key,'article',$title,'<p>'.$body.'</p><h2>เรียนรู้จากการลงมือทำ</h2><p>โรงเรียนต้นธารวิทยาเป็นโรงเรียนสมมติ ข่าว กิจกรรม และภาพในรายการนี้จัดทำเพื่อทดลอง CMS เท่านั้น</p>',['cover_media_id'=>$cover,'category'=>$category]);
        $add('admission-news','article','ตัวอย่างประกาศเตรียมรับสมัครนักเรียน ปีการศึกษา 2570','<p>ประกาศจำลองสำหรับทดลอง CMS ไม่เปิดรับสมัครจริง กรุณาใช้ข้อมูลสมมติเท่านั้น สามารถดูแนวทางและเอกสารตัวอย่างจากเมนูรับสมัครนักเรียน</p>',['category'=>'admission','cover_media_id'=>$community,'metadata'=>['attachments'=>[$media['family-guide']]]]);
        $add('job-demo','job','ตัวอย่างรับสมัครครูผู้ช่วยกิจกรรมการเรียนรู้','<p>ประกาศจำลองเพื่อทดสอบการแสดงตำแหน่ง คุณสมบัติ กำหนดรับสมัคร และเอกสารแนบ ไม่ใช่การเปิดรับสมัครงานจริง</p>',['cover_media_id'=>$teachers,'metadata'=>['position'=>'ครูผู้ช่วยกิจกรรมการเรียนรู้ (สมมติ)','positions'=>1,'opens_on'=>'2026-11-05','deadline'=>'2026-11-30','qualification'=>'ตัวอย่างคุณสมบัติ: สนใจการเรียนรู้ผ่านโครงงานและการทำงานร่วมกัน','salary'=>'ค่าตอบแทนสมมติ โปรดแก้ตามประกาศจริง']]);
        $add('job-language-demo','job','ตัวอย่างรับสมัครครูอัตราจ้าง วิชาภาษาอังกฤษและภาษาจีน','<p>ประกาศจำลองของโรงเรียนสมมติ รับสมัครครูอัตราจ้างวิชาภาษาอังกฤษและภาษาจีน จำนวน 2 อัตรา ใช้ทดลองการแสดงประกาศที่พ้นกำหนดรับสมัครแล้ว ไม่ใช่การรับสมัครงานจริง</p>',['metadata'=>['deadline'=>'2026-10-25','position'=>'ครูภาษาอังกฤษและภาษาจีน (สมมติ)','positions'=>2]]);
        $add('job-chemistry-demo','job','ตัวอย่างรับสมัครครูอัตราจ้าง วิชาเคมี','<p>ประกาศจำลองของโรงเรียนสมมติ รับสมัครครูอัตราจ้างวิชาเคมี จำนวน 1 อัตรา กำหนดรับสมัคร 21–28 ตุลาคม 2569 ซึ่งพ้นกำหนดแล้ว ไม่ใช่การรับสมัครงานจริง</p>',['metadata'=>['opens_on'=>'2026-10-21','deadline'=>'2026-10-28','position'=>'ครูผู้สอนวิชาเคมี (สมมติ)','positions'=>1]]);
        $add('procurement-demo','procurement','ตัวอย่างร่างขอบเขตงาน ปรับปรุงพื้นที่การเรียนรู้','<p>ประกาศจำลองสำหรับทดลองเผยแพร่ร่าง TOR วงเงิน วิธีจัดหา และเอกสารแนบ ไม่มีการเสนอราคา รับเงิน หรือจัดซื้อจริง เอกสารแนบภาษาอังกฤษมีเครื่องหมายแสดงว่าเป็นตัวอย่าง</p>',['metadata'=>['method'=>'specific','stage'=>'tor','document_no'=>'DEMO-2569-01','budget'=>120000,'attachments'=>[$media['procurement-sample']]]]);
        $add('procurement-contract-demo','procurement','ตัวอย่างรายงานความก้าวหน้าการจัดหาสื่อการเรียนรู้','<p>ข้อมูลจำลองเพื่อแสดงขั้นตอนสัญญาและการส่งมอบ สามารถจัดเก็บเลขที่เอกสาร วงเงิน และไฟล์ประกอบในหมวดเดียวกันได้</p>',['metadata'=>['method'=>'selection','stage'=>'contract','document_no'=>'DEMO-2569-02','budget'=>45000]]);
        $add('procurement-dormitory-demo','procurement','ตัวอย่างประกาศประกวดราคา ปรับปรุงอาคารหอพักนักเรียน','<p>ประกาศจำลองสำหรับทดลองขั้นเชิญชวนด้วยวิธีประกวดราคาอิเล็กทรอนิกส์ เลขที่เอกสารและราคากลางเป็นตัวเลขสมมติ ไม่มีการยื่นข้อเสนอหรือจัดจ้างจริง</p>',['metadata'=>['method'=>'e-bidding','stage'=>'invitation','document_no'=>'DEMO-2569-03','budget'=>2400000]]);
        $add('procurement-textbook-award-demo','procurement','ตัวอย่างประกาศผู้ชนะการเสนอราคา จัดซื้อหนังสือเรียน','<p>ประกาศจำลองสำหรับทดลองหมวดประกาศผลจัดซื้อโดยวิธีคัดเลือก หนังสือเรียนปีการศึกษา 2569 ชื่อผู้ชนะและวงเงินเป็นข้อมูลสมมติ ไม่มีการจัดซื้อจริง</p>',['metadata'=>['method'=>'selection','stage'=>'award','document_no'=>'DEMO-2569-04','budget'=>386500]]);
        $add('procurement-supplies-award-demo','procurement','ตัวอย่างประกาศผู้ชนะการเสนอราคา จัดซื้อวัสดุสำนักงาน','<p>ประกาศจำลองสำหรับทดลองหมวดประกาศผลจัดซื้อโดยวิธีเฉพาะเจาะจง วัสดุสำนักงานประจำภาคเรียน วงเงินเป็นตัวเลขสมมติ ไม่มีการจัดซื้อจริง</p>',['metadata'=>['method'=>'specific','stage'=>'award','document_no'=>'DEMO-2569-05','budget'=>48200]]);
        foreach([
            ['learning-album','จากห้องเรียนสู่โลกกว้าง',$stem,[$stem,$library,$digital]],
            ['community-album','พื้นที่ของครอบครัวโรงเรียน',$community,[$community,$campus,$teachers]],
            ['campus-album','มุมเล็ก ๆ ในรั้วโรงเรียน',$campus,[$campus,$library,$digital]],
        ]as[$key,$title,$cover,$gallery])$add($key,'album',$title,'<p>อัลบั้มภาพที่สร้างด้วย AI สำหรับโรงเรียนสมมติ ใช้ทดลองการจัดภาพและดูรายละเอียดใน CMS</p>',['cover_media_id'=>$cover,'metadata'=>['gallery'=>$gallery]]);
        foreach([['family-guide','คู่มือครอบครัวโรงเรียน (ตัวอย่างภาษาอังกฤษ)'],['activity-form','แบบฟอร์มกิจกรรม (ตัวอย่างภาษาอังกฤษ)'],['procurement-sample','เอกสารจัดซื้อจัดจ้าง (ตัวอย่างภาษาอังกฤษ)']]as[$key,$title])$add('download-'.$key,'download',$title,'<p>ไฟล์ PDF จำลองสำหรับทดสอบดาวน์โหลด ไม่ใช่เอกสารราชการ ใช้ภาษาอังกฤษเพื่อแยกจากเอกสารจริงอย่างชัดเจน</p>',['metadata'=>['attachments'=>[$media[$key]]]]);
        foreach([
            ['director','ดร.ต้นธาร เรียนดี (สมมติ)','ผู้อำนวยการโรงเรียน','บริหารสถานศึกษา'],
            ['academic','ครูพิมพ์ใจ ใฝ่รู้ (สมมติ)','หัวหน้างานวิชาการ','วิชาการ'],
            ['science','ครูเมธา ทดลอง (สมมติ)','ครูวิทยาศาสตร์','วิทยาศาสตร์และเทคโนโลยี'],
            ['thai','ครูรินรดา อ่านเขียน (สมมติ)','ครูภาษาไทย','ภาษาไทย'],
            ['primary','ครูธารา อ่อนโยน (สมมติ)','ครูประถมศึกษา','ประถมศึกษา'],
            ['support','คุณกานต์ ประสานงาน (สมมติ)','เจ้าหน้าที่ธุรการ','สนับสนุนการศึกษา'],
        ]as[$key,$name,$position,$department])$add('staff-'.$key,'person',$name,'<p>ข้อมูลบุคลากรสมมติสำหรับทดลองหน้าแนะนำทีมงาน ไม่มีการเผยแพร่ข้อมูลส่วนตัวของบุคคลจริง</p>',['metadata'=>['job_title'=>$position,'department'=>$department,'contact_email'=>$key.'@tontharn.example']]);
        foreach([
            ['open-house','เปิดบ้านการเรียนรู้','2026-11-14T09:00','2026-11-14T15:00','ลานเรียนรู้ (สมมติ)',$community],
            ['science-week','สัปดาห์คำถามวิทยาศาสตร์','2026-11-23T08:30','2026-11-27T15:30','ห้องปฏิบัติการ (สมมติ)',$stem],
            ['family-reading','ครอบครัวนักอ่าน','2026-12-05T09:00','2026-12-05T12:00','ห้องสมุด (สมมติ)',$library],
        ]as[$key,$title,$start,$end,$location,$cover])$add($key,'event',$title,'<p>กิจกรรมสมมติสำหรับทดลองปฏิทินเว็บไซต์ ไม่มีการลงทะเบียนหรือจัดกิจกรรมจริง</p>',['cover_media_id'=>$cover,'metadata'=>['starts_at'=>$start,'ends_at'=>$end,'location'=>$location]]);
        $add('welcome-banner','banner','ยินดีต้อนรับสู่พื้นที่ทดลอง สาน (Saan)','<p>ทดลองเขียนข่าว ปรับธีม และจัดเมนูเว็บไซต์ได้จากหลังบ้าน</p>',['excerpt'=>'โรงเรียน ชื่อบุคลากร และกิจกรรมใน demo เป็นข้อมูลสมมติ ภาพประกอบสร้างด้วย AI','metadata'=>['url'=>'/portal/pages/'.$about->slug,'placement'=>'notice','sort_order'=>0]]);
        foreach([
            ['admission-service','รับสมัครนักเรียน','แนวทางและเอกสารสำหรับครอบครัว','/portal/pages/'.$admission->slug],
            ['documents-service','เอกสารดาวน์โหลด','คู่มือ แบบฟอร์ม และเอกสารตัวอย่าง','/portal/downloads'],
            ['calendar-service','ปฏิทินกิจกรรม','วันสำคัญและกิจกรรมการเรียนรู้','/portal/events'],
            ['contact-service','ติดต่อโรงเรียน','ส่งข้อความถึงทีมงานผ่านเว็บไซต์','/portal/contact'],
        ]as$i=>[$key,$title,$excerpt,$link])$add($key,'service',$title,'<p>'.$excerpt.'</p>',['excerpt'=>$excerpt,'metadata'=>['url'=>$link,'sort_order'=>$i]]);
        $theme=$themes->theme($school);
        if($theme['version']===0){
            $new=array_replace($themes->defaults(),['school_name'=>'โรงเรียนต้นธารวิทยา','tagline'=>'เรียนรู้อย่างเข้าใจ เติบโตอย่างมีคุณค่า','hero_title'=>"ทุกการเรียนรู้\nเริ่มจากความเป็นไปได้",'hero_description'=>'โรงเรียนสมมติที่เชื่อมโยงห้องเรียน ธรรมชาติ และชุมชน เพื่อทดลองเว็บไซต์ที่ทุกคนดูแลได้ด้วยตัวเอง','hero_media_id'=>$campus,'address'=>'ที่ตั้งสมมติสำหรับ demo ภายในเครื่อง ประเทศไทย','phone'=>'0xx-xxx-xxxx']);
            $themes->themeSave($new,$theme['version'],true);
        }
        $current=$settings->read($school);
        if(!$current['version']){
            $site=array_replace(SiteSettings::defaults(),['badge'=>'โรงเรียนสมมติ · WEBSITE DEMO','intro_heading'=>'ทุกคนมีพื้นที่สำหรับการเติบโต','intro_text'=>'จากคำถามเล็ก ๆ ในห้องเรียน ไปสู่ความเข้าใจโลกที่กว้างขึ้น โรงเรียนและครอบครัวเติบโตไปด้วยกัน','mission'=>'เรียนรู้อย่างเข้าใจ เติบโตอย่างมีคุณค่า','email'=>'hello@tontharn.example','statistics'=>[['value'=>'24','label'=>'นักเรียนสมมติ'],['value'=>'6','label'=>'ครูในชุดข้อมูลทดลอง'],['value'=>'3','label'=>'ห้องเรียนตัวอย่าง']],'footer_text'=>'ข้อมูลโรงเรียนสมมติ · ภาพสร้างด้วย AI · ประกาศอ้างอิงมีลิงก์ต้นฉบับ',
                'homepage_sections'=>['services','page:'.$about->id,'news','notices','albums','events'],
                'navigation'=>[
                    ['label'=>'หน้าหลัก','url'=>'/portal'],
                    ['label'=>'รู้จักโรงเรียน','url'=>'/portal/pages/'.$about->slug,'children'=>[['label'=>'ข้อมูลโรงเรียน','url'=>'/portal/pages/'.$about->slug,'children'=>[['label'=>'วิสัยทัศน์และพันธกิจ','url'=>'/portal/pages/'.$vision->slug],['label'=>'หลักสูตร','url'=>'/portal/pages/'.$curriculum->slug]]],['label'=>'บุคลากร','url'=>'/portal/people']]],
                    ['label'=>'ข่าวและกิจกรรม','url'=>'/portal/news','children'=>[['label'=>'ข่าวทั้งหมด','url'=>'/portal/news'],['label'=>'อัลบั้มภาพ','url'=>'/portal/albums'],['label'=>'ปฏิทินกิจกรรม','url'=>'/portal/events']]],
                    ['label'=>'ประกาศ','url'=>'/portal/procurement','children'=>[['label'=>'รับสมัครนักเรียน','url'=>'/portal/pages/'.$admission->slug],['label'=>'รับสมัครงาน','url'=>'/portal/jobs'],['label'=>'จัดซื้อจัดจ้าง','url'=>'/portal/procurement']]],
                    ['label'=>'เอกสาร','url'=>'/portal/downloads'],['label'=>'ติดต่อ','url'=>'/portal/contact'],
                ]]);$settings->save($site,0,true);
        }
        if(class_exists(\Saan\Modules\WebCms\HubDemo::class)&&\Illuminate\Support\Facades\Schema::hasTable('cms_hubs')&&\Illuminate\Support\Facades\Schema::hasColumn('cms_hub_categories','rule')){$hubs=\Saan\Modules\WebCms\HubDemo::seed();$this->info('[OK] Hub pages: created '.count($hubs['created']).', kept '.count($hubs['skipped']));}
        Auth::logout();$this->info('[OK] Demo content ready: '.count($ids).' entries, '.count($media).' media. Existing content preserved.');return 0;
    }
    private function import(string $path,string $alt,string $folder,int $school,int $actor): int
    {
        if(!is_file($path))throw new \RuntimeException('Missing asset: '.basename($path));
        $mime=mime_content_type($path);$destination='cms/'.$school.'/demo-'.hash_file('sha256',$path).'.'.pathinfo($path,PATHINFO_EXTENSION);
        $existing=DB::table('cms_media')->where('school_id',$school)->where('path',$destination)->first();if($existing)return $existing->id;
        $size=$mime==='application/pdf'?[0,0]:getimagesize($path);if(!Storage::disk('local')->put($destination,file_get_contents($path)))throw new \RuntimeException('Could not store demo media '.$destination);
        return DB::table('cms_media')->insertGetId(['school_id'=>$school,'uploaded_by'=>$actor,'path'=>$destination,'mime'=>$mime,'bytes'=>filesize($path),'width'=>$size[0],'height'=>$size[1],'alt'=>$alt,'original_name'=>basename($path),'folder'=>$folder,'created_at'=>now(),'updated_at'=>now()]);
    }
}
