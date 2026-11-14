<?php

// Developer utility. Runtime rendering does not require Node or an asset build.
$root=dirname(__DIR__);
$paths=json_decode(file_get_contents($root.'/public/assets/ui-icons.json'),true,512,JSON_THROW_ON_ERROR);
foreach($paths as$name=>$path){
    if(!preg_match('/^[a-z]+(?:-[a-z]+)*$/D',$name)||!is_string($path)||!preg_match('/^[MmLlHhVvCcSsQqTtAaZz0-9., +\-]+$/D',$path)){
        throw new RuntimeException('Invalid icon name or SVG path');
    }
}
$template=<<<'JS'
'use strict';
// Generated from ui-icons.json; PHP and browser screens share these exact paths.
(() => {
    const paths=Object.freeze(PATHS);
    window.SchoolIcons=Object.freeze({render(name='arrow-right'){
        const key=Object.hasOwn(paths,name)?name:'arrow-right';
        return `<svg class="ui-icon ui-icon--${key}" data-ui-icon="${key}" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="${paths[key]}"/></svg>`;
    }});
})();
JS;
$javascript=str_replace('PATHS',json_encode($paths,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$template)."\n";
if(file_put_contents($root.'/public/assets/ui-icons.js',$javascript)===false)throw new RuntimeException('Cannot write generated icon JavaScript');
echo '[OK] Built '.count($paths).' shared SVG icons'.PHP_EOL;
