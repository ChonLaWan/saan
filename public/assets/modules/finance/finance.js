'use strict';
// finance module: staff page #finance (student lookup + receipts, bulk invoices, daily summary, cash book, setup) for the /work shell.
// Money is handled as integer satang only; baht text typed by the user is parsed with a regex, never with floats.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const METHOD = {cash: 'เงินสด', transfer: 'โอนเงิน', promptpay: 'พร้อมเพย์', other: 'อื่น ๆ'};
    const STATUS = {open: 'ค้างชำระ', partial: 'ชำระบางส่วน', paid: 'ชำระครบ', void: 'ยกเลิก'};
    const SKIP = {duplicate: 'มีใบแจ้งหนี้แล้ว', no_fee: 'ไม่มีรายการที่ใช้กับชั้นนี้'};

    const fmt = s => {
        const n = Math.abs(Number(s) || 0);
        return (s < 0 ? '-' : '') + Math.floor(n / 100).toLocaleString('en-US') + '.' + String(n % 100).padStart(2, '0');
    };
    // "1,234.5" -> 123450; null when the text is not a valid amount with at most two decimals
    const toSatang = text => {
        const m = /^\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?\s*$/.exec(String(text));
        if (!m) return null;
        const baht = m[1].replace(/,/g, '');
        if (baht.length > 12) return null;
        return Number(baht) * 100 + Number((m[2] || '').padEnd(2, '0'));
    };
    const plain = s => fmt(s).replace(/,/g, '');
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    };
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const link = path => base() + path;
    const field = (label, control) => `<label class="fin-field"><span>${esc(label)}</span>${control}</label>`;
    const opts = (map, cur) => Object.entries(map).map(([k, v]) => `<option value="${esc(k)}"${k === cur ? ' selected' : ''}>${esc(v)}</option>`).join('');
    // receiving-account checkboxes (active accounts only); none ticked = null = "fall back" (fee item list, else every active account)
    const acctBoxes = (accts, sel, legend) => accts.length ? `<fieldset class="fin-accts"><legend>${esc(legend || 'บัญชีรับเงิน (ไม่เลือก = ทุกบัญชีที่เปิดใช้)')}</legend>${accts.map(a => `<label class="fin-check"><input type="checkbox" name="acc" value="${esc(a.id)}"${(sel || []).includes(a.id) ? ' checked' : ''}> ${esc(a.bank_name)} ${esc(a.account_name)}</label>`).join('')}</fieldset>` : '';
    const pickAccts = root => { const ids = [...root.querySelectorAll('input[name=acc]:checked')].map(c => Number(c.value)); return ids.length ? ids : null; };
    const badge = st => `<span class="fin-badge ${esc(st)}">${esc(STATUS[st] || st)}</span>`;
    const TABS = [['', 'ค้นหาและรับเงิน'], ['bulk', 'ออกใบแจ้งหนี้'], ['claims', 'ตรวจสลิป'], ['rooms', 'ยอดค้างรายห้อง'], ['accounts', 'บัญชีรับเงิน'], ['daily', 'สรุปรายวัน'], ['ledger', 'สมุดเงินสด'], ['setup', 'ตั้งค่า']];

    function shell(root, me, tab) {
        const host = document.createElement('div');
        host.className = 'fin-host';
        const tabs = TABS.filter(t => t[0] !== 'setup' || me.is_manager)
            .map(([k, label]) => `<a href="#finance${k ? '/' + k : ''}"${k === tab ? ' aria-current="page"' : ''}>${esc(label)}</a>`).join('');
        host.innerHTML = `<section class="fin glass"><h1>การเงิน</h1><nav class="fin-tabs" aria-label="เมนูการเงิน">${tabs}</nav><div class="fin-body" id="fin-body"></div></section>`;
        root.append(host);
        return {host, body: host.querySelector('#fin-body')};
    }

    async function guarded(fn, reload) {
        try { return await fn(); } catch (e) {
            if (e?.name === 'AbortError') return null;
            if (e?.status === 409 && reload) { toast(e.message + ' (โหลดข้อมูลใหม่แล้ว)', true); reload(); } else toast(e?.message || 'เกิดข้อผิดพลาด', true);
            return null;
        }
    }

    // ------------------------------------------------------------ search
    // empty state of the search-first page: say what to do, and where the overview lists are
    const HINT = 'ยังไม่ได้ค้นหา พิมพ์รหัสนักเรียน (มีตัวเลข) หรือส่วนหนึ่งของชื่อ เพื่อดูใบแจ้งหนี้และรับชำระเงิน หรือเปิด “ยอดค้างรายห้อง” หรือ “สรุปรายวัน” จากแถบด้านบนเพื่อดูภาพรวม';

    function search(body, ctx) {
        body.innerHTML = `<form class="fin-search" role="search"><label class="fin-field"><span>รหัสหรือชื่อนักเรียน</span><input type="search" name="q" autocomplete="off" maxlength="80" autofocus></label></form>
            <p class="fin-hint" id="fin-count">${HINT}</p><div id="fin-results"></div>`;
        const input = body.querySelector('input'), out = body.querySelector('#fin-results'), count = body.querySelector('#fin-count');
        let timer = 0, seq = 0;
        body.querySelector('form').addEventListener('submit', e => { e.preventDefault(); run(); });
        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 250); });
        async function run() {
            const q = input.value.trim(), mine = ++seq;
            if (!q) { out.replaceChildren(); count.textContent = HINT; return; }
            const res = await guarded(() => ctx.api('/api/finance/students?q=' + encodeURIComponent(q)));
            if (!res || mine !== seq || !ctx.alive()) return;
            count.textContent = res.data.length ? `พบ ${res.data.length} รายการ` : 'ไม่พบนักเรียน';
            out.innerHTML = `<ul class="fin-cards">${res.data.map(s => `<li><a href="#finance/student/${esc(s.id)}"><strong>${esc(s.name)}</strong><span>${esc(s.code)} ${esc(s.classroom || '')}</span>
                <span class="fin-owed${s.balance_satang > 0 ? ' due' : ''}">ค้างชำระ ${esc(fmt(s.balance_satang))} บาท</span></a></li>`).join('')}</ul>`;
        }
    }

    // ------------------------------------------------------------ student account
    async function student(body, ctx, id, me) {
        let lastReceipt = null; // shown as a link after payment: window.open after an await is blocked by popup blockers
        const load = async () => {
            const [acc, fees, lk, accts] = await Promise.all([ctx.api('/api/finance/students/' + encodeURIComponent(id) + '/account'), ctx.api('/api/finance/fee-items?active=1'), ctx.api('/api/finance/lookups'), ctx.api('/api/finance/accounts')]);
            if (!ctx.alive()) return;
            draw(acc, fees.data, lk, accts.data.filter(a => a.is_active));
        };
        const reload = () => guarded(load).then(() => {});
        function draw(acc, fees, lk, accts) {
            const s = acc.student, pays = {};
            acc.payments.forEach(p => (pays[p.invoice_id] ||= []).push(p));
            const rows = acc.invoices.map(i => {
                const owe = i.total_satang - i.paid_satang, list = pays[i.id] || [];
                return `<article class="fin-invoice" data-invoice="${esc(i.id)}" data-version="${esc(i.version)}"><header><strong>${esc(i.invoice_no)}</strong> ${badge(i.status)}
                    <span>ออก ${esc(window.SaanCore.date(i.issued_on))}</span></header>
                    <p>ยอดรวม ${esc(fmt(i.total_satang))} / ชำระแล้ว ${esc(fmt(i.paid_satang))} / ค้าง <strong>${esc(fmt(owe))}</strong> บาท</p>
                    <ul class="fin-pays">${list.map(p => `<li class="${p.status === 'void' ? 'void' : ''}">${esc(p.receipt_no)} ${esc(window.SaanCore.date(p.paid_on))} ${esc(METHOD[p.method] || p.method)} ${esc(fmt(p.amount_satang))}
                        ${p.status === 'void' ? '(ยกเลิก: ' + esc(p.void_reason || '') + ')' : ''}
                        <a href="${esc(link('/api/finance/payments/' + p.id + '/receipt'))}" target="_blank" rel="noopener">พิมพ์ใบเสร็จ</a>
                        ${p.status === 'valid' ? `<button type="button" class="fin-btn small danger" data-act="void-pay" data-id="${esc(p.id)}" data-version="${esc(p.version)}">ยกเลิกใบเสร็จ</button>` : ''}</li>`).join('')}</ul>
                    ${owe > 0 && i.status !== 'void' ? `<form class="fin-pay" data-id="${esc(i.id)}"><div class="fin-grid">
                        ${field('จำนวนเงิน (บาท)', `<input name="amount" inputmode="decimal" value="${esc(plain(owe))}" required>`)}
                        ${field('วิธีชำระ', `<select name="method">${opts(METHOD, 'cash')}</select>`)}
                        ${field('วันที่รับเงิน', `<input type="date" name="paid_on" value="${esc(me.today)}" max="${esc(me.today)}" required>`)}
                        ${field('อ้างอิง (ถ้ามี)', '<input name="reference" maxlength="120">')}</div>
                        <button class="fin-btn primary" type="submit">รับเงินและออกใบเสร็จ</button></form>` : ''}
                    ${i.status === 'open' ? `<button type="button" class="fin-btn small danger" data-act="void-inv" data-id="${esc(i.id)}" data-version="${esc(i.version)}">ยกเลิกใบแจ้งหนี้</button>` : ''}</article>`;
            }).join('') || '<p class="fin-hint">ยังไม่มีใบแจ้งหนี้</p>';
            const banner = lastReceipt ? `<p class="fin-hint fin-receipt-ok" role="status">ออกใบเสร็จ <strong>${esc(lastReceipt.no)}</strong> แล้ว
                <a class="fin-btn primary" href="${esc(link('/api/finance/payments/' + lastReceipt.id + '/receipt'))}" target="_blank" rel="noopener">พิมพ์ใบเสร็จ ${esc(lastReceipt.no)}</a></p>` : '';
            body.innerHTML = `${banner}<p><a href="#finance">&larr; ค้นหานักเรียน</a></p><h2>${esc(s.name)}</h2><p class="fin-hint">${esc(s.code)} ${esc(s.classroom || '')} / ยอดค้างรวม <strong>${esc(fmt(acc.balance_satang))}</strong> บาท</p>
                ${rows}<details class="fin-new"><summary>ออกใบแจ้งหนี้ใหม่</summary><form class="fin-inv"><div class="fin-grid">
                ${field('ภาคเรียน', `<select name="term_id">${lk.terms.map(t => `<option value="${esc(t.id)}"${t.id === lk.current_term_id ? ' selected' : ''}>${esc(t.year + '/' + t.term)}</option>`).join('')}</select>`)}
                ${field('วันที่ออก', `<input type="date" name="issued_on" value="${esc(me.today)}" max="${esc(me.today)}" required>`)}
                ${field('ครบกำหนด', '<input type="date" name="due_on">')}</div>
                <div class="fin-lines">${fees.map(f => `<div class="fin-line"><label class="fin-check"><input type="checkbox" name="fee" value="${esc(f.id)}" data-amount="${esc(f.default_amount_satang)}" data-label="${esc(f.label)}"> ${esc(f.label)} (${esc(fmt(f.default_amount_satang))})</label>
                    ${accts.length ? `<details><summary>เลือกบัญชีรับเงินของรายการนี้</summary>${acctBoxes(accts, f.account_ids)}</details>` : ''}</div>`).join('')}</div>
                <div class="fin-line" data-custom><div class="fin-grid">${field('รายการอื่น (ชื่อ)', '<input name="custom_label" maxlength="200">')}${field('จำนวนเงิน (บาท)', '<input name="custom_amount" inputmode="decimal">')}</div>
                    ${accts.length ? `<details><summary>เลือกบัญชีรับเงินของรายการอื่น</summary>${acctBoxes(accts, [])}</details>` : ''}</div>
                <button class="fin-btn primary" type="submit">ออกใบแจ้งหนี้</button></form></details>`;
            body.querySelector('.fin-inv').addEventListener('submit', async e => {
                e.preventDefault();
                const f = e.currentTarget, lines = [...f.querySelectorAll('input[name=fee]:checked')].map(c => {
                    const ids = pickAccts(c.closest('.fin-line'));
                    return {fee_item_id: Number(c.value), label: c.dataset.label, amount_satang: Number(c.dataset.amount), ...(ids ? {account_ids: ids} : {})};
                });
                const label = f.custom_label.value.trim(), amt = f.custom_amount.value.trim();
                if (label || amt) { const sat = toSatang(amt); if (!label || sat === null) return toast('รายการอื่นต้องมีชื่อและจำนวนเงินที่ถูกต้อง', true); const ids = pickAccts(f.querySelector('[data-custom]')); lines.push({label, amount_satang: sat, ...(ids ? {account_ids: ids} : {})}); }
                if (!lines.length) return toast('เลือกอย่างน้อยหนึ่งรายการ', true);
                f.querySelector('button').disabled = true;
                const ok = await guarded(() => ctx.api('/api/finance/invoices', {method: 'POST', idempotencyKey: window.SaanCore.uid(), body: {student_id: Number(id), term_id: Number(f.term_id.value), issued_on: f.issued_on.value, due_on: f.due_on.value || null, lines}}), reload);
                if (ok) { toast('ออกใบแจ้งหนี้แล้ว'); reload(); } else f.querySelector('button').disabled = false;
            });
            body.querySelectorAll('.fin-pay').forEach(f => {
                const key = window.SaanCore.uid(); // one key per open form: a double click or retry replays the same receipt
                f.addEventListener('submit', async e => {
                    e.preventDefault();
                    const sat = toSatang(f.amount.value);
                    if (sat === null || sat < 1) return toast('จำนวนเงินไม่ถูกต้อง', true);
                    const card = f.closest('.fin-invoice');
                    f.querySelector('button').disabled = true;
                    const res = await guarded(() => ctx.api('/api/finance/invoices/' + f.dataset.id + '/payments', {method: 'POST', idempotencyKey: key,
                        body: {amount_satang: sat, method: f.method.value, paid_on: f.paid_on.value, reference: f.reference.value.trim() || null, invoice_version: Number(card.dataset.version)}}), reload);
                    if (res) { lastReceipt = {id: res.payment.id, no: res.payment.receipt_no}; toast('ออกใบเสร็จ ' + res.payment.receipt_no + ' แล้ว'); reload(); } else f.querySelector('button').disabled = false;
                });
            });
        }
        body.addEventListener('click', async e => {
            const b = e.target.closest('button[data-act]'); if (!b) return;
            const reason = window.prompt(b.dataset.act === 'void-pay' ? 'เหตุผลที่ยกเลิกใบเสร็จ (ใบเสร็จจะยังเก็บเลขที่เดิมไว้)' : 'เหตุผลที่ยกเลิกใบแจ้งหนี้');
            if (!reason || reason.trim().length < 3) return;
            const url = b.dataset.act === 'void-pay' ? '/api/finance/payments/' + b.dataset.id + '/void' : '/api/finance/invoices/' + b.dataset.id + '/void';
            b.disabled = true;
            const ok = await guarded(() => ctx.api(url, {method: 'POST', idempotencyKey: window.SaanCore.uid(), body: {version: Number(b.dataset.version), reason: reason.trim()}}), reload);
            if (ok) { toast('ยกเลิกแล้ว'); reload(); } else b.disabled = false;
        });
        return guarded(load);
    }

    // ------------------------------------------------------------ bulk
    async function bulk(body, ctx, me) {
        const [lk, fees] = await Promise.all([ctx.api('/api/finance/lookups'), ctx.api('/api/finance/fee-items?active=1')]);
        if (!ctx.alive()) return;
        const grades = [...new Set(lk.classrooms.map(c => c.grade_level).filter(Boolean))];
        body.innerHTML = `<form class="fin-bulk"><div class="fin-grid">
            ${field('ห้องเรียน', `<select name="classroom_id"><option value="">-- เลือกห้อง --</option>${lk.classrooms.map(c => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('')}</select>`)}
            ${field('หรือทั้งระดับชั้น', `<select name="grade_level"><option value="">-- ไม่เลือก --</option>${grades.map(g => `<option value="${esc(g)}">${esc(g)}</option>`).join('')}</select>`)}
            ${field('วันที่ออก', `<input type="date" name="issued_on" value="${esc(me.today)}" max="${esc(me.today)}" required>`)}
            ${field('ครบกำหนด', '<input type="date" name="due_on">')}</div>
            <fieldset class="fin-lines"><legend>รายการค่าธรรมเนียม</legend>${fees.data.map(f => `<div class="fin-line"><label class="fin-check"><input type="checkbox" name="fee" value="${esc(f.id)}"> ${esc(f.label)}</label>
                <input name="amt-${esc(f.id)}" inputmode="decimal" value="${esc(plain(f.default_amount_satang))}" aria-label="จำนวนเงิน ${esc(f.label)}"></div>`).join('') || '<p class="fin-hint">ยังไม่มีรายการค่าธรรมเนียม เพิ่มได้ที่แท็บตั้งค่า</p>'}</fieldset>
            <button class="fin-btn" type="submit">ดูตัวอย่าง</button></form><div id="fin-preview"></div>`;
        const form = body.querySelector('form'), out = body.querySelector('#fin-preview');
        let plan = null, payload = null;
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const items = [...form.querySelectorAll('input[name=fee]:checked')].map(c => ({fee_item_id: Number(c.value), amount_satang: toSatang(form['amt-' + c.value].value)}));
            if (!items.length || items.some(i => i.amount_satang === null)) return toast('เลือกรายการและกรอกจำนวนเงินให้ถูกต้อง', true);
            if (!form.classroom_id.value && !form.grade_level.value) return toast('เลือกห้องหรือระดับชั้น', true);
            payload = {items, ...(form.classroom_id.value ? {classroom_id: Number(form.classroom_id.value)} : {grade_level: form.grade_level.value})};
            plan = await guarded(() => ctx.api('/api/finance/invoices/bulk/preview', {method: 'POST', body: payload}));
            if (!plan || !ctx.alive()) return;
            out.innerHTML = `<p><strong>จะออก ${esc(plan.create_count)} ใบ</strong> รวม ${esc(fmt(plan.total_satang))} บาท / ข้าม ${esc(plan.skip_count)} คน</p>
                <div class="fin-table-wrap"><table class="fin-table"><thead><tr><th>รหัส</th><th>ชื่อ</th><th>ห้อง</th><th class="n">ยอด</th><th>สถานะ</th></tr></thead><tbody>${plan.rows.map(r => `<tr class="${r.skip ? 'skip' : ''}">
                <td data-label="รหัส">${esc(r.code)}</td><td data-label="ชื่อ">${esc(r.name)}</td><td data-label="ห้อง">${esc(r.classroom)}</td><td class="n" data-label="ยอด">${esc(fmt(r.total_satang))}</td><td data-label="สถานะ">${esc(r.skip ? SKIP[r.skip] || r.skip : 'ออกใบแจ้งหนี้')}</td></tr>`).join('')}</tbody></table></div>
                <button class="fin-btn primary" type="button" id="fin-commit"${plan.create_count ? '' : ' disabled'}>ยืนยันออกใบแจ้งหนี้ ${esc(plan.create_count)} ใบ</button>`;
            const key = window.SaanCore.uid();
            out.querySelector('#fin-commit').addEventListener('click', async ev => {
                ev.currentTarget.disabled = true;
                const res = await guarded(() => ctx.api('/api/finance/invoices/bulk', {method: 'POST', idempotencyKey: key, body: {...payload, issued_on: form.issued_on.value, due_on: form.due_on.value || null, expected_count: plan.create_count}}));
                if (res) { toast('ออกใบแจ้งหนี้ ' + res.created + ' ใบแล้ว'); out.innerHTML = `<p class="fin-ok">ออกแล้ว ${esc(res.created)} ใบ ข้าม ${esc(res.skipped)} คน</p>`; }
                else ev.currentTarget.disabled = false;
            });
        });
    }

    // ------------------------------------------------------------ daily
    async function daily(body, ctx) {
        const load = async date => {
            const d = await ctx.api('/api/finance/reports/daily?date=' + encodeURIComponent(date));
            if (!ctx.alive()) return;
            body.querySelector('#fin-day').innerHTML = `<div class="fin-sums">${Object.entries(d.methods).map(([k, v]) => `<div><span>${esc(METHOD[k])}</span><strong>${esc(fmt(v.total_satang))}</strong><small>${esc(v.count)} ใบ</small></div>`).join('')}
                <div class="total"><span>รวมวันนี้</span><strong>${esc(fmt(d.total_satang))}</strong><small>ยกเลิก ${esc(d.voided.count)} ใบ</small></div></div>
                <p><a href="${esc(link('/api/finance/reports/daily.csv?date=' + encodeURIComponent(date)))}">ส่งออก CSV</a>${d.closed ? ' <span class="fin-badge void">งวดนี้ปิดแล้ว</span>' : ''}</p>
                <div class="fin-table-wrap"><table class="fin-table"><thead><tr><th>ใบเสร็จ</th><th>นักเรียน</th><th>วิธี</th><th class="n">จำนวน</th><th>สถานะ</th></tr></thead><tbody>${d.payments.map(p => `<tr class="${p.status === 'void' ? 'skip' : ''}">
                <td data-label="ใบเสร็จ">${esc(p.receipt_no)}</td><td data-label="นักเรียน">${esc(p.student_code)} ${esc(p.student_name)}</td><td data-label="วิธี">${esc(METHOD[p.method] || p.method)}</td>
                <td class="n" data-label="จำนวน">${esc(fmt(p.amount_satang))}</td><td data-label="สถานะ">${p.status === 'void' ? 'ยกเลิก' : 'ใช้งาน'}</td></tr>`).join('') || '<tr><td colspan="5">ไม่มีรายการ</td></tr>'}</tbody></table></div>`;
        };
        body.innerHTML = `<form class="fin-range">${field('วันที่', `<input type="date" name="date" value="${esc(window.SaanCore.today())}">`)}</form><div id="fin-day" aria-live="polite"></div>`;
        const input = body.querySelector('input');
        input.addEventListener('change', () => input.value && guarded(() => load(input.value)));
        return guarded(() => load(input.value));
    }

    // ------------------------------------------------------------ ledger + periods
    async function ledger(body, ctx, me) {
        const month = window.SaanCore.today().slice(0, 8) + '01';
        body.innerHTML = `<form class="fin-range fin-grid">${field('ตั้งแต่', `<input type="date" name="from" value="${esc(month)}">`)}${field('ถึง', `<input type="date" name="to" value="${esc(me.today)}">`)}</form>
            <div id="fin-ledger" aria-live="polite"></div>
            <details class="fin-new"><summary>บันทึกรายรับ/รายจ่ายอื่น</summary><form class="fin-entry"><div class="fin-grid">
            ${field('วันที่', `<input type="date" name="entry_date" value="${esc(me.today)}" max="${esc(me.today)}" required>`)}${field('ประเภท', '<select name="kind"><option value="expense">รายจ่าย</option><option value="income">รายรับ</option></select>')}
            ${field('หมวด', '<input name="category" maxlength="120" required>')}${field('จำนวนเงิน (บาท)', '<input name="amount" inputmode="decimal" required>')}
            ${field('รายการ', '<input name="description" maxlength="300" required>')}${field('เลขที่เอกสาร', '<input name="document_no" maxlength="60">')}</div>
            <button class="fin-btn primary" type="submit">บันทึก</button></form></details>
            <h2>ปิดงวดบัญชี</h2><div id="fin-periods"></div>`;
        const range = body.querySelector('.fin-range');
        const load = async () => {
            const q = new URLSearchParams({from: range.from.value, to: range.to.value});
            const l = await ctx.api('/api/finance/ledger?' + q);
            if (!ctx.alive()) return;
            body.querySelector('#fin-ledger').innerHTML = `<div class="fin-sums"><div><span>ยอดยกมา</span><strong>${esc(fmt(l.opening_satang))}</strong></div><div><span>รับ</span><strong>${esc(fmt(l.income_satang))}</strong></div>
                <div><span>จ่าย</span><strong>${esc(fmt(l.expense_satang))}</strong></div><div class="total"><span>คงเหลือ</span><strong>${esc(fmt(l.closing_satang))}</strong></div></div>
                <p><a href="${esc(link('/api/finance/ledger.csv?' + q))}">ส่งออก CSV</a></p>
                <div class="fin-table-wrap"><table class="fin-table"><thead><tr><th>วันที่</th><th>รายการ</th><th>เอกสาร</th><th class="n">รับ</th><th class="n">จ่าย</th><th></th></tr></thead><tbody>${l.data.map(e => `<tr>
                <td data-label="วันที่">${esc(window.SaanCore.date(e.entry_date))}</td><td data-label="รายการ">${esc(e.category)} ${esc(e.description)}</td><td data-label="เอกสาร">${esc(e.document_no || '')}</td>
                <td class="n" data-label="รับ">${e.kind === 'income' ? esc(fmt(e.amount_satang)) : (e.kind === 'reversal' ? esc(fmt(-e.amount_satang)) : '')}</td><td class="n" data-label="จ่าย">${e.kind === 'expense' ? esc(fmt(e.amount_satang)) : ''}</td>
                <td>${e.payment_id ? '' : `<button type="button" class="fin-btn small danger" data-del="${esc(e.id)}" data-version="${esc(e.version)}">ลบ</button>`}</td></tr>`).join('') || '<tr><td colspan="6">ไม่มีรายการ</td></tr>'}</tbody></table></div>`;
        };
        const periods = async () => {
            const p = await ctx.api('/api/finance/periods');
            if (!ctx.alive()) return;
            const host = body.querySelector('#fin-periods');
            host.innerHTML = `<ul class="fin-cards">${p.data.map(r => `<li><span><strong>${esc(r.period)}</strong> ${r.closed_at ? 'ปิดแล้ว' : 'เปิด'}</span>${r.closed_at && me.is_admin ? `<button type="button" class="fin-btn small" data-reopen="${esc(r.period)}">เปิดงวดอีกครั้ง</button>` : ''}</li>`).join('')}</ul>
                ${me.is_manager ? `<form class="fin-close"><div class="fin-grid">${field('ปิดงวด (เดือน)', `<input type="month" name="period" value="${esc(me.today.slice(0, 7))}" required>`)}</div><button class="fin-btn danger" type="submit">ปิดงวด</button></form>` : '<p class="fin-hint">ผู้อำนวยการหรือผู้ดูแลระบบเป็นผู้ปิดงวด</p>'}`;
        };
        range.addEventListener('change', () => guarded(load));
        let entryKey = window.SaanCore.uid(); // one key per entry until it succeeds: a double click or retry replays the same row
        body.querySelector('.fin-entry').addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.currentTarget, sat = toSatang(f.amount.value);
            if (sat === null || sat < 1) return toast('จำนวนเงินไม่ถูกต้อง', true);
            const btn = f.querySelector('button[type=submit]');
            if (btn?.disabled) return;
            if (btn) btn.disabled = true;
            let ok;
            try {
                ok = await guarded(() => ctx.api('/api/finance/ledger', {method: 'POST', idempotencyKey: entryKey, body: {entry_date: f.entry_date.value, kind: f.kind.value, category: f.category.value, amount_satang: sat, description: f.description.value, document_no: f.document_no.value || null}}));
            } finally { if (btn) btn.disabled = false; }
            if (ok) { entryKey = window.SaanCore.uid(); toast('บันทึกแล้ว'); f.reset(); guarded(load); }
        });
        body.addEventListener('click', async e => {
            const del = e.target.closest('[data-del]'), re = e.target.closest('[data-reopen]');
            if (del && window.confirm('ลบรายการนี้ออกจากสมุดเงินสด?')) { if (await guarded(() => ctx.api('/api/finance/ledger/' + del.dataset.del, {method: 'DELETE', body: {version: Number(del.dataset.version)}}), load)) guarded(load); }
            if (re && window.confirm('เปิดงวด ' + re.dataset.reopen + ' อีกครั้ง?')) { if (await guarded(() => ctx.api('/api/finance/periods/' + re.dataset.reopen + '/reopen', {method: 'POST'}))) guarded(periods); }
        });
        body.addEventListener('submit', async e => {
            const f = e.target.closest('.fin-close'); if (!f) return;
            e.preventDefault();
            if (!window.confirm('ปิดงวด ' + f.period.value + ' แล้วจะบันทึกหรือยกเลิกรายการในงวดนี้ไม่ได้')) return;
            if (await guarded(() => ctx.api('/api/finance/periods/' + encodeURIComponent(f.period.value) + '/close', {method: 'POST'}))) { toast('ปิดงวดแล้ว'); guarded(periods); }
        });
        await guarded(load); await guarded(periods);
    }

    // ------------------------------------------------------------ setup
    async function setup(body, ctx, me) {
        const load = async () => {
            const [fees, settings, accList] = await Promise.all([ctx.api('/api/finance/fee-items'), me.is_admin ? ctx.api('/api/finance/settings') : null, ctx.api('/api/finance/accounts')]);
            if (!ctx.alive()) return;
            const accts = accList.data.filter(a => a.is_active);
            body.innerHTML = `<h2>รายการค่าธรรมเนียม</h2><ul class="fin-cards">${fees.data.map(f => `<li data-fee="${esc(f.id)}" data-version="${esc(f.version)}"><span><strong>${esc(f.code)}</strong> ${esc(f.label)} ${esc(fmt(f.default_amount_satang))} บาท ${f.is_active ? '' : '(ปิดใช้)'}</span>
                <button type="button" class="fin-btn small" data-toggle="${esc(f.id)}" data-active="${f.is_active ? 1 : 0}">${f.is_active ? 'ปิดใช้' : 'เปิดใช้'}</button>
                ${accts.length ? `<details style="flex:1 1 100%;padding:0 .7rem .5rem"><summary>บัญชีรับเงินของรายการนี้</summary>${acctBoxes(accts, f.account_ids)}<button type="button" class="fin-btn small" data-saveacc="${esc(f.id)}">บันทึกบัญชีรับเงิน</button></details>` : ''}</li>`).join('')}</ul>
                <form class="fin-fee"><div class="fin-grid">${field('รหัส', '<input name="code" maxlength="40" required>')}${field('ชื่อรายการ', '<input name="label" maxlength="200" required>')}
                ${field('จำนวนเงิน (บาท)', '<input name="amount" inputmode="decimal" required>')}${field('ระดับชั้น (คั่นด้วยจุลภาค เว้นว่าง = ทุกชั้น)', '<input name="grades" maxlength="200">')}</div>
                ${acctBoxes(accts, [])}
                <button class="fin-btn primary" type="submit">เพิ่มรายการ</button></form>
                ${settings ? `<h2>เลขที่เอกสารและเจ้าหน้าที่การเงิน</h2><form class="fin-settings"><div class="fin-grid">${field('คำนำหน้าใบแจ้งหนี้', `<input name="invoice_prefix" maxlength="8" pattern="[A-Za-z0-9]{1,8}" value="${esc(settings.invoice_prefix)}">`)}
                ${field('คำนำหน้าใบเสร็จ', `<input name="receipt_prefix" maxlength="8" pattern="[A-Za-z0-9]{1,8}" value="${esc(settings.receipt_prefix)}">`)}</div>
                <fieldset><legend>ผู้ใช้ที่ทำงานการเงินได้ (นอกจากผู้ดูแลและผู้อำนวยการ)</legend>${settings.candidates.filter(c => c.role !== 'admin' && c.role !== 'principal').map(c => `<label class="fin-check"><input type="checkbox" name="staff" value="${esc(c.id)}"${settings.staff_user_ids.includes(c.id) ? ' checked' : ''}> ${esc(c.name)}</label>`).join('') || '<p class="fin-hint">ยังไม่มีผู้ใช้ครู</p>'}</fieldset>
                <button class="fin-btn primary" type="submit">บันทึกการตั้งค่า</button></form>` : ''}`;
            body.querySelector('.fin-fee').addEventListener('submit', async e => {
                e.preventDefault();
                const f = e.currentTarget, sat = toSatang(f.amount.value);
                if (sat === null) return toast('จำนวนเงินไม่ถูกต้อง', true);
                const grades = f.grades.value.split(',').map(s => s.trim()).filter(Boolean);
                if (await guarded(() => ctx.api('/api/finance/fee-items', {method: 'POST', idempotencyKey: window.SaanCore.uid(), body: {code: f.code.value, label: f.label.value, default_amount_satang: sat, grade_levels: grades.length ? grades : null, account_ids: pickAccts(f)}}))) { toast('เพิ่มรายการแล้ว'); load(); }
            });
            body.querySelector('.fin-settings')?.addEventListener('submit', async e => {
                e.preventDefault();
                const f = e.currentTarget;
                if (await guarded(() => ctx.api('/api/finance/settings', {method: 'PUT', body: {invoice_prefix: f.invoice_prefix.value, receipt_prefix: f.receipt_prefix.value, staff_user_ids: [...f.querySelectorAll('input[name=staff]:checked')].map(c => Number(c.value))}}))) toast('บันทึกการตั้งค่าแล้ว');
            });
        };
        body.addEventListener('click', async e => {
            const sv = e.target.closest('[data-saveacc]');
            if (sv) {
                const row = sv.closest('li');
                if (await guarded(() => ctx.api('/api/finance/fee-items/' + sv.dataset.saveacc, {method: 'PUT', body: {version: Number(row.dataset.version), account_ids: pickAccts(row)}}), load)) { toast('บันทึกบัญชีรับเงินแล้ว'); load(); }
                return;
            }
            const b = e.target.closest('[data-toggle]'); if (!b) return;
            const li = b.closest('li');
            if (await guarded(() => ctx.api('/api/finance/fee-items/' + b.dataset.toggle, {method: 'PUT', body: {version: Number(li.dataset.version), is_active: b.dataset.active !== '1'}}), load)) load();
        });
        await guarded(load);
    }

    window.SaanWork.register('finance', {
        title: 'การเงิน',
        async render(root, ctx) {
            const me = await ctx.api('/api/finance/me');
            if (!ctx.alive()) return;
            const tab = ctx.parts[0] === 'student' ? '' : (ctx.parts[0] || '');
            if (!me.can_access) {
                root.innerHTML = `<section class="fin glass" role="alert"><h1>การเงิน</h1><p>${esc(me.message || 'ไม่มีสิทธิ์ใช้งานส่วนนี้')}</p></section>`;
                return;
            }
            const {body} = shell(root, me, tab);
            if (ctx.parts[0] === 'student' && ctx.parts[1]) return student(body, ctx, ctx.parts[1], me);
            if (tab === 'bulk') return guarded(() => bulk(body, ctx, me));
            if (['claims', 'rooms', 'accounts'].includes(tab) && window.SaanFinanceFees) return guarded(() => window.SaanFinanceFees.render(tab, body, ctx, me, {fmt, plain, toSatang, toast, guarded, field}));
            if (tab === 'daily') return daily(body, ctx);
            if (tab === 'ledger') return ledger(body, ctx, me);
            if (tab === 'setup' && me.is_manager) return setup(body, ctx, me);
            return search(body, ctx);
        },
    });
})();
