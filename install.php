<?php
// Optional CLI preparation. The web wizard also creates the ownership token for File Manager users.
//   php install.php                         prepare and report the requirements, then open /install/ in a browser
//   php install.php --config=<file.json>    install with no browser (container image, scripted servers): same installer classes and checks
//                                           as the web wizard; prints a JSON result that holds the generated database passwords
// Config: {"layout":"standard","db_admin":{db_host,db_port,db_user,db_password},"public_root":"/abs/path","public_url":"https://...",
//          "site":{school_name,admin_name,admin_email,password,password_confirmation,app_url,mode,public_email,phone,address}}
//      or {"layout":"demo","database":{db_host,db_port,db_name,db_user,db_password},"site":{...}}   (combined system, trial only)
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/installer/Setup.php';
require __DIR__.'/installer/Split.php';
use SchoolAtelier\Installer\{Setup,Split,Identity};
$setup=new Setup(__DIR__);
$config=null;foreach(array_slice($argv,1)as$a)if(str_starts_with($a,'--config='))$config=substr($a,9);
try{
    if($config===null){
        $setup->prepare();foreach($setup->requirements()as$name=>$ok)echo($ok?'[OK] ':'[WARN] ').$name.PHP_EOL;echo '[OK] Read storage/app/private/setup/owner-token.txt privately; open /install/ in your browser.'.PHP_EOL;
        exit(0);
    }
    if($setup->installed()||$setup->legacy()){fwrite(STDERR,'[OK] already installed, nothing changed'.PHP_EOL);echo json_encode(['installed'=>true,'already'=>true]).PHP_EOL;exit(0);}
    $raw=@file_get_contents($config);if($raw===false)throw new RuntimeException('cannot read the config file');
    $c=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($c)||!is_array($c['site']??null))throw new RuntimeException('config needs a "site" object');
    $missing=array_keys(array_filter($setup->requirements(),fn($ok)=>!$ok));if($missing)throw new RuntimeException('requirements not met: '.implode(', ',$missing));
    $setup->prepare();
    $layout=$c['layout']??'standard';
    if($layout==='standard'){
        $admin=Split::validateAdmin(is_array($c['db_admin']??null)?$c['db_admin']:[]);if($admin['db_user']==='')throw new RuntimeException('db_admin.db_user is required: the scripted install creates both databases itself');
        $publicUrl=trim((string)($c['public_url']??''));[,$publicReal]=Split::validateRoots(__DIR__,(string)($c['public_root']??''));Setup::validatePublicSite(['app_url'=>$publicUrl,'school_name'=>'x']);
        $site=Setup::validateSite($c['site']);
        $split=new Split($setup);$p=$split->provision($admin,array_filter([Identity::idAt($publicReal)]));
        if(!$p['auto'])throw new RuntimeException($p['note']!==''?$p['note']:'the databases could not be created');
        $bad=$split->verify($p['creds']);if($bad)throw new RuntimeException(implode(' | ',$bad));
        $result=$split->install(['id'=>$p['id'],'public_root'=>$publicReal,'public_url'=>rtrim($publicUrl,'/'),'creds'=>$p['creds']],$site);
        $out=['installed'=>true,'layout'=>'standard','names'=>$p['names'],'creds'=>$p['creds'],'result'=>$result];
    }elseif($layout==='demo'){
        $db=Setup::validateDatabase(is_array($c['database']??null)?$c['database']:[]);$setup->checkDatabase($db);
        $result=$setup->install($db,Setup::validateSite($c['site']),['layout'=>'demo']);
        $out=['installed'=>true,'layout'=>'demo','result'=>$result];
    }else throw new RuntimeException('layout must be "standard" or "demo"');
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'[ERR] '.$e->getMessage().PHP_EOL);exit(1);}
