<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Offline-only archive operations. Restore can only populate an empty, preconfigured database. */
class SchoolArchive extends Command
{
    protected $signature='school:archive {action : backup or restore} {path : Absolute private ZIP path} {--yes-empty-database : Explicitly allow restore into an empty database} {--use-source-school : Restore SCHOOL_ID/SCHOOL_TERM_ID from the archive when they differ from this install} {--use-source-install : Accept an archive made by a different install id (the id, cookie and cache prefixes of this install are kept)} {--resume : Finish an interrupted restore of the same archive (uses the restore receipt)} {--replace-hash-key : Overwrite a different SAAN_HASH_KEY already set on this install}';
    protected $description='Back up database, private media and config, or restore into a verified empty MySQL/MariaDB database';
    /**
     * SAAN_HASH_KEY never rotates and must travel with the backup like APP_KEY: PIN hashes, national-id hashes, certificate links and survey respondent keys are keyed on it.
     * Copies it from the archive's .env into $next. Refuses when the target already has a different non-empty value unless $replace is set.
     */
    public static function carryHashKey(string $current, string $next, array $sourceEnv, bool $replace = false): string
    {
        $src = (string) ($sourceEnv['SAAN_HASH_KEY'] ?? '');
        if ($src === '') return $next;
        $cur = (string) (\Dotenv\Dotenv::parse($current)['SAAN_HASH_KEY'] ?? '');
        if ($cur !== '' && !hash_equals($cur, $src) && !$replace) throw new \RuntimeException('เครื่องนี้ตั้ง SAAN_HASH_KEY ไว้แล้วและต่างจาก archive การเขียนทับทำให้ PIN/เลขประจำตัวที่ hash ไว้ใช้ไม่ได้ ใช้ --replace-hash-key หากต้องการใช้ค่าจาก archive');
        $line = 'SAAN_HASH_KEY="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $src).'"';
        $out = preg_replace_callback('/^SAAN_HASH_KEY=.*$/m', fn () => $line, $next, 1, $found);
        return $found ? $out : rtrim($next)."\n".$line."\n";
    }
    /**
     * Restore guard: an archive goes back only to the same edition (combined -> internal is the one allowed migration of an existing system), never
     * internal/combined data onto a public site (identity data and saan-keys secrets would be served from the public machine), and a different
     * install id needs --use-source-install. The target's own id, cookie and cache prefixes are kept either way.
     */
    public static function assertSameInstall(array $manifest, string $edition, string $installId, bool $acceptOtherInstall = false): void
    {
        $source = (string) ($manifest['edition'] ?? 'combined');
        if ($edition === 'public' && $source !== 'public') throw new \RuntimeException('archive นี้เป็นของ edition '.$source.' (มีข้อมูลภายในและกุญแจลับ) ห้ามกู้คืนลงหน้าบ้าน ตรวจ path ของโฟลเดอร์แอปที่รันคำสั่ง');
        if ($source !== $edition && !($source === 'combined' && $edition === 'internal')) throw new \RuntimeException('archive เป็นของ edition '.$source.' แต่เครื่องนี้เป็น '.$edition.' กู้คืนข้าม edition ไม่ได้ (ยกเว้นย้ายระบบรวมเดิมไปเป็นระบบภายใน)');
        $sourceId = (string) ($manifest['install_id'] ?? '');
        if ($sourceId !== '' && $installId !== '' && $sourceId !== $installId && !$acceptOtherInstall) throw new \RuntimeException('archive เป็นของการติดตั้งรหัส '.$sourceId.' แต่เครื่องนี้รหัส '.$installId.' ใช้ --use-source-install เมื่อตั้งใจกู้ข้ามการติดตั้ง (รหัส คุกกี้ และ prefix ของเครื่องนี้คงเดิม)');
    }
    /** Fields of storage/installed.json that say WHICH install this is and where its other half lives: they always belong to the target, never to an archive. */
    public const IDENTITY_FIELDS = ['install_id', 'edition', 'layout', 'public_root', 'public_url', 'internal_root', 'db_isolation_verified_at'];
    /**
     * The marker written after a restore: the archive's non-identity fields (version, install date, mode ...) merged under the target's own identity.
     * Without a target marker one is derived from the target's .env (edition and install id; layout and the other root are unknown and stay out, so nothing points to a
     * folder of the source install).
     */
    public static function mergeMarker(string $archiveMarker, ?string $targetMarker, array $targetEnv): string
    {
        $src = json_decode($archiveMarker, true);
        $src = is_array($src) ? $src : [];
        $own = $targetMarker !== null ? json_decode($targetMarker, true) : null;
        if (!is_array($own)) {
            $own = [];
            $ed = strtolower(trim((string) ($targetEnv['SAAN_EDITION'] ?? '')));
            $own['edition'] = in_array($ed, \App\Support\Saan\Edition::ALL, true) ? $ed : 'combined';
            if ((string) ($targetEnv['SAAN_INSTALL_ID'] ?? '') !== '') $own['install_id'] = (string) $targetEnv['SAAN_INSTALL_ID'];
        }
        $out = array_diff_key($src, array_flip(self::IDENTITY_FIELDS));
        foreach (self::IDENTITY_FIELDS as $k) if (array_key_exists($k, $own)) $out[$k] = $own[$k];
        return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    /**
     * contains_secret_keys and the warning for the manifest. The .env that travels in every archive holds APP_KEY and the database password, and the public key
     * folder holds peer-unlock.key (the HMAC key of the unlock-code hashes that are in the same archive's database dump), so even a public archive is not harmless.
     * @return array{0:bool,1:string}
     */
    public static function keyWarning(bool $secretSide, bool $hasUnlockKey): array
    {
        if ($secretSide) return [true, 'archive นี้มีกุญแจลับของระบบ (saan-keys: push.secret, pull.secret, inbox.secret, unlock.key) รวมทั้ง APP_KEY และรหัสผ่านฐานข้อมูลใน .env ใครได้ไฟล์นี้ปลอมการส่งข้อมูลและอ่านกล่องรับเรื่องได้ เก็บนอกเครื่องแบบเข้ารหัสและห้ามส่งให้ฝั่งหน้าบ้าน'];
        if ($hasUnlockKey) return [true, 'archive นี้ของหน้าบ้านมีกุญแจสาธารณะของคู่ (peer-*) แต่มี peer-unlock.key (กุญแจ HMAC ของรหัสปลดล็อกที่อยู่ในฐานข้อมูลใน archive เดียวกัน ใครได้ไฟล์นี้เดารหัสสั้นของห้องเรียนและประกาศแบบออฟไลน์ได้) รวมทั้ง APP_KEY และรหัสผ่านฐานข้อมูลใน .env เก็บแบบเข้ารหัสและไม่ส่งต่อ'];
        return [false, 'archive นี้ไม่มีกุญแจลับของคู่ แต่มี .env ที่มี APP_KEY และรหัสผ่านฐานข้อมูล เก็บแบบเข้ารหัสและไม่ส่งต่อ'];
    }
    /** saan-<install id>-<edition>-<UTC stamp>.zip (saan-noid-... on installs made before S7). */
    public static function fileName(?string $stamp=null):string
    {
        return 'saan-'.(preg_match('/^[a-z2-7]{6}$/D',(string)config('saan.install_id'))?config('saan.install_id'):'noid').'-'.\App\Support\Saan\Edition::current().'-'.($stamp??gmdate('Ymd\THis\Z')).'.zip';
    }
    // ------------------------------------------------------------------ scheduled backup (routes/console.php, config saan.backup.*)

    /** Folder shared by the scheduled archives (auto-*.zip) and the updater's own pre-update-*.zip files. */
    public static function backupDir():string{return storage_path('app/private/backups');}

    /** The scheduled archives only (auto-YYYYMMDD.zip), newest first. pre-update-*.zip and manual archives are never listed here. */
    public static function autoBackups():array
    {
        $files=[];foreach(glob(self::backupDir().'/auto-*.zip')?:[]as$f)if(is_file($f)&&preg_match('/^auto-\d{8}\.zip$/D',basename($f)))$files[]=$f;
        rsort($files,SORT_STRING); // the date is in the name, so a name sort is a date sort
        return$files;
    }

    /** Deletes all but the newest $keep auto-*.zip; returns how many were removed. */
    public static function pruneAuto(int $keep):int
    {
        $n=0;foreach(array_slice(self::autoBackups(),max(1,$keep))as$f)if(@unlink($f))$n++;
        return$n;
    }

    /**
     * One scheduled backup: auto-YYYYMMDD.zip via `school:archive backup`, then prune. Skips (ok=false, never throws) when today's file exists, when the
     * folder cannot be made, or when the free disk space is below 1.5 x the newest earlier archive (first run, nothing to compare with: 1.5 x the files
     * the school has stored). $backup(string $file):int and $free (bytes, default disk_free_space) are replaceable in tests.
     * @return array{ok:bool,file:?string,message:string,pruned:int}
     */
    public static function runScheduled(?callable $backup=null,?string $stamp=null,int|float|false|null $free=null):array
    {
        $dir=self::backupDir();
        try{
            if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return['ok'=>false,'file'=>null,'message'=>'สร้างโฟลเดอร์ backups ไม่ได้','pruned'=>0];
            $file=$dir.'/auto-'.($stamp??date('Ymd')).'.zip';
            if(is_file($file))return['ok'=>true,'file'=>$file,'message'=>'มี archive ของวันนี้แล้ว','pruned'=>0];
            $previous=self::autoBackups()[0]??null;$free??=@disk_free_space($dir);
            $need=1.5*($previous!==null?(int)@filesize($previous):\App\Support\StorageQuota::usedBytes());
            if($free!==false&&$free<$need)return['ok'=>false,'file'=>null,'message'=>'พื้นที่ดิสก์ไม่พอ ข้ามการสำรองอัตโนมัติรอบนี้','pruned'=>0];
            $code=$backup?$backup($file):\Illuminate\Support\Facades\Artisan::call('school:archive',['action'=>'backup','path'=>$file]);
            if($code!==0||!is_file($file)){@unlink($file);return['ok'=>false,'file'=>null,'message'=>'school:archive backup ไม่สำเร็จ','pruned'=>0];}
            return['ok'=>true,'file'=>$file,'message'=>'สำรองแล้ว','pruned'=>self::pruneAuto((int)config('saan.backup.keep',7))];
        }catch(\Throwable $e){return['ok'=>false,'file'=>null,'message'=>$e->getMessage(),'pruned'=>0];}
    }

    private function safePath(string $path):string
    {
        if(!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$path)||strpbrk($path,"\0\r\n")!==false)throw new \RuntimeException('ระบุ absolute path ของ ZIP นอก public');
        $parent=realpath(dirname($path));if(!$parent||!is_writable($parent))throw new \RuntimeException('โฟลเดอร์ backup ต้องมีอยู่และเขียนได้');$target=$parent.DIRECTORY_SEPARATOR.basename($path);
        foreach([public_path(),storage_path('app/public')]as$public){$base=strtolower(str_replace('\\','/',realpath($public)?:$public));$check=strtolower(str_replace('\\','/',$target));if($check===$base||str_starts_with($check,$base.'/'))throw new \RuntimeException('ไม่เก็บ archive ในพื้นที่สาธารณะ');}
        return$target;
    }
    public function handle():int
    {
        $zip=new \ZipArchive;$temp=null;$partial=null;
        try{
            if(!in_array(DB::connection()->getDriverName(),['mysql','mariadb'],true))throw new \RuntimeException('คำสั่ง archive รุ่นนี้รองรับ MySQL/MariaDB');
            $arg=(string)$this->argument('path');$action=$this->argument('action');
            // S7: a directory instead of a file name gets a generated name that carries the install id and the edition
            if($action==='backup'&&is_dir($arg))$arg=rtrim($arg,'/\\').DIRECTORY_SEPARATOR.self::fileName();
            $path=$this->safePath($arg);
            if($action==='backup'){
                if(is_file($path))throw new \RuntimeException('ไม่เขียนทับ archive เดิม');
                if(!is_file(base_path('.env'))||empty(\Dotenv\Dotenv::parse(file_get_contents(base_path('.env')))['APP_KEY']))throw new \RuntimeException('ต้องมี config .env และ APP_KEY ก่อนสำรองทั้งระบบ');
                $temp=storage_path('app/private/archive-'.bin2hex(random_bytes(8)));mkdir($temp,0700,true);$manifest=['version'=>1,'release'=>config('saan.version'),'created_at'=>gmdate('c'),'install_id'=>(string)config('saan.install_id'),'edition'=>\App\Support\Saan\Edition::current(),'school_id'=>(int)config('school.school_id'),'term_id'=>(int)config('school.term_id'),'tables'=>[],'files'=>[]];
                $partial=$path.'.incomplete-'.bin2hex(random_bytes(8));if($zip->open($partial,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true)throw new \RuntimeException('สร้าง ZIP ไม่ได้');
                $pdo=DB::connection()->getPdo();$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
                try{
                    $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN);
                    foreach($tables as$table){if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new \RuntimeException('ชื่อตารางไม่รองรับ');$schema=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(\PDO::FETCH_NUM)[1];$sql='database/'.$table.'.schema.json';$zip->addFromString($sql,json_encode(['sql'=>$schema],JSON_THROW_ON_ERROR));$file=$temp.'/'.$table.'.jsonl';$h=fopen($file,'wb');$count=0;$pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);/* stream rows: a buffered result would copy the whole table into PHP memory */try{$statement=$pdo->query('SELECT * FROM `'.$table.'`');while($row=$statement->fetch(\PDO::FETCH_ASSOC)){fwrite($h,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");$count++;}$statement->closeCursor();}finally{$pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);}fclose($h);$name='database/'.$table.'.jsonl';$zip->addFile($file,$name);$manifest['tables'][]=['name'=>$table,'rows'=>$count,'schema_file'=>$sql,'schema_sha256'=>hash('sha256',json_encode(['sql'=>$schema],JSON_THROW_ON_ERROR)),'data_file'=>$name,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)];}
                    foreach(DB::table('cms_media')->get(['path','bytes'])as$media){$relative='private/'.$media->path;if(!preg_match('~^private/[A-Za-z0-9_./-]+$~D',$relative)||str_contains($relative,'..'))throw new \RuntimeException('เส้นทางสื่อไม่ถูกต้อง');$file=storage_path('app/'.$relative);if(!is_file($file)||filesize($file)!==$media->bytes)throw new \RuntimeException('สื่อไม่ครบ จึงไม่สร้าง backup ที่ขาดไฟล์');$zip->addFile($file,'storage/'.$relative);$manifest['files'][]=['path'=>'storage/'.$relative,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)];}
                    // Private module folders on the local disk (registry identity documents, leave attachments) travel with the database rows that point at them.
                    // Each module declares its folders in module.json "private_storage" (relative to storage/app/private); modules that are switched off still keep their files.
$folders=['demo-library','registry','leave-duty'];
// S7: the key directory travels with the archive (internal: the secret signing/inbox keys; public: only the public halves of the pair). A custom SAAN_KEYS_DIR lies outside storage and must be saved by the operator.
if(config('saan.keys_dir')){$manifest['contains_secret_keys']=false;$manifest['warning']='SAAN_KEYS_DIR ตั้งนอก storage จึงไม่อยู่ใน archive นี้ ต้องสำรองโฟลเดอร์กุญแจเอง';}
elseif(is_dir(storage_path('app/private/saan-keys'))){$folders[]='saan-keys';$secret=\App\Support\Saan\Edition::isInternal()||\App\Support\Saan\Edition::isCombined();[$manifest['contains_secret_keys'],$manifest['warning']]=self::keyWarning($secret,is_file(storage_path('app/private/saan-keys/peer-unlock.key')));}
foreach(app(\App\Support\Modules\ModuleRegistry::class)->all() as$module)foreach((array)($module['private_storage']??[])as$dir)if(is_string($dir)&&preg_match('~^[a-z0-9][a-z0-9-]{0,39}$~D',$dir))$folders[]=$dir;
foreach(array_values(array_unique($folders))as$folder){$library=storage_path('app/private/'.$folder);if(is_dir($library)){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($library,\FilesystemIterator::SKIP_DOTS))as$file){if(!$file->isFile()||$file->isLink())continue;$relative='private/'.$folder.'/'.str_replace('\\','/',substr($file->getPathname(),strlen($library)+1));if(!preg_match('~^private/[A-Za-z0-9_./-]+$~D',$relative)||str_contains($relative,'..'))throw new \RuntimeException('เส้นทางคลังตัวอย่างไม่ถูกต้อง');$zip->addFile($file->getPathname(),'storage/'.$relative);$manifest['files'][]=['path'=>'storage/'.$relative,'bytes'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getPathname())];}}}
                    foreach(['.env'=>base_path('.env'),'installed.json'=>storage_path('installed.json')]as$name=>$file)if(is_file($file)){$zip->addFile($file,'config/'.$name);$manifest['files'][]=['path'=>'config/'.$name,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)];}
                    $pdo->commit();
                }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
                $zip->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));$zip->close();
                // Verify all bytes after ZipArchive has actually read the source files.
                chmod($partial,0600);$zip->open($partial);foreach($manifest['files']as$f)if(!hash_equals($f['sha256'],hash('sha256',$zip->getFromName($f['path']))))throw new \RuntimeException('ไฟล์เปลี่ยนระหว่าง backup กรุณาลองใน maintenance mode');$zip->close();if(!rename($partial,$path))throw new \RuntimeException('บันทึก archive ไม่สำเร็จ');$partial=null;
                $this->line('[OK] Archive created; '.count($manifest['tables']).' tables; SHA-256 '.hash_file('sha256',$path));
            }elseif($action==='restore'){
                if(!$this->option('yes-empty-database'))throw new \RuntimeException('ใช้ --yes-empty-database เฉพาะฐานใหม่ที่เตรียมไว้');
                if(!is_file($path))throw new \RuntimeException('ไม่พบไฟล์ archive');$archiveHash=hash_file('sha256',$path);
                $receiptFile=storage_path('app/private/system/restore-receipt.json');$receipt=is_file($receiptFile)?json_decode((string)file_get_contents($receiptFile),true):null;
                $resume=$this->option('resume');
                if($resume&&(!$receipt||($receipt['archive_sha256']??'')!==$archiveHash||empty($receipt['db_committed'])))throw new \RuntimeException('ไม่มีใบรับการกู้คืนที่ตรงกับ archive นี้ จึงทำต่อไม่ได้');
                if(!$resume&&DB::select('SHOW FULL TABLES'))throw new \RuntimeException('ฐานข้อมูลไม่ว่าง ไม่ลบหรือ restore ทับ (ถ้าครั้งก่อนข้อมูลถูก commit แล้วแต่ค้างตอนย้ายไฟล์ ใช้ --resume กับ archive เดิม; ถ้าล้มก่อน commit ระบบลบตารางที่สร้างเองให้แล้ว หากยังเหลือตารางจากการค้างกลางคัน ให้ลบตารางในฐานข้อมูลใหม่ให้ว่างแล้วรันซ้ำโดยไม่ใช้ --resume)');
                if($zip->open($path)!==true)throw new \RuntimeException('เปิด ZIP ไม่ได้');$manifest=json_decode($zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
                if(($manifest['version']??0)!==1)throw new \RuntimeException('เวอร์ชัน archive ไม่รองรับ');
                self::assertSameInstall($manifest,\App\Support\Saan\Edition::current(),(string)config('saan.install_id'),(bool)$this->option('use-source-install'));
                foreach($manifest['tables']as$t){if(!preg_match('/^[A-Za-z0-9_]+$/D',$t['name'])||$t['schema_file']!=='database/'.$t['name'].'.schema.json'||$t['data_file']!=='database/'.$t['name'].'.jsonl')throw new \RuntimeException('เส้นทางใน archive ไม่ถูกต้อง');foreach([$t['schema_file']=>$t['schema_sha256'],$t['data_file']=>$t['sha256']]as$name=>$hash)if(!hash_equals($hash,hash('sha256',$zip->getFromName($name))))throw new \RuntimeException('checksum ข้อมูลไม่ตรง');}
                $saved=$zip->getFromName('config/.env');if($saved===false)throw new \RuntimeException('archive ไม่มี config');$sourceEnv=\Dotenv\Dotenv::parse($saved);if(empty($sourceEnv['APP_KEY']))throw new \RuntimeException('archive ไม่มี application key');
                // RT-04: school/term identity must match this install, or the operator must explicitly take the archive's values.
                $sourceSchool=(int)($manifest['school_id']??$sourceEnv['SCHOOL_ID']??1);$sourceTerm=(int)($manifest['term_id']??$sourceEnv['SCHOOL_TERM_ID']??1);
                $identityDiffers=$sourceSchool!==(int)config('school.school_id')||$sourceTerm!==(int)config('school.term_id');
                if($identityDiffers&&!$this->option('use-source-school'))throw new \RuntimeException('archive เป็นของ SCHOOL_ID='.$sourceSchool.' / SCHOOL_TERM_ID='.$sourceTerm.' แต่เครื่องนี้ตั้งเป็น '.(int)config('school.school_id').' / '.(int)config('school.term_id').' ใช้ --use-source-school เพื่อใช้ค่าจาก archive');
                $current=file_get_contents(base_path('.env'));$next=preg_replace('/^APP_KEY=.*$/m','APP_KEY="'.str_replace(['\\','"'],['\\\\','\\"'],$sourceEnv['APP_KEY']).'"',$current,1,$replacements);if($replacements!==1)throw new \RuntimeException('APP_KEY ใน config เป้าหมายไม่พร้อม');
                // Rotated keys: rows encrypted under a previous key stay readable only when APP_PREVIOUS_KEYS travels with APP_KEY.
                $next=self::carryHashKey($current,$next,$sourceEnv,(bool)$this->option('replace-hash-key'));
                if(!empty($sourceEnv['APP_PREVIOUS_KEYS'])){$prev='APP_PREVIOUS_KEYS="'.str_replace(['\\','"'],['\\\\','\\"'],$sourceEnv['APP_PREVIOUS_KEYS']).'"';$next=preg_replace_callback('/^APP_PREVIOUS_KEYS=.*$/m',fn()=>$prev,$next,1,$foundPrev);if(!$foundPrev)$next=rtrim($next)."\n".$prev."\n";}
                foreach(['SCHOOL_ID'=>$sourceSchool,'SCHOOL_TERM_ID'=>$sourceTerm]as$key=>$value){$next=preg_replace('/^'.$key.'=.*$/m',$key.'='.$value,$next,1,$found);if(!$found)$next=rtrim($next)."\n".$key.'='.$value."\n";}
                $files=array_values(array_filter($manifest['files'],fn($f)=>str_starts_with($f['path'],'storage/')));
                foreach($manifest['files']as$f){if(!preg_match('~^(?:storage/private/[A-Za-z0-9_./-]+|config/\.env|config/installed\.json)$~D',$f['path'])||str_contains($f['path'],'..')||!hash_equals($f['sha256'],hash('sha256',$zip->getFromName($f['path']))))throw new \RuntimeException('checksum หรือเส้นทางไฟล์ไม่ถูกต้อง');}
                // RT-08: check space and permissions BEFORE any DDL, and stage every file before the database commit.
                $needed=array_sum(array_column($files,'bytes'))+array_sum(array_column($manifest['tables'],'bytes'));$free=@disk_free_space(storage_path('app/private'));
                if($free!==false&&$free<$needed*1.2+10485760)throw new \RuntimeException('พื้นที่ว่างไม่พอสำหรับกู้คืน ต้องการประมาณ '.round(($needed*1.2)/1048576).' MB');
                if(!is_dir(storage_path('app/private')))mkdir(storage_path('app/private'),0700,true);
                foreach([storage_path('app/private'),base_path('.env'),storage_path()]as$w)if(!is_writable($w))throw new \RuntimeException('เขียนไม่ได้: '.$w);
                $staging=storage_path('app/private/.restore-staging-'.substr($archiveHash,0,16));
                $writeReceipt=function(array $extra)use($receiptFile,$archiveHash,&$receipt){$receipt=array_replace($receipt??[],['archive_sha256'=>$archiveHash,'updated_at'=>gmdate('c')],$extra);if(!is_dir(dirname($receiptFile)))mkdir(dirname($receiptFile),0700,true);if(file_put_contents($receiptFile,json_encode($receipt,JSON_PRETTY_PRINT),LOCK_EX)===false)throw new \RuntimeException('เขียนใบรับการกู้คืนไม่ได้');};
                foreach($files as$f){$rel=substr($f['path'],8);$dest=storage_path('app/'.$rel);if(is_file($dest)){if($resume&&hash_equals($f['sha256'],hash_file('sha256',$dest)))continue;throw new \RuntimeException('พื้นที่สื่อเป้าหมายมีไฟล์แล้ว ไม่เขียนทับ: '.$rel);}$stage=$staging.'/'.$rel;if(is_file($stage)&&hash_equals($f['sha256'],hash_file('sha256',$stage)))continue;if(!is_dir(dirname($stage)))mkdir(dirname($stage),0700,true);if(file_put_contents($stage,$zip->getFromName($f['path']),LOCK_EX)!==$f['bytes']||!hash_equals($f['sha256'],hash_file('sha256',$stage)))throw new \RuntimeException('เตรียมไฟล์กู้คืนไม่สำเร็จ: '.$rel);}
                $writeReceipt(['staged'=>true,'staging'=>$staging,'files'=>count($files)]);
                if(!$resume){
                    $pdo=DB::connection()->getPdo();$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                    $created=[];$writeReceipt(['ddl_started'=>true,'db_committed'=>false]);
                    try{foreach($manifest['tables']as$t){$schema=json_decode($zip->getFromName($t['schema_file']),true,512,JSON_THROW_ON_ERROR);$pdo->exec($schema['sql']);$created[]=$t['name'];}$pdo->beginTransaction();foreach($manifest['tables']as$t){$h=$zip->getStream($t['data_file']);$count=0;while(($line=fgets($h))!==false){$row=json_decode($line,true,512,JSON_THROW_ON_ERROR);DB::table($t['name'])->insert($row);$count++;}fclose($h);if($count!==$t['rows'])throw new \RuntimeException('จำนวนแถวไม่ตรง');}$pdo->commit();}
                    catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();
                        // DDL auto-commits, so drop the tables this run created (the database was verified empty) to allow a clean rerun without --resume
                        foreach(array_reverse($created)as$name){try{$pdo->exec('DROP TABLE IF EXISTS `'.$name.'`');}catch(\Throwable){}}
                        try{$writeReceipt(['ddl_started'=>false,'ddl_rolled_back'=>true,'error'=>$e->getMessage()]);}catch(\Throwable){}
                        throw$e;}finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
                    $writeReceipt(['db_committed'=>true,'tables'=>count($manifest['tables'])]);
                }
                foreach($files as$f){$rel=substr($f['path'],8);$dest=storage_path('app/'.$rel);if(is_file($dest)&&hash_equals($f['sha256'],hash_file('sha256',$dest)))continue;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);if(!@rename($staging.'/'.$rel,$dest)||!hash_equals($f['sha256'],hash_file('sha256',$dest)))throw new \RuntimeException('ย้ายไฟล์กู้คืนไม่สำเร็จ: '.$rel.' (แก้สาเหตุแล้วรันซ้ำด้วย --resume)');@chmod($dest,0600);}
                $writeReceipt(['files_placed'=>true]);
                // Recover APP_KEY (and school/term identity). Keep target DB credentials, URL and cookie namespace intact.
                if(file_put_contents(base_path('.env'),$next,LOCK_EX)!==strlen($next))throw new \RuntimeException('เขียน .env ไม่สำเร็จ ข้อมูลกู้แล้ว รันซ้ำด้วย --resume');@chmod(base_path('.env'),0600);
                $marker=$zip->getFromName('config/installed.json');if($marker!==false){$ownMarker=is_file(storage_path('installed.json'))?(string)file_get_contents(storage_path('installed.json')):null;$merged=self::mergeMarker($marker,$ownMarker,\Dotenv\Dotenv::parse((string)file_get_contents(base_path('.env'))));if(file_put_contents(storage_path('installed.json'),$merged,LOCK_EX)!==strlen($merged))throw new \RuntimeException('เขียน storage/installed.json ไม่สำเร็จ รันซ้ำด้วย --resume');}
                $writeReceipt(['env_written'=>true,'completed'=>true]);
                if(is_dir($staging)){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staging,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST)as$item)$item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());@rmdir($staging);}
                $zip->close();$this->line('[OK] Restored'.($resume?' (resumed)':'').'; school '.$sourceSchool.' term '.$sourceTerm.'; database credentials and APP_URL preserved. Run config:clear before opening.');
            }else throw new \RuntimeException('action ต้องเป็น backup หรือ restore');
            return self::SUCCESS;
        }catch(\Throwable $e){if(DB::connection()->transactionLevel())DB::rollBack();$this->error('[ERR] '.$e->getMessage());return self::FAILURE;}
        finally{if($partial&&is_file($partial)){unset($zip);unlink($partial);}if($temp&&is_dir($temp)){foreach(glob($temp.'/*')as$f)unlink($f);rmdir($temp);}}
    }
}
