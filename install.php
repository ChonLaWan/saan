<?php
// Optional CLI preparation. The web wizard also creates the ownership token for File Manager users.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/installer/Setup.php';
$setup=new SchoolAtelier\Installer\Setup(__DIR__);
try{$setup->prepare();foreach($setup->requirements()as$name=>$ok)echo($ok?'[OK] ':'[WARN] ').$name.PHP_EOL;echo '[OK] Read storage/app/private/setup/owner-token.txt privately; open /install/ in your browser.'.PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,'[ERR] '.$e->getMessage().PHP_EOL);exit(1);}
