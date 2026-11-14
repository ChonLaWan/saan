'use strict';
// student-portal: the student's own pages (results, timetable, attendance, behaviour points, account). Read-only: no request carries a student id.
// Every interpolated value goes through SaanCore.esc; no inline handlers (one delegated listener on the root).
(() => {
    const root = document.querySelector('#gp-root');
    if (!root) return;
    const esc = v => window.SaanCore.esc(v);
    const api = (url, opts) => window.SaanCore.api('/student/api' + url, opts);
    const date = iso => window.SaanCore.date(iso, {day: 'numeric', month: 'short', year: 'numeric'});
    const TABS = [['results', 'ผลการเรียน'], ['timetable', 'ตารางเรียน'], ['attendance', 'การมาเรียน'], ['behavior', 'ความประพฤติ'], ['account', 'บัญชีของฉัน']];
    const MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    // tab key -> read-only endpoint under /student/api (the results tab is served by /report, there is no /results route)
    const ENDPOINT = {results: '/report', timetable: '/timetable', attendance: '/attendance', behavior: '/behavior'};
    const st = {me: null, tab: 'results', month: window.SaanCore.today().slice(0, 7), day: 0, data: null, msg: null};
    let busy = 0;

    const monthLabel = m => { const [y, mo] = m.split('-').map(Number); return MONTHS[mo - 1] + ' ' + (y + 543); };
    const shiftMonth = (m, d) => { const [y, mo] = m.split('-').map(Number); const t = new Date(y, mo - 1 + d, 1); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0'); };
    const nowMonth = () => window.SaanCore.today().slice(0, 7);

    function secretForm(me) {
        return `<form class="gp-form" data-form="secret"><p class="gp-muted">ตั้งรหัสผ่านใหม่: ตัวเลขล้วนอย่างน้อย 6 หลัก หรือรหัสผ่านอย่างน้อย ${esc(me.password_min)} ตัวอักษร (ห้ามมีรหัสนักเรียนอยู่ในรหัสผ่าน)</p>`
            + '<label>รหัสผ่านปัจจุบัน<input type="password" name="current" required maxlength="200" autocomplete="current-password"></label>'
            + '<label>รหัสผ่านใหม่<input type="password" name="new" required maxlength="200" autocomplete="new-password"></label>'
            + '<button type="submit" class="gp-btn gp-primary">เปลี่ยนรหัสผ่าน</button></form>';
    }

    function frame(inner) {
        const me = st.me;
        const head = me ? `<h1>${esc(me.name)}</h1><p class="gp-muted">${esc(me.classroom || '')}</p>` : '';
        const msg = st.msg ? `<div class="gp-msg ${st.msg.err ? 'err' : ''}" role="${st.msg.err ? 'alert' : 'status'}">${esc(st.msg.text)}</div>` : '';
        root.innerHTML = head + `<nav class="gp-tabs" aria-label="หัวข้อ">${TABS.map(([k, l]) => `<button type="button" data-act="tab" data-tab="${k}" aria-current="${k === st.tab}">${esc(l)}</button>`).join('')}</nav>` + msg + `<section>${inner}</section>`;
    }

    const views = {
        results: d => window.SaanPortal.report(d),
        timetable: d => window.SaanPortal.timetable(d, st.day),
        attendance(d) {
            if (!d.available) return '<p class="gp-card">โรงเรียนยังไม่เปิดใช้การเช็คชื่อ</p>';
            const nav = `<div class="gp-row"><button type="button" class="gp-btn" data-act="month" data-d="-1">เดือนก่อน</button><strong>${esc(monthLabel(d.month))}</strong><button type="button" class="gp-btn" data-act="month" data-d="1"${d.month >= nowMonth() ? ' disabled' : ''}>เดือนถัดไป</button></div>`;
            const c = d.counts;
            const sum = `<p class="gp-muted">เช็คชื่อแล้ว ${esc(d.confirmed_days)} วัน · ขาด ${esc(c.absent)} · สาย ${esc(c.late)} · ลา ${esc(c.leave)} · ป่วย ${esc(c.sick)}</p>`;
            const list = d.exceptions.length ? '<ul class="gp-list">' + d.exceptions.map(e => `<li class="gp-item gp-row"><span>${esc(date(e.date))}</span><span class="gp-badge">${esc(e.label)}</span></li>`).join('') + '</ul>' : '<p class="gp-card">ไม่มีวันขาด สาย ลา หรือป่วยในเดือนนี้</p>';
            return nav + sum + list;
        },
        behavior(d) {
            if (!d.available) return '<p class="gp-card">โรงเรียนยังไม่เปิดใช้การบันทึกความประพฤติ</p>';
            const t = d.totals;
            const sum = `<p class="gp-muted">คะแนนความดี <b class="gp-pts good">${esc(t.good_points)}</b> · ควรปรับปรุง <b class="gp-pts improve">${esc(t.improve_points)}</b> · สุทธิ <b class="gp-pts">${esc(t.net_points)}</b> (ภาคเรียนปัจจุบัน)</p>`;
            if (!d.records.length) return sum + '<p class="gp-card">ยังไม่มีรายการ</p>';
            return sum + '<ul class="gp-list">' + d.records.map(r => `<li class="gp-item"><div class="gp-row"><span>${esc(date(r.occurred_on))}</span><b class="gp-pts ${r.points >= 0 ? 'good' : 'improve'}">${r.points > 0 ? '+' : ''}${esc(r.points)}</b></div><div>${esc(r.label || '')}</div></li>`).join('') + '</ul>';
        },
        account(me) {
            const c = me.contact || {};
            const email = c.verified ? `<p>อีเมลที่ยืนยันแล้ว: <b>${esc(c.email_masked)}</b> (ใช้ขอรหัสผ่านใหม่ได้เอง)</p>`
                : (c.has_email ? `<p class="gp-muted">ส่งลิงก์ยืนยันไปที่ ${esc(c.email_masked)} แล้ว กดลิงก์ในอีเมลเพื่อยืนยัน</p>` : '<p class="gp-muted">ยังไม่มีอีเมลที่ยืนยัน (ไม่บังคับ) ถ้าเพิ่มไว้ จะขอรหัสผ่านใหม่ได้เองเมื่อลืม</p>');
            return `<div class="gp-card"><h2>เปลี่ยนรหัสผ่าน</h2>${secretForm(me)}</div>`
                + `<div class="gp-card"><h2>อีเมล (ไม่บังคับ)</h2>${email}<form class="gp-form" data-form="email"><label>อีเมล<input type="email" name="email" required maxlength="190" autocomplete="email"></label><label>รหัสผ่านปัจจุบัน<input type="password" name="current" required maxlength="200" autocomplete="current-password"></label><button type="submit" class="gp-btn">ส่งลิงก์ยืนยัน</button></form></div>`;
        },
    };

    async function load() {
        const mine = ++busy;
        root.setAttribute('aria-busy', 'true');
        try {
            const qs = st.tab === 'attendance' ? '?month=' + encodeURIComponent(st.month) : '';
            if (st.tab === 'account') st.me = await api('/me');
            const d = st.tab === 'account' ? st.me : await api(ENDPOINT[st.tab] + qs);
            if (mine !== busy) return;
            st.data = d;
            frame(views[st.tab](d));
        } catch (e) {
            if (mine !== busy) return;
            if (e.status === 401) { location.reload(); return; }
            st.msg = {err: true, text: e.message};
            frame('');
        } finally { if (mine === busy) root.setAttribute('aria-busy', 'false'); }
    }

    root.addEventListener('click', async ev => {
        const b = ev.target.closest('[data-act]');
        if (!b || !root.contains(b) || b.disabled) return;
        const act = b.dataset.act;
        st.msg = null;
        if (act === 'tab') { st.tab = b.dataset.tab; await load(); return; }
        if (act === 'month') { const m = shiftMonth(st.month, +b.dataset.d); if (m <= nowMonth()) { st.month = m; await load(); } return; }
        if (act === 'day') { st.day = Number(b.dataset.day); frame(views.timetable(st.data)); return; }
        if (act === 'print') window.print();
    });

    root.addEventListener('submit', async ev => {
        const f = ev.target.closest('form[data-form=secret], form[data-form=email]');
        if (!f) return;
        ev.preventDefault();
        const btn = f.querySelector('button[type=submit]');
        if (btn.disabled) return;
        btn.disabled = true;
        st.msg = null;
        try {
            if (f.dataset.form === 'secret') {
                await api('/secret', {method: 'POST', body: {current: f.current.value, new: f.new.value}});
                if (st.me && st.me.must_change) { location.reload(); return; }
                st.msg = {err: false, text: 'เปลี่ยนรหัสผ่านแล้ว'};
            } else {
                await api('/email', {method: 'POST', body: {email: f.email.value, current: f.current.value}});
                st.msg = {err: false, text: 'ส่งลิงก์ยืนยันไปที่อีเมลแล้ว'};
            }
        } catch (e) {
            if (e.status === 401) { location.reload(); return; }
            if (st.me && st.me.must_change) { f.querySelectorAll('.gp-msg').forEach(n => n.remove()); f.insertAdjacentHTML('beforeend', `<p class="gp-msg err" role="alert">${esc(e.message)}</p>`); btn.disabled = false; return; }
            st.msg = {err: true, text: e.message};
        }
        await load();
    });

    (async () => {
        try {
            st.me = await api('/me');
            if (st.me.must_change) {
                root.innerHTML = `<div class="gp-card"><h1>ตั้งรหัสผ่านใหม่</h1><p>รหัสผ่านที่ครูให้เป็นรหัสชั่วคราว กรุณาตั้งรหัสผ่านของตัวเองก่อนใช้งาน</p>${secretForm(st.me)}</div>`;
                root.setAttribute('aria-busy', 'false');
                return;
            }
            await load();
        } catch (e) {
            if (e.status === 401) { location.reload(); return; }
            root.innerHTML = `<p class="gp-msg err" role="alert">${esc(e.message)}</p>`; root.setAttribute('aria-busy', 'false');
        }
    })();
})();
