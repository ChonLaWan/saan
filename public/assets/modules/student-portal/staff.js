'use strict';
// student-portal: SaanWork page "student-accounts" (status of student and guardian accounts of the homeroom, issue / reset / unlock,
// printable slip sheet, term announcement). Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners).
// Secrets exist only in the response of the action: they are kept in memory for the slip sheet and never written anywhere else.
(() => {
    const BASE = '/api/student-portal';
    const GP = '/api/guardian-portal';
    const esc = v => window.SaanCore.esc(v);
    // school-base is the full app URL (origin + sub-folder), so the slip address stays right on a sub-folder install.
    const appBase = () => (document.querySelector('meta[name="school-base"]')?.content || location.origin).replace(/\/+$/, '');
    const when = s => String(s || '').replace('T', ' ').slice(0, 16);
    const badge = (text, kind) => `<span class="sps-badge ${kind || ''}">${esc(text)}</span>`;

    function studentBadges(s) {
        if (!s.has_secret) return badge('ยังไม่มีรหัส', 'warn');
        return [s.locked ? badge('ถูกล็อก', 'err') : '', s.temporary ? badge('รหัสชั่วคราว', 'warn') : badge('ตั้งรหัสเองแล้ว', 'ok'), s.has_verified_email ? badge('มีอีเมลยืนยัน', 'ok') : ''].join(' ');
    }

    function guardianLine(s, g) {
        if (!g.may_view) return `<li class="sps-g"><b>${esc(g.name)}</b> <span class="sps-muted">ถูกจำกัดสิทธิ์ ไม่เปิดให้ดูข้อมูล</span></li>`;
        const state = !g.has_account ? badge('ยังไม่มีบัญชี', 'warn') : [g.active ? '' : badge('ปิดใช้งาน', 'err'), g.locked ? badge('ถูกล็อก', 'err') : '', g.temporary ? badge('รหัสชั่วคราว', 'warn') : '',
            g.has_verified_email ? badge('มีอีเมลยืนยัน', 'ok') : '', g.username ? `<span class="sps-muted">ชื่อผู้ใช้ ${esc(g.username)}</span>` : ''].join(' ');
        const unlock = g.has_account && g.locked ? ` <button type="button" class="sps-btn sps-small" data-act="unlock-g" data-guardian="${esc(g.guardian_id)}">ปลดล็อก</button>` : '';
        return `<li class="sps-g"><b>${esc(g.name)}</b> <span class="sps-muted">${esc(g.relationship || '')}</span> ${state}${unlock} `
            + `<button type="button" class="sps-btn sps-small" data-act="issue-g" data-student="${esc(s.id)}" data-guardian="${esc(g.guardian_id)}">${g.has_account ? 'รีเซ็ตรหัสผู้ปกครอง' : 'ออกรหัสผู้ปกครอง'}</button></li>`;
    }

    window.SaanWork.register('student-accounts', {
        title: 'รหัสเข้าระบบนักเรียน',
        async render(root, ctx) {
            const info = await ctx.api(BASE + '/context');
            if (!ctx.alive()) return;
            const st = {room: Number(ctx.parts[0]) || info.classrooms[0]?.id || 0, data: null, slips: null, terms: null, canManage: false};
            root.innerHTML = '<section class="sps-page glass"><h1>รหัสเข้าระบบนักเรียน</h1><div class="sps-msg" aria-live="polite"></div><div class="sps-body"></div></section>';
            const box = root.querySelector('.sps-page');
            const body = box.querySelector('.sps-body');
            const flash = (kind, text) => { box.querySelector('.sps-msg').innerHTML = text ? `<div class="sps-note ${kind}" role="${kind === 'ok' ? 'status' : 'alert'}">${esc(text)}</div>` : ''; };

            function slipSheet() {
                if (!st.slips) return '';
                const url = appBase() + '/student';
                const cards = st.slips.data.map(x => x.kind === 'guardian'
                    ? `<div class="sps-slip"><b>ผู้ปกครองของ ${esc(x.student_name)}</b><div>${esc(x.name)}</div><div>เข้าที่ ${esc(appBase() + '/guardian')}</div><div>ชื่อผู้ใช้ <code>${esc(x.code)}</code></div><div>รหัสผ่านชั่วคราว <code>${esc(x.secret)}</code></div><div class="sps-muted">ต้องตั้งรหัสผ่านใหม่เมื่อเข้าครั้งแรก</div></div>`
                    : `<div class="sps-slip"><b>${esc(x.name)}</b><div>เข้าที่ ${esc(url)}</div><div>รหัสนักเรียน <code>${esc(x.code)}</code></div><div>รหัสผ่านชั่วคราว <code>${esc(x.secret)}</code></div><div class="sps-muted">ต้องตั้งรหัสผ่านใหม่เมื่อเข้าครั้งแรก</div></div>`).join('');
                return `<div class="sps-sheet" role="region" aria-label="ใบรหัสเข้าระบบ"><h2>ใบรหัสเข้าระบบ ห้อง ${esc(st.slips.classroom.name)}</h2><div class="sps-slips">${cards}</div></div>`
                    + '<p class="sps-noprint"><button type="button" class="sps-btn sps-primary" data-act="print">พิมพ์ใบรหัส (1 ชุดต่อห้อง)</button> <button type="button" class="sps-btn" data-act="close-slips">ปิดใบรหัส</button></p>'
                    + '<p class="sps-muted sps-noprint">รหัสแสดงครั้งเดียวเท่านั้น ระบบเก็บเฉพาะค่าแฮช หากปิดแล้วต้องรีเซ็ตเพื่อออกรหัสใหม่</p>';
            }

            function termBlock() {
                if (!st.terms) return '';
                const rows = st.terms.map(t => {
                    const state = t.announced ? badge('ประกาศแล้ว ' + when(t.announce_at), 'ok') : (t.announce_at ? badge('ตั้งเวลาไว้ ' + when(t.announce_at), 'warn') : badge('ยังไม่ประกาศ', ''));
                    const ctl = st.canManage ? ` <button type="button" class="sps-btn sps-small" data-act="announce" data-mode="now" data-term="${esc(t.id)}">ประกาศทันที</button>`
                        + ` <input type="datetime-local" data-at="${esc(t.id)}" aria-label="ตั้งเวลาประกาศ"> <button type="button" class="sps-btn sps-small" data-act="announce" data-mode="schedule" data-term="${esc(t.id)}">ตั้งเวลา</button>`
                        + (t.announce_at ? ` <button type="button" class="sps-btn sps-small" data-act="announce" data-mode="clear" data-term="${esc(t.id)}">ยกเลิกประกาศ</button>` : '') : '';
                    return `<li>ปีการศึกษา ${esc(t.year)} ภาคเรียนที่ ${esc(t.term)}${t.current ? ' (ปัจจุบัน)' : ''} ${state}${ctl}</li>`;
                }).join('');
                return `<details class="sps-card" open><summary>ประกาศผลการเรียนให้นักเรียนและผู้ปกครอง</summary><p class="sps-muted">ก่อนกดประกาศ นักเรียนและผู้ปกครองจะไม่เห็นผลของภาคเรียนนั้นเลย</p><ul class="sps-terms">${rows}</ul></details>`;
            }

            function draw() {
                const rooms = info.classrooms;
                if (!rooms.length) { body.innerHTML = '<p class="sps-muted">ไม่พบห้องเรียนที่คุณดูแลได้</p>'; return; }
                const list = (st.data?.data || []).map(s => `<article class="sps-card"><div class="sps-row"><h2>${s.roll ? esc(s.roll) + '. ' : ''}${esc(s.name)} <span class="sps-muted">${esc(s.code)}</span></h2></div>`
                    + `<div>${studentBadges(s)}${s.last_login_at ? ` <span class="sps-muted">เข้าล่าสุด ${esc(when(s.last_login_at))}</span>` : ''}</div>`
                    + `<div class="sps-actions"><button type="button" class="sps-btn sps-primary" data-act="reset" data-student="${esc(s.id)}">${s.has_secret ? 'รีเซ็ตรหัส' : 'ออกรหัส'}</button>`
                    + (s.has_secret && s.locked ? ` <button type="button" class="sps-btn" data-act="unlock" data-student="${esc(s.id)}">ปลดล็อก</button>` : '') + '</div>'
                    + (s.guardians.length ? `<ul class="sps-gl">${s.guardians.map(g => guardianLine(s, g)).join('')}</ul>` : '<p class="sps-muted">ยังไม่มีผู้ปกครองในทะเบียน</p>') + '</article>').join('');
                body.innerHTML = slipSheet() + termBlock()
                    + `<div class="sps-filters"><label class="sps-field"><span>ห้องเรียน</span><select data-change="room">${rooms.map(r => `<option value="${esc(r.id)}"${r.id === st.room ? ' selected' : ''}>${esc(r.name)}</option>`).join('')}</select></label></div>`
                    + '<div class="sps-actions"><button type="button" class="sps-btn sps-primary" data-act="issue-class" data-mode="missing">ออกรหัสให้คนที่ยังไม่มีทั้งห้องและพิมพ์ใบรหัส</button>'
                    + ' <button type="button" class="sps-btn" data-act="issue-class" data-mode="all">รีเซ็ตรหัสทั้งห้อง</button></div>'
                    + `<p class="sps-muted">รหัสผ่านสั้นที่สุดที่นักเรียนตั้งเองได้: ตัวเลขล้วน 6 หลัก หรือรหัสผ่าน ${esc(info.password_min)} ตัวอักษร</p>` + (list || '<p class="sps-muted">ไม่มีนักเรียนในห้องนี้</p>');
            }

            async function refresh() {
                try {
                    if (st.room) st.data = await ctx.api(`${BASE}/classrooms/${st.room}/accounts`);
                    try { const t = await ctx.api(GP + '/terms'); st.terms = t.data; st.canManage = t.can_manage; } catch { st.terms = null; }
                } catch (e) { if (e.name === 'AbortError') return; flash('err', e.message); }
                if (ctx.alive()) draw();
            }

            box.addEventListener('click', async ev => {
                const b = ev.target.closest('[data-act]');
                if (!b || !box.contains(b) || b.disabled) return;
                ev.preventDefault();
                const act = b.dataset.act;
                if (act === 'close-slips') { st.slips = null; draw(); return; }
                if (act === 'print') {
                    document.body.classList.add('sps-printing');
                    window.addEventListener('afterprint', () => document.body.classList.remove('sps-printing'), {once: true});
                    window.print();
                    return;
                }
                if (act === 'reset' && !window.confirm('ออกรหัสชั่วคราวใหม่ให้นักเรียนคนนี้ใช่หรือไม่ (รหัสเดิมและการเข้าสู่ระบบที่ค้างอยู่ทั้งหมดจะใช้ไม่ได้)')) return;
                if (act === 'issue-class' && b.dataset.mode === 'all' && !window.confirm('รีเซ็ตรหัสของนักเรียนทั้งห้องใช่หรือไม่ (รหัสเดิมและการเข้าสู่ระบบที่ค้างอยู่ทั้งหมดจะใช้ไม่ได้)')) return;
                if (act === 'issue-g' && !window.confirm('ออกรหัสชั่วคราวให้ผู้ปกครองรายนี้ใช่หรือไม่ (ถ้ามีรหัสเดิม จะใช้ไม่ได้อีก)')) return;
                b.disabled = true;
                try {
                    flash('ok', '');
                    if (act === 'reset') st.slips = await ctx.api(`${BASE}/students/${b.dataset.student}/reset`, {method: 'POST', body: {}});
                    else if (act === 'issue-class') {
                        st.slips = await ctx.api(`${BASE}/classrooms/${st.room}/issue`, {method: 'POST', body: {mode: b.dataset.mode}});
                        if (!st.slips.data.length) { st.slips = null; flash('ok', 'ทุกคนในห้องมีรหัสแล้ว'); }
                    } else if (act === 'issue-g') st.slips = await ctx.api(`${BASE}/students/${b.dataset.student}/guardians/${b.dataset.guardian}/issue`, {method: 'POST', body: {}});
                    else if (act === 'unlock') { await ctx.api(`${BASE}/students/${b.dataset.student}/unlock`, {method: 'POST', body: {}}); flash('ok', 'ปลดล็อกแล้ว'); }
                    else if (act === 'unlock-g') { await ctx.api(`${BASE}/guardian-accounts/${b.dataset.guardian}/unlock`, {method: 'POST', body: {}}); flash('ok', 'ปลดล็อกแล้ว'); }
                    else if (act === 'announce') {
                        const body = {mode: b.dataset.mode};
                        if (body.mode === 'schedule') { body.at = box.querySelector(`[data-at="${b.dataset.term}"]`)?.value || ''; if (!body.at) { flash('err', 'เลือกวันและเวลาก่อน'); return; } }
                        const r = await ctx.api(`${GP}/terms/${b.dataset.term}/announce`, {method: 'PUT', body});
                        st.terms = r.data;
                        flash('ok', body.mode === 'clear' ? 'ยกเลิกการประกาศแล้ว' : 'บันทึกการประกาศแล้ว');
                    }
                    await refresh();
                } catch (e) {
                    if (e.name === 'AbortError') return;
                    flash('err', e.message);
                } finally { b.disabled = false; }
            });
            box.addEventListener('change', ev => {
                const t = ev.target.closest('[data-change="room"]');
                if (!t || !box.contains(t)) return;
                st.room = Number(t.value); st.slips = null; location.hash = '#student-accounts/' + st.room;
            });
            await refresh();
        },
    });
})();
