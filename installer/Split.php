<?php
namespace SchoolAtelier\Installer;

require_once __DIR__.'/Setup.php';
require_once __DIR__.'/DbAdmin.php';
require_once __DIR__.'/MysqlAdmin.php';
require_once __DIR__.'/Roots.php';

/**
 * S7 standard install: one machine, two sibling copies of the same release. The installer runs in the INTERNAL app root and also sets up the
 * PUBLIC app root: two databases and two database users (each user can reach only its own database), separate .env, APP_KEY, storage, key
 * directory and cookie/cache prefixes, local pairing (no network), the first full publish, and the lock of both installers.
 * Nothing here is read by the application at run time; it runs once, before Laravel has an .env.
 */
final class Split
{
    /** @var callable(string):array{0:object,1:object} boots the Laravel app of a root and returns [app, console kernel] */
    private $boot;

    public function __construct(private Setup $setup,private ?DbAdmin $admin=null,?callable $boot=null)
    {
        $this->admin??=new MysqlAdmin;
        $this->boot=$boot??function(string $root):array{
            require_once $root.'/vendor/autoload.php';
            $app=require $root.'/bootstrap/app.php';$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();return[$app,$kernel];
        };
    }

    // ------------------------------------------------------------------ roots

    private static function norm(string $p):string{$p=rtrim(str_replace('\\','/',$p),'/');return DIRECTORY_SEPARATOR==='\\'?strtolower($p):$p;}
    private static function inside(string $child,string $parent):bool{$c=self::norm($child).'/';$p=self::norm($parent).'/';return str_starts_with($c,$p);}

