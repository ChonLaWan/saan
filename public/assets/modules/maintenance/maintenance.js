'use strict';
// maintenance module (M21): staff pages #repairs (report + follow up) and #assets (asset register, stock, reports) for the /work shell.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const C = window.SaanCore;
    const esc = v => C.esc(v);
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const href = p => base() + p;
    const STATUS = {new: 'รอรับเรื่อง', accepted: 'รับเรื่องแล้ว', in_progress: 'กำลังซ่อม', waiting_parts: 'รออะไหล่', done: 'ซ่อมเสร็จ', rejected: 'ไม่ดำเนินการ', cancelled: 'ยกเลิก'};
    const URG = {normal: 'ปกติ', urgent: 'ด่วน'};
    const ASTATUS = {in_use: 'ใช้งาน', repair: 'ซ่อม', disposed: 'จำหน่ายแล้ว'};
    const KINDS = {in: 'รับเข้า', out: 'เบิกออก', adjust: 'ปรับยอด (ใส่ค่าบวกหรือลบ)'};
    const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    const BUCKETS = {'0-2': '0-2 วัน', '3-7': '3-7 วัน', '8-14': '8-14 วัน', '15-30': '15-30 วัน', '31+': 'เกิน 30 วัน'};
    const opts = (map, cur, blank) => (blank !== undefined ? `<option value="">${esc(blank)}</option>` : '')
        + Object.entries(map).map(([k, v]) => `<option value="${esc(k)}"${String(k) === String(cur) ? ' selected' : ''}>${esc(v)}</option>`).join('');
    const field = (label, control) => `<label class="mnt-field"><span>${esc(label)}</span>${control}</label>`;
    const when = s => s ? C.date(String(s).slice(0, 10), {day: 'numeric', month: 'short', year: 'numeric'}) : '';
    const baht = n => n === null || n === undefined ? '-' : (n / 100).toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    // text -> satang without floating point; '' -> null; invalid -> undefined
    const toSatang = s => {
        s = String(s ?? '').trim().replace(/,/g, '');
        if (s === '') return null;
        const m = /^(\d{1,9})(?:\.(\d{1,2}))?$/.exec(s);
        return m ? Number(m[1]) * 100 + Number((m[2] || '').padEnd(2, '0') || 0) : undefined;
    };
    const satangText = n => n === null || n === undefined ? '' : (Math.floor(n / 100) + '.' + String(n % 100).padStart(2, '0'));
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    };
    const mount = root => { const host = document.createElement('div'); host.className = 'mnt-host'; root.append(host); return host; };
    const fail = (err, reload) => {
        if (err?.name === 'AbortError') return;
        toast(err?.message || 'เกิดข้อผิดพลาด', true);
        if (err?.status === 409 && reload) reload();
    };
    const tag = (cls, text) => `<span class="mnt-tag mnt-${esc(cls)}">${esc(text)}</span>`;
    const stTag = s => tag('st-' + s, STATUS[s] || s);
    const pager = (res, page) => {
        const pages = Math.max(1, Math.ceil(res.total / res.per_page));
        return pages > 1 ? `<div class="mnt-pager"><button type="button" class="mnt-btn" data-act="prev"${page <= 1 ? ' disabled' : ''}>ก่อนหน้า</button><span>${page} / ${pages}</span><button type="button" class="mnt-btn" data-act="next"${page >= pages ? ' disabled' : ''}>ถัดไป</button></div>` : '';
    };
    const metaOf = ctx => ctx.api('/api/maintenance/meta');
    const busy = btn => { if (btn) btn.disabled = true; return () => { if (btn) btn.disabled = false; }; };

    // =============================================================== #repairs
    async function repairs(root, ctx) {
        const meta = await metaOf(ctx);
        if (!ctx.alive()) return;
        const host = mount(root);
        const p0 = ctx.parts[0];
        if (p0 === 'new') return repairForm(host, ctx, meta);
        if (p0 && /^\d+$/.test(p0)) return repairDetail(host, ctx, meta, +p0);
        return repairList(host, ctx, meta);
    }

    async function repairList(host, ctx, meta) {
        const q = {scope: meta.is_tech ? 'all' : 'mine', status: 'open', assigned: '', q: '', page: 1};
        const draw = async () => {
            const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v !== '' && v != null));
            const res = await ctx.api('/api/maintenance/requests?' + qs);
            if (!ctx.alive()) return;
            host.innerHTML = `<section class="mnt glass"><div class="mnt-head"><h1>แจ้งซ่อม</h1><div class="mnt-actions">
                <a class="mnt-btn primary" href="#repairs/new">แจ้งซ่อมใหม่</a>${meta.is_tech ? '<a class="mnt-btn" href="#assets">ครุภัณฑ์และพัสดุ</a>' : ''}</div></div>
                <form class="mnt-filters" data-form="filter">
                ${meta.is_tech ? field('แสดง', `<select data-f="scope">${opts({mine: 'เรื่องที่ฉันแจ้ง', all: 'ทุกเรื่อง (งานช่าง)'}, q.scope)}</select>`) : ''}
                ${field('สถานะ', `<select data-f="status">${opts({open: 'ที่ยังไม่ปิด', closed: 'ปิดแล้ว'}, q.status, 'ทั้งหมด')}</select>`)}
                ${meta.is_tech ? field('ผู้รับผิดชอบ', `<select data-f="assigned">${opts({me: 'งานที่มอบหมายให้ฉัน'}, q.assigned, 'ทั้งหมด')}</select>`) : ''}
                ${field('ค้นหา', `<input type="search" data-f="q" value="${esc(q.q)}" maxlength="100">`)}</form>
                ${res.data.length ? `<ul class="mnt-list">${res.data.map(r => `<li class="mnt-card"><a href="#repairs/${esc(r.id)}">
                    <strong>#${esc(r.id)} ${esc(r.title)}</strong>
                    <span>${stTag(r.status)} ${r.urgency === 'urgent' ? tag('urgent', 'ด่วน') : ''}</span>
                    <small>${esc(r.location)}${r.asset_code ? ' - ' + esc(r.asset_code) : ''}</small>
                    <small>แจ้งโดย ${esc(r.reported_by_name || '-')} เมื่อ ${esc(when(r.created_at))}${r.assigned_to_name ? ' - ผู้รับผิดชอบ ' + esc(r.assigned_to_name) : ''}</small></a></li>`).join('')}</ul>`
                    : '<p class="mnt-empty">ไม่มีรายการ</p>'}${pager(res, q.page)}</section>`;
        };
        let timer = null;
        host.addEventListener('change', e => { const f = e.target.dataset.f; if (!f) return; q[f] = e.target.value; q.page = 1; draw().catch(err => fail(err)); });
        host.addEventListener('input', e => { if (e.target.dataset.f !== 'q') return; q.q = e.target.value; q.page = 1; clearTimeout(timer); timer = setTimeout(() => draw().then(() => host.querySelector('[data-f="q"]')?.focus()).catch(err => fail(err)), 400); });
        host.addEventListener('submit', e => e.preventDefault());
        host.addEventListener('click', e => {
            const a = e.target.closest('[data-act]')?.dataset.act;
            if (a === 'prev') { q.page--; draw().catch(err => fail(err)); } else if (a === 'next') { q.page++; draw().catch(err => fail(err)); }
        });
        await draw();
    }

    async function repairForm(host, ctx, meta) {
        const key = C.uid();
        let asset = null;
        host.innerHTML = `<section class="mnt glass"><p><a href="#repairs">กลับรายการแจ้งซ่อม</a></p><h1>แจ้งซ่อมใหม่</h1>
            <form class="mnt-form" data-form="new" novalidate>
            ${field('ค้นหาครุภัณฑ์ด้วยเลขหรือชื่อ (ไม่บังคับ)', '<input type="search" data-f="asset-q" maxlength="80" autocomplete="off">')}
            <ul class="mnt-pick" data-pick hidden></ul><p class="mnt-chosen" data-chosen hidden></p>
            ${field('สถานที่', '<input name="location" maxlength="160" required placeholder="เช่น อาคาร 1 ห้อง 203">')}
            ${field('เรื่องที่แจ้ง', '<input name="title" maxlength="200" required placeholder="เช่น ก๊อกน้ำรั่ว">')}
            ${field('รายละเอียด', '<textarea name="detail" rows="4" maxlength="3000"></textarea>')}
            ${field('ความเร่งด่วน', `<select name="urgency">${opts(URG, 'normal')}</select>`)}
            ${field('รูปถ่าย (ไม่บังคับ ไม่เกิน ' + meta.max_photo_mb + ' MB)', '<input type="file" name="photo" accept="image/*">')}
            <button class="mnt-btn primary" type="submit">ส่งเรื่องแจ้งซ่อม</button></form></section>`;
        const pick = host.querySelector('[data-pick]'), chosen = host.querySelector('[data-chosen]'), form = host.querySelector('form');
        let timer = null;
        host.addEventListener('input', e => {
            if (e.target.dataset.f !== 'asset-q') return;
            clearTimeout(timer);
            const v = e.target.value.trim();
            if (!v) { pick.hidden = true; return; }
            timer = setTimeout(async () => {
                try {
                    const res = await ctx.api('/api/maintenance/assets/lookup?q=' + encodeURIComponent(v));
                    if (!ctx.alive()) return;
                    pick.innerHTML = res.data.length ? res.data.map(a => `<li><button type="button" class="mnt-btn" data-asset="${esc(a.id)}" data-code="${esc(a.asset_code)}" data-name="${esc(a.name)}" data-loc="${esc(a.location || '')}">${esc(a.asset_code)} ${esc(a.name)}</button></li>`).join('') : '<li class="mnt-empty">ไม่พบครุภัณฑ์</li>';
                    pick.hidden = false;
                } catch (err) { fail(err); }
            }, 300);
        });
        host.addEventListener('click', e => {
            const b = e.target.closest('[data-asset]'); if (!b) return;
            asset = +b.dataset.asset;
            chosen.textContent = 'ครุภัณฑ์ที่เลือก: ' + b.dataset.code + ' ' + b.dataset.name; chosen.hidden = false; pick.hidden = true;
            if (!form.elements.location.value) form.elements.location.value = b.dataset.loc;
        });
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const f = form.elements;
            if (!f.location.value.trim() || f.title.value.trim().length < 2) { toast('กรุณากรอกสถานที่และเรื่องที่แจ้ง', true); return; }
            const photo = f.photo.files[0];
            if (photo && photo.size > meta.max_photo_mb * 1048576) { toast('รูปใหญ่เกิน ' + meta.max_photo_mb + ' MB', true); return; }
            const fd = new FormData();
            fd.append('location', f.location.value.trim()); fd.append('title', f.title.value.trim()); fd.append('detail', f.detail.value); fd.append('urgency', f.urgency.value);
            if (asset) fd.append('asset_id', String(asset));
            if (photo) fd.append('photo', photo);
            const done = busy(form.querySelector('button[type=submit]'));
            try {
                const r = await ctx.api('/api/maintenance/requests', {method: 'POST', body: fd, idempotencyKey: key});
                toast('ส่งเรื่องแจ้งซ่อมแล้ว');
                window.SaanWork.go('repairs/' + r.id);
            } catch (err) { done(); fail(err); }
        });
    }

    async function repairDetail(host, ctx, meta, id) {
        const draw = async () => {
            const r = await ctx.api('/api/maintenance/requests/' + id);
            if (!ctx.alive()) return;
            const t = meta.is_tech;
            const targets = r.allowed || [];
            host.innerHTML = `<section class="mnt glass"><p><a href="#repairs">กลับรายการแจ้งซ่อม</a></p>
                <h1>#${esc(r.id)} ${esc(r.title)}</h1><p>${stTag(r.status)} ${r.urgency === 'urgent' ? tag('urgent', 'ด่วน') : ''}</p>
                <dl class="mnt-facts"><dt>สถานที่</dt><dd>${esc(r.location)}</dd>
                ${r.asset_code ? `<dt>ครุภัณฑ์</dt><dd>${esc(r.asset_code)} ${esc(r.asset_name)}${t ? ` (<a href="#assets/${esc(r.asset_id)}">ประวัติซ่อม</a>)` : ''}</dd>` : ''}
                <dt>ผู้แจ้ง</dt><dd>${esc(r.reported_by_name || '-')} เมื่อ ${esc(when(r.created_at))}</dd>
                <dt>ผู้รับผิดชอบ</dt><dd>${esc(r.assigned_to_name || '-')}</dd>
                ${r.detail ? `<dt>รายละเอียด</dt><dd class="mnt-pre">${esc(r.detail)}</dd>` : ''}
                ${r.resolution ? `<dt>${r.status === 'rejected' ? 'เหตุผล' : 'ผลการซ่อม'}</dt><dd class="mnt-pre">${esc(r.resolution)}</dd>` : ''}
                ${r.cost_satang !== null ? `<dt>ค่าใช้จ่าย</dt><dd>${esc(baht(r.cost_satang))} บาท</dd>` : ''}
                ${r.closed_at ? `<dt>ปิดเมื่อ</dt><dd>${esc(when(r.closed_at))}</dd>` : ''}</dl>
                ${r.has_photo ? `<a href="${esc(href('/api/maintenance/requests/' + r.id + '/photo'))}" target="_blank" rel="noopener"><img class="mnt-photo" alt="รูปประกอบการแจ้งซ่อม" src="${esc(href('/api/maintenance/requests/' + r.id + '/photo'))}"></a>` : ''}
                ${targets.length ? `<h2>ดำเนินการ</h2><form class="mnt-form" data-form="step" data-version="${esc(r.version)}">
                    ${field('เปลี่ยนสถานะเป็น', `<select name="to">${targets.map(s => `<option value="${esc(s)}">${esc(STATUS[s])}</option>`).join('')}</select>`)}
                    <div data-show="done">${field('สรุปผลการซ่อม (จำเป็น)', '<textarea name="resolution" rows="3" maxlength="3000"></textarea>')}${field('ค่าใช้จ่าย (บาท)', '<input name="cost" inputmode="decimal" placeholder="0.00">')}</div>
                    ${field('หมายเหตุ / เหตุผล (ถ้าไม่ดำเนินการต้องระบุ)', '<input name="note" maxlength="1000">')}
                    <button class="mnt-btn primary" type="submit">บันทึกสถานะ</button></form>` : ''}
                ${r.status === 'new' && (t || r.reported_by === meta.user_id) ? `<details class="mnt-details"><summary>แก้ไขเรื่องที่แจ้ง</summary><form class="mnt-form" data-form="edit" data-version="${esc(r.version)}">
                    ${field('สถานที่', `<input name="location" maxlength="160" required value="${esc(r.location)}">`)}${field('เรื่องที่แจ้ง', `<input name="title" maxlength="200" required value="${esc(r.title)}">`)}
                    ${field('รายละเอียด', `<textarea name="detail" rows="4" maxlength="3000">${esc(r.detail || '')}</textarea>`)}${field('ความเร่งด่วน', `<select name="urgency">${opts(URG, r.urgency)}</select>`)}
                    <button class="mnt-btn" type="submit">บันทึกการแก้ไข</button></form></details>` : ''}
                ${t && ['new', 'accepted', 'in_progress', 'waiting_parts'].includes(r.status) ? `<form class="mnt-form" data-form="assign" data-version="${esc(r.version)}">
                    ${field('มอบหมายให้', `<select name="user_id"><option value="">ยังไม่มอบหมาย</option>${meta.assignees.map(a => `<option value="${esc(a.id)}"${a.id === r.assigned_to ? ' selected' : ''}>${esc(a.name)}</option>`).join('')}</select>`)}
                    <button class="mnt-btn" type="submit">บันทึกการมอบหมาย</button></form>` : ''}
                <h2>ประวัติการดำเนินการ</h2><ol class="mnt-log">${r.logs.map(l => `<li><strong>${esc(STATUS[l.to_status] || l.to_status)}</strong> <small>${esc(when(l.at))} ${esc(String(l.at).slice(11, 16))} - ${esc(l.by_name || '-')}</small>${l.note ? `<p>${esc(l.note)}</p>` : ''}</li>`).join('')}</ol></section>`;
            sync();
        };
        const sync = () => {
            const sel = host.querySelector('[name=to]'); if (!sel) return;
            host.querySelector('[data-show=done]').hidden = sel.value !== 'done';
        };
        host.addEventListener('change', e => { if (e.target.name === 'to') sync(); });
        host.addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.target.closest('form[data-form]'); if (!f) return;
            const done = busy(f.querySelector('button[type=submit]'));
            try {
                if (f.dataset.form === 'step') {
                    const cost = toSatang(f.elements.cost.value);
                    if (cost === undefined) { done(); toast('ค่าใช้จ่ายไม่ถูกต้อง', true); return; }
                    const to = f.elements.to.value;
                    const body = {to, version: +f.dataset.version, note: f.elements.note.value};
                    if (to === 'done') { body.resolution = f.elements.resolution.value; body.cost_satang = cost; }
                    await ctx.api(`/api/maintenance/requests/${id}/transition`, {method: 'POST', body, idempotencyKey: C.uid()});
                } else if (f.dataset.form === 'edit') {
                    const e2 = f.elements;
                    if (!e2.location.value.trim() || e2.title.value.trim().length < 2) { done(); toast('กรุณากรอกสถานที่และเรื่องที่แจ้ง', true); return; }
                    await ctx.api('/api/maintenance/requests/' + id, {method: 'PUT', body: {version: +f.dataset.version, location: e2.location.value.trim(), title: e2.title.value.trim(), detail: e2.detail.value, urgency: e2.urgency.value}});
                } else {
                    const u = f.elements.user_id.value;
                    await ctx.api(`/api/maintenance/requests/${id}/assign`, {method: 'POST', body: {version: +f.dataset.version, user_id: u === '' ? null : +u}});
                }
                toast('บันทึกแล้ว');
                await draw();
            } catch (err) { done(); fail(err, () => draw().catch(() => {})); }
        });
        await draw();
    }

    // =============================================================== #assets
    async function assets(root, ctx) {
        const meta = await metaOf(ctx);
        if (!ctx.alive()) return;
        const host = mount(root);
        if (!meta.is_tech) { host.innerHTML = '<section class="mnt glass" role="alert"><h1>ครุภัณฑ์และพัสดุ</h1><p>บัญชีนี้ไม่ได้รับสิทธิ์ช่างซ่อมหรือเจ้าหน้าที่พัสดุ ผู้ดูแลระบบให้สิทธิ์ได้ในหน้าตั้งค่า</p></section>'; return; }
        const [p0, p1] = ctx.parts;
        const nav = `<nav class="mnt-tabs" aria-label="เมนูย่อย"><a href="#assets">ครุภัณฑ์</a><a href="#assets/stock">พัสดุ</a><a href="#assets/reports">รายงาน</a>${meta.is_admin ? '<a href="#assets/techs">ช่างซ่อม</a>' : ''}<a href="#repairs">งานแจ้งซ่อม</a></nav>`;
        host.innerHTML = nav + '<div data-body></div>';
        const body = host.querySelector('[data-body]');
        if (p0 === 'stock') return p1 && /^\d+$/.test(p1) ? stockMoves(body, ctx, +p1) : stock(body, ctx, meta);
        if (p0 === 'reports') return reports(body, ctx, meta);
        if (p0 === 'techs' && meta.is_admin) return techs(body, ctx);
        if (p0 === 'new') return assetForm(body, ctx, null);
        if (p0 && /^\d+$/.test(p0)) return assetDetail(body, ctx, +p0, p1 === 'edit');
        return assetList(body, ctx);
    }

    async function assetList(host, ctx) {
        const q = {q: '', category: '', status: '', page: 1};
        const draw = async () => {
            const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v !== '' && v != null));
            const res = await ctx.api('/api/maintenance/assets?' + qs);
            if (!ctx.alive()) return;
            const lab = new URLSearchParams(q.category ? {category: q.category} : {});
            host.innerHTML = `<section class="mnt glass"><div class="mnt-head"><h1>ทะเบียนครุภัณฑ์</h1><div class="mnt-actions">
                <a class="mnt-btn primary" href="#assets/new">เพิ่มครุภัณฑ์</a>
                <a class="mnt-btn" href="${esc(href('/api/maintenance/export/assets'))}" download>ส่งออก CSV</a>
                <a class="mnt-btn" href="${esc(href('/maintenance/labels' + (lab.toString() ? '?' + lab : '')))}" target="_blank" rel="noopener">พิมพ์ป้ายรหัส</a></div></div>
                <form class="mnt-filters" data-form="filter">${field('ค้นหา (เลข ชื่อ สถานที่)', `<input type="search" data-f="q" value="${esc(q.q)}" maxlength="100">`)}
                ${field('หมวด', `<select data-f="category"><option value="">ทุกหมวด</option>${res.categories.map(c => `<option value="${esc(c)}"${c === q.category ? ' selected' : ''}>${esc(c)}</option>`).join('')}</select>`)}
                ${field('สถานะ', `<select data-f="status">${opts(ASTATUS, q.status, 'ทุกสถานะ')}</select>`)}</form>
                <p class="mnt-sub">พบ ${esc(res.total)} รายการ</p>
                ${res.data.length ? `<ul class="mnt-list">${res.data.map(a => `<li class="mnt-card"><a href="#assets/${esc(a.id)}"><strong>${esc(a.asset_code)} ${esc(a.name)}</strong>
                    <span>${tag('as-' + a.status, ASTATUS[a.status] || a.status)}${a.category ? ' ' + esc(a.category) : ''}</span>
                    <small>${esc(a.location || '-')}${a.responsible_name ? ' - ผู้รับผิดชอบ ' + esc(a.responsible_name) : ''}</small></a></li>`).join('')}</ul>` : '<p class="mnt-empty">ไม่พบครุภัณฑ์</p>'}${pager(res, q.page)}</section>`;
        };
        let timer = null;
        host.addEventListener('change', e => { const f = e.target.dataset.f; if (!f) return; q[f] = e.target.value; q.page = 1; draw().catch(err => fail(err)); });
        host.addEventListener('input', e => { if (e.target.dataset.f !== 'q') return; q.q = e.target.value; q.page = 1; clearTimeout(timer); timer = setTimeout(() => draw().then(() => host.querySelector('[data-f="q"]')?.focus()).catch(err => fail(err)), 400); });
        host.addEventListener('submit', e => e.preventDefault());
        host.addEventListener('click', e => {
            const a = e.target.closest('[data-act]')?.dataset.act;
            if (a === 'prev') { q.page--; draw().catch(err => fail(err)); } else if (a === 'next') { q.page++; draw().catch(err => fail(err)); }
        });
        await draw();
    }

    async function assetForm(host, ctx, a) {
        const key = C.uid();
        const teachers = (await ctx.api('/api/maintenance/teachers')).data;
        if (!ctx.alive()) return;
        host.innerHTML = `<section class="mnt glass"><p><a href="${a ? '#assets/' + esc(a.id) : '#assets'}">กลับ</a></p><h1>${a ? 'แก้ไขครุภัณฑ์' : 'เพิ่มครุภัณฑ์'}</h1>
            <form class="mnt-form" novalidate>
            ${field('เลขครุภัณฑ์', `<input name="asset_code" maxlength="80" required value="${esc(a?.asset_code || '')}">`)}
            ${field('ชื่อ', `<input name="name" maxlength="200" required value="${esc(a?.name || '')}">`)}
            ${field('หมวด', `<input name="category" maxlength="80" value="${esc(a?.category || '')}">`)}
            ${field('สถานที่ตั้ง', `<input name="location" maxlength="160" value="${esc(a?.location || '')}">`)}
            ${field('วันที่ได้มา', `<input type="date" name="acquired_on" value="${esc(a?.acquired_on || '')}">`)}
            ${field('ราคา (บาท)', `<input name="price" inputmode="decimal" value="${esc(satangText(a?.price_satang))}">`)}
            ${field('ครูผู้รับผิดชอบ', `<select name="teacher"><option value="">ไม่ระบุ</option>${teachers.map(t => `<option value="${esc(t.id)}"${t.id === a?.responsible_teacher_id ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select>`)}
            ${a ? field('สถานะ',`<select name="status">${opts(ASTATUS, a.status)}</select>`) : ''}
            <button class="mnt-btn primary" type="submit">บันทึก</button></form></section>`;
        const form = host.querySelector('form');
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const f = form.elements, price = toSatang(f.price.value);
            if (price === undefined) { toast('ราคาไม่ถูกต้อง', true); return; }
            const body = {asset_code: f.asset_code.value.trim(), name: f.name.value.trim(), category: f.category.value.trim() || null, location: f.location.value.trim() || null,
                acquired_on: f.acquired_on.value || null, price_satang: price, responsible_teacher_id: f.teacher.value === '' ? null : +f.teacher.value};
            if (!body.asset_code || !body.name) { toast('กรุณากรอกเลขครุภัณฑ์และชื่อ', true); return; }
            const done = busy(form.querySelector('button[type=submit]'));
            try {
                if (a) { body.version = a.version; body.status = f.status.value; await ctx.api('/api/maintenance/assets/' + a.id, {method: 'PUT', body}); toast('บันทึกแล้ว'); window.SaanWork.go('assets/' + a.id); }
                else { const r = await ctx.api('/api/maintenance/assets', {method: 'POST', body, idempotencyKey: key}); toast('เพิ่มครุภัณฑ์แล้ว'); window.SaanWork.go('assets/' + r.id); }
            } catch (err) { done(); fail(err, a ? () => window.SaanWork.go('assets/' + a.id) : null); }
        });
    }

    async function assetDetail(host, ctx, id, edit) {
        const a = await ctx.api('/api/maintenance/assets/' + id);
        if (!ctx.alive()) return;
        if (edit) return assetForm(host, ctx, a);
        host.innerHTML = `<section class="mnt glass"><p><a href="#assets">กลับทะเบียนครุภัณฑ์</a></p><div class="mnt-head"><h1>${esc(a.asset_code)} ${esc(a.name)}</h1>
            <div class="mnt-actions"><a class="mnt-btn" href="#assets/${esc(a.id)}/edit">แก้ไข</a><a class="mnt-btn" href="${esc(href('/maintenance/labels?ids=' + a.id))}" target="_blank" rel="noopener">พิมพ์ป้าย</a></div></div>
            <dl class="mnt-facts"><dt>สถานะ</dt><dd>${tag('as-' + a.status, ASTATUS[a.status] || a.status)}</dd><dt>หมวด</dt><dd>${esc(a.category || '-')}</dd><dt>สถานที่</dt><dd>${esc(a.location || '-')}</dd>
            <dt>วันที่ได้มา</dt><dd>${esc(when(a.acquired_on) || '-')}</dd><dt>ราคา</dt><dd>${a.price_satang === null ? '-' : esc(baht(a.price_satang)) + ' บาท'}</dd>
            <dt>ผู้รับผิดชอบ</dt><dd>${esc(a.responsible_name || '-')}</dd></dl>
            <h2>ประวัติแจ้งซ่อม (${esc(a.history_count)} ครั้ง ค่าใช้จ่ายรวม ${esc(baht(a.history_cost_satang))} บาท)</h2>
            ${a.history.length ? `<ul class="mnt-list">${a.history.map(r => `<li class="mnt-card"><a href="#repairs/${esc(r.id)}"><strong>#${esc(r.id)} ${esc(r.title)}</strong><span>${stTag(r.status)}</span>
                <small>แจ้ง ${esc(when(r.created_at))}${r.closed_at ? ' ปิด ' + esc(when(r.closed_at)) : ''}${r.cost_satang !== null ? ' - ' + esc(baht(r.cost_satang)) + ' บาท' : ''}</small></a></li>`).join('')}</ul>` : '<p class="mnt-empty">ยังไม่เคยแจ้งซ่อม</p>'}</section>`;
    }

    // ---------------------------------------------------------------- stock
    async function stock(host, ctx) {
        const q = {q: '', below: '', page: 1};
        const draw = async () => {
            const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v !== '' && v != null));
            const res = await ctx.api('/api/maintenance/stock?' + qs);
            if (!ctx.alive()) return;
            host.innerHTML = `<section class="mnt glass"><div class="mnt-head"><h1>พัสดุ</h1><div class="mnt-actions"><a class="mnt-btn" href="${esc(href('/api/maintenance/export/stock'))}" download>ส่งออกพัสดุ CSV</a>
                <a class="mnt-btn" href="${esc(href('/api/maintenance/export/moves'))}" download>ส่งออกความเคลื่อนไหว CSV</a></div></div>
                <form class="mnt-filters" data-form="filter">${field('ค้นหา (รหัส ชื่อ)', `<input type="search" data-f="q" value="${esc(q.q)}" maxlength="100">`)}
                ${field('แสดง', `<select data-f="below">${opts({1: 'เฉพาะที่ถึงจุดสั่งซื้อ'}, q.below, 'ทั้งหมด')}</select>`)}</form>
                <details class="mnt-details"><summary>เพิ่มรายการพัสดุ</summary><form class="mnt-form" data-form="item">
                ${field('รหัส', '<input name="code" maxlength="60" required>')}${field('ชื่อ', '<input name="name" maxlength="200" required>')}${field('หน่วยนับ', '<input name="unit" maxlength="30" required placeholder="เช่น หลอด">')}
                ${field('จุดสั่งซื้อ (0 = ไม่แจ้งเตือน)', '<input name="reorder" type="number" min="0" step="1" value="0">')}${field('ยอดยกมา', '<input name="initial" type="number" min="0" step="1" value="0">')}
                <button class="mnt-btn primary" type="submit">เพิ่มพัสดุ</button></form></details>
                ${res.data.length ? `<ul class="mnt-list">${res.data.map(i => `<li class="mnt-card mnt-item"><div><strong>${esc(i.code)} ${esc(i.name)}</strong>
                    <span>คงเหลือ <b>${esc(i.qty_on_hand)}</b> ${esc(i.unit)} ${i.below_reorder ? tag('urgent', 'ถึงจุดสั่งซื้อ') : ''}</span><small>จุดสั่งซื้อ ${esc(i.reorder_level)} - <a href="#assets/stock/${esc(i.id)}">ประวัติเคลื่อนไหว</a></small>
                    <details class="mnt-details"><summary>แก้ไขรายการ</summary><form class="mnt-form" data-form="itemedit" data-id="${esc(i.id)}" data-version="${esc(i.version)}">
                    ${field('รหัส', `<input name="code" maxlength="60" required value="${esc(i.code)}">`)}${field('ชื่อ', `<input name="name" maxlength="200" required value="${esc(i.name)}">`)}${field('หน่วยนับ', `<input name="unit" maxlength="30" required value="${esc(i.unit)}">`)}
                    ${field('จุดสั่งซื้อ (0 = ไม่แจ้งเตือน)', `<input name="reorder" type="number" min="0" step="1" value="${esc(i.reorder_level)}">`)}
                    <button class="mnt-btn" type="submit">บันทึกการแก้ไข</button></form></details></div>
                    <form class="mnt-move" data-form="move" data-id="${esc(i.id)}"><select name="kind" aria-label="ประเภท">${opts(KINDS, 'out')}</select>
                    <input name="qty" type="number" step="1" required aria-label="จำนวน" placeholder="จำนวน"><input name="ref" maxlength="120" aria-label="อ้างอิง" placeholder="อ้างอิง เช่น งานซ่อม #12">
                    <button class="mnt-btn" type="submit">บันทึก</button></form></li>`).join('')}</ul>` : '<p class="mnt-empty">ไม่มีรายการพัสดุ</p>'}${pager(res, q.page)}</section>`;
        };
        let timer = null;
        host.addEventListener('change', e => { const f = e.target.dataset.f; if (!f) return; q[f] = e.target.value; q.page = 1; draw().catch(err => fail(err)); });
        host.addEventListener('input', e => { if (e.target.dataset.f !== 'q') return; q.q = e.target.value; q.page = 1; clearTimeout(timer); timer = setTimeout(() => draw().then(() => host.querySelector('[data-f="q"]')?.focus()).catch(err => fail(err)), 400); });
        host.addEventListener('click', e => {
            const a = e.target.closest('[data-act]')?.dataset.act;
            if (a === 'prev') { q.page--; draw().catch(err => fail(err)); } else if (a === 'next') { q.page++; draw().catch(err => fail(err)); }
        });
        // one idempotency key per rendered move form: a double tap or a retry after a lost response never withdraws twice
        const keys = new WeakMap();
        host.addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.target.closest('form[data-form]'); if (!f) return;
            if (f.dataset.form === 'filter') return;
            const done = busy(f.querySelector('button[type=submit]'));
            try {
                if (f.dataset.form === 'item') {
                    await ctx.api('/api/maintenance/stock', {method: 'POST', idempotencyKey: C.uid(), body: {code: f.elements.code.value.trim(), name: f.elements.name.value.trim(), unit: f.elements.unit.value.trim(),
                        reorder_level: +f.elements.reorder.value || 0, initial_qty: +f.elements.initial.value || 0}});
                    toast('เพิ่มพัสดุแล้ว');
                } else if (f.dataset.form === 'itemedit') {
                    const e2 = f.elements;
                    await ctx.api('/api/maintenance/stock/' + f.dataset.id, {method: 'PUT', body: {version: +f.dataset.version, code: e2.code.value.trim(), name: e2.name.value.trim(), unit: e2.unit.value.trim(), reorder_level: +e2.reorder.value || 0}});
                    toast('บันทึกแล้ว');
                } else {
                    const qty = parseInt(f.elements.qty.value, 10);
                    if (!Number.isInteger(qty) || qty === 0) { done(); toast('กรุณาระบุจำนวนเป็นจำนวนเต็ม', true); return; }
                    if (!keys.has(f)) keys.set(f, C.uid());
                    await ctx.api(`/api/maintenance/stock/${f.dataset.id}/moves`, {method: 'POST', idempotencyKey: keys.get(f), body: {kind: f.elements.kind.value, qty, ref: f.elements.ref.value.trim() || null}});
                    toast('บันทึกการเคลื่อนไหวแล้ว');
                }
                await draw();
            } catch (err) { done(); fail(err); if (err?.status >= 400 && err.status < 500) keys.delete(f); if (err?.status === 409) draw().catch(() => {}); } // keep the key after a network error or 5xx: the server may have committed, so a retry must replay
        });
        await draw();
    }

    async function stockMoves(host, ctx, id) {
        let page = 1;
        const draw = async () => {
            const res = await ctx.api(`/api/maintenance/stock/${id}/moves?page=${page}`);
            if (!ctx.alive()) return;
            host.innerHTML = `<section class="mnt glass"><p><a href="#assets/stock">กลับรายการพัสดุ</a></p><h1>${esc(res.item.code)} ${esc(res.item.name)}</h1><p>คงเหลือ <b>${esc(res.item.qty_on_hand)}</b> ${esc(res.item.unit)}</p>
                ${res.data.length ? `<ul class="mnt-list">${res.data.map(m => `<li class="mnt-card"><div><strong>${esc(KINDS[m.kind]?.split(' ')[0] || m.kind)} ${m.kind === 'adjust' && m.qty > 0 ? '+' : ''}${esc(m.qty)}</strong>
                    <small>${esc(when(m.at))} ${esc(String(m.at).slice(11, 16))} - ${esc(m.by_name || '-')}${m.ref ? ' - ' + esc(m.ref) : ''}${m.note ? ' - ' + esc(m.note) : ''}</small></div></li>`).join('')}</ul>` : '<p class="mnt-empty">ยังไม่มีความเคลื่อนไหว</p>'}${pager(res, page)}</section>`;
        };
        host.addEventListener('click', e => {
            const a = e.target.closest('[data-act]')?.dataset.act;
            if (a === 'prev') { page--; draw().catch(err => fail(err)); } else if (a === 'next') { page++; draw().catch(err => fail(err)); }
        });
        await draw();
    }

    // -------------------------------------------------------------- reports
    async function reports(host, ctx, meta) {
        let year = meta.year;
        const draw = async () => {
            const [age, cost, low] = await Promise.all([ctx.api('/api/maintenance/reports/open-by-age'), ctx.api('/api/maintenance/reports/cost-by-month?year=' + year), ctx.api('/api/maintenance/reports/stock-below')]);
            if (!ctx.alive()) return;
            host.innerHTML = `<section class="mnt glass"><div class="mnt-head"><h1>รายงาน</h1><div class="mnt-actions"><a class="mnt-btn" href="${esc(href('/api/maintenance/export/requests'))}" download>ส่งออกงานแจ้งซ่อม CSV</a></div></div>
                <h2>งานค้างตามอายุ (${esc(age.total)} งาน)</h2>
                <ul class="mnt-buckets">${Object.entries(BUCKETS).map(([k, l]) => `<li><b>${esc(age.buckets[k])}</b><span>${esc(l)}</span></li>`).join('')}</ul>
                ${age.data.length ? `<ul class="mnt-list">${age.data.map(r => `<li class="mnt-card"><a href="#repairs/${esc(r.id)}"><strong>#${esc(r.id)} ${esc(r.title)}</strong><span>${stTag(r.status)} ${r.urgency === 'urgent' ? tag('urgent', 'ด่วน') : ''} ค้าง ${esc(r.age_days)} วัน</span>
                    <small>${esc(r.location)}${r.assigned_to_name ? ' - ' + esc(r.assigned_to_name) : ' - ยังไม่มอบหมาย'}</small></a></li>`).join('')}</ul>` : '<p class="mnt-empty">ไม่มีงานค้าง</p>'}
                <h2>ค่าซ่อมรายเดือน</h2>${field('ปี พ.ศ.', `<select data-f="year">${[0, 1, 2, 3, 4].map(d => meta.year - d).map(y => `<option value="${y}"${y === year ? ' selected' : ''}>${y + 543}</option>`).join('')}</select>`)}
                <table class="mnt-table"><thead><tr><th>เดือน</th><th>งานที่ปิด</th><th>ค่าใช้จ่าย (บาท)</th></tr></thead><tbody>${cost.months.map(m => `<tr><td data-l="เดือน">${esc(MONTHS[m.month - 1])}</td><td data-l="งานที่ปิด">${esc(m.count)}</td><td data-l="ค่าใช้จ่าย">${esc(baht(m.cost_satang))}</td></tr>`).join('')}</tbody>
                <tfoot><tr><th>รวม</th><th>${esc(cost.total_count)}</th><th>${esc(baht(cost.total_cost_satang))}</th></tr></tfoot></table>
                <h2>พัสดุที่ถึงจุดสั่งซื้อ (${esc(low.data.length)} รายการ)</h2>
                ${low.data.length ? `<ul class="mnt-list">${low.data.map(i => `<li class="mnt-card"><a href="#assets/stock/${esc(i.id)}"><strong>${esc(i.code)} ${esc(i.name)}</strong><span>คงเหลือ ${esc(i.qty_on_hand)} ${esc(i.unit)} (จุดสั่งซื้อ ${esc(i.reorder_level)})</span></a></li>`).join('')}</ul>` : '<p class="mnt-empty">ไม่มีพัสดุที่ต้องสั่งเพิ่ม</p>'}</section>`;
        };
        host.addEventListener('change', e => { if (e.target.dataset.f === 'year') { year = +e.target.value; draw().catch(err => fail(err)); } });
        await draw();
    }

    // ---------------------------------------------------------- technicians
    async function techs(host, ctx) {
        const draw = async () => {
            const res = await ctx.api('/api/maintenance/technicians');
            if (!ctx.alive()) return;
            host.innerHTML = `<section class="mnt glass"><h1>ช่างซ่อมและเจ้าหน้าที่พัสดุ</h1><p class="mnt-sub">ผู้อำนวยการและผู้ดูแลระบบมีสิทธิ์อยู่แล้ว เลือกบุคลากรอื่นที่ให้รับเรื่อง ปิดงาน และจัดการครุภัณฑ์/พัสดุ</p>
                <form class="mnt-form" data-form="techs"><fieldset><legend>บุคลากร</legend><div class="mnt-checks">${res.candidates.map(c => `<label class="mnt-check"><input type="checkbox" name="u" value="${esc(c.id)}"${res.technician_user_ids.includes(c.id) ? ' checked' : ''}> ${esc(c.name)}</label>`).join('') || '<p class="mnt-empty">ไม่มีบัญชีให้เลือก</p>'}</div></fieldset>
                ${field('เก็บรูปแจ้งซ่อมหลังปิดงานกี่เดือน (0 = เก็บไว้ไม่ลบ)', `<input name="months" type="number" min="0" max="120" step="1" value="${esc(res.photo_retention_months)}">`)}
                <button class="mnt-btn primary" type="submit">บันทึกสิทธิ์</button></form></section>`;
            ver = res.version;
        };
        let ver = 0;
        host.addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.target.closest('[data-form="techs"]'); if (!f) return;
            const done = busy(f.querySelector('button[type=submit]'));
            try {
                await ctx.api('/api/maintenance/technicians', {method: 'PUT', body: {version: ver, photo_retention_months: Math.max(0, parseInt(f.elements.months.value, 10) || 0), technician_user_ids: [...f.querySelectorAll('input[name=u]:checked')].map(i => +i.value)}});
                toast('บันทึกสิทธิ์แล้ว'); await draw();
            } catch (err) { done(); fail(err, () => draw().catch(() => {})); }
        });
        await draw();
    }

    window.SaanWork.register('repairs', {title: 'แจ้งซ่อม', render: repairs});
    window.SaanWork.register('assets', {title: 'ครุภัณฑ์และพัสดุ', render: assets});
})();
