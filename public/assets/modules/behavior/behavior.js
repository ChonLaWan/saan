'use strict';
// behavior module (M05): SaanWork page "behavior" with sections record, class, student, report, rules.
// Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners on one box per render).
(() => {
    const BASE = '/api/behavior';
    const esc = v => window.SaanCore.esc(v);
    const role = () => { try { return JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').role || ''; } catch { return ''; } };
    const manager = () => role() === 'admin' || role() === 'principal';
    const meta = n => document.querySelector(`meta[name="${n}"]`)?.content || '';
    const href = (path) => meta('school-base').replace(/\/+$/, '') + path;
    const date = iso => window.SaanCore.date(iso, {day: 'numeric', month: 'short', year: 'numeric'});
    const sign = n => (n > 0 ? '+' + n : String(n));
    const CAT = {good: 'ความดี', improve: 'ควรปรับปรุง'};

    const opt = (pairs, selected) => pairs.map(([v, l]) => `<option value="${esc(v)}"${String(v) === String(selected ?? '') ? ' selected' : ''}>${esc(l)}</option>`).join('');
    const pts = n => `<b class="bhv-pts ${n > 0 ? 'good' : n < 0 ? 'improve' : ''}">${esc(sign(n))}</b>`;
    const termLabel = t => `ปี ${t.year} ภาค ${t.term}`;

    const SECTIONS = [['record', 'บันทึกคะแนน'], ['class', 'สรุปรายห้อง'], ['report', 'รายงานโรงเรียน', true], ['rules', 'กติกาและคะแนน', true]];

    function nav(active) {
        return '<nav class="bhv-nav" aria-label="เมนูพฤติกรรม">' + SECTIONS.filter(s => !s[2] || manager())
            .map(([k, l]) => `<a href="#behavior${k === 'record' ? '' : '/' + k}"${k === active ? ' aria-current="page"' : ''}>${esc(l)}</a>`).join('') + '</nav>';
    }

    function notice(box, kind, html) {
        const m = box.querySelector('.bhv-msg');
        if (m) m.innerHTML = html ? `<div class="bhv-notice bhv-${kind}" role="${kind === 'ok' ? 'status' : 'alert'}">${html}</div>` : '';
    }
    function problem(box, e) {
        if (!e || e.name === 'AbortError') return;
        if (e.status === 409) { notice(box, 'error', `<p>${esc(e.message)}</p><button type="button" class="bhv-btn" data-act="reload">โหลดข้อมูลล่าสุด</button>`); return; }
        const list = e.data?.errors ? Object.values(e.data.errors).flat() : [e.message || 'เกิดข้อผิดพลาด'];
        notice(box, 'error', `<ul>${list.map(x => `<li>${esc(x)}</li>`).join('')}</ul>`);
    }

    function mount(root, ctx, active, title, def) {
        root.innerHTML = `<section class="bhv-page glass"><h1>${esc(title)}</h1>${nav(active)}<div class="bhv-msg" aria-live="polite"></div><div class="bhv-body"><p class="bhv-loading">กำลังโหลด…</p></div></section>`;
        const box = root.querySelector('.bhv-page');
        const body = box.querySelector('.bhv-body');
        const api = (url, opts) => ctx.api(BASE + url, opts);
        const h = {box, body, api, ctx, st: {}, draw: () => { body.innerHTML = def.view(h.st); def.after?.(h); }};
        h.refresh = async () => { try { await def.load(h); if (ctx.alive()) h.draw(); } catch (e) { problem(box, e); } };
        box.addEventListener('click', async ev => {
            const b = ev.target.closest('[data-act]');
            if (!b || !box.contains(b) || b.disabled) return;
            if (b.dataset.act === 'reload') { ev.preventDefault(); notice(box, 'ok', ''); await h.refresh(); return; }
            if (!def.click) return;
            ev.preventDefault();
            b.disabled = true;
            try { await def.click(b.dataset.act, b, h); } catch (e) { problem(box, e); } finally { b.disabled = false; }
        });
        box.addEventListener('change', ev => { const t = ev.target.closest('[data-change]'); if (t && box.contains(t)) def.change?.(t.dataset.change, t, h); });
        box.addEventListener('submit', async ev => {
            const f = ev.target.closest('form[data-form]');
            if (!f || !box.contains(f)) return;
            ev.preventDefault();
            const btn = f.querySelector('button[type=submit]');
            if (btn) btn.disabled = true;
            try { await def.submit?.(f.dataset.form, f, h); } catch (e) { problem(box, e); } finally { if (btn) btn.disabled = false; }
        });
        return h.refresh();
    }

    async function context(ctx) { return ctx.api(BASE + '/context'); }

    // ---------------------------------------------------------------- record
    // guardian-visible defaults to on for a single student and off for bulk records until staff ticks it
    function syncGv(st, h) {
        const c = h.body.querySelector('input[name=guardian_visible]');
        if (c && st.gv === undefined) c.checked = st.picked.size === 1;
    }
    function recordPage(root, ctx) {
        let idem = null;
        return mount(root, ctx, 'record', 'บันทึกพฤติกรรมนักเรียน', {
            async load(h) {
                const st = h.st;
                if (!st.ctx) {
                    [st.ctx, st.rules] = await Promise.all([context(ctx), ctx.api(BASE + '/rules').then(r => r.data)]);
                    st.classroomId = st.ctx.classrooms[0]?.id ?? null;
                    st.picked = new Set();
                    st.date = st.ctx.today || window.SaanCore.today();
                    st.ruleId = '';
                }
                st.students = st.classroomId ? (await h.api(`/classrooms/${st.classroomId}/summary`)).students : [];
            },
            view(st) {
                if (!st.ctx.classrooms.length) return '<p class="bhv-empty">ยังไม่มีห้องเรียนที่คุณบันทึกได้ ติดต่อผู้ดูแลระบบเพื่อกำหนดครูประจำชั้นหรือคาบสอน</p>';
                const good = st.rules.filter(r => r.category === 'good'), bad = st.rules.filter(r => r.category === 'improve');
                const grp = (label, rs) => rs.length ? `<optgroup label="${esc(label)}">${rs.map(r => `<option value="${esc(r.id)}"${String(r.id) === String(st.ruleId) ? ' selected' : ''}>${esc(r.label)} (${esc(sign(r.points))})</option>`).join('')}</optgroup>` : '';
                const all = st.students.length && st.students.every(s => st.picked.has(s.id));
                return `<p class="bhv-term">${st.ctx.term ? `ภาคเรียนปัจจุบัน: ${esc(termLabel(st.ctx.term))}` : 'ยังไม่ได้ตั้งภาคเรียนปัจจุบัน'}</p><form class="bhv-form" data-form="record">
<label class="bhv-field"><span>ห้องเรียน</span><select name="classroom" data-change="classroom">${opt(st.ctx.classrooms.map(c => [c.id, c.name]), st.classroomId)}</select></label>
<label class="bhv-field"><span>วันที่</span><input type="date" name="occurred_on" value="${esc(st.date)}" max="${esc(st.ctx.today || window.SaanCore.today())}" required></label>
<div class="bhv-field"><span>นักเรียน (เลือกได้หลายคน)</span>
<label class="bhv-check bhv-all"><input type="checkbox" data-change="all"${all ? ' checked' : ''}><span>เลือกทั้งห้อง (${esc(st.students.length)} คน)</span></label>
<ul class="bhv-students">${st.students.map(s => `<li><label class="bhv-check"><input type="checkbox" name="s" value="${esc(s.id)}" data-change="pick"${st.picked.has(s.id) ? ' checked' : ''}><span class="bhv-name">${esc(s.name)}</span></label><span class="bhv-tot">${pts(s.net_points)}</span><a class="bhv-link" href="#behavior/student/${esc(s.id)}">ประวัติ</a></li>`).join('')}</ul></div>
<label class="bhv-field"><span>กติกา</span><select name="rule" data-change="rule"><option value="">ให้คะแนนเอง</option>${grp('ความดี', good)}${grp('ควรปรับปรุง', bad)}</select></label>
<label class="bhv-field bhv-custom"${st.ruleId ? ' hidden' : ''}><span>คะแนนเอง (บวกหรือลบ)</span><input type="number" name="points" min="-100" max="100" step="1" inputmode="numeric"></label>
<label class="bhv-field"><span>หมายเหตุ${st.ruleId ? '' : ' (จำเป็นเมื่อให้คะแนนเอง)'}</span><textarea name="note" rows="2" maxlength="1000"></textarea></label>
<label class="bhv-check"><input type="checkbox" name="guardian_visible" data-change="gv"${(st.gv === undefined ? st.picked.size === 1 : st.gv) ? ' checked' : ''}><span>ให้ผู้ปกครองเห็น (หมายเหตุจะถูกส่งให้ผู้ปกครองของทุกคนที่เลือก; บันทึกหลายคนพร้อมกันจะไม่เปิดให้เห็นโดยอัตโนมัติ)</span></label>
<div class="bhv-actions"><button type="submit" class="bhv-btn primary">บันทึกคะแนน</button><a class="bhv-btn" href="${esc(href(BASE + '/export.csv'))}">ส่งออก CSV</a></div></form>`;
            },
            change(what, el, h) {
                const st = h.st;
                if (what === 'classroom') { st.classroomId = Number(el.value); st.picked = new Set(); h.refresh(); }
                else if (what === 'pick') { const id = Number(el.value); el.checked ? st.picked.add(id) : st.picked.delete(id); h.body.querySelector('[data-change=all]').checked = st.students.every(s => st.picked.has(s.id)); syncGv(st, h); }
                else if (what === 'all') { st.picked = el.checked ? new Set(st.students.map(s => s.id)) : new Set(); h.body.querySelectorAll('input[name=s]').forEach(c => { c.checked = el.checked; }); syncGv(st, h); }
                else if (what === 'gv') st.gv = el.checked;
                else if (what === 'rule') { st.ruleId = el.value; h.body.querySelector('.bhv-custom').hidden = !!el.value; }
            },
            async submit(_, f, h) {
                const st = h.st, fd = new FormData(f);
                if (!st.picked.size) { notice(h.box, 'error', '<p>เลือกนักเรียนอย่างน้อย 1 คน</p>'); return; }
                const body = {student_ids: [...st.picked].sort((a, b) => a - b), occurred_on: fd.get('occurred_on'), note: String(fd.get('note') || '').trim() || null, guardian_visible: fd.get('guardian_visible') === 'on'};
                if (fd.get('rule')) body.rule_id = Number(fd.get('rule')); else body.points = Number(fd.get('points'));
                // the same payload keeps the same key so a retry after a lost response never records twice
                const sig = JSON.stringify(body);
                if (!idem || idem.sig !== sig) idem = {sig, key: window.SaanCore.uid()};
                const res = await h.api('/records', {method: 'POST', body, idempotencyKey: idem.key});
                idem = null;
                st.picked = new Set();
                st.gv = undefined;
                st.date = body.occurred_on;
                await h.refresh();
                const other = res.term && res.term.id !== st.ctx.term?.id;
                notice(h.box, 'ok', esc(`บันทึกแล้ว ${res.count} รายการ` + (other ? ` ในปี ${res.term.year} ภาค ${res.term.term}` : '')));
            },
        });
    }

    // ---------------------------------------------------------------- class summary
    function classPage(root, ctx) {
        return mount(root, ctx, 'class', 'สรุปพฤติกรรมรายห้อง', {
            async load(h) {
                const st = h.st;
                if (!st.ctx) {
                    st.ctx = await context(ctx);
                    st.classroomId = Number(ctx.parts[1]) || st.ctx.classrooms[0]?.id || null;
                    st.termId = st.ctx.term?.id ?? '';
                }
                st.sum = st.classroomId ? await h.api(`/classrooms/${st.classroomId}/summary?term_id=${encodeURIComponent(st.termId)}`) : null;
            },
            view(st) {
                if (!st.sum) return '<p class="bhv-empty">ยังไม่มีห้องเรียนที่คุณดูได้</p>';
                const csv = href(`${BASE}/export.csv?classroom_id=${st.classroomId}&term_id=${st.termId}`);
                return `<div class="bhv-filters"><label class="bhv-field"><span>ห้องเรียน</span><select data-change="classroom">${opt(st.ctx.classrooms.map(c => [c.id, c.name]), st.classroomId)}</select></label>
<label class="bhv-field"><span>ภาคเรียน</span><select data-change="term">${opt(st.ctx.terms.map(t => [t.id, termLabel(t)]), st.termId)}</select></label><a class="bhv-btn" href="${esc(csv)}">ส่งออก CSV</a></div>
<table class="bhv-table"><thead><tr><th>อันดับ</th><th>นักเรียน</th><th>ความดี</th><th>ควรปรับปรุง</th><th>สุทธิ</th><th>รายการ</th></tr></thead><tbody>${st.sum.students.map(s =>
    `<tr><td data-label="อันดับ">${esc(s.rank)}</td><td data-label="นักเรียน"><a class="bhv-link" href="#behavior/student/${esc(s.id)}">${esc(s.name)}</a></td><td data-label="ความดี">${pts(s.good_points)}</td><td data-label="ควรปรับปรุง">${pts(s.improve_points)}</td><td data-label="สุทธิ">${pts(s.net_points)}</td><td data-label="รายการ">${esc(s.records)}</td></tr>`).join('')}</tbody></table>`;
            },
            change(what, el, h) { h.st[what === 'term' ? 'termId' : 'classroomId'] = Number(el.value); h.refresh(); },
        });
    }

    // ---------------------------------------------------------------- student timeline
    function studentPage(root, ctx) {
        const id = Number(ctx.parts[1]);
        return mount(root, ctx, 'class', 'ประวัติพฤติกรรมนักเรียน', {
            async load(h) {
                const st = h.st;
                if (!st.ctx) { st.ctx = await context(ctx); st.termId = st.ctx.term?.id ?? ''; }
                st.tl = await h.api(`/students/${id}/timeline?term_id=${encodeURIComponent(st.termId)}`);
            },
            view(st) {
                const tl = st.tl, t = tl.totals;
                return `<p><a class="bhv-link" href="#behavior/class">กลับไปสรุปรายห้อง</a></p><h2>${esc(tl.student.name)} <small>${esc(tl.student.code)} ${esc(tl.student.classroom || '')}</small></h2>
<label class="bhv-field"><span>ภาคเรียน</span><select data-change="term">${opt(st.ctx.terms.map(x => [x.id, termLabel(x)]), st.termId)}</select></label>
<ul class="bhv-totals"><li><span>ความดี</span>${pts(t.good_points)}</li><li><span>ควรปรับปรุง</span>${pts(t.improve_points)}</li><li><span>สุทธิ</span>${pts(t.net_points)}</li><li><span>รายการ</span><b>${esc(t.records)}</b></li></ul>
${tl.records.length ? `<ol class="bhv-timeline">${tl.records.map(r => `<li class="${r.status === 'voided' ? 'is-voided' : ''}"><div class="bhv-rec"><span>${esc(date(r.occurred_on))}</span> ${pts(r.points)} <strong>${esc(r.label || 'ให้คะแนนเอง')}</strong> <span class="bhv-badge">${esc(CAT[r.category])}</span>${r.status === 'voided' ? ' <span class="bhv-badge off">ยกเลิกแล้ว</span>' : ''}</div>
${r.note ? `<p class="bhv-note">${esc(r.note)}</p>` : ''}<p class="bhv-by">บันทึกโดย ${esc(r.recorded_by_name || '-')}${r.guardian_visible ? '' : ' (ซ่อนจากผู้ปกครอง)'}${r.guardian_ack_at ? ' / ผู้ปกครองรับทราบแล้ว' : ''}</p>
${r.status === 'voided' ? `<p class="bhv-note">เหตุผลที่ยกเลิก: ${esc(r.voided_reason)}</p>` : !r.can_void ? '' : `<form class="bhv-void" data-form="void" data-id="${esc(r.id)}" data-version="${esc(r.version)}"><label class="bhv-field"><span>เหตุผลที่ยกเลิก</span><input name="reason" maxlength="300" required minlength="3"></label><button type="submit" class="bhv-btn danger">ยกเลิกรายการนี้</button></form>`}</li>`).join('')}</ol>` : '<p class="bhv-empty">ยังไม่มีบันทึกในภาคเรียนนี้</p>'}`;
            },
            change(what, el, h) { h.st.termId = Number(el.value); h.refresh(); },
            async submit(_, f, h) {
                const reason = String(new FormData(f).get('reason') || '').trim();
                await h.api(`/records/${f.dataset.id}/void`, {method: 'POST', body: {version: Number(f.dataset.version), reason}});
                await h.refresh();
                notice(h.box, 'ok', 'ยกเลิกรายการแล้ว (ยังเก็บประวัติไว้)');
            },
        });
    }

    // ---------------------------------------------------------------- school report
    function reportPage(root, ctx) {
        if (!manager()) { root.innerHTML = '<section class="bhv-page glass"><p class="bhv-empty">เฉพาะผู้อำนวยการและผู้ดูแลระบบ</p></section>'; return; }
        return mount(root, ctx, 'report', 'รายงานพฤติกรรมทั้งโรงเรียน', {
            async load(h) {
                const st = h.st;
                if (!st.ctx) { st.ctx = await context(ctx); st.termId = st.ctx.term?.id ?? ''; }
                st.rep = await h.api(`/report?term_id=${encodeURIComponent(st.termId)}`);
            },
            view(st) {
                const r = st.rep;
                return `<div class="bhv-filters"><label class="bhv-field"><span>ภาคเรียน</span><select data-change="term">${opt(st.ctx.terms.map(t => [t.id, termLabel(t)]), st.termId)}</select></label>
<a class="bhv-btn" href="${esc(href(`${BASE}/export.csv?term_id=${st.termId}`))}">ส่งออก CSV</a></div>
<ul class="bhv-totals"><li><span>รายการทั้งหมด</span><b>${esc(r.totals.records)}</b></li><li><span>นักเรียนที่มีบันทึก</span><b>${esc(r.totals.students)}</b></li><li><span>คะแนนสุทธิ</span>${pts(r.totals.points)}</li></ul>
<h2>ตามประเภท</h2><table class="bhv-table"><thead><tr><th>ประเภท</th><th>รายการ</th><th>นักเรียน</th><th>คะแนนรวม</th></tr></thead><tbody>${r.by_category.map(c => `<tr><td data-label="ประเภท">${esc(CAT[c.category])}</td><td data-label="รายการ">${esc(c.records)}</td><td data-label="นักเรียน">${esc(c.students)}</td><td data-label="คะแนนรวม">${pts(c.points)}</td></tr>`).join('')}</tbody></table>
<h2>ตามกติกา</h2><table class="bhv-table"><thead><tr><th>กติกา</th><th>รายการ</th><th>คะแนนรวม</th></tr></thead><tbody>${r.by_rule.map(c => `<tr><td data-label="กติกา">${esc(c.label)}</td><td data-label="รายการ">${esc(c.records)}</td><td data-label="คะแนนรวม">${pts(c.points)}</td></tr>`).join('') || '<tr><td colspan="3">ยังไม่มีข้อมูล</td></tr>'}</tbody></table>
<h2>ตามห้องเรียน</h2><table class="bhv-table"><thead><tr><th>ห้อง</th><th>รายการ</th><th>ความดี</th><th>ควรปรับปรุง</th><th>สุทธิ</th></tr></thead><tbody>${r.by_classroom.map(c => `<tr><td data-label="ห้อง">${c.classroom_id ? `<a class="bhv-link" href="#behavior/class/${esc(c.classroom_id)}">${esc(c.name)}</a>` : esc(c.name)}</td><td data-label="รายการ">${esc(c.records)}</td><td data-label="ความดี">${pts(c.good_points)}</td><td data-label="ควรปรับปรุง">${pts(c.improve_points)}</td><td data-label="สุทธิ">${pts(c.net_points)}</td></tr>`).join('') || '<tr><td colspan="5">ยังไม่มีข้อมูล</td></tr>'}</tbody></table>`;
            },
            change(what, el, h) { h.st.termId = Number(el.value); h.refresh(); },
        });
    }

    // ---------------------------------------------------------------- rules
    function rulesPage(root, ctx) {
        if (!manager()) { root.innerHTML = '<section class="bhv-page glass"><p class="bhv-empty">เฉพาะผู้อำนวยการและผู้ดูแลระบบ</p></section>'; return; }
        const catOpts = Object.entries(CAT);
        return mount(root, ctx, 'rules', 'กติกาและคะแนนพฤติกรรม', {
            async load(h) { h.st.rules = (await h.api('/rules?all=1')).data; },
            view(st) {
                return `<p class="bhv-lead">แก้คะแนนแล้วมีผลกับรายการที่บันทึกใหม่เท่านั้น รายการเดิมยังคงคะแนนที่บันทึกไว้ ความดีเป็นคะแนนบวก ควรปรับปรุงเป็นคะแนนลบ</p>
<ul class="bhv-rules">${st.rules.map(r => `<li><form class="bhv-rule" data-form="rule" data-id="${esc(r.id)}" data-version="${esc(r.version)}">
<label class="bhv-field"><span>ชื่อกติกา (${esc(r.code)})</span><input name="label" value="${esc(r.label)}" maxlength="200" required></label>
<label class="bhv-field"><span>หมวด</span><select name="category">${opt(catOpts, r.category)}</select></label>
<label class="bhv-field"><span>คะแนน</span><input type="number" name="points" value="${esc(r.points)}" min="-100" max="100" step="1" required></label>
<label class="bhv-check"><input type="checkbox" name="is_active"${r.is_active ? ' checked' : ''}><span>เปิดใช้งาน</span></label>
<button type="submit" class="bhv-btn">บันทึก</button></form></li>`).join('')}</ul>
<h2>เพิ่มกติกาใหม่</h2><form class="bhv-form" data-form="new"><label class="bhv-field"><span>รหัส (ไม่ซ้ำ)</span><input name="code" maxlength="40" required></label>
<label class="bhv-field"><span>ชื่อกติกา</span><input name="label" maxlength="200" required></label>
<label class="bhv-field"><span>หมวด</span><select name="category">${opt(catOpts, 'good')}</select></label>
<label class="bhv-field"><span>คะแนน</span><input type="number" name="points" min="-100" max="100" step="1" required></label>
<button type="submit" class="bhv-btn primary">เพิ่มกติกา</button></form>`;
            },
            async submit(which, f, h) {
                const fd = new FormData(f);
                if (which === 'new') {
                    await h.api('/rules', {method: 'POST', body: {code: String(fd.get('code')).trim(), label: String(fd.get('label')).trim(), category: fd.get('category'), points: Number(fd.get('points'))}});
                    await h.refresh(); notice(h.box, 'ok', 'เพิ่มกติกาแล้ว'); return;
                }
                await h.api(`/rules/${f.dataset.id}`, {method: 'PUT', body: {version: Number(f.dataset.version), label: String(fd.get('label')).trim(), category: fd.get('category'), points: Number(fd.get('points')), is_active: fd.get('is_active') === 'on'}});
                await h.refresh(); notice(h.box, 'ok', 'บันทึกกติกาแล้ว');
            },
        });
    }

    window.SaanWork.register('behavior', {
        title: 'พฤติกรรมนักเรียน',
        render: async (root, ctx) => {
            const part = ctx.parts[0] || 'record';
            const pages = {record: recordPage, class: classPage, student: studentPage, report: reportPage, rules: rulesPage};
            await (pages[part] || recordPage)(root, ctx);
        },
    });
})();
