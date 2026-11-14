'use strict';
// assessment module (M08/M09): SaanWork pages "scores" (grid entry per course) and "courses" (subjects, courses, teachers).
// Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners on the page root).
(() => {
    const BASE = '/api/assessment';
    const esc = v => window.SaanCore.esc(v);
    const role = () => { try { return JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').role || ''; } catch { return ''; } };
    const isManager = () => role() === 'admin' || role() === 'principal';
    const call = (ctx, url, opts = {}) => window.SaanCore.api(BASE + url, {...opts, signal: ctx.signal});
    const termLabel = t => t ? `ปีการศึกษา ${t.year} ภาคเรียนที่ ${t.term}` : '';
    const fmt = c => { if (c == null) return ''; const s = (c / 100).toFixed(2); return s.replace(/\.00$/, '').replace(/(\.\d)0$/, '$1'); };
    const timeOf = iso => { const d = new Date(iso); return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString('th-TH-u-nu-latn', {hour: '2-digit', minute: '2-digit', second: '2-digit'}); };
    const STATUS = {open: 'เปิดรับคะแนน', closed: 'ปิดผลแล้ว'};
    const opts = (pairs, sel, blank) => (blank ? `<option value="">${esc(blank)}</option>` : '')
        + pairs.map(([v, l]) => `<option value="${esc(v)}"${String(v) === String(sel ?? '') ? ' selected' : ''}>${esc(l)}</option>`).join('');

    function problem(box, e) {
        if (!e || e.name === 'AbortError') return;
        const list = e.data?.errors ? Object.values(e.data.errors).flat() : [e.message || 'เกิดข้อผิดพลาด'];
        box.innerHTML = `<div class="as-notice as-error" role="alert"><ul>${list.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>`;
    }
    const ok = (box, text) => { box.innerHTML = `<div class="as-notice as-ok" role="status">${esc(text)}</div>`; };

    // ============================ scores ============================
    async function scoresList(root, ctx) {
        root.innerHTML = '<section class="as-page glass"><div class="as-msg" aria-live="polite"></div><div class="as-body"><p class="as-loading">กำลังโหลด…</p></div></section>';
        const box = root.querySelector('.as-page'), msg = box.querySelector('.as-msg'), body = box.querySelector('.as-body');
        let terms = [], history = null;
        const card = c => {
            const cap = c.students_count * c.policy.components.length;
            const pct = cap ? Math.min(100, Math.round(c.cells_entered * 100 / cap)) : 0;
            return `<li class="as-course"><a href="#scores/${esc(c.id)}"><strong>${esc(c.subject.code)} ${esc(c.subject.name)}</strong>
                <span>${esc(c.classroom.name)} · ${esc(termLabel(c.term))}</span>
                ${isManager() ? `<span>ครูผู้สอน: ${esc(c.teacher?.name || 'ยังไม่มอบหมาย')}</span>` : ''}
                <span class="as-badge ${c.status === 'open' ? 'on' : 'off'}">${esc(STATUS[c.status] || c.status)}</span>
                <span class="as-progress" aria-label="กรอกแล้ว ${pct}%"><i style="width:${pct}%"></i></span>
                <small>กรอกแล้ว ${esc(c.cells_entered)} / ${esc(cap)} ช่อง (${esc(c.students_count)} คน)</small></a></li>`;
        };
        const draw = (cur) => {
            const list = history || cur;
            body.innerHTML = `<h1>บันทึกคะแนน</h1><p class="as-lead">${esc(termLabel(list.term))}${history ? ' (ย้อนหลัง อ่านเพื่อดูเท่านั้น)' : ''}</p>
                ${isManager() ? `<label class="as-field"><span>ดูภาคเรียนอื่น</span><select data-act="term">${opts(terms.map(t => [t.id, termLabel(t) + (t.current ? ' (ปัจจุบัน)' : '')]), history ? history.term.id : (terms.find(t => t.current)?.id ?? ''))}</select></label>` : ''}
                ${list.data.length ? `<ul class="as-courses">${list.data.map(card).join('')}</ul>` : '<p class="as-empty">ยังไม่มีรายวิชาที่มอบหมายให้คุณในภาคเรียนนี้ ติดต่อฝ่ายวิชาการเพื่อมอบหมายรายวิชา</p>'}`;
        };
        try {
            const cur = await call(ctx, '/courses');
            if (isManager()) terms = (await call(ctx, '/terms')).data;
            if (!ctx.alive()) return;
            draw(cur);
            box.addEventListener('change', async ev => {
                const s = ev.target.closest('select[data-act="term"]');
                if (!s) return;
                try {
                    const t = terms.find(x => String(x.id) === s.value);
                    history = t && !t.current ? await call(ctx, '/history/courses?term_id=' + encodeURIComponent(t.id)) : null;
                    if (ctx.alive()) draw(cur);
                } catch (e) { problem(msg, e); }
            });
        } catch (e) { problem(msg, e); body.innerHTML = ''; }
    }

    async function scoresGrid(root, ctx, id) {
        root.innerHTML = '<section class="as-page glass"><div class="as-msg" aria-live="polite"></div><div class="as-body"><p class="as-loading">กำลังโหลด…</p></div></section>';
        const page = root.querySelector('.as-page'), msg = page.querySelector('.as-msg'), body = page.querySelector('.as-body');
        let data = null;
        let drafts = new Map();               // "sid|key" -> typed text, only while different from the saved value
        let saved = new Set();                // student ids whose row was just saved (highlight)
        let conflicts = new Set();
        const cellKey = (s, k) => s + '|' + k;
        const scoreMap = () => new Map(data.scores.map(r => [cellKey(r.student_id, r.component_key), r]));
        const totalMap = () => new Map(data.totals.map(t => [t.student_id, t]));
        const pastTerm = () => data.course.term.id !== data.current_term_id;
        // closed course: nobody edits. Past term: teachers never edit; admin/principal edit only with a reason (server enforces both)
        const closed = () => data.course.status !== 'open' || (pastTerm() && !isManager());
        const comps = () => data.course.policy.components;

        function view() {
            const c = data.course, sm = scoreMap(), tm = totalMap(), isClosed = closed();
            const head = comps().map(k => `<th scope="col">${esc(k.label)}<small>เต็ม ${esc(k.max)}</small></th>`).join('');
            const rows = data.students.map((s, i) => {
                const t = tm.get(s.id) || {};
                const cells = comps().map(k => {
                    const key = cellKey(s.id, k.key), row = sm.get(key), v = drafts.has(key) ? drafts.get(key) : fmt(row?.score_centi);
                    const cls = (drafts.has(key) ? ' is-dirty' : '') + (conflicts.has(key) ? ' is-conflict' : '');
                    return `<td data-label="${esc(k.label)} (เต็ม ${esc(k.max)})"><input class="as-cell${cls}" inputmode="decimal" autocomplete="off" data-sid="${esc(s.id)}" data-key="${esc(k.key)}" value="${esc(v)}" aria-label="${esc(s.name)} ${esc(k.label)}"${isClosed ? ' disabled' : ''}></td>`;
                }).join('');
                const total = t.status === 'complete' ? `${esc(t.total)} <b>เกรด ${esc(t.grade)}</b>${t.passed ? '' : ' <em>ไม่ผ่านเกณฑ์</em>'}` : '<span class="as-muted">กรอกไม่ครบ</span>';
                return `<tr class="${saved.has(s.id) ? 'is-saved' : ''}" data-row="${esc(s.id)}"><th scope="row" data-label="นักเรียน"><span class="as-no">${i + 1}</span> <span>${esc(s.name)}</span> ${s.in_class === false ? '<span class="as-badge off">ย้ายห้อง/จำหน่าย</span>' : ''} <small>${esc(s.code)}</small></th>${cells}<td data-label="รวม/เกรด" class="as-total">${total}</td><td data-label="ประวัติ"><button type="button" class="as-btn small" data-act="history" data-sid="${esc(s.id)}">ประวัติ</button></td></tr>`;
            }).join('');
            body.innerHTML = `<a class="as-back" href="#scores">กลับไปรายการรายวิชา</a>
                <h1>${esc(c.subject.code)} ${esc(c.subject.name)}</h1>
                <p class="as-lead">${esc(c.classroom.name)} · ${esc(termLabel(c.term))}${c.teacher ? ' · ครู' + esc(c.teacher.name) : ''}
                    <span class="as-badge ${c.status !== 'open' ? 'off' : 'on'}">${esc(STATUS[c.status] || c.status)}</span></p>
                <p class="as-policy">สัดส่วน ระหว่างภาค ${esc(c.policy.continuous_ratio)} : ปลายภาค ${esc(c.policy.exam_ratio)} · ปัดแบบ half-up · ผ่านเมื่อรวมไม่ต่ำกว่า ${esc(c.policy.pass_mark)}</p>
                ${c.status !== 'open' ? '<div class="as-notice">รายวิชานี้ปิดผลแล้ว ดูได้อย่างเดียว</div>' : ''}
                ${pastTerm() ? `<div class="as-notice">ภาคเรียนนี้ผ่านไปแล้ว${isManager() ? ' แก้คะแนนได้เฉพาะผู้ดูแล และต้องระบุเหตุผลที่แก้ทุกครั้ง' : ' ดูได้อย่างเดียว ต้องให้ผู้ดูแลเปิดแก้'}</div>` : ''}
                <div class="as-scroll"><table class="as-grid"><thead><tr><th scope="col">นักเรียน</th>${head}<th scope="col">รวม/เกรด</th><th scope="col">ประวัติ</th></tr></thead><tbody>${rows}</tbody></table></div>
                ${data.students.length ? '' : '<p class="as-empty">ห้องนี้ยังไม่มีนักเรียนที่กำลังศึกษา</p>'}
                ${isClosed ? '' : `<div class="as-savebar"><label class="as-field"><span>เหตุผลที่แก้${pastTerm() ? ' (จำเป็น)' : ' (ถ้ามี)'}</span><input name="reason" maxlength="200" autocomplete="off"></label>
                    <button type="button" class="as-btn primary" data-act="save"><span class="as-count">${drafts.size}</span> ช่องที่ยังไม่บันทึก · บันทึก</button></div>`}
                <div class="as-hist" aria-live="polite"></div>`;
        }

        // OPS-008: re-render from persisted rows but keep the scroll position and the focused cell
        function redraw() {
            const y = window.scrollY, a = document.activeElement;
            const focus = a?.classList?.contains('as-cell') ? [a.dataset.sid, a.dataset.key] : null;
            const reason = body.querySelector('[name=reason]')?.value || '';
            view();
            const r = body.querySelector('[name=reason]'); if (r) r.value = reason;
            window.scrollTo(0, y);
            if (focus) body.querySelector(`.as-cell[data-sid="${CSS.escape(focus[0])}"][data-key="${CSS.escape(focus[1])}"]`)?.focus({preventScroll: true});
        }
        const count = () => { const n = body.querySelector('.as-count'); if (n) n.textContent = String(drafts.size); };
        const maxOf = key => comps().find(k => k.key === key)?.max ?? 0;
        const valid = (text, max) => /^\d{1,4}(\.\d{1,2})?$/.test(text) && Number(text) <= max;

        async function load() {
            const fresh = await call(ctx, `/courses/${id}/scores`);
            if (!ctx.alive()) return false;
            data = fresh;
            return true;
        }

        async function save(btn) {
            const sm = scoreMap(), cells = [], bad = [];
            body.querySelectorAll('.as-cell').forEach(el => el.removeAttribute('aria-invalid'));
            for (const [key, text] of drafts) {
                const [sid, k] = key.split('|');
                const t = text.trim(), el = body.querySelector(`.as-cell[data-sid="${CSS.escape(sid)}"][data-key="${CSS.escape(k)}"]`);
                if (t !== '' && !valid(t, maxOf(k))) { el?.setAttribute('aria-invalid', 'true'); bad.push(el); continue; }
                cells.push({student_id: Number(sid), component_key: k, score: t === '' ? null : t, version: sm.get(key)?.version ?? 0});
            }
            if (bad.length) { msg.innerHTML = '<div class="as-notice as-error" role="alert">คะแนนต้องเป็นตัวเลข ไม่เกินสองตำแหน่ง และไม่เกินคะแนนเต็ม ช่องที่ผิดถูกทำเครื่องหมายไว้</div>'; bad[0]?.focus(); return; }
            if (!cells.length) { msg.innerHTML = '<div class="as-notice" role="status">ไม่มีช่องที่เปลี่ยน</div>'; return; }
            const reason = (body.querySelector('[name=reason]')?.value || '').trim();
            if (pastTerm() && !reason) { msg.innerHTML = '<div class="as-notice as-error" role="alert">ภาคเรียนที่ผ่านมา ต้องระบุเหตุผลที่แก้</div>'; body.querySelector('[name=reason]')?.focus(); return; }
            btn.disabled = true;
            // OPS-008: remember exactly what was sent; anything typed while the request runs must survive the redraw
            const sent = new Map(cells.map(c => { const k = cellKey(c.student_id, c.component_key); return [k, drafts.get(k)]; }));
            try {
                const res = await call(ctx, `/courses/${id}/scores`, {method: 'PUT', body: {cells, reason: reason || null}, idempotencyKey: window.SaanCore.uid()});
                if (!ctx.alive()) return;
                data.scores = res.scores; data.totals = res.totals;
                saved = new Set(cells.map(c => c.student_id)); conflicts = new Set();
                const fresh = scoreMap();
                for (const [k, text] of sent) {
                    // keep a cell whose text changed after we sent it, unless it already equals the stored value
                    if (drafts.get(k) === text || drafts.get(k)?.trim() === fmt(fresh.get(k)?.score_centi)) drafts.delete(k);
                }
                redraw();
                ok(msg, `บันทึกแล้ว ${res.saved} ช่อง เมื่อ ${timeOf(res.saved_at)} (แสดงค่าที่อ่านกลับจากฐานข้อมูล)`);
            } catch (e) {
                if (e?.name === 'AbortError') return;
                if (e.status === 409 && e.current?.conflicts) {
                    // keep what the teacher typed, show the stored value, let them decide
                    conflicts = new Set(e.current.conflicts.map(c => cellKey(c.student_id, c.component_key)));
                    await load();
                    for (const [key, text] of [...drafts]) if (!conflicts.has(key) && text.trim() === fmt(scoreMap().get(key)?.score_centi)) drafts.delete(key);
                    redraw();
                    const names = new Map(data.students.map(s => [s.id, s.name]));
                    msg.innerHTML = `<div class="as-notice as-conflict" role="alert"><p>มีผู้อื่นแก้คะแนนช่องเหล่านี้ไปก่อน ยังไม่ได้บันทึกอะไรเลย ค่าที่พิมพ์ไว้ยังอยู่ในช่อง ตรวจแล้วกดบันทึกอีกครั้งเพื่อทับค่าใหม่</p><ul>${e.current.conflicts.map(c => `<li>${esc(names.get(c.student_id) || c.student_id)} · ${esc(comps().find(k => k.key === c.component_key)?.label || c.component_key)} ค่าในระบบ: ${c.score_centi == null ? 'ว่าง' : esc(fmt(c.score_centi))}</li>`).join('')}</ul></div>`;
                } else if (e.status === 423) {
                    await load(); redraw(); problem(msg, e);
                } else problem(msg, e);
            } finally { btn.disabled = false; }
        }

        async function history(sid) {
            const box = body.querySelector('.as-hist');
            box.innerHTML = '<p class="as-loading">กำลังโหลดประวัติ…</p>';
            try {
                const res = await call(ctx, `/courses/${id}/revisions?student_id=${encodeURIComponent(sid)}`);
                if (!ctx.alive()) return;
                const name = data.students.find(s => String(s.id) === String(sid))?.name || '';
                const label = key => comps().find(k => k.key === key)?.label || key;
                box.innerHTML = `<section class="as-card"><h2>ประวัติการแก้คะแนน ${esc(name)}</h2>${res.data.length ? `<ul class="as-revs">${res.data.map(r => `<li><b>${esc(label(r.component_key))}</b> ${r.old_centi == null ? 'ว่าง' : esc(fmt(r.old_centi))} → ${r.new_centi == null ? 'ว่าง' : esc(fmt(r.new_centi))}
                    <small>${esc(r.created_at)} · ${esc(r.changed_by || '-')}${r.reason ? ' · ' + esc(r.reason) : ''}</small></li>`).join('')}</ul>` : '<p class="as-empty">ยังไม่มีประวัติ</p>'}</section>`;
                box.scrollIntoView({block: 'nearest'});
            } catch (e) { problem(box, e); }
        }

        try {
            if (!(await load())) return;
            view();
        } catch (e) { problem(msg, e); body.innerHTML = '<a class="as-back" href="#scores">กลับไปรายการรายวิชา</a>'; return; }

        page.addEventListener('input', ev => {
            const el = ev.target.closest('.as-cell');
            if (!el) return;
            const key = cellKey(el.dataset.sid, el.dataset.key), orig = fmt(scoreMap().get(key)?.score_centi);
            if (el.value.trim() === orig) { drafts.delete(key); el.classList.remove('is-dirty'); } else { drafts.set(key, el.value); el.classList.add('is-dirty'); }
            el.classList.remove('is-conflict'); conflicts.delete(key); el.removeAttribute('aria-invalid');
            count();
        });
        page.addEventListener('keydown', ev => {
            const el = ev.target.closest?.('.as-cell');
            if (!el || (ev.key !== 'Enter' && ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp')) return;
            ev.preventDefault();
            const same = [...body.querySelectorAll(`.as-cell[data-key="${CSS.escape(el.dataset.key)}"]`)];
            const next = same[same.indexOf(el) + (ev.key === 'ArrowUp' ? -1 : 1)];
            if (next) { next.focus(); next.select?.(); }
        });
        page.addEventListener('click', async ev => {
            const b = ev.target.closest('[data-act]');
            if (!b || b.disabled) return;
            ev.preventDefault();
            if (b.dataset.act === 'save') await save(b);
            else if (b.dataset.act === 'history') await history(b.dataset.sid);
        });
    }

    window.SaanWork.register('scores', {
        title: 'บันทึกคะแนน',
        async render(root, ctx) {
            const id = ctx.parts[0];
            if (id && /^\d+$/.test(id)) return scoresGrid(root, ctx, id);
            return scoresList(root, ctx);
        },
    });

    // ============================ courses (setup) ============================
    async function coursesPage(root, ctx) {
        root.innerHTML = '<section class="as-page glass"><div class="as-msg" aria-live="polite"></div><div class="as-body"><p class="as-loading">กำลังโหลด…</p></div></section>';
        const page = root.querySelector('.as-page'), msg = page.querySelector('.as-msg'), body = page.querySelector('.as-body');
        const st = {terms: [], look: null, subjects: [], term: null, courses: [], edit: null, policyEdit: null};
        const DEFAULT_POLICY = {components: [{key: 'continuous', label: 'คะแนนระหว่างภาค', max: 70, group: 'continuous'}, {key: 'exam', label: 'คะแนนปลายภาค', max: 30, group: 'exam'}], continuous_ratio: 70, exam_ratio: 30, pass_mark: 50};
        const GROUPS = [['continuous', 'ระหว่างภาค'], ['exam', 'ปลายภาค']];
        // grading policy editor: repeatable component rows + ratios + pass mark; values are validated by the server (GradePolicy::normalize)
        const compRow = c => `<div class="as-comp">
            <label class="as-field"><span>รหัส (a-z 0-9 _)</span><input data-p="key" maxlength="40" autocomplete="off" value="${esc(c.key)}"></label>
            <label class="as-field"><span>ชื่อองค์ประกอบ</span><input data-p="label" maxlength="80" autocomplete="off" value="${esc(c.label)}"></label>
            <label class="as-field"><span>คะแนนเต็ม</span><input data-p="max" inputmode="numeric" autocomplete="off" value="${esc(c.max)}"></label>
            <label class="as-field"><span>กลุ่ม</span><select data-p="group">${opts(GROUPS, c.group)}</select></label>
            <button type="button" class="as-btn small danger" data-act="comp-del">ลบแถว</button></div>`;
        const policyFields = (p, locked) => `<fieldset class="as-policy-edit"${locked ? ' disabled' : ''}><legend>เกณฑ์คะแนน</legend>
            <div class="as-comps">${p.components.map(compRow).join('')}</div>
            ${locked ? '' : '<div class="as-actions"><button type="button" class="as-btn small" data-act="comp-add">เพิ่มองค์ประกอบคะแนน</button></div>'}
            <label class="as-field"><span>สัดส่วนระหว่างภาค (%)</span><input data-p="continuous_ratio" inputmode="numeric" value="${esc(p.continuous_ratio)}"></label>
            <label class="as-field"><span>สัดส่วนปลายภาค (%)</span><input data-p="exam_ratio" inputmode="numeric" value="${esc(p.exam_ratio)}"></label>
            <label class="as-field"><span>เกณฑ์ผ่าน (คะแนนรวมขั้นต่ำ)</span><input data-p="pass_mark" inputmode="numeric" value="${esc(p.pass_mark)}"></label></fieldset>`;
        const num = v => { const s = String(v ?? '').trim(); return s === '' ? null : (/^\d+$/.test(s) ? Number(s) : s); };
        const readPolicy = f => {
            const val = (root, k) => root.querySelector(`[data-p="${k}"]`)?.value ?? '';
            return {components: [...f.querySelectorAll('.as-comp')].map(r => ({key: val(r, 'key').trim(), label: val(r, 'label').trim(), max: num(val(r, 'max')), group: val(r, 'group')})),
                continuous_ratio: num(val(f, 'continuous_ratio')), exam_ratio: num(val(f, 'exam_ratio')), pass_mark: num(val(f, 'pass_mark'))};
        };
        const policyText = p => p.components.map(k => `${k.label} ${k.max}`).join(' + ') + ` · ${p.continuous_ratio}:${p.exam_ratio} · ผ่าน ${p.pass_mark}`;
        const termOf = () => st.terms.find(t => String(t.id) === String(st.term)) || st.terms.find(t => t.current) || st.terms[0];
        const subjectOptions = sel => opts(st.subjects.filter(s => s.is_active).map(s => [s.id, `${s.code} ${s.name}`]), sel, 'เลือกรายวิชา');
        const teacherOptions = sel => opts(st.look.teachers.map(t => [t.id, t.name]), sel, 'ยังไม่มอบหมาย');

        async function load() {
            const [terms, look, subjects] = await Promise.all([call(ctx, '/terms'), call(ctx, '/lookups'), call(ctx, '/subjects')]);
            st.terms = terms.data; st.look = look; st.subjects = subjects.data;
            if (!st.term) st.term = (st.terms.find(t => t.current) || st.terms[0])?.id ?? null;
            await loadCourses();
        }
        async function loadCourses() {
            const t = termOf();
            if (!t) { st.courses = []; return; }
            const res = t.current ? await call(ctx, '/courses') : await call(ctx, '/history/courses?term_id=' + encodeURIComponent(t.id));
            st.courses = res.data;
        }
        async function refresh(text) {
            const y = window.scrollY;
            try { await load(); if (!ctx.alive()) return; draw(); window.scrollTo(0, y); if (text) ok(msg, text); } catch (e) { problem(msg, e); }
        }

        function draw() {
            const t = termOf(), e = st.edit;
            const subjectRows = st.subjects.map(s => `<li class="as-row"><span><b>${esc(s.code)}</b> ${esc(s.name)} <small>${esc(s.group_name || '')}${s.credit != null ? ' · ' + esc(s.credit) + ' หน่วยกิต' : ''}${s.is_active ? '' : ' · ปิดใช้งาน'}</small></span>
                <span class="as-actions"><button type="button" class="as-btn small" data-act="subject-edit" data-id="${esc(s.id)}">แก้ไข</button><button type="button" class="as-btn small danger" data-act="subject-del" data-id="${esc(s.id)}" data-version="${esc(s.version)}">ลบ</button></span></li>`).join('');
            const courseRows = st.courses.map(c => `<li class="as-row" data-course="${esc(c.id)}"><span><b>${esc(c.subject.code)}</b> ${esc(c.subject.name)} <small>${esc(c.classroom.name)} · ${esc(termLabel(c.term))} · ${esc(STATUS[c.status] || c.status)} · คะแนน ${esc(c.cells_entered)} ช่อง</small></span>
                <span class="as-actions"><select data-act="teacher" data-id="${esc(c.id)}" data-version="${esc(c.version)}" aria-label="ครูผู้สอน ${esc(c.subject.name)} ${esc(c.classroom.name)}">${teacherOptions(c.teacher?.id)}</select>
                <button type="button" class="as-btn small" data-act="policy-open" data-id="${esc(c.id)}">เกณฑ์คะแนน</button>
                <button type="button" class="as-btn small" data-act="status" data-id="${esc(c.id)}" data-version="${esc(c.version)}" data-status="${c.status === 'open' ? 'closed' : 'open'}">${c.status === 'open' ? 'ปิดผล' : 'เปิดผล'}</button>
                <button type="button" class="as-btn small danger" data-act="course-del" data-id="${esc(c.id)}" data-version="${esc(c.version)}">ลบ</button></span>
                <small class="as-policy-sum">${esc(policyText(c.policy))}</small>
                ${st.policyEdit === c.id ? `<form class="as-form as-policy-form" data-form="policy" data-id="${esc(c.id)}" data-version="${esc(c.version)}">
                    ${c.cells_entered > 0 ? '<p class="as-muted">รายวิชานี้มีคะแนนแล้ว แก้เกณฑ์ไม่ได้</p>' : ''}${policyFields(c.policy, c.cells_entered > 0)}
                    ${c.cells_entered > 0 ? '' : '<div class="as-actions"><button class="as-btn primary" type="submit">บันทึกเกณฑ์คะแนน</button></div>'}</form>` : ''}</li>`).join('');
            body.innerHTML = `<h1>รายวิชาและครูผู้สอน</h1>
                <label class="as-field"><span>ภาคเรียน</span><select data-act="term">${opts(st.terms.map(x => [x.id, termLabel(x) + (x.current ? ' (ปัจจุบัน)' : '')]), t?.id)}</select></label>
                <section class="as-card"><h2>${e ? 'แก้ไขรายวิชา (กลุ่มสาระ)' : 'เพิ่มรายวิชา'}</h2>
                    <form class="as-form" data-form="subject" data-id="${esc(e?.id || '')}" data-version="${esc(e?.version || '')}">
                    <label class="as-field"><span>รหัสวิชา</span><input name="code" required maxlength="20" value="${esc(e?.code || '')}"></label>
                    <label class="as-field"><span>ชื่อวิชา</span><input name="name" required maxlength="150" value="${esc(e?.name || '')}"></label>
                    <label class="as-field"><span>กลุ่มสาระ</span><input name="group_name" maxlength="100" value="${esc(e?.group_name || '')}"></label>
                    <label class="as-field"><span>หน่วยกิต</span><input name="credit" inputmode="decimal" value="${esc(e?.credit ?? '')}"></label>
                    <label class="as-field"><span>ชั่วโมงต่อปี</span><input name="hours_per_year" inputmode="numeric" value="${esc(e?.hours_per_year ?? '')}"></label>
                    <label class="as-field"><span>ชั้นที่เปิดสอน (คั่นด้วยจุลภาค)</span><input name="grade_levels" value="${esc((e?.grade_levels || []).join(', '))}"></label>
                    <label class="as-check"><input type="checkbox" name="is_active"${!e || e.is_active ? ' checked' : ''}> เปิดใช้งาน</label>
                    <div class="as-actions"><button class="as-btn primary" type="submit">${e ? 'บันทึกการแก้ไข' : 'เพิ่มรายวิชา'}</button>${e ? '<button type="button" class="as-btn" data-act="subject-cancel">ยกเลิก</button>' : ''}</div></form>
                    ${subjectRows ? `<ul class="as-list">${subjectRows}</ul>` : '<p class="as-empty">ยังไม่มีรายวิชา</p>'}</section>
                <section class="as-card"><h2>เปิดรายวิชาให้ห้อง (${esc(termLabel(t))})</h2>
                    <form class="as-form" data-form="course">
                    <label class="as-field"><span>รายวิชา</span><select name="subject_id" required>${subjectOptions('')}</select></label>
                    <label class="as-field"><span>ห้อง</span><select name="classroom_id" required>${opts(st.look.classrooms.map(k => [k.id, k.name]), '', 'เลือกห้อง')}</select></label>
                    <label class="as-field"><span>ครูผู้สอน</span><select name="teacher_id">${teacherOptions('')}</select></label>
                    ${policyFields(DEFAULT_POLICY, false)}
                    <div class="as-actions"><button class="as-btn primary" type="submit">เพิ่มรายวิชาให้ห้อง</button></div></form>
                    <form class="as-form" data-form="bulk">
                    <label class="as-field"><span>เปิดทั้งระดับชั้น: รายวิชา</span><select name="subject_id" required>${subjectOptions('')}</select></label>
                    <label class="as-field"><span>ระดับชั้น</span><select name="grade_level" required>${opts(st.look.grade_levels.map(g => [g, g]), '', 'เลือกระดับชั้น')}</select></label>
                    <label class="as-field"><span>ครูผู้สอน (ปรับรายห้องได้ภายหลัง)</span><select name="teacher_id">${teacherOptions('')}</select></label>
                    ${policyFields(DEFAULT_POLICY, false)}
                    <div class="as-actions"><button class="as-btn" type="submit">สร้างให้ทุกห้องของชั้น</button></div></form>
                    <form class="as-form" data-form="copy">
                    <label class="as-field"><span>คัดลอกรายวิชาจากภาคเรียน (ไม่คัดลอกคะแนน)</span><select name="from_term_id" required>${opts(st.terms.filter(x => x.id !== t?.id).map(x => [x.id, termLabel(x)]), '', 'เลือกภาคเรียนต้นทาง')}</select></label>
                    <div class="as-actions"><button class="as-btn" type="submit">คัดลอกมายัง ${esc(termLabel(t))}</button></div></form></section>
                <section class="as-card"><h2>รายวิชาที่เปิดสอน ${esc(termLabel(t))}</h2>${courseRows ? `<ul class="as-list">${courseRows}</ul>` : '<p class="as-empty">ยังไม่มีรายวิชาในภาคเรียนนี้</p>'}</section>`;
        }

        const n = v => { const s = String(v ?? '').trim(); return s === '' ? null : Number(s); };
        const s0 = v => { const s = String(v ?? '').trim(); return s === '' ? null : s; };
        const act = async fn => { try { await fn(); } catch (e) { if (e?.status === 409 && e.current) { problem(msg, e); await refresh(); } else problem(msg, e); } };

        try { await load(); if (!ctx.alive()) return; draw(); } catch (e) { problem(msg, e); body.innerHTML = ''; return; }

        page.addEventListener('submit', ev => {
            const f = ev.target.closest('form[data-form]');
            if (!f) return;
            ev.preventDefault();
            const d = Object.fromEntries(new FormData(f)), kind = f.dataset.form, t = termOf();
            act(async () => {
                if (kind === 'subject') {
                    const payload = {code: d.code, name: d.name, group_name: s0(d.group_name), credit: n(d.credit), hours_per_year: n(d.hours_per_year),
                        grade_levels: String(d.grade_levels || '').split(',').map(x => x.trim()).filter(Boolean), is_active: f.elements.is_active.checked};
                    if (f.dataset.id) await call(ctx, '/subjects/' + f.dataset.id, {method: 'PUT', body: {...payload, version: Number(f.dataset.version)}});
                    else await call(ctx, '/subjects', {method: 'POST', body: payload});
                    st.edit = null; await refresh('บันทึกรายวิชาแล้ว');
                } else if (kind === 'course') {
                    await call(ctx, '/courses', {method: 'POST', body: {term_id: t.id, subject_id: n(d.subject_id), classroom_id: n(d.classroom_id), teacher_id: n(d.teacher_id), policy: readPolicy(f)}});
                    await refresh('เพิ่มรายวิชาให้ห้องแล้ว');
                } else if (kind === 'bulk') {
                    const r = await call(ctx, '/courses/bulk', {method: 'POST', body: {term_id: t.id, subject_id: n(d.subject_id), grade_level: d.grade_level, teacher_id: n(d.teacher_id), policy: readPolicy(f)}});
                    await refresh(`สร้างใหม่ ${r.created} ห้อง ข้ามที่มีอยู่แล้ว ${r.skipped} ห้อง`);
                } else if (kind === 'policy') {
                    await call(ctx, '/courses/' + f.dataset.id, {method: 'PUT', body: {version: Number(f.dataset.version), policy: readPolicy(f)}});
                    st.policyEdit = null; await refresh('บันทึกเกณฑ์คะแนนแล้ว');
                } else if (kind === 'copy') {
                    const r = await call(ctx, '/courses/copy', {method: 'POST', body: {from_term_id: n(d.from_term_id), to_term_id: t.id}});
                    await refresh(`คัดลอก ${r.created} รายวิชา ข้ามที่มีอยู่แล้ว ${r.skipped} รายวิชา`);
                }
            });
        });
        page.addEventListener('change', ev => {
            const s = ev.target.closest('select[data-act]');
            if (!s) return;
            if (s.dataset.act === 'term') { st.term = s.value; refresh(); return; }
            if (s.dataset.act === 'teacher') act(async () => {
                await call(ctx, '/courses/' + s.dataset.id, {method: 'PUT', body: {version: Number(s.dataset.version), teacher_id: n(s.value)}});
                await refresh('มอบหมายครูแล้ว');
            });
        });
        page.addEventListener('click', ev => {
            const b = ev.target.closest('button[data-act]');
            if (!b || b.disabled) return;
            ev.preventDefault();
            const a = b.dataset.act;
            if (a === 'subject-edit') { st.edit = st.subjects.find(s => String(s.id) === b.dataset.id) || null; draw(); page.scrollIntoView(); return; }
            if (a === 'subject-cancel') { st.edit = null; draw(); return; }
            if (a === 'comp-add') { const box = b.closest('form').querySelector('.as-comps'); box.insertAdjacentHTML('beforeend', compRow({key: 'c' + (box.children.length + 1), label: '', max: '', group: 'continuous'})); box.lastElementChild.querySelector('input')?.focus(); return; }
            if (a === 'comp-del') { const row = b.closest('.as-comp'); if (row && row.parentElement.children.length > 1) row.remove(); return; }
            if (a === 'policy-open') { const cid = Number(b.dataset.id); st.policyEdit = st.policyEdit === cid ? null : cid; draw(); return; }
            act(async () => {
                if (a === 'subject-del') { if (!confirm('ลบรายวิชานี้?')) return; await call(ctx, '/subjects/' + b.dataset.id, {method: 'DELETE', body: {version: Number(b.dataset.version)}}); await refresh('ลบรายวิชาแล้ว'); }
                else if (a === 'course-del') { if (!confirm('ลบรายวิชานี้ออกจากห้อง?')) return; await call(ctx, '/courses/' + b.dataset.id, {method: 'DELETE', body: {version: Number(b.dataset.version)}}); await refresh('ลบแล้ว'); }
                else if (a === 'status') { await call(ctx, '/courses/' + b.dataset.id, {method: 'PUT', body: {version: Number(b.dataset.version), status: b.dataset.status}}); await refresh(b.dataset.status === 'closed' ? 'ปิดผลแล้ว' : 'เปิดผลแล้ว'); }
            });
        });
    }

    window.SaanWork.register('courses', {title: 'รายวิชาและครูผู้สอน', render: coursesPage});
})();
