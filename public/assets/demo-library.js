'use strict';
(() => {
    const query=document.querySelector('#mock-library-query'),cards=[...document.querySelectorAll('[data-mock-card]')];let page=1;
    const previous=document.querySelector('#mock-library-prev'),next=document.querySelector('#mock-library-next');
    function paint(){const matching=cards.filter(card=>card.dataset.search.toLocaleLowerCase('th').includes(query.value.trim().toLocaleLowerCase('th'))),pages=Math.max(1,Math.ceil(matching.length/6));page=Math.min(page,pages);cards.forEach(card=>{card.hidden=true;});matching.slice((page-1)*6,page*6).forEach(card=>{card.hidden=false;});previous.disabled=page===1;next.disabled=page===pages;document.querySelector('#mock-library-page').textContent=`หน้า ${page} / ${pages}`;document.querySelector('#mock-library-count').textContent=`พบ ${matching.length} โมดูล แสดงครั้งละ 6 โมดูล`;} 
    query.addEventListener('input',()=>{page=1;paint();});previous.addEventListener('click',()=>{page--;paint();});next.addEventListener('click',()=>{page++;paint();});paint();
})();
