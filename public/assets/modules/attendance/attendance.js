'use strict';
// M04 attendance: phone-first class-day sheet (exceptions only) and reports. Registers #attendance and #attendance-report.
(() => {
    const esc = v => window.SaanCore.esc(v);
    const core = () => window.SaanCore;
    const ORDER = ['present', 'absent', 'late', 'leave', 'sick'];
    const LABEL = {present: 'มา', absent: 'ขาด', late: 'สาย', leave: 'ลา', sick: 'ป่วย'};
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const qs = o => new URLSearchParams(Object.entries(o).filter(([, v]) => v !== '' && v != null)).toString();
    const toast = text => { const t = document.querySelector('#toast'); if (t) { t.textContent = text; t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 2600); } };
    const when = s => s ? String(s).replace('T', ' ').slice(0, 16) : '';
    const chip = st => `<span class="att-chip att-${esc(st)}">${esc(LABEL[st] || st)}</span>`;
    const options = (rooms, sel) => rooms.map(r => `<option value="${esc(r.id)}"${Number(sel) === r.id ? ' selected' : ''}>${esc(r.name)}${r.homeroom ? ' (ประจำชั้น)' : ''}</option>`).join('');

    // ---- sheet page ---------------------------------------------------------------------------------------------
    async function renderSheet(root, ctx) {
        const meta = await ctx.api('/api/attendance/classes');
        if (!ctx.alive()) return;
        if (!meta.classrooms.length) { root.innerHTML = '<section class="att glass"><h1>เช็คชื่อ</h1><p>ยังไม่มีห้องเรียนที่คุณเช็คชื่อได้</p></section>'; return; }
        const st = {classroom: meta.default_classroom_id || meta.classrooms[0].id, date: meta.today, sheet: null, rows: new Map(), key: null, reason: '', force: false, confirm: true, busy: false, conflict: null};
        root.innerHTML = `<section class="att glass">
            <h1>เช็คชื่อ</h1>
            <form class="att-pick" data-form="pick">
                <label>ห้องเรียน<select name="classroom">${options(meta.classrooms, st.classroom)}</select></label>
                <label>วันที่<input type="date" name="date" value="${esc(st.date)}" max="${esc(meta.today)}"></label>
            </form>
            <div data-slot="body" aria-live="polite"></div>
        </section>`;
        const host = root.querySelector('.att'), body = root.querySelector('[data-slot=body]');
        let seq = 0; // response sequencing: only the newest classroom/date request may fill the sheet
        const status = s => st.rows.get(s.id)?.status ?? s.status;

        function load(sheet) {
            st.sheet = sheet;
            st.rows = new Map(sheet.students.map(s => [s.id, {status: s.status, note: s.note || '', version: s.version, origStatus: s.status, origNote: s.note || ''}]));
            st.key = null;
        }
        const changed = () => st.sheet.students.filter(s => { const r = st.rows.get(s.id); return r.status !== r.origStatus || r.note !== r.origNote; });

        function draw() {
            const sh = st.sheet;
            if (!sh) { body.innerHTML = '<p class="att-muted">กำลังโหลด...</p>'; return; }
            const counts = Object.fromEntries(ORDER.map(k => [k, 0]));
            sh.students.forEach(s => { counts[status(s)]++; });
            const day = sh.class_day;
            let notice = '';
            if (sh.is_future) notice += '<p class="att-warn" role="alert">วันที่เลือกยังมาไม่ถึง บันทึกไม่ได้</p>';
            if (sh.blocked) notice += `<p class="att-warn" role="alert">วันนี้เป็น${esc(sh.blocked.title)} ${sh.can_force ? 'ผู้ดูแลระบบบันทึกแบบบังคับได้' : 'ไม่เปิดให้เช็คชื่อ'}</p>`;
            if (day.confirmed) notice += `<p class="att-ok">ห้องนี้เช็คชื่อแล้วโดย ${esc(day.confirmed_by || '-')} ${esc(when(day.confirmed_at))}</p>`;
            if (sh.needs_reason) notice += `<p class="att-warn">ย้อนหลังเกิน ${esc(sh.edit_window_days)} วัน ต้องระบุเหตุผล</p>`;
            body.innerHTML = `${notice}
                <p class="att-summary">${ORDER.map(k => `${chip(k)} ${counts[k]}`).join(' ')}</p>
                <ul class="att-list">${sh.students.map(s => {
                    const r = st.rows.get(s.id), cur = r.status, dirty = cur !== r.origStatus || r.note !== r.origNote;
                    return `<li class="att-row${dirty ? ' is-dirty' : ''}">
                        <button type="button" class="att-tap" data-cycle="${esc(s.id)}"${st.busy ? ' disabled' : ''} aria-label="${esc(s.name)} สถานะ ${esc(LABEL[cur])} แตะเพื่อเปลี่ยน">
                            <span class="att-roll">${esc(s.roll ?? '')}</span><span class="att-name">${esc(s.name)}</span>${chip(cur)}
                        </button>
                        ${cur !== 'present' ? `<input class="att-note" data-note="${esc(s.id)}"${st.busy ? ' disabled' : ''} maxlength="200" placeholder="หมายเหตุ (ไม่บังคับ)" value="${esc(r.note)}" aria-label="หมายเหตุของ ${esc(s.name)}">` : ''}
                        ${s.recorded && s.changed_by ? `<small class="att-muted">บันทึกโดย ${esc(s.changed_by)} ${esc(when(s.changed_at))}</small>` : ''}
                    </li>`; }).join('')}</ul>
                <div class="att-foot">
                    ${sh.blocked && !sh.can_force ? '' : `<label class="att-check"><input type="checkbox" data-confirm ${st.confirm ? 'checked' : ''}> เช็คชื่อห้องนี้แล้ว (คนที่ไม่ได้ระบุถือว่ามาเรียน)</label>`}
                    ${sh.needs_reason && !(sh.blocked && !sh.can_force) ? `<label>เหตุผลการบันทึกย้อนหลัง<input data-reason maxlength="300" value="${esc(st.reason)}"></label>` : ''}
                    ${sh.blocked && sh.can_force ? `<label class="att-check"><input type="checkbox" data-force ${st.force ? 'checked' : ''}> บันทึกแบบบังคับ (วันหยุด)</label>` : ''}
                    <button type="button" class="att-save" data-save ${st.busy || sh.is_future || (sh.blocked && !(sh.can_force && st.force)) ? 'disabled' : ''}>บันทึก${changed().length ? ' (' + changed().length + ' รายการ)' : ''}</button>
                </div>`;
            // no taps, notes or class/date changes while a save is in flight: the answer replaces every row
            root.querySelectorAll('[data-form=pick] select, [data-form=pick] input').forEach(el => { el.disabled = st.busy; });
        }

        async function fetchSheet() {
            const mine = ++seq;
            body.innerHTML = '<p class="att-muted">กำลังโหลด...</p>';
            let sheet;
            try { sheet = await ctx.api('/api/attendance/sheet?' + qs({classroom_id: st.classroom, date: st.date})); }
            catch (e) { if (e.name === 'AbortError' || !ctx.alive() || mine !== seq) return; body.innerHTML = `<p class="att-warn" role="alert">${esc(e.message)}</p>`; return; }
            if (!ctx.alive() || mine !== seq) return; // a newer classroom/date was picked meanwhile
            load(sheet);
            draw();
        }

        function payload() {
            return {classroom_id: st.classroom, date: st.date, class_day_version: st.sheet.class_day.version, confirm: st.confirm, reason: st.reason, force: st.force,
                rows: changed().map(s => { const r = st.rows.get(s.id); return {student_id: s.id, status: r.status, version: r.version, note: r.status === 'present' ? '' : r.note}; })};
        }

        async function save() {
            if (st.busy) return;
            st.busy = true; draw();
            st.key ||= core().uid();
            const mineSeq = seq;
            try {
                const out = await ctx.api('/api/attendance/sheet', {method: 'POST', body: payload(), idempotencyKey: st.key});
                if (!ctx.alive()) return;
                if (mineSeq !== seq) { st.busy = false; return; } // another classroom/date is already loading: do not paint this one over it
                load(out.sheet); st.busy = false; st.reason = '';
                toast('บันทึกแล้ว'); draw();
            } catch (e) {
                if (!ctx.alive() || e.name === 'AbortError') return;
                st.busy = false;
                if (e.status === 409 && e.current) { st.key = null; st.conflict = e.current; drawConflict(e.message); }
                else { draw(); toast(e.message); }
            }
        }

        function drawConflict(message) {
            const list = st.conflict.conflicts || [];
            body.innerHTML = `<div class="att-conflict" role="alert"><h2>มีการแก้ไขซ้อนกัน</h2><p>${esc(message)}</p>
                ${list.map(c => `<fieldset class="att-pair"><legend>${esc(c.name)}</legend>
                    <label class="att-choice"><input type="radio" name="c${esc(c.student_id)}" value="mine" checked> ของฉัน ${chip(c.mine.status)}</label>
                    <label class="att-choice"><input type="radio" name="c${esc(c.student_id)}" value="theirs"> ล่าสุด ${chip(c.current.status)}
                        <small>${esc(c.current.changed_by || 'ไม่ทราบผู้บันทึก')} ${esc(when(c.current.changed_at))}</small></label>
                </fieldset>`).join('')}
                ${list.length ? '' : `<p>${esc(st.conflict.class_day?.confirmed_by || 'ผู้อื่น')} ยืนยันห้องนี้ไปแล้ว</p>`}
                <div class="att-foot"><button type="button" class="att-save" data-resolve>บันทึกตามที่เลือก</button> <button type="button" class="att-ghost" data-reload>ทิ้งการแก้ไขของฉันและโหลดใหม่</button></div></div>`;
        }

        async function resolve() {
            const keep = new Map(changed().map(s => [s.id, {...st.rows.get(s.id)}]));
            const picks = {};
            (st.conflict.conflicts || []).forEach(c => { picks[c.student_id] = body.querySelector(`input[name="c${c.student_id}"]:checked`)?.value || 'mine'; });
            const rs = ++seq;
            try { const sh = await ctx.api('/api/attendance/sheet?' + qs({classroom_id: st.classroom, date: st.date})); if (!ctx.alive() || rs !== seq) return; load(sh); }
            catch (e) { if (e.name !== 'AbortError') toast(e.message); return; }
            if (!ctx.alive()) return;
            for (const [id, mine] of keep) {
                if (picks[id] === 'theirs') continue;
                const row = st.rows.get(id);
                if (row) { row.status = mine.status; row.note = mine.note; }
            }
            st.conflict = null;
            await save();
        }

        host.addEventListener('submit', ev => ev.preventDefault());
        host.addEventListener('change', ev => {
            const t = ev.target;
            if (t.closest('[data-form=pick]')) {
                const f = root.querySelector('[data-form=pick]');
                st.classroom = Number(f.elements.classroom.value); st.date = f.elements.date.value || meta.today; st.sheet = null; st.reason = ''; st.force = false;
                fetchSheet();
            } else if (t.matches('[data-confirm]')) { st.confirm = t.checked; st.key = null; }
            else if (t.matches('[data-force]')) { st.force = t.checked; st.key = null; draw(); }
        });
        host.addEventListener('input', ev => {
            const t = ev.target;
            if (t.matches('[data-reason]')) { st.reason = t.value; st.key = null; }
            else if (t.matches('[data-note]')) { const r = st.rows.get(Number(t.dataset.note)); if (r) { r.note = t.value; st.key = null; } }
        });
        host.addEventListener('click', ev => {
            const t = ev.target.closest('button');
            if (!t || !st.sheet) return;
            if (t.dataset.cycle) {
                const r = st.rows.get(Number(t.dataset.cycle));
                if (r) { r.status = ORDER[(ORDER.indexOf(r.status) + 1) % ORDER.length]; if (r.status === 'present') r.note = ''; st.key = null; draw(); root.querySelector(`[data-cycle="${t.dataset.cycle}"]`)?.focus(); }
            } else if (t.matches('[data-save]')) save();
            else if (t.matches('[data-resolve]')) resolve();
            else if (t.matches('[data-reload]')) { st.conflict = null; fetchSheet(); }
        });
        await fetchSheet();
    }

    // ---- report page --------------------------------------------------------------------------------------------
    async function renderReport(root, ctx) {
        const meta = await ctx.api('/api/attendance/classes');
        if (!ctx.alive()) return;
        const today = meta.today;
        const st = {tab: ctx.parts[0] === 'student' ? 'student' : 'month', classroom: meta.default_classroom_id || meta.classrooms[0]?.id, month: today.slice(0, 7), date: today, student: Number(ctx.parts[1]) || null};
        const tabs = [['month', 'รายเดือน'], ['daily', 'สรุปรายวัน']];
        root.innerHTML = '<section class="att glass"><h1>รายงานการมาเรียน</h1><nav class="att-tabs" data-slot="tabs"></nav><div data-slot="ctl"></div><div data-slot="out" aria-live="polite"></div></section>';
        const host = root.querySelector('.att'), tabsEl = root.querySelector('[data-slot=tabs]'), ctl = root.querySelector('[data-slot=ctl]'), out = root.querySelector('[data-slot=out]');
        const csvLink = (params, label = 'ดาวน์โหลด CSV') => `<a class="att-ghost" href="${esc(base() + '/api/attendance/export?' + qs(params))}" download>${esc(label)}</a>`;

        let rseq = 0;
        function drawTabs() {
            tabsEl.innerHTML = tabs.map(([k, l]) => `<button type="button" data-tab="${k}" ${st.tab === k ? 'aria-current="true"' : ''}>${esc(l)}</button>`).join('');
        }
        function drawCtl() {
            if (st.tab === 'month') ctl.innerHTML = `<div class="att-pick"><label>ห้องเรียน<select data-f="classroom">${options(meta.classrooms, st.classroom)}</select></label><label>เดือน<input type="month" data-f="month" value="${esc(st.month)}"></label></div>`;
            else if (st.tab === 'daily') ctl.innerHTML = `<div class="att-pick"><label>วันที่<input type="date" data-f="date" value="${esc(st.date)}" max="${esc(today)}"></label></div>`;
            else ctl.innerHTML = '<p><a href="#attendance-report">กลับไปรายงานรายเดือน</a></p>';
        }
        async function show() {
            drawTabs(); drawCtl(); out.innerHTML = '<p class="att-muted">กำลังโหลด...</p>';
            const mine = ++rseq; // a slow older answer must not overwrite the newer selection
            try {
                if (st.tab === 'month') { const m = await ctx.api('/api/attendance/reports/month?' + qs({classroom_id: st.classroom, month: st.month})); if (mine === rseq) drawMonth(m); }
                else if (st.tab === 'daily') { const d = await ctx.api('/api/attendance/reports/daily?' + qs({date: st.date})); if (mine === rseq) drawDaily(d); }
                else { const h = await ctx.api('/api/attendance/reports/student/' + encodeURIComponent(st.student)); if (mine === rseq) drawStudent(h); }
            } catch (e) { if (e.name === 'AbortError' || !ctx.alive() || mine !== rseq) return; out.innerHTML = `<p class="att-warn" role="alert">${esc(e.message)}</p>`; }
        }
        function totals(t) { return ORDER.map(k => `<td data-label="${esc(LABEL[k])}">${esc(t[k] ?? 0)}</td>`).join(''); }
        function drawMonth(m) {
            if (!ctx.alive()) return;
            const days = m.days;
            const head = days.map(d => `<th class="${d.off ? 'att-off' : ''}" title="${esc(d.off || '')}">${esc(d.day)}</th>`).join('');
            const rows = m.students.map(s => `<tr><th scope="row" class="att-left"><a href="#attendance-report/student/${esc(s.id)}">${esc(s.name)}</a></th>${days.map(d => {
                const c = s.cells[d.date]; return `<td class="${d.off ? 'att-off' : ''}">${c && c !== 'present' ? chip(c) : (c ? '<span class="att-dot" aria-label="มา">.</span>' : '')}</td>`; }).join('')}${totals(s.totals)}</tr>`).join('');
            const cards = m.students.map(s => {
                const ex = days.filter(d => s.cells[d.date] && s.cells[d.date] !== 'present').map(d => `${esc(d.day)}: ${esc(LABEL[s.cells[d.date]])}`);
                return `<article class="att-card"><h3><a href="#attendance-report/student/${esc(s.id)}">${esc(s.name)}</a></h3>
                    <p>${ORDER.map(k => `${esc(LABEL[k])} ${esc(s.totals[k])}`).join(' | ')}</p><p class="att-muted">${ex.length ? ex.join(', ') : 'ไม่มีวันขาด ลา สาย ป่วย'}</p></article>`; }).join('');
            out.innerHTML = `<p>${csvLink({type: 'month', classroom_id: st.classroom, month: st.month})}</p>
                <div class="att-table-wrap att-desktop"><table class="att-grid"><caption>${esc(m.classroom.name)} เดือน ${esc(m.month)}</caption><thead><tr><th class="att-left">ชื่อ</th>${head}${ORDER.map(k => `<th>${esc(LABEL[k])}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div>
                <div class="att-mobile">${cards}</div>`;
        }
        function drawDaily(d) {
            if (!ctx.alive()) return;
            const t = d.total;
            const rows = d.classrooms.map(r => `<tr><th scope="row" data-label="ห้อง">${esc(r.name)}</th><td data-label="นักเรียน">${esc(r.students)}</td>${totals(r)}<td data-label="สถานะ">${r.confirmed ? 'เช็คแล้ว' : '<span class="att-warn-text">ยังไม่เช็ค</span>'}</td></tr>`).join('');
            out.innerHTML = `${d.blocked ? `<p class="att-warn">วันนี้เป็น${esc(d.blocked.title)}</p>` : ''}<p>${csvLink({type: 'daily', date: st.date})}</p>
                <table class="att-stack"><thead><tr><th>ห้อง</th><th>นักเรียน</th>${ORDER.map(k => `<th>${esc(LABEL[k])}</th>`).join('')}<th>สถานะ</th></tr></thead><tbody>${rows}
                <tr class="att-total"><th scope="row" data-label="ห้อง">รวม</th><td data-label="นักเรียน">${esc(t.students)}</td>${totals(t)}<td data-label="สถานะ">${esc(t.checked)}/${esc(t.classrooms)} ห้อง</td></tr></tbody></table>`;
        }
        function drawStudent(h) {
            if (!ctx.alive()) return;
            const rows = h.rows.map(r => `<li><strong>${esc(core().date(r.date))}</strong> ${chip(r.status)} ${r.note ? esc(r.note) : ''}
                <small class="att-muted">${esc(r.changed_by || '')} ${esc(when(r.changed_at))}</small>
                ${r.corrections.map(c => `<div class="att-corr">แก้ ${esc(LABEL[c.old] || c.old)} เป็น ${esc(LABEL[c.new] || c.new)} โดย ${esc(c.by || '-')} ${esc(when(c.at))}${c.reason ? ' เหตุผล: ' + esc(c.reason) : ''}</div>`).join('')}</li>`).join('');
            out.innerHTML = `<h2>${esc(h.student.name)} <small>${esc(h.student.code)}</small></h2>
                <p class="att-summary">${ORDER.map(k => `${chip(k)} ${esc(h.totals[k])}`).join(' ')}</p><p class="att-muted">${esc(core().date(h.from))} ถึง ${esc(core().date(h.to))}</p>
                <p>${csvLink({type: 'student', student_id: h.student.id})}</p>
                <ul class="att-history">${rows || '<li class="att-muted">ไม่มีบันทึกในช่วงนี้ (ไม่มีบันทึก = มาเรียน)</li>'}</ul>`;
        }
        host.addEventListener('click', ev => { const b = ev.target.closest('[data-tab]'); if (b) { st.tab = b.dataset.tab; show(); } });
        host.addEventListener('change', ev => {
            const f = ev.target.dataset.f;
            if (!f) return;
            st[f] = f === 'classroom' ? Number(ev.target.value) : ev.target.value;
            if (!st[f]) return;
            show();
        });
        await show();
    }

    window.SaanWork.register('attendance', {title: 'เช็คชื่อ', render: renderSheet});
    window.SaanWork.register('attendance-report', {title: 'รายงานการมาเรียน', render: renderReport});
})();


