<?php
namespace Tests\Feature;

use App\Domain\InstallationContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Hash};
use Tests\TestCase;

require_once __DIR__.'/../../installer/Setup.php';
use SchoolAtelier\Installer\Setup;

class InstallationTest extends TestCase
{
    use RefreshDatabase;
    private array $temporary=[];
    private function temp():string
    {
        $root=storage_path('framework/testing/setup-'.bin2hex(random_bytes(6)));foreach(['storage','bootstrap/cache']as$dir)mkdir($root.'/'.$dir,0700,true);$this->temporary[]=$root;return$root;
    }
    protected function tearDown():void{foreach($this->temporary as$root)(new \Illuminate\Filesystem\Filesystem)->deleteDirectory($root);parent::tearDown();}
    private function input(string $mode='blank'):array{return['mode'=>$mode,'school_name'=>'โรงเรียนทดสอบการติดตั้ง','admin_name'=>'ผู้ดูแลทดสอบ','admin_email'=>'owner@school.example','password'=>'Synthetic-Install-2026','password_confirmation'=>'Synthetic-Install-2026','app_url'=>'https://school.example/cms','public_email'=>'contact@school.example','phone'=>'','address'=>''];}
    public function test_blank_install_has_private_structure_and_no_fictional_people_or_published_news():void
    {
        $r=app(InstallationContent::class)->install($this->input(),'blank-test');
        $this->assertSame(0,$r['published']);$this->assertSame(11,$r['drafts']);$this->assertSame(0,$r['media']);
        $this->assertSame(0,DB::table('students')->count());$this->assertSame(0,DB::table('teachers')->count());
        $user=DB::table('users')->first();$this->assertFalse((bool)$user->is_demo);$this->assertTrue(Hash::check('Synthetic-Install-2026',$user->password));
        config(['school.local_only'=>false,'school.demo'=>false]);$this->get('/portal')->assertOk()->assertSee('โรงเรียนทดสอบการติดตั้ง')->assertSee('เรื่องราวใหม่ของโรงเรียนจะปรากฏที่นี่');
        $this->post('/login',['email'=>'owner@school.example','password'=>'Synthetic-Install-2026'])->assertRedirect('/');$this->getJson('/api/site/state')->assertOk();
        $this->assertDatabaseHas('posts',['slug'=>'hero-1','status'=>'draft','content_type'=>'banner']);$this->assertDatabaseHas('audit_events',['action'=>'installation.completed']);
    }
    public function test_demo_install_reuses_verified_media_and_all_twelve_content_types_without_shared_logins():void
    {
        $original=$this->app->storagePath();$originalDisk=config('filesystems.disks.local.root');$isolated=$this->temp().'/storage';$this->app->useStoragePath($isolated);config(['filesystems.disks.local.root'=>$isolated.'/app/private']);\Illuminate\Support\Facades\Storage::forgetDisk('local');
        try{$r=app(InstallationContent::class)->install($this->input('demo'),'demo-test');$this->assertSame(62,$r['published']);$this->assertSame(53,$r['media']);$this->assertSame(12,DB::table('posts')->distinct()->count('content_type'));$this->assertSame(1,DB::table('users')->count());$this->assertSame(24,DB::table('students')->count());
            foreach(DB::table('cms_media')->get()as$m)$this->assertSame($m->bytes,filesize(storage_path('app/private/'.$m->path)));
            $this->assertSame(0,DB::table('users')->where('is_demo',true)->count());
            $this->get('/portal')->assertOk();$this->get('/portal/people')->assertOk();$this->get('/portal/downloads')->assertOk();$this->get('/media/'.DB::table('posts')->whereNotNull('cover_media_id')->value('cover_media_id'))->assertOk();
            $this->travelTo(\Carbon\Carbon::parse('2026-11-06 10:00'));$this->actingAs(\App\Models\User::first());$this->getJson('/api/state')->assertOk()->assertJsonCount(6,'teachers')->assertJsonPath('counts.students',24)->assertJsonCount(8,'students')->assertJsonCount(79,'lessons')->assertJsonCount(4,'duties');$this->getJson('/api/operations/catalogue')->assertOk()->assertJsonCount(24,'modules')->assertJsonPath('modules.0.evidence',[]);
            // N8: the demo bundle must also feed the new modules (keys, versions, term ids), not only the retired prototype
            $this->assertSame(0,DB::table('classrooms')->whereNull('name_key')->count());$this->assertSame(0,DB::table('teachers')->whereNull('name_key')->count());$this->assertSame(0,DB::table('students')->whereNull('code_key')->count());$this->assertSame(0,DB::table('lessons')->whereNull('room_key')->count());$this->assertSame(0,DB::table('attendances')->whereNull('term_id')->count());
            // content, not only HTTP 200: an empty list must fail
            $cl=$this->getJson('/api/academic/classrooms')->assertOk()->json();$this->assertCount(3,$cl['data']??$cl['classrooms']??$cl);$this->assertNotEmpty($this->getJson('/api/academic/teachers')->assertOk()->json());
            $stu=$this->getJson('/api/registry/students')->assertOk()->json();$this->assertCount(24,$stu['data']??$stu['students']??$stu);
            $this->assertSame(['ป.4/1','ป.5/1','ม.1/1'],DB::table('classrooms')->orderBy('id')->pluck('name_key')->all());
            $lessons=$this->getJson('/api/timetable/lessons?term_id=1&view=classroom&id=1')->assertOk()->assertJsonPath('term_id',1)->json('lessons');$this->assertGreaterThan(0,count($lessons));
            $leaves=$this->getJson('/api/leave/requests')->assertOk()->json();$this->assertNotEmpty($leaves);
            $this->assertSame(0,DB::table('leave_requests')->whereNull('fiscal_year')->count());$this->assertSame(0,DB::table('leave_requests')->where('days','<=',0)->count());$this->assertNotEmpty($this->getJson('/api/duty/duties')->assertOk()->json());$this->assertNotEmpty($this->getJson('/api/attendance/classes')->assertOk()->json());
        }finally{$this->app->useStoragePath($original);config(['filesystems.disks.local.root'=>$originalDisk]);\Illuminate\Support\Facades\Storage::forgetDisk('local');}
    }
    public function test_demo_content_observance_gets_the_ribbon_default_when_mourning_is_switched_on():void
    {
        $original=$this->app->storagePath();$originalDisk=config('filesystems.disks.local.root');$isolated=$this->temp().'/storage';$this->app->useStoragePath($isolated);config(['filesystems.disks.local.root'=>$isolated.'/app/private']);\Illuminate\Support\Facades\Storage::forgetDisk('local');
        try{
            // the bundled content carries every observance key (ribbon on) and the manifest hash matches it
            $bundle=app(InstallationContent::class)->bundle();$this->assertSame(array_keys(\App\Domain\Cms\Observance::defaults()),array_keys($bundle['site']['observance']));$this->assertTrue($bundle['site']['observance']['ribbon']);$this->assertSame('off',$bundle['site']['observance']['mode']);
            app(InstallationContent::class)->install($this->input('demo'),'demo-observance');$this->actingAs(\App\Models\User::first());
            // an installation made before the ribbon (old eleven keys, mode off) is the same case: strip the new keys from the stored rows
            $old=array_intersect_key($bundle['site']['observance'],array_flip(['mode','title','body','image_media_id','mobile_media_id','button_label','starts_at','ends_at','frequency','grayscale','image_fit']));
            foreach(['draft','published']as$col){$row=json_decode(DB::table('cms_site_settings')->value($col),true);$row['observance']=$old;DB::table('cms_site_settings')->update([$col=>json_encode($row)]);}
            $state=$this->getJson('/api/site/state')->assertOk()->assertJsonPath('settings.draft.observance.ribbon',true)->assertJsonPath('settings.draft.observance.mode','off');
            $settings=$state->json('settings.draft');$settings['observance']=array_replace($state->json('settings.draft.observance'),['mode'=>'mourning','title'=>'ไว้อาลัย โรงเรียนต้นธารวิทยา']);
            $this->putJson('/api/site/settings',['version'=>$state->json('settings.version'),'intent'=>'publish','section'=>'all','settings'=>$settings])->assertOk()->assertJsonPath('published.observance.ribbon',true);
            $html=$this->get('/portal')->assertOk()->getContent();$this->assertStringContainsString('data-observance-ribbon',$html);$this->assertStringContainsString('data-observance-mode="mourning"',$html);
        }finally{$this->app->useStoragePath($original);config(['filesystems.disks.local.root'=>$originalDisk]);\Illuminate\Support\Facades\Storage::forgetDisk('local');}
    }
    public function test_bootstrap_refuses_existing_users_without_modifying_them():void
    {
        DB::table('users')->insert(['name'=>'Existing marker','email'=>'existing@school.example','password'=>'untouched','role'=>'admin','school_id'=>1]);
        try{app(InstallationContent::class)->install($this->input(),'repeat');$this->fail('Overwrote existing account');}catch(\RuntimeException $e){$this->assertStringContainsString('ไม่ติดตั้งทับ',$e->getMessage());}
        $this->assertDatabaseHas('users',['password'=>'untouched']);$this->assertSame(0,DB::table('posts')->count());
    }
    public function test_installed_blank_site_can_publish_an_original_notice_without_demo_attribution():void
    {
        app(InstallationContent::class)->install($this->input(),'original-notice');config(['school.demo'=>false]);$user=\App\Models\User::first();$this->actingAs($user);
        $payload=['content_type'=>'job','title'=>'ประกาศของโรงเรียนที่ติดตั้งใหม่','body'=>'รายละเอียดที่โรงเรียนตรวจแล้ว','excerpt'=>'ประกาศจากโรงเรียน','category'=>'jobs','cover_media_id'=>null,'is_pinned'=>false,'metadata'=>['source_kind'=>'original','body_format'=>'plain'],'intent'=>'publish','version'=>0];
        $this->postJson('/api/site/content',$payload)->assertSuccessful();
        $this->get('/portal')->assertOk()->assertSee('ประกาศของโรงเรียนที่ติดตั้งใหม่')->assertDontSee(' · ข้อมูลตัวอย่าง');
        $this->get('/portal/jobs?source=original')->assertOk()->assertSee('ประกาศของโรงเรียนที่ติดตั้งใหม่');
    }
    public function test_owner_token_stays_stable_and_installed_marker_blocks_reentry():void
    {
        $s=new Setup($this->temp());$s->prepare();$token=file_get_contents($s->dir.'/owner-token.txt');$s->prepare();$this->assertSame($token,file_get_contents($s->dir.'/owner-token.txt'));$this->assertFalse((bool)$s->unlock(str_repeat('f',48)));$this->assertTrue($s->unlock($token));
        file_put_contents($s->root.'/storage/installed.json','{}');$this->assertTrue($s->installed());$this->assertFalse($s->unlock($token));
    }
    public function test_legacy_env_is_never_adopted_or_overwritten():void
    {
        $s=new Setup($this->temp());file_put_contents($s->root.'/.env','APP_KEY=original-marker');$this->assertTrue($s->legacy());$this->assertFalse(Setup::pending($s->root));
        try{$s->prepare();$this->fail();}catch(\RuntimeException $e){$this->assertSame('APP_KEY=original-marker',file_get_contents($s->root.'/.env'));}
    }
    public function test_owner_attempt_limit_is_shared_between_sessions():void
    {
        $s=new Setup($this->temp());$s->prepare();for($i=0;$i<20;$i++)$this->assertFalse((bool)$s->unlock('wrong'));$this->assertFalse((bool)$s->unlock(file_get_contents($s->dir.'/owner-token.txt')));
    }
    public function test_database_dsn_and_env_injection_are_rejected():void
    {
        foreach(['db_host'=>"localhost;dbname=other",'db_port'=>'3306;anything','db_name'=>"demo\nAPP_DEBUG=true",'db_user'=>'name;sql','db_password'=>"pass\0word"]as$key=>$bad){$d=['db_host'=>'127.0.0.1','db_port'=>'3306','db_name'=>'new_school','db_user'=>'school_user','db_password'=>'safe'];$d[$key]=$bad;try{Setup::validateDatabase($d);$this->fail($key);}catch(\InvalidArgumentException $e){$this->addToAssertionCount(1);}}
    }
    public function test_site_validation_rejects_remote_http_url_and_weak_or_mismatched_passwords():void
    {
        foreach([['app_url'=>'http://school.example'],['app_url'=>'https://user:pass@school.example'],['app_url'=>'https://school.example/?marker=1'],['password'=>'weak'],['password_confirmation'=>'different'],['mode'=>'overwrite']]as$bad){try{Setup::validateSite(array_replace($this->input(),$bad));$this->fail();}catch(\InvalidArgumentException $e){$this->addToAssertionCount(1);}}
        $this->assertSame('blank',Setup::validateSite($this->input())['mode']);
    }
    public function test_environment_quotes_password_safely_and_disables_demo_login_debug_and_insecure_remote_cookies():void
    {
        $s=new Setup($this->temp());$db=['db_host'=>'localhost','db_port'=>'3306','db_name'=>'school','db_user'=>'school','db_password'=>'a${APP_DEBUG}#"\\xyz'];$env=$s->environment($db,$this->input(),'base64:marker');
        $path=$s->root.'/.env';file_put_contents($path,$env);$parsed=\Dotenv\Dotenv::parse($env);$this->assertSame($db['db_password'],$parsed['DB_PASSWORD']);$this->assertSame('false',$parsed['SCHOOL_DEMO']);$this->assertSame('false',$parsed['APP_DEBUG']);$this->assertSame('true',$parsed['SESSION_SECURE_COOKIE']);$this->assertSame('/cms',$parsed['SESSION_PATH']);$this->assertSame('true',$parsed['SESSION_ENCRYPT']);
    }
    public function test_installer_checks_the_extensions_the_doctor_and_composer_need_and_writes_the_real_version(): void
    {
        $s=new Setup($this->temp());$checks=$s->requirements();
        // every extension the doctor reports as an error, and every ext-* of composer.json, is checked before the install starts
        $this->assertSame(1,preg_match('/\$need = \[([^\]]+)\]/',file_get_contents(app_path('Domain/Cms/DeploymentHealth.php')),$m));
        $need=array_map(fn($x)=>trim($x," '\""),explode(',',$m[1]));$this->assertContains('sodium',$need);
        foreach(array_keys(json_decode(file_get_contents(base_path('composer.json')),true)['require'])as$package)if(str_starts_with($package,'ext-'))$need[]=substr($package,4);
        foreach(array_merge($need,['pdo_mysql','gd'])as$ext)$this->assertArrayHasKey('PHP '.$ext,$checks,$ext);
        file_put_contents($s->root.'/VERSION',"9.8.7-test\n");$this->assertSame('9.8.7-test',$s->version());
        foreach(['installer/Setup.php','installer/Split.php','app/Domain/InstallationContent.php']as$file)$this->assertStringNotContainsString('0.5.0-preview',file_get_contents(base_path($file)),$file);
    }
    public function test_installer_page_announces_only_real_errors_and_offers_the_three_starting_modes():void
    {
        $page=file_get_contents(base_path('public/install/index.php'));
        $this->assertSame(1,substr_count($page,'role="alert"'),'only the error box is an alert; saved passwords and advice are status/note');
        $this->assertStringNotContainsString('INSTALLER LOCKED',$page);$this->assertStringContainsString('aria-current="step"',$page);
        $this->assertStringNotContainsString("\$_POST['school_name']??'โรงเรียน",$page,'the school name is a placeholder, not a prefilled value');
        foreach(['demo','blank','full']as$mode)$this->assertStringContainsString('name="mode" value="'.$mode.'"',$page);
        $this->assertStringContainsString('ห้ามใช้กับโรงเรียนจริง',$page);$this->assertStringNotContainsString('demo-login',str_replace('ไม่มีบัญชี demo-login','',$page));
        foreach(['.notice{','.accounts{']as$rule)$this->assertStringContainsString($rule,file_get_contents(base_path('public/install/setup.css')));
    }
    /** Runs a copy of public/install/index.php in a child PHP with the given $_SERVER values: [status code or 200, HTML]. The copy sits in a folder that already has an .env, so an allowed request sees the locked page and nothing is written. */
    private function installerPage(array $server):array
    {
        $root=$this->temp();mkdir($root.'/public/install',0700,true);mkdir($root.'/installer',0700,true);
        copy(base_path('public/install/index.php'),$root.'/public/install/index.php');foreach(glob(base_path('installer/*.php'))as$file)copy($file,$root.'/installer/'.basename($file));
        file_put_contents($root.'/.env','APP_KEY=existing-install');file_put_contents($root.'/VERSION',"1.2.3\n");
        $runner=$root.'/run.php';
        file_put_contents($runner,'<?php $_SERVER=array_merge($_SERVER,json_decode(base64_decode($argv[1]),true));ob_start();include $argv[2];$html=ob_get_clean();echo (int)http_response_code(),"\n",$html;');
        $server+=['DOCUMENT_ROOT'=>$root.'/public','SCRIPT_NAME'=>'/install/index.php','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'school.example','SERVER_PORT'=>'80'];
        $process=proc_open(array_merge([PHP_BINARY],php_ini_loaded_file()?['-c',php_ini_loaded_file()]:[],[$runner,base64_encode(json_encode($server)),$root.'/public/install/index.php']),[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($process),$err);
        [$code,$html]=explode("\n",$out,2);return[(int)$code?:200,$html];
    }
    public function test_installer_behind_a_tls_proxy_trusts_only_a_nearby_proxy_and_explains_the_other_case():void
    {
        // Cloudflare (or any far proxy) talking plain HTTP to the server: refused, with what to do
        [$code,$html]=$this->installerPage(['REMOTE_ADDR'=>'203.0.113.7','HTTP_X_FORWARDED_PROTO'=>'https','HTTP_X_FORWARDED_FOR'=>'198.51.100.4','HTTP_CF_VISITOR'=>'{"scheme":"https"}']);
        $this->assertSame(403,$code);$this->assertStringContainsString('Full (strict)',$html);$this->assertStringContainsString('TRUSTED_PROXIES',$html);$this->assertStringNotContainsString('พบการติดตั้งหรือไฟล์ตั้งค่าเดิม',$html);
        // direct plain HTTP from the internet: still refused, with the short message
        [$code,$html]=$this->installerPage(['REMOTE_ADDR'=>'203.0.113.7']);
        $this->assertSame(403,$code);$this->assertStringContainsString('ยกเว้นการทดสอบบน localhost',$html);$this->assertStringNotContainsString('Full (strict)',$html);
        // a proxy on the same machine that forwards a plain HTTP visitor is not "localhost"
        [$code]=$this->installerPage(['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'198.51.100.4','HTTP_X_FORWARDED_PROTO'=>'http']);$this->assertSame(403,$code);
        // TLS ended at a proxy on this machine or the private network, direct HTTPS, and a real localhost test are accepted (the page is merely locked here)
        foreach([['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'198.51.100.4','HTTP_X_FORWARDED_PROTO'=>'https'],['REMOTE_ADDR'=>'10.0.0.8','HTTP_X_FORWARDED_PROTO'=>'https'],['REMOTE_ADDR'=>'203.0.113.7','HTTPS'=>'on','SERVER_PORT'=>'443'],['REMOTE_ADDR'=>'127.0.0.1']]as$server){
            [$code,$html]=$this->installerPage($server);$this->assertSame(200,$code,json_encode($server));$this->assertStringContainsString('ตัวติดตั้งถูกล็อก',$html);$this->assertStringNotContainsString('role="alert"',$html);
        }
    }
    public function test_demo_content_hash_survives_a_git_checkout(): void
    {
        // .gitattributes stores text with LF: a CRLF working copy would hash differently from every clone and release archive, and the demo install would refuse it
        $c=file_get_contents(resource_path('install/demo/content.json'));
        $this->assertStringNotContainsString("\r",$c);
        $this->assertSame(json_decode(file_get_contents(resource_path('install/demo/manifest.json')),true)['content_sha256'],hash('sha256',$c));
    }
}
