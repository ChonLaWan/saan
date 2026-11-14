'use strict';
// R0-05: shared browser helpers for every Saan screen. Loaded first (not deferred) by x-appearance-assets.
(() => {
    const meta = name => document.querySelector(`meta[name="${name}"]`)?.content || '';

    // JSON fetch with CSRF + school base path. Errors carry .status, .data and (409) .current.
    async function api(url, {method = 'GET', body, signal, idempotencyKey} = {}) {
        const base = meta('school-base').replace(/\/+$/, '');
        const target = /^https?:\/\//i.test(url) ? url : base + (url.startsWith('/') ? '' : '/') + url;
        const headers = {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'};
        const sameOrigin = new URL(target, location.href).origin === location.origin;
        const csrf = sameOrigin ? meta('csrf-token') : '';
        if (csrf) headers['X-CSRF-TOKEN'] = csrf;
        if (idempotencyKey && sameOrigin) headers['Idempotency-Key'] = String(idempotencyKey).slice(0, 80);
        const init = {method: method.toUpperCase(), credentials: 'same-origin', headers, signal};
        if (body !== undefined && init.method !== 'GET' && init.method !== 'HEAD') {
            if (body instanceof FormData) init.body = body;
            else { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
        }
        let res;
        try { res = await fetch(target, init); }
        catch (e) { if (e?.name === 'AbortError') throw e; throw new Error('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'); }
        let data = null;
        if (res.status !== 204) { const text = await res.text(); try { data = text ? JSON.parse(text) : null; } catch { data = null; } }
        if (res.ok) return data;
        let message = data?.message;
        if (data?.errors) message = Object.values(data.errors).flat()[0] || message;
        if (!message) message = res.status === 419 ? 'หน้าหมดอายุ กรุณาโหลดใหม่' : res.status === 403 ? 'ไม่มีสิทธิ์ใช้งานส่วนนี้' : 'เกิดข้อผิดพลาด (' + res.status + ')';
        const err = new Error(message);
        err.status = res.status;
        err.data = data;
        if (res.status === 409) err.current = data?.current ?? null;
        throw err;
    }

    // Thai date (Buddhist year, Latin digits). Date-only strings are read as local calendar days.
    function date(iso, options = {day: 'numeric', month: 'long', year: 'numeric'}) {
        if (!iso) return '';
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso));
        const d = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(iso);
        if (Number.isNaN(d.getTime())) return '';
        return new Intl.DateTimeFormat('th-TH-u-nu-latn', {timeZone: m ? undefined : 'Asia/Bangkok', ...options}).format(d);
    }

    function uid() {
        if (globalThis.crypto?.randomUUID) return crypto.randomUUID();
        const b = new Uint8Array(16);
        if (globalThis.crypto?.getRandomValues) crypto.getRandomValues(b); else for (let i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
        return [...b].map(x => x.toString(16).padStart(2, '0')).join('');
    }

    window.SaanCore = Object.freeze({
        esc(value) { return String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c])); },
        // school "today" from the server (Asia/Bangkok), never the visitor's clock or a hard-coded date
        today() { return document.querySelector('meta[name=saan-today]')?.content || new Date().toISOString().slice(0, 10); },
        // returns a check that is false once the user moved to another screen; use it before writing late responses
        routeGuard() { const hash = location.hash; return () => location.hash === hash; },
        api, date, uid,
    });
})();