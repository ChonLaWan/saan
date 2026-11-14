'use strict';
// finance module, F3 (internal only): verification queue for guardian slips (#finance/claims), receiving accounts with QR (#finance/accounts),
// outstanding per classroom (#finance/rooms). Loaded after finance.js, which passes its helpers in. Every value goes through esc().
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const CLAIM = {pending: 'รอตรวจสอบ', approved: 'อนุมัติแล้ว', rejected: 'ไม่รับ', withdrawn: 'ผู้ปกครองถอนคืน', voided: 'ยกเลิกใบเสร็จแล้ว'};
    const link = path => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '') + path;
    const when = s => String(s || '').replace('T', ' ').slice(0, 16);
    const uid = () => window.SaanCore.uid();

    // ------------------------------------------------------------ verification queue
    async function claims(body, ctx, h) {
        const id = Number(ctx.parts[1] || 0);
        if (id) return claimDetail(body, ctx, h, id);
        const lk = await ctx.api('/api/finance/lookups');
        if (!ctx.alive()) return;
        body.innerHTML = `<form class="fin-grid" id="fc-filter">${h.field('สถานะ', `<select name="status"><option value="pending">รอตรวจสอบ</option><option value="approved">อนุมัติแล้ว</option><option value="rejected">ไม่รับ</option><option value="withdrawn">ถอนคืน</option><option value="voided">ยกเลิกใบเสร็จแล้ว</option><option value="all">ทั้งหมด</option></select>`)}
            ${h.field('ห้องเรียน', `<select name="classroom_id"><option value="">ทุกห้อง</option>${lk.classrooms.map(c => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('')}</select>`)}
            ${h.field('ตั้งแต่วันที่', '<input type="date" name="from">')}${h.field('ถึงวันที่', '<input type="date" name="to">')}</form>
            <div id="fc-list" aria-live="polite"></div>
            <details class="fin-card"><summary>บันทึกสลิปแทนผู้ปกครอง (ชำระที่โรงเรียน)</summary><div id="fc-staff"></div></details>`;
        const f = body.querySelector('#fc-filter'), out = body.querySelector('#fc-list');
        let seq = 0;
        const run = async () => {
            const mine = ++seq, qs = new URLSearchParams();
            for (const [k, v] of new FormData(f)) if (v) qs.set(k, v);
            const res = await h.guarded(() => ctx.api('/api/finance/claims?' + qs));
            if (!res || mine !== seq || !ctx.alive()) return;
            out.innerHTML = res.data.length ? `<ul class="fin-cards">${res.data.map(c => `<li><a href="#finance/claims/${esc(c.id)}"><strong>${esc(c.student_name)}</strong>
                <span>${esc(c.student_code)} ${esc(c.classroom || '')} · ใบแจ้งหนี้ ${esc(c.invoice_no)}</span>
                <span>แจ้งโอน ${esc(h.fmt(c.amount_satang))} บาท เมื่อ ${esc(when(c.transferred_at))} · สลิป ${esc(c.slip_count)} ไฟล์${c.source === 'staff' ? ' · เจ้าหน้าที่บันทึกแทน' : ''}</span>
                <span class="fin-badge ${esc(c.status)}">${esc(CLAIM[c.status] || c.status)}</span></a></li>`).join('')}</ul><p class="fin-hint">ทั้งหมด ${esc(res.total)} รายการ</p>` : '<p class="fin-hint">ไม่มีรายการ</p>';
        };
        f.addEventListener('change', run);
        await run();
        staffForm(body.querySelector('#fc-staff'), ctx, h);
    }

    async function staffForm(box, ctx, h) {
        const acc = await h.guarded(() => ctx.api('/api/finance/accounts'));
        if (!acc || !ctx.alive()) return;
        box.innerHTML = `<form class="fin-grid" id="fc-new">${h.field('เลขที่ใบแจ้งหนี้', '<input name="invoice_no" required maxlength="40">')}${h.field('จำนวนเงินที่โอน (บาท)', '<input name="amount" inputmode="decimal" required>')}
            ${h.field('วันเวลาที่โอน', '<input type="datetime-local" name="transferred_at" required>')}
            ${h.field('บัญชีที่โอนเข้า', `<select name="account_id"><option value="">ไม่ระบุ</option>${acc.data.filter(a => a.is_active).map(a => `<option value="${esc(a.id)}">${esc(a.bank_name)} ${esc(a.account_name)}</option>`).join('')}</select>`)}
            ${h.field('ไฟล์สลิป (PNG, JPEG, WebP หรือ PDF ไม่เกิน 3 ไฟล์)', '<input type="file" name="slips" accept="image/png,image/jpeg,image/webp,application/pdf" multiple required>')}
            <button class="fin-btn primary" type="submit">บันทึกสลิป</button></form>`;
        box.querySelector('#fc-new').addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.currentTarget, sat = h.toSatang(f.amount.value);
            if (sat === null) return h.toast('จำนวนเงินไม่ถูกต้อง', true);
            const list = await h.guarded(() => ctx.api('/api/finance/invoices?all_terms=1&invoice_no=' + encodeURIComponent(f.invoice_no.value.trim())));
            const inv = list?.data?.find(i => i.invoice_no === f.invoice_no.value.trim());
            if (!inv) return h.toast('ไม่พบใบแจ้งหนี้เลขที่นี้', true);
            const fd = new FormData();
            fd.set('amount_satang', String(sat)); fd.set('transferred_at', f.transferred_at.value);
            if (f.account_id.value) fd.set('account_id', f.account_id.value);
            [...f.slips.files].forEach(file => fd.append('slips[]', file));
            const res = await h.guarded(() => ctx.api('/api/finance/invoices/' + inv.id + '/claims', {method: 'POST', body: fd, idempotencyKey: uid()}));
            if (res) { h.toast('บันทึกสลิปแล้ว รอตรวจสอบในรายการ'); location.hash = '#finance/claims/' + res.id; }
        });
    }

    async function claimDetail(body, ctx, h, id) {
        const load = async () => {
            const d = await h.guarded(() => ctx.api('/api/finance/claims/' + id));
            if (!d || !ctx.alive()) return;
            const c = d.claim, pending = c.status === 'pending';
            body.innerHTML = `<p><a href="#finance/claims">กลับไปรายการ</a></p><section class="fin-card"><h2>${esc(d.invoice.student_name)} (${esc(d.invoice.student_code)})</h2>
                <p>ใบแจ้งหนี้ ${esc(d.invoice.invoice_no)} · ค้างชำระ ${esc(h.fmt(d.outstanding_satang))} บาท</p>
                <p>แจ้งโอน ${esc(h.fmt(c.amount_satang))} บาท เมื่อ ${esc(when(c.transferred_at))}${c.account_label ? ' เข้า ' + esc(c.account_label) : ''}${c.guardian_name ? ' · โดย ' + esc(c.guardian_name) : ' · เจ้าหน้าที่บันทึกแทน'}</p>
                <p><span class="fin-badge ${esc(c.status)}">${esc(CLAIM[c.status] || c.status)}</span>${c.reject_reason ? ' เหตุผล: ' + esc(c.reject_reason) : ''}</p>
                ${(c.possible_duplicates || []).length ? `<p class="fin-hint" role="alert">คำเตือน: มีรายการแจ้งชำระอื่นที่ยอด บัญชี และเวลาโอนใกล้เคียงกัน (รายการที่ ${esc(c.possible_duplicates.join(', '))}) อาจเป็นการโอนครั้งเดียวกัน กรุณาตรวจกับรายการเดินบัญชีก่อนอนุมัติ</p>` : ''}
                <div class="fin-slips">${d.slips.map(s => s.mime === 'application/pdf' ? `<a class="fin-btn small" href="${esc(s.url)}" target="_blank" rel="noopener">เปิดสลิป PDF</a>` : `<a href="${esc(s.url)}" target="_blank" rel="noopener"><img src="${esc(s.url)}" alt="สลิปการโอน" loading="lazy" style="max-width:100%;max-height:28rem"></a>`).join('') || '<p class="fin-hint">ไม่มีไฟล์สลิปแล้ว</p>'}</div>
                ${pending ? `<form id="fc-decide" class="fin-grid">${h.field('จำนวนที่รับ (บาท)', `<input name="amount" inputmode="decimal" value="${esc(h.plain(Math.min(c.amount_satang, d.outstanding_satang)))}">`)}
                ${h.field('วันที่รับเงิน (เว้นว่าง = วันโอน ถ้างวดของวันโอนปิดบัญชีแล้วจะใช้วันนี้)', `<input type="date" name="paid_on" max="${esc(window.SaanCore.today())}">`)}
                ${h.field('เหตุผล (ต้องกรอกเมื่อไม่รับ)', '<input name="reason" maxlength="300">')}
                <div><button class="fin-btn primary" type="button" data-act="approve">อนุมัติและออกใบเสร็จ</button> <button class="fin-btn danger" type="button" data-act="reject">ไม่รับ</button></div></form>` : ''}
                ${c.payment_id ? `<p><a class="fin-btn small" href="${esc(link('/api/finance/payments/' + c.payment_id + '/receipt'))}" target="_blank" rel="noopener">เปิดใบเสร็จ</a></p>` : ''}</section>`;
            body.querySelector('#fc-decide')?.addEventListener('click', async e => {
                const b = e.target.closest('[data-act]'); if (!b) return;
                const f = e.currentTarget; b.disabled = true;
                try {
                    if (b.dataset.act === 'approve') {
                        const sat = h.toSatang(f.amount.value);
                        if (sat === null || sat < 1) return h.toast('จำนวนเงินไม่ถูกต้อง', true);
                        const dup = (c.possible_duplicates || []).length > 0;
                        if (dup && !window.confirm('รายการนี้อาจซ้ำกับรายการแจ้งชำระอื่น (ยอด บัญชี และเวลาโอนใกล้เคียงกัน) ตรวจกับรายการเดินบัญชีแล้วและต้องการอนุมัติต่อหรือไม่')) return;
                        const res = await h.guarded(() => ctx.api('/api/finance/claims/' + id + '/approve', {method: 'POST', idempotencyKey: uid(), body: {version: c.version, amount_satang: sat, ...(dup ? {confirm_duplicate: true} : {}), ...(f.paid_on.value ? {paid_on: f.paid_on.value} : {})}}), load);
                        if (res) h.toast('อนุมัติและออกใบเสร็จแล้ว');
                    } else {
                        const reason = f.reason.value.trim();
                        if (reason.length < 3) return h.toast('กรุณากรอกเหตุผลที่ไม่รับ', true);
                        const res = await h.guarded(() => ctx.api('/api/finance/claims/' + id + '/reject', {method: 'POST', idempotencyKey: uid(), body: {version: c.version, reason}}), load);
                        if (res) h.toast('บันทึกการไม่รับแล้ว แจ้งผู้ปกครองในพอร์ทัล');
                    }
                } finally { b.disabled = false; }
                load();
            });
        };
        await load();
    }

    // ------------------------------------------------------------ receiving accounts
    async function accounts(body, ctx, h, me) {
        const load = async () => {
            const res = await h.guarded(() => ctx.api('/api/finance/accounts'));
            if (!res || !ctx.alive()) return;
            const mgr = !!me.is_manager;
            body.innerHTML = `<p class="fin-hint">เลขบัญชีแสดงให้ผู้ปกครองเห็นครบทุกหลักเพื่อใช้โอนเงิน การแก้เลขบัญชีมีบันทึกการแก้ไข ตรวจเลขให้ถูกต้องก่อนบันทึก${mgr ? '' : ' (เพิ่มหรือแก้ไขบัญชีได้เฉพาะผู้อำนวยการหรือผู้ดูแลระบบ)'}</p>
                <ul class="fin-cards">${res.data.map(a => `<li data-id="${esc(a.id)}" data-version="${esc(a.version)}"><a href="#finance/accounts"><strong>${esc(a.bank_name)} ${esc(a.account_name)}</strong>
                <span>${esc(a.account_number)}${a.branch ? ' · สาขา' + esc(a.branch) : ''} · ลำดับ ${esc(a.sort_order)} · ${a.is_active ? 'ใช้งาน' : 'ปิดใช้งาน'}</span></a>
                ${mgr ? `<span>${a.has_qr ? `<img src="${esc(link('/api/finance/accounts/' + a.id + '/qr'))}" alt="QR รับเงิน" width="72" height="72"> <button class="fin-btn small danger" data-qrdel="${esc(a.id)}" type="button">ลบ QR</button>` : 'ยังไม่มี QR'}
                <label class="fin-btn small">อัปโหลด QR<input type="file" accept="image/png,image/jpeg,image/webp" data-qr="${esc(a.id)}" hidden></label>
                <button class="fin-btn small" data-toggle="${esc(a.id)}" data-active="${a.is_active ? 1 : 0}" type="button">${a.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน'}</button></span>
                <details style="flex:1 1 100%;padding:0 .7rem .5rem"><summary>แก้ไขข้อมูลบัญชี</summary><form class="fin-grid" data-edit="${esc(a.id)}" data-number="${esc(a.account_number)}">
                ${h.field('ธนาคาร', `<select name="bank_code">${Object.entries(res.banks).map(([k, v]) => `<option value="${esc(k)}"${k === a.bank_code ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select>`)}
                ${h.field('ชื่อบัญชี', `<input name="account_name" required maxlength="160" value="${esc(a.account_name)}">`)}${h.field('เลขบัญชี (10-15 หลัก)', `<input name="account_number" required inputmode="numeric" maxlength="30" value="${esc(a.account_number)}">`)}
                ${h.field('สาขา (ไม่บังคับ)', `<input name="branch" maxlength="120" value="${esc(a.branch || '')}">`)}${h.field('ลำดับที่แสดง', `<input name="sort_order" type="number" min="0" max="9999" value="${esc(a.sort_order)}">`)}
                <button class="fin-btn primary" type="submit">บันทึกการแก้ไข</button></form></details>` : ''}</li>`).join('') || '<li><span>ยังไม่มีบัญชีรับเงิน</span></li>'}</ul>
                ${mgr ? `<h2>เพิ่มบัญชีรับเงิน</h2><form id="fa-new" class="fin-grid">${h.field('ธนาคาร', `<select name="bank_code">${Object.entries(res.banks).map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join('')}</select>`)}
                ${h.field('ชื่อบัญชี', '<input name="account_name" required maxlength="160">')}${h.field('เลขบัญชี (10-15 หลัก)', '<input name="account_number" required inputmode="numeric" maxlength="30">')}
                ${h.field('สาขา (ไม่บังคับ)', '<input name="branch" maxlength="120">')}${h.field('ลำดับที่แสดง', '<input name="sort_order" type="number" min="0" max="9999" value="0">')}
                <button class="fin-btn primary" type="submit">เพิ่มบัญชี</button></form>` : ''}`;
            body.querySelector('#fa-new')?.addEventListener('submit', async e => {
                e.preventDefault();
                const f = e.currentTarget;
                if (await h.guarded(() => ctx.api('/api/finance/accounts', {method: 'POST', idempotencyKey: uid(), body: {bank_code: f.bank_code.value, account_name: f.account_name.value, account_number: f.account_number.value, branch: f.branch.value || null, sort_order: Number(f.sort_order.value || 0)}}))) { h.toast('เพิ่มบัญชีแล้ว'); load(); }
            });
        };
        body.addEventListener('click', async e => {
            const t = e.target.closest('[data-toggle],[data-qrdel]'); if (!t) return;
            const li = t.closest('li'), v = Number(li.dataset.version);
            const ok = t.dataset.toggle
                ? await h.guarded(() => ctx.api('/api/finance/accounts/' + t.dataset.toggle, {method: 'PUT', body: {version: v, is_active: t.dataset.active !== '1'}}), load)
                : await h.guarded(() => ctx.api('/api/finance/accounts/' + t.dataset.qrdel + '/qr', {method: 'DELETE', body: {version: v}}), load);
            if (ok) load();
        });
        body.addEventListener('submit', async e => {
            const f = e.target.closest('form[data-edit]'); if (!f) return;
            e.preventDefault();
            const num = f.account_number.value.trim();
            if (num !== f.dataset.number && !window.confirm('เปลี่ยนเลขบัญชีรับเงิน ผู้ปกครองจะเห็นเลขใหม่ทันทีและโอนเงินเข้าบัญชีนี้ กรุณาตรวจเลขให้ถูกต้องอีกครั้ง ยืนยันบันทึก?')) return;
            const body2 = {version: Number(f.closest('li').dataset.version), bank_code: f.bank_code.value, account_name: f.account_name.value, account_number: num, branch: f.branch.value.trim() || null, sort_order: Number(f.sort_order.value || 0)};
            if (await h.guarded(() => ctx.api('/api/finance/accounts/' + f.dataset.edit, {method: 'PUT', body: body2}), load)) { h.toast('บันทึกการแก้ไขบัญชีแล้ว'); load(); }
        });
        body.addEventListener('change', async e => {
            const i = e.target.closest('input[data-qr]'); if (!i || !i.files[0]) return;
            const fd = new FormData();
            fd.set('version', i.closest('li').dataset.version); fd.set('qr', i.files[0]);
            if (await h.guarded(() => ctx.api('/api/finance/accounts/' + i.dataset.qr + '/qr', {method: 'POST', body: fd}), load)) { h.toast('อัปโหลด QR แล้ว'); load(); }
        });
        await load();
    }

    // ------------------------------------------------------------ outstanding per classroom
    async function rooms(body, ctx, h) {
        const room = Number(ctx.parts[1] || 0);
        if (room) {
            const res = await h.guarded(() => ctx.api('/api/finance/students?only_owing=1&classroom_id=' + room));
            if (!res || !ctx.alive()) return;
            body.innerHTML = `<p><a href="#finance/rooms">กลับไปสรุปรายห้อง</a></p>` + (res.data.length ? `<ul class="fin-cards">${res.data.map(s => `<li><a href="#finance/student/${esc(s.id)}"><strong>${esc(s.name)}</strong><span>${esc(s.code)} ${esc(s.classroom || '')}</span>
                <span class="fin-owed due">ค้างชำระ ${esc(h.fmt(s.balance_satang))} บาท (${esc(s.open_invoices)} ใบ)</span></a></li>`).join('')}</ul>` : '<p class="fin-hint">ไม่มีนักเรียนค้างชำระในห้องนี้</p>');
            return;
        }
        const res = await h.guarded(() => ctx.api('/api/finance/classrooms/outstanding'));
        if (!res || !ctx.alive()) return;
        body.innerHTML = `<p class="fin-ok">ยอดค้างชำระรวม ${esc(h.fmt(res.total_owed_satang))} บาท</p><ul class="fin-cards">${res.data.map(c => c.id === null ? `<li><span><strong>${esc(c.name)}</strong></span><span>ค้างชำระ ${esc(c.owing_students)} คน (${esc(c.open_invoices)} ใบ) · นักเรียนที่ยังไม่ได้จัดห้อง</span><span class="fin-owed due">ค้างชำระ ${esc(h.fmt(c.owed_satang))} บาท</span></li>`
            : `<li><a href="#finance/rooms/${esc(c.id)}"><strong>${esc(c.name)}</strong>
            <span>นักเรียน ${esc(c.students)} คน · ค้างชำระ ${esc(c.owing_students)} คน (${esc(c.open_invoices)} ใบ)</span><span class="fin-owed${c.owed_satang > 0 ? ' due' : ''}">ค้างชำระ ${esc(h.fmt(c.owed_satang))} บาท</span></a></li>`).join('')}</ul>`;
    }

    window.SaanFinanceFees = {
        render(tab, body, ctx, me, helpers) {
            if (tab === 'claims') return claims(body, ctx, helpers);
            if (tab === 'accounts') return accounts(body, ctx, helpers, me);
            return rooms(body, ctx, helpers);
        },
    };
})();
