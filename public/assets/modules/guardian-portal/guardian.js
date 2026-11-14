'use strict';
// guardian-portal: guardian pages (attendance, behaviour, term results, growth) with "รับทราบ".
// Every interpolated value goes through SaanCore.esc; no inline handlers (one delegated listener on the root).
(() => {
    const root = document.querySelector('#gp-root');
    if (!root) return;
    const esc = v => window.SaanCore.esc(v);
    const api = (url, opts) => window.SaanCore.api('/guardian/api' + url, opts);
    const date = iso => window.SaanCore.date(iso, {day: 'numeric', month: 'short', year: 'numeric'});
    const TABS = [['attendance', 'การมาเรียน'], ['behavior', 'พฤติกรรม'], ['results', 'ผลการเรียน'], ['timetable', 'ตารางเรียน'], ['health', 'น้ำหนักส่วนสูง'], ['fees', 'ค่าธรรมเนียม'], ['account', 'บัญชีของฉัน']];
    const MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    const st = {children: [], child: null, tab: 'attendance', month: window.SaanCore.today().slice(0, 7), data: null, msg: null, day: 0, me: null};
    let busy = 0;

    const monthLabel = m => { const [y, mo] = m.split('-').map(Number); return MONTHS[mo - 1] + ' ' + (y + 543); };
    const shiftMonth = (m, d) => { const [y, mo] = m.split('-').map(Number); const t = new Date(y, mo - 1 + d, 1); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0'); };
    const nowMonth = () => window.SaanCore.today().slice(0, 7);
    const baht = s => { const n = Math.abs(Number(s) || 0); return (s < 0 ? '-' : '') + Math.floor(n / 100).toLocaleString('en-US') + '.' + String(n % 100).padStart(2, '0'); };
    const CLAIM = {pending: 'รอโรงเรียนตรวจสอบ', approved: 'โรงเรียนอนุมัติแล้ว', rejected: 'โรงเรียนไม่รับรายการนี้', withdrawn: 'ถอนคืนแล้ว', voided: 'โรงเรียนยกเลิกใบเสร็จแล้ว'};
    const when = s => String(s || '').replace('T', ' ').slice(0, 16);
    const localNow = () => { const d = new Date(Date.now() - new Date().getTimezoneOffset() * 60000); return d.toISOString().slice(0, 16); };
    const noteBox = () => '<label class="gp-note"><span class="gp-muted">ความเห็น (ไม่บังคับ)</span><textarea maxlength="500" rows="2" data-note></textarea></label>';
    const ackBadge =(done, at) => done ? `<span class="gp-badge ok">รับทราบแล้ว${at ? ' ' + esc(date(String(at).slice(0, 10))) : ''}</span>` : '';

    function frame(inner) {
        const c = st.children.find(x => x.id === st.child);
        const pick = st.children.length > 1
            ? `<div class="gp-pick"><label for="gp-child">เลือกบุตรหลาน</label><select id="gp-child" data-change="child">${st.children.map(x => `<option value="${esc(x.id)}"${x.id === st.child ? ' selected' : ''}>${esc(x.name)}</option>`).join('')}</select></div>` : '';
        const head = c ? `<h1>${esc(c.name)}</h1><p class="gp-muted">${esc(c.classroom || '')}</p>` : '';
        const msg = st.msg ? `<div class="gp-msg ${st.msg.err ? 'err' : ''}" role="${st.msg.err ? 'alert' : 'status'}">${esc(st.msg.text)}</div>` : '';
        root.innerHTML = pick + head + `<nav class="gp-tabs" aria-label="หัวข้อ">${TABS.map(([k, l]) => `<button type="button" data-act="tab" data-tab="${k}" aria-current="${k === st.tab}">${esc(l)}</button>`).join('')}</nav>` + msg + `<section>${inner}</section>`;
    }

    const views = {
        attendance(d) {
            const nav = `<div class="gp-row"><button type="button" class="gp-btn" data-act="month" data-d="-1">เดือนก่อน</button><strong>${esc(monthLabel(d.month))}</strong><button type="button" class="gp-btn" data-act="month" data-d="1"${d.month >= nowMonth() ? ' disabled' : ''}>เดือนถัดไป</button></div>`;
            const sum = `<p class="gp-muted">แสดงเฉพาะวันที่ไม่ได้มาเรียนตามปกติ (ขาด สาย ลา ป่วย)${d.confirmed_days ? ' · เช็คชื่อแล้ว ' + esc(d.confirmed_days) + ' วัน' : ''}</p>`;
            const list = d.exceptions.length
                ? '<ul class="gp-list">' + d.exceptions.map(e => `<li class="gp-item gp-row"><span>${esc(date(e.date))}</span><span class="gp-badge">${esc(e.label)}</span></li>`).join('') + '</ul>'
                : '<p class="gp-card">ไม่มีวันขาด สาย ลา หรือป่วยในเดือนนี้</p>';
            const ack = d.acknowledged ? `<p>${ackBadge(true, d.acknowledged_at)}</p>` : `<div class="gp-ack">${noteBox()}<button type="button" class="gp-btn gp-primary" data-act="ack" data-kind="attendance_month" data-month="${esc(d.month)}">รับทราบการมาเรียนเดือนนี้</button></div>`;
            return nav + sum + list + ack;
        },
        behavior(d) {
            if (!d.available) return '<p class="gp-card">โรงเรียนยังไม่เปิดใช้การบันทึกพฤติกรรม</p>';
            const t = d.totals;
            const sum = `<p class="gp-muted">คะแนนความดี <b class="gp-pts good">${esc(t.good_points)}</b> · ควรปรับปรุง <b class="gp-pts improve">${esc(t.improve_points)}</b> · สุทธิ <b class="gp-pts">${esc(t.net_points)}</b> (ภาคเรียนปัจจุบัน)</p>`;
            if (!d.records.length) return sum + '<p class="gp-card">ยังไม่มีรายการที่โรงเรียนเปิดให้ผู้ปกครองดู</p>';
            return sum + '<ul class="gp-list">' + d.records.map(r => `<li class="gp-item"><div class="gp-row"><span>${esc(date(r.occurred_on))}</span><b class="gp-pts ${r.points >= 0 ? 'good' : 'improve'}">${r.points > 0 ? '+' : ''}${esc(r.points)}</b></div>`
                + `<div>${esc(r.label || '')}</div>${r.note ? `<div class="gp-muted">${esc(r.note)}</div>` : ''}`
                + (r.acknowledged ? `<div>${ackBadge(true, r.acknowledged_at)}</div>` : `<div class="gp-ack">${r.family_acknowledged ? '<p class="gp-muted">ผู้ปกครองอีกท่านรับทราบรายการนี้แล้ว</p>' : ''}${noteBox()}<button type="button" class="gp-btn gp-primary" data-act="ack" data-kind="behavior" data-ref="${esc(r.id)}">รับทราบ</button></div>`) + '</li>').join('') + '</ul>';
        },
        results(d) {
            return window.SaanPortal.report(d, {ack: t => t.acknowledged ? `<p>${ackBadge(true, t.acknowledged_at)}</p>` : `<div class="gp-ack">${noteBox()}<button type="button" class="gp-btn gp-primary" data-act="ack" data-kind="term_result" data-ref="${esc(t.term_id)}">รับทราบผลการเรียนภาคนี้</button></div>`});
        },
        timetable(d) { return window.SaanPortal.timetable(d, st.day); },
        account(me) {
            if (me.kind !== 'account') return '<p class="gp-card">คุณเข้าด้วยลิงก์ชั่วคราว จึงยังไม่มีบัญชีให้ตั้งรหัสผ่าน ขอให้ครูประจำชั้นออกลิงก์ตั้งบัญชีหรือรหัสผ่านให้</p>';
            const c = me.contact || {};
            const email = c.verified ? `<p>อีเมลที่ยืนยันแล้ว: <b>${esc(c.email_masked)}</b> (ใช้ขอรหัสผ่านใหม่ได้เอง)</p>`
                : (c.has_email ? `<p class="gp-muted">ส่งลิงก์ยืนยันไปที่ ${esc(c.email_masked)} แล้ว กดลิงก์ในอีเมลเพื่อยืนยัน</p>` : '<p class="gp-muted">ยังไม่มีอีเมลที่ยืนยัน (ไม่บังคับ) ถ้าเพิ่มไว้ จะขอรหัสผ่านใหม่ได้เองเมื่อลืม</p>');
            return `<div class="gp-card"><h2>บัญชี${me.username ? ' ' + esc(me.username) : ''}</h2>${secretForm(me)}</div>`
                + `<div class="gp-card"><h2>อีเมล (ไม่บังคับ)</h2>${email}<form class="gp-form" data-form="email"><label>อีเมล<input type="email" name="email" required maxlength="190" autocomplete="email"></label><label>รหัสผ่านปัจจุบัน<input type="password" name="current" required maxlength="200" autocomplete="current-password"></label><button type="submit" class="gp-btn">ส่งลิงก์ยืนยัน</button></form></div>`;
        },fees(d) {
            if (!d.available) return '<p class="gp-card">โรงเรียนยังไม่เปิดใช้การแจ้งค่าธรรมเนียมผ่านพอร์ทัล</p>';
            const unread = d.notices.filter(n => !n.read);
            const notices = unread.length ? '<div class="gp-card" role="status"><h2>ข่าวจากโรงเรียน</h2><ul class="gp-list">' + unread.map(n => '<li class="gp-item"><b>' + esc(n.title) + '</b><div class="gp-muted">' + esc(n.body || '') + '</div></li>').join('') + '</ul><button type="button" class="gp-btn" data-act="readnotices">รับทราบข่าวทั้งหมด</button></div>' : '';
            const head = '<p class="gp-card">ยอดค้างชำระรวม <b>' + esc(baht(d.balance_satang)) + '</b> บาท</p>';
            return notices + head + (d.invoices.length ? d.invoices.map(invoiceCard).join('') : '<p class="gp-card">ยังไม่มีใบแจ้งหนี้</p>');
        },
        health(d) {
            if (!d.available) return '<p class="gp-card">โรงเรียนยังไม่เปิดใช้บันทึกน้ำหนักส่วนสูง</p>';
            if (!d.measurements.length) return '<p class="gp-card">ยังไม่มีบันทึกน้ำหนักส่วนสูง</p>';
            return '<table class="gp-table"><thead><tr><th scope="col">วันที่วัด</th><th scope="col">น้ำหนัก (กก.)</th><th scope="col">ส่วนสูง (ซม.)</th></tr></thead><tbody>'
                + d.measurements.map(m => `<tr><td data-label="วันที่วัด">${esc(date(m.measured_on))}</td><td data-label="น้ำหนัก (กก.)">${m.weight_kg === null ? '-' : esc(m.weight_kg)}</td><td data-label="ส่วนสูง (ซม.)">${m.height_cm === null ? '-' : esc(m.height_cm)}</td></tr>`).join('') + '</tbody></table>';
        },
    };

    function secretForm(me) {
        return `<form class="gp-form" data-form="secret"><p class="gp-muted">ตั้งรหัสผ่านใหม่: ตัวเลขล้วนอย่างน้อย 6 หลัก หรือรหัสผ่านอย่างน้อย ${esc(me.password_min)} ตัวอักษร</p>`
            + '<label>รหัสผ่านปัจจุบัน<input type="password" name="current" required maxlength="200" autocomplete="current-password"></label>'
            + '<label>รหัสผ่านใหม่<input type="password" name="new" required maxlength="200" autocomplete="new-password"></label>'
            + '<button type="submit" class="gp-btn gp-primary">เปลี่ยนรหัสผ่าน</button></form>';
    }

    function invoiceCard(i) {
        const open = i.status === 'open' || i.status === 'partial';
        const accounts = open ? (i.accounts.length ? '<h3>โอนเงินเข้าบัญชีใดบัญชีหนึ่ง</h3><ul class="gp-list">' + i.accounts.map(a => '<li class="gp-item"><div><b>' + esc(a.bank_name) + '</b> ' + esc(a.account_name) + '</div><div class="gp-acct">เลขบัญชี ' + esc(a.account_number) + (a.branch ? ' สาขา' + esc(a.branch) : '') + '</div>'
            + (a.qr_url ? '<img class="gp-qr" src="' + esc(a.qr_url) + '" alt="QR สำหรับโอนเงินเข้าบัญชี ' + esc(a.bank_name) + '" width="200" height="200">' : '') + '</li>').join('') + '</ul>'
            + '<p class="gp-note-ref">ใส่เลขที่ใบแจ้งหนี้ <b>' + esc(i.reference) + '</b> ในบันทึกการโอน และโอนจำนวน <b>' + esc(baht(i.outstanding_satang)) + '</b> บาท (หรือบางส่วนตามที่ต้องการ)</p>'
            : '<p class="gp-muted">โรงเรียนยังไม่ได้ตั้งบัญชีรับเงินสำหรับรายการนี้ กรุณาติดต่อโรงเรียน</p>') : '';
        const form = open && i.accounts.length ? '<form class="gp-form" data-form="claim" data-invoice="' + esc(i.id) + '"><h3>แจ้งการโอนเงินพร้อมแนบสลิป</h3>'
            + '<label>ไฟล์สลิป (PNG, JPEG, WebP หรือ PDF ไม่เกิน 3 ไฟล์ ไฟล์ละไม่เกิน 5 MB)<input type="file" name="slips" accept="image/png,image/jpeg,image/webp,application/pdf" multiple required></label>'
            + '<label>จำนวนเงินที่โอน (บาท)<input name="amount" inputmode="decimal" required value="' + esc(baht(i.outstanding_satang).replace(/,/g, '')) + '"></label>'
            + '<label>วันเวลาที่โอน<input type="datetime-local" name="transferred_at" required max="' + esc(localNow()) + '" value="' + esc(localNow()) + '"></label>'
            + '<label>โอนเข้าบัญชี<select name="account_id" required>' + i.accounts.map(a => '<option value="' + esc(a.id) + '">' + esc(a.bank_name) + ' ' + esc(a.account_number) + '</option>').join('') + '</select></label>'
            + '<button type="submit" class="gp-btn gp-primary">ส่งสลิป</button></form>' : '';
        const claims = i.claims.length ? '<h3>รายการที่แจ้งไว้</h3><ul class="gp-list">' + i.claims.map(c => '<li class="gp-item"><div class="gp-row"><span>' + esc(baht(c.amount_satang)) + ' บาท · ' + esc(when(c.transferred_at)) + '</span><span class="gp-badge">' + esc(CLAIM[c.status] || c.status) + '</span></div>'
            + (c.reject_reason ? '<div class="gp-muted">เหตุผล: ' + esc(c.reject_reason) + '</div>' : '')
            + (c.slips.length ? '<div class="gp-muted">' + c.slips.map((s, n) => '<a href="' + esc(s.url) + '" target="_blank" rel="noopener">สลิปไฟล์ที่ ' + (n + 1) + '</a>').join(' · ') + '</div>' : '')
            + (c.can_withdraw ? '<button type="button" class="gp-btn" data-act="withdraw" data-claim="' + esc(c.id) + '" data-version="' + esc(c.version) + '">ถอนรายการนี้</button>' : '') + '</li>').join('') + '</ul>' : '';
        const rec = i.receipts.length ? '<h3>ใบเสร็จรับเงิน</h3><ul class="gp-list">' + i.receipts.map(r => '<li class="gp-item gp-row"><span>' + esc(r.receipt_no) + ' · ' + esc(date(r.paid_on)) + ' · ' + esc(baht(r.amount_satang)) + ' บาท</span><a class="gp-btn" href="' + esc(r.url) + '" target="_blank" rel="noopener">เปิดใบเสร็จ (พิมพ์เป็น PDF ได้)</a></li>').join('') + '</ul>' : '';
        return '<div class="gp-card"><div class="gp-row"><h2>ใบแจ้งหนี้ ' + esc(i.invoice_no) + '</h2><span class="gp-badge">' + esc(i.status_label) + '</span></div>'
            + '<p class="gp-muted">ออกเมื่อ ' + esc(date(i.issued_on)) + (i.due_on ? ' · ครบกำหนด ' + esc(date(i.due_on)) : '') + '</p>'
            + '<ul class="gp-list">' + i.lines.map(l => '<li class="gp-item gp-row"><span>' + esc(l.label) + '</span><span>' + esc(baht(l.amount_satang - l.discount_satang)) + '</span></li>').join('') + '</ul>'
            + '<p>รวม <b>' + esc(baht(i.total_satang)) + '</b> · ชำระแล้ว ' + esc(baht(i.paid_satang)) + ' · ค้างชำระ <b>' + esc(baht(i.outstanding_satang)) + '</b> บาท</p>'
            + accounts + form + claims + rec + '</div>';
    }

    async function load() {
        const mine = ++busy;
        root.setAttribute('aria-busy', 'true');
        try {
            const qs = st.tab === 'attendance' ? '?month=' + encodeURIComponent(st.month) : '';
            const part = st.tab === 'results' ? 'report' : st.tab;
            const d = st.tab === 'account' ? await api('/me') : await api(`/children/${st.child}/${part}${qs}`);
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
        if (act === 'day') { st.day = Number(b.dataset.day); frame(views.timetable(st.data)); return; }
        if (act === 'print') { window.print(); return; }
        if (act === 'month') { const m = shiftMonth(st.month, +b.dataset.d); if (m <= nowMonth()) { st.month = m; await load(); } return; }
        if (act === 'readnotices') { try { await api('/children/' + st.child + '/notices/read', {method: 'POST', body: {}}); } catch (e) { if (e.status === 401) { location.reload(); return; } } await load(); return; }
        if (act === 'withdraw') {
            if (!window.confirm('ต้องการถอนรายการแจ้งชำระนี้หรือไม่')) return;
            b.disabled = true;
            try {
                await api('/children/' + st.child + '/claims/' + b.dataset.claim + '/withdraw', {method: 'POST', body: {version: Number(b.dataset.version)}});
                st.msg = {err: false, text: 'ถอนรายการแล้ว'};
            } catch (e) {
                if (e.status === 401) { location.reload(); return; }
                st.msg = {err: true, text: e.message};
            }
            await load();
            return;
        }
        if (act === 'ack') {
            b.disabled = true;
            try {
                const note = (b.closest('.gp-ack')?.querySelector('[data-note]')?.value || '').trim().slice(0, 500);
                await api(`/children/${st.child}/ack`, {method: 'POST', body: {kind: b.dataset.kind, ref_id: b.dataset.ref ? Number(b.dataset.ref) : null, ref_month: b.dataset.month || null, note: note || null}});
                st.msg = {err: false, text: 'บันทึกการรับทราบแล้ว'};
            } catch (e) {
                if (e.status === 401) { location.reload(); return; }
                st.msg = {err: true, text: e.message};
            }
            await load();
        }
    });
    root.addEventListener('submit', async ev => {
        const pf = ev.target.closest('form[data-form=secret], form[data-form=email]');
        if (pf) {
            ev.preventDefault();
            const btn = pf.querySelector('button[type=submit]');
            if (btn.disabled) return;
            btn.disabled = true;
            st.msg = null;
            try {
                if (pf.dataset.form === 'secret') {
                    await api('/secret', {method: 'POST', body: {current: pf.current.value, new: pf.new.value}});
                    st.msg = {err: false, text: 'เปลี่ยนรหัสผ่านแล้ว'};
                    if (st.me && st.me.must_change) { location.reload(); return; }
                } else {
                    await api('/email', {method: 'POST', body: {email: pf.email.value, current: pf.current.value}});
                    st.msg = {err: false, text: 'ส่งลิงก์ยืนยันไปที่อีเมลแล้ว'};
                }
            } catch (e) {
                if (e.status === 401) { location.reload(); return; }
                if (st.me && st.me.must_change) { pf.querySelectorAll('.gp-msg').forEach(n => n.remove()); pf.insertAdjacentHTML('beforeend', `<p class="gp-msg err" role="alert">${esc(e.message)}</p>`); btn.disabled = false; return; }
                st.msg = {err: true, text: e.message};
            }
            await load();
            return;
        }
        const f = ev.target.closest('form[data-form=claim]');
        if (!f) return;
        ev.preventDefault();
        const btn = f.querySelector('button[type=submit]');
        if (btn.disabled) return;
        btn.disabled = true;
        st.msg = null;
        try {
            const fd = new FormData();
            [...f.slips.files].forEach(file => fd.append('slips[]', file));
            fd.set('amount', f.amount.value); fd.set('transferred_at', f.transferred_at.value); fd.set('account_id', f.account_id.value);
            if (!f.dataset.key) f.dataset.key = window.SaanCore.uid(); // one key per form: a double tap or retry never sends the claim twice
            await api('/children/' + st.child + '/invoices/' + f.dataset.invoice + '/claims', {method: 'POST', body: fd, idempotencyKey: f.dataset.key});
            st.msg = {err: false, text: 'ส่งสลิปแล้ว รอโรงเรียนตรวจสอบ'};
        } catch (e) {
            if (e.status === 401) { location.reload(); return; }
            st.msg = {err: true, text: e.message};
            f.dataset.key = '';
        }
        await load();
    });
    root.addEventListener('change', async ev => {
        const t = ev.target.closest('[data-change="child"]');
        if (!t) return;
        st.child = Number(t.value); st.msg = null; await load();
    });

    (async () => {
        try {
            try { st.me = await api('/me'); } catch (e) { if (e.status === 401) { location.reload(); return; } }
            if (st.me && st.me.must_change) {
                root.innerHTML = `<div class="gp-card"><h1>ตั้งรหัสผ่านใหม่</h1><p>รหัสผ่านที่ครูออกให้เป็นรหัสชั่วคราว กรุณาตั้งรหัสผ่านของคุณเองก่อนใช้งาน</p>${secretForm(st.me)}</div>`;
                root.setAttribute('aria-busy', 'false');
                return;
            }
            const r = await api('/children');
            st.children = r.data;
            if (!st.children.length) { root.innerHTML = '<p class="gp-card">ยังไม่มีบุตรหลานที่เปิดสิทธิ์ให้ดูข้อมูล กรุณาติดต่อครูประจำชั้น</p>'; root.setAttribute('aria-busy', 'false'); return; }
            st.child = st.children[0].id;
            await load();
        } catch (e) {
            if (e.status === 401) { location.reload(); return; }
            root.innerHTML = `<p class="gp-msg err" role="alert">${esc(e.message)}</p>`; root.setAttribute('aria-busy', 'false');
        }
    })();
})();
