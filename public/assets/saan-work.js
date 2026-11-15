'use strict';
// Staff work shell router: window.SaanWork. Pages register by key and render into #work-view.
(() => {
    const pages = new Map();
    let epoch = 0, controller = null, now = {key: '', parts: []}, shownHash = location.hash;
    const guards = new Set(); // navigation veto hooks: a function returning false keeps the current page (unsaved work)
    const view = () => document.querySelector('#work-view');
    const esc = v => window.SaanCore.esc(v);
    const KEY = /^[a-z0-9][a-z0-9-]*$/;

    function parse(hash) {
        const raw = String(hash || '').replace(/^#/, '');
        const segs = raw.split('/').filter(Boolean).map(s => { try { return decodeURIComponent(s); } catch { return s; } });
        return {key: segs[0] || '', parts: segs.slice(1)};
    }

    function menu() {
        try { return JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').menu || []; } catch { return []; }
    }

    // the strip of module links scrolls inside itself: mark which side still has links beyond the edge (CSS fades that side)
    let stripWired = false;
    function stripEdges() {
        const t = document.querySelector('.work-tabs-track');
        if (!t) return;
        t.toggleAttribute('data-more-start', t.scrollLeft > 4);
        t.toggleAttribute('data-more-end', t.scrollLeft + t.clientWidth < t.scrollWidth - 4);
    }

        // bring the current link into the visible part of the strip (the page itself is not scrolled)
    function centerCurrent() {
        const track = document.querySelector('.work-tabs-track');
        const cur = track?.querySelector('[aria-current="page"]');
        if (cur) track.scrollLeft += cur.getBoundingClientRect().left - track.getBoundingClientRect().left - (track.clientWidth - cur.offsetWidth) / 2;
        stripEdges();
    }

    function markCurrent(key) {
        document.querySelectorAll('.work-tabs a').forEach(a => {
            if (a.dataset.workKey === key) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        const track = document.querySelector('.work-tabs-track');
        if (!track) return;
        if (!stripWired) {
            stripWired = true;
            track.addEventListener('scroll', stripEdges, {passive: true});
            window.addEventListener('resize', stripEdges, {passive: true});
            // the web font changes the widths of the links after the first layout: centre again once it is there
            if (document.fonts?.ready) document.fonts.ready.then(centerCurrent);
            window.addEventListener('load', centerCurrent, {once: true});
        }
        centerCurrent();
    }

    // central service links (settings of the payslip module, https only): label + URL pairs rendered under the work home as "บริการบุคลากร"
    function services() {
        try {
            return (JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').services || [])
                .filter(s => s && typeof s.url === 'string' && /^https:\/\//i.test(s.url) && typeof s.label === 'string');
        } catch { return []; }
    }

    function home(root) {
        const items = menu();
        if (!items.length) return; // keep the server-rendered empty state
        const svc = services();
        const icon = name => window.SchoolIcons ? window.SchoolIcons.render(name) : '';
        let areas = {};
        try { areas = JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').areas || {}; } catch { areas = {}; }
        // tiles grouped by area of school work, in the order the server lists the areas; a module without an area lands in "system"
        const order = Object.keys(areas);
        const groups = order.map(id => ({id, label: areas[id], items: items.filter(i => (order.includes(i.area) ? i.area : order[order.length - 1]) === id)})).filter(g => g.items.length);
        root.innerHTML = '<section class="work-home glass"><div class="sg-pagehead"><div><h1>งานโรงเรียน</h1><p>เลือกงานที่ต้องการ จัดกลุ่มตามหน้าที่ในโรงเรียน</p></div></div>'
            + groups.map(g => `<section class="work-area" aria-labelledby="work-area-${esc(g.id)}"><h2 id="work-area-${esc(g.id)}">${esc(g.label)} <small>${g.items.length}</small></h2><ul>`
                + g.items.map(i => `<li><a href="#${esc(i.path.slice(6))}"><span class="sg-ico" aria-hidden="true">${icon(i.glyph || i.icon)}</span><span>${esc(i.label)}</span></a></li>`).join('') + '</ul></section>').join('')
            + '</section>'
            + (svc.length ? '<section class="work-home glass work-services" aria-labelledby="work-services-h"><h2 id="work-services-h">บริการบุคลากร</h2><ul>'
                + svc.map(s => `<li><a href="${esc(s.url)}" target="_blank" rel="noopener noreferrer"><span class="sg-ico" aria-hidden="true">${icon('external')}</span><span>${esc(s.label)}</span></a></li>`).join('') + '</ul></section>' : '');
    }
function showError(root, text) {
        root.innerHTML = `<section class="work-error glass" role="alert">${esc(text)}</section>`;
    }

    async function navigate() {
        const root = view();
        if (!root) return;
        const route = parse(location.hash);
        now = route;
        shownHash = location.hash;
        const mine = ++epoch;
        if (controller) controller.abort();
        const ctl = controller = new AbortController();
        const signal = ctl.signal;
        const alive = () => epoch === mine && !signal.aborted;
        markCurrent(route.key);
        root.setAttribute('aria-busy', 'true');
        try {
            if (!route.key) { home(root); return; }
            const page = pages.get(route.key);
            if (!page) { showError(root, 'ไม่พบหน้างานนี้'); return; }
            if (page.title) document.title = page.title + ' | ' + (document.title.split(' | ').pop() || '');
            const api = (url, opts = {}) => window.SaanCore.api(url, {...opts, signal});
            root.replaceChildren();
            await page.render(root, {key: route.key, parts: route.parts, signal, alive, api});
        } catch (e) {
            if (e?.name === 'AbortError' || !alive()) return;
            showError(root, e?.message || 'เกิดข้อผิดพลาด');
        } finally {
            if (epoch === mine) root.setAttribute('aria-busy', 'false');
        }
    }

    window.SaanWork = Object.freeze({
        register(key, def) {
            if (!KEY.test(String(key)) || !def || typeof def.render !== 'function') throw new Error('SaanWork.register: key หรือ render ไม่ถูกต้อง');
            pages.set(key, {title: String(def.title || ''), render: def.render});
            // late registration of the page already requested: render it now
            if (key === now.key && view()?.querySelector('.work-error')) navigate();
        },
        /** Register a veto hook while a page holds unsaved work; it returns false to stay. Returns the remover. */
        guard(fn) { guards.add(fn); return () => guards.delete(fn); },
        go(hash) { const h = '#' + String(hash || '').replace(/^#/, ''); if (location.hash === h) navigate(); else location.hash = h; },
        current() { return {key: now.key, parts: [...now.parts]}; },
    });

    window.addEventListener('hashchange', () => {
        // Back button, Alt+Left or any link that changes the hash directly: a page with unsaved work may veto
        if (location.hash !== shownHash && [...guards].some(g => { try { return g() === false; } catch { return false; } })) {
            history.replaceState(history.state, '', location.pathname + location.search + shownHash); // restore without firing hashchange
            return;
        }
        navigate();
    });
    // deferred scripts run while readyState is 'interactive'; DOMContentLoaded fires after all of them, so pages are registered by then
    if (document.readyState === 'complete') navigate(); else document.addEventListener('DOMContentLoaded', navigate, {once: true});
})();