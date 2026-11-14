'use strict';
(() => {
    const toggle=document.querySelector('.site-menu-toggle'),menu=document.querySelector('#public-navigation');
    const compactNavigation=matchMedia('(max-width:1100px)');
    const closeNavigation=()=>{if(!toggle||!menu)return;toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','เปิดเมนูเว็บไซต์');menu.classList.remove('open');};
    const fitNavigation=()=>{if(menu&&compactNavigation.matches){const bottom=toggle.closest('header').getBoundingClientRect().bottom;menu.style.setProperty('--navigation-space',Math.max(100,innerHeight-bottom-8)+'px');}};
    toggle?.addEventListener('click',()=>{const open=toggle.getAttribute('aria-expanded')!=='true';toggle.setAttribute('aria-expanded',String(open));toggle.setAttribute('aria-label',open?'ปิดเมนูเว็บไซต์':'เปิดเมนูเว็บไซต์');menu.classList.toggle('open',open);if(open)fitNavigation();});
    compactNavigation.addEventListener('change',()=>{const wasFocused=menu?.contains(document.activeElement);closeNavigation();document.querySelectorAll('.site-dropdown[open]').forEach(d=>d.open=false);if(compactNavigation.matches&&wasFocused)toggle.focus();});
    window.addEventListener('resize',fitNavigation);
    menu?.addEventListener('click',e=>{if(compactNavigation.matches&&e.target.closest('a'))closeNavigation();});
    document.addEventListener('click',e=>{if(compactNavigation.matches&&toggle?.getAttribute('aria-expanded')==='true'&&!toggle.closest('header').contains(e.target))closeNavigation();});
    document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.site-dropdown[open]').forEach(d=>d.open=false);if(toggle?.getAttribute('aria-expanded')==='true'){toggle.click();toggle.focus();}}});
    document.querySelectorAll('.site-dropdown').forEach(details=>details.addEventListener('toggle',()=>{if(details.open)document.querySelectorAll('.site-dropdown').forEach(other=>{if(other!==details)other.open=false;});}));
    const slides=[...document.querySelectorAll('[data-banner-slide]')];let slideIndex=0;
    const showSlide=delta=>{slideIndex=(slideIndex+delta+slides.length)%slides.length;slides.forEach((slide,index)=>slide.hidden=index!==slideIndex);const counter=document.querySelector('[data-banner-counter]');if(counter)counter.textContent=(slideIndex+1)+' / '+slides.length;};
    document.querySelector('[data-banner-previous]')?.addEventListener('click',()=>showSlide(-1));document.querySelector('[data-banner-next]')?.addEventListener('click',()=>showSlide(1));
    const popup=document.querySelector('[data-school-popup]');if(popup){const key='school-popup:'+(document.querySelector('meta[name=school-base]')?.content||location.origin)+':'+popup.dataset.schoolPopup;let seen=false;try{seen=sessionStorage.getItem(key)==='seen';}catch{}if(!seen&&!document.querySelector('[data-observance]')){popup.showModal();try{sessionStorage.setItem(key,'seen');}catch{}}popup.querySelector('[data-close-popup]').onclick=()=>popup.close();}
    document.querySelectorAll('[data-play-video]').forEach(button=>button.onclick=()=>{const host=button.closest('[data-video-embed]'),frame=document.createElement('iframe');frame.src=host.dataset.videoEmbed;frame.title='วีดิทัศน์โรงเรียน';frame.allow='fullscreen; picture-in-picture';frame.allowFullscreen=true;frame.referrerPolicy='strict-origin-when-cross-origin';host.replaceChildren(frame);});
    const motion=matchMedia('(prefers-reduced-motion: reduce)').matches;
    if(!motion&&'IntersectionObserver'in window){const observer=new IntersectionObserver(entries=>{for(const entry of entries)if(entry.isIntersecting){entry.target.classList.add('entered');observer.unobserve(entry.target);}},{threshold:.06});document.querySelectorAll('.school-services,.school-news,.school-notices,.school-albums,.school-events').forEach(el=>{el.classList.add('will-enter');observer.observe(el);});}
})();
