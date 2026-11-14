'use strict';
// web-notices module: CMS screen #notices (lists) and #notices/<id> (editor). Uses the CMS globals request/CMS at call time.
(() => {
    window.SaanModulePages = (window.SaanModulePages || []).concat(['notices']);
    const previous = window.CmsComplete?.render; if (!previous) return;
    // Own fetch wrapper: CMS request() throws plain Errors, but this screen needs err.status (409) and Idempotency-Key.
    async function request(path, method = 'GET', payload, idempotencyKey) {
        const headers = {Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''};
        if (payload !== undefined) headers['Content-Type'] = 'application/json';
        if (idempotencyKey) headers['Idempotency-Key'] = String(idempotencyKey).slice(0, 80);
        let res;
        try { res = await fetch(CMS.base + path, {method, headers, credentials: 'same-origin', body: payload !== undefined ? JSON.stringify(payload) : undefined}); }
        catch (e) { throw new Error('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'); }
        const json = await res.json().catch(() => null);
        if (res.ok) return json;
        if (res.status === 401) { location.href = CMS.base + '/login'; throw new Error('กรุณาเข้าระบบใหม่'); }
        const err = new Error((json?.errors ? Object.values(json.errors).flat()[0] : null) || json?.message || 'เกิดข้อผิดพลาด (' + res.status + ')');
        err.status = res.status; throw err;
    }
    const esc = s => window.SaanCore.esc(s);
    const LABEL = {order_no: 'ลำดับ', display_name: 'ชื่อ', classroom: 'ห้อง/ชั้น', group: 'กลุ่ม', note: 'หมายเหตุ', applicant_no: 'เลขที่สมัคร/เลขที่นั่งสอบ'};
    const STATUS = {draft: 'ฉบับร่าง', published: 'เผยแพร่', archived: 'เก็บถาวร'};
    const MODES = {first_initial: 'ชื่อ + ตัวอักษรแรกของนามสกุล (สมชาย ใ.)', first_only: 'ชื่อต้นเท่านั้น (สมชาย)', full: 'ชื่อเต็ม'};
    const toast = (m, bad) => { const t = document.querySelector('#cms-toast'); if (t) { t.textContent = m; t.classList.toggle('error', !!bad); t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 3200); } };
    const ymd = d => d.toISOString().slice(0, 10);
    const addDays = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return ymd(d); };
    const dateTh = s => new Date(s + 'T00:00:00').toLocaleDateString('th-TH', {day: 'numeric', month: 'short', year: 'numeric'});
    // F9: full names expire. Local wall-clock helpers for the datetime-local field (the server stores Asia/Bangkok local time).
    const pad = n => String(n).padStart(2, '0');
    const fmtLocal = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    const defaultUntil = (from, to, days) => { const a = new Date(from + 'T00:00:00'); a.setDate(a.getDate() + days); const b = new Date(to + 'T23:59:00'); return fmtLocal(a > b ? b : a); };
    const untilLocal = s => (s || '').replace(' ', 'T').slice(0, 16);
    const untilTh = s => { const d = new Date(untilLocal(s)); return isNaN(d) ? '' : d.toLocaleString('th-TH', {day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'}) + ' น.'; };
    const colChecks = cols => Object.keys(LABEL).map(k => `<label><input type="checkbox" name="columns" value="${k}" ${cols.includes(k) ? 'checked' : ''} ${k === 'display_name' ? 'disabled checked' : ''}>${LABEL[k]}</label>`).join('');
    const readCols = f => ['display_name'].concat([...f.querySelectorAll('[name=columns]:checked')].map(i => i.value).filter(v => v !== 'display_name'));

    async function list(root, route) {
        const res = await request('/api/notices/lists'); if (location.hash !== route) return;
        root.innerHTML = `<div class="cms-heading"><div><h1>ประกาศรายชื่อ</h1><p>ประกาศรายชื่อแบบเปิดเผยข้อมูลน้อยที่สุด ปิดบังชื่อ มีวันหมดอายุไม่เกิน ${res.max_days} วัน ไม่ถูกจัดทำดัชนีโดยเครื่องมือค้นหา</p></div></div>
        <section class="desk-editor-card glass"><h2>สร้างรายการใหม่</h2><p id="nt-error" class="desk-error" role="alert"></p><form id="nt-create" class="desk-form">
            <label class="desk-field"><span>หัวข้อประกาศ</span><input name="title" maxlength="200" required placeholder="เช่น ประกาศรายชื่อนักเรียนชั้น ม.1 ปีการศึกษา 2569"></label>
            <div class="notice-admin-grid"><label class="desk-field"><span>เริ่มเผยแพร่</span><input type="date" name="publish_from" value="${esc(res.today)}" required></label>
            <label class="desk-field"><span>หมดอายุ (ไม่เกิน ${res.max_days} วัน)</span><input type="date" name="expires_on" value="${addDays(30)}" required></label></div>
            <label class="desk-field"><span>การแสดงชื่อ</span><select name="name_mode">${Object.entries(MODES).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}</select></label>
            <p class="desk-help">ชื่อเต็มแสดงให้ผู้ที่ผ่าน Turnstile เท่านั้น โดยตั้งต้นถึง เริ่มเผยแพร่ + ${res.full_default_days} วัน (ปรับเวลาได้หลังสร้าง) แล้วจะถูกปิดบังอัตโนมัติ ${res.turnstile ? '' : 'ขณะนี้ยังไม่ได้ตั้งค่า Turnstile จึงเลือกชื่อเต็มไม่ได้'}</p>
            <fieldset class="desk-field"><legend>คอลัมน์ที่แสดง (ไม่มีเบอร์โทร เลขบัตร วันเกิด)</legend><div class="notice-admin-cols">${colChecks(['order_no', 'display_name'])}</div></fieldset>
            <button class="primary" type="submit">สร้างรายการ</button></form></section>
        <section class="desk-editor-card glass"><h2>รายการทั้งหมด</h2>${res.data.length ? res.data.map(l => `<div class="notice-admin-row"><span class="tag ${l.live ? '' : 'warm'}">${l.live ? 'กำลังแสดง' : STATUS[l.status]}</span><p><strong>${esc(l.title)}</strong><br><small>${l.rows} รายการ · ${dateTh(l.publish_from.slice(0, 10))} ถึง ${dateTh(l.expires_on.slice(0, 10))}</small></p><a class="quiet-button" href="#notices/${l.id}">จัดการ</a></div>`).join('') : '<p class="desk-help">ยังไม่มีประกาศรายชื่อ</p>'}</section>`;
        const f = root.querySelector('#nt-create');
        f.onsubmit = async e => {
            e.preventDefault(); if (!f.reportValidity()) return;
            const btn = f.querySelector('[type=submit]'); if (btn.disabled) return; btn.disabled = true;
            try { const r = await request('/api/notices/lists', 'POST', {title: f.elements.title.value.trim(), publish_from: f.elements.publish_from.value, expires_on: f.elements.expires_on.value, name_mode: f.elements.name_mode.value, columns: readCols(f)}, window.SaanCore.uid()); if (location.hash === route) location.hash = 'notices/' + r.list.id; }
            catch (err) { if (location.hash !== route) return; root.querySelector('#nt-error').textContent = err.message; btn.disabled = false; }
        };
    }

    async function editor(root, id, route) {
        let st = await request('/api/notices/lists/' + id); if (location.hash !== route) return;
        const draw = () => {
            const l = st.list, cols = l.columns;
            root.innerHTML = `<div class="cms-heading"><div><a href="#notices">&larr; ประกาศรายชื่อทั้งหมด</a><h1>${esc(l.title)}</h1><p><span class="tag ${l.live ? '' : 'warm'}">${l.live ? 'กำลังแสดง' : STATUS[l.status]}</span> ลิงก์ <a href="${CMS.base}${esc(l.path)}" target="_blank" rel="noopener">${esc(l.path)}</a> (ไม่อยู่ใน sitemap และการค้นหา)</p></div></div>
            <p id="nt-error" class="desk-error" role="alert"></p>
            <form id="nt-meta" class="desk-editor-card glass desk-form"><h2>ตั้งค่า</h2>
                <label class="desk-field"><span>หัวข้อ</span><input name="title" value="${esc(l.title)}" maxlength="200" required></label>
                <label class="desk-field"><span>คำอธิบาย (ไม่บังคับ)</span><textarea name="intro" maxlength="2000" rows="2">${esc(l.intro || '')}</textarea></label>
                <div class="notice-admin-grid"><label class="desk-field"><span>เริ่มเผยแพร่</span><input type="date" name="publish_from" value="${esc(l.publish_from.slice(0, 10))}" required></label>
                <label class="desk-field"><span>หมดอายุ (ไม่เกิน ${120} วันหลังวันเริ่ม)</span><input type="date" name="expires_on" value="${esc(l.expires_on.slice(0, 10))}" required></label></div>
                <label class="desk-field"><span>การแสดงชื่อ</span><select name="name_mode">${Object.entries(MODES).map(([k, v]) => `<option value="${k}" ${l.name_mode === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
                <div id="nt-full" class="desk-field" ${l.name_mode === 'full' ? '' : 'hidden'}>
                    <p class="desk-error" role="note"><strong>คำเตือนชื่อเต็ม:</strong> ชื่อเต็มของนักเรียนหรือผู้สมัครจะแสดงต่อผู้เยี่ยมชมที่ผ่าน Cloudflare Turnstile เท่านั้น (หน้าเว็บเริ่มต้นไม่มีชื่อ ไม่มีปุ่มดาวน์โหลด ไม่มีเบอร์โทรหรือเลขบัตร) และจะถูกปิดบังอัตโนมัติเมื่อถึงเวลาที่กำหนด ควรตั้งเวลาให้สั้นที่สุดเท่าที่จำเป็น ${l.turnstile ? '' : '<br><strong>ยังไม่ได้ตั้งค่า Turnstile จึงบันทึกโหมดชื่อเต็มไม่ได้</strong>'}</p>
                    <label class="desk-field"><span>แสดงชื่อเต็มถึงวันเวลา (ค่าเริ่มต้น เริ่มเผยแพร่ + ${l.full_name_default_days} วัน ไม่เกินสิ้นวันหมดอายุ)</span>
                        <input type="datetime-local" name="full_name_until" value="${esc(untilLocal(l.full_name_until) || (l.name_mode === 'full' ? '' : defaultUntil(l.publish_from.slice(0, 10), l.expires_on.slice(0, 10), l.full_name_default_days)))}"></label>
                    <p class="desk-help" id="nt-full-limit">ตั้งได้ตั้งแต่ ${esc(l.publish_from.slice(0, 10))} 00:00 ถึง ${esc(l.expires_on.slice(0, 10))} 23:59 หลังเวลานี้ชื่อจะแสดงแบบ ชื่อ + ตัวอักษรแรกของนามสกุล</p>
                </div>
                <label class="notice-admin-cols"><input type="checkbox" name="search_only" ${l.search_only ? 'checked' : ''}>โหมดค้นหาชื่อตัวเอง (ผู้เยี่ยมชมพิมพ์ชื่อ หรือเลขที่สมัคร/เลขที่นั่งสอบ 4 หลักขึ้นไปเมื่อเปิดคอลัมน์นั้น แสดงผลสูงสุด 5 รายการ ไม่แสดงรายชื่อทั้งหมด ต้องใช้ Turnstile)</label>
                <fieldset class="desk-field"><legend>คอลัมน์ที่แสดง</legend><div class="notice-admin-cols">${colChecks(cols)}</div></fieldset>
                <label class="desk-field"><span>รหัสเข้าดู ${l.has_access_code ? '(ตั้งไว้แล้ว ใส่ใหม่เพื่อเปลี่ยน)' : '(ไม่บังคับ 6-40 ตัวอักษร)'}</span><input name="access_code" type="password" minlength="6" maxlength="40" autocomplete="new-password"></label>
                ${l.has_access_code ? '<label class="notice-admin-cols"><input type="checkbox" name="clear_access_code">ยกเลิกรหัสเข้าดู</label>' : ''}
                <div class="desk-form-actions"><button class="primary" type="submit">บันทึกการตั้งค่า</button></div></form>
            <form id="nt-rows" class="desk-editor-card glass desk-form"><h2>วางรายชื่อจาก Excel / Google Sheets</h2>
                <p class="desk-help">คัดลอกจากตารางแล้ววาง (แถวแรกเป็นหัวตารางได้: ลำดับ ชื่อ ห้อง กลุ่ม หมายเหตุ) การวางจะแทนที่รายชื่อเดิมทั้งหมด ระบบปฏิเสธคอลัมน์หรือข้อมูลที่เหมือนเบอร์โทร เลขบัตรประชาชน วันเกิด และอีเมล คอลัมน์ที่เปิดอยู่: ${cols.map(c => LABEL[c]).join(', ')}</p>
                <textarea class="notice-admin-paste" name="text" aria-label="รายชื่อที่วาง" spellcheck="false"></textarea>
                <div class="desk-form-actions"><button class="primary" type="submit">แทนที่รายชื่อ</button></div></form>
            <section class="desk-editor-card glass"><h2>ตัวอย่างที่ผู้ชมเห็น (ชื่อปิดบังแล้ว) ${st.rows_total} รายการ</h2>${viewNote()}${preview()}</section>
            <section class="desk-editor-card glass"><h2>สถานะ</h2><div class="desk-form-actions">
                ${l.status === 'draft' ? '<button type="button" class="primary" data-act="publish">เผยแพร่</button>' : ''}${l.status === 'published' ? '<button type="button" data-act="archive">เก็บถาวร (ปิดการแสดง)</button>' : ''}${l.status === 'draft' ? '<button type="button" data-act="archive">เก็บถาวร</button>' : ''}${l.status === 'archived' ? '<button type="button" data-act="restore">กู้เป็นฉบับร่าง</button>' : ''}
                ${l.live ? '' : '<button type="button" data-act="delete">ลบรายการและรายชื่อ</button>'}</div>
                <p class="desk-help">รายชื่อจะถูกลบอัตโนมัติ 30 วันหลังหมดอายุ รายการที่กำลังแสดงต้องเก็บถาวรก่อนจึงลบได้</p></section>`;
            bind();
        };
        // What the public sees now and after full_name_until. The table below is always masked: full names are never sent back to this browser.
        const viewNote = () => { const v = st.public_view || {}, label = m => ({first_initial: 'ชื่อ + ตัวอักษรแรกของนามสกุล', first_only: 'ชื่อต้นเท่านั้น', full: 'ชื่อเต็ม'}[m] || m);
            const now = v.now === 'full_after_turnstile' ? 'ชื่อเต็ม (หลังผู้เยี่ยมชมผ่าน Turnstile หน้าเว็บเริ่มต้นไม่มีชื่อ)' : label(v.now);
            const after = v.now === 'full_after_turnstile' ? ' หลัง ' + esc(untilTh(v.until)) + ' ชื่อจะแสดงเป็น ' + label(v.after) + ' (ตามตารางด้านล่าง) และฝั่งสาธารณะจะแทนที่ชื่อเต็มโดยอัตโนมัติ' : '';
            return `<p class="desk-help"><strong>ตอนนี้ผู้ชมเห็น:</strong> ${esc(now)}${v.search_only ? ' แบบค้นหาชื่อตัวเอง (สูงสุด 5 รายการ)' : ''}.${after}${v.public_ready === false ? ' <strong>คำเตือน:</strong> ฝั่งสาธารณะยังไม่รองรับชื่อเต็มที่มีเวลาหมดอายุ หรือยังไม่ได้ตั้งค่า Turnstile จึงยังไม่ส่งชื่อเต็มไปที่นั่น (ผู้ชมจะเห็นชื่อปิดบัง) กรุณาอัปเดตและตั้งค่าฝั่งสาธารณะ' : ''} ตารางด้านล่างแสดงเฉพาะแบบปิดบังเพื่อความปลอดภัย</p>`; };
        const preview = () => { const cols = st.list.columns; if (!st.preview.length) return '<p class="desk-help">ยังไม่มีรายชื่อ</p>';
            return `<div class="notice-admin-preview"><table><thead><tr>${cols.map(c => `<th scope="col">${LABEL[c]}</th>`).join('')}</tr></thead><tbody>${st.preview.map(r => `<tr>${cols.map(c => `<td data-label="${LABEL[c]}">${esc(r[c] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>${st.rows_total > st.preview.length ? `<p class="desk-help">แสดง ${st.preview.length} แถวแรก</p>` : ''}`; };
        const fail = async err => { if (err.status === 409) { try { const fresh = await request('/api/notices/lists/' + id); if (location.hash !== route) return; st = fresh; draw(); } catch (e) { /* keep old view */ } }
            if (location.hash !== route) return; // the admin already opened another CMS page: never write into its #cms-view
            const e = root.querySelector('#nt-error'); if (e) e.textContent = err.status === 409 ? 'ข้อมูลถูกแก้จากที่อื่น โหลดข้อมูลล่าสุดให้แล้ว กรุณาตรวจและทำรายการใหม่' : err.message; };
        const bind = () => {
            const meta = root.querySelector('#nt-meta');
            const refill = () => { const f = meta.elements; if (f.name_mode.value === 'full' && f.publish_from.value && f.expires_on.value && !f.full_name_until.value) f.full_name_until.value = defaultUntil(f.publish_from.value, f.expires_on.value, st.list.full_name_default_days); };
            meta.elements.name_mode.onchange = () => { root.querySelector('#nt-full').hidden = meta.elements.name_mode.value !== 'full'; refill(); };
            meta.elements.publish_from.onchange = meta.elements.expires_on.onchange = () => { const f = meta.elements; if (f.name_mode.value !== 'full' || !f.publish_from.value || !f.expires_on.value) return; const u = f.full_name_until.value, max = f.expires_on.value + 'T23:59';
                if (!u || u < f.publish_from.value + 'T00:00') f.full_name_until.value = defaultUntil(f.publish_from.value, f.expires_on.value, st.list.full_name_default_days); else if (u > max) f.full_name_until.value = max; };
            meta.onsubmit = async e => { e.preventDefault(); if (!meta.reportValidity()) return; const f = meta.elements;
                const body = {version: st.list.version, title: f.title.value.trim(), intro: f.intro.value.trim() || null, publish_from: f.publish_from.value, expires_on: f.expires_on.value, name_mode: f.name_mode.value, columns: readCols(meta)};
                body.search_only = f.search_only.checked;
                if (f.name_mode.value === 'full' && f.full_name_until.value) body.full_name_until = f.full_name_until.value; // empty on a full list = leave as stored (a legacy list without a date stays expired)
                if (f.access_code.value) body.access_code = f.access_code.value; if (f.clear_access_code?.checked) body.clear_access_code = true;
                try { st = await request('/api/notices/lists/' + id, 'PUT', body); if (location.hash !== route) return; draw(); toast('บันทึกแล้ว'); } catch (err) { fail(err); } };
            const rows = root.querySelector('#nt-rows');
            rows.onsubmit = async e => { e.preventDefault(); const text = rows.elements.text.value; if (!text.trim()) return fail(new Error('ยังไม่ได้วางรายชื่อ'));
                if (st.rows_total && !confirm('แทนที่รายชื่อเดิม ' + st.rows_total + ' รายการ?')) return;
                try { st = await request('/api/notices/lists/' + id + '/rows', 'PUT', {version: st.list.version, text}); if (location.hash !== route) return; draw(); toast('บันทึกรายชื่อ ' + st.rows_total + ' รายการ'); } catch (err) { fail(err); } };
            root.querySelectorAll('[data-act]').forEach(b => b.onclick = async () => { const act = b.dataset.act;
                try {
                    if (act === 'delete') { if (!confirm('ลบรายการและรายชื่อทั้งหมดถาวร?')) return; await request('/api/notices/lists/' + id, 'DELETE', {version: st.list.version}); if (location.hash === route) location.hash = 'notices'; return; }
                    st = await request('/api/notices/lists/' + id + '/status', 'POST', {version: st.list.version, action: act}); if (location.hash !== route) return; draw(); toast('อัปเดตสถานะแล้ว');
                } catch (err) { fail(err); } });
        };
        draw();
    }

    window.CmsComplete.render = async parts => {
        if (parts[0] === 'notices') {
            window.CmsComplete.leave(); const root = document.querySelector('#cms-view'), route = location.hash;
            root.innerHTML = '<div class="loading glass">กำลังอ่านข้อมูล…</div>';
            try { return /^\d+$/.test(parts[1] || '') ? await editor(root, Number(parts[1]), route) : await list(root, route); }
            catch (err) { if (location.hash === route) root.innerHTML = `<div class="desk-editor-card glass"><h1>ประกาศรายชื่อ</h1><p class="desk-error" role="alert">${esc(err.message)}</p></div>`; }
            return;
        }
        return previous(parts);
    };
})();
