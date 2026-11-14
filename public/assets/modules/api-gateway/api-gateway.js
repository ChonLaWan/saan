'use strict';
// api-gateway module: staff pages #api (clients, scopes, secrets, usage) and #student-pins (PIN slips) for the /work shell.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    };
    // #work-view outlives page changes, so listeners go on a fresh host element and die with it
    const mount = root => { const host = document.createElement('div'); host.className = 'gw-host'; root.append(host); return host; };
    const lines = text => String(text || '').split(/\r?\n/).map(s => s.trim()).filter(Boolean);
    const fail = (err, reload) => {
        if (err?.name === 'AbortError') return;
        toast(err?.message || 'เกิดข้อผิดพลาด', true);
        if (err?.status === 409 && reload) reload();
    };

    // ---------------- #api ----------------
    async function apiPage(root, ctx) {
        const [scopes, clients, usage] = await Promise.all([
            ctx.api('/api/api-gateway/scopes'), ctx.api('/api/api-gateway/clients'), ctx.api('/api/api-gateway/usage'),
        ]);
        if (!ctx.alive()) return;
        const host = mount(root);
        const state = {clients: clients.data, usage, secret: null, editing: null};

        const scopeBoxes = (picked = []) => scopes.data.map(s =>
            `<label class="gw-check"><input type="checkbox" name="scopes" value="${esc(s.key)}"${picked.includes(s.key) ? ' checked' : ''}><span><code>${esc(s.key)}</code> ${esc(s.label)}</span></label>`).join('');

        const form = (c) => `<form class="gw-form" data-form="${c ? 'edit' : 'create'}" ${c ? `data-id="${c.id}" data-version="${c.version}"` : ''}>
            <label class="gw-field"><span>ชื่อแอป</span><input name="name" maxlength="120" required value="${esc(c?.name || '')}"></label>
            <fieldset class="gw-field"><legend>สิทธิ์ที่ให้แอปนี้ (scope)</legend>${scopeBoxes(c?.scopes || [])}</fieldset>
            <label class="gw-field"><span>IP ที่อนุญาต (บรรทัดละหนึ่งรายการ รองรับ CIDR เว้นว่าง = ไม่จำกัด)</span><textarea name="allowed_ips" rows="3">${esc((c?.allowed_ips || []).join('\n'))}</textarea></label>
            <label class="gw-field"><span>Redirect URI สำหรับให้นักเรียนเข้าสู่ระบบ (บรรทัดละหนึ่งรายการ ต้องเป็น https)</span><textarea name="redirect_uris" rows="2">${esc((c?.redirect_uris || []).join('\n'))}</textarea></label>
            <label class="gw-field"><span>จำนวนครั้งต่อนาที</span><input name="rate_per_minute" type="number" min="1" max="6000" value="${esc(c?.rate_per_minute ?? 120)}"></label>
            ${c ? `<label class="gw-check"><input type="checkbox" name="is_active"${c.is_active ? ' checked' : ''}><span>เปิดใช้งาน</span></label>` : ''}
            <div class="gw-actions"><button class="gw-btn gw-primary" type="submit">${c ? 'บันทึก' : 'สร้างไคลเอนต์'}</button>${c ? '<button class="gw-btn" type="button" data-act="cancel">ยกเลิก</button>' : ''}</div></form>`;

        const secretBox = () => !state.secret ? '' : `<section class="gw-secret" role="alert">
            <h2>เก็บ client secret ของ ${esc(state.secret.name)} ไว้เดี๋ยวนี้</h2>
            <p>ระบบแสดงรหัสนี้ครั้งเดียว และเก็บไว้เฉพาะค่าแฮช หากหายต้องออกรหัสใหม่</p>
            <dl><dt>client_id</dt><dd><code>${esc(state.secret.client_id)}</code></dd><dt>client_secret</dt><dd><code>${esc(state.secret.value)}</code></dd></dl>
            <div class="gw-actions"><button class="gw-btn" type="button" data-act="copy-secret">คัดลอก secret</button><button class="gw-btn gw-primary" type="button" data-act="hide-secret">เก็บแล้ว ปิดหน้านี้</button></div></section>`;

        const usageTable = () => {
            const days = state.usage.days;
            return `<div class="gw-scroll"><table class="gw-table gw-usage"><caption>จำนวนครั้งที่เรียกใช้ใน 7 วันล่าสุด (ผิดพลาด)</caption>
                <thead><tr><th scope="col">แอป</th>${days.map(d => `<th scope="col">${esc(window.SaanCore.date(d, {day: 'numeric', month: 'short'}))}</th>`).join('')}</tr></thead>
                <tbody>${state.usage.data.map(u => `<tr><th scope="row" data-label="แอป">${esc(u.name)}</th>${u.days.map(x =>
                    `<td data-label="${esc(window.SaanCore.date(x.date, {day: 'numeric', month: 'short'}))}">${esc(x.calls)}${x.errors ? ` <span class="gw-bad">(${esc(x.errors)})</span>` : ''}</td>`).join('')}</tr>`).join('')
                    || `<tr><td colspan="${days.length + 1}">ยังไม่มีไคลเอนต์</td></tr>`}</tbody></table></div>`;
        };

        const clientRows = () => state.clients.map(c => state.editing === c.id
            ? `<li class="gw-card">${form(c)}</li>`
            : `<li class="gw-card"><div class="gw-row"><h3>${esc(c.name)} ${c.is_active ? '' : '<span class="gw-badge gw-off">ปิดใช้งาน</span>'}</h3>
                <p><code>${esc(c.client_id)}</code></p>
                <p class="gw-meta">${c.scopes.map(s => `<code>${esc(s)}</code>`).join(' ')}</p>
                <p class="gw-meta">${esc(c.rate_per_minute)} ครั้ง/นาที | IP: ${c.allowed_ips.length ? esc(c.allowed_ips.join(', ')) : 'ไม่จำกัด'} | ใช้ล่าสุด: ${c.last_used_at ? esc(c.last_used_at) : 'ยังไม่เคย'}</p></div>
                <div class="gw-actions" data-id="${c.id}" data-version="${c.version}">
                    <button class="gw-btn" type="button" data-act="edit">แก้ไข</button>
                    <button class="gw-btn" type="button" data-act="rotate">ออก secret ใหม่</button>
                    ${c.is_active ? '<button class="gw-btn gw-danger" type="button" data-act="revoke">เพิกถอน</button>' : ''}</div></li>`).join('')
            || '<li class="gw-empty">ยังไม่มีไคลเอนต์ สร้างรายการแรกด้านล่าง</li>';

        const render = () => {
            host.innerHTML = `<section class="gw glass"><div class="gw-head"><h1>เชื่อมต่อแอปภายนอก (API)</h1></div>
                <p class="gw-lead">แอปภายนอกอ่านข้อมูลผ่าน <code>/api/v1</code> ด้วยโทเคนที่จำกัดสิทธิ์และจำนวนครั้ง ไม่เห็นวันเกิดหรือเลขประจำตัวประชาชน</p>
                ${secretBox()}
                <h2>ไคลเอนต์</h2><ul class="gw-list">${clientRows()}</ul>
                <details class="gw-new"${state.clients.length ? '' : ' open'}><summary>สร้างไคลเอนต์ใหม่</summary>${form(null)}</details>
                <h2>การใช้งาน 7 วันล่าสุด</h2>${usageTable()}</section>`;
        };
        const reload = async () => {
            const [c, u] = await Promise.all([ctx.api('/api/api-gateway/clients'), ctx.api('/api/api-gateway/usage')]);
            if (!ctx.alive()) return;
            state.clients = c.data; state.usage = u; state.editing = null; render();
        };
        const body = f => {
            const data = new FormData(f);
            const out = {name: String(data.get('name') || '').trim(), scopes: data.getAll('scopes'),
                allowed_ips: lines(data.get('allowed_ips')), redirect_uris: lines(data.get('redirect_uris')),
                rate_per_minute: Number(data.get('rate_per_minute')) || 120};
            if (f.dataset.form === 'edit') { out.version = Number(f.dataset.version); out.is_active = f.elements.is_active.checked; }
            return out;
        };

        host.addEventListener('submit', async ev => {
            const f = ev.target.closest('form.gw-form'); if (!f) return;
            ev.preventDefault();
            const btn = f.querySelector('button[type=submit]'); btn.disabled = true;
            try {
                if (f.dataset.form === 'create') {
                    const r = await ctx.api('/api/api-gateway/clients', {method: 'POST', body: body(f)});
                    state.secret = {name: r.client.name, client_id: r.client.client_id, value: r.client_secret};
                    render(); // show the one-time secret before the follow-up GET, which may fail
                    toast('สร้างไคลเอนต์แล้ว');
                } else {
                    await ctx.api('/api/api-gateway/clients/' + f.dataset.id, {method: 'PUT', body: body(f)});
                    toast('บันทึกแล้ว');
                }
                await reload();
            } catch (e) { fail(e, reload); } finally { btn.disabled = false; }
        });
        host.addEventListener('click', async ev => {
            const b = ev.target.closest('button[data-act]'); if (!b) return;
            const act = b.dataset.act, box = b.closest('[data-id]');
            const id = box?.dataset.id, version = Number(box?.dataset.version);
            try {
                if (act === 'hide-secret') { state.secret = null; render(); }
                else if (act === 'copy-secret') { await navigator.clipboard?.writeText(state.secret.value); toast('คัดลอกแล้ว'); }
                else if (act === 'edit') { state.editing = Number(id); render(); }
                else if (act === 'cancel') { state.editing = null; render(); }
                else if (act === 'rotate') {
                    if (!confirm('ออก secret ใหม่? secret เดิมและโทเคนที่ออกไปแล้วจะใช้ไม่ได้ทันที')) return;
                    const r = await ctx.api('/api/api-gateway/clients/' + id + '/rotate', {method: 'POST', body: {version}});
                    state.secret = {name: r.client.name, client_id: r.client.client_id, value: r.client_secret};
                    render(); // show the one-time secret before the follow-up GET, which may fail
                    await reload();
                } else if (act === 'revoke') {
                    if (!confirm('เพิกถอนไคลเอนต์นี้? แอปจะเรียกใช้ API ไม่ได้อีกจนกว่าจะเปิดใช้งานใหม่')) return;
                    await ctx.api('/api/api-gateway/clients/' + id + '/revoke', {method: 'POST', body: {version}});
                    toast('เพิกถอนแล้ว'); await reload();
                }
            } catch (e) { fail(e, reload); }
        });
        render();
    }

    // ---------------- #student-pins ----------------
    async function pinsPage(root, ctx) {
        const classes = (await ctx.api('/api/api-gateway/pins/classrooms')).data;
        if (!ctx.alive()) return;
        const host = mount(root);
        const state = {classroom: ctx.parts[0] && classes.find(c => String(c.id) === ctx.parts[0]) ? ctx.parts[0] : String(classes[0]?.id || ''), roster: [], slips: null};

        const slipSheet = () => !state.slips ? '' : `<section class="gw-slips" aria-label="ใบ PIN สำหรับพิมพ์">
            <div class="gw-actions gw-noprint"><button class="gw-btn gw-primary" type="button" data-act="print">พิมพ์ใบ PIN</button><button class="gw-btn" type="button" data-act="clear">ล้างหน้านี้</button></div>
            <p class="gw-noprint gw-warn">PIN แสดงครั้งเดียว ปิดหน้านี้แล้วจะดูย้อนหลังไม่ได้ ต้องออกใหม่เท่านั้น</p>
            <div class="gw-slip-grid">${state.slips.map(s => `<article class="gw-slip"><h3>${esc(s.display_name)}</h3>
                <p>รหัสนักเรียน <strong>${esc(s.code)}</strong></p><p>PIN <strong class="gw-pin">${esc(s.pin)}</strong></p>
                <p class="gw-small">ใช้คู่กับรหัสนักเรียนเพื่อเข้าแอปเรียนออนไลน์ ห้ามบอกผู้อื่น</p></article>`).join('')}</div></section>`;

        const rosterTable = () => `<div class="gw-scroll"><table class="gw-table"><thead><tr><th scope="col">รหัส</th><th scope="col">ชื่อ</th><th scope="col">สถานะ PIN</th><th scope="col"><span class="gw-sr">จัดการ</span></th></tr></thead><tbody>
            ${state.roster.map(s => `<tr><td data-label="รหัส">${esc(s.code)}</td><td data-label="ชื่อ">${esc(s.display_name)}</td>
                <td data-label="สถานะ PIN">${s.locked ? '<span class="gw-badge gw-off">ถูกล็อก</span>' : s.has_pin ? '<span class="gw-badge">มี PIN แล้ว</span>' : '<span class="gw-badge gw-none">ยังไม่มี</span>'}</td>
                <td><button class="gw-btn" type="button" data-act="reset" data-id="${s.id}">${s.has_pin ? 'รีเซ็ต PIN' : 'ออก PIN'}</button></td></tr>`).join('')
                || '<tr><td colspan="4">ห้องนี้ไม่มีนักเรียนที่กำลังศึกษา</td></tr>'}</tbody></table></div>`;

        const render = () => {
            host.innerHTML = `<section class="gw glass"><div class="gw-head gw-noprint"><h1>PIN เข้าแอปเรียนของนักเรียน</h1></div>
                ${classes.length ? `<div class="gw-noprint"><label class="gw-field"><span>ห้องเรียน</span><select id="gw-class">${classes.map(c => `<option value="${esc(c.id)}"${String(c.id) === state.classroom ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
                <div class="gw-actions"><button class="gw-btn gw-primary" type="button" data-act="issue-missing">ออก PIN ให้คนที่ยังไม่มี</button>
                <button class="gw-btn gw-danger" type="button" data-act="issue-all">รีเซ็ต PIN ทั้งห้อง</button></div>
                <p class="gw-lead">นักเรียนใช้รหัสนักเรียนคู่กับ PIN 6 หลัก ใส่ผิด 5 ครั้งจะถูกล็อก 30 นาที ครูประจำชั้นรีเซ็ตเพื่อปลดล็อกได้ทันที</p>
                ${rosterTable()}</div>` : '<p class="gw-empty">บัญชีนี้ไม่ได้เป็นครูประจำชั้นของห้องใด</p>'}
                ${slipSheet()}</section>`;
        };
        const load = async () => {
            if (!state.classroom) { render(); return; }
            const r = await ctx.api('/api/api-gateway/pins/classrooms/' + encodeURIComponent(state.classroom));
            if (!ctx.alive()) return;
            state.roster = r.data; render();
        };
        const issue = async (url, body, merge) => {
            const r = await ctx.api(url, {method: 'POST', body});
            if (!ctx.alive()) return;
            // a single-student reset keeps the slips already on screen (shown once, not yet printed)
            state.slips = merge && state.slips ? state.slips.filter(s => !r.data.some(n => n.code === s.code)).concat(r.data) : r.data;
            render(); // show the one-time PINs before the follow-up GET, which may fail
            toast(r.data.length ? 'ออก PIN แล้ว ' + r.data.length + ' คน' : 'ทุกคนมี PIN แล้ว');
            await load();
        };

        host.addEventListener('change', ev => { if (ev.target.id === 'gw-class') { state.classroom = ev.target.value; state.slips = null; location.hash = '#student-pins/' + state.classroom; } });
        host.addEventListener('click', async ev => {
            const b = ev.target.closest('button[data-act]'); if (!b) return;
            b.disabled = true;
            try {
                const act = b.dataset.act;
                if (act === 'print') window.print();
                else if (act === 'clear') { state.slips = null; render(); }
                else if (act === 'issue-missing') await issue('/api/api-gateway/pins/classrooms/' + state.classroom + '/issue', {only_missing: true});
                else if (act === 'issue-all') {
                    if (!confirm('รีเซ็ต PIN ทั้งห้อง? PIN เดิมของทุกคนจะใช้ไม่ได้')) return;
                    await issue('/api/api-gateway/pins/classrooms/' + state.classroom + '/issue', {});
                } else if (act === 'reset') await issue('/api/api-gateway/pins/students/' + b.dataset.id + '/reset', undefined, true);
            } catch (e) { fail(e, load); } finally { b.disabled = false; }
        });
        await load();
    }

    window.SaanWork.register('api', {title: 'เชื่อมต่อแอปภายนอก (API)', render: apiPage});
    window.SaanWork.register('student-pins', {title: 'PIN เข้าแอปเรียนของนักเรียน', render: pinsPage});
})();
