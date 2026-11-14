'use strict';
(() => {
    const query=document.querySelector('#coverage-query'),kind=document.querySelector('#coverage-kind');
    const tabs=[...document.querySelectorAll('[data-coverage-tab]')],panels=[...document.querySelectorAll('.coverage-group')];
    const states=new Map();
    for(const panel of panels){
        const rows=[...panel.querySelectorAll('tbody>tr')],bar=document.createElement('div');
        bar.className='coverage-pagination';bar.innerHTML='<button type="button" data-previous>ก่อนหน้า</button><span role="status"></span><button type="button" data-next>ถัดไป</button>';
        panel.append(bar);states.set(panel.id,{page:1,rows,bar});
        bar.querySelector('[data-previous]').onclick=()=>{states.get(panel.id).page--;paint(panel);};
        bar.querySelector('[data-next]').onclick=()=>{states.get(panel.id).page++;paint(panel);};
    }
    function paint(panel){
        const state=states.get(panel.id),isNavigation=panel.id==='coverage-navigation',q=isNavigation?query.value.trim().toLocaleLowerCase('th-TH-u-nu-latn'):'';
        const matches=state.rows.filter(row=>(!q||row.textContent.toLocaleLowerCase('th-TH-u-nu-latn').includes(q))&&(!isNavigation||kind.value==='all'||row.dataset.kind===kind.value));
        const pages=Math.max(1,Math.ceil(matches.length/12));state.page=Math.min(Math.max(state.page,1),pages);
        const visible=new Set(matches.slice((state.page-1)*12,state.page*12));state.rows.forEach(row=>row.hidden=!visible.has(row));
        state.bar.querySelector('span').textContent=`หน้า ${state.page} / ${pages} · ${matches.length} รายการ`;
        state.bar.querySelector('[data-previous]').disabled=state.page===1;state.bar.querySelector('[data-next]').disabled=state.page===pages;state.bar.hidden=pages<2;
        if(isNavigation)document.querySelector('#coverage-results').textContent=`พบ ${matches.length} จาก ${state.rows.length} เมนู`;
    }
    function activate(id,focus=false){
        if(!states.has(id))id='coverage-content';
        tabs.forEach(tab=>{const selected=tab.dataset.coverageTab===id;tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;if(selected&&focus)tab.focus();});
        panels.forEach(panel=>{panel.hidden=panel.id!==id;});paint(document.querySelector('#'+id));
    }
    tabs.forEach((tab,index)=>{tab.onclick=()=>{history.replaceState(null,'','#'+tab.dataset.coverageTab);activate(tab.dataset.coverageTab);};tab.onkeydown=event=>{let next=index;if(event.key==='ArrowRight')next=(index+1)%tabs.length;else if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else return;event.preventDefault();activate(tabs[next].dataset.coverageTab,true);history.replaceState(null,'','#'+tabs[next].dataset.coverageTab);};});
    const filter=()=>{states.get('coverage-navigation').page=1;paint(document.querySelector('#coverage-navigation'));};query.addEventListener('input',filter);kind.addEventListener('change',filter);
    activate(location.hash.slice(1));window.addEventListener('hashchange',()=>activate(location.hash.slice(1)));
})();
