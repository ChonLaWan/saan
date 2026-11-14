<?php
namespace App\Console\Commands;

use App\Domain\Cms\{ContentTypes,DemoLibrary,ImageVariant,WebsiteContent};
use App\Domain\SchoolOperations;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Auth,DB,Storage};

class InstallMockLibrary extends Command
{
    protected $signature='cms:mock-library {directory : Verified fictional asset package}';
    protected $description='Add fictional images, Thai documents and private design datasets without replacing user content';
    private array $createdPaths=[];
    private int $updatedMedia=0;
    public function handle(WebsiteContent $content,ImageVariant $variants,DemoLibrary $library,SchoolOperations $ops):int
    {
        $this->createdPaths=[];$this->updatedMedia=0;
        if(!config('school.demo')||!config('school.local_only')){$this->error('[ERR] Local demo mode is required');return self::FAILURE;}
        $root=realpath($this->argument('directory'));if(!$root||!is_file($root.'/catalog.json')){$this->error('[ERR] Package catalog not found');return self::FAILURE;}
        $school=(int)config('school.school_id');$disk=Storage::disk('local');$disk->makeDirectory('demo-library/'.$school);
        $lock=fopen($disk->path('demo-library/'.$school.'/install.lock'),'c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){$this->error('[ERR] Another import is running');return self::FAILURE;}
        $committed=false;
        try{
            $catalog=json_decode(file_get_contents($root.'/catalog.json'),true,512,JSON_THROW_ON_ERROR);
            if(($catalog['fictional']??false)!==true||($catalog['version']??null)!==DemoLibrary::VERSION||count($catalog['modules']??[])!==24)throw new \RuntimeException('Invalid fictional package version');
            $resolved=[];
            foreach($catalog['files']as$key=>$file){
                if(!preg_match('~^[A-Za-z0-9_-]+$~D',$key)||!preg_match('~^(documents|previews|datasets|concepts|artwork|videos)/[A-Za-z0-9._-]+$~D',$file['path']))throw new \RuntimeException('Invalid asset path');
                $path=realpath($root.'/'.$file['path']);$prefix=str_replace('\\','/',$root).'/';
                if(!$path||!str_starts_with(str_replace('\\','/',$path),$prefix)||!hash_equals($file['sha256'],hash_file('sha256',$path))||filesize($path)!==$file['bytes'])throw new \RuntimeException('Asset checksum mismatch: '.$key);
                $resolved[$key]=$path;
            }
            $actor=User::where('school_id',$school)->where('role','admin')->where('is_active',true)->firstOrFail();Auth::login($actor);
            foreach($resolved as$key=>$source){$target=$library->prefix().'/'.$catalog['files'][$key]['path'];$this->copy($source,$target);}
            $result=DB::transaction(function()use($catalog,$resolved,$school,$actor,$content,$variants,$ops){
                ContentTypes::ensureCategories($school);$media=[];$before=DB::table('cms_media')->where('school_id',$school)->count();$created=0;$updated=0;$skipped=[];
                $image=function(string $key)use(&$media,$resolved,$catalog,$school,$actor,$variants){
                    if(isset($media[$key]))return$media[$key];$file=$catalog['files'][$key];$alt='ภาพหรือหน้าปกสมมติ '.basename($file['path']);
                    foreach($catalog['staff']as$person)if(($person['image_key']??'')===$key)$alt='ภาพ AI ของ '.$person['name'];
                    foreach($catalog['documents']as$doc)if($doc['preview_key']===$key)$alt='ภาพหน้าแรก '.$doc['title'];
                    $source=$this->import($resolved[$key],$file['path'],$school,$actor->id,$alt);
                    $row=DB::table('cms_media')->find($source);$width=min(str_starts_with(basename($file['path']),'staff-')?480:720,(int)$row->width);$height=(int)round($width*$row->height/$row->width);
                    $existing=DB::table('cms_media')->where('school_id',$school)->where('source_media_id',$source)->where('width',$width)->where('height',$height)->where('mime','image/webp')->first();
                    if($existing){$this->refineAlt($existing,$alt);return$media[$key]=$existing->id;}
                    $variant=$variants->create($source,['width'=>$width,'ratio'=>'original','format'=>'webp','quality'=>85,'focal_x'=>50,'focal_y'=>50]);$this->createdPaths[]=DB::table('cms_media')->find($variant['id'])->path;return$media[$key]=$variant['id'];
                };
                $document=function(string$key)use(&$media,$resolved,$catalog,$school,$actor){$alt='เอกสารภาษาไทยสมมติ '.basename($catalog['files'][$key]['path']);foreach($catalog['documents']as$doc)if($doc['pdf_key']===$key)$alt=$doc['title'].' (ข้อมูลสมมติ)';if(str_starts_with($key,'video-'))$alt='วีดิทัศน์สไลด์ภาพ AI (สมมติ) '.substr($key,6);return$media[$key]??=$this->import($resolved[$key],$catalog['files'][$key]['path'],$school,$actor->id,$alt);};
                $add=function(string$key,string$type,string$title,string$body,string$cover,array$metadata=[])use(&$created,$content,$school,$image){
                    if(DB::table('posts')->where('school_id',$school)->where('metadata->demo_key',$key)->exists())return;
                    $content->save(['content_type'=>$type,'category'=>ContentTypes::TYPES[$type]['category'],'title'=>$title,'body'=>$body,'excerpt'=>'','cover_media_id'=>$image($cover),'is_pinned'=>false,'slug'=>$key,'intent'=>'publish','metadata'=>array_replace(['demo_key'=>$key,'source_kind'=>'mock','body_format'=>'html'],$metadata)]);$created++;
                };
                foreach($catalog['public_documents']as$d){
                    if(DB::table('posts')->where('school_id',$school)->where('metadata->demo_key','mock-library-'.$d['id'])->exists())continue;
                    $type=($d['type']??'download');$meta=['attachments'=>[$document($d['pdf_key'])]];
                    if($type==='newsletter')$meta+=['issue_no'=>$d['issue_no'],'issued_on'=>$d['issued_on']];
                    $body='<h2>'.$d['title'].'</h2><p>'.$d['text'].'</p><ul>';foreach($d['rows']as$r)$body.='<li><strong>'.$r[0].'</strong>: '.$r[1].'</li>';$body.='</ul><p>เปิดเอกสาร PDF ภาษาไทยในส่วนเอกสารแนบ ภาพหน้าปกมาจากหน้าแรกของไฟล์ฉบับนี้</p>';
                    $add('mock-library-'.$d['id'],$type,$d['title'],$body,$d['preview_key'],$meta);
                }
                foreach([
                    ['campus-story','สไลด์ภาพ: เดินชมโรงเรียนสมมติ','image-01-campus-hero','อาคารเรียนในสวน มุมอ่านหนังสือ และพื้นที่ชุมชน'],
                    ['learning-story','สไลด์ภาพ: ห้องเรียนและคำถาม','image-02-stem-learning','กิจกรรม STEM ห้องเรียนดิจิทัล และการร่วมออกแบบของครู'],
                    ['reading-story','สไลด์ภาพ: หนังสือและครอบครัว','image-03-library-reading','ห้องสมุด พื้นที่ครอบครัว และสวนในโรงเรียน'],
                ]as[$key,$title,$cover,$description])$add('mock-video-'.$key,'video',$title,'<p>วีดิทัศน์สาธิตความยาว 9 วินาที เรียงจากภาพนิ่งที่สร้างด้วย AI ไม่มีเสียง ไม่ใช่ภาพบันทึกกิจกรรมจริง</p><p>คำอธิบายภาพ: '.$description.'</p>',$cover,['video_media_id'=>$document('video-'.$key)]);
                $add('mock-art-learning','article','ห้องศิลปะของเรา: ทดลองสีจากธรรมชาติ','<h2>วัสดุใกล้ตัวกับคำถามใหม่</h2><p>เรื่องเล่าสมมติสำหรับสาธิตข่าว ครูและนักเรียนทดลองเลือกใบไม้ สังเกตสี และออกแบบงานศิลปะจากวัสดุใกล้ตัว</p><p>ภาพประกอบสร้างด้วย AI ไม่ใช่การรายงานกิจกรรมของโรงเรียนจริง</p>','image-cover-creative',['tags'=>['ศิลปะ','การเรียนรู้']]);
                $add('mock-resource-room','article','รู้จักห้องสื่อและอุปกรณ์การเรียนรู้','<h2>หยิบใช้ แบ่งปัน และดูแลร่วมกัน</h2><p>ตัวอย่างข่าวแนะนำพื้นที่อุปกรณ์กีฬา สื่อวิทยาศาสตร์ และสื่อการเรียนรู้ โรงเรียนและรายการพัสดุทั้งหมดเป็นข้อมูลสมมติ</p>','image-cover-resources',['tags'=>['แหล่งเรียนรู้']]);
                $add('mock-creative-album','album','ภาพเล่าเรื่อง: ศิลปะ ห้องสื่อ และครอบครัว','<p>อัลบั้มภาพ AI สำหรับโรงเรียนสมมติ ใช้ทดลองแสดงภาพหลายภาพและเพิ่มคำอธิบายใน CMS</p>','image-cover-creative',['gallery'=>[$image('image-cover-creative'),$image('image-cover-resources'),$image('image-cover-care')]]);
                foreach(DB::table('posts')->where('school_id',$school)->whereNull('deleted_at')->where('status','published')->get()as$row){
                    if($row->draft_payload||$row->scheduled_at){$skipped[]=$row->id;continue;}
                    $entry=$content->read($row->id);$data=$entry['draft'];$meta=$data['metadata'];$change=false;
                    if(!$row->cover_media_id){
                        $key=$meta['demo_key']??'';$cover=match($row->content_type){'person'=>'image-'.$key,'download'=>'image-cover-documents','job','procurement'=>'image-cover-opportunities','service'=>'image-cover-documents','page'=>$key==='privacy'?'image-cover-documents':'image-01-campus-hero',default=>'image-01-campus-hero'};
                        foreach(['family-guide','activity-form','procurement-sample']as$legacy)if($key==='download-'.$legacy)$cover='legacy-'.$legacy;
                        if(!isset($catalog['files'][$cover]))$cover='image-cover-care';
                        $data['cover_media_id']=$image($cover);$change=true;
                    }
                    if(($meta['source_kind']??'mock')==='mock'&&in_array($row->content_type,['job','procurement'])&&!DB::table('cms_media')->where('school_id',$school)->whereIn('id',$meta['attachments']??[])->where('original_name',$row->content_type==='job'?'job-sample.pdf':'procurement-summary.pdf')->exists()){
                        $attachment=$document($row->content_type==='job'?'job-sample-pdf':'procurement-summary-pdf');$old=$meta['attachments']??[];
                        if(!in_array($attachment,$old)&&count($old)<12){$meta['attachments']=array_merge($old,[$attachment]);$data['metadata']=$meta;$change=true;}
                    }
                    if($change){$content->save($data+['version'=>$entry['version'],'intent'=>'publish'],$row->id);$updated++;}
                }
                return['created_posts'=>$created,'updated_posts'=>$updated,'created_media'=>DB::table('cms_media')->where('school_id',$school)->count()-$before,'skipped_active_drafts'=>$skipped];
            });
            $committed=true;$json=json_encode($catalog,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);$target=$library->prefix().'/catalog.json';
            if(!$disk->exists($target)||$disk->get($target)!==$json){$temp=$target.'.'.bin2hex(random_bytes(4)).'.tmp';if(!$disk->put($temp,$json)||$disk->size($temp)!==strlen($json))throw new \RuntimeException('Could not write catalog '.$target);$full=$disk->path($target);if(!@rename($disk->path($temp),$full)){$disk->delete($temp);throw new \RuntimeException('Could not replace catalog '.$target);}}
            $this->line(json_encode($result+['updated_media_metadata'=>$this->updatedMedia,'verified_files'=>count($resolved),'modules'=>24,'documents'=>count($catalog['documents'])],JSON_UNESCAPED_UNICODE));return self::SUCCESS;
        }catch(\Throwable$e){if(!$committed)foreach(array_unique($this->createdPaths)as$path)$disk->delete($path);$this->error('[ERR] '.$e->getMessage());return self::FAILURE;}
        finally{Auth::logout();flock($lock,LOCK_UN);fclose($lock);}
    }
    private function copy(string$source,string$target):void
    {
        $disk=Storage::disk('local');if($disk->exists($target)){if(!hash_equals(hash_file('sha256',$source),hash_file('sha256',$disk->path($target))))throw new \RuntimeException('Existing file differs; preserve it and stop');return;}
        if(!$disk->put($target,file_get_contents($source)))throw new \RuntimeException('Could not store verified asset');$this->createdPaths[]=$target;
    }
    private function import(string$source,string$name,int$school,int$actor,string$alt):int
    {
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=mime_content_type($source);$allowed=['png'=>'image/png','pdf'=>'application/pdf','mp4'=>'video/mp4'];
        if(($allowed[$extension]??null)!==$mime)throw new \RuntimeException('Unexpected media type');
        $path='cms/'.$school.'/demo-'.hash_file('sha256',$source).'.'.$extension;$row=DB::table('cms_media')->where('school_id',$school)->where('path',$path)->first();$this->copy($source,$path);if($row){$this->refineAlt($row,$alt);return$row->id;}
        $size=$mime==='image/png'?getimagesize($source):[0,0];
        $id=DB::table('cms_media')->insertGetId(['school_id'=>$school,'uploaded_by'=>$actor,'path'=>$path,'mime'=>$mime,'bytes'=>filesize($source),'width'=>$size[0],'height'=>$size[1],'alt'=>mb_substr($alt,0,200),'original_name'=>basename($name),'folder'=>$extension==='pdf'?'เอกสารภาษาไทยสมมติ':($extension==='mp4'?'วีดิทัศน์สมมติ':'ภาพสมมติและปกเอกสาร'),'created_at'=>now(),'updated_at'=>now()]);
        app(SchoolOperations::class)->audit('media.mock_imported','cms_media',$id);return$id;
    }
    private function refineAlt(object$row,string$alt):void
    {
        if((int)$row->version!==1||$row->alt===$alt||(!str_starts_with($row->alt,'ภาพหรือหน้าปกสมมติ ')&&!str_starts_with($row->alt,'เอกสารภาษาไทยสมมติ ')))return;
        DB::table('cms_media')->where('id',$row->id)->update(['alt'=>mb_substr($alt,0,200),'version'=>2,'updated_at'=>now()]);app(SchoolOperations::class)->audit('media.mock_label_refined','cms_media',$row->id);$this->updatedMedia++;
    }
}
