'use strict';
// activities module: SaanWork pages "clubs" and "elections".
// Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners on the page root).
(() => {
    const C = window.SaanCore;
    const esc = v => C.esc(v);
    const A = '/api/activities';
    const role = () => { try { return JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').role || ''; } catch { return ''; } };
    const isManager = () => role() === 'admin' || role() === 'principal';
    const opt = (pairs, sel) => pairs.map(([v, l]) => `<option value="${esc(v)}"${String(v) === String(sel ?? '') ? ' selected' : ''}>${esc(l)}</option>`).join('');
    const ELECTION_STATUS = {draft: ['ร่าง', 'off'], open: ['เปิดลงคะแนน', 'ok'], closed: ['ปิดแล้ว', 'warn']};
    const when = iso => iso ? C.date(iso.slice(0, 10)) + ' ' + iso.slice(11, 16) + ' น.' : '';

    function notice(root, kind, text) {
        const n = root.querySelector('.act-msg');
        if (!n) return;
        n.innerHTML = text ? `<div class="act-notice ${esc(kind)}" role="${kind === 'error' ? 'alert' : 'status'}">${text}</div>` : '';
        if (text) n.scrollIntoView?.({block: 'nearest'});
    }
    function fail(root, e) {
        if (!e || e.name === 'AbortError') return;
        if (e.status === 409 && e.current) { notice(root, 'error', `${esc(e.message)} <button type="button" class="act-btn" data-act="reload">โหลดข้อมูลล่าสุด</button>`); return; }
        const list = e.data?.errors ? Object.values(e.data.errors).flat() : [e.message || 'เกิดข้อผิดพลาด'];
        notice(root, 'error', `<ul>${list.map(x => `<li>${esc(x)}</li>`).join('')}</ul>`);
    }
    // One delegated listener set per render, removed when the page's signal aborts (SaanWork reuses the same root);
    // handlers receive (name, element) and may throw: errors are shown in .act-msg.
    function wire(root, signal, h) {
        root.addEventListener('click', async ev => {
            const b = ev.target.closest('[data-act]');
            if (!b || !root.contains(b) || b.disabled) return;
            ev.preventDefault();
            if (b.dataset.act === 'reload') { await h.reload(); return; }
            b.disabled = true;
            try { await h.click(b.dataset.act, b); } catch (e) { fail(root, e); } finally { b.disabled = false; }
        }, {signal});
        root.addEventListener('submit', async ev => {
            const f = ev.target.closest('form[data-form]');
            if (!f || !root.contains(f)) return;
            ev.preventDefault();
            const all = [...f.querySelectorAll('button')];
            all.forEach(x => { x.disabled = true; });
            try { await h.submit(f.dataset.form, f); } catch (e) { fail(root, e); } finally { all.forEach(x => { x.disabled = false; }); }
        }, {signal});
    }
    const fd = f => Object.fromEntries(new FormData(f).entries());
    const num = v => (v === '' || v == null) ? null : Number(v);
    const levels = v => String(v || '').split(',').map(x => x.trim()).filter(Boolean);

    // ====================================================================== clubs
    window.SaanWork.register('clubs', {
        title: 'ชุมนุม',
        async render(root, ctx) {
            const parts = ctx.parts;
            root.innerHTML = '<div class="act-page"><div class="act-msg"></div><div class="act-body"></div></div>';
            const body = root.querySelector('.act-body');
            const state = {termId: '', list: null, detail: null, classrooms: null, att: null};
            const reload = async () => { notice(root, 'ok', ''); await show(); };
            const show = async () => {
                if (parts[0] === 'session' && parts[1]) return sessionView();
                if (parts[0] && /^\d+$/.test(parts[0])) return detail(Number(parts[0]));
                return list();
            };

            async function list() {
                const d = state.list = await ctx.api(`${A}/clubs${state.termId ? '?term_id=' + encodeURIComponent(state.termId) : ''}`);
                if (!ctx.alive()) return;
                state.termId = String(d.term_id);
                const rows = d.data.map(c => `<article class="act-card glass">
                    <div class="act-head"><h2>${esc(c.name)}</h2>${c.is_open ? '<span class="act-badge ok">เปิดรับสมัคร</span>' : '<span class="act-badge off">ปิดรับ</span>'}</div>
                    <div>ครูที่ปรึกษา: ${esc(c.advisor_name || '-')}</div>
                    <div>สมาชิก ${esc(c.enrolled)}${c.capacity ? ' / ' + esc(c.capacity) : ''} คน${c.waitlist ? ' (สำรอง ' + esc(c.waitlist) + ')' : ''}${c.grade_levels.length ? ' | ระดับ ' + esc(c.grade_levels.join(', ')) : ''}</div>
                    ${c.description ? `<p>${esc(c.description)}</p>` : ''}
                    <div class="act-row">${c.can_manage ? `<a class="act-btn" href="#clubs/${esc(c.id)}">จัดการชุมนุม</a>` : ''}
                    ${c.is_open ? `<button type="button" class="act-btn primary" data-act="enroll-open" data-id="${esc(c.id)}">ลงทะเบียนนักเรียน</button>` : ''}</div></article>`).join('');
                const form = isManager() ? `<form data-form="club-new" class="act-card glass"><h2>เพิ่มชุมนุม</h2><div class="act-form">
                    <label class="act-field">ชื่อชุมนุม<input name="name" required maxlength="160"></label>
                    <label class="act-field">ครูที่ปรึกษา<select name="advisor_teacher_id"><option value="">ยังไม่กำหนด</option>${opt(d.teachers.map(t => [t.id, t.name]))}</select></label>
                    <label class="act-field">จำนวนรับ (ว่าง = ไม่จำกัด)<input name="capacity" type="number" min="1" max="2000" inputmode="numeric"></label>
                    <label class="act-field">ระดับชั้นที่รับ (คั่นด้วย , เช่น ม.1, ม.2)<input name="grade_levels" maxlength="200"></label>
                    <label class="act-field">รายละเอียด<input name="description" maxlength="2000"></label></div>
                    <div class="act-row"><button class="act-btn primary" type="submit">บันทึกชุมนุม</button></div></form>
                    <form data-form="settings" class="act-card glass"><h2>เกณฑ์และเกียรติบัตร</h2><div class="act-form">
                    <label class="act-field">เวลาเข้าร่วมขั้นต่ำเพื่อผ่าน (%)<input name="pass_percent" type="number" min="1" max="100" value="${esc(d.settings.pass_percent)}"></label>
                    <label class="act-field">อักษรนำเลขเกียรติบัตร (A-Z 0-9)<input name="cert_prefix" maxlength="12" value="${esc(d.settings.cert_prefix)}"></label>
                    <label class="act-field">ผู้ลงนาม<input name="signer_name" maxlength="120" value="${esc(d.settings.signer_name)}"></label>
                    <label class="act-field">ตำแหน่งผู้ลงนาม<input name="signer_title" maxlength="120" value="${esc(d.settings.signer_title)}"></label></div>
                    <div class="act-row"><button class="act-btn" type="submit">บันทึกการตั้งค่า</button></div></form>` : '';
                body.innerHTML = `<section class="act-card glass"><div class="act-head"><h1>ชุมนุม</h1>
                    <label class="act-field">ภาคเรียน<select data-change="term">${opt(d.terms.map(t => [t.id, t.label]), d.term_id)}</select></label></div></section>
                    <div class="act-grid">${rows || '<p class="act-card glass">ยังไม่มีชุมนุมในภาคเรียนนี้</p>'}</div><div class="act-enroll"></div>${form}`;
            }

            async function enrollPanel(clubId) {
                if (!state.classrooms) state.classrooms = (await ctx.api(`${A}/classrooms`)).data;
                const box = body.querySelector('.act-enroll');
                const club = state.list.data.find(c => c.id === clubId);
                box.innerHTML = `<section class="act-card glass" data-club="${esc(clubId)}"><div class="act-head"><h2>ลงทะเบียนเข้าชุมนุม ${esc(club?.name)}</h2></div>
                    <label class="act-field">เลือกห้อง<select data-change="enroll-room"><option value="">เลือกห้องเรียน</option>${opt(state.classrooms.map(c => [c.id, c.name]))}</select></label><div class="act-roster"></div></section>`;
                box.scrollIntoView?.({block: 'start'});
            }

            async function loadRoster(clubId, roomId) {
                const holder = body.querySelector('.act-roster');
                if (!roomId) { holder.innerHTML = ''; return; }
                const d = await ctx.api(`${A}/classrooms/${encodeURIComponent(roomId)}/students?term_id=${encodeURIComponent(state.termId)}`);
                if (!ctx.alive()) return;
                const label = s => s.club ? `อยู่ในชุมนุม ${esc(s.club.name)}${s.club.status === 'waitlist' ? ' (สำรอง)' : ''}` : '';
                holder.innerHTML = `<form data-form="enroll" data-club="${esc(clubId)}"><div class="act-row"><button class="act-btn" type="button" data-act="enroll-all">เลือกทั้งห้องที่ยังไม่มีชุมนุม</button></div>
                    <table class="act-table"><thead><tr><th>เลือก</th><th>รหัส</th><th>ชื่อ</th><th>สถานะ</th></tr></thead><tbody>${d.data.map(s => `<tr>
                    <td data-label="เลือก"><input type="checkbox" name="s" value="${esc(s.id)}" ${s.club ? 'disabled' : ''} aria-label="เลือก ${esc(s.name)}"></td><td data-label="รหัส">${esc(s.code)}</td><td data-label="ชื่อ">${esc(s.name)}</td><td data-label="สถานะ">${label(s)}</td></tr>`).join('')}</tbody></table>
                    <div class="act-row"><button class="act-btn primary" type="submit">ลงทะเบียนที่เลือก</button></div></form>`;
            }

            async function detail(id) {
                const d = state.detail = await ctx.api(`${A}/clubs/${id}`);
                if (!ctx.alive()) return;
                const c = d.club;
                const certBtn = `<button type="button" class="act-btn" data-act="issue-certs">ออกเกียรติบัตรให้ผู้ที่ผ่าน</button> <a class="act-btn" target="_blank" rel="noopener" href="${esc((document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, ''))}/activities/clubs/${esc(id)}/certificates/print">พิมพ์เกียรติบัตร</a>`;
                body.innerHTML = `<section class="act-card glass"><div class="act-head"><h1>${esc(c.name)}</h1><a class="act-btn" href="#clubs">กลับรายการชุมนุม</a></div>
                    <div>ครูที่ปรึกษา: ${esc(c.advisor_name || '-')} | สมาชิก ${esc(c.enrolled)}${c.capacity ? ' / ' + esc(c.capacity) : ''} คน | เกณฑ์ผ่าน ${esc(d.pass_percent)}%</div>
                    ${isManager() ? `<form data-form="club-edit" class="act-form"><label class="act-field">ชื่อ<input name="name" value="${esc(c.name)}" maxlength="160"></label>
                    <label class="act-field">จำนวนรับ<input name="capacity" type="number" min="1" value="${esc(c.capacity ?? '')}"></label>
                    <label class="act-field">เปิดรับสมัคร<select name="is_open">${opt([[1, 'เปิดรับ'], [0, 'ปิดรับ']], c.is_open ? 1 : 0)}</select></label>
                    <div class="act-row"><button class="act-btn" type="submit">บันทึก</button></div></form>` : ''}</section>
                    <section class="act-card glass"><div class="act-head"><h2>คาบกิจกรรม</h2></div>
                    <form data-form="session-new" class="act-form"><label class="act-field">วันที่<input type="date" name="date" required value="${esc(C.today())}"></label>
                    <label class="act-field">หัวข้อ<input name="topic" maxlength="200"></label><div class="act-row"><button class="act-btn primary" type="submit">เพิ่มคาบ</button></div></form>
                    <ul>${d.sessions.map(s => `<li><a href="#clubs/session/${esc(s.id)}">${esc(C.date(s.date))}</a> ${esc(s.topic)}</li>`).join('') || '<li>ยังไม่มีคาบกิจกรรม</li>'}</ul></section>
                    <section class="act-card glass"><div class="act-head"><h2>สมาชิกและผล</h2><div class="act-row"><button type="button" class="act-btn" data-act="compute">สรุปผลจากการเข้าร่วม</button>${certBtn}</div></div>
                    <table class="act-table"><thead><tr><th>ชื่อ</th><th>ห้อง</th><th>สถานะ</th><th>เข้าร่วม</th><th>ผล</th><th>เกียรติบัตร</th><th></th></tr></thead><tbody>${d.members.map(m => `<tr>
                    <td data-label="ชื่อ">${esc(m.name)}</td><td data-label="ห้อง">${esc(m.classroom_name || '')}</td><td data-label="สถานะ">${m.status === 'waitlist' ? '<span class="act-badge warn">สำรอง</span>' : 'สมาชิก'}</td>
                    <td data-label="เข้าร่วม">${m.percent === null ? '-' : esc(m.present) + '/' + esc(m.sessions) + ' (' + esc(m.percent) + '%)'}</td>
                    <td data-label="ผล">${m.result ? `<span class="act-badge ${m.result === 'ผ' ? 'ok' : 'warn'}">${esc(m.result)}</span>` : '-'}${m.result_manual ? ' (แก้เอง)' : ''}</td>
                    <td data-label="เกียรติบัตร">${esc(m.certificate_no || '')}</td>
                    <td data-label="จัดการ"><div class="act-row">${m.status === 'enrolled' ? `<button type="button" class="act-btn" data-act="override" data-id="${esc(m.id)}" data-version="${esc(m.version)}">แก้ผล</button>` : ''}
                    <button type="button" class="act-btn danger" data-act="leave" data-id="${esc(m.id)}" data-version="${esc(m.version)}">นำออก</button></div></td></tr>`).join('')}</tbody></table></section>`;
            }

            async function sessionView() {
                const d = state.att = await ctx.api(`${A}/sessions/${encodeURIComponent(parts[1])}/attendance`);
                if (!ctx.alive()) return;
                body.innerHTML = `<section class="act-card glass"><div class="act-head"><h1>เช็คชื่อ ${esc(C.date(d.session.date))}</h1><a class="act-btn" href="#clubs/${esc(d.session.club_id)}">กลับ</a></div>
                    <p>${esc(d.session.topic)}</p><form data-form="attendance"><div class="act-row"><button type="button" class="act-btn" data-act="all-present">มาทั้งหมด</button></div>
                    <table class="act-table"><thead><tr><th>รหัส</th><th>ชื่อ</th><th>มา</th></tr></thead><tbody>${d.roster.map(r => `<tr><td data-label="รหัส">${esc(r.code)}</td><td data-label="ชื่อ">${esc(r.name)}</td>
                    <td data-label="มา"><input type="checkbox" name="p" value="${esc(r.student_id)}" ${r.present ? 'checked' : ''} aria-label="มา ${esc(r.name)}"></td></tr>`).join('')}</tbody></table>
                    <div class="act-row"><button class="act-btn primary" type="submit">บันทึกการเช็คชื่อ</button></div></form></section>`;
            }

            wire(root, ctx.signal, {
                reload,
                async click(act, b) {
                    if (act === 'enroll-open') return enrollPanel(Number(b.dataset.id));
                    if (act === 'enroll-all') { body.querySelectorAll('input[name=s]:not(:disabled)').forEach(i => { i.checked = true; }); return; }
                    if (act === 'all-present') { body.querySelectorAll('input[name=p]').forEach(i => { i.checked = true; }); return; }
                    if (act === 'compute') {
                        const r = await ctx.api(`${A}/clubs/${parts[0]}/results/compute`, {method: 'POST'});
                        notice(root, 'ok', esc(r.note || `สรุปผลแล้ว ผ ${r.passed} คน มผ ${r.failed} คน (คงผลที่แก้เองไว้ ${r.manual_kept} คน)`));
                        return detail(Number(parts[0]));
                    }
                    if (act === 'issue-certs') {
                        const r = await ctx.api(`${A}/clubs/${parts[0]}/certificates`, {method: 'POST', idempotencyKey: C.uid()});
                        notice(root, 'ok', esc(`ออกเกียรติบัตรใหม่ ${r.count} ใบ`));
                        return detail(Number(parts[0]));
                    }
                    if (act === 'leave') {
                        if (!window.confirm('นำสมาชิกคนนี้ออกจากชุมนุม?')) return;
                        await ctx.api(`${A}/members/${b.dataset.id}/leave`, {method: 'POST', body: {version: Number(b.dataset.version)}});
                        return detail(Number(parts[0]));
                    }
                    if (act === 'override') {
                        const res = window.prompt('ผลการประเมิน: พิมพ์ ผ หรือ มผ (เว้นว่างเพื่อกลับไปใช้ผลจากการเข้าร่วม)', 'ผ');
                        if (res === null) return;
                        const result = res.trim() === '' ? null : res.trim();
                        const reason = window.prompt('เหตุผลที่แก้ผล (บันทึกไว้ในประวัติ)', '');
                        if (!reason) return;
                        await ctx.api(`${A}/members/${b.dataset.id}/result`, {method: 'PUT', body: {version: Number(b.dataset.version), result, reason}});
                        return detail(Number(parts[0]));
                    }
                },
                async submit(name, f) {
                    const v = fd(f);
                    if (name === 'club-new') {
                        await ctx.api(`${A}/clubs`, {method: 'POST', idempotencyKey: C.uid(), body: {name: v.name, advisor_teacher_id: num(v.advisor_teacher_id), capacity: num(v.capacity), grade_levels: levels(v.grade_levels), description: v.description || null, term_id: num(state.termId)}});
                        notice(root, 'ok', 'บันทึกชุมนุมแล้ว');
                        return list();
                    }
                    if (name === 'settings') {
                        await ctx.api(`${A}/settings`, {method: 'PUT', body: {pass_percent: num(v.pass_percent), cert_prefix: v.cert_prefix || '', signer_name: v.signer_name || '', signer_title: v.signer_title || ''}});
                        notice(root, 'ok', 'บันทึกการตั้งค่าแล้ว');
                        return list();
                    }
                    if (name === 'club-edit') {
                        const c = state.detail.club;
                        await ctx.api(`${A}/clubs/${c.id}`, {method: 'PUT', body: {version: c.version, name: v.name, capacity: num(v.capacity), is_open: v.is_open === '1'}});
                        notice(root, 'ok', 'บันทึกแล้ว');
                        return detail(c.id);
                    }
                    if (name === 'session-new') {
                        await ctx.api(`${A}/clubs/${parts[0]}/sessions`, {method: 'POST', body: {date: v.date, topic: v.topic || null}});
                        return detail(Number(parts[0]));
                    }
                    if (name === 'enroll') {
                        const ids = [...f.querySelectorAll('input[name=s]:checked')].map(i => Number(i.value));
                        if (!ids.length) { notice(root, 'error', 'กรุณาเลือกนักเรียนอย่างน้อย 1 คน'); return; }
                        const r = await ctx.api(`${A}/clubs/${f.dataset.club}/enroll`, {method: 'POST', idempotencyKey: C.uid(), body: {student_ids: ids}});
                        const why = r.rejected.map(x => esc(x.reason)).join(', ');
                        notice(root, r.rejected.length ? 'error' : 'ok', `ได้ที่นั่ง ${esc(r.enrolled)} คน สำรอง ${esc(r.waitlisted)} คน${r.rejected.length ? ' ไม่ผ่าน ' + esc(r.rejected.length) + ' คน: ' + why : ''}`);
                        return list();
                    }
                    if (name === 'attendance') {
                        const checked = new Set([...f.querySelectorAll('input[name=p]:checked')].map(i => Number(i.value)));
                        const records = state.att.roster.map(r => ({student_id: r.student_id, present: checked.has(r.student_id)}));
                        const r = await ctx.api(`${A}/sessions/${state.att.session.id}/attendance`, {method: 'PUT', body: {version: state.att.session.version, records}});
                        state.att.session.version = r.version;
                        notice(root, 'ok', 'บันทึกการเช็คชื่อแล้ว');
                    }
                },
            });
            root.addEventListener('change', async ev => {
                const s = ev.target.closest('select[data-change]');
                if (!s) return;
                try {
                    if (s.dataset.change === 'term') { state.termId = s.value; await list(); }
                    if (s.dataset.change === 'enroll-room') await loadRoster(Number(s.closest('[data-club]').dataset.club), s.value);
                } catch (e) { fail(root, e); }
            }, {signal: ctx.signal});
            await show();
        },
    });

    // ====================================================================== elections
    window.SaanWork.register('elections', {
        title: 'เลือกตั้งสภานักเรียน',
        async render(root, ctx) {
            const parts = ctx.parts;
            root.innerHTML = '<div class="act-page"><div class="act-msg"></div><div class="act-body"></div></div>';
            const body = root.querySelector('.act-body');
            const state = {election: null, kiosk: null, ticket: null, timer: null};
            const stop = () => { if (state.timer) { clearInterval(state.timer); state.timer = null; } };
            const reload = async () => { notice(root, 'ok', ''); await show(); };
            const show = async () => {
                stop();
                if (parts[0] === 'kiosk' && parts[1] && parts[2]) return kiosk(Number(parts[1]), Number(parts[2]));
                if (parts[0] && /^\d+$/.test(parts[0])) return detail(Number(parts[0]));
                return list();
            };

            async function list() {
                const d = await ctx.api(`${A}/elections`);
                if (!ctx.alive()) return;
                const form = d.manager ? `<form data-form="election-new" class="act-card glass"><h2>สร้างการเลือกตั้ง</h2><div class="act-form">
                    <label class="act-field">ชื่อการเลือกตั้ง<input name="title" required maxlength="200"></label>
                    <label class="act-field">เปิดลงคะแนน<input type="datetime-local" name="opens_at" required></label>
                    <label class="act-field">ปิดลงคะแนน<input type="datetime-local" name="closes_at" required></label>
                    <label class="act-field">ระดับชั้นที่มีสิทธิ์ (คั่นด้วย , ว่าง = ทุกระดับ)<input name="grades" maxlength="200"></label></div>
                    <div class="act-row"><button class="act-btn primary" type="submit">สร้าง</button></div></form>` : '';
                body.innerHTML = `<section class="act-card glass"><h1>เลือกตั้งสภานักเรียน</h1></section><div class="act-grid">${d.data.map(e => {
                    const [label, cls] = ELECTION_STATUS[e.status] || [e.status, 'off'];
                    return `<article class="act-card glass"><div class="act-head"><h2>${esc(e.title)}</h2><span class="act-badge ${cls}">${esc(label)}</span></div>
                    <div>${esc(when(e.opens_at))} ถึง ${esc(when(e.closes_at))}</div><div>ผู้สมัคร ${esc(e.candidates)} คน | ลงคะแนนแล้ว ${esc(e.voted)} คน</div>
                    <div class="act-row"><a class="act-btn primary" href="#elections/${esc(e.id)}">เปิดดู</a></div></article>`;
                }).join('') || '<p class="act-card glass">ยังไม่มีการเลือกตั้ง</p>'}</div>${form}`;
            }

            async function detail(id) {
                const [d, t] = await Promise.all([ctx.api(`${A}/elections/${id}`), ctx.api(`${A}/elections/${id}/turnout`)]);
                if (!ctx.alive()) return;
                const e = state.election = d.election;
                const [label, cls] = ELECTION_STATUS[e.status] || [e.status, 'off'];
                let results = '';
                if (e.status === 'closed') {
                    const r = await ctx.api(`${A}/elections/${id}/results`);
                    if (!ctx.alive()) return;
                    const max = Math.max(1, ...r.results.map(x => x.votes), r.abstain);
                    results = `<section class="act-card glass"><h2>ผลการเลือกตั้ง</h2>${r.tie ? '<div class="act-notice error">คะแนนสูงสุดเท่ากัน ต้องตัดสินตามระเบียบของสภานักเรียน</div>' : ''}
                        <table class="act-table"><thead><tr><th>หมายเลข</th><th>ผู้สมัคร</th><th>คะแนน</th><th></th></tr></thead><tbody>${r.results.map(x => `<tr><td data-label="หมายเลข">${esc(x.number)}</td><td data-label="ผู้สมัคร">${esc(x.name)}</td>
                        <td data-label="คะแนน">${esc(x.votes)}</td><td><div class="act-bar"><span style="width:${esc(Math.round(x.votes * 100 / max))}%"></span></div></td></tr>`).join('')}
                        <tr><td data-label="หมายเลข">-</td><td data-label="ผู้สมัคร">ไม่ประสงค์ลงคะแนน</td><td data-label="คะแนน">${esc(r.abstain)}</td><td></td></tr></tbody></table><p>บัตรทั้งหมด ${esc(r.ballots)} ใบ จากผู้มีสิทธิ์ ${esc(r.eligible)} คน</p></section>`;
                } else {
                    results = '<section class="act-card glass"><h2>ผลการเลือกตั้ง</h2><p>ผลจะแสดงหลังปิดการลงคะแนนเท่านั้น</p></section>';
                }
                const cands = d.candidates.map(c => `<li>หมายเลข ${esc(c.number)} ${esc(c.name)} (${esc(c.classroom_name || '-')}) ${esc(c.slogan)}
                    ${d.manager && e.status === 'draft' ? `<button type="button" class="act-btn danger" data-act="cand-del" data-id="${esc(c.id)}" data-version="${esc(c.version)}">ลบ</button>` : ''}</li>`).join('');
                const actions = d.manager ? `<div class="act-row">${e.status === 'draft' ? '<button type="button" class="act-btn primary" data-act="open">เปิดลงคะแนน</button> <button type="button" class="act-btn danger" data-act="del">ลบร่าง</button>' : ''}
                    ${e.status === 'open' ? '<button type="button" class="act-btn danger" data-act="close">ปิดการลงคะแนนและนับคะแนน</button>' : ''}</div>` : '';
                const rooms = t.classrooms.map(c => `<tr><td data-label="ห้อง">${esc(c.name)}</td><td data-label="ลงคะแนนแล้ว">${esc(c.voted)} / ${esc(c.eligible)}</td>
                    <td data-label="คูหา">${e.voting_now ? `<a class="act-btn primary" href="#elections/kiosk/${esc(id)}/${esc(c.classroom_id)}">เปิดคูหา</a>` : ''}</td></tr>`).join('');
                body.innerHTML = `<section class="act-card glass"><div class="act-head"><h1>${esc(e.title)}</h1><span class="act-badge ${cls}">${esc(label)}</span></div>
                    <div>${esc(when(e.opens_at))} ถึง ${esc(when(e.closes_at))}${e.eligible_grade_levels.length ? ' | ระดับ ' + esc(e.eligible_grade_levels.join(', ')) : ' | ทุกระดับ'}</div>${actions}
                    <a class="act-btn" href="#elections">กลับรายการ</a></section>
                    <section class="act-card glass"><h2>ผู้สมัคร</h2><ul>${cands || '<li>ยังไม่มีผู้สมัคร</li>'}</ul>
                    ${d.manager && e.status === 'draft' ? `<form data-form="cand-new" class="act-form"><label class="act-field">หมายเลข<input name="number" type="number" min="1" max="99" required></label>
                    <label class="act-field">รหัสนักเรียน<input name="code" required maxlength="40"></label><label class="act-field">คำขวัญ<input name="slogan" maxlength="300"></label>
                    <div class="act-row"><button class="act-btn primary" type="submit">เพิ่มผู้สมัคร</button></div></form>` : ''}</section>
                    <section class="act-card glass" data-live="${esc(id)}"><div class="act-head"><h2>ผู้มาลงคะแนน</h2></div><div class="act-turnout">${turnout(t)}</div>
                    <table class="act-table act-rooms"><thead><tr><th>ห้อง</th><th>ลงคะแนนแล้ว</th><th></th></tr></thead><tbody>${rooms}</tbody></table></section>${results}`;
                if (e.status === 'open') {
                    // live turnout: poll every 10s while this page is on screen; stops on navigation
                    state.timer = setInterval(async () => {
                        if (!ctx.alive()) { stop(); return; }
                        try { const n = await ctx.api(`${A}/elections/${id}/turnout`); const el = body.querySelector('.act-turnout'); if (el && ctx.alive()) el.innerHTML = turnout(n); } catch { /* next tick */ }
                    }, 10000);
                }
            }
            const turnout = t => `<p>ลงคะแนนแล้ว ${esc(t.voted)} จาก ${esc(t.eligible)} คน (${esc(t.percent)}%)</p><div class="act-bar" aria-hidden="true"><span style="width:${esc(Math.min(100, t.percent))}%"></span></div>`;

            // Voting kiosk: the teacher picks one student, the student taps a candidate, the screen locks again.
            async function kiosk(id, roomId) {
                const d = state.kiosk = await ctx.api(`${A}/elections/${id}/kiosk/${roomId}`);
                if (!ctx.alive()) return;
                state.ticket = null;
                const head = `<section class="act-card glass"><div class="act-head"><h1>คูหาลงคะแนน: ${esc(d.election.title)}</h1><a class="act-btn" href="#elections/${esc(id)}">กลับ</a></div>
                    <p>ลงคะแนนแล้ว ${esc(d.voted)} จาก ${esc(d.total)} คน ${d.election.voting_now ? '' : '<strong>(ยังไม่อยู่ในช่วงเวลาลงคะแนน)</strong>'}</p></section>`;
                if (!d.election.voting_now) { body.innerHTML = head; return; }
                body.innerHTML = head + `<section class="act-card glass act-kiosk"><h2>ครูเลือกชื่อนักเรียนที่จะลงคะแนน</h2><div class="act-students">${d.students.map(s => `<button type="button" class="act-btn" data-act="unlock" data-id="${esc(s.id)}" ${s.voted ? 'disabled' : ''}>${esc(s.name)}${s.voted ? ' (ลงแล้ว)' : ''}</button>`).join('')}</div></section>`;
            }
            function ballot(id, student, ticket, expiresIn) {
                const d = state.kiosk;
                const mins = expiresIn ? Math.max(1, Math.round(expiresIn / 60)) : null;
                body.innerHTML = `<section class="act-card glass act-kiosk" data-ticket="${esc(ticket)}"><h1>เลือกผู้สมัคร</h1><p class="act-voter">ผู้ลงคะแนน: <strong>${esc(student.name)}</strong>${mins ? ' (ใช้ได้ภายใน ' + esc(mins) + ' นาที)' : ''}</p><p>นักเรียนแตะหมายเลขที่ต้องการ (ครูไม่ต้องดูหน้าจอ)</p><div class="act-ballot">${d.candidates.map(c => `<button type="button" class="act-cand" data-act="vote" data-id="${esc(c.id)}"><strong>${esc(c.number)}</strong><span>${esc(c.name)}</span><small>${esc(c.slogan)}</small></button>`).join('')}
                    <button type="button" class="act-cand abstain" data-act="vote" data-id="">ไม่ประสงค์ลงคะแนน</button></div>
                    <div class="act-row"><button type="button" class="act-btn" data-act="cancel-ballot">ยกเลิก (กลับไปเลือกชื่อ)</button></div></section>`;
            }

            wire(root, ctx.signal, {
                reload,
                async click(act, b) {
                    const id = Number(parts[1] ?? parts[0]);
                    if (act === 'unlock') {
                        const r = await ctx.api(`${A}/elections/${id}/unlock`, {method: 'POST', body: {student_id: Number(b.dataset.id)}});
                        state.ticket = r.ticket;
                        return ballot(id, r.student, r.ticket, r.expires_in);
                    }
                    if (act === 'cancel-ballot') { state.ticket = null; return kiosk(id, Number(parts[2])); }
                    if (act === 'vote') {
                        const ticket = state.ticket;
                        state.ticket = null; // single use: a second tap cannot send a second request
                        if (!ticket) return;
                        const all = body.querySelectorAll('.act-cand');
                        all.forEach(x => { x.disabled = true; });
                        try {
                            await ctx.api(`${A}/elections/${id}/vote`, {method: 'POST', body: {ticket, candidate_id: b.dataset.id === '' ? null : Number(b.dataset.id)}});
                        } catch (e) { await kiosk(id, Number(parts[2])); throw e; }
                        await kiosk(id, Number(parts[2]));
                        notice(root, 'ok', 'บันทึกคะแนนแล้ว ขอบคุณ');
                        return;
                    }
                    const e = state.election;
                    if (act === 'open') { await ctx.api(`${A}/elections/${e.id}/status`, {method: 'POST', body: {version: e.version, action: 'open'}}); return detail(e.id); }
                    if (act === 'close') {
                        if (!window.confirm('ปิดการลงคะแนนแล้วจะเปิดใหม่ไม่ได้ และระบบจะลบกุญแจที่เชื่อมบัตรกับนักเรียน ยืนยัน?')) return;
                        await ctx.api(`${A}/elections/${e.id}/status`, {method: 'POST', body: {version: e.version, action: 'close'}});
                        return detail(e.id);
                    }
                    if (act === 'del') {
                        if (!window.confirm('ลบการเลือกตั้งร่างนี้?')) return;
                        await ctx.api(`${A}/elections/${e.id}`, {method: 'DELETE', body: {version: e.version}});
                        location.hash = '#elections';
                        return;
                    }
                    if (act === 'cand-del') { await ctx.api(`${A}/candidates/${b.dataset.id}`, {method: 'DELETE', body: {version: Number(b.dataset.version)}}); return detail(e.id); }
                },
                async submit(name, f) {
                    const v = fd(f);
                    if (name === 'election-new') {
                        const r = await ctx.api(`${A}/elections`, {method: 'POST', idempotencyKey: C.uid(), body: {title: v.title, opens_at: v.opens_at, closes_at: v.closes_at, eligible_grade_levels: levels(v.grades)}});
                        location.hash = '#elections/' + r.id;
                        return;
                    }
                    if (name === 'cand-new') {
                        // one lookup by exact student code (admin/principal)
                        let found = null;
                        try { found = await ctx.api(`${A}/students/lookup?code=${encodeURIComponent(String(v.code).trim())}`); } catch (e) { if (e.status !== 404) throw e; }
                        if (!found) { notice(root, 'error', 'ไม่พบรหัสนักเรียนนี้'); return; }
                        await ctx.api(`${A}/elections/${state.election.id}/candidates`, {method: 'POST', body: {number: Number(v.number), student_id: found.id, slogan: v.slogan || null}});
                        return detail(state.election.id);
                    }
                },
            });
            await show();
        },
    });
})();
