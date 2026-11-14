'use strict';
// web-cms F4: staff page #hubs (group hubs: work groups and subject groups) for the /work shell.
// Everyone with the menu entry can read; the server decides who may write (admin, or the head of that group) and answers 403 otherwise.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const api = (ctx, url, opts) => ctx.api(url, opts);
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const href = path => base() + path;
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 4000);
    };
    const fail = (err, reload) => {
        if (err?.name === 'AbortError') return;
        if (err?.status === 413) { toast('ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ กรุณาแบ่งอัปโหลดทีละไม่กี่ไฟล์', true); return; }
        if (err?.status === 409 && reload) { toast(err.message + ' ระบบโหลดข้อมูลล่าสุดให้แล้ว', true); reload(); return; }
        toast(err?.message || 'เกิดข้อผิดพลาด', true);
    };
    const field = (label, control, hint) => `<label class="hub-field"><span>${esc(label)}</span>${control}${hint ? `<small>${esc(hint)}</small>` : ''}</label>`;
    const opts = (items, current, blank) => (blank !== undefined ? `<option value="">${esc(blank)}</option>` : '')
        + items.map(([k, v]) => `<option value="${esc(k)}"${String(k) === String(current) ? ' selected' : ''}>${esc(v)}</option>`).join('');
    const thDate = iso => window.SaanCore.date(iso, {day: 'numeric', month: 'short', year: 'numeric'});
    const bytes = n => !n ? '' : n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
    const num = n => Number(n || 0).toLocaleString('th-TH');
    const STATUS = {published: 'เผยแพร่แล้ว', draft: 'ฉบับร่าง'};
    // F5b: committee typed one per line as "name | role"; trial-balance month and Buddhist-era year
    const committeeText = rows => (rows || []).map(r => r.role ? r.name + ' | ' + r.role : r.name).join('\n');
    const parseCommittee = text => String(text || '').split('\n').map(l => l.trim()).filter(Boolean).map(l => {
        const i = l.indexOf('|');
        return i < 0 ? {name: l, role: ''} : {name: l.slice(0, i).trim(), role: l.slice(i + 1).trim()};
    }).filter(r => r.name);
    const beYear = () => new Date().getFullYear() + 543;
    const periodFields = (d, vals) => `<div class="hub-period" hidden>
        ${field('เดือนของรายงาน', '<select name="period_month">' + opts(Object.entries(d.months), vals.month, 'เลือกเดือน') + '</select>')}
        ${field('ปี พ.ศ. ของรายงาน', `<input name="period_year" type="number" min="${beYear() - 20}" max="${beYear()}" value="${esc(vals.year || beYear())}">`, 'เดือนตุลาคมถึงธันวาคมนับเข้าปีงบประมาณถัดไป เช่น ตุลาคม 2569 อยู่ในปีงบประมาณ 2570')}
        <label class="hub-check"><input type="checkbox" name="replace"> แทนที่ไฟล์เดิมของเดือนนี้ (ไฟล์เดิมถูกถอนและเก็บไว้ภายในเท่านั้น)</label></div>`;
    // shows the period fields and narrows the file input to PDF while the chosen category has a file rule
    const bindRule = (form, d) => {
        const sel = form.querySelector('[name=category_id]'), box = form.querySelector('.hub-period'), file = form.querySelector('[type=file]'), link = form.querySelector('[name=external_url]');
        const sync = () => {
            const c = d.categories.find(x => String(x.id) === sel.value), rule = c ? c.rule : null;
            if (box) box.hidden = rule !== 'monthly';
            const pm = form.querySelector('[name=period_month]');
            if (pm) pm.required = rule === 'monthly';
            // a monthly report's file is never swapped inside the edit form (the old file would be lost): add a new document with "replace" instead
            if (file?.dataset.hideMonthly) { file.closest('label').hidden = rule === 'monthly'; if (rule === 'monthly') file.value = ''; }
            if (file) { file.accept = rule ? '.pdf' : '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp'; file.multiple = rule !== 'monthly' && file.dataset.multi === '1'; }
            if (link) { link.disabled = !!rule; link.closest('label').hidden = !!rule; }
        };
        sel.addEventListener('change', sync);
        sync();
    };

    // ---------------- list ----------------
    async function list(root, ctx) {
        const data = await api(ctx, '/api/cms/hubs');
        if (!ctx.alive()) return;
        const host = document.createElement('section');
        host.className = 'hub-admin glass';
        root.append(host);
        const draw = () => {
            host.innerHTML = `<div class="hub-head"><h1>หน้ากลุ่มงานและกลุ่มสาระการเรียนรู้</h1></div>
                <p class="hub-hint">แต่ละหน้ามีรายชื่อบุคลากรของกลุ่ม กล่องเอกสารแยกหมวด และข่าวของกลุ่ม ผู้ดูแลระบบและหัวหน้ากลุ่มนั้นจัดการหน้าได้ คนอื่นดูได้อย่างเดียว</p>
                ${data.can_create ? `<form class="hub-form" id="hub-new" novalidate><h2>สร้างหน้ากลุ่มใหม่</h2>
                    ${field('ชื่อหน้า', '<input name="title" required maxlength="160" placeholder="เช่น กลุ่มงานบริหารวิชาการ">')}
                    ${field('ที่อยู่หน้า (ภาษาอังกฤษพิมพ์เล็ก ตัวเลข และ -)', '<input name="slug" required maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="academic">', 'เว็บไซต์จะเป็น /portal/groups/ตามด้วยที่อยู่นี้')}
                    ${field('ประเภท', '<select name="kind">' + opts(Object.entries(data.kinds), 'work') + '</select>')}
                    ${field('ชื่อกลุ่มในทะเบียนบุคลากร', '<input name="group_name" maxlength="120" placeholder="เช่น บริหารวิชาการ">', 'ต้องตรงกับช่อง กลุ่มงาน หรือ ฝ่าย/กลุ่มสาระ ในทะเบียนบุคลากร จึงจะแสดงบุคลากรและให้หัวหน้ากลุ่มจัดการหน้าได้')}
                    <button class="hub-btn primary" type="submit">สร้างหน้า</button></form>
                    <form class="hub-form" id="hub-tpl" novalidate><h2>สร้างจากเทมเพลต (สมาคม และรายงานงบทดลองรายเดือน)</h2>
                    ${field('เทมเพลต', '<select name="template">' + opts(Object.entries(data.templates).map(([k, t]) => [k, t.label]), 'alumni') + '</select>', 'สมาคมได้ส่วนเกี่ยวกับ คณะกรรมการ ติดต่อ หมวดข่าวของตัวเอง และหมวดรายชื่อแบบ PDF หน้าที่สร้างเป็นฉบับร่างจนกว่าจะเผยแพร่')}
                    ${field('ชื่อหน้า', '<input name="title" maxlength="160" placeholder="เว้นว่างเพื่อใช้ชื่อตามเทมเพลต">')}
                    ${field('ที่อยู่หน้า (ภาษาอังกฤษพิมพ์เล็ก ตัวเลข และ -)', '<input name="slug" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="เว้นว่างเพื่อใช้ตามเทมเพลต">')}
                    <button class="hub-btn primary" type="submit">สร้างจากเทมเพลต</button></form>` : ''}
                <ul class="hub-cards">${data.data.map(h => `<li class="hub-card">
                    <div class="hub-card-main"><strong>${esc(h.title)}</strong>
                        <small>${esc(h.kind_label)}${h.group_name ? ' · ' + esc(h.group_name) : ''}</small>
                        <span class="hub-badge ${esc(h.status)}">${esc(STATUS[h.status] || h.status)}</span>
                        <small>เอกสาร ${num(h.documents_published)} จาก ${num(h.documents)} · ดาวน์โหลดรวม ${num(h.downloads)} ครั้ง${h.can_manage ? '' : ' · ดูอย่างเดียว'}</small></div>
                    <div class="hub-actions"><a class="hub-btn primary" href="#hubs/${h.id}">${h.can_manage ? 'จัดการ' : 'ดูรายละเอียด'}</a>
                        <a class="hub-btn" href="${esc(href('/hub-preview/' + h.id))}" target="_blank" rel="noopener">ดูตัวอย่าง</a></div></li>`).join('') || '<li class="hub-empty">ยังไม่มีหน้ากลุ่ม</li>'}</ul>`;
            host.querySelector('#hub-new')?.addEventListener('submit', async ev => {
                ev.preventDefault();
                const f = ev.currentTarget;
                if (!f.reportValidity()) return;
                const body = Object.fromEntries(new FormData(f).entries());
                try { const d = await api(ctx, '/api/cms/hubs', {method: 'POST', body}); toast('สร้างหน้ากลุ่มแล้ว'); location.hash = '#hubs/' + d.hub.id; }
                catch (e) { fail(e); }
            });
            host.querySelector('#hub-tpl')?.addEventListener('submit', async ev => {
                ev.preventDefault();
                const f = ev.currentTarget;
                if (!f.reportValidity()) return;
                const body = Object.fromEntries([...new FormData(f).entries()].filter(([, v]) => String(v).trim() !== ''));
                try { const d = await api(ctx, '/api/cms/hubs/template', {method: 'POST', body}); toast('สร้างหน้าจากเทมเพลตแล้ว (ฉบับร่าง)'); location.hash = '#hubs/' + d.hub.id; }
                catch (e) { fail(e); }
            });
        };
        draw();
    }

    // ---------------- one hub ----------------
    async function detail(root, ctx, id) {
        const state = {category_id: '', status: '', q: '', page: 1, tab: 'docs'};
        const host = document.createElement('section');
        host.className = 'hub-admin glass';
        root.append(host);
        let d = null;
        const load = async () => {
            const qs = new URLSearchParams(Object.entries({category_id: state.category_id, status: state.status, q: state.q, page: state.page}).filter(([, v]) => v !== '' && v !== null));
            d = await api(ctx, '/api/cms/hubs/' + id + (qs.toString() ? '?' + qs : ''));
            if (ctx.alive()) draw();
        };
        const reload = () => load().catch(e => fail(e));
        const act = async (fn, ok) => { try { await fn(); if (ok) toast(ok); await load(); } catch (e) { fail(e, reload); } };

        const catLabel = c => (c.parent_id ? '— ' : '') + c.label + (c.is_hidden ? ' (ซ่อน)' : '');
        const catOptions = (current, blank) => {
            const top = d.categories.filter(c => c.parent_id === null);
            const rows = [];
            top.forEach(c => { rows.push(c); d.categories.filter(s => s.parent_id === c.id).forEach(s => rows.push(s)); });
            return opts(rows.map(c => [c.id, catLabel(c)]), current, blank);
        };

        function drawSettings() {
            const h = d.hub, w = d.can.manage, a = d.can.admin;
            const dis = ok => ok ? '' : ' disabled';
            return `<form class="hub-form" id="hub-settings" novalidate><h2>ข้อมูลหน้า</h2>
                ${field('ชื่อหน้า', `<input name="title" maxlength="160" required value="${esc(h.title)}"${dis(w)}>`)}
                ${field('ที่อยู่หน้า', `<input name="slug" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="${esc(h.slug)}"${dis(a)}>`, a ? '' : 'เฉพาะผู้ดูแลระบบเปลี่ยนได้')}
                ${field('ประเภท', `<select name="kind"${dis(a)}>${opts(Object.entries(d.kinds), h.kind)}</select>`)}
                ${field('ชื่อกลุ่มในทะเบียนบุคลากร', `<input name="group_name" maxlength="120" value="${esc(h.group_name || '')}"${dis(a)}>`, `พบหัวหน้า ${d.staff.heads} คน และบุคลากร ${d.staff.members} คนที่แสดงสู่สาธารณะ (หัวหน้าคือตำแหน่งที่ขึ้นต้นด้วย "หัวหน้า")`)}
                ${field('คำอธิบายหน้า', `<textarea name="description" rows="3" maxlength="4000"${dis(w)}>${esc(h.description || '')}</textarea>`)}
                ${field('คณะกรรมการ (พิมพ์บรรทัดละคน: ชื่อ | ตำแหน่ง)', `<textarea name="committee_text" rows="5" maxlength="6000"${dis(w)}>${esc(committeeText(h.committee))}</textarea>`, 'ใส่เฉพาะผู้ดำรงตำแหน่งที่ประกาศเปิดเผยได้ ไม่เกิน 30 คน รายชื่อสมาชิกทั้งหมดให้อัปโหลดเป็นไฟล์ PDF ในหมวดรายชื่อ')}
                ${field('ช่องทางติดต่อ (แสดงสู่สาธารณะ)', `<textarea name="contact" rows="3" maxlength="1000"${dis(w)}>${esc(h.contact || '')}</textarea>`)}
                ${field('หมวดข่าวของกลุ่ม', `<select name="news_category"${dis(w)}>${opts(Object.entries(d.news_categories), h.news_category, 'ไม่ผูกหมวดข่าว')}</select>`)}
                ${field('ป้ายกำกับข่าว (tag) ของกลุ่ม', `<input name="news_tag" maxlength="40" value="${esc(h.news_tag || '')}"${dis(w)}>`, `ข่าวที่ติดป้ายนี้หรืออยู่ในหมวดข้างบนจะแสดงในหน้ากลุ่ม (ตอนนี้ ${d.news} ข่าว)`)}
                ${field('จำนวนเอกสารต่อกล่อง', `<input name="box_limit" type="number" min="1" max="20" value="${esc(h.box_limit)}"${dis(w)}>`)}
                ${field('จำนวนข่าวในหน้า', `<input name="news_limit" type="number" min="1" max="20" value="${esc(h.news_limit)}"${dis(w)}>`)}
                ${field('สถานะ', `<select name="status"${dis(w)}>${opts(Object.entries(STATUS), h.status)}</select>`)}
                ${w ? field('บันทึกภายใน (ไม่แสดงสู่สาธารณะ)', `<textarea name="internal_note" rows="2" maxlength="2000">${esc(h.internal_note || '')}</textarea>`) : ''}
                ${w ? '<button class="hub-btn primary" type="submit">บันทึกข้อมูลหน้า</button>' : '<p class="hub-hint">คุณดูข้อมูลได้อย่างเดียว</p>'}</form>`;
        }

        function drawCategories() {
            const w = d.can.manage;
            const top = d.categories.filter(c => c.parent_id === null);
            const row = (c, i, n) => `<li class="hub-cat${c.parent_id ? ' sub' : ''}" data-id="${c.id}" data-version="${c.version}">
                <input class="hub-cat-label" value="${esc(c.label)}" maxlength="120" aria-label="ชื่อหมวด"${w ? '' : ' disabled'}>
                <small>${num(c.documents)} เอกสาร${c.rule ? ' · ' + esc(d.rules[c.rule] || c.rule) : ''}</small>
                ${w ? `<span class="hub-actions">${c.parent_id ? '' : `<button type="button" class="hub-btn" data-up aria-label="เลื่อนขึ้น"${i === 0 ? ' disabled' : ''}>ขึ้น</button><button type="button" class="hub-btn" data-down aria-label="เลื่อนลง"${i === n - 1 ? ' disabled' : ''}>ลง</button>`}
                    <label class="hub-check"><input type="checkbox" data-hide${c.is_hidden ? ' checked' : ''}> ซ่อน</label>
                    <button type="button" class="hub-btn" data-save>บันทึกชื่อ</button><button type="button" class="hub-btn danger" data-del>ลบ</button></span>` : ''}</li>`;
            return `<div class="hub-block"><h2>หมวดเอกสาร</h2><p class="hub-hint">แต่ละหมวดคือหนึ่งกล่องในหน้ากลุ่ม ซ่อนหมวดเพื่อซ่อนกล่องและเอกสารทั้งหมดในหมวด</p>
                <ul class="hub-cats">${top.map((c, i) => row(c, i, top.length) + d.categories.filter(s => s.parent_id === c.id).map(s => row(s, 0, 1)).join('')).join('')}</ul>
                ${w ? `<form class="hub-form" id="cat-new" novalidate>${field('เพิ่มหมวดใหม่', '<input name="label" required maxlength="120" placeholder="ชื่อหมวด">')}
                    ${field('เป็นหมวดย่อยของ', '<select name="parent_id">' + opts(top.map(c => [c.id, c.label]), '', 'หมวดหลัก (ไม่เป็นหมวดย่อย)') + '</select>')}
                    ${field('กฎของไฟล์ในหมวด', '<select name="rule">' + opts(Object.entries(d.rules), '', 'ไม่จำกัด (PDF, Office, รูปภาพ หรือลิงก์)') + '</select>', 'หมวดรายเดือนใช้กับงบทดลอง: เลือกเดือนและปี พ.ศ. ตอนอัปโหลด และแสดงแยกตามปีงบประมาณ หมวดที่มีกฎไม่มีหมวดย่อย')}
                    <button class="hub-btn primary" type="submit">เพิ่มหมวด</button></form>` : ''}</div>`;
        }

        // order is per category: only when the list shows exactly one whole category (no status or text filter, everything on one page)
        function canOrder() {
            return !!state.category_id && !state.status && !state.q && d.documents.length > 1 && d.meta.total <= d.meta.per_page;
        }

        function drawDocuments() {
            const w = d.can.manage;
            const pages = Math.max(1, Math.ceil(d.meta.total / d.meta.per_page));
            return `<div class="hub-block"><h2>เอกสาร</h2>
                ${w ? `<form class="hub-form" id="doc-new" novalidate><h3>เพิ่มเอกสาร</h3>
                    ${field('หมวด', '<select name="category_id" required>' + catOptions('', 'เลือกหมวด') + '</select>')}
                    ${field('ไฟล์ (เลือกได้หลายไฟล์พร้อมกัน สูงสุด 20)', '<input name="files" type="file" multiple data-multi="1" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp">', 'รับเฉพาะ PDF, Word, Excel, PowerPoint และรูปภาพ ชื่อเอกสารตั้งจากชื่อไฟล์ แก้ภายหลังได้ หมวดที่จำกัดไว้รับเฉพาะ PDF')}
                    ${periodFields(d, {})}
                    ${field('หรือลิงก์ภายนอก (https://)', '<input name="external_url" type="url" maxlength="500" placeholder="https://">')}
                    ${field('ชื่อเอกสาร (จำเป็นเมื่อใช้ลิงก์ ใช้กับไฟล์เดียวเท่านั้น)', '<input name="title" maxlength="200">')}
                    ${field('วันที่ของเอกสาร', `<input name="doc_date" type="date" value="${esc(window.SaanCore.today())}">`)}
                    <label class="hub-check"><input type="checkbox" name="is_published" checked> เผยแพร่ทันที</label>
                    <button class="hub-btn primary" type="submit">อัปโหลด</button></form>` : ''}
                <form class="hub-filters" id="doc-filter">
                    ${field('หมวด', '<select name="category_id">' + catOptions(state.category_id, 'ทุกหมวด') + '</select>')}
                    ${field('สถานะ', '<select name="status">' + opts(Object.entries(STATUS), state.status, 'ทั้งหมด') + '</select>')}
                    ${field('ค้นหาชื่อ', `<input type="search" name="q" maxlength="100" value="${esc(state.q)}">`)}
                    <button class="hub-btn" type="submit">กรอง</button></form>
                <p class="hub-hint">พบ ${num(d.meta.total)} รายการ${w ? ' · ติ๊กเลือกแล้วเผยแพร่หรือถอนทีละหลายรายการได้' : ''}</p>
                ${w ? '<div class="hub-actions"><button type="button" class="hub-btn" data-bulk="1">เผยแพร่ที่เลือก</button><button type="button" class="hub-btn" data-bulk="0">ถอนที่เลือก</button></div>' : ''}
                <ul class="hub-docs">${d.documents.map(x => `<li class="hub-doc" data-id="${x.id}" data-version="${x.version}">
                    ${w ? '<input type="checkbox" class="hub-pick" aria-label="เลือกเอกสารนี้">' : ''}
                    <div class="hub-doc-main"><strong>${esc(x.title)}</strong>
                        <small>${x.period_label ? esc(x.period_label) : esc(thDate(x.doc_date))} · ${esc(x.type)}${x.bytes ? ' · ' + esc(bytes(x.bytes)) : ''} · ${esc((d.categories.find(c => c.id === x.category_id) || {}).label || '-')}</small>
                        <small>ดาวน์โหลด ${num(x.downloads)} ครั้ง (30 วันล่าสุด ${num(x.downloads_30d)})</small>
                        <span class="hub-badge ${x.is_published ? 'published' : 'draft'}">${x.is_published ? 'เผยแพร่แล้ว' : 'ฉบับร่าง'}</span>${x.superseded ? '<span class="hub-badge draft">ถูกแทนที่ (เก็บไว้ภายใน)</span>' : ''}${x.pinned ? '<span class="hub-badge pinned">ปักหมุด</span>' : ''}</div>
                    <div class="hub-actions">${x.media_id ? `<a class="hub-btn" href="${esc(href('/api/cms/hubs/documents/' + x.id + '/file'))}" target="_blank" rel="noopener">เปิดไฟล์</a>` : `<a class="hub-btn" href="${esc(x.external_url || '#')}" target="_blank" rel="noopener noreferrer">เปิดลิงก์</a>`}
                        ${w && canOrder() ? '<button type="button" class="hub-btn" data-doc-up aria-label="เลื่อนขึ้น">ขึ้น</button><button type="button" class="hub-btn" data-doc-down aria-label="เลื่อนลง">ลง</button>' : ''}
                        ${w ? '<button type="button" class="hub-btn" data-edit>แก้ไข</button><button type="button" class="hub-btn danger" data-del>ลบ</button>' : ''}</div></li>`).join('') || '<li class="hub-empty">ยังไม่มีเอกสารตามเงื่อนไข</li>'}</ul>
                ${w && !canOrder() ? '<p class="hub-hint">จัดลำดับเอกสารได้เมื่อกรองเหลือหมวดเดียว (ไม่กรองสถานะหรือคำค้น) และเอกสารของหมวดอยู่ในหน้าเดียว</p>' : ''}
                <nav class="hub-pager" aria-label="หน้ารายการ"><button type="button" class="hub-btn" data-page="-1"${state.page <= 1 ? ' disabled' : ''}>ก่อนหน้า</button><span>หน้า ${state.page} / ${pages}</span><button type="button" class="hub-btn" data-page="1"${state.page >= pages ? ' disabled' : ''}>ถัดไป</button></nav></div>`;
        }

        function editDialog(x) {
            const dlg = document.createElement('dialog');
            dlg.className = 'hub-dialog';
            dlg.innerHTML = `<form method="dialog" class="hub-form" novalidate><h2>แก้ไขเอกสาร</h2>
                ${field('ชื่อเอกสาร', `<input name="title" required maxlength="200" value="${esc(x.title)}">`)}
                ${field('วันที่ของเอกสาร', `<input name="doc_date" type="date" required value="${esc(x.doc_date)}">`)}
                ${field('หมวด', '<select name="category_id">' + catOptions(x.category_id) + '</select>')}
                ${periodFields(d, {month: x.period_month, year: x.period_year})}
                <label class="hub-check"><input type="checkbox" name="is_published"${x.is_published ? ' checked' : ''}> เผยแพร่</label>
                <label class="hub-check"><input type="checkbox" name="pinned"${x.pinned ? ' checked' : ''}> ปักหมุดไว้บนสุดของหมวด</label>
                ${field('เปลี่ยนไฟล์', '<input name="file" type="file" data-hide-monthly="1" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp">')}
                ${field(x.media_id ? 'เปลี่ยนเป็นลิงก์ภายนอก (https://)' : 'ลิงก์ภายนอก (https://)', `<input name="external_url" type="url" maxlength="500" value="${esc(x.external_url || '')}">`)}
                ${x.media_id ? '<label class="hub-check"><input type="checkbox" name="remove_file"> ใช้ลิงก์แทนไฟล์เดิม</label>' : ''}
                ${field('บันทึกภายใน', `<textarea name="note" rows="2" maxlength="1000">${esc(x.note || '')}</textarea>`)}
                <div class="hub-actions"><button class="hub-btn primary" value="save">บันทึก</button><button class="hub-btn" value="cancel" formnovalidate>ยกเลิก</button></div></form>`;
            document.body.append(dlg);
            dlg.addEventListener('close', () => dlg.remove());
            dlg.querySelector('form').addEventListener('submit', ev => {
                if (ev.submitter?.value !== 'save') return;
                const f = ev.currentTarget;
                if (!f.reportValidity()) { ev.preventDefault(); return; } // keep the dialog and what was typed
                const fd = new FormData(f);
                const body = new FormData();
                body.append('version', x.version);
                ['title', 'doc_date', 'category_id', 'note'].forEach(k => body.append(k, fd.get(k) ?? ''));
                body.append('is_published', fd.get('is_published') ? '1' : '0');
                body.append('pinned', fd.get('pinned') ? '1' : '0');
                if (!f.querySelector('.hub-period').hidden) {
                    if (!fd.get('period_month')) { ev.preventDefault(); toast('เลือกเดือนของรายงาน', true); return; }
                    body.append('period_month', fd.get('period_month'));
                    body.append('period_year', fd.get('period_year'));
                    if (fd.get('replace')) body.append('replace', '1');
                }
                const file = f.querySelector('[name=file]').files[0];
                if (file) body.append('file', file);
                else if (fd.get('external_url')) { body.append('external_url', fd.get('external_url')); if (fd.get('remove_file')) body.append('remove_file', '1'); }
                act(() => api(ctx, '/api/cms/hubs/' + id + '/documents/' + x.id, {method: 'POST', body}), 'บันทึกเอกสารแล้ว');
            });
            bindRule(dlg.querySelector('form'), d);
            dlg.showModal();
        }

        function draw() {
            host.innerHTML = `<div class="hub-head"><a class="hub-btn" href="#hubs">กลับรายการ</a><h1>${esc(d.hub.title)}</h1>
                <span class="hub-badge ${esc(d.hub.status)}">${esc(STATUS[d.hub.status] || d.hub.status)}</span>
                <span class="hub-actions"><a class="hub-btn" href="${esc(d.preview_url)}" target="_blank" rel="noopener">ดูตัวอย่าง</a>${d.public_url ? `<a class="hub-btn" href="${esc(d.public_url)}" target="_blank" rel="noopener">เปิดหน้าจริง</a>` : ''}</span></div>
                ${d.can.manage ? '' : '<p class="hub-note" role="status">คุณดูข้อมูลได้อย่างเดียว เฉพาะผู้ดูแลระบบและหัวหน้ากลุ่มนี้แก้ไขได้</p>'}
                <div class="hub-tabs" role="tablist">${[['docs', 'เอกสาร'], ['cats', 'หมวดเอกสาร'], ['settings', 'ข้อมูลหน้า']].map(([k, l]) => `<button type="button" role="tab" class="hub-tab" data-tab="${k}" aria-selected="${state.tab === k}">${l}</button>`).join('')}</div>
                <div class="hub-pane">${state.tab === 'docs' ? drawDocuments() : state.tab === 'cats' ? drawCategories() : drawSettings()}</div>`;
            bind();
        }

        function bind() {
            host.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => { state.tab = b.dataset.tab; draw(); }));
            host.querySelector('#hub-settings')?.addEventListener('submit', ev => {
                ev.preventDefault();
                const f = ev.currentTarget;
                if (!f.reportValidity()) return;
                const body = {version: d.hub.version};
                new FormData(f).forEach((v, k) => { body[k] = v; });
                for (const el of f.elements) if (el.disabled) delete body[el.name];
                if ('committee_text' in body) { body.committee = parseCommittee(body.committee_text); delete body.committee_text; }
                ['news_limit', 'box_limit'].forEach(k => { if (k in body) body[k] = Number(body[k]); });
                act(() => api(ctx, '/api/cms/hubs/' + id, {method: 'PUT', body}), 'บันทึกข้อมูลหน้าแล้ว');
            });
            host.querySelector('#cat-new')?.addEventListener('submit', ev => {
                ev.preventDefault();
                const f = ev.currentTarget;
                if (!f.reportValidity()) return;
                const body = {label: f.label.value.trim()};
                if (f.parent_id.value) body.parent_id = Number(f.parent_id.value);
                if (f.rule.value) body.rule = f.rule.value;
                act(() => api(ctx, '/api/cms/hubs/' + id + '/categories', {method: 'POST', body}), 'เพิ่มหมวดแล้ว');
            });
            host.querySelectorAll('.hub-cat').forEach(li => {
                const cid = li.dataset.id, ver = Number(li.dataset.version);
                const cat = d.categories.find(c => String(c.id) === cid);
                li.querySelector('[data-save]')?.addEventListener('click', () => act(() => api(ctx, `/api/cms/hubs/${id}/categories/${cid}`, {method: 'PUT', body: {version: ver, label: li.querySelector('.hub-cat-label').value.trim()}}), 'บันทึกชื่อหมวดแล้ว'));
                li.querySelector('[data-hide]')?.addEventListener('change', ev => act(() => api(ctx, `/api/cms/hubs/${id}/categories/${cid}`, {method: 'PUT', body: {version: ver, is_hidden: ev.target.checked}}), ev.target.checked ? 'ซ่อนหมวดแล้ว' : 'แสดงหมวดแล้ว'));
                li.querySelector('[data-del]')?.addEventListener('click', () => { if (confirm('ลบหมวด "' + cat.label + '" ?')) act(() => api(ctx, `/api/cms/hubs/${id}/categories/${cid}`, {method: 'DELETE', body: {version: ver}}), 'ลบหมวดแล้ว'); });
                const move = dir => {
                    const top = d.categories.filter(c => c.parent_id === null);
                    const i = top.findIndex(c => String(c.id) === cid), j = i + dir;
                    if (j < 0 || j >= top.length) return;
                    [top[i], top[j]] = [top[j], top[i]];
                    act(() => api(ctx, `/api/cms/hubs/${id}/categories/order`, {method: 'PUT', body: {items: top.map(c => ({id: c.id, version: c.version}))}}), 'จัดลำดับหมวดแล้ว');
                };
                li.querySelector('[data-up]')?.addEventListener('click', () => move(-1));
                li.querySelector('[data-down]')?.addEventListener('click', () => move(1));
            });
            host.querySelector('#doc-new')?.addEventListener('submit', ev => {
                ev.preventDefault();
                const f = ev.currentTarget;
                if (!f.reportValidity()) return;
                const body = new FormData();
                body.append('category_id', f.category_id.value);
                body.append('doc_date', f.doc_date.value);
                body.append('is_published', f.is_published.checked ? '1' : '0');
                if (f.title.value.trim()) body.append('title', f.title.value.trim());
                if (!f.querySelector('.hub-period').hidden) {
                    if (!f.period_month.value) { toast('เลือกเดือนของรายงาน', true); return; }
                    body.append('period_month', f.period_month.value);
                    body.append('period_year', f.period_year.value);
                    if (f.replace.checked) body.append('replace', '1');
                }
                const files = [...f.files.files];
                if (files.length) files.forEach(file => body.append('files[]', file));
                else if (f.external_url.value.trim()) body.append('external_url', f.external_url.value.trim());
                else { toast('เลือกไฟล์ หรือใส่ลิงก์ภายนอก', true); return; }
                const btn = f.querySelector('button[type=submit]'); btn.disabled = true;
                act(() => api(ctx, '/api/cms/hubs/' + id + '/documents', {method: 'POST', body}), 'เพิ่มเอกสารแล้ว').finally(() => { btn.disabled = false; });
            });
            const upload = host.querySelector('#doc-new');
            if (upload) bindRule(upload, d);
            host.querySelector('#doc-filter')?.addEventListener('submit', ev => {
                ev.preventDefault();
                const f = ev.currentTarget;
                Object.assign(state, {category_id: f.category_id.value, status: f.status.value, q: f.q.value.trim(), page: 1});
                reload();
            });
            host.querySelectorAll('[data-page]').forEach(b => b.addEventListener('click', () => { state.page = Math.max(1, state.page + Number(b.dataset.page)); reload(); }));
            host.querySelectorAll('.hub-doc').forEach(li => {
                const did = Number(li.dataset.id);
                const x = d.documents.find(r => r.id === did);
                li.querySelector('[data-edit]')?.addEventListener('click', () => editDialog(x));
                const moveDoc = dir => {
                    const list = d.documents.slice();
                    const i = list.findIndex(r => r.id === did), j = i + dir;
                    if (j < 0 || j >= list.length) return;
                    [list[i], list[j]] = [list[j], list[i]];
                    act(() => api(ctx, `/api/cms/hubs/${id}/documents/order`, {method: 'PUT', body: {category_id: Number(state.category_id), items: list.map(r => ({id: r.id, version: r.version}))}}), 'จัดลำดับเอกสารแล้ว');
                };
                li.querySelector('[data-doc-up]')?.addEventListener('click', () => moveDoc(-1));
                li.querySelector('[data-doc-down]')?.addEventListener('click', () => moveDoc(1));
                li.querySelector('[data-del]')?.addEventListener('click', () => { if (confirm('ลบเอกสาร "' + x.title + '" ?')) act(() => api(ctx, `/api/cms/hubs/${id}/documents/${did}`, {method: 'DELETE', body: {version: x.version}}), 'ลบเอกสารแล้ว'); });
            });
            host.querySelectorAll('[data-bulk]').forEach(b => b.addEventListener('click', () => {
                const items = [...host.querySelectorAll('.hub-doc')].filter(li => li.querySelector('.hub-pick')?.checked).map(li => ({id: Number(li.dataset.id), version: Number(li.dataset.version)}));
                if (!items.length) { toast('ติ๊กเลือกเอกสารก่อน', true); return; }
                act(() => api(ctx, '/api/cms/hubs/' + id + '/documents/publish', {method: 'POST', body: {published: b.dataset.bulk === '1', items}}), 'ปรับสถานะเอกสารแล้ว');
            }));
        }

        await load();
    }

    window.SaanWork.register('hubs', {
        title: 'หน้ากลุ่มงาน/กลุ่มสาระ',
        async render(root, ctx) {
            const id = ctx.parts[0] && /^\d+$/.test(ctx.parts[0]) ? Number(ctx.parts[0]) : null;
            if (id) await detail(root, ctx, id); else await list(root, ctx);
        },
    });
})();