    /**
     * Both roots must exist, be two different copies of the same release, and never share storage/ or bootstrap/cache (also through a symlink).
     * @return array{0:string,1:string} real paths of the internal and the public root
     */
    public static function validateRoots(string $internal,string $public):array
    {
        if($public===''||strpbrk($public,"\0\r\n")!==false||!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$public))throw new \RuntimeException('ระบุ path เต็มของโฟลเดอร์แอปหน้าบ้าน (สำเนาชุดเดียวกัน) เช่น /home/school/www-app');
        $int=realpath($internal);$pub=realpath($public);
        if(!$int)throw new \RuntimeException('ไม่พบโฟลเดอร์แอปฝั่งระบบภายใน');
        if(!$pub||!is_dir($pub))throw new \RuntimeException('ไม่พบโฟลเดอร์แอปหน้าบ้าน: ต้องวางสำเนาของชุดติดตั้งเดียวกันไว้ก่อน');
        if(self::norm($int)===self::norm($pub)||self::inside($pub,$int)||self::inside($int,$pub))throw new \RuntimeException('โฟลเดอร์หน้าบ้านต้องเป็นสำเนาแยก ไม่ซ้อนหรือเป็นที่เดียวกับระบบภายใน');
        foreach(['artisan','vendor/autoload.php','VERSION','bootstrap/app.php']as$f)if(!is_file($pub.'/'.$f))throw new \RuntimeException('โฟลเดอร์หน้าบ้านไม่ใช่ชุดติดตั้งที่ครบ (ไม่พบ '.$f.')');
        if(trim((string)@file_get_contents($int.'/VERSION'))!==trim((string)@file_get_contents($pub.'/VERSION')))throw new \RuntimeException('เวอร์ชันของสองโฟลเดอร์ต้องเท่ากัน (ไฟล์ VERSION ต่างกัน)');
        if(is_file($pub.'/.env')||is_file($pub.'/storage/installed.json'))throw new \RuntimeException('โฟลเดอร์หน้าบ้านมี .env หรือถูกติดตั้งแล้ว ไม่ติดตั้งทับ');
        if(!is_writable($pub))throw new \RuntimeException('เขียนโฟลเดอร์หน้าบ้านไม่ได้ (ต้องเขียน .env ได้)');
        foreach(['storage','bootstrap/cache']as$d)if(!is_dir($pub.'/'.$d)||!is_writable($pub.'/'.$d))throw new \RuntimeException('เขียนโฟลเดอร์ '.$d.' ของหน้าบ้านไม่ได้');
        if($why=Roots::conflict($int,$pub))throw new \RuntimeException($why);
        if(is_file($pub.'/public/install/index.php')===false&&is_dir($pub.'/public')===false)throw new \RuntimeException('โฟลเดอร์หน้าบ้านไม่มี public/');
        return[$int,$pub];
    }

    // ------------------------------------------------------------------ inputs

    public static function validateAdmin(array $in):array
    {
        $d=[];foreach(['db_host','db_port','db_user','db_password']as$k){$v=$in[$k]??'';if(!is_string($v)||strpbrk($v,"\r\n\0")!==false)throw new \InvalidArgumentException('ข้อมูลฐานข้อมูลไม่ถูกต้อง');$d[$k]=$v;}
        if(!preg_match('/^[a-zA-Z0-9._:-]{1,253}$/D',$d['db_host'])||!ctype_digit($d['db_port'])||(int)$d['db_port']<1||(int)$d['db_port']>65535||($d['db_user']!==''&&!preg_match('/^[a-zA-Z0-9_.@-]{1,80}$/D',$d['db_user']))||strlen($d['db_password'])>512)throw new \InvalidArgumentException('ตรวจ host, port และชื่อผู้ใช้ผู้ดูแลฐานข้อมูล');
        return$d;
    }

    /** Names the shared-hosting user may have changed (the control panel often adds an account prefix): same limits as MySQL. */
    public static function validateNames(array $in,array $names):array
    {
        foreach(['db_internal','db_public']as$k){$v=$in[$k]??$names[$k];if(!is_string($v)||!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$v))throw new \InvalidArgumentException('ชื่อฐานข้อมูลไม่ถูกต้อง');$names[$k]=$v;}
        foreach(['user_internal','user_public']as$k){$v=$in[$k]??$names[$k];if(!is_string($v)||!preg_match('/^[a-zA-Z0-9_]{1,32}$/D',$v))throw new \InvalidArgumentException('ชื่อผู้ใช้ฐานข้อมูลต้องไม่เกิน 32 ตัวอักษร');$names[$k]=$v;}
        if($names['db_internal']===$names['db_public']||$names['user_internal']===$names['user_public'])throw new \InvalidArgumentException('สองฝั่งต้องใช้ฐานข้อมูลและผู้ใช้คนละชุด');
        return$names;
    }

    public static function password():string
    {
        $a='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';$p='';for($i=0;$i<32;$i++)$p.=$a[random_int(0,strlen($a)-1)];return$p;
    }

    private static function cred(array $server,string $db,string $user,string $password):array
    {
        return['db_host'=>$server['db_host'],'db_port'=>$server['db_port'],'db_name'=>$db,'db_user'=>$user,'db_password'=>$password];
    }

    // ------------------------------------------------------------------ database provisioning

    /**
     * Creates both databases and both users with random passwords when the admin account can; otherwise returns the same names and passwords
     * for the operator to create in the control panel.
     * @param array{db_host:string,db_port:string,db_user:string,db_password:string} $admin  db_user '' = no admin account (shared hosting)
     * @param list<string> $taken ids that must not be used
     * @return array{id:string,auto:bool,note:string,names:array,creds:array{internal:array,public:array}}
     */
    public function provision(array $admin,array $taken=[]):array
    {
        $note='';$auto=false;
        for($try=0;$try<5;$try++){
            $id=Identity::generate($taken);$n=Identity::derive($id,'internal');
            $names=['db_internal'=>$n['db_internal'],'db_public'=>$n['db_public'],'user_internal'=>$n['user_internal'],'user_public'=>$n['user_public']];
            $creds=['internal'=>self::cred($admin,$names['db_internal'],$names['user_internal'],self::password()),'public'=>self::cred($admin,$names['db_public'],$names['user_public'],self::password())];
            if($admin['db_user']===''){$note='ไม่ได้ระบุบัญชีผู้ดูแลฐานข้อมูล: สร้างตามรายการด้านล่างในแผงควบคุม';break;}
            $made=[];
            try{
                // never reuse an id whose database OR user already exists (a user left behind by an earlier run has a password nobody knows)
                if($this->admin->exists($admin,$names['db_internal'])||$this->admin->exists($admin,$names['db_public'])||$this->admin->userExists($admin,$names['user_internal'])===true||$this->admin->userExists($admin,$names['user_public'])===true){$taken[]=$id;continue;}
                foreach(['internal','public']as$side){
                    $this->admin->createDatabase($admin,$names['db_'.$side]);$made[]=['db',$names['db_'.$side]];
                    $this->admin->createUser($admin,$names['user_'.$side],$creds[$side]['db_password']);$made[]=['user',$names['user_'.$side]];
                    $this->admin->grant($admin,$names['db_'.$side],$names['user_'.$side]);
                }
                $auto=true;
            }catch(\Throwable $e){
                $this->undo($admin,$made); // only what this run created, so 'start again' leaves no database or user nobody knows the password of
                if($e instanceof \PDOException&&MysqlAdmin::errno($e)===1045)throw new \RuntimeException('ชื่อหรือรหัสผ่านของบัญชีผู้ดูแลฐานข้อมูลไม่ถูกต้อง');
                $note='บัญชีผู้ดูแลฐานข้อมูลสร้างฐานข้อมูลหรือผู้ใช้ไม่ได้ (สิทธิ์ไม่พอ): สร้างตามรายการด้านล่างในแผงควบคุม แล้วกดตรวจสอบอีกครั้ง';
            }
            break;
        }
        if(!isset($id)||$try>=5)throw new \RuntimeException('สร้างรหัสติดตั้งที่ไม่ซ้ำไม่สำเร็จ ลองใหม่');
        return['id'=>$id,'auto'=>$auto,'note'=>$note,'names'=>$names,'creds'=>$creds];
    }

    private function undo(array $admin,array $made):void
    {
        foreach(array_reverse($made)as[$kind,$name]){try{$kind==='db'?$this->admin->dropDatabase($admin,$name):$this->admin->dropUser($admin,$name);}catch(\Throwable){}}
    }

    /**
     * Every line of SHOW GRANTS must stay inside the user's own database: only `GRANT USAGE ON *.*` and grants on `<own>`.* (or one of its tables/columns,
     * with or without the escaped underscore) are accepted. Any other database (mysql, %, another customer's), a global privilege, GRANT OPTION and a
     * role grant (no ON clause) are problems.
     * @param list<string> $grants
     * @return list<string> problems
     */
    public static function grantProblems(array $grants,string $own):array
    {
        $out=[];
        foreach($grants as$g){
            $g=(string)$g;
            if(preg_match('/\bGRANT OPTION\b/i',$g))$out[]='ผู้ใช้มี GRANT OPTION';
            if(!preg_match('/^GRANT\s+(.+?)\s+ON\s+(?:(?:TABLE|FUNCTION|PROCEDURE)\s+)?(.+?)\s+TO\s/is',$g,$m)||!preg_match('/^(`(?:[^`]|``)*`|\*)\.(`(?:[^`]|``)*`|\*)$/',trim($m[2]),$o)){$out[]='ผู้ใช้ได้สิทธิ์รูปแบบที่ตรวจไม่ได้ (เช่น role): '.mb_substr(trim($g),0,70);continue;}
            if($o[1]==='*'){if($o[2]!=='*'||strtoupper(trim($m[1]))!=='USAGE')$out[]='ผู้ใช้มีสิทธิ์ระดับทั้งเซิร์ฟเวอร์ ('.trim($m[1]).')';continue;}
            $raw=str_replace('``','`',substr($o[1],1,-1));
            if($raw!==$own&&str_replace('\\_','_',$raw)!==$own)$out[]='ผู้ใช้มีสิทธิ์บนฐาน '.$raw.' ซึ่งไม่ใช่ฐานของฝั่งนี้ ('.trim($m[1]).')';
        }
        return $out;
    }

    /**
     * Connectivity and isolation: each user reaches its own database and is REFUSED on the other one; every grant stays inside its own database.
     * A check that could not be completed (timeout, too many connections, host down, unreadable grants) is a problem, never a pass.
     * @return list<string> problems (empty = ok)
     */
    public function verify(array $creds):array
    {
        $p=[];$other=['internal'=>'public','public'=>'internal'];
        if($creds['internal']['db_name']===$creds['public']['db_name']||$creds['internal']['db_user']===$creds['public']['db_user'])$p[]='สองฝั่งต้องใช้ฐานข้อมูลและผู้ใช้คนละชุด';
        foreach(['internal'=>'ระบบภายใน','public'=>'หน้าบ้าน']as$side=>$label){
            $c=$creds[$side];
            if(!$this->admin->canConnect($c)){$p[]=$label.': ผู้ใช้ '.$c['db_user'].' เชื่อมต่อฐาน '.$c['db_name'].' ไม่ได้ (ตรวจชื่อ รหัสผ่าน และสิทธิ์)';continue;}
            $o=$creds[$other[$side]]['db_name'];
            try{if($this->admin->canRead($c,$o))$p[]=$label.': ผู้ใช้ '.$c['db_user'].' อ่านฐาน '.$o.' ของอีกฝั่งได้ ต้องถอนสิทธิ์ก่อน';}
            catch(\Throwable){$p[]=$label.': ตรวจการแยกไม่สำเร็จ (ลองอ่านฐาน '.$o.' แล้วเกิดข้อผิดพลาดที่ไม่ใช่การปฏิเสธสิทธิ์) กดตรวจสอบอีกครั้ง';continue;}
            try{$g=$this->admin->grants($c);if(!$g)throw new \RuntimeException('empty');}
            catch(\Throwable){$p[]=$label.': ตรวจการแยกไม่สำเร็จ (อ่านสิทธิ์ของผู้ใช้ '.$c['db_user'].' ไม่ได้) กดตรวจสอบอีกครั้ง';continue;}
            foreach(self::grantProblems($g,$c['db_name'])as$m)$p[]=$label.': '.$m;
        }
        return array_values(array_unique($p));
    }

    // ------------------------------------------------------------------ install

    /**
     * @param array{id:string,public_root:string,public_url:string,creds:array{internal:array,public:array}} $plan
     * @param array $site validated by Setup::validateSite (app_url = the internal URL)
     * @return array{mode:string,layout:string,id:string,public_url:string,internal_url:string,paired:array,published:int}
     */
    public function install(array $plan,array $site):array
    {
        $setup=$this->setup;$setup->prepare();
        $handle=fopen($setup->dir.'/install.lock','c+');if(!flock($handle,LOCK_EX|LOCK_NB)){fclose($handle);throw new \RuntimeException('มีการติดตั้งอีกหน้าต่างกำลังทำงาน');}
        $created=[];$dirtied=[];$app=null;$restore=[];$written=[];$result=[];
        try{
            if($setup->installed()||$setup->legacy())throw new \RuntimeException('ไม่ติดตั้งทับเว็บเดิม');
            [$int,$pub]=self::validateRoots($setup->root,$plan['public_root']);$id=$plan['id'];
            if(!Identity::valid($id)||Identity::idAt($int)!==null||Identity::idAt($pub)!==null)throw new \RuntimeException('รหัสติดตั้งไม่ถูกต้อง');
            if($problems=$this->verify($plan['creds']))throw new \RuntimeException('ฐานข้อมูลยังไม่พร้อม: '.implode(' | ',$problems));
            $internalUrl=$site['app_url'];$publicUrl=Setup::validatePublicSite(['app_url'=>$plan['public_url'],'school_name'=>$site['school_name']])['app_url'];
            if(Roots::sameOrigin($publicUrl,$internalUrl))throw new \RuntimeException('URL หน้าบ้านกับระบบภายในต้องอยู่คนละโฮสต์ (เช่น www.โรงเรียน.ac.th กับ app.โรงเรียน.ac.th) ถ้าอยู่ origin เดียวกัน สคริปต์ของหน้าบ้านจะอ่านและสั่งงานระบบภายในด้วยคุกกี้ของเจ้าหน้าที่ได้');
            $pushUrl=$publicUrl.'/_saan/push';
            $state=$setup->state()+['receipt'=>bin2hex(random_bytes(16)),'key_internal'=>'base64:'.base64_encode(random_bytes(32)),'key_public'=>'base64:'.base64_encode(random_bytes(32))];$setup->atomic($setup->dir.'/state.json',json_encode(['receipt'=>$state['receipt']],JSON_THROW_ON_ERROR)); // keys are not written to disk before the .env
            [$app,$kernel]=($this->boot)($int);
            $cfg=$app['config'];
            foreach(['database.default','database.connections.saan_internal','database.connections.saan_public','app.key','saan.edition','saan.install_id','school.school_id','school.demo','cache.default','queue.default','saan.push.receive_disk','filesystems.disks.saan_install_public','saan.public_url']as$k)$restore[$k]=$cfg->get($k);
            $conn=fn(array $c)=>['driver'=>'mysql','host'=>$c['db_host'],'port'=>$c['db_port'],'database'=>$c['db_name'],'username'=>$c['db_user'],'password'=>$c['db_password'],'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'engine'=>'InnoDB'];
            $cfg->set('database.connections.saan_internal',$conn($plan['creds']['internal']));$cfg->set('database.connections.saan_public',$conn($plan['creds']['public']));
            $cfg->set('app.key',$state['key_internal']);$cfg->set('saan.edition','internal');$cfg->set('saan.public_url',$publicUrl);$cfg->set('saan.install_id',$id);$cfg->set('school.school_id',1);$cfg->set('school.demo',false);$cfg->set('cache.default','file');$cfg->set('queue.default','sync');
            $app['db']->purge('saan_internal');$app['db']->purge('saan_public');
            foreach(['saan_internal','saan_public']as$c){if($app['db']->connection($c)->getSchemaBuilder()->getTables())throw new \RuntimeException('ฐานข้อมูลต้องว่างทั้งสองฝั่ง ระบบจะไม่ลบหรือเขียนทับฐานเดิม ('.$plan['creds'][$c==='saan_internal'?'internal':'public']['db_name'].')');$dirtied[]=$c;}
            $app['db']->setDefaultConnection('saan_internal');
            foreach(['saan_internal'=>'ระบบภายใน','saan_public'=>'หน้าบ้าน']as$c=>$label)if($kernel->call('migrate',['--database'=>$c,'--force'=>true,'--no-interaction'=>true])!==0)throw new \RuntimeException('สร้างโครงสร้างฐานข้อมูล'.$label.'ไม่สำเร็จ ตรวจสิทธิ์ CREATE/ALTER/INDEX/REFERENCES');
            $result=$app->make(\App\Domain\InstallationContent::class)->install($site,$state['receipt']);

            // pairing without a network: the same internal half as the one-time code flow, the public half written into the public app's own key directory
            $keys=$pub.'/storage/app/private/saan-keys';if(!is_dir($keys))$created[]=$keys;
            $paired=\App\Support\Saan\Publish\Pairing::pairLocal($keys,$pushUrl);
            // first full publish: the signed batches go straight into the public database (the public vhost does not have to exist yet)
            $cfg->set('filesystems.disks.saan_install_public',['driver'=>'local','root'=>$pub.'/storage/app/private']);$cfg->set('saan.push.receive_disk','saan_install_public');
            $receiver=new \App\Support\Saan\Publish\Receiver('saan_public',\App\Support\Saan\Publish\Keys::publicKey());
            $sent=(new \App\Support\Saan\Publish\Publisher(null,fn(string $body,array $headers)=>$receiver->handle($body,$headers)))->fullAndDeliver(120);
            if(($sent['failed']??0)>0||!empty($sent['error']))throw new \RuntimeException('ส่งข้อมูลชุดแรกไปหน้าบ้านไม่สำเร็จ: '.($sent['error']??'ไม่ทราบสาเหตุ'));

            // only now write the configuration and the lock files (public first: the internal installed.json is what the installer itself checks)
            $when=gmdate('c');$common=['version'=>$setup->version(),'installed_at'=>$when,'receipt'=>$state['receipt'],'install_id'=>$id,'layout'=>'standard','db_isolation_verified_at'=>$when];
            $pubSite=array_replace($site,['app_url'=>$publicUrl,'public_email'=>$site['public_email']]);
            $envPub=$setup->environment($plan['creds']['public'],$pubSite,$state['key_public'],Identity::env($id,'public')+['SAAN_INTERNAL_URL'=>$internalUrl]);
            $envInt=$setup->environment($plan['creds']['internal'],$site,$state['key_internal'],Identity::env($id,'internal')+['SAAN_PUBLIC_URL'=>$publicUrl,'SAAN_PUBLIC_PUSH_URL'=>$pushUrl]);
            // every file already written is remembered: if a later one fails, all of them are removed again together with the tables (nothing half-locked)
            $put=function(string $file,string $bytes)use($setup,&$written):void{$written[]=$file;$setup->atomic($file,$bytes);};
            $put($pub.'/.env',$envPub);
            $put($pub.'/storage/installed.json',json_encode($common+['mode'=>'blank','edition'=>'public'],JSON_PRETTY_PRINT));
            $put($int.'/.env',$envInt);
            $put($int.'/storage/installed.json',json_encode($common+['mode'=>$site['mode'],'edition'=>'internal','public_root'=>$pub,'public_url'=>$publicUrl],JSON_PRETTY_PRINT));
            foreach(['owner-token.txt','state.json','attempts.json']as$name)if(is_file($setup->dir.'/'.$name))unlink($setup->dir.'/'.$name);
            $setup->purgeSessions();
            return$result+['layout'=>'standard','id'=>$id,'public_url'=>$publicUrl,'internal_url'=>$internalUrl,'paired'=>$paired,'published'=>(int)($sent['sent']??0)];
        }catch(\Throwable $e){
            // nothing is locked yet: empty what this run created so the operator can simply start again
            foreach($written as$f){@unlink($f);foreach(glob($f.'.*.tmp')?:[]as$t)@unlink($t);}
            // a full trial that got as far as its school: the tables go below, so its files (portraits, sample slips, the account list with passwords) go too
            foreach($result['demo']['files']??[]as$f)if(is_file($f))@unlink($f);
            if($app)foreach($dirtied as$c){try{$app['db']->connection($c)->getSchemaBuilder()->dropAllTables();}catch(\Throwable){}}
            foreach($created as$d)self::removeTree($d);
            throw$e;
        }finally{
            if($app){foreach($restore as$k=>$v)$app['config']->set($k,$v);try{$app['db']->setDefaultConnection($restore['database.default']);$app['db']->purge('saan_internal');$app['db']->purge('saan_public');}catch(\Throwable){}}
            flock($handle,LOCK_UN);fclose($handle);
        }
    }

    private static function removeTree(string $d):void
    {
        if(!is_dir($d))return;foreach(scandir($d)?:[]as$f){if($f==='.'||$f==='..')continue;$p=$d.'/'.$f;is_dir($p)&&!is_link($p)?self::removeTree($p):@unlink($p);}@rmdir($d);
    }
}
