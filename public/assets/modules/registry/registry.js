'use strict';
// registry module: staff pages #students (list, detail, create) and #import (CSV wizard) for the /work shell.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const api = (ctx, url, opts) => ctx.api(url, opts);
    const NAT = {thai: 'ไทย', foreign: 'ต่างชาติ', stateless: 'ไร้สัญชาติ', undetermined: 'รอตรวจสอบ'};
    const STATUS = {studying: 'กำลังศึกษา', transferred_out: 'ย้ายออก', graduated: 'จบการศึกษา', left: 'ลาออก'};
    const REL = {mother: 'มารดา', father: 'บิดา', grandmother: 'ยาย/ย่า', grandfather: 'ตา/ปู่', aunt: 'ป้า/น้า/อา', uncle: 'ลุง/น้า/อา', sibling: 'พี่/น้อง', guardian: 'ผู้ปกครอง', other: 'อื่น ๆ'};
    const KIND = {thai_id: 'เลขประจำตัวประชาชน', g_code: 'เลข G', passport: 'หนังสือเดินทาง', other: 'เลขอื่น ๆ', pending: 'รอเอกสาร'};
    const VERIFY = {unverified: 'ยังไม่ตรวจ', verified: 'ตรวจแล้ว', rejected: 'ไม่ผ่าน'};
    const DOC = {birth_certificate: 'สูติบัตร', house_registration: 'ทะเบียนบ้าน', passport: 'หนังสือเดินทาง', transfer_record: 'ใบรับรองการย้าย', photo: 'รูปถ่าย', other: 'อื่น ๆ'};
    const REVIEW = {pending: 'รอตรวจ', accepted: 'รับแล้ว', rejected: 'ไม่รับ'};
    const ACTION = {create: 'เพิ่มใหม่', update: 'อัปเดต', skip: 'ข้าม'};
    const KIND_IMPORT = {students: 'นักเรียน', guardians: 'ผู้ปกครอง', student_guardians: 'การผูกนักเรียนกับผู้ปกครอง'};

    const opts = (map, current, blank) => (blank !== undefined ? `<option value="">${esc(blank)}</option>` : '')
        + Object.entries(map).map(([k, v]) => `<option value="${esc(k)}"${k === current ? ' selected' : ''}>${esc(v)}</option>`).join('');
    const bytes = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    };
    const field = (label, control) => `<label class="reg-field"><span>${esc(label)}</span>${control}</label>`;
    // #work-view outlives page changes, so listeners go on a fresh host element and die with it
    const mount = root => { const host = document.createElement('div'); host.className = 'reg-host'; root.append(host); return host; };

    // a stale version: show what changed and offer a reload instead of overwriting
    function conflict(err, reload) {
        const box = document.createElement('div');
        box.className = 'reg-conflict'; box.setAttribute('role', 'alert');
        box.innerHTML = `<p>${esc(err.message)}</p><button type="button" class="reg-btn">โหลดข้อมูลล่าสุด</button>`;
        box.querySelector('button').addEventListener('click', () => { box.remove(); reload(); });
        document.querySelector('#work-view')?.prepend(box);
    }
    function fail(err, reload) {
        if (err?.name === 'AbortError') return;
        if (err?.status === 409 && !err.data?.blocking) conflict(err, reload); else toast(err?.message || 'เกิดข้อผิดพลาด', true);
    }

    // ---------------- list ----------------
    async function list(root, ctx) {
        const state = {classroom_id: '', status: 'studying', nationality_status: '', pending_documents: '', q: '', page: 1};
        let data = await api(ctx, '/api/registry/students?' + new URLSearchParams({...state, per_page: 50}));
        if (!ctx.alive()) return;
        const host = mount(root);
        host.innerHTML = `<section class="reg glass"><div class="reg-head"><h1>ทะเบียนนักเรียน</h1><div class="reg-actions"></div></div>
            <form class="reg-filters" id="reg-filter">
                ${field('ค้นหา', '<input name="q" type="search" maxlength="80" placeholder="ชื่อ ชื่อเล่น หรือรหัส">')}
                ${field('ห้องเรียน', '<select name="classroom_id"></select>')}
                ${field('สถานะ', `<select name="status">${opts(STATUS, 'studying', 'ทุกสถานะ')}</select>`)}
                ${field('สัญชาติ', `<select name="nationality_status">${opts(NAT, '', 'ทุกกรณี')}</select>`)}
                <label class="reg-check" data-registrar><input type="checkbox" name="pending_documents" value="1"><span>รอเอกสาร</span></label>
            </form>
            <p class="reg-count" id="reg-count" aria-live="polite"></p><div id="reg-rows"></div><div class="reg-pager" id="reg-pager"></div></section>`;
        const form = host.querySelector('#reg-filter');
        const render = () => {
            const root = host;
            const rows = data.data, total = data.meta.total, last = Math.max(1, Math.ceil(total / data.meta.per_page));
            const sel = form.elements.classroom_id, keep = sel.value;
            sel.innerHTML = `<option value="">ทุกห้อง</option>` + (data.can_edit ? '<option value="none">ยังไม่จัดห้อง</option>' : '')
                + data.classrooms.map(c => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('');
            sel.value = keep;
            form.querySelector('[data-registrar]').hidden = !data.can_see_identity;
            root.querySelector('.reg-actions').innerHTML = (data.can_edit ? '<a class="reg-btn primary" href="#students/new">เพิ่มนักเรียน</a>' : '')
                + (state.classroom_id && state.classroom_id !== 'none' ? '<a class="reg-btn" href="#" data-export>ส่งออกรายชื่อห้อง (CSV)</a>' : '');
            root.querySelector('#reg-count').textContent = `พบ ${total} คน`;
            root.querySelector('#reg-rows').innerHTML = rows.length ? `<div class="reg-table-wrap"><table class="reg-table"><thead><tr><th>เลขที่</th><th>รหัส</th><th>ชื่อ</th><th>ห้อง</th><th>สัญชาติ</th><th>สถานะ</th></tr></thead><tbody>`
                + rows.map(s => `<tr><td>${esc(s.roll_number ?? '')}</td><td>${esc(s.code)}</td>
                    <td><a href="#students/${esc(s.id)}">${esc((s.prefix || '') + s.name)}</a>${s.nickname ? ` <small>(${esc(s.nickname)})</small>` : ''}
                    ${s.pickup_restricted ? ' <span class="reg-badge danger">มีผู้ห้ามรับ</span>' : ''}${s.needs_interpreter ? ' <span class="reg-badge">ต้องการล่าม</span>' : ''}
                    ${s.pending_documents ? ` <span class="reg-badge warn">เอกสารรอตรวจ ${s.pending_documents}</span>` : ''}
                    ${(s.identifiers || []).some(i => i.kind === 'pending') ? ' <span class="reg-badge warn">รอเอกสาร</span>' : ''}</td>
                    <td>${esc(s.classroom_name || '-')}</td><td>${esc(NAT[s.nationality_status] || '')}</td><td>${esc(STATUS[s.status] || s.status)}</td></tr>`).join('')
                + '</tbody></table></div>' : '<p class="reg-empty">ไม่พบนักเรียนตามเงื่อนไข</p>';
            root.querySelector('#reg-pager').innerHTML = last > 1 ? `<button type="button" class="reg-btn" data-page="${state.page - 1}" ${state.page <= 1 ? 'disabled' : ''}>ก่อนหน้า</button><span>หน้า ${state.page} / ${last}</span><button type="button" class="reg-btn" data-page="${state.page + 1}" ${state.page >= last ? 'disabled' : ''}>ถัดไป</button>` : '';
        };
        let seq = 0, timer = null;
        const reload = async () => {
            const mine = ++seq; // a slower, older response must never overwrite a newer one
            try {
                const params = new URLSearchParams(Object.fromEntries(Object.entries({...state, per_page: 50}).filter(([, v]) => v !== '' && v !== null)));
                const res = await api(ctx, '/api/registry/students?' + params);
                if (mine !== seq) return;
                data = res;
                if (ctx.alive()) render();
            } catch (e) { if (mine === seq) fail(e, reload); }
        };
        form.addEventListener('submit', e => e.preventDefault());
        form.addEventListener('input', () => {
            const f = form.elements;
            Object.assign(state, {q: f.q.value.trim(), classroom_id: f.classroom_id.value, status: f.status.value, nationality_status: f.nationality_status.value, pending_documents: f.pending_documents.checked ? '1' : '', page: 1});
            clearTimeout(timer);
            timer = setTimeout(reload, 300);
        });
        host.addEventListener('click', e => {
            const p = e.target.closest('[data-page]');
            if (p && !p.disabled) { state.page = +p.dataset.page; reload(); }
            const x = e.target.closest('[data-export]');
            if (x) { e.preventDefault(); window.location.assign(document.querySelector('meta[name="school-base"]')?.content.replace(/\/+$/, '') + '/api/registry/export/class-list?classroom_id=' + encodeURIComponent(state.classroom_id)); }
        });
        render();
    }

    // ---------------- student form ----------------
    function studentForm(s, classrooms, creating) {
        s = s || {};
        return `<form class="reg-form" id="student-form">
            ${field('รหัสนักเรียน', `<input name="code" required maxlength="40" value="${esc(s.code || '')}">`)}
            ${field('คำนำหน้า', `<input name="prefix" maxlength="30" value="${esc(s.prefix || '')}" list="reg-prefixes"><datalist id="reg-prefixes"><option value="ด.ช."><option value="ด.ญ."><option value="นาย"><option value="นางสาว"></datalist>`)}
            ${field('ชื่อ', `<input name="first_name" required maxlength="120" value="${esc(s.first_name || '')}">`)}
            ${field('นามสกุล', `<input name="last_name" maxlength="120" value="${esc(s.last_name || '')}">`)}
            ${field('ชื่อเล่น', `<input name="nickname" maxlength="60" value="${esc(s.nickname || '')}">`)}
            ${field('เพศ', `<select name="sex">${opts({M: 'ชาย', F: 'หญิง'}, s.sex, 'ไม่ระบุ')}</select>`)}
            ${field('วันเกิด', `<input name="birth_date" type="date" value="${esc(s.birth_date || '')}">`)}
            ${field('ปีเกิด พ.ศ. (เมื่อไม่ทราบวันเกิด)', `<input name="birth_year_be" type="number" min="2400" max="2700" value="${esc(s.birth_year_be ?? '')}">`)}
            ${field('สถานะสัญชาติ', `<select name="nationality_status">${opts(NAT, s.nationality_status || 'thai')}</select>`)}
            ${field('ภาษาที่ใช้', `<input name="preferred_language" maxlength="40" value="${esc(s.preferred_language || '')}">`)}
            <label class="reg-check"><input type="checkbox" name="needs_interpreter" ${s.needs_interpreter ? 'checked' : ''}><span>ต้องการล่าม</span></label>
            ${field('สถานะนักเรียน', `<select name="status">${opts(STATUS, s.status || 'studying')}</select>`)}
            ${field('ห้องเรียน', `<select name="classroom_id"><option value="">ยังไม่จัดห้อง</option>${classrooms.map(c => `<option value="${esc(c.id)}"${c.id === s.classroom_id ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}</select>`)}
            ${field('เลขที่', `<input name="roll_number" type="number" min="1" max="999" value="${esc(s.roll_number ?? '')}">`)}
            ${field('วันที่เข้าเรียน', `<input name="admitted_on" type="date" value="${esc(s.admitted_on ? String(s.admitted_on).slice(0, 10) : '')}">`)}
            ${field('ประเภทการรับเข้า', `<select name="admission_type">${opts({regular: 'ปกติ', transfer_in: 'ย้ายเข้า', re_enroll: 'กลับเข้าเรียน', other: 'อื่น ๆ'}, s.admission_type, 'ไม่ระบุ')}</select>`)}
            ${field('โรงเรียนเดิม (กรณีย้ายเข้า)', `<input name="previous_school" maxlength="200" value="${esc(s.previous_school || '')}">`)}
            <div class="reg-actions"><button class="reg-btn primary" type="submit">${creating ? 'บันทึกนักเรียนใหม่' : 'บันทึกการแก้ไข'}</button></div></form>`;
    }

    function readStudent(f) {
        const e = f.elements, body = {};
        for (const k of ['code', 'prefix', 'first_name', 'last_name', 'nickname', 'sex', 'birth_date', 'preferred_language', 'status', 'admitted_on', 'admission_type', 'previous_school', 'nationality_status']) body[k] = e[k].value.trim() || null;
        body.needs_interpreter = e.needs_interpreter.checked;
        body.birth_year_be = e.birth_year_be.value ? +e.birth_year_be.value : null;
        body.roll_number = e.roll_number.value ? +e.roll_number.value : null;
        body.classroom_id = e.classroom_id.value ? +e.classroom_id.value : null;
        if (!body.status) delete body.status;
        return body;
    }

    async function create(root, ctx) {
        const cls = await api(ctx, '/api/registry/classrooms');
        if (!ctx.alive()) return;
        const host = mount(root);
        host.innerHTML = `<section class="reg glass"><p><a href="#students">&larr; กลับรายชื่อ</a></p><h1>เพิ่มนักเรียน</h1>${studentForm(null, cls.data, true)}</section>`;
        const f = host.querySelector('#student-form'); let busy = false;
        const key = window.SaanCore.uid();
        f.addEventListener('submit', async e => {
            e.preventDefault(); if (busy) return; busy = true;
            try { const r = await api(ctx, '/api/registry/students', {method: 'POST', body: readStudent(f), idempotencyKey: key}); window.SaanWork.go('students/' + r.student.id); }
            catch (err) { fail(err, () => {}); } finally { busy = false; }
        });
    }

    // ---------------- detail ----------------
    async function detail(root, ctx, id) {
        let d = await api(ctx, '/api/registry/students/' + encodeURIComponent(id));
        if (!ctx.alive()) return;
        const host = mount(root);
        const reload = async () => { d = await api(ctx, '/api/registry/students/' + encodeURIComponent(id)); if (ctx.alive()) draw(); };
        const keys = {guardian: null, ident: null, doc: null}, submitting = new Set();
        const act = async (fn) => { try { await fn(); await reload(); } catch (e) { fail(e, reload); } };
        const draw = () => {
            const s = d.student, ed = d.can_edit;
            host.innerHTML = `<section class="reg glass"><p><a href="#students">&larr; กลับรายชื่อ</a></p>
                <div class="reg-head"><h1>${esc((s.prefix || '') + s.name)}</h1><span class="reg-badge">${esc(NAT[s.nationality_status] || '')}</span><span class="reg-badge">${esc(STATUS[s.status] || s.status)}</span>${s.needs_interpreter ? '<span class="reg-badge">ต้องการล่าม</span>' : ''}</div>
                <p class="reg-sub">รหัส ${esc(s.code)} · ห้อง ${esc(s.classroom_name || 'ยังไม่จัดห้อง')}${s.roll_number ? ' · เลขที่ ' + esc(s.roll_number) : ''}${s.previous_school ? ' · ย้ายมาจาก ' + esc(s.previous_school) : ''}</p>
                ${d.pickup_restrictions.length ? `<div class="reg-alert" role="alert"><strong>ห้ามรับนักเรียนกลับบ้าน</strong><ul>${d.pickup_restrictions.map(r => `<li>${esc(r.display_name)}${r.note ? ' : ' + esc(r.note) : ''}</li>`).join('')}</ul></div>` : ''}
                <h2>ผู้ปกครอง</h2><div id="reg-guardians">${guardiansHtml()}</div>${ed ? addGuardianHtml() : ''}
                ${d.can_see_identity ? `<h2>เลขประจำตัว</h2><div id="reg-idents">${identsHtml(d.identifiers, 'student', s.id)}</div>${addIdentHtml('student', s.id)}<h2>เอกสาร</h2><div id="reg-docs">${docsHtml()}</div>${uploadHtml()}` : '<p class="reg-sub">เลขประจำตัวและเอกสารดูได้เฉพาะผู้ดูแลระบบและผู้อำนวยการ</p>'}
                ${ed ? `<h2>แก้ไขข้อมูลนักเรียน</h2>${studentForm(s, d.classrooms, false)}` : ''}</section>`;
            wire();
        };
        const guardiansHtml = () => d.guardians.length ? d.guardians.map(g => `<article class="reg-card${g.can_pick_up ? '' : ' restricted'}" data-link="${esc(g.link_id)}" data-lv="${esc(g.link_version)}" data-gid="${esc(g.guardian_id)}" data-gv="${esc(g.guardian_version)}">
                <h3>${esc(g.display_name)} <small>${esc(REL[g.relationship] || g.relationship)}</small></h3>
                <p>${g.is_primary ? '<span class="reg-badge">ผู้ปกครองหลัก</span> ' : ''}${g.is_emergency_contact ? '<span class="reg-badge">ติดต่อฉุกเฉิน</span> ' : ''}${g.can_pick_up ? '' : '<span class="reg-badge danger">ห้ามรับนักเรียน</span>'}</p>
                ${g.pickup_note ? `<p class="reg-note">${esc(g.pickup_note)}</p>` : ''}
                <p>${g.phone ? 'โทร ' + esc(g.phone) : ''}${g.line_id ? ' · LINE ' + esc(g.line_id) : ''}${g.email ? ' · ' + esc(g.email) : ''}</p>
                ${d.can_see_identity ? identsHtml(g.identifiers, 'guardian', g.guardian_id) : ''}
                ${d.can_edit ? `<div class="reg-actions"><button type="button" class="reg-btn" data-edit-link>แก้ไขการรับนักเรียน</button><button type="button" class="reg-btn" data-edit-guardian>แก้ไขผู้ปกครอง</button><button type="button" class="reg-btn danger" data-del-link>เอาออก</button></div><div class="reg-inline" data-slot></div>` : ''}</article>`).join('') : '<p class="reg-empty">ยังไม่มีผู้ปกครอง</p>';
        const addGuardianHtml = () => `<details class="reg-details"><summary>เพิ่มผู้ปกครอง</summary><form class="reg-form" id="add-guardian">
                ${field('คำนำหน้า', '<input name="prefix" maxlength="30">')}${field('ชื่อ', '<input name="first_name" required maxlength="120">')}${field('นามสกุล', '<input name="last_name" maxlength="120">')}
                ${field('โทรศัพท์', '<input name="phone" maxlength="40" inputmode="tel">')}${field('LINE ID', '<input name="line_id" maxlength="80">')}${field('ความสัมพันธ์', `<select name="relationship">${opts(REL, 'other')}</select>`)}
                <label class="reg-check"><input type="checkbox" name="is_primary"><span>ผู้ปกครองหลัก</span></label><label class="reg-check"><input type="checkbox" name="is_emergency_contact"><span>ติดต่อฉุกเฉิน</span></label>
                <label class="reg-check"><input type="checkbox" name="restricted"><span>ห้ามรับนักเรียนกลับบ้าน</span></label>${field('เหตุผลหรือคำแนะนำ (จำเป็นเมื่อห้ามรับ)', '<input name="pickup_note" maxlength="300">')}
                <div class="reg-actions"><button class="reg-btn primary" type="submit">เพิ่มผู้ปกครอง</button></div></form></details>`;
        function identsHtml(list, type, pid) {
            return list.length ? `<ul class="reg-idents">${list.map(i => `<li data-ident="${esc(i.id)}" data-iv="${esc(i.version)}"><strong>${esc(KIND[i.kind] || i.kind)}</strong> ${i.masked ? `<code>${esc(i.masked)}</code>` : ''} <span class="reg-badge">${esc(VERIFY[i.verification_state] || '')}</span>${i.note ? ` <small>${esc(i.note)}</small>` : ''}
                ${d.can_edit ? `<button type="button" class="reg-btn" data-reveal ${i.masked ? '' : 'hidden'}>แสดงเลขเต็ม (บันทึกประวัติ)</button><button type="button" class="reg-btn" data-verify>${i.verification_state === 'verified' ? 'ยกเลิกการตรวจ' : 'ตรวจแล้ว'}</button><button type="button" class="reg-btn danger" data-del-ident>ลบ</button>` : ''}<span class="reg-revealed" data-out></span></li>`).join('')}</ul>` : '<p class="reg-empty">ยังไม่มีเลขประจำตัว</p>';
        }
        const addIdentHtml = (type, pid) => d.can_edit ? `<details class="reg-details"><summary>เพิ่มเลขประจำตัว</summary><form class="reg-form" data-add-ident data-type="${type}" data-pid="${pid}">
                ${field('ชนิด', `<select name="kind">${opts(KIND, 'thai_id')}</select>`)}${field('เลข (เว้นว่างได้เมื่อรอเอกสาร)', '<input name="value" maxlength="60" autocomplete="off" inputmode="text">')}${field('หมายเหตุ', '<input name="note" maxlength="300">')}
                <div class="reg-actions"><button class="reg-btn primary" type="submit">บันทึกเลข</button></div></form></details>` : '';
        const docsHtml = () => d.documents.length ? `<ul class="reg-docs">${d.documents.map(x => `<li data-doc="${esc(x.id)}" data-dv="${esc(x.version)}"><strong>${esc(x.label)}</strong> <small>${esc(DOC[x.kind] || x.kind)} · ${esc(bytes(x.size))}</small>
                <select data-review aria-label="สถานะการตรวจเอกสาร">${opts(REVIEW, x.review_state)}</select>
                <a class="reg-btn" href="${esc((document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, ''))}/api/registry/documents/${esc(x.id)}/download">ดาวน์โหลด</a>
                ${d.can_edit ? '<button type="button" class="reg-btn danger" data-del-doc>ลบ</button>' : ''}</li>`).join('')}</ul>` : '<p class="reg-empty">ยังไม่มีเอกสาร</p>';
        const uploadHtml = () => d.can_edit ? `<form class="reg-form" id="doc-form">${field('ชนิดเอกสาร', `<select name="kind">${opts(DOC, 'birth_certificate')}</select>`)}${field('ชื่อเรียก', '<input name="label" maxlength="160">')}
                ${field('ไฟล์ (PDF, JPG, PNG, WebP)', '<input name="file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" required>')}<div class="reg-actions"><button class="reg-btn primary" type="submit">อัปโหลด</button></div></form>` : '';

        function wire() {
            const v = d.student.version, root = host;
            root.querySelector('#student-form')?.addEventListener('submit', e => { e.preventDefault(); act(() => api(ctx, '/api/registry/students/' + d.student.id, {method: 'PUT', body: {...readStudent(e.currentTarget), version: v}}).then(() => toast('บันทึกแล้ว'))); });
            root.querySelector('#add-guardian')?.addEventListener('submit', e => {
                e.preventDefault(); const f = e.currentTarget.elements;
                const body = {guardian: {prefix: f.prefix.value.trim() || null, first_name: f.first_name.value.trim(), last_name: f.last_name.value.trim() || null, phone: f.phone.value.trim() || null, line_id: f.line_id.value.trim() || null},
                    relationship: f.relationship.value, is_primary: f.is_primary.checked, is_emergency_contact: f.is_emergency_contact.checked, can_pick_up: !f.restricted.checked, pickup_note: f.pickup_note.value.trim() || null};
                if (submitting.has('guardian')) return; // double click / slow network: one request at a time
                // the key is created once and kept until the request succeeds, so a retry of the same form is deduplicated by the server
                const sig = JSON.stringify(body);
                if (!keys.guardian || keys.guardian.sig !== sig) keys.guardian = {sig, key: window.SaanCore.uid()};
                const btn = e.currentTarget.querySelector('button[type=submit]'); if (btn) btn.disabled = true;
                submitting.add('guardian');
                act(() => api(ctx, `/api/registry/students/${d.student.id}/guardians`, {method: 'POST', body, idempotencyKey: keys.guardian.key}).then(() => { keys.guardian = null; toast('เพิ่มผู้ปกครองแล้ว'); }))
                    .finally(() => { submitting.delete('guardian'); if (btn && btn.isConnected) btn.disabled = false; });
            });
            root.querySelectorAll('[data-add-ident]').forEach(f => f.addEventListener('submit', e => {
                e.preventDefault(); const el = f.elements;
                if (submitting.has('ident')) return;
                const body = {person_type: f.dataset.type, person_id: +f.dataset.pid, kind: el.kind.value, value: el.value.value.trim() || null, note: el.note.value.trim() || null};
                const sig = JSON.stringify(body);
                if (!keys.ident || keys.ident.sig !== sig) keys.ident = {sig, key: window.SaanCore.uid()};
                const btn = f.querySelector('button[type=submit]'); if (btn) btn.disabled = true;
                submitting.add('ident');
                act(() => api(ctx, '/api/registry/identifiers', {method: 'POST', body, idempotencyKey: keys.ident.key}).then(() => { keys.ident = null; toast('บันทึกเลขแล้ว'); }))
                    .finally(() => { submitting.delete('ident'); if (btn && btn.isConnected) btn.disabled = false; });
            }));
            root.querySelector('#doc-form')?.addEventListener('submit', e => {
                e.preventDefault(); const f = e.currentTarget, fd = new FormData();
                if (submitting.has('doc')) return; // double click on a slow link must not store the identity document twice
                const file = f.elements.file.files[0];
                const sig = [f.elements.kind.value, f.elements.label.value.trim(), file ? file.name + ':' + file.size + ':' + file.lastModified : ''].join('|');
                if (!keys.doc || keys.doc.sig !== sig) keys.doc = {sig, key: window.SaanCore.uid()};
                fd.append('kind', f.elements.kind.value); fd.append('label', f.elements.label.value.trim()); fd.append('file', file);
                const btn = f.querySelector('button[type=submit]'); if (btn) btn.disabled = true;
                submitting.add('doc');
                act(() => api(ctx, `/api/registry/students/${d.student.id}/documents`, {method: 'POST', body: fd, idempotencyKey: keys.doc.key}).then(() => { keys.doc = null; toast('อัปโหลดแล้ว'); }))
                    .finally(() => { submitting.delete('doc'); if (btn && btn.isConnected) btn.disabled = false; });
            });
        }

        host.addEventListener('click', async e => {
            const t = e.target.closest('button'); if (!t) return;
            const card = t.closest('[data-link]'), li = t.closest('[data-ident]'), doc = t.closest('[data-doc]');
            if (t.hasAttribute('data-reveal') && li) {
                try {
                    const r = await api(ctx, `/api/registry/identifiers/${li.dataset.ident}/reveal`, {method: 'POST'});
                    const out = li.querySelector('[data-out]'); out.textContent = ' ' + (r.value || '');
                    setTimeout(() => { if (out.isConnected) out.textContent = ''; }, 15000);
                } catch (err) { fail(err, reload); }
            } else if (t.hasAttribute('data-verify') && li) {
                const cur = d.identifiers.concat(...d.guardians.map(g => g.identifiers)).find(i => String(i.id) === li.dataset.ident);
                act(() => api(ctx, '/api/registry/identifiers/' + li.dataset.ident, {method: 'PUT', body: {version: +li.dataset.iv, verification_state: cur?.verification_state === 'verified' ? 'unverified' : 'verified'}}));
            } else if (t.hasAttribute('data-del-ident') && li) {
                if (confirm('ลบเลขประจำตัวนี้?')) act(() => api(ctx, '/api/registry/identifiers/' + li.dataset.ident, {method: 'DELETE', body: {version: +li.dataset.iv}}));
            } else if (t.hasAttribute('data-del-link') && card) {
                if (confirm('เอาผู้ปกครองคนนี้ออกจากนักเรียน?')) act(() => api(ctx, '/api/registry/student-guardians/' + card.dataset.link, {method: 'DELETE', body: {version: +card.dataset.lv}}));
            } else if (t.hasAttribute('data-del-doc') && doc) {
                if (confirm('ลบเอกสารนี้?')) act(() => api(ctx, '/api/registry/documents/' + doc.dataset.doc, {method: 'DELETE', body: {version: +doc.dataset.dv}}));
            } else if (t.hasAttribute('data-edit-link') && card) {
                const g = d.guardians.find(x => String(x.link_id) === card.dataset.link), slot = card.querySelector('[data-slot]');
                slot.innerHTML = `<form class="reg-form">${field('ความสัมพันธ์', `<select name="relationship">${opts(REL, g.relationship)}</select>`)}
                    <label class="reg-check"><input type="checkbox" name="primary" ${g.is_primary ? 'checked' : ''}><span>ผู้ปกครองหลัก</span></label><label class="reg-check"><input type="checkbox" name="emergency" ${g.is_emergency_contact ? 'checked' : ''}><span>ติดต่อฉุกเฉิน</span></label>
                    <label class="reg-check"><input type="checkbox" name="restricted" ${g.can_pick_up ? '' : 'checked'}><span>ห้ามรับนักเรียนกลับบ้าน</span></label>${field('เหตุผลหรือคำแนะนำ', `<input name="note" maxlength="300" value="${esc(g.pickup_note || '')}">`)}
                    <div class="reg-actions"><button class="reg-btn primary" type="submit">บันทึก</button></div></form>`;
                slot.querySelector('form').addEventListener('submit', ev => {
                    ev.preventDefault(); const f = ev.currentTarget.elements;
                    act(() => api(ctx, '/api/registry/student-guardians/' + card.dataset.link, {method: 'PUT', body: {version: +card.dataset.lv, relationship: f.relationship.value, is_primary: f.primary.checked, is_emergency_contact: f.emergency.checked, can_pick_up: !f.restricted.checked, pickup_note: f.note.value.trim() || null}}));
                });
            } else if (t.hasAttribute('data-edit-guardian') && card) {
                const g = d.guardians.find(x => String(x.link_id) === card.dataset.link), slot = card.querySelector('[data-slot]');
                slot.innerHTML = `<form class="reg-form">${field('คำนำหน้า', `<input name="prefix" maxlength="30" value="${esc(g.prefix || '')}">`)}${field('ชื่อ', `<input name="first_name" required maxlength="120" value="${esc(g.first_name || '')}">`)}
                    ${field('นามสกุล', `<input name="last_name" maxlength="120" value="${esc(g.last_name || '')}">`)}${field('โทรศัพท์', `<input name="phone" maxlength="40" value="${esc(g.phone || '')}">`)}${field('LINE ID', `<input name="line_id" maxlength="80" value="${esc(g.line_id || '')}">`)}
                    <div class="reg-actions"><button class="reg-btn primary" type="submit">บันทึก</button></div></form>`;
                slot.querySelector('form').addEventListener('submit', ev => {
                    ev.preventDefault(); const f = ev.currentTarget.elements;
                    act(() => api(ctx, '/api/registry/guardians/' + card.dataset.gid, {method: 'PUT', body: {version: +card.dataset.gv, prefix: f.prefix.value.trim() || null, first_name: f.first_name.value.trim(), last_name: f.last_name.value.trim() || null, phone: f.phone.value.trim() || null, line_id: f.line_id.value.trim() || null}}));
                });
            }
        });
        host.addEventListener('change', e => {
            const sel = e.target.closest('[data-review]'); const doc = e.target.closest('[data-doc]');
            if (sel && doc) act(() => api(ctx, '/api/registry/documents/' + doc.dataset.doc, {method: 'PUT', body: {version: +doc.dataset.dv, review_state: sel.value}}));
        });
        draw();
    }

    // ---------------- import ----------------
    async function importPage(root, ctx) {
        if (ctx.parts[0]) return batchPage(root, ctx, ctx.parts[0]);
        const history = (await api(ctx, '/api/registry/import/batches')).data;
        if (!ctx.alive()) return;
        // admin/principal only: any other role gets 403 and simply does not see the section
        let orphans = null;
        try { orphans = (await api(ctx, '/api/registry/guardians/unlinked')).data; } catch (e) { orphans = null; }
        if (!ctx.alive()) return;
        const show = batchId => window.SaanWork.go('import/' + batchId);
        const host = mount(root);
        host.innerHTML = `<section class="reg glass"><h1>นำเข้าข้อมูลนักเรียน</h1>
            <ol class="reg-steps"><li>เลือกชนิดไฟล์และไฟล์ CSV (UTF-8)</li><li>ตรวจตัวอย่างและข้อผิดพลาดรายแถว</li><li>ยืนยันนำเข้า (ย้อนกลับได้ถ้าแถวนั้นยังไม่ถูกแก้ไข)</li></ol>
            <p class="reg-sub">นำเข้าตามลำดับ นักเรียน แล้วผู้ปกครอง แล้วการผูกผู้ปกครองกับนักเรียน ช่องว่างในไฟล์จะไม่ลบข้อมูลเดิม ระบบไม่นำเข้าเลขประจำตัวหรือเบอร์โทรจากไฟล์นักเรียน</p>
            <form class="reg-form" id="import-form">${field('ชนิดข้อมูล', `<select name="kind">${opts(KIND_IMPORT, 'students')}</select>`)}${field('ไฟล์ CSV', '<input name="file" type="file" accept=".csv,text/csv" required>')}
            <div class="reg-actions"><button class="reg-btn primary" type="submit">สร้างตัวอย่างก่อนนำเข้า</button></div></form>
            <h2>ชุดนำเข้าล่าสุด</h2>${history.length ? `<ul class="reg-batches">${history.map(b => `<li><a href="#import/${esc(b.id)}">#${esc(b.id)} ${esc(KIND_IMPORT[b.kind] || b.kind)} : ${esc(b.filename)}</a> <span class="reg-badge">${esc({preview: 'ตัวอย่าง', committed: 'นำเข้าแล้ว', rolled_back: 'ย้อนกลับแล้ว'}[b.status] || b.status)}</span></li>`).join('')}</ul>` : '<p class="reg-empty">ยังไม่มีชุดนำเข้า</p>'}</section>`;
        if (orphans) {
            const sec = document.createElement('section'); sec.className = 'reg glass'; host.append(sec);
            const drawOrphans = () => {
                sec.innerHTML = `<h2>ผู้ปกครองที่ไม่ผูกนักเรียน</h2><p class="reg-sub">ผู้ปกครองที่ไม่มีนักเรียนผูกอยู่แล้ว จะไม่ปรากฏในหน้านักเรียนคนใด ลบได้ที่นี่ (รวมเลขประจำตัว บัญชีและลิงก์ผู้ปกครองของรายนั้น)</p>`
                    + (orphans.length ? `<ul class="reg-batches">${orphans.map(g => `<li data-gid="${esc(g.id)}" data-gv="${esc(g.version)}">${esc(g.display_name || ('#' + g.id))} <button type="button" class="reg-btn danger" data-del-orphan>ลบผู้ปกครอง</button></li>`).join('')}</ul>` : '<p class="reg-empty">ไม่มีผู้ปกครองที่ไม่ผูกนักเรียน</p>');
            };
            drawOrphans();
            sec.addEventListener('click', async e => {
                const b = e.target.closest('[data-del-orphan]'); if (!b || b.disabled) return;
                const li = b.closest('li');
                if (!confirm('ลบผู้ปกครองรายนี้ถาวร? การลบย้อนกลับไม่ได้')) return;
                b.disabled = true;
                try {
                    await api(ctx, '/api/registry/guardians/' + li.dataset.gid, {method: 'DELETE', body: {version: +li.dataset.gv}});
                    orphans = orphans.filter(x => String(x.id) !== li.dataset.gid); toast('ลบผู้ปกครองแล้ว'); drawOrphans();
                } catch (err) { b.disabled = false; fail(err, async () => { orphans = (await api(ctx, '/api/registry/guardians/unlinked')).data; drawOrphans(); }); }
            });
        }
        const f = host.querySelector('#import-form'); let busy = false;
        f.addEventListener('submit', async e => {
            e.preventDefault(); if (busy) return; busy = true;
            const fd = new FormData(); fd.append('kind', f.elements.kind.value); fd.append('file', f.elements.file.files[0]);
            try { const r = await api(ctx, '/api/registry/import/batches', {method: 'POST', body: fd}); show(r.batch.id); }
            catch (err) { fail(err, () => {}); } finally { busy = false; }
        });
    }

    async function batchPage(root, ctx, batchId) {
        let page = 1, data = await api(ctx, `/api/registry/import/batches/${encodeURIComponent(batchId)}?page=1`);
        if (!ctx.alive()) return;
        const host = mount(root);
        let blocking = null;
        const reload = async () => { data = await api(ctx, `/api/registry/import/batches/${encodeURIComponent(batchId)}?page=${page}`); if (ctx.alive()) draw(); };
        const draw = () => {
            const b = data.batch, s = b.summary, rows = data.rows, total = s.rows || rows.length, last = Math.max(1, Math.ceil(total / data.per_page));
            host.innerHTML = `<section class="reg glass"><p><a href="#import">&larr; กลับหน้านำเข้า</a></p><h1>ชุดนำเข้า #${esc(b.id)} : ${esc(KIND_IMPORT[b.kind] || b.kind)}</h1>
                <p class="reg-sub">ไฟล์ ${esc(b.filename)} · สถานะ ${esc({preview: 'ตัวอย่าง (ยังไม่เขียนข้อมูล)', committed: 'นำเข้าแล้ว', rolled_back: 'ย้อนกลับแล้ว'}[b.status] || b.status)}</p>
                <ul class="reg-summary"><li>ทั้งหมด ${esc(s.rows)} แถว</li><li>เพิ่มใหม่ ${esc(s.create)}</li><li>อัปเดต ${esc(s.update)}</li><li>ข้าม ${esc(s.skip)}</li><li class="${s.error ? 'bad' : ''}">มีข้อผิดพลาด ${esc(s.error)}</li></ul>
                ${(s.ignored_columns || []).length ? `<p class="reg-sub">คอลัมน์ที่ระบบไม่ใช้: ${esc(s.ignored_columns.join(', '))}</p>` : ''}
                ${blocking ? `<div class="reg-alert" role="alert"><strong>ย้อนกลับไม่ได้ เพราะแถวต่อไปนี้ถูกแก้ไขหรือมีข้อมูลที่เกี่ยวข้องแล้ว</strong><ul>${blocking.map(x => `<li>แถว ${esc(x.row_no)} : ${esc(x.reason)}</li>`).join('')}</ul></div>` : ''}
                <div class="reg-actions">${b.status === 'preview' ? '<button type="button" class="reg-btn primary" data-commit>ยืนยันนำเข้า (เฉพาะแถวที่ไม่มีข้อผิดพลาด)</button>' : ''}${b.status === 'committed' ? '<button type="button" class="reg-btn danger" data-rollback>ย้อนกลับชุดนี้</button>' : ''}</div>
                <div class="reg-table-wrap"><table class="reg-table"><thead><tr><th>แถว</th><th>การดำเนินการ</th><th>ข้อมูล</th><th>ข้อผิดพลาด</th></tr></thead><tbody>${rows.map(r => `<tr class="${r.errors.length ? 'bad' : ''}"><td>${esc(r.row_no)}</td><td>${esc(r.errors.length ? 'ข้าม' : ACTION[r.action] || r.action)}</td>
                    <td>${esc([r.data.code || r.data.student_ref, r.data.display_name || [r.data.first_name, r.data.last_name].filter(Boolean).join(' '), r.data.guardian_ref, r.data.classroom_label].filter(Boolean).join(' · '))}${(r.data._diff || []).length ? `<br><small>เปลี่ยน: ${esc(r.data._diff.join(', '))}</small>` : ''}</td>
                    <td>${r.errors.map(x => esc(x)).join('<br>')}</td></tr>`).join('')}</tbody></table></div>
                ${last > 1 ? `<div class="reg-pager"><button type="button" class="reg-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>ก่อนหน้า</button><span>หน้า ${page} / ${last}</span><button type="button" class="reg-btn" data-page="${page + 1}" ${page >= last ? 'disabled' : ''}>ถัดไป</button></div>` : ''}</section>`;
        };
        host.addEventListener('click', async e => {
            const t = e.target.closest('button'); if (!t || t.disabled) return;
            try {
                if (t.hasAttribute('data-page')) { page = +t.dataset.page; await reload(); }
                else if (t.hasAttribute('data-commit')) { t.disabled = true; data = await api(ctx, `/api/registry/import/batches/${data.batch.id}/commit`, {method: 'POST'}); blocking = null; toast('นำเข้าแล้ว'); page = 1; draw(); }
                else if (t.hasAttribute('data-rollback') && confirm('ย้อนกลับชุดนำเข้านี้?')) { t.disabled = true; data = await api(ctx, `/api/registry/import/batches/${data.batch.id}/rollback`, {method: 'POST'}); blocking = null; toast('ย้อนกลับแล้ว'); page = 1; draw(); }
            } catch (err) {
                if (err?.status === 409 && err.data?.blocking) { blocking = err.data.blocking; draw(); }
                else if (err?.status === 409) { toast((err.data?.message || 'ข้อมูลถูกแก้ไขระหว่างนำเข้า') + ' กรุณากลับไปสร้างตัวอย่างใหม่'); draw(); }
                else fail(err, reload);
            }
        });
        draw();
    }

    window.SaanWork.register('students', {title: 'ทะเบียนนักเรียน', async render(root, ctx) {
        const p = ctx.parts[0];
        if (!p) return list(root, ctx);
        if (p === 'new') return create(root, ctx);
        return detail(root, ctx, p);
    }});
    window.SaanWork.register('import', {title: 'นำเข้าข้อมูลนักเรียน', render: importPage});
})();
