'use strict';
(() => {
    const sidebar = document.querySelector('#school-navigation');
    if (!sidebar) return;
    const toggle = document.querySelector('.school-menu-toggle');
    const closeButton = sidebar.querySelector('.school-menu-close');
    const overlay = document.querySelector('.school-nav-overlay');
    const mobile = window.matchMedia('(max-width: 767px)'); // drawer
    const rail = window.matchMedia('(min-width: 768px) and (max-width: 1024px)'); // icon rail that opens as a labelled panel
    const compact = () => mobile.matches || rail.matches;
    const main = document.querySelector('.cms-shell, .workspace, .account-shell');
    let previousFocus = null;

    function close(restoreFocus = true) {
        document.body.classList.remove('school-nav-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'เปิดเมนูหลัก');
        overlay.hidden = true;
        sidebar.inert = mobile.matches;
        sidebar.removeAttribute('role');
        sidebar.removeAttribute('aria-modal');
        if (main) main.inert = false;
        if (restoreFocus && compact() && previousFocus?.isConnected) previousFocus.focus();
    }

    function open() {
        previousFocus = document.activeElement;
        sidebar.inert = false;
        document.body.classList.add('school-nav-open');
        sidebar.setAttribute('role', 'dialog');
        sidebar.setAttribute('aria-modal', 'true');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'ปิดเมนูหลัก');
        overlay.hidden = false;
        if (main) main.inert = true;
        (closeButton.getClientRects().length ? closeButton : sidebar.querySelector('a, button')).focus();
    }

    function highlight() {
        const current = location.pathname.replace(/\/$/, '');
        const defaultHash = current.endsWith('/cms') ? '#home' : current.endsWith('/operations') ? '#overview' : '';
        let hash = location.hash || defaultHash;
        if (current.endsWith('/cms') && hash.startsWith('#compose/')) hash = '#compose';
        const links = [...sidebar.querySelectorAll('.school-nav-link')];
        const exact = links.find(link => {
            const target = new URL(link.href);
            return target.pathname.replace(/\/$/, '') === current && target.hash === hash;
        });
        for (const link of links) {
            link.removeAttribute('aria-current');
            if (link === exact) {
                link.setAttribute('aria-current', 'page');
                const section = link.closest('details');
                if (section) section.open = true;
            }
        }
    }

    toggle.addEventListener('click', () => document.body.classList.contains('school-nav-open') ? close() : open());
    closeButton.addEventListener('click', () => close());
    overlay.addEventListener('click', () => close());
    sidebar.addEventListener('click', event => { if (event.target.closest('a') && compact()) close(false); });
    document.addEventListener('keydown', event => {
        if (!document.body.classList.contains('school-nav-open')) return;
        if (event.key === 'Escape') { event.preventDefault(); close(); }
        if (event.key === 'Tab') {
            const focusable = [...sidebar.querySelectorAll('a, button, summary')].filter(item => item.getClientRects().length);
            const first = focusable[0], last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    // the rail shows every link as an icon: closed groups would hide theirs, so they are opened while the rail is on screen
    const openGroups = () => { if (rail.matches) sidebar.querySelectorAll('details.school-nav-group').forEach(group => { group.open = true; }); };
    const onChange = () => {
        const focusedInside = sidebar.contains(document.activeElement);
        close(false);
        openGroups();
        if (compact() && focusedInside) toggle.focus();
    };
    mobile.addEventListener('change', onChange);
    rail.addEventListener('change', onChange);
    window.addEventListener('hashchange', highlight);
    close(false);
    openGroups();
    highlight();

    // Health banner for admins on /cms and /work: the cron heartbeat is older than 10 minutes, or a check is at error level.
    // Same endpoint as the /cms#deployment page; only admins have the "deployment" menu link (the endpoint is admin-only).
    const deploymentLink = sidebar.querySelector('[data-nav-key="deployment"]');
    const host = document.querySelector('main');
    if (deploymentLink && host && window.fetch) {
        const target = new URL(deploymentLink.href);
        const api = target.pathname.replace(/\/cms\/?$/, '') + '/api/site/deployment';
        fetch(api, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => response.ok ? response.json() : null)
            .then(report => {
                if (!report || !Array.isArray(report.checks)) return;
                const stale = report.checks.some(check => check.key === 'heartbeat' && !check.ok);
                const errors = report.checks.filter(check => check.level === 'err').length;
                if (!stale && !errors) return;
                const banner = document.createElement('div');
                banner.className = 'school-health-banner';
                banner.setAttribute('role', 'alert');
                const parts = [];
                if (stale) parts.push('cron (schedule:run) ไม่ทำงานเกิน 10 นาที งานเผยแพร่ตามเวลา การส่งข้อมูลไปหน้าบ้าน และการสำรองอัตโนมัติจะหยุด');
                if (errors) parts.push('มี ' + errors + ' รายการตรวจที่ผิดพลาด');
                banner.append(document.createTextNode(parts.join(' · ') + ' '));
                const link = document.createElement('a');
                link.href = deploymentLink.href;
                link.textContent = 'ดูหน้าตรวจความพร้อมระบบ';
                banner.append(link);
                if (window.SchoolIcons) banner.insertAdjacentHTML('afterbegin', window.SchoolIcons.render('alert'));
                host.parentNode.insertBefore(banner, host);
            })
            .catch(() => {});
    }
})();
