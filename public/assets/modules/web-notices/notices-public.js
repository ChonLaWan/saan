'use strict';
// web-notices public page for full-name and search-only lists. The page HTML carries no name. After Cloudflare Turnstile passes:
//   1. POST <base>/challenge {cf-turnstile-response}  -> one-time token (2 minutes, this session, this list)
//   2. POST <base>/rows {token}  (list mode)   or   POST <base>/search {token, q}  (search mode)  -> JSON rows
// Rows are written with textContent only (never innerHTML), so a name can never become markup.
(() => {
    const box = document.getElementById('notice-gate');
    if (!box) return;
    const base = box.dataset.base, csrf = box.dataset.csrf, mode = box.dataset.mode, siteKey = box.dataset.sitekey;
    const status = document.getElementById('notice-status'), result = document.getElementById('notice-result');
    const form = document.getElementById('notice-search');
    // List mode loads exactly once per visit: a Turnstile reset() starts a new challenge that passes by itself and would call the callback again, so a
    // reset after a list load would loop (challenge + rows forever) until the visitor's network is rate limited and the table is gone.
    let widget = null, busy = false, pending = null, done = false;

    const say = (t) => { status.textContent = t || ''; };

    async function post(path, body) {
        const res = await fetch(base + '/' + path, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'no-referrer',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify(body),
        });
        const json = await res.json().catch(() => null);
        if (!res.ok) { const e = new Error((json && json.message) || 'ไม่สามารถแสดงรายชื่อได้ในขณะนี้ กรุณาลองใหม่อีกครั้งภายหลัง'); e.status = res.status; throw e; }
        return json;
    }

    function render(data) {
        result.replaceChildren();
        if (!data.rows.length) {
            const p = document.createElement('p'); p.className = 'notice-empty';
            p.textContent = mode === 'search' ? 'ไม่พบรายการที่ตรงกับที่ค้นหา' : 'ไม่มีรายชื่อในประกาศนี้';
            result.appendChild(p); return;
        }
        const table = document.createElement('table'); table.className = 'notice-table';
        const head = table.createTHead().insertRow();
        data.columns.forEach((c) => { const th = document.createElement('th'); th.scope = 'col'; th.textContent = c.label; head.appendChild(th); });
        const body = table.createTBody();
        data.rows.forEach((row) => {
            const tr = body.insertRow();
            data.columns.forEach((c) => { const td = tr.insertCell(); td.setAttribute('data-label', c.label); td.textContent = row[c.key] == null ? '' : String(row[c.key]); });
        });
        result.appendChild(table);
        if (data.more) { const p = document.createElement('p'); p.className = 'notice-hint'; p.textContent = 'พบมากกว่า ' + data.rows.length + ' รายการ กรุณาพิมพ์ให้เจาะจงขึ้น'; result.appendChild(p); }
        if (mode === 'list') { const p = document.createElement('p'); p.className = 'notice-hint'; p.textContent = data.rows.length + ' รายการ'; result.appendChild(p); }
    }

    function resetWidget() { widget !== null && window.turnstile && window.turnstile.reset(widget); }

    // List mode, after a failure: only the visitor's own click may try again (never automatically).
    function offerRetry() {
        const b = document.createElement('button'); b.type = 'button'; b.className = 'notice-retry'; b.textContent = 'โหลดอีกครั้ง';
        b.addEventListener('click', () => { result.replaceChildren(); say('กำลังตรวจสอบ...'); done = false; resetWidget(); });
        result.appendChild(b);
    }

    async function run(turnstileToken, q) {
        if (busy) return; busy = true;
        say('กำลังตรวจสอบ...');
        try {
            const t = await post('challenge', {'cf-turnstile-response': turnstileToken});
            const data = mode === 'search' ? await post('search', {token: t.token, q}) : await post('rows', {token: t.token});
            say(''); render(data);
        } catch (e) {
            result.replaceChildren(); say(e.message);
            if (mode === 'list') offerRetry();
        } finally {
            busy = false; pending = null;
            if (mode === 'list') done = true; // success or failure: no automatic second run
            else resetWidget(); // search mode: every search needs a fresh Turnstile token
        }
    }

    window.saanNoticeTurnstileReady = () => {
        const slot = document.getElementById('notice-turnstile');
        widget = window.turnstile.render(slot, {
            sitekey: siteKey, language: 'th',
            callback: (token) => {
                if (mode === 'list') { if (done || busy) return; return run(token, null); }
                pending = token; // search mode: the token waits for the visitor's query
                say('พร้อมค้นหา');
            },
            'expired-callback': () => { pending = null; },
            'error-callback': () => { say('ไม่สามารถตรวจสอบได้ กรุณาโหลดหน้าใหม่'); },
        });
    };
    // Load Turnstile only now, after the ready callback exists (explicit render).
    const s = document.createElement('script');
    s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=saanNoticeTurnstileReady';
    s.async = true; s.defer = true;
    document.head.appendChild(s);

    if (form) {
        form.addEventListener('submit', (ev) => {
            ev.preventDefault();
            const q = form.elements.q.value;
            if (q.trim() === '') { say('กรุณาพิมพ์ชื่อหรือเลขที่ที่ต้องการค้นหา'); return; }
            if (!pending) { say('กรุณายืนยันว่าเป็นผู้ใช้จริงก่อนค้นหา'); return; }
            run(pending, q);
        });
    }
})();
