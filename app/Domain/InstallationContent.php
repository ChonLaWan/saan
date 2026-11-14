<?php
namespace App\Domain;

use App\Domain\Cms\{ContentTypes,SiteSettings};
use Illuminate\Support\Facades\{DB,Hash};

/** Fresh-database bootstrap only. Never imports accounts, sessions, private drafts or old audit logs. */
class InstallationContent
{
    private function library(array &$created):bool
    {
        $root=resource_path('install/library');if(!is_file($root.'/manifest.json'))return false;
        $manifest=json_decode(file_get_contents($root.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);if(($manifest['version']??'')!==\App\Domain\Cms\DemoLibrary::VERSION)throw new \RuntimeException('เวอร์ชันคลัง mockup ไม่ถูกต้อง');
        foreach($manifest['files']as$f){if(!preg_match('~^(?:catalog\.json|(?:documents|previews|datasets|concepts|artwork|videos)/[A-Za-z0-9._-]+)$~D',$f['path']))throw new \RuntimeException('เส้นทางคลัง mockup ไม่ถูกต้อง');$source=$root.'/'.$f['path'];if(!is_file($source)||filesize($source)!==$f['bytes']||!hash_equals($f['sha256'],hash_file('sha256',$source)))throw new \RuntimeException('ไฟล์คลัง mockup ไม่ครบ');}
        foreach($manifest['files']as$f){$dest=storage_path('app/private/'.\App\Domain\Cms\DemoLibrary::prefixFor((int)config('school.school_id')).'/'.$f['path']);if(is_file($dest)){if(!hash_equals($f['sha256'],hash_file('sha256',$dest)))throw new \RuntimeException('ไม่เขียนทับคลัง mockup ที่ถูกแก้ไข');continue;}if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);if(!copy($root.'/'.$f['path'],$dest))throw new \RuntimeException('คัดลอกคลัง mockup ไม่สำเร็จ');$created[]=$dest;}
        return true;
    }
    /**
     * Demo/seed rows are written by plain inserts, after the module migrations ran, so the columns those modules added
     * (name_key, room_key, code_key, first/last name, fiscal year, term id) are still empty. Fill them with the same keys the
     * modules compute (academic, registry, timetable, leave-duty, attendance) so the new /work screens see consistent data.
     * Idempotent and column-guarded: only fills NULLs, skips columns that do not exist, never overwrites a staff edit.
     */
    public static function normaliseOperational(int $school=1):void
    {
        $has=fn(string $t,string $c)=>\Illuminate\Support\Facades\Schema::hasColumn($t,$c);
        $key=function(string $v,bool $keepSingleSpace=false):string{
            if(class_exists(\Normalizer::class))$v=\Normalizer::normalize($v,\Normalizer::FORM_C)?:$v;
            return $keepSingleSpace?mb_strtolower(trim((string)preg_replace('/\s+/u',' ',$v))):mb_strtolower((string)preg_replace('/\s+/u','',$v));
        };
        $unique=function(array &$seen,string $k,int $id,int $max):string{if(isset($seen[$k]))$k=mb_substr($k,0,$max-10).'~'.$id;$seen[$k]=true;return $k;};
        if($has('classrooms','name_key')){
            $seen=[];foreach(DB::table('classrooms')->where('school_id',$school)->whereNotNull('name_key')->pluck('name_key')as$k)$seen[$k]=true;
            foreach(DB::table('classrooms')->where('school_id',$school)->whereNull('name_key')->orderBy('id')->get(['id','name','grade_level'])as$r){
                $set=['name_key'=>$unique($seen,mb_substr($key((string)$r->name),0,110),(int)$r->id,120)];
                if($has('classrooms','grade_level')&&$r->grade_level===null&&preg_match('~^\s*(\S+?)\s*/\s*(\d{1,3})\s*$~u',(string)$r->name,$m)){$set['grade_level']=mb_substr($m[1],0,20);$set['section']=(int)$m[2];}
                DB::table('classrooms')->where('id',$r->id)->update($set);
            }
        }
        if($has('teachers','name_key')){
            $seen=[];foreach(DB::table('teachers')->where('school_id',$school)->whereNotNull('name_key')->pluck('name_key')as$k)$seen[$k]=true;
            foreach(DB::table('teachers')->where('school_id',$school)->whereNull('name_key')->orderBy('id')->get(['id','name','first_name','last_name'])as$r){
                $prefix=null;$rest=trim((string)preg_replace('/\s+/u',' ',(string)$r->name));
                // same title list and matching rule as academic (expand_academic_base): longest first, case-insensitive
                $titles=['นางสาว','น.ส.','นาย','นาง','ว่าที่ร.ต.','ว่าที่ ร.ต.','ผศ.ดร.','ผศ.','รศ.','ศ.','ดร.','อาจารย์','ครู','Mr.','Mrs.','Miss','Ms.'];
                usort($titles,fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
                foreach($titles as$pf)if(mb_stripos($rest,$pf)===0){if(trim(mb_substr($rest,mb_strlen($pf)))!==''){$prefix=$pf;$rest=trim(mb_substr($rest,mb_strlen($pf)));}break;}
                $parts=explode(' ',$rest,2);$first=$r->first_name??$parts[0];$last=$r->last_name??($parts[1]??null);
                $base=class_exists(\Saan\Modules\Academic\Academic::class)?\Saan\Modules\Academic\Academic::teacherKey($first,$last):mb_substr($key(trim($first.' '.$last)),0,200);
                $set=['name_key'=>$unique($seen,$base,(int)$r->id,200)];
                if($has('teachers','first_name')){$set['first_name']=$first;$set['last_name']=$last;if($prefix!==null&&$has('teachers','prefix'))$set['prefix']=$prefix;}
                DB::table('teachers')->where('id',$r->id)->update($set);
            }
        }
        if($has('students','code_key')){
            $seen=[];foreach(DB::table('students')->where('school_id',$school)->whereNotNull('code_key')->pluck('code_key')as$k)$seen[$k]=true;
            foreach(DB::table('students')->where('school_id',$school)->where(fn($q)=>$q->whereNull('code_key')->orWhereNull('first_name'))->orderBy('id')->get(['id','code','name','code_key','first_name','last_name'])as$r){
                $set=[];$k=mb_strtolower(trim((string)$r->code));
                if($r->code_key===null&&$k!==''&&!isset($seen[$k])){$seen[$k]=true;$set['code_key']=$k;}
                if($r->first_name===null&&$has('students','first_name')){$parts=explode(' ',trim((string)preg_replace('/\s+/u',' ',(string)$r->name)),2);$set['first_name']=$parts[0];if(isset($parts[1]))$set['last_name']=$parts[1];}
                if($set)DB::table('students')->where('id',$r->id)->update($set);
            }
        }
        if($has('lessons','room_key')){
            $seen=[];foreach(DB::table('lessons')->where('school_id',$school)->whereNotNull('room_key')->get(['term_id','weekday','period_id','room_key'])as$r)$seen[$r->term_id.'|'.$r->weekday.'|'.$r->period_id.'|'.$r->room_key]=true;
            foreach(DB::table('lessons')->where('school_id',$school)->whereNull('room_key')->orderBy('id')->get(['id','term_id','weekday','period_id','room'])as$r){
                if(trim((string)$r->room)===''||str_starts_with((string)$r->room,'~none~'))continue;
                // same rule as Timetable::key: ALL whitespace removed ('ห้อง 1' and 'ห้อง1' are one room), or the room view and the clash check would not find these lessons
                $k=mb_substr($key((string)$r->room),0,120);$slot=$r->term_id.'|'.$r->weekday.'|'.$r->period_id.'|';
                if(isset($seen[$slot.$k]))$k=mb_substr($k,0,100).'~'.$r->id;$seen[$slot.$k]=true;
                DB::table('lessons')->where('id',$r->id)->update(['room_key'=>$k]);
            }
        }
        // days/fiscal_year are leave-duty's derived columns (calendar mode, half day, holidays): let that module fill them.
        if($has('leave_requests','fiscal_year')&&class_exists(\Saan\Modules\LeaveDuty\Leave::class))app(\Saan\Modules\LeaveDuty\Leave::class)->normalizeDerived();
        if($has('attendances','term_id')){
            foreach(DB::table('academic_terms')->where('school_id',$school)->get(['id','starts_on','ends_on'])as$t)
                DB::table('attendances')->where('school_id',$school)->whereNull('term_id')->whereBetween('date',[$t->starts_on,$t->ends_on])->update(['term_id'=>$t->id]);
        }
    }    public function bundle(): array
    {
        $root=resource_path('install/demo');
        $manifest=json_decode(file_get_contents($root.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if(($manifest['version']??'')!=='2026.11.05.1'||!hash_equals($manifest['content_sha256'],hash_file('sha256',$root.'/content.json')))throw new \RuntimeException('ชุดข้อมูลตัวอย่างไม่ตรงกับ manifest');
        $bundle=json_decode(file_get_contents($root.'/content.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($bundle['media'] as$m){
            if(!preg_match('~^media/[0-9]+\.(webp|png|jpe?g|pdf|mp4)$~D',$m['file']))throw new \RuntimeException('เส้นทางไฟล์ตัวอย่างไม่ถูกต้อง');
            $file=$root.'/'.$m['file'];if(!is_file($file)||filesize($file)!==$m['bytes']||!hash_equals($m['sha256'],hash_file('sha256',$file)))throw new \RuntimeException('ไฟล์ตัวอย่างไม่ครบหรือถูกแก้ไข');
        }
        return $bundle;
    }
    public function install(array $input,string $receipt): array
    {
        if(DB::table('users')->exists()||DB::table('posts')->exists()||DB::table('cms_media')->exists())throw new \RuntimeException('ฐานข้อมูลมีผู้ใช้หรือเนื้อหาแล้ว ไม่ติดตั้งทับ');
        // mode: blank = structure only; demo = website sample plus a tiny operational sample; full = website sample plus the whole trial school
        // (App\Domain\Demo\DemoSchool: fictional people in every module and trial accounts with random passwords). Only "full" creates accounts.
        if(!in_array($input['mode'],['blank','demo','full'],true))throw new \RuntimeException('รูปแบบข้อมูลเริ่มต้นไม่ถูกต้อง');
        $full=$input['mode']==='full';$bundle=$input['mode']==='blank'?null:$this->bundle();$created=[];
        try{return DB::transaction(function()use($input,$bundle,$receipt,$full,&$created){
            $admin=DB::table('users')->insertGetId(['school_id'=>1,'name'=>$input['admin_name'],'email'=>mb_strtolower($input['admin_email']),'password'=>Hash::make($input['password']),'role'=>'admin','is_demo'=>false,'is_active'=>true,'auth_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
            ContentTypes::ensureCategories(1);
            $site=SiteSettings::defaults();$theme=app(CmsPublishing::class)->defaults();
            if($bundle){
                // the full trial builds its own coherent school below, so the small operational sample is left out there
                if(!$full){
                foreach($bundle['operational']as$table=>$rows){
                    if(!in_array($table,['academic_terms','teachers','classrooms','students','bell_periods','lessons','duties','leave_requests','attendances'],true))throw new \RuntimeException('ตารางตัวอย่างไม่อยู่ใน allowlist');
                    foreach($rows as$row){$row['school_id']=1;foreach(['recorded_by','decided_by']as$k)if(!empty($row[$k]))$row[$k]=$admin;DB::table($table)->insert($row);}
                }
                self::normaliseOperational(1);
                }
                foreach($bundle['media']as$m){
                    $source=resource_path('install/demo/'.$m['file']);$relative='cms/1/starter-'.$m['id'].'.'.pathinfo($m['file'],PATHINFO_EXTENSION);$dest=storage_path('app/private/'.$relative);
                    if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0700,true))throw new \RuntimeException('สร้างคลังสื่อไม่ได้');
                    if(is_file($dest)){if(!hash_equals($m['sha256'],hash_file('sha256',$dest)))throw new \RuntimeException('พบไฟล์เดิมที่ checksum ต่างกัน');}
                    else{if(!copy($source,$dest))throw new \RuntimeException('คัดลอกสื่อไม่สำเร็จ');$created[]=$dest;}
                    $row=$m;unset($row['file'],$row['sha256']);$row['school_id']=1;$row['uploaded_by']=$admin;$row['path']=$relative;
                    // Only published assets ship in the starter. Treat optimized derivatives as standalone files.
                    $row['source_media_id']=null;DB::table('cms_media')->insert($row);
                }
                foreach($bundle['posts']as$p){$p['school_id']=1;$p['author_id']=$admin;$p['metadata']=json_encode($p['metadata'],JSON_UNESCAPED_UNICODE);DB::table('posts')->insert($p);}
                $this->library($created);$site=array_replace($site,$bundle['site']);$theme=array_replace($theme,$bundle['theme']);
                $site['badge']='ข้อมูลตัวอย่าง · กรุณาปรับก่อนใช้งานจริง';
            }else{
                // Structural pages and slides remain private drafts; no invented personnel or news is published.
                foreach([['about','รู้จักโรงเรียน','page',[]],['history','ประวัติโรงเรียน','page',[]],['admission','รับสมัครนักเรียน','page',[]],['curriculum','หลักสูตร','page',[]],['hero-1','หัวข้อสไลด์หน้าแรก','banner',['placement'=>'hero','sort_order'=>1,'button_label'=>'ข่าวโรงเรียน','url'=>'/portal/news']],['hero-2','สไลด์กิจกรรมการเรียนรู้','banner',['placement'=>'hero','sort_order'=>2]],['hero-3','สไลด์ข้อมูลสำหรับครอบครัว','banner',['placement'=>'hero','sort_order'=>3]],['admission-link','รับสมัครนักเรียน','service',['url'=>'/portal/pages','icon_key'=>'admission']],['download-link','เอกสารดาวน์โหลด','service',['url'=>'/portal/downloads','icon_key'=>'downloads']],['calendar-link','ปฏิทินกิจกรรม','service',['url'=>'/portal/events','icon_key'=>'calendar']],['contact-link','ติดต่อโรงเรียน','service',['url'=>'/portal/contact','icon_key'=>'contact']]]as[$slug,$title,$type,$metadata]){
                    DB::table('posts')->insert(['school_id'=>1,'author_id'=>$admin,'slug'=>$slug,'title'=>$title,'body'=>'','excerpt'=>'','category'=>$type==='page'?'general':'news','status'=>'draft','content_type'=>$type,'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);
                }
                $site['hero_autoplay']=false;$site['badge']='เว็บไซต์โรงเรียน';$site['statistics']=[];
                $year=(int)date('Y');DB::table('academic_terms')->insert(['school_id'=>1,'year'=>$year+543,'term'=>1,'starts_on'=>$year.'-05-01','ends_on'=>($year+1).'-03-31']);
            }
            $theme['school_name']=$input['school_name'];$theme['phone']=$input['phone']??'';$theme['address']=$input['address']??'';
            $site['email']=$input['public_email']??'';$site['observance']['mode']='off';
            $site['navigation']=[['label'=>'หน้าหลัก','url'=>'/portal'],['label'=>'รู้จักโรงเรียน','url'=>'/portal/pages'],['label'=>'ข่าวและกิจกรรม','url'=>'/portal/news'],['label'=>'ประกาศ','url'=>'/portal/procurement','children'=>[['label'=>'จัดซื้อจัดจ้าง','url'=>'/portal/procurement'],['label'=>'รับสมัครงาน','url'=>'/portal/jobs']]],['label'=>'เอกสาร','url'=>'/portal/downloads'],['label'=>'ติดต่อ','url'=>'/portal/contact']];
            $site['footer_navigation']=[['label'=>'ข้อมูลและบริการทั้งหมด','url'=>'/portal/directory'],['label'=>'บุคลากร','url'=>'/portal/people'],['label'=>'ติดต่อโรงเรียน','url'=>'/portal/contact']];
            foreach(['cms_themes'=>$theme,'cms_site_settings'=>$site]as$table=>$values)DB::table($table)->insert(['school_id'=>1,'draft'=>json_encode($values,JSON_UNESCAPED_UNICODE),'published'=>json_encode($values,JSON_UNESCAPED_UNICODE),'version'=>1,'published_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('audit_events')->insert(['school_id'=>1,'actor_id'=>$admin,'action'=>'installation.completed','entity'=>'users','entity_id'=>$admin,'detail'=>json_encode(['receipt'=>$receipt,'mode'=>$input['mode'],'release'=>(string)config('saan.version')]),'created_at'=>now()]);
            // Same transaction as the rest of the install: a trial school that fails half-way leaves nothing behind (its files are in $created) and the installer can simply be run again.
            $demo=$full?app(\App\Domain\Demo\DemoSchool::class)->build($admin,(string)($input['app_url']??config('app.url')),'installer',$created,true):null;
            return ['admin_id'=>$admin,'mode'=>$input['mode'],'published'=>DB::table('posts')->where('status','published')->count(),'drafts'=>DB::table('posts')->where('status','draft')->count(),'media'=>DB::table('cms_media')->count()]+($demo?['demo'=>$demo]:[]);
        });}catch(\Throwable $e){foreach($created as$file)if(is_file($file))unlink($file);throw$e;}
    }
}
