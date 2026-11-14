'use strict';
(() => {
    const search = document.querySelector('#plan-search');
    const status = document.querySelector('#plan-status');
    const modules = [...document.querySelectorAll('[data-plan-module]')];
    function filter() {
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0, sections = 0;
        for (const module of modules) {
            const heading = module.querySelector('h2').textContent.toLocaleLowerCase();
            let count = 0;
            for (const item of module.querySelectorAll('[data-plan-status]')) {
                const matches = (!query || heading.includes(query) || item.textContent.toLocaleLowerCase().includes(query))
                    && (status.value === 'all' || item.dataset.planStatus === status.value);
                item.hidden = !matches;
                if (matches) count++;
            }
            module.hidden = count === 0;
            if (count) sections++;
            visible += count;
        }
        document.querySelector('#plan-results').textContent = `แสดง ${visible} รายการ ใน ${sections} หมวดระบบ`;
    }
    search.addEventListener('input', filter);
    status.addEventListener('change', filter);
    document.querySelector('#plan-print').addEventListener('click', () => window.print());
    window.addEventListener('hashchange', () => {
        const module = document.getElementById(location.hash.slice(1));
        if (module?.matches('[data-plan-module]') && module.hidden) { search.value = ''; status.value = 'all'; filter(); module.scrollIntoView(); }
    });
    filter();
})();
