'use strict';
window.SchoolCompact={
    theme(){
        const form=document.querySelector('#theme-form');if(!form)return;
        const cards=[...form.querySelectorAll('.cms-control-card')].filter(card=>!card.hidden&&!card.classList.contains('compact-accordion'));
        cards.forEach((card,index)=>{
            const heading=card.querySelector('h2');if(!heading)return;
            const details=document.createElement('details'),summary=document.createElement('summary'),body=document.createElement('div');
            details.className=card.className+' compact-accordion';details.open=index===0;summary.append(heading);body.className='compact-panel-body';
            while(card.firstChild)body.append(card.firstChild);details.append(summary,body);card.replaceWith(details);
            details.addEventListener('toggle',()=>{if(details.open)form.querySelectorAll('.compact-accordion').forEach(other=>{if(other!==details)other.open=false;});});
        });
        if(!form.dataset.compactValidation){form.dataset.compactValidation='true';form.addEventListener('invalid',event=>{const details=event.target.closest('.compact-accordion');if(details)details.open=true;},true);}
    },
    media(){
        document.querySelectorAll('.desk-media-grid>article').forEach(card=>{
            if(card.dataset.compactMedia)return;const alt=card.querySelector('[data-file-alt]'),folder=card.querySelector('[data-file-folder]'),footer=card.querySelector('footer');
            if(!alt||!folder||!footer)return;card.dataset.compactMedia='true';
            const details=document.createElement('details'),summary=document.createElement('summary'),body=document.createElement('div');details.className='compact-media-fields';summary.textContent='แก้คำอธิบาย / โฟลเดอร์';body.append(alt.closest('label'),folder.closest('label'));
            const save=card.querySelector('[data-file-save]');if(save)body.append(save);details.append(summary,body);footer.before(details);
        });
    },
    enhance(page){if(page==='theme')this.theme();if(page==='media')this.media();}
};
