'use strict';
// documents module (M20): staff pages #inbox (documents routed to me) and #documents (registry, clerks only) for the /work shell.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const C = window.SaanCore;
    const esc = v => C.esc(v);
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const href = p => base() + p;
    const URG = {normal: 'ปกติ', urgent: 'ด่วน', very_urgent: 'ด่วนมาก', most_urgent: 'ด่วนที่สุด'};
    const STATUS = {registered: 'ลงทะเบียนแล้ว', routed: 'ส่งต่อแล้ว', done: 'เสร็จสิ้น', cancelled: 'ยกเลิก'};
    const KIND = {incoming: 'หนังสือรับ', outgoing: 'หนังสือส่ง', order: 'คำสั่ง', announcement: 'ประกาศ', memo: 'บันทึกข้อความ'};
    const MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    const opts = (map, cur, blank) => (blank !== undefined ? `<option value="">${esc(blank)}</option>` : '')
        + Object.entries(map).map(([k, v]) => `<option value="${esc(k)}"${String(k) === String(cur) ? ' selected' : ''}>${esc(v)}</option>`).join('');
    const field = (label, control) => `<label class="doc-field"><span>${esc(label)}</span>${control}</label>`;
    const bytes = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
    const when = s => s ? C.date(String(s).slice(0, 10), {day: 'numeric', month: 'short', year: 'numeric'}) : '';
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    };
    // #work-view outlives page changes, so listeners go on a fresh host element and die with it
    const mount = root => { const host = document.createElement('div'); host.className = 'doc-host'; root.append(host); return host; };
    const fail = (err, reload) => {
        if (err?.name === 'AbortError') return;
        toast(err?.message || 'เกิดข้อผิดพลาด', true);
        if (err?.status === 409 && reload) reload();
    };
    const urg = u => u && u !== 'normal' ? `<span class="doc-tag doc-urg-${esc(u)}">${esc(URG[u] || u)}</span>` : '';
    const sec = s => s === 'confidential' ? '<span class="doc-tag doc-secret">ลับ</span>' : '';
    const dl = f => `<li><a href="${esc(href('/api/documents/files/' + f.id + '/download'))}" download>${esc(f.original_name)}</a> <small>${esc(bytes(f.size))}</small></li>`;
    const facts = d => `<dl class="doc-facts">
        <dt>เลขทะเบียน</dt><dd>${esc(d.reg_label)} ${esc(KIND[d.kind] || '')}</dd>
        <dt>ที่</dt><dd>${esc(d.doc_no || '-')}</dd>
        <dt>ลงวันที่</dt><dd>${esc(when(d.doc_date))}</dd>
        ${d.from_org ? `<dt>จาก</dt><dd>${esc(d.from_org)}</dd>` : ''}${d.to_org ? `<dt>ถึง</dt><dd>${esc(d.to_org)}</dd>` : ''}
        ${d.received_on ? `<dt>วันที่รับ</dt><dd>${esc(when(d.received_on))}</dd>` : ''}
        <dt>สถานะ</dt><dd>${esc(STATUS[d.status] || d.status)}${d.cancel_reason ? ' (' + esc(d.cancel_reason) + ')' : ''}</dd>
        ${d.summary ? `<dt>สาระสำคัญ</dt><dd class="doc-pre">${esc(d.summary)}</dd>` : ''}</dl>`;

    // ---------------------------------------------------------------- inbox
    async function inbox(root, ctx) {
        if (ctx.parts[0] && /^\d+$/.test(ctx.parts[0])) return inboxDetail(root, ctx, +ctx.parts[0]);
        const host = mount(root);
        let state = 'all', page = 1;
        const draw = async () => {
            const res = await ctx.api(`/api/documents/inbox?state=${state}&page=${page}`);
            if (!ctx.alive()) return;
            const pages = Math.max(1, Math.ceil(res.total / res.per_page));
            host.innerHTML = `<section class="doc glass"><div class="doc-head"><h1>หนังสือเข้าถึงฉัน</h1>${res.unread ? `<span class="doc-badge" aria-label="ยังไม่เปิดอ่าน ${res.unread} ฉบับ">${res.unread}</span>` : ''}</div>
                ${field('แสดง', `<select data-f="state">${opts({all: 'ทั้งหมด', unread: 'ยังไม่เปิดอ่าน', pending: 'ยังไม่รับทราบ'}, state)}</select>`)}
                ${res.data.length ? `<ul class="doc-list">${res.data.map(r => `<li class="doc-card${r.read_at ? '' : ' unread'}"><a href="#inbox/${r.document.id}">
                    <strong>${esc(r.document.subject)}</strong>
                    <span>${esc(r.document.reg_label)} ${esc(KIND[r.document.kind] || '')} ${urg(r.document.urgency)}${sec(r.document.secrecy)}</span>
                    <small>${esc(r.document.from_org || r.document.to_org || '')} - ส่งต่อโดย ${esc(r.routed_by_name || '-')} ${esc(when(r.routed_at))}</small>
                    ${r.note ? `<small class="doc-note">${esc(r.note)}</small>` : ''}
                    <em>${r.document.status === 'cancelled' ? 'ยกเลิกแล้ว' : r.acknowledged_at ? 'รับทราบแล้ว ' + esc(when(r.acknowledged_at)) : r.read_at ? 'เปิดอ่านแล้ว รอรับทราบ' : 'ยังไม่เปิดอ่าน'}</em></a></li>`).join('')}</ul>`
                    : '<p class="doc-empty">ไม่มีหนังสือในรายการนี้</p>'}
                ${pages > 1 ? `<div class="doc-pager"><button type="button" class="doc-btn" data-act="prev"${page <= 1 ? ' disabled' : ''}>ก่อนหน้า</button><span>${page} / ${pages}</span><button type="button" class="doc-btn" data-act="next"${page >= pages ? ' disabled' : ''}>ถัดไป</button></div>` : ''}</section>`;
        };
        host.addEventListener('change', e => { if (e.target.dataset.f === 'state') { state = e.target.value; page = 1; draw().catch(err => fail(err)); } });
        host.addEventListener('click', e => {
            const a = e.target.closest('[data-act]')?.dataset.act;
            if (a === 'prev') { page--; draw().catch(err => fail(err)); } else if (a === 'next') { page++; draw().catch(err => fail(err)); }
        });
        await draw();
    }

    async function inboxDetail(root, ctx, id) {
        const host = mount(root);
        const draw = async () => {
            const d = await ctx.api('/api/documents/' + id);
            if (!ctx.alive()) return;
            const mine = d.routes.find(r => r.mine);
            host.innerHTML = `<section class="doc glass"><p><a href="#inbox">กลับกล่องหนังสือ</a></p>
                <h1>${esc(d.subject)} ${urg(d.urgency)}${sec(d.secrecy)}</h1>${facts(d)}
                ${d.files.length ? `<h2>ไฟล์แนบ</h2><ul class="doc-files">${d.files.map(dl).join('')}</ul>` : ''}
                ${mine && d.status === 'cancelled' ? '<p class="doc-note">หนังสือนี้ถูกยกเลิกแล้ว</p>' : mine ? `<h2>การรับทราบ</h2>${mine.note ? `<p class="doc-note">ข้อความจากผู้ส่งต่อ: ${esc(mine.note)}</p>` : ''}
                ${mine.acknowledged_at ? `<p>รับทราบเมื่อ ${esc(when(mine.acknowledged_at))}${mine.action_note ? ' - ' + esc(mine.action_note) : ''}</p>`
                    : `<form class="doc-form" data-form="ack" data-route="${mine.id}">${field('หมายเหตุ (ไม่บังคับ)', '<input name="note" maxlength="500">')}<button class="doc-btn primary">รับทราบ</button></form>`}` : ''}</section>`;
        };
        host.addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.target.closest('[data-form="ack"]'); if (!f) return;
            try { await ctx.api(`/api/documents/inbox/${f.dataset.route}/ack`, {method: 'POST', body: {note: f.elements.note.value}}); toast('บันทึกการรับทราบแล้ว'); await draw(); }
            catch (err) { fail(err); }
        });
        await draw();
    }

    // ------------------------------------------------------------- registry
    async function registry(root, ctx) {
        const meta = await ctx.api('/api/documents/meta');
        if (!ctx.alive()) return;
        const host = mount(root);
        if (!meta.is_clerk) { host.innerHTML = '<section class="doc glass" role="alert"><h1>ทะเบียนหนังสือ</h1><p>บัญชีนี้ไม่ได้รับสิทธิ์เจ้าหน้าที่สารบรรณ ผู้ดูแลระบบให้สิทธิ์ได้ในหน้าตั้งค่า</p></section>'; return; }
        const p0 = ctx.parts[0];
        if (p0 === 'new') return form(host, ctx, meta);
        if (p0 === 'settings' && meta.is_admin) return settings(host, ctx);
        if (p0 && /^\d+$/.test(p0)) return detail(host, ctx, meta, +p0);
        return list(host, ctx, meta);
    }

    async function list(host, ctx, meta) {
        const q = {q: '', year_be: String(meta.year_be), kind: '', status: '', page: 1};
        const printSel = {kind: 'incoming', year_be: meta.year_be, month: +meta.today.slice(5, 7)};
        const draw = async () => {
            const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v !== '' && v != null));
            const res = await ctx.api('/api/documents?' + qs);
            if (!ctx.alive()) return;
            const years = [...new Set([meta.year_be, ...res.years])].sort((a, b) => b - a);
            const pages = Math.max(1, Math.ceil(res.total / res.per_page));
            const focus = document.activeElement?.dataset?.f;
            host.innerHTML = `<section class="doc glass"><div class="doc-head"><h1>ทะเบียนหนังสือ</h1><div class="doc-actions">
                <a class="doc-btn primary" href="#documents/new">ลงทะเบียนหนังสือ</a>${meta.is_admin ? '<a class="doc-btn" href="#documents/settings">ตั้งค่าเจ้าหน้าที่</a>' : ''}</div></div>
                <form class="doc-filters" data-form="filter">${field('ค้นหา (เรื่อง ผู้ส่ง เลขที่หนังสือ เลขทะเบียน)', `<input type="search" data-f="q" value="${esc(q.q)}" maxlength="100">`)}
                ${field('ปี พ.ศ.', `<select data-f="year_be"><option value="">ทุกปี</option>${years.map(y => `<option value="${esc(y)}"${String(y) === String(q.year_be) ? ' selected' : ''}>${esc(y)}</option>`).join('')}</select>`)}
                ${field('ประเภท', `<select data-f="kind">${opts(KIND, q.kind, 'ทุกประเภท')}</select>`)}
                ${field('สถานะ', `<select data-f="status">${opts(STATUS, q.status, 'ทุกสถานะ')}</select>`)}</form>
                <p class="doc-count">พบ ${res.total} รายการ</p>
                ${res.data.length ? `<div class="doc-table-wrap"><table class="doc-table"><thead><tr><th>เลขทะเบียน</th><th>ลงวันที่</th><th>เรื่อง</th><th>จาก / ถึง</th><th>สถานะ</th></tr></thead><tbody>
                ${res.data.map(d => `<tr><td data-l="เลขทะเบียน"><a href="#documents/${d.id}">${esc(d.reg_label)}</a> <small>${esc(KIND[d.kind] || '')}</small></td><td data-l="ลงวันที่">${esc(when(d.doc_date))}</td>
                <td data-l="เรื่อง">${esc(d.subject)} ${urg(d.urgency)}${sec(d.secrecy)}</td><td data-l="จาก / ถึง">${esc(d.from_org || d.to_org || '')}</td>
                <td data-l="สถานะ">${esc(STATUS[d.status] || d.status)}${d.route_total ? ` <small>(รับทราบ ${d.route_ack}/${d.route_total})</small>` : ''}</td></tr>`).join('')}</tbody></table></div>` : '<p class="doc-empty">ไม่พบหนังสือ</p>'}
                ${pages > 1 ? `<div class="doc-pager"><button type="button" class="doc-btn" data-act="prev"${q.page <= 1 ? ' disabled' : ''}>ก่อนหน้า</button><span>${q.page} / ${pages}</span><button type="button" class="doc-btn" data-act="next"${q.page >= pages ? ' disabled' : ''}>ถัดไป</button></div>` : ''}
                <details class="doc-print"><summary>พิมพ์ทะเบียนรายเดือน</summary><div class="doc-filters">
                ${field('ทะเบียน', `<select data-p="kind">${opts(KIND, printSel.kind)}</select>`)}${field('ปี พ.ศ.', `<input type="number" data-p="year_be" value="${esc(printSel.year_be)}" min="2400" max="2700">`)}
                ${field('เดือน', `<select data-p="month">${opts(Object.fromEntries(MONTHS.map((m, i) => [i + 1, m])), printSel.month)}</select>`)}
                <a class="doc-btn" data-act="print" target="_blank" rel="noopener" href="${esc(href('/documents/print?' + new URLSearchParams(printSel)))}">เปิดหน้าพิมพ์ A4 แนวนอน</a></div></details></section>`;
            if (focus) { const el = host.querySelector(`[data-f="${focus}"]`); if (el) { el.focus(); if (el.setSelectionRange && el.type === 'search') el.setSelectionRange(el.value.length, el.value.length); } }
        };
        let timer;
        host.addEventListener('input', e => {
            if (e.target.dataset.f === 'q') { q.q = e.target.value.trim(); q.page = 1; clearTimeout(timer); timer = setTimeout(() => ctx.alive() && draw().catch(err => fail(err)), 350); }
        });
        host.addEventListener('change', e => {
            const f = e.target.dataset.f, pk = e.target.dataset.p;
            if (f && f !== 'q') { q[f] = e.target.value; q.page = 1; draw().catch(err => fail(err)); }
            if (pk) { printSel[pk] = e.target.value; host.querySelector('[data-act="print"]').href = href('/documents/print?' + new URLSearchParams(printSel)); }
        });
        host.addEventListener('submit', e => e.preventDefault());
        host.addEventListener('click', e => {
            const a = e.target.closest('[data-act]')?.dataset.act;
            if (a === 'prev') { q.page--; draw().catch(err => fail(err)); } else if (a === 'next') { q.page++; draw().catch(err => fail(err)); }
        });
        await draw();
    }

    function form(host, ctx, meta) {
        const key = C.uid();
        host.innerHTML = `<section class="doc glass"><p><a href="#documents">กลับทะเบียนหนังสือ</a></p><h1>ลงทะเบียนหนังสือ</h1>
            <form class="doc-form" data-form="create">
            ${field('ประเภททะเบียน', `<select name="kind" required>${opts(KIND, 'incoming')}</select>`)}
            ${field('เรื่อง', '<input name="subject" required maxlength="300">')}
            ${field('ที่ (เลขที่หนังสือของผู้ส่ง)', '<input name="doc_no" maxlength="80">')}
            ${field('ลงวันที่', `<input type="date" name="doc_date" required value="${esc(meta.today)}">`)}
            ${field('จาก (หนังสือรับ)', '<input name="from_org" maxlength="200">')}${field('ถึง (หนังสือส่ง)', '<input name="to_org" maxlength="200">')}
            ${field('ความเร่งด่วน', `<select name="urgency">${opts(URG, 'normal')}</select>`)}
            ${meta.is_manager ? field('ชั้นความลับ', `<select name="secrecy">${opts({normal: 'ปกติ', confidential: 'ลับ (เห็นเฉพาะผู้ดูแล ผู้อำนวยการ และผู้รับ)'}, 'normal')}</select>`) : ''}
            ${field('สาระสำคัญ', '<textarea name="summary" rows="3" maxlength="5000"></textarea>')}
            <button class="doc-btn primary">บันทึกและออกเลขทะเบียน</button></form></section>`;
        host.addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.target, btn = f.querySelector('button'); btn.disabled = true;
            const body = Object.fromEntries(new FormData(f).entries());
            for (const k of Object.keys(body)) if (body[k] === '') delete body[k];
            try { const d = await ctx.api('/api/documents', {method: 'POST', body, idempotencyKey: key}); toast('ออกเลขทะเบียน ' + d.reg_label + ' แล้ว'); window.SaanWork.go('documents/' + d.id); }
            catch (err) { btn.disabled = false; fail(err); }
        });
    }

    async function detail(host, ctx, meta, id) {
        let recipients = null;
        let uploadKey = C.uid(); // one key per file pick; refreshed after a success so the next upload is a new request
        const draw = async () => {
            const d = await ctx.api('/api/documents/' + id);
            if (!ctx.alive()) return;
            if (!recipients) recipients = (await ctx.api('/api/documents/recipients')).data;
            const open = d.status !== 'cancelled';
            const routed = new Set(d.routes.map(r => r.user_id));
            host.innerHTML = `<section class="doc glass"><p><a href="#documents">กลับทะเบียนหนังสือ</a></p>
                <h1>${esc(d.subject)} ${urg(d.urgency)}${sec(d.secrecy)}</h1>${facts(d)}
                ${open ? `<div class="doc-actions"><button type="button" class="doc-btn" data-act="done" data-done="${d.status === 'done' ? 0 : 1}">${d.status === 'done' ? 'เปิดเรื่องอีกครั้ง' : 'ทำเครื่องหมายเสร็จสิ้น'}</button></div>` : ''}
                <h2>ไฟล์แนบ</h2>${d.files.length ? `<ul class="doc-files">${d.files.map(f => `<li><a href="${esc(href('/api/documents/files/' + f.id + '/download'))}" download>${esc(f.original_name)}</a> <small>${esc(bytes(f.size))}</small>
                    ${open ? `<button type="button" class="doc-btn danger" data-act="rmfile" data-id="${f.id}" data-v="${f.version}">ลบ</button>` : ''}</li>`).join('')}</ul>` : '<p class="doc-empty">ยังไม่มีไฟล์แนบ</p>'}
                ${open ? `<form class="doc-form" data-form="upload">${field(`แนบไฟล์ PDF หรือรูปภาพ (ไม่เกิน ${esc(meta.max_upload_mb || 10)} MB)`, '<input type="file" name="file" accept="application/pdf,image/jpeg,image/png,image/webp" required>')}<button class="doc-btn">อัปโหลด</button></form>` : ''}
                <h2>การส่งต่อ</h2>${d.routes.length ? `<ul class="doc-routes">${d.routes.map(r => `<li><strong>${esc(r.user_name || '-')}</strong>
                    <span>${r.acknowledged_at ? 'รับทราบ ' + esc(when(r.acknowledged_at)) + (r.action_note ? ' - ' + esc(r.action_note) : '') : r.read_at ? 'เปิดอ่านแล้ว' : 'ยังไม่เปิดอ่าน'}</span>
                    ${!r.acknowledged_at && open ? `<button type="button" class="doc-btn danger" data-act="unroute" data-id="${r.id}" data-v="${r.version}">ถอน</button>` : ''}</li>`).join('')}</ul>` : '<p class="doc-empty">ยังไม่ได้ส่งต่อ</p>'}
                ${open ? `<form class="doc-form" data-form="route"><fieldset><legend>ส่งต่อให้บุคลากร</legend><div class="doc-checks">${recipients.filter(r => !routed.has(r.id)).map(r => `<label class="doc-check"><input type="checkbox" name="user_ids" value="${r.id}"><span>${esc(r.name)}</span></label>`).join('')}</div></fieldset>
                    ${field('หมายเหตุถึงผู้รับ', '<input name="note" maxlength="300">')}<button class="doc-btn primary">ส่งต่อ</button></form>
                <details><summary>แก้ไขข้อมูลหนังสือ</summary><form class="doc-form" data-form="edit">
                    ${field('เรื่อง', `<input name="subject" required maxlength="300" value="${esc(d.subject)}">`)}${field('ที่', `<input name="doc_no" maxlength="80" value="${esc(d.doc_no || '')}">`)}
                    ${field('ลงวันที่', `<input type="date" name="doc_date" required value="${esc(d.doc_date)}">`)}${field('จาก', `<input name="from_org" maxlength="200" value="${esc(d.from_org || '')}">`)}${field('ถึง', `<input name="to_org" maxlength="200" value="${esc(d.to_org || '')}">`)}
                    ${field('ความเร่งด่วน', `<select name="urgency">${opts(URG, d.urgency)}</select>`)}
                    ${meta.is_manager ? field('ชั้นความลับ', `<select name="secrecy">${opts({normal: 'ปกติ', confidential: 'ลับ'}, d.secrecy)}</select>`) : ''}
                    ${field('สาระสำคัญ', `<textarea name="summary" rows="3" maxlength="5000">${esc(d.summary || '')}</textarea>`)}<button class="doc-btn primary">บันทึกการแก้ไข</button></form></details>
                <details><summary>ยกเลิกหนังสือ (เลขทะเบียนยังคงอยู่)</summary><form class="doc-form" data-form="cancel">${field('เหตุผลที่ยกเลิก', '<input name="reason" required minlength="2" maxlength="300">')}<button class="doc-btn danger">ยกเลิกหนังสือ</button></form></details>` : ''}</section>`;
            host.dataset.version = d.version;
        };
        const run = async (fn, okText) => {
            try { await fn(); if (okText) toast(okText); await draw(); } catch (err) { fail(err, () => draw().catch(() => {})); }
        };
        host.addEventListener('click', e => {
            const b = e.target.closest('[data-act]'); if (!b) return;
            const v = +host.dataset.version;
            if (b.dataset.act === 'done') run(() => ctx.api(`/api/documents/${id}/done`, {method: 'POST', body: {version: v, done: b.dataset.done === '1'}}), 'อัปเดตสถานะแล้ว');
            if (b.dataset.act === 'rmfile') run(() => ctx.api('/api/documents/files/' + b.dataset.id, {method: 'DELETE', body: {version: +b.dataset.v}}), 'ลบไฟล์แล้ว');
            if (b.dataset.act === 'unroute') run(() => ctx.api(`/api/documents/${id}/routes/${b.dataset.id}`, {method: 'DELETE', body: {version: +b.dataset.v}}), 'ถอนการส่งต่อแล้ว');
        });
        host.addEventListener('submit', e => {
            e.preventDefault();
            const f = e.target.closest('form'), v = +host.dataset.version, kind = f?.dataset.form;
            if (kind === 'upload') {
                const btn = f.querySelector('button'); btn.disabled = true;
                const fd = new FormData(); fd.append('file', f.elements.file.files[0]);
                run(async () => { try { await ctx.api(`/api/documents/${id}/files`, {method: 'POST', body: fd, idempotencyKey: uploadKey}); uploadKey = C.uid(); } finally { btn.disabled = false; } }, 'แนบไฟล์แล้ว');
            }
            if (kind === 'route') {
                const ids = [...f.querySelectorAll('input[name=user_ids]:checked')].map(i => +i.value);
                if (!ids.length) { toast('เลือกผู้รับอย่างน้อยหนึ่งคน', true); return; }
                run(() => ctx.api(`/api/documents/${id}/routes`, {method: 'POST', body: {user_ids: ids, note: f.elements.note.value}}), 'ส่งต่อแล้ว');
            }
            if (kind === 'edit') {
                const body = Object.fromEntries(new FormData(f).entries()); body.version = v;
                run(() => ctx.api('/api/documents/' + id, {method: 'PUT', body}), 'บันทึกแล้ว');
            }
            if (kind === 'cancel') run(() => ctx.api(`/api/documents/${id}/cancel`, {method: 'POST', body: {version: v, reason: f.elements.reason.value}}), 'ยกเลิกหนังสือแล้ว');
        });
        await draw();
    }

    async function settings(host, ctx) {
        let version = 0; // optimistic version of the clerk list: a save made on an older copy is refused with 409
        const draw = async () => {
            const s = await ctx.api('/api/documents/clerks');
            if (!ctx.alive()) return;
            version = s.version || 0;
            const set = new Set(s.clerk_user_ids);
            host.innerHTML = `<section class="doc glass"><p><a href="#documents">กลับทะเบียนหนังสือ</a></p><h1>เจ้าหน้าที่สารบรรณ</h1>
                <p class="doc-sub">ผู้ดูแลระบบและผู้อำนวยการลงทะเบียนและส่งต่อได้อยู่แล้ว เลือกบัญชีอื่นที่ให้ช่วยงานสารบรรณ (ลงทะเบียนหนังสือลับไม่ได้)</p>
                <form class="doc-form" data-form="clerks"><div class="doc-checks">${s.candidates.map(c => `<label class="doc-check"><input type="checkbox" name="clerk" value="${c.id}"${set.has(c.id) ? ' checked' : ''}><span>${esc(c.name)}</span></label>`).join('') || '<p class="doc-empty">ไม่มีบัญชีอื่น</p>'}</div>
                <button class="doc-btn primary">บันทึก</button></form></section>`;
        };
        host.addEventListener('submit', async e => {
            e.preventDefault();
            const ids = [...e.target.querySelectorAll('input[name=clerk]:checked')].map(i => +i.value);
            try { await ctx.api('/api/documents/clerks', {method: 'PUT', body: {version, clerk_user_ids: ids}}); toast('บันทึกแล้ว'); await draw(); } catch (err) { fail(err); }
        });
        await draw();
    }

    window.SaanWork.register('inbox', {title: 'หนังสือเข้าถึงฉัน', render: inbox});
    window.SaanWork.register('documents', {title: 'ทะเบียนหนังสือ', render: registry});
})();
