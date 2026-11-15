'use strict';
// payslip module: staff page #payslip for the /work shell. Everyone sees their own slips and 50 tawi; payroll officers manage periods, imports, payees and
// certificates; admin and principal see summaries. Amounts are integer satang end to end; text typed by the user is sent as text and parsed by the server.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const fmt = s => {
        const n = Math.abs(Number(s) || 0);
        return (s < 0 ? '-' : '') + Math.floor(n / 100).toLocaleString('en-US') + '.' + String(n % 100).padStart(2, '0');
    };
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    };
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const link = path => base() + path;
    const idem = () => (window.crypto?.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
    const field = (label, control) => `<label class="psl-field"><span>${esc(label)}</span>${control}</label>`;
    const STATUS = {draft: 'ฉบับร่าง', published: 'เผยแพร่แล้ว', cancelled: 'ยกเลิก', active: 'ใช้งาน', voided: 'ยกเลิกแล้ว', issued: 'ออกแล้ว', stale: 'ต้องออกใหม่', missing: 'ยังไม่ออก', pdf_partial: 'มีงวดไฟล์ PDF', unreadable: 'ถอดรหัสสลิปไม่ได้'};
    const REASON = {unmatched: 'ไม่พบผู้รับเงิน', duplicate_in_file: 'รหัสซ้ำในไฟล์', duplicate_existing: 'มีสลิปในงวดนี้แล้ว', own_payee: 'เป็นผู้รับเงินของบัญชีที่ล็อกอินอยู่ ทำสลิปของตนเองไม่ได้ ให้เจ้าหน้าที่อีกคนนำเข้า', invalid: 'จำนวนเงินไม่ถูกต้อง', mismatch: 'ยอดสุทธิไม่เท่ากับรายรับลบรายการหัก'};
    const badge = st => `<span class="psl-badge ${esc(st)}">${esc(STATUS[st] || st)}</span>`;
    const year = y => Number(y) + 543;

    async function guarded(fn, reload) {
        try { return await fn(); } catch (e) {
            if (e?.name === 'AbortError') return null;
            if (e?.status === 409 && reload) { toast((e.message || 'ข้อมูลเปลี่ยนไปแล้ว') + ' (โหลดใหม่แล้ว)', true); reload(); } else toast(e?.message || 'เกิดข้อผิดพลาด', true);
            return null;
        }
    }

    function tabsFor(me) {
        const t = [['', 'สลิปของฉัน'], ['certs', '50 ทวิของฉัน']];
        if (me.can_summary || me.is_officer) t.push(['periods', 'งวดเงินเดือน']);
        if (me.is_officer) t.push(['payees', 'ผู้รับเงิน'], ['setup', 'จับคู่คอลัมน์']);
        if (me.can_summary || me.is_officer) t.push(['tax', '50 ทวิทั้งโรงเรียน']);
        return t;
    }

    function shell(root, me, tab) {
        const host = document.createElement('div');
        const tabs = tabsFor(me).map(([k, label]) => `<a href="#payslip${k ? '/' + k : ''}"${k === tab ? ' aria-current="page"' : ''}>${esc(label)}</a>`).join('');
        const svc = (me.links || []).filter(l => /^https:\/\//i.test(l.url));
        const services = svc.length ? `<section class="psl-services" aria-labelledby="psl-svc-h"><h2 id="psl-svc-h">บริการบุคลากร</h2><ul>${svc.map(l => `<li><a href="${esc(l.url)}" target="_blank" rel="noopener noreferrer">${esc(l.label)}</a></li>`).join('')}</ul></section>` : '';
        host.innerHTML = `<section class="psl glass"><h1>สลิปเงินเดือน</h1><nav class="psl-tabs" aria-label="เมนูสลิปเงินเดือน">${tabs}</nav>${tab === '' ? services : ''}<div class="psl-body" id="psl-body"></div></section>`;
        root.append(host);
        return host.querySelector('#psl-body');
    }

    // ------------------------------------------------------------ my slips
    async function mySlips(body, ctx, me) {
        if (!me.has_payee) {
            body.innerHTML = `<p class="psl-hint">ยังไม่มีสลิปเงินเดือนของบัญชีนี้ในระบบโรงเรียน สลิปจะแสดงเมื่อเจ้าหน้าที่การเงินผูกบัญชีของท่านกับรหัสผู้รับเงินและเผยแพร่งวดแล้ว ผู้รับบำนาญและข้าราชการที่รับเงินจากส่วนกลาง ใช้ลิงก์ในบล็อก บริการบุคลากร ด้านบน (ถ้าโรงเรียนตั้งไว้)</p>`;
            return;
        }
        const d = await ctx.api('/api/payslip/my/slips');
        if (!ctx.alive()) return;
        body.innerHTML = (d.data.length ? `<ul class="psl-cards">${d.data.map(s => `<li><div><strong>${esc(s.title)}</strong><span class="psl-sub">จ่ายวันที่ ${esc(s.pay_date_th)}${s.kind === 'pdf' ? ' (ไฟล์ PDF)' : ''}</span></div>
            <div class="psl-actions"><button class="psl-btn small primary" type="button" data-open="${esc(s.id)}" data-kind="${esc(s.kind)}">เปิดดู</button></div></li>`).join('')}</ul>` : '<p class="psl-hint">ยังไม่มีสลิปที่เผยแพร่</p>') + '<div id="psl-detail" aria-live="polite"></div>';
        body.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => guarded(() => openSlip(body.querySelector('#psl-detail'), ctx, b.dataset.open, b.dataset.kind))));
    }

    async function openSlip(box, ctx, id, kind) {
        if (kind === 'pdf') {
            box.innerHTML = `<p class="psl-hint">สลิปนี้เป็นไฟล์ PDF ที่เจ้าหน้าที่อัปโหลด</p><a class="psl-btn primary" href="${esc(link('/api/payslip/my/slips/' + id + '/download'))}">ดาวน์โหลด PDF</a>`;
            return;
        }
        const s = await ctx.api('/api/payslip/my/slips/' + encodeURIComponent(id));
        if (!ctx.alive()) return;
        const d = s.data;
        if (!d) { box.innerHTML = '<p class="psl-warn">อ่านข้อมูลสลิปไม่ได้ กรุณาติดต่อเจ้าหน้าที่การเงิน</p>'; return; }
        const rows = list => list.map(it => `<tr><td>${esc(it[0])}</td><td class="n">${esc(fmt(it[1]))}</td></tr>`).join('');
        box.innerHTML = `<h2>${esc(s.period.title)}</h2><p class="psl-sub">จ่ายวันที่ ${esc(s.period.pay_date_th)}${d.bank ? ' / บัญชี ' + esc(d.bank_name || '') + ' ' + esc(d.bank) : ''}</p>
            <table class="psl-lines"><caption class="psl-sub" style="text-align:left">รายรับ (บาท)</caption><tbody>${rows(d.e)}<tr class="sum"><td>รวมรายรับ</td><td class="n">${esc(fmt(d.gross))}</td></tr></tbody></table>
            <table class="psl-lines"><caption class="psl-sub" style="text-align:left">รายการหัก (บาท)</caption><tbody>${rows(d.d)}${d.tax ? `<tr><td>ภาษีหัก ณ ที่จ่าย</td><td class="n">${esc(fmt(d.tax))}</td></tr>` : ''}${d.sso ? `<tr><td>ประกันสังคม</td><td class="n">${esc(fmt(d.sso))}</td></tr>` : ''}${d.pf ? `<tr><td>กองทุนสำรองเลี้ยงชีพ</td><td class="n">${esc(fmt(d.pf))}</td></tr>` : ''}<tr class="sum"><td>รวมรายการหัก</td><td class="n">${esc(fmt(d.ded))}</td></tr></tbody></table>
            <p><strong>รับสุทธิ ${esc(fmt(d.net))} บาท</strong></p>
            <a class="psl-btn primary" target="_blank" rel="noopener" href="${esc(link('/api/payslip/my/slips/' + id + '/print'))}">พิมพ์หรือบันทึกเป็น PDF</a>`;
        box.scrollIntoView?.({block: 'nearest'});
    }

    // ------------------------------------------------------------ my 50 tawi
    async function myCerts(body, ctx, me) {
        if (!me.has_payee) { body.innerHTML = '<p class="psl-hint">ยังไม่มีข้อมูลผู้รับเงินของบัญชีนี้</p>'; return; }
        const d = await ctx.api('/api/payslip/my/certificates');
        if (!ctx.alive()) return;
        body.innerHTML = d.data.length ? `<ul class="psl-cards">${d.data.map(c => `<li><div><strong>หนังสือรับรอง 50 ทวิ ปี ${esc(c.year_be)}</strong></div><div class="psl-actions">
            <button class="psl-btn small" type="button" data-cert="${esc(c.id)}">ดูสรุป</button>
            <a class="psl-btn small primary" target="_blank" rel="noopener" href="${esc(link('/api/payslip/my/certificates/' + c.id + '/print'))}">พิมพ์</a></div></li>`).join('')}</ul><div id="psl-detail" aria-live="polite"></div>`
            : '<p class="psl-hint">ยังไม่มีหนังสือรับรอง 50 ทวิ เจ้าหน้าที่การเงินจะออกให้หลังสิ้นปี</p>';
        body.querySelectorAll('[data-cert]').forEach(b => b.addEventListener('click', () => guarded(async () => {
            const c = await ctx.api('/api/payslip/my/certificates/' + encodeURIComponent(b.dataset.cert));
            body.querySelector('#psl-detail').innerHTML = `<dl class="psl-stats"><div><dt>เงินได้ที่จ่าย</dt><dd>${esc(fmt(c.gross))}</dd></div><div><dt>ภาษีที่หัก</dt><dd>${esc(fmt(c.tax))}</dd></div>
                <div><dt>ประกันสังคม</dt><dd>${esc(fmt(c.sso))}</dd></div><div><dt>กองทุนสำรองเลี้ยงชีพ</dt><dd>${esc(fmt(c.pf))}</dd></div></dl>
                <p class="psl-sub">เลขประจำตัว ${esc(c.payee.national_id || '-')} (เลขเต็มอยู่บนฉบับพิมพ์)</p>`;
        })));
    }

    // ------------------------------------------------------------ periods
    async function periods(body, ctx, me) {
        const d = await ctx.api('/api/payslip/periods');
        if (!ctx.alive()) return;
        const form = me.is_officer ? `<details class="psl-field"><summary class="psl-btn small">สร้างงวดใหม่</summary><form id="psl-new" class="psl-grid">
            ${field('ปี (พ.ศ.)', '<input name="year" inputmode="numeric" required maxlength="4" value="' + year(new Date().getFullYear()) + '">')}
            ${field('เดือน (1-12)', '<input name="month" inputmode="numeric" required maxlength="2" value="' + (new Date().getMonth() + 1) + '">')}
            ${field('ชื่อเรียกงวด (ไม่บังคับ เช่น โบนัส)', '<input name="label" maxlength="120">')}
            ${field('วันที่จ่าย', '<input name="pay_date" type="date" required>')}
            <button class="psl-btn primary" type="submit">สร้างงวด</button></form></details>` : '';
        body.innerHTML = form + (d.data.length ? `<ul class="psl-cards">${d.data.map(p => `<li><div><strong>${esc(p.title)}</strong> ${badge(p.status)}
            <span class="psl-sub">จ่าย ${esc(p.pay_date_th)} / ${esc(p.slips)} สลิป${p.voided ? ' (ยกเลิก ' + esc(p.voided) + ')' : ''}${p.totals ? ' / สุทธิรวม ' + esc(fmt(p.totals.net)) + ' บาท' : ''}</span></div>
            ${me.is_officer ? `<a class="psl-btn small" href="#payslip/period/${esc(p.id)}">จัดการ</a>` : ''}</li>`).join('')}</ul>` : '<p class="psl-hint">ยังไม่มีงวด</p>');
        body.querySelector('#psl-new')?.addEventListener('submit', async ev => {
            ev.preventDefault();
            const f = new FormData(ev.target);
            await guarded(async () => {
                const p = await ctx.api('/api/payslip/periods', {method: 'POST', idempotencyKey: idem(), body: {year: Number(f.get('year')) - 543, month: Number(f.get('month')), label: String(f.get('label') || ''), pay_date: String(f.get('pay_date'))}});
                location.hash = '#payslip/period/' + p.id;
            });
        });
    }

    async function periodDetail(body, ctx, me, id) {
        const d = await ctx.api('/api/payslip/periods/' + encodeURIComponent(id));
        if (!ctx.alive()) return;
        const p = d.period, t = p.totals || {};
        const draft = p.status === 'draft', live = p.status !== 'cancelled';
        const reload = () => window.SaanWork.go('payslip/period/' + id);
        body.innerHTML = `<p><a href="#payslip/periods">&larr; กลับไปรายการงวด</a></p><h2>${esc(p.title)} ${badge(p.status)}</h2>
            <p class="psl-sub">จ่ายวันที่ ${esc(p.pay_date_th)}${p.cancel_reason ? ' / เหตุผลที่ยกเลิก ' + esc(p.cancel_reason) : ''}</p>
            <dl class="psl-stats"><div><dt>สลิปที่ใช้งาน</dt><dd>${esc(p.slips)}</dd></div><div><dt>รายรับรวม</dt><dd>${esc(fmt(t.gross || 0))}</dd></div><div><dt>รายการหักรวม</dt><dd>${esc(fmt(t.ded || 0))}</dd></div><div><dt>สุทธิรวม</dt><dd>${esc(fmt(t.net || 0))}</dd></div></dl>
            ${draft ? `<fieldset><legend>นำเข้าจากไฟล์ CSV/XLSX</legend><p class="psl-hint">ใช้การจับคู่คอลัมน์ที่ตั้งไว้ในแท็บ จับคู่คอลัมน์ ระบบจะแสดงหน้าตรวจก่อนยืนยัน</p>
                <input type="file" id="psl-file" accept=".csv,.xlsx,text/csv"><div class="psl-actions"><button class="psl-btn primary" type="button" id="psl-check">ตรวจไฟล์</button></div><div id="psl-check-out" aria-live="polite"></div></fieldset>
                <fieldset><legend>หรืออัปโหลด PDF รายคน</legend><p class="psl-hint">ตั้งชื่อไฟล์เป็นรหัสผู้รับเงิน เช่น T001.pdf (สูงสุด 60 ไฟล์ต่อครั้ง)</p>
                <input type="file" id="psl-pdfs" accept="application/pdf,.pdf" multiple><div class="psl-actions"><button class="psl-btn" type="button" id="psl-pdf-up">อัปโหลด PDF</button></div><div id="psl-pdf-out" aria-live="polite"></div></fieldset>
                <fieldset><legend>เผยแพร่หรือยกเลิกงวด</legend><label class="psl-check"><input type="checkbox" id="psl-notify"> ส่งอีเมลแจ้งผู้รับ (ไม่มีจำนวนเงินในอีเมล)</label>
                <div class="psl-actions"><button class="psl-btn primary" type="button" id="psl-publish">เผยแพร่งวด</button><button class="psl-btn danger" type="button" id="psl-cancel">ยกเลิกทั้งงวด</button></div></fieldset>` : ''}
            <h3>สลิปในงวด</h3>${d.slips.length ? `<ul class="psl-cards">${d.slips.map(s => `<li><div><strong>${esc(s.name)}</strong> ${badge(s.status)}<span class="psl-sub">รหัส ${esc(s.payee_code)} / ${s.kind === 'pdf' ? 'ไฟล์ PDF' : 'สุทธิ ' + esc(fmt(s.net || 0)) + ' บาท'}${s.void_reason ? ' / ' + esc(s.void_reason) : ''}</span></div>
            ${live && s.status === 'active' ? `<div class="psl-actions"><button class="psl-btn small danger" type="button" data-void="${esc(s.id)}" data-v="${esc(s.version)}">ยกเลิก</button><button class="psl-btn small" type="button" data-rep="${esc(s.id)}" data-v="${esc(s.version)}" data-kind="${esc(s.kind)}">แทนที่</button></div>` : ''}</li>`).join('')}</ul>` : '<p class="psl-hint">ยังไม่มีสลิป</p>'}
            <div id="psl-rep" aria-live="polite"></div>`;
        const ask = (m) => (window.prompt(m) || '').trim();
        body.querySelector('#psl-check')?.addEventListener('click', () => guarded(async () => {
            const f = body.querySelector('#psl-file').files[0]; if (!f) { toast('เลือกไฟล์ก่อน', true); return; }
            const fd = new FormData(); fd.append('file', f);
            const c = await ctx.api('/api/payslip/periods/' + id + '/imports', {method: 'POST', body: fd, idempotencyKey: idem()});
            checkScreen(body.querySelector('#psl-check-out'), ctx, c, reload);
        }));
        body.querySelector('#psl-pdf-up')?.addEventListener('click', () => guarded(async () => {
            const fs = body.querySelector('#psl-pdfs').files; if (!fs.length) { toast('เลือกไฟล์ PDF ก่อน', true); return; }
            const fd = new FormData(); [...fs].forEach(f => fd.append('files[]', f));
            const r = await ctx.api('/api/payslip/periods/' + id + '/pdfs', {method: 'POST', body: fd, idempotencyKey: idem()});
            body.querySelector('#psl-pdf-out').innerHTML = `<p class="psl-ok">เก็บแล้ว ${esc(r.stored)} ไฟล์</p><ul class="psl-cards">${r.files.map(x => `<li><div><strong>${esc(x.name)}</strong><span class="psl-sub">${esc(x.result === 'stored' ? 'เก็บแล้ว' : x.message || x.result)}</span></div></li>`).join('')}</ul>`;
            setTimeout(reload, 1500);
        }));
        body.querySelector('#psl-publish')?.addEventListener('click', () => guarded(async () => {
            if (!window.confirm('เผยแพร่งวดนี้ ผู้รับจะเห็นสลิปทันที')) return;
            const r = await ctx.api('/api/payslip/periods/' + id + '/publish', {method: 'POST', idempotencyKey: idem(), body: {version: p.version, notify: body.querySelector('#psl-notify').checked}});
            toast('เผยแพร่แล้ว' + (r.mails ? ' ส่งอีเมล ' + r.mails + ' ฉบับ' : '')); reload();
        }, reload));
        body.querySelector('#psl-cancel')?.addEventListener('click', () => guarded(async () => {
            const reason = ask('เหตุผลที่ยกเลิกทั้งงวด (สลิปในงวดจะถูกลบ)'); if (reason.length < 3) return;
            await ctx.api('/api/payslip/periods/' + id + '/cancel', {method: 'POST', idempotencyKey: idem(), body: {version: p.version, reason}});
            toast('ยกเลิกงวดแล้ว'); reload();
        }, reload));
        body.querySelectorAll('[data-void]').forEach(b => b.addEventListener('click', () => guarded(async () => {
            const reason = ask('เหตุผลที่ยกเลิกสลิปนี้ (บันทึกใน audit)'); if (reason.length < 3) return;
            await ctx.api('/api/payslip/slips/' + b.dataset.void + '/void', {method: 'POST', idempotencyKey: idem(), body: {version: Number(b.dataset.v), reason}});
            toast('ยกเลิกสลิปแล้ว'); reload();
        }, reload)));
        body.querySelectorAll('[data-rep]').forEach(b => b.addEventListener('click', () => replaceForm(body.querySelector('#psl-rep'), ctx, b, reload)));
    }

    function checkScreen(box, ctx, c, reload) {
        const n = c.counts || {}, t = c.totals || {};
        box.innerHTML = `<dl class="psl-stats"><div><dt>แถวทั้งหมด</dt><dd>${esc(n.rows)}</dd></div><div><dt>จับคู่ผู้รับได้</dt><dd>${esc(n.matched)}</dd></div><div><dt>ไม่พบผู้รับ</dt><dd>${esc(n.unmatched)}</dd></div>
            <div><dt>ซ้ำ</dt><dd>${esc(n.duplicated)}</dd></div><div><dt>ปฏิเสธ (ยอดไม่ลงตัว)</dt><dd>${esc(n.refused)}</dd></div><div><dt>รับเข้า</dt><dd>${esc(n.accepted)}</dd></div>
            <div><dt>รายรับรวม</dt><dd>${esc(fmt(t.earnings))}</dd></div><div><dt>รายการหักรวม</dt><dd>${esc(fmt(t.deductions))}</dd></div><div><dt>สุทธิรวม</dt><dd>${esc(fmt(t.net))}</dd></div></dl>
            ${c.problems.length ? `<h3>แถวที่ไม่ผ่าน (${esc(c.problems_total)})</h3><ul class="psl-cards">${c.problems.map(x => `<li><div><strong>แถว ${esc(x.line)}</strong><span class="psl-sub">${esc(x.key)} / ${esc(REASON[x.reason] || x.reason)}</span></div></li>`).join('')}</ul>` : ''}
            <div class="psl-actions"><button class="psl-btn primary" type="button" id="psl-confirm"${c.can_confirm ? '' : ' disabled'}>ยืนยันนำเข้า ${esc(n.accepted)} สลิป</button></div>`;
        box.querySelector('#psl-confirm')?.addEventListener('click', () => guarded(async () => {
            await ctx.api('/api/payslip/imports/' + c.import_id + '/confirm', {method: 'POST', idempotencyKey: idem(), body: {version: c.version}});
            toast('นำเข้าแล้ว'); reload();
        }, reload));
    }

    function replaceForm(box, ctx, b, reload) {
        const pdf = b.dataset.kind === 'pdf';
        box.innerHTML = `<fieldset><legend>แทนที่สลิป (สลิปเดิมจะถูกยกเลิก)</legend>${field('เหตุผล', '<input name="reason" maxlength="300" required>')}
            ${pdf ? field('ไฟล์ PDF ใหม่', '<input type="file" name="file" accept="application/pdf,.pdf">') : `${field('รายรับ (บรรทัดละรายการ: ชื่อ | จำนวน)', '<textarea name="earnings" placeholder="เงินเดือน | 15,000.00"></textarea>')}
            ${field('รายการหัก (บรรทัดละรายการ: ชื่อ | จำนวน)', '<textarea name="deductions"></textarea>')}
            <div class="psl-grid">${field('ภาษีที่หัก', '<input name="tax" inputmode="decimal">')}${field('ประกันสังคม', '<input name="sso" inputmode="decimal">')}${field('กองทุนสำรองเลี้ยงชีพ', '<input name="pf" inputmode="decimal">')}${field('ยอดสุทธิ', '<input name="net" inputmode="decimal" required>')}</div>`}
            <div class="psl-actions"><button class="psl-btn primary" type="button" id="psl-rep-go">บันทึกการแทนที่</button></div></fieldset>`;
        const lines = txt => String(txt || '').split('\n').map(l => l.trim()).filter(Boolean).map(l => { const i = l.lastIndexOf('|'); return {name: (i < 0 ? l : l.slice(0, i)).trim(), amount: (i < 0 ? '' : l.slice(i + 1)).trim()}; });
        box.querySelector('#psl-rep-go').addEventListener('click', () => guarded(async () => {
            const q = n => box.querySelector('[name=' + n + ']');
            let body;
            if (pdf) { body = new FormData(); body.append('version', b.dataset.v); body.append('reason', q('reason').value); if (q('file').files[0]) body.append('file', q('file').files[0]); }
            else body = {version: Number(b.dataset.v), reason: q('reason').value, earnings: lines(q('earnings').value), deductions: lines(q('deductions').value), tax: q('tax').value, sso: q('sso').value, pf: q('pf').value, net: q('net').value};
            await ctx.api('/api/payslip/slips/' + b.dataset.rep + '/replace', {method: 'POST', idempotencyKey: idem(), body});
            toast('แทนที่สลิปแล้ว'); reload();
        }, reload));
    }

    // ------------------------------------------------------------ payees
    async function payees(body, ctx) {
        const [d, c] = await Promise.all([ctx.api('/api/payslip/payees'), ctx.api('/api/payslip/payees/candidates')]);
        if (!ctx.alive()) return;
        const reload = () => window.SaanWork.go('payslip/payees');
        body.innerHTML = `<details class="psl-field"><summary class="psl-btn small">เพิ่มผู้รับเงิน</summary><form id="psl-pe" class="psl-grid">
            ${field('บัญชีเจ้าหน้าที่', '<select name="user_id" required><option value="">เลือกบัญชี</option>' + c.data.map(u => `<option value="${esc(u.id)}">${esc(u.name)} (${esc(u.role)})</option>`).join('') + '</select>')}
            ${field('รหัสผู้รับเงิน', '<input name="payee_code" required maxlength="40">')}
            ${field('เลขประจำตัวประชาชน 13 หลัก', '<input name="national_id" inputmode="numeric" autocomplete="off" maxlength="20">')}
            ${field('ธนาคาร', '<input name="bank_name" maxlength="80">')}${field('เลขบัญชี', '<input name="bank_account" inputmode="numeric" autocomplete="off" maxlength="40">')}
            <button class="psl-btn primary" type="submit">บันทึก</button></form></details>
            <p class="psl-hint">เลขประจำตัวและเลขบัญชีแสดงแบบปิดบังเสมอ กรอกใหม่เพื่อเปลี่ยน เว้นว่างไว้เพื่อไม่แก้</p>
            ${d.data.length ? `<ul class="psl-cards">${d.data.map(p => `<li><div><strong>${esc(p.name)}</strong><span class="psl-sub">รหัส ${esc(p.payee_code)} / ${esc(p.nid_masked || 'ยังไม่มีเลขประจำตัว')}${p.nid_warning ? ' (เลขตรวจสอบไม่ตรง ควรตรวจทาน)' : ''} / ${esc(p.bank_masked ? (p.bank_name || '') + ' ' + p.bank_masked : 'ยังไม่มีบัญชี')}${p.user_id === null || p.account_active === false ? ' / บัญชีผู้ใช้ปิดหรือถูกลบ' : ''}</span></div>
            <button class="psl-btn small" type="button" data-edit="${esc(p.id)}" data-v="${esc(p.version)}" data-code="${esc(p.payee_code)}">แก้ไข</button></li>`).join('')}</ul>` : '<p class="psl-hint">ยังไม่มีผู้รับเงิน</p>'}`;
        body.querySelector('#psl-pe').addEventListener('submit', ev => { ev.preventDefault(); guarded(async () => {
            const f = Object.fromEntries(new FormData(ev.target)); f.user_id = Number(f.user_id);
            await ctx.api('/api/payslip/payees', {method: 'POST', idempotencyKey: idem(), body: f}); toast('บันทึกแล้ว'); reload();
        }, reload); });
        body.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => guarded(async () => {
            const code = (window.prompt('รหัสผู้รับเงิน', b.dataset.code) || '').trim(); if (!code) return;
            const nid = (window.prompt('เลขประจำตัวประชาชนใหม่ (เว้นว่างเพื่อไม่แก้)', '') || '').trim();
            const bank = (window.prompt('เลขบัญชีใหม่ (เว้นว่างเพื่อไม่แก้)', '') || '').trim();
            const body2 = {version: Number(b.dataset.v), payee_code: code}; if (nid) body2.national_id = nid; if (bank) body2.bank_account = bank;
            await ctx.api('/api/payslip/payees/' + b.dataset.edit, {method: 'PUT', body: body2}); toast('บันทึกแล้ว'); reload();
        }, reload)));
    }

    // ------------------------------------------------------------ mapping (set once, reused every month)
    async function setup(body, ctx) {
        const m = (await ctx.api('/api/payslip/mapping')).mapping || {key_type: 'code', key_col: '', earnings: [], deductions: [], tax_col: '', sso_col: '', pf_col: '', net_col: ''};
        if (!ctx.alive()) return;
        const items = l => (l || []).map(i => i.col + (i.name && i.name !== i.col ? ' | ' + i.name : '')).join('\n');
        body.innerHTML = `<p class="psl-hint">ตั้งครั้งเดียว ใช้ซ้ำทุกเดือน ชื่อคอลัมน์ต้องตรงกับหัวตารางในไฟล์ ใส่รายรับและรายการหักบรรทัดละคอลัมน์ (ถ้าต้องการชื่อบนสลิปต่างจากหัวคอลัมน์ ใช้รูปแบบ: หัวคอลัมน์ | ชื่อบนสลิป) ภาษี ประกันสังคม และกองทุนสำรองเลี้ยงชีพนับเป็นรายการหักด้วย ยอดสุทธิต้องเท่ากับรายรับลบรายการหักทั้งหมด</p>
            <form id="psl-map" class="psl-grid">
            ${field('ใช้อะไรจับคู่ผู้รับเงิน', `<select name="key_type"><option value="code"${m.key_type === 'code' ? ' selected' : ''}>รหัสผู้รับเงิน</option><option value="national_id"${m.key_type === 'national_id' ? ' selected' : ''}>เลขประจำตัวประชาชน</option></select>`)}
            ${field('คอลัมน์ผู้รับเงิน', `<input name="key_col" required maxlength="120" value="${esc(m.key_col)}">`)}
            ${field('คอลัมน์รายรับ', `<textarea name="earnings" required>${esc(items(m.earnings))}</textarea>`)}
            ${field('คอลัมน์รายการหัก', `<textarea name="deductions">${esc(items(m.deductions))}</textarea>`)}
            ${field('คอลัมน์ภาษีที่หัก', `<input name="tax_col" maxlength="120" value="${esc(m.tax_col || '')}">`)}
            ${field('คอลัมน์ประกันสังคม', `<input name="sso_col" maxlength="120" value="${esc(m.sso_col || '')}">`)}
            ${field('คอลัมน์กองทุนสำรองเลี้ยงชีพ', `<input name="pf_col" maxlength="120" value="${esc(m.pf_col || '')}">`)}
            ${field('คอลัมน์ยอดสุทธิ', `<input name="net_col" required maxlength="120" value="${esc(m.net_col)}">`)}
            <button class="psl-btn primary" type="submit">บันทึกการจับคู่</button></form>
            <fieldset><legend>ดูหัวตารางจากไฟล์ตัวอย่าง</legend><input type="file" id="psl-sample" accept=".csv,.xlsx,text/csv"><div id="psl-heads" class="psl-hint"></div></fieldset>`;
        const parse = t => String(t || '').split('\n').map(l => l.trim()).filter(Boolean).map(l => { const [col, ...nm] = l.split('|'); return {col: col.trim(), name: nm.join('|').trim()}; });
        body.querySelector('#psl-map').addEventListener('submit', ev => { ev.preventDefault(); guarded(async () => {
            const f = Object.fromEntries(new FormData(ev.target));
            await ctx.api('/api/payslip/mapping', {method: 'PUT', body: {mapping: {...f, earnings: parse(f.earnings), deductions: parse(f.deductions)}}}); toast('บันทึกแล้ว');
        }); });
        body.querySelector('#psl-sample').addEventListener('change', ev => guarded(async () => {
            const fd = new FormData(); fd.append('file', ev.target.files[0]);
            const h = await ctx.api('/api/payslip/headers', {method: 'POST', body: fd});
            body.querySelector('#psl-heads').textContent = 'หัวคอลัมน์ในไฟล์ (' + h.rows + ' แถวข้อมูล): ' + h.headers.join(' , ');
        }));
    }

    // ------------------------------------------------------------ 50 tawi for the school
    async function tax(body, ctx, me, yearArg) {
        const q = yearArg ? '?year=' + encodeURIComponent(yearArg) : '';
        const d = await ctx.api('/api/payslip/certificates' + q);
        if (!ctx.alive()) return;
        const s = d.summary, years = d.years.length ? d.years : [d.year];
        body.innerHTML = `${field('ปี (พ.ศ.)', '<select id="psl-year">' + years.map(y => `<option value="${esc(y)}"${y === d.year ? ' selected' : ''}>${esc(year(y))}</option>`).join('') + '</select>')}
            <dl class="psl-stats"><div><dt>ผู้มีสลิปในปี</dt><dd>${esc(s.payees_with_slips)}</dd></div><div><dt>ออกแล้ว</dt><dd>${esc(s.issued)}</dd></div><div><dt>ยังไม่ออก</dt><dd>${esc(s.missing)}</dd></div><div><dt>ต้องออกใหม่</dt><dd>${esc(s.stale)}</dd></div>
            <div><dt>เงินได้รวม</dt><dd>${esc(fmt(s.gross))}</dd></div><div><dt>ภาษีรวม</dt><dd>${esc(fmt(s.tax))}</dd></div><div><dt>ประกันสังคมรวม</dt><dd>${esc(fmt(s.sso))}</dd></div><div><dt>กองทุนรวม</dt><dd>${esc(fmt(s.pf))}</dd></div></dl>
            ${s.unreadable ? `<p class="psl-warn">มีผู้รับเงิน ${esc(s.unreadable)} ราย ที่ถอดรหัสสลิปบางงวดไม่ได้ (เปลี่ยนกุญแจระบบหรือข้อมูลเสียหาย) ระบบไม่ออก 50 ทวิให้เพราะยอดจะขาดงวดเหล่านั้น กรุณาตรวจ APP_KEY / APP_PREVIOUS_KEYS</p>` : ''}
                ${s.pdf_partial ? `<p class="psl-warn">มีผู้รับเงิน ${esc(s.pdf_partial)} ราย ที่มีสลิปแบบไฟล์ PDF ในปีนี้ ระบบไม่ออก 50 ทวิให้เพราะยอดจะขาดงวดเหล่านั้น</p>` : ''}
            ${s.id_warnings ? `<p class="psl-warn">มีผู้รับเงิน ${esc(s.id_warnings)} ราย ที่เลขประจำตัวไม่มีหรือเลขตรวจสอบไม่ตรง ควรตรวจทานก่อนพิมพ์</p>` : ''}
            ${me.is_officer ? '<div class="psl-actions"><button class="psl-btn primary" type="button" id="psl-gen">ออก 50 ทวิให้ทุกคนของปีนี้</button></div>' : '<p class="psl-hint">ผู้บริหารและผู้ดูแลระบบดูได้เฉพาะสรุป</p>'}
            ${d.rows ? `<ul class="psl-cards">${d.rows.map(r => `<li><div><strong>${esc(r.name)}</strong> ${badge(r.status)}<span class="psl-sub">รหัส ${esc(r.payee_code)} / ${esc(r.nid_masked || '-')} / เงินได้ ${esc(fmt(r.gross))} / ภาษี ${esc(fmt(r.tax))}</span></div>
            ${r.cert_id && r.status === 'issued' ? `<a class="psl-btn small" target="_blank" rel="noopener" href="${esc(link('/api/payslip/certificates/' + r.cert_id + '/print'))}">พิมพ์</a>` : ''}</li>`).join('')}</ul>` : ''}`;
        body.querySelector('#psl-year').addEventListener('change', ev => { body.replaceChildren(); guarded(() => tax(body, ctx, me, ev.target.value)); });
        body.querySelector('#psl-gen')?.addEventListener('click', () => guarded(async () => {
            const r = await ctx.api('/api/payslip/certificates/generate', {method: 'POST', idempotencyKey: idem(), body: {year: d.year}});
            toast('ออกใหม่ ' + r.result.issued + ' ปรับปรุง ' + r.result.updated + ' ไม่เปลี่ยน ' + r.result.unchanged);
            body.replaceChildren(); await tax(body, ctx, me, d.year);
        }));
    }

    window.SaanWork.register('payslip', {
        title: 'สลิปเงินเดือน',
        async render(root, ctx) {
            const me = await ctx.api('/api/payslip/me');
            if (!ctx.alive()) return;
            const tab = ctx.parts[0] || '';
            const allowed = tabsFor(me).map(t => t[0]);
            const effective = tab === 'period' ? 'periods' : tab;
            const body = shell(root, me, allowed.includes(effective) ? effective : '');
            if (tab === 'period' && me.is_officer) return periodDetail(body, ctx, me, ctx.parts[1]);
            if (!allowed.includes(tab)) return mySlips(body, ctx, me);
            const pages = {'': mySlips, certs: myCerts, periods, payees, setup, tax};
            return pages[tab](body, ctx, me);
        },
    });
})();
