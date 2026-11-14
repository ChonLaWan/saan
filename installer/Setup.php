<?php
namespace SchoolAtelier\Installer;

require_once __DIR__.'/Identity.php';

/** Runs before Laravel has an .env, database, application key or database session. */
final class Setup
{
    public readonly string $dir;
    public function __construct(public readonly string $root){$this->dir=$root.'/storage/app/private/setup';}
    public function state():array{return is_file($this->dir.'/state.json')?json_decode(file_get_contents($this->dir.'/state.json'),true,512,JSON_THROW_ON_ERROR):[];}
    public function installed():bool{return is_file($this->root.'/storage/installed.json');}
    public function legacy():bool
    {
        if(!is_file($this->root.'/.env'))return false;
        $state=$this->state();$sha=hash_file('sha256',$this->root.'/.env');
        // Only an .env written by this exact interrupted installer can be resumed.
        return empty($state['env_sha256'])||!hash_equals($state['env_sha256'],$sha);
    }
    /** Release that is being installed (the VERSION file of this folder), written into storage/installed.json. */
    public function version():string{$v=trim((string)@file_get_contents($this->root.'/VERSION'));return$v!==''?$v:'0.0.0-unknown';}
    public static function pending(string $root):bool{return !is_file($root.'/.env')||(!is_file($root.'/storage/installed.json')&&is_file($root.'/storage/app/private/setup/state.json'));}
    public function writable():bool{return is_dir($this->root.'/storage')&&is_writable($this->root.'/storage')&&is_writable($this->root)&&is_writable($this->root.'/bootstrap/cache');}
    public function requirements():array
    {
        $checks=['PHP 8.4.1 ขึ้นไป'=>PHP_VERSION_ID>=80401,'Composer dependencies'=>is_file($this->root.'/vendor/autoload.php'),'เขียน config / storage / cache ได้'=>$this->writable()];
        // Same set as the doctor (App\Domain\Cms\DeploymentHealth) and composer.json, so an install that passes here can also run updates later:
        // intl, sodium and zip are errors there (signed update packages are ZIP files verified with sodium), xmlreader reads spreadsheets.
        foreach(['pdo','pdo_mysql','mbstring','openssl','fileinfo','ctype','curl','dom','filter','hash','session','tokenizer','xml','xmlreader','intl','sodium','zip','gd']as$ext)$checks['PHP '.$ext]=extension_loaded($ext);
        $checks['ไม่เหลือ config cache จากเครื่องเดิม']=!is_file($this->root.'/bootstrap/cache/config.php');return$checks;
    }
    public function prepare():void
    {
        if($this->installed()||$this->legacy())throw new \RuntimeException('ติดตั้งแล้วหรือมี .env เดิม ไม่เปิดตัวติดตั้งทับ');
        if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true))throw new \RuntimeException('สร้างพื้นที่ติดตั้งไม่ได้');
        $file=$this->dir.'/owner-token.txt';if(!is_file($file)){$handle=@fopen($file,'x');if($handle){fwrite($handle,bin2hex(random_bytes(24)));fclose($handle);chmod($file,0600);}}
    }
    public function unlock(string $token):bool
    {
        if($this->installed()||$this->legacy())return false;$this->prepare();
        $h=fopen($this->dir.'/attempts.json','c+');flock($h,LOCK_EX);$raw=stream_get_contents($h);$a=$raw?json_decode($raw,true):['start'=>time(),'count'=>0];if(time()-$a['start']>600)$a=['start'=>time(),'count'=>0];
        if($a['count']>=20){flock($h,LOCK_UN);fclose($h);return false;}
        $a['count']++;rewind($h);ftruncate($h,0);fwrite($h,json_encode($a));flock($h,LOCK_UN);fclose($h);
        return preg_match('/^[a-f0-9]{48}$/D',$token)&&hash_equals(trim(file_get_contents($this->dir.'/owner-token.txt')),$token);
    }
    public static function validateDatabase(array $input):array
    {
        $d=[];foreach(['db_host','db_port','db_name','db_user','db_password']as$key){$v=$input[$key]??'';if(!is_string($v)||strpbrk($v,"\r\n\0")!==false)throw new \InvalidArgumentException('ข้อมูลฐานข้อมูลไม่ถูกต้อง');$d[$key]=$v;}
        if(!preg_match('/^[a-zA-Z0-9._:-]{1,253}$/D',$d['db_host'])||!ctype_digit($d['db_port'])||(int)$d['db_port']<1||(int)$d['db_port']>65535||!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$d['db_name'])||!preg_match('/^[a-zA-Z0-9_.@-]{1,80}$/D',$d['db_user'])||strlen($d['db_password'])>512)throw new \InvalidArgumentException('ตรวจ host, port, ชื่อฐานข้อมูลและชื่อผู้ใช้');
        return$d;
    }
    /** Public edition of a two machine install: only the school name and URL are asked, there is no account to create (the public site has no login). */
    public static function validatePublicSite(array $input):array
    {
        $pw='Aa1'.bin2hex(random_bytes(12));$d=self::validateSite(array_replace($input,['admin_name'=>'public','admin_email'=>'public@example.invalid','password'=>$pw,'password_confirmation'=>$pw,'mode'=>'blank']));
        unset($d['password'],$d['admin_name'],$d['admin_email']);return$d;
    }
    public static function validateSite(array $input):array
    {
        $d=[];foreach(['school_name','admin_name','admin_email','password','password_confirmation','app_url','mode','public_email','phone','address']as$k){$v=$input[$k]??'';if(!is_string($v)||strpbrk($v,"\r\n\0")!==false)throw new \InvalidArgumentException('ข้อมูลมีตัวอักษรที่ใช้ไม่ได้');$d[$k]=trim($v);}
        foreach(['school_name'=>140,'admin_name'=>140,'admin_email'=>254,'app_url'=>500,'public_email'=>254,'phone'=>80,'address'=>500]as$k=>$max)if(mb_strlen($d[$k])>$max)throw new \InvalidArgumentException('ข้อมูลยาวเกินกำหนด');
        if(!$d['school_name']||!$d['admin_name']||!filter_var($d['admin_email'],FILTER_VALIDATE_EMAIL)||($d['public_email']&&!filter_var($d['public_email'],FILTER_VALIDATE_EMAIL)))throw new \InvalidArgumentException('กรอกชื่อโรงเรียน ผู้ดูแล และอีเมลให้ครบ');
        $url=parse_url($d['app_url']);if(!filter_var($d['app_url'],FILTER_VALIDATE_URL)||!in_array($url['scheme']??'',['https','http'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||(($url['scheme']??'')==='http'&&!in_array($url['host']??'',['localhost','127.0.0.1','::1'],true)))throw new \InvalidArgumentException('URL ต้องเป็น https หรือ localhost ไม่มี query/ข้อมูลผู้ใช้');
        $password=$input['password'];if(strlen($password)>72||mb_strlen($password)<12||!preg_match('/[a-zA-Z]/',$password)||!preg_match('/[0-9]/',$password)||$password!==$input['password_confirmation'])throw new \InvalidArgumentException('รหัสผ่านต้องตรงกัน อย่างน้อย 12 ตัวอักษร มีตัวอักษรอังกฤษและตัวเลข ไม่เกิน 72 bytes');
        // blank = structure only, demo = website sample, full = website sample plus the whole fictional trial school with trial accounts (trials only)
        if(!in_array($d['mode'],['blank','demo','full'],true))throw new \InvalidArgumentException('เลือกรูปแบบติดตั้ง');$d['password']=$password;unset($d['password_confirmation']);$d['app_url']=rtrim($d['app_url'],'/');return$d;
    }
    private function pdo(array $d):\PDO{return new \PDO('mysql:host='.$d['db_host'].';port='.$d['db_port'].';dbname='.$d['db_name'].';charset=utf8mb4',$d['db_user'],$d['db_password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_TIMEOUT=>5]);}
    private function target(array $d):string{unset($d['db_password']);ksort($d);return hash('sha256',json_encode($d));}
    public function checkDatabase(array $d):array
    {
        $pdo=$this->pdo($d);$tables=$pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM);$s=$this->state();
        if($tables&&(($s['target']??'')!==$this->target($d)||empty($s['receipt'])))throw new \RuntimeException('ฐานข้อมูลต้องว่าง ระบบจะไม่ลบหรือเขียนทับฐานเดิม');
        return['server'=>$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),'tables'=>count($tables),'resume'=>(bool)$tables];
    }
    private function saveState(array $s):void{$this->atomic($this->dir.'/state.json',json_encode($s,JSON_THROW_ON_ERROR));}
    public function atomic(string $file,string $bytes):void
    {
        $temp=$file.'.'.bin2hex(random_bytes(6)).'.tmp';if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes))throw new \RuntimeException('เขียนไฟล์ติดตั้งไม่สำเร็จ');chmod($temp,0600);if(!rename($temp,$file)){unlink($temp);throw new \RuntimeException('บันทึก config ไม่สำเร็จ');}
    }
    /** Installer sessions can hold generated database passwords: after a finished install every sess_* file (any browser, any abandoned run) goes. */
    public function purgeSessions():void
    {
        $d=$this->dir.'/sessions';if(!is_dir($d))return;
        foreach(scandir($d)?:[]as$f)if($f!=='.'&&$f!=='..'&&is_file($d.'/'.$f)&&!is_link($d.'/'.$f))@unlink($d.'/'.$f);
        @rmdir($d);
    }
    public static function quote(string $value):string{return '"'.str_replace(['\\','"','$'],['\\\\','\\"','\\$'],$value).'"';}
    public function environment(array $db,array $site,string $key,array $extra=[]):string
    {
        $secure=str_starts_with($site['app_url'],'https://');$values=['APP_NAME'=>$site['school_name'],'APP_ENV'=>'production','APP_KEY'=>$key,'APP_DEBUG'=>'false','APP_URL'=>$site['app_url'],'APP_LOCALE'=>'th','APP_FALLBACK_LOCALE'=>'en','APP_TIMEZONE'=>'Asia/Bangkok','LOG_CHANNEL'=>'stack','LOG_STACK'=>'daily','LOG_DAILY_DAYS'=>'14','LOG_LEVEL'=>'warning','DB_CONNECTION'=>$db['driver']??'mysql','DB_HOST'=>$db['db_host'],'DB_PORT'=>$db['db_port'],'DB_DATABASE'=>$db['db_name'],'DB_USERNAME'=>$db['db_user'],'DB_PASSWORD'=>$db['db_password'],'SESSION_DRIVER'=>'database','SESSION_LIFETIME'=>'120','SESSION_ENCRYPT'=>'true','SESSION_SECURE_COOKIE'=>$secure?'true':'false','SESSION_HTTP_ONLY'=>'true','SESSION_SAME_SITE'=>'lax','SESSION_COOKIE'=>'school_'.substr(hash('sha256',$site['app_url']),0,12),'SESSION_PATH'=>parse_url($site['app_url'],PHP_URL_PATH)?:'/','CACHE_STORE'=>'file','QUEUE_CONNECTION'=>'sync','FILESYSTEM_DISK'=>'local','MAIL_MAILER'=>'log','MAIL_FROM_ADDRESS'=>$site['public_email']?:'hello@example.com','SCHOOL_LOCAL_ONLY'=>$secure?'false':'true','SCHOOL_DEMO'=>'false','SCHOOL_ID'=>'1','SCHOOL_TERM_ID'=>'1'];
        $values=array_replace($values,$extra);$out="# Generated by Saan installer. Keep this file private.\n";foreach($values as$k=>$v)$out.=$k.'='.self::quote($v)."\n";return$out;
    }
    public function install(array $db,array $site,array $opt=[]):array
    {
        $edition=$opt['edition']??'combined';if(!in_array($edition,Identity::EDITIONS,true))throw new \RuntimeException('รูปแบบการติดตั้งไม่ถูกต้อง');
        $this->prepare();$handle=fopen($this->dir.'/install.lock','c+');if(!flock($handle,LOCK_EX|LOCK_NB)){fclose($handle);throw new \RuntimeException('มีการติดตั้งอีกหน้าต่างกำลังทำงาน');}
        try{
            if($this->installed()||$this->legacy())throw new \RuntimeException('ไม่ติดตั้งทับเว็บเดิม');
            $this->checkDatabase($db);$state=$this->state();$state+=['receipt'=>bin2hex(random_bytes(16)),'key'=>'base64:'.base64_encode(random_bytes(32)),'target'=>$this->target($db),'install_id'=>Identity::generate()];
            if($state['target']!==$this->target($db))throw new \RuntimeException('การกู้ติดตั้งต้องใช้ฐานข้อมูลเป้าหมายเดิม');
            $this->saveState($state);
            require_once $this->root.'/vendor/autoload.php';
            $app=require $this->root.'/bootstrap/app.php';$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
            $app['config']->set('database.default','mysql');$app['config']->set('database.connections.mysql',['driver'=>'mysql','host'=>$db['db_host'],'port'=>$db['db_port'],'database'=>$db['db_name'],'username'=>$db['db_user'],'password'=>$db['db_password'],'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'engine'=>'InnoDB']);
            $app['config']->set('app.key',$state['key']);$app['config']->set('school.school_id',1);$app['config']->set('school.demo',false);$app['config']->set('cache.default','file');$app['config']->set('queue.default','sync');$app['db']->purge('mysql');
            if($kernel->call('migrate',['--force'=>true,'--no-interaction'=>true])!==0)throw new \RuntimeException('สร้างโครงสร้างฐานข้อมูลไม่สำเร็จ ตรวจสิทธิ์ CREATE/ALTER/INDEX/REFERENCES');
            $old=\Illuminate\Support\Facades\DB::table('audit_events')->where('action','installation.completed')->first();
            if($edition==='public')$result=['mode'=>'public','resumed'=>false];elseif($old){$detail=json_decode($old->detail,true);if(($detail['receipt']??'')!==$state['receipt'])throw new \RuntimeException('มีข้อมูลติดตั้งจากระบบอื่นแล้ว');$user=\Illuminate\Support\Facades\DB::table('users')->where('id',$old->actor_id)->first();if($user->email!==mb_strtolower($site['admin_email'])||!\Illuminate\Support\Facades\Hash::check($site['password'],$user->password)||$detail['mode']!==$site['mode'])throw new \RuntimeException('ติดตั้งข้อมูลแล้ว ให้ใช้บัญชีและโหมดเดิมเพื่อจบขั้นตอน config');$result=['mode'=>$detail['mode'],'resumed'=>true];
                // the trial school and its accounts were committed together with the content in the earlier run: the passwords are only in the account file now
                if($detail['mode']==='full')$result['demo']=['accounts'=>[],'file'=>$this->root.'/storage/app/private/demo-accounts.txt','resumed'=>true];}
            else $result=$app->make(\App\Domain\InstallationContent::class)->install($site,$state['receipt']);
            $env=$this->environment($db,$site,$state['key'],Identity::env($state['install_id'],$edition));$state['env_sha256']=hash('sha256',$env);$this->saveState($state);$this->atomic($this->root.'/.env',$env);
            $this->atomic($this->root.'/storage/installed.json',json_encode(['version'=>$this->version(),'installed_at'=>gmdate('c'),'mode'=>$site['mode'],'receipt'=>$state['receipt'],'install_id'=>$state['install_id'],'edition'=>$edition,'layout'=>$edition==='combined'?($opt['layout']??'demo'):'advanced'],JSON_PRETTY_PRINT));
            foreach(['owner-token.txt','state.json','attempts.json']as$name)if(is_file($this->dir.'/'.$name))unlink($this->dir.'/'.$name);
            $this->purgeSessions();
            return$result;
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }
}
