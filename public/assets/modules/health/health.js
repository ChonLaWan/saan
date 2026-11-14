'use strict';
// health module: whole-class weight/height grid. Page key: #health[/<classroomId>[/<round>]]
(() => {
    const core = window.SaanCore;
    const esc = v => core.esc(v);
    const W = [8, 150], H = [70, 210];
    const THAI = '๐๑๒๓๔๕๖๗๘๙';

    // "๔๒,5" -> "42.5"; anything else is left for validation to flag
    function clean(raw) {
        return String(raw ?? '').trim().replace(/[๐-๙]/g, d => String(THAI.indexOf(d))).replace(',', '.').replace(/\s+/g, '');
    }
    function parse(raw, [lo, hi]) {
        const s = clean(raw);
        if (s === '') return {value: null, ok: true};
        if (!/^\d{1,3}(\.\d{1,2})?$/.test(s)) return {value: null, ok: false};
        const n = Number(s);
        return {value: n, ok: n >= lo && n <= hi};
    }
    const fmt = (v, d) => v === null || v === undefined ? '' : Number(v).toFixed(d).replace(/\.?0+$/, '');
    const bmi = (w, h) => w && h ? Math.round(w / ((h / 100) ** 2) * 10) / 10 : null;

    async function render(root, ctx) {
        const boot = await ctx.api('/api/health/classrooms');
        if (!ctx.alive()) return;
        const classes = boot.data || [];
        if (!classes.length) {
            root.innerHTML = '<section class="hl glass"><h1>น้ำหนัก ส่วนสูง</h1><p class="hl-sub">บัญชีนี้ยังไม่ได้เป็นครูประจำชั้นของห้องใด จึงยังไม่มีห้องให้กรอก</p></section>';
            return;
        }
        const classId = Number(ctx.parts[0]) || classes[0].id;
        const round = [1, 2, 3, 4].includes(Number(ctx.parts[1])) ? Number(ctx.parts[1]) : 1;
        if (!classes.some(c => c.id === classId)) throw new Error('ไม่มีสิทธิ์ใช้ข้อมูลห้องนี้');

        let data = await ctx.api(`/api/health/classrooms/${classId}/grid?round=${round}`);
        if (!ctx.alive()) return;

        const state = {rows: [], saving: false, key: core.uid(), dirty: false, conflicts: new Set()};
        state.rows = data.rows.map(r => ({...r, w: fmt(r.weight_kg, 2), h: fmt(r.height_cm, 1), origW: fmt(r.weight_kg, 2), origH: fmt(r.height_cm, 1)}));
        const base = (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');

        root.innerHTML = `<section class="hl glass">
            <div class="hl-head"><h1>น้ำหนัก ส่วนสูง</h1>
              <div class="hl-pick">
                <label class="hl-field"><span>ห้อง</span><select data-role="class">${classes.map(c => `<option value="${esc(c.id)}"${c.id === classId ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
                <label class="hl-field"><span>รอบ</span><select data-role="round">${[1, 2, 3, 4].map(n => `<option value="${n}"${n === round ? ' selected' : ''}>รอบที่ ${n}</option>`).join('')}</select></label>
                <label class="hl-field"><span>วันที่วัด</span><input type="date" data-role="date" max="${esc(core.today())}" value="${esc(data.measured_on || core.today())}"></label>
              </div></div>
            <p class="hl-sub" data-role="count" aria-live="polite"></p>
            <div class="hl-msg" data-role="msg" role="status" aria-live="polite"></div>
            <div class="hl-wrap"><table class="hl-table"><caption class="hl-sr">กรอกน้ำหนักและส่วนสูงของห้อง ${esc(data.classroom.name)} Enter หรือลูกศรลงเพื่อไปช่องถัดไป วางคอลัมน์จาก Excel ได้</caption>
              <thead><tr><th scope="col">นักเรียน</th><th scope="col">น้ำหนัก (กก.)</th><th scope="col">ส่วนสูง (ซม.)</th><th scope="col" class="hl-bmi-col">BMI</th></tr></thead>
              <tbody></tbody></table></div>
            <div class="hl-actions"><button type="button" class="hl-btn primary" data-role="save">บันทึกทั้งห้อง</button>
              <a class="hl-btn" data-role="csv" href="${esc(base)}/api/health/classrooms/${esc(classId)}/export.csv?round=${esc(round)}" download>ส่งออก CSV</a></div>
          </section>`;
        const $ = sel => root.querySelector(`[data-role="${sel}"]`);
        const tbody = root.querySelector('tbody');

        tbody.innerHTML = state.rows.map((r, i) => `<tr data-i="${i}">
            <th scope="row" class="hl-name"><span>${esc(r.roll_number ?? i + 1)}. ${esc(r.name)}</span>
              <small class="hl-prev">${r.previous ? (r.previous.term_id !== data.term_id ? 'ภาคเรียนก่อน ' : 'รอบก่อน ') + esc(fmt(r.previous.weight_kg, 2) || '-') + ' กก. / ' + esc(fmt(r.previous.height_cm, 1) || '-') + ' ซม.' : ''}</small>
              <small class="hl-bmi-inline" data-bmi-inline></small><small class="hl-row-msg" data-row-msg></small></th>
            <td><input class="hl-in" data-c="0" inputmode="decimal" autocomplete="off" aria-label="น้ำหนัก ${esc(r.name)}" value="${esc(r.w)}"></td>
            <td><input class="hl-in" data-c="1" inputmode="decimal" autocomplete="off" aria-label="ส่วนสูง ${esc(r.name)}" value="${esc(r.h)}"></td>
            <td class="hl-bmi-col" data-bmi></td></tr>`).join('');

        const input = (i, c) => tbody.querySelector(`tr[data-i="${i}"] input[data-c="${c}"]`);

        function refresh(i) {
            const r = state.rows[i], tr = tbody.querySelector(`tr[data-i="${i}"]`);
            const pw = parse(r.w, W), ph = parse(r.h, H);
            input(i, 0).setAttribute('aria-invalid', pw.ok ? 'false' : 'true');
            input(i, 1).setAttribute('aria-invalid', ph.ok ? 'false' : 'true');
            const b = pw.ok && ph.ok ? bmi(pw.value, ph.value) : null;
            const text = b === null ? '' : String(b);
            tr.querySelector('[data-bmi]').textContent = text;
            tr.querySelector('[data-bmi-inline]').textContent = text ? 'BMI ' + text : '';
            tr.classList.toggle('changed', r.w !== r.origW || r.h !== r.origH);
            tr.classList.toggle('bad', !pw.ok || !ph.ok);
            tr.classList.toggle('conflict', state.conflicts.has(r.student_id));
            const rm = tr.querySelector('[data-row-msg]');
            rm.textContent = !pw.ok ? `น้ำหนักต้องเป็นตัวเลข ${W[0]}-${W[1]} กก.` : !ph.ok ? `ส่วนสูงต้องเป็นตัวเลข ${H[0]}-${H[1]} ซม.` : '';
        }
        function summary() {
            let full = 0, part = 0, bad = 0, changed = 0;
            state.rows.forEach(r => {
                const pw = parse(r.w, W), ph = parse(r.h, H);
                if (!pw.ok || !ph.ok) bad++;
                const n = (pw.value !== null ? 1 : 0) + (ph.value !== null ? 1 : 0);
                if (n === 2) full++; else if (n === 1) part++;
                if (r.w !== r.origW || r.h !== r.origH) changed++;
            });
            state.dirty = changed > 0;
            $('count').textContent = `วัดครบ ${full} จาก ${state.rows.length} คน` + (part ? ` · ไม่ครบ ${part}` : '') + ` · ยังไม่ได้วัด ${state.rows.length - full - part}` + (changed ? ` · แก้ไขที่ยังไม่บันทึก ${changed}` : '');
            $('save').disabled = state.saving || bad > 0 || changed === 0;
            return {bad, changed};
        }
        function msg(kind, html) { const el = $('msg'); el.className = 'hl-msg' + (kind ? ' ' + kind : ''); el.innerHTML = html; }
        function touch() { state.key = core.uid(); }
        state.rows.forEach((_, i) => refresh(i));
        summary();

        // ---- input handling (delegated, no inline handlers) ----
        tbody.addEventListener('input', e => {
            const el = e.target.closest('input.hl-in'); if (!el) return;
            const i = Number(el.closest('tr').dataset.i), c = Number(el.dataset.c);
            state.rows[i][c === 0 ? 'w' : 'h'] = clean(el.value);
            state.conflicts.delete(state.rows[i].student_id);
            touch(); refresh(i); summary();
        });
        tbody.addEventListener('focusin', e => { if (e.target.matches('input.hl-in')) e.target.select(); });
        tbody.addEventListener('keydown', e => {
            const el = e.target.closest('input.hl-in'); if (!el || e.isComposing) return;
            const i = Number(el.closest('tr').dataset.i), c = Number(el.dataset.c), last = state.rows.length - 1;
            let to = null;
            if (e.key === 'Enter' || e.key === 'ArrowDown') to = i < last ? [i + 1, c] : (e.key === 'Enter' && c === 0 ? [0, 1] : null);
            else if (e.key === 'ArrowUp') to = i > 0 ? [i - 1, c] : null;
            else if (e.key === 'ArrowLeft' && c === 1 && el.selectionStart === 0 && el.selectionEnd === 0) to = [i, 0];
            else if (e.key === 'ArrowRight' && c === 0 && el.selectionStart === el.value.length) to = [i, 1];
            if (!to) { if (e.key === 'Enter') e.preventDefault(); return; }
            e.preventDefault();
            input(to[0], to[1])?.focus();
        });
        // paste a block copied from a spreadsheet: rows split by newline, columns by tab, starting at the focused cell
        tbody.addEventListener('paste', e => {
            const el = e.target.closest('input.hl-in'); if (!el) return;
            const text = (e.clipboardData || window.clipboardData)?.getData('text') || '';
            if (!/[\t\r\n]/.test(text.replace(/[\r\n]+$/, ''))) return; // single value: normal paste
            e.preventDefault();
            const grid = text.replace(/\r\n?/g, '\n').replace(/\n+$/, '').split('\n').map(l => l.split('\t'));
            let i = Number(el.closest('tr').dataset.i);
            const c0 = Number(el.dataset.c);
            let cut = 0;
            grid.forEach((cells, dr) => cells.forEach((cell, dc) => {
                const r = i + dr, c = c0 + dc;
                if (r >= state.rows.length) { if (dc === 0) cut++; return; }
                if (c > 1) return;
                state.rows[r][c === 0 ? 'w' : 'h'] = clean(cell);
                input(r, c).value = state.rows[r][c === 0 ? 'w' : 'h'];
                state.conflicts.delete(state.rows[r].student_id);
            }));
            for (let r = i; r < Math.min(state.rows.length, i + grid.length); r++) refresh(r);
            touch(); summary();
            msg('info', `วางข้อมูล ${grid.length - cut} แถว` + (cut ? ` (เกินจำนวนนักเรียน ${cut} แถวถูกตัดออก)` : '') + ' ตรวจค่าแล้วกดบันทึก');
        });

        // ---- navigation guards: unsaved changes ----
        const leave = () => !state.dirty || window.confirm('มีข้อมูลที่ยังไม่ได้บันทึก ต้องการทิ้งการแก้ไขหรือไม่');
        const onBefore = e => { if (state.dirty) { e.preventDefault(); e.returnValue = ''; } };
        // hash navigation (tabs, links, Back / Alt+Left) goes through the SaanWork veto hook; full page loads are covered by beforeunload
        const unguard = window.SaanWork.guard ? window.SaanWork.guard(() => (root.isConnected && state.dirty ? leave() : true)) : () => {};
        window.addEventListener('beforeunload', onBefore);
        ctx.signal.addEventListener('abort', () => { window.removeEventListener('beforeunload', onBefore); unguard(); }, {once: true});

        const go = () => { if (leave()) { state.dirty = false; window.SaanWork.go(`health/${$('class').value}/${$('round').value}`); } else { $('class').value = String(classId); $('round').value = String(round); } };
        $('class').addEventListener('change', go);
        $('round').addEventListener('change', go);
        $('date').addEventListener('change', touch);

        // ---- save: one request, sent once ----
        async function save() {
            if (state.saving) return;
            const {bad, changed} = summary();
            if (bad || !changed) return;
            const date = $('date').value;
            if (!date) { msg('error', 'เลือกวันที่วัดก่อนบันทึก'); return; }
            const rows = state.rows.filter(r => r.w !== r.origW || r.h !== r.origH).map(r => ({
                student_id: r.student_id, weight_kg: parse(r.w, W).value, height_cm: parse(r.h, H).value, version: r.version,
            }));
            // snapshot of exactly what is sent: edits typed while the request is in flight stay dirty
            const sent = new Map(rows.map(x => [x.student_id, {w: fmt(x.weight_kg, 2), h: fmt(x.height_cm, 1)}]));
            const sentKey = state.key;
            state.saving = true; $('save').disabled = true; msg('info', 'กำลังบันทึก…');
            try {
                const res = await ctx.api(`/api/health/classrooms/${classId}/grid`, {method: 'PUT', idempotencyKey: sentKey, body: {round, measured_on: date, rows}});
                if (!ctx.alive()) return;
                const byId = new Map(res.rows.map(x => [x.student_id, x]));
                state.rows.forEach((r, i) => {
                    const s = sent.get(r.student_id);
                    if (!s) return; // never sent: leave its original and its edits alone
                    const x = byId.get(r.student_id);
                    if (x) r.version = x.version;
                    // compare normalised text: '42.50' and '150.0' are saved as 42.5 and 150, so the typed text differs from fmt(sent)
                    const unchangedSince = fmt(parse(r.w, W).value, 2) === s.w && fmt(parse(r.h, H).value, 1) === s.h;
                    r.origW = s.w; r.origH = s.h;
                    if (x && unchangedSince) { r.w = r.origW = fmt(x.weight_kg, 2); r.h = r.origH = fmt(x.height_cm, 1); input(i, 0).value = r.w; input(i, 1).value = r.h; }
                    refresh(i);
                });
                state.conflicts.clear();
                if (state.key === sentKey) touch(); // keep the key if the user already edited (they rotated it)
                const warn = (res.warnings || []).map(w => `<li>${esc(w.message)}</li>`).join('');
                msg(warn ? 'warn' : 'ok', `บันทึกแล้ว ${res.inserted + res.updated} แถว` + (warn ? `<p>ตรวจค่าเหล่านี้อีกครั้ง (บันทึกไว้แล้ว แก้ไขได้):</p><ul>${warn}</ul>` : ''));
            } catch (err) {
                if (err.name === 'AbortError' || !ctx.alive()) return;
                if (err.status === 409 && Array.isArray(err.current)) conflict(err);
                else {
                    // keep the Idempotency-Key on network/5xx so a retry of an already committed save replays; rotate only on 4xx
                    if (err.status && err.status >= 400 && err.status < 500) touch();
                    msg('error', esc(err.message || 'บันทึกไม่สำเร็จ'));
                }
            } finally {
                state.saving = false;
                if (ctx.alive()) summary();
            }
        }
        function conflict(err) {
            state.conflicts = new Set(err.current.map(c => c.student_id));
            state.rows.forEach((_, i) => refresh(i));
            touch();
            const list = err.current.map(c => `<li>${esc(c.name)}: ในระบบตอนนี้ ${esc(fmt(c.weight_kg, 2) || '-')} กก. / ${esc(fmt(c.height_cm, 1) || '-')} ซม.</li>`).join('');
            msg('error', `<p>${esc(err.message)}</p><ul>${list}</ul><div class="hl-actions"><button type="button" class="hl-btn" data-role="keep">ใช้ค่าของฉันทับ</button><button type="button" class="hl-btn" data-role="reload">โหลดค่าล่าสุด (ทิ้งการแก้ไข)</button></div>`);
            $('keep').addEventListener('click', () => {
                err.current.forEach(c => { const r = state.rows.find(x => x.student_id === c.student_id); if (r) { r.version = c.version; r.origW = fmt(c.weight_kg, 2); r.origH = fmt(c.height_cm, 1); } });
                state.conflicts.clear(); state.rows.forEach((_, i) => refresh(i)); summary(); touch();
                msg('info', 'ปรับเวอร์ชันแล้ว ตรวจค่าของคุณแล้วกดบันทึกอีกครั้ง');
            });
            $('reload').addEventListener('click', () => { state.dirty = false; window.SaanWork.go(`health/${classId}/${round}`); });
        }
        $('save').addEventListener('click', save);
        root.querySelector('section.hl').addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); } }, {signal: ctx.signal});
    }

    window.SaanWork.register('health', {title: 'น้ำหนัก ส่วนสูง', render});
})();
