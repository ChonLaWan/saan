'use strict';
(() => {
    const dialog=document.querySelector('[data-observance]');if(!dialog)return;const identity=dialog.dataset.observance,key='school-notice:'+(document.querySelector('meta[name=school-base]')?.content||location.origin)+':'+identity,frequency=dialog.dataset.frequency,day=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Bangkok',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    if(dialog.dataset.observanceMode==='mourning'&&dialog.dataset.grayscale==='true')document.documentElement.dataset.schoolMourning='true';
    let dismissed=false;try{dismissed=frequency==='session'?sessionStorage.getItem(key)==='closed':frequency==='day'?localStorage.getItem(key)===day:false;}catch{}
    if(dialog.dataset.observancePreview==='true')dismissed=false;
    const save=()=>{if(dialog.dataset.observancePreview==='true')return;try{if(frequency==='session')sessionStorage.setItem(key,'closed');else if(frequency==='day')localStorage.setItem(key,day);}catch{}document.dispatchEvent(new Event('school:notice-closed'));};
    dialog.querySelectorAll('[data-close-observance]').forEach(button=>button.onclick=()=>dialog.close());dialog.addEventListener('close',save);document.querySelectorAll('[data-observance-open]').forEach(button=>button.onclick=()=>{if(!dialog.open)dialog.showModal();});if(!dismissed&&dialog.dataset.observanceAutoshow!=='false')dialog.showModal();
})();
