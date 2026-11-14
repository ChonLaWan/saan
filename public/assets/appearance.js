'use strict';
(() => {
    const modes=['light','dark','system','light-high','dark-high'];
    const key='school-appearance:'+(document.querySelector('meta[name=school-base]')?.content||'/');
    const system=matchMedia('(prefers-color-scheme: dark)'),contrast=matchMedia('(prefers-contrast: more)');
    let selected=document.querySelector('meta[name=school-color-mode]')?.content||'light';if(!modes.includes(selected))selected='light';
    try{const saved=localStorage.getItem(key);if(modes.includes(saved))selected=saved;}catch{}
    const apply=()=>{const dark=selected==='dark'||selected==='dark-high'||selected==='system'&&system.matches;document.documentElement.dataset.appearance=dark?'dark':'light';document.documentElement.dataset.contrast=selected.endsWith('-high')||selected==='system'&&contrast.matches?'high':'normal';document.documentElement.style.colorScheme=dark?'dark':'light';document.querySelectorAll('[data-appearance-choice]').forEach(select=>select.value=selected);};
    apply();system.addEventListener('change',apply);contrast.addEventListener('change',apply);
    window.addEventListener('storage',event=>{if(event.key===key&&modes.includes(event.newValue)){selected=event.newValue;apply();}});
    document.addEventListener('DOMContentLoaded',()=>{const host=document.querySelector('.site-header')||document.querySelector('.cms-top-links')||document.querySelector('.account-topbar')||document.querySelector('.account-top')||document.querySelector('.top-actions');if(!host||host.dataset.showMode==='false')return;
        let control=host.querySelector('[data-appearance-choice]')?.closest('.appearance-control');if(!control){control=document.createElement('label');control.className='appearance-control';control.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 1v3M12 20v3M1 12h3M20 12h3M4 4l2 2M18 18l2 2M4 20l2-2M18 6l2-2"/></svg><select data-appearance-choice aria-label="โหมดสี"><option value="light">สว่าง</option><option value="dark">มืด</option><option value="system">ตามระบบ</option><option value="light-high">ชัดสูง</option><option value="dark-high">มืดชัด</option></select>';host.append(control);}
        control.title='โหมดสีและคอนทราสต์: ชัดสูง / มืดชัด เพิ่มความชัดและขนาดตัวอักษร';control.querySelector('select').value=selected;control.querySelector('select').onchange=e=>{selected=e.target.value;try{localStorage.setItem(key,selected);}catch{}apply();document.dispatchEvent(new CustomEvent('school:appearance',{detail:selected}));};
    });
})();
