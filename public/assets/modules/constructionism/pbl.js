'use strict';
// constructionism module (M17): SaanWork page "pbl". Routes: #pbl (list), #pbl/<id> (project), #pbl/rubric (criteria).
// Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners removed on navigation via ctx.signal).
(() => {
    const C = window.SaanCore;
    const esc = v => C.esc(v);
    const A = '/api/pbl';
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const opt = (pairs, sel) => pairs.map(([v, l]) => `<option value="${esc(v)}"${String(v) === String(sel ?? '') ? ' selected' : ''}>${esc(l)}</option>`).join('');
    const dshort = iso => C.date(iso, {day: 'numeric', month: 'short', year: 'numeric'});
    const kb = n => Math.max(1, Math.round(n / 1024)) + ' KB';

    window.SaanWork.register('pbl', {
        title: 'โครงงาน Constructionism',
        async render(root, ctx) {
            const st = {opts: null, project: null, detail: null, list: [], term: '', editLog: null, editArt: null};
            root.innerHTML = '<section class="pbl-page glass"><h1>โครงงาน Constructionism</h1><div class="pbl-msg" role="status"></div><div class="pbl-body">กำลังโหลด...</div></section>';
            const body = root.querySelector('.pbl-body');
            const msg = (kind, text) => { root.querySelector('.pbl-msg').innerHTML = text ? `<div class="pbl-note pbl-${esc(kind)}" role="${kind === 'error' ? 'alert' : 'status'}">${esc(text)}</div>` : ''; };
            const on = (type, fn) => root.addEventListener(type, fn, {signal: ctx.signal});
            const isRubric = ctx.parts[0] === 'rubric';
            const pid = /^\d+$/.test(ctx.parts[0] || '') ? Number(ctx.parts[0]) : null;

            async function fail(e) {
                if (!e || e.name === 'AbortError') return;
                if (e.status === 409) { msg('error', e.message + ' (โหลดข้อมูลล่าสุดแล้ว)'); await start().catch(() => {}); return; }
                msg('error', e.message || 'เกิดข้อผิดพลาด');
            }

            async function start() {
                st.opts = await ctx.api(A + '/options');
                if (!ctx.alive()) return;
                if (isRubric) return drawRubric();
                if (pid) { st.detail = await ctx.api(A + '/projects/' + pid); if (!ctx.alive()) return; return drawProject(); }
                st.list = (await ctx.api(A + '/projects' + (st.term ? '?term_id=' + encodeURIComponent(st.term) : ''))).data;
                if (!ctx.alive()) return;
                drawList();
            }

            // ---------------------------------------------------------------- list
            function drawList() {
                const o = st.opts;
                const me = o.my_teacher_id;
                body.innerHTML = `
                <p class="pbl-lead">สำหรับโรงเรียนที่ใช้โครงการ Constructionism ครูเป็นผู้บันทึกข้อมูลโครงงานของนักเรียน${o.term ? ' ปีการศึกษา ' + esc(o.term.year) + ' ภาคเรียนที่ ' + esc(o.term.term) : ''}</p>
                ${o.rubric.can_edit ? '<p><a class="pbl-btn" href="#pbl/rubric">แก้เกณฑ์รูบริกของโรงเรียน</a></p>' : ''}
                <div class="pbl-box"><h2>สร้างโครงงานใหม่</h2>
                    <form class="pbl-form" data-role="create" novalidate>
                        <label class="pbl-field">ชื่อโครงงาน<input name="title" maxlength="160" required></label>
                        <label class="pbl-field">ห้องเรียน<select name="classroom_id" required>${opt([['', 'เลือกห้อง']].concat(o.classrooms.map(c => [c.id, c.name])), '')}</select></label>
                        <label class="pbl-field">ครูที่ปรึกษา<select name="advisor_teacher_id">${opt([['', 'ไม่ระบุ']].concat(o.teachers.map(t => [t.id, t.name])), me)}</select></label>
                        <label class="pbl-field pbl-wide">คำถามนำ (Driving question)<textarea name="driving_question" rows="2" maxlength="1500"></textarea></label>
                        <div class="pbl-actions pbl-wide"><button class="pbl-btn pbl-primary" type="submit">สร้างโครงงาน</button></div>
                    </form></div>
                <div class="pbl-box"><label class="pbl-field">ภาคเรียน<select data-role="term">${opt([['', 'ภาคเรียนปัจจุบัน']].concat((o.terms || []).map(t => [t.id, 'ปีการศึกษา ' + t.year + ' ภาคเรียนที่ ' + t.term])), st.term)}</select></label></div>
                <div class="pbl-box"><h2>โครงงาน (${st.list.length})</h2>
                ${st.list.length ? '<ul class="pbl-list">' + st.list.map(p => `<li class="pbl-item"><a href="#pbl/${esc(p.id)}"><strong>${esc(p.title)}</strong></a>
                    <div class="pbl-meta">ห้อง ${esc(p.classroom_name)} · ${esc(p.status_label)} · สมาชิก ${esc(p.members)} คน · บันทึก ${esc(p.logs)} · ชิ้นงาน ${esc(p.artifacts)}${p.advisor_name ? ' · ที่ปรึกษา ' + esc(p.advisor_name) : ''}</div></li>`).join('') + '</ul>' : '<p class="pbl-empty">ยังไม่มีโครงงาน</p>'}</div>`;
            }

            // ---------------------------------------------------------------- project
            function drawProject() {
                const d = st.detail, p = d.project, o = st.opts, w = p.can_write;
                const crit = d.rubric.criteria;
                const dis = w ? '' : ' disabled';
                body.innerHTML = `
                <p><a href="#pbl">&larr; โครงงานทั้งหมด</a> · <a class="pbl-btn" href="${esc(base())}/pbl/projects/${esc(p.id)}/print" target="_blank" rel="noopener">พิมพ์ใบสรุปโครงงาน</a></p>
                ${w ? '' : '<div class="pbl-note">โครงงานของภาคเรียนที่ผ่านมา ดูได้อย่างเดียว</div>'}
                <div class="pbl-box"><h2>ข้อมูลโครงงาน</h2>
                    <form class="pbl-form" data-role="project" novalidate>
                        <label class="pbl-field">ชื่อโครงงาน<input name="title" maxlength="160" required value="${esc(p.title)}"${dis}></label>
                        <label class="pbl-field">สถานะ<select name="status"${dis}>${opt(Object.entries(o.statuses), p.status)}</select></label>
                        <label class="pbl-field">ครูที่ปรึกษา<select name="advisor_teacher_id"${dis}>${opt([['', 'ไม่ระบุ']].concat(o.teachers.map(t => [t.id, t.name])), p.advisor_teacher_id)}</select></label>
                        <div class="pbl-field">ห้องเรียน<strong>${esc(p.classroom_name)}</strong></div>
                        <label class="pbl-field pbl-wide">คำถามนำ<textarea name="driving_question" rows="2" maxlength="1500"${dis}>${esc(p.driving_question || '')}</textarea></label>
                        ${w ? `<div class="pbl-actions pbl-wide"><button class="pbl-btn pbl-primary" type="submit">บันทึก</button>${p.can_delete ? '<button class="pbl-btn pbl-danger" type="button" data-act="del-project">ลบโครงงาน</button>' : ''}</div>` : ''}
                    </form></div>

                <div class="pbl-box"><h2>สมาชิกทีม (${d.members.length})</h2>
                    ${d.members.length ? '<ul class="pbl-list">' + d.members.map(m => `<li class="pbl-item"><div><strong>${esc(m.name)}</strong> <span class="pbl-meta">${esc(m.code)}</span></div>
                        <div class="pbl-actions"><label class="pbl-field pbl-inline">บทบาท<input data-role="member-role" data-id="${esc(m.id)}" data-version="${esc(m.version)}" maxlength="80" value="${esc(m.role || '')}"${dis}></label>
                        ${w ? `<button class="pbl-btn" type="button" data-act="save-member" data-id="${esc(m.id)}">บันทึกบทบาท</button><button class="pbl-btn pbl-danger" type="button" data-act="del-member" data-id="${esc(m.id)}" data-version="${esc(m.version)}">นำออก</button>` : ''}</div></li>`).join('') + '</ul>' : '<p class="pbl-empty">ยังไม่มีสมาชิก</p>'}
                    ${w ? `<form class="pbl-form" data-role="member-add" novalidate><label class="pbl-field">เพิ่มนักเรียน<select name="student_id">${opt([['', d.candidates.length ? 'เลือกนักเรียน' : 'ไม่มีนักเรียนที่เพิ่มได้']].concat(d.candidates.map(s => [s.id, s.code + ' ' + s.name])), '')}</select></label>
                        <label class="pbl-field">บทบาท<input name="role" maxlength="80"></label><div class="pbl-actions pbl-wide"><button class="pbl-btn" type="submit">เพิ่มสมาชิก</button></div></form>` : ''}</div>

                <div class="pbl-box"><h2>สมุดบันทึกความก้าวหน้า (${d.logs.length})</h2>
                    ${w ? `<form class="pbl-form" data-role="log-add" novalidate><label class="pbl-field">วันที่<input type="date" name="date" value="${esc(st.editLog?.date || C.today())}" required></label>
                        <label class="pbl-field">ผู้บันทึก<select name="by_teacher">${opt([['1', 'ครูสังเกตและบันทึก'], ['0', 'บันทึกจากคำบอกเล่าของนักเรียน']], st.editLog ? (st.editLog.by_teacher ? '1' : '0') : '1')}</select></label>
                        <label class="pbl-field pbl-wide">บันทึก<textarea name="entry" rows="3" maxlength="4000" required>${esc(st.editLog?.entry || '')}</textarea></label>
                        <div class="pbl-actions pbl-wide"><button class="pbl-btn" type="submit">${st.editLog ? 'บันทึกการแก้ไข' : 'เพิ่มบันทึก'}</button>${st.editLog ? '<button class="pbl-btn" type="button" data-act="cancel-log">ยกเลิกการแก้ไข</button>' : ''}</div></form>` : ''}
                    ${d.logs.length ? '<ul class="pbl-list">' + d.logs.slice().reverse().map(l => `<li class="pbl-item"><div><strong>${esc(dshort(l.date))}</strong> <span class="pbl-meta">${l.by_teacher ? 'ครูสังเกต' : 'จากคำบอกเล่าของนักเรียน'} · ${esc(l.by_name)}</span><p>${esc(l.entry).replace(/\n/g, '<br>')}</p></div>
                        ${w ? `<div class="pbl-actions"><button class="pbl-btn" type="button" data-act="edit-log" data-id="${esc(l.id)}">แก้ไข</button><button class="pbl-btn pbl-danger" type="button" data-act="del-log" data-id="${esc(l.id)}" data-version="${esc(l.version)}">ลบ</button></div>` : ''}</li>`).join('') + '</ul>' : '<p class="pbl-empty">ยังไม่มีบันทึก</p>'}</div>

                <div class="pbl-box"><h2>ชิ้นงาน (${d.artifacts.length})</h2>
                    ${d.artifacts.length ? '<ul class="pbl-list">' + d.artifacts.map(a => `<li class="pbl-item"><div><strong>${esc(a.title)}</strong> <span class="pbl-meta">${esc(a.kind_label)}</span><br>
                        ${a.has_file ? `<a href="${esc(base())}/api/pbl/artifacts/${esc(a.id)}/file">${esc(a.original_name)} (${esc(kb(a.size))})</a>` : `<a href="${esc(a.url)}" target="_blank" rel="noopener noreferrer" class="pbl-url">${esc(a.url)}</a>`}</div>
                        ${w ? `<div class="pbl-actions"><button class="pbl-btn" type="button" data-act="edit-artifact" data-id="${esc(a.id)}">แก้ไข</button><button class="pbl-btn pbl-danger" type="button" data-act="del-artifact" data-id="${esc(a.id)}" data-version="${esc(a.version)}">ลบ</button></div>` : ''}</li>`).join('') + '</ul>' : '<p class="pbl-empty">ยังไม่มีชิ้นงาน</p>'}
                    ${w ? artifactForm(st.editArt, o) : ''}</div>

                <div class="pbl-box"><h2>การสะท้อนคิดและรูบริก</h2>
                    ${d.reflections.map(r => reflectionForm(r, crit, w)).join('') || '<p class="pbl-empty">ยังไม่มีการสะท้อนคิด</p>'}
                    ${w && d.members.some(m => !d.reflections.some(r => r.student_id === m.student_id)) ? '<h3>เพิ่มการสะท้อนคิด</h3>' + reflectionForm({id: 0, student_id: '', text: '', rubric: {}, version: 0}, crit, w, d.members.filter(m => !d.reflections.some(r => r.student_id === m.student_id))) : ''}</div>`;
            }

            function artifactForm(a, o) {
                const urlEdit = a && !a.has_file;
                return `<form class="pbl-form" data-role="artifact-add" novalidate><label class="pbl-field">ชื่อชิ้นงาน<input name="title" maxlength="160" required value="${esc(a?.title || '')}"></label>
                    <label class="pbl-field">ชนิด<select name="kind">${opt(Object.entries(o.kinds), a?.kind || 'other')}</select></label>
                    ${a ? (urlEdit ? `<label class="pbl-field">ลิงก์ https://<input name="url" type="url" inputmode="url" maxlength="500" value="${esc(a.url || '')}" placeholder="https://"></label>` : '')
                        : `<label class="pbl-field">ไฟล์ (เก็บแบบส่วนตัว เปิดได้เฉพาะครู)<input type="file" name="file"></label>
                    <label class="pbl-field">หรือลิงก์ https://<input name="url" type="url" inputmode="url" maxlength="500" placeholder="https://"></label>`}
                    <div class="pbl-actions pbl-wide"><button class="pbl-btn" type="submit">${a ? 'บันทึกการแก้ไข' : 'เพิ่มชิ้นงาน'}</button>${a ? '<button class="pbl-btn" type="button" data-act="cancel-artifact">ยกเลิกการแก้ไข</button>' : ''}</div></form>`;
            }

            function reflectionForm(r, crit, w, pick) {
                const dis = w ? '' : ' disabled';
                const who = pick ? `<label class="pbl-field">นักเรียน<select name="student_id">${opt(pick.map(m => [m.student_id, m.name]), '')}</select></label>` : `<div class="pbl-field">นักเรียน<strong>${esc(r.student_name)}</strong></div>`;
                return `<form class="pbl-form pbl-refl" data-role="reflection" data-id="${esc(r.id)}" data-version="${esc(r.version)}" novalidate>${who}
                    <label class="pbl-field pbl-wide">การสะท้อนคิด<textarea name="text" rows="3" maxlength="4000"${dis}>${esc(r.text || '')}</textarea></label>
                    <div class="pbl-rubric pbl-wide">${crit.map(c => `<label class="pbl-field">${esc(c.label)}<select name="rubric:${esc(c.key)}"${dis}>${opt([['', 'ยังไม่ประเมิน']].concat(Object.entries(st.opts.levels).map(([k, v]) => [k, k + ' ' + v + (c.levels?.[Number(k) - 1] ? ' - ' + c.levels[Number(k) - 1] : '')])), r.rubric?.[c.key])}</select></label>`).join('')}</div>
                    ${w ? `<div class="pbl-actions pbl-wide"><button class="pbl-btn pbl-primary" type="submit">บันทึก</button>${r.id ? `<button class="pbl-btn pbl-danger" type="button" data-act="del-reflection" data-id="${esc(r.id)}" data-version="${esc(r.version)}">ลบ</button>` : ''}</div>` : ''}</form>`;
            }

            // ---------------------------------------------------------------- rubric editor
            async function drawRubric() {
                const r = st.opts.rubric;
                if (!r.can_edit) { body.innerHTML = '<p>เฉพาะผู้ดูแลระบบและผู้อำนวยการแก้เกณฑ์รูบริกได้</p><p><a href="#pbl">กลับ</a></p>'; return; }
                st.rubric = r.criteria.map(c => ({...c}));
                st.revision = r.revision;
                paintRubric();
            }
            function paintRubric() {
                body.innerHTML = `<p><a href="#pbl">&larr; โครงงานทั้งหมด</a></p><div class="pbl-box"><h2>เกณฑ์รูบริกของโรงเรียน</h2>
                    <p class="pbl-lead">ทุกเกณฑ์ให้คะแนน 4 ระดับ (1 ต้องพัฒนา ถึง 4 ดีเยี่ยม) เกณฑ์ที่มีการให้คะแนนแล้วลบไม่ได้ แต่เปลี่ยนชื่อได้</p>
                    <form data-role="rubric" novalidate><ul class="pbl-list">${st.rubric.map((c, i) => `<li class="pbl-item"><div class="pbl-form">
                        <label class="pbl-field">รหัส (a-z 0-9 _)<input data-f="key" data-i="${i}" maxlength="30" value="${esc(c.key)}"></label>
                        <label class="pbl-field">ชื่อเกณฑ์<input data-f="label" data-i="${i}" maxlength="80" value="${esc(c.label)}"></label>
                        <label class="pbl-field pbl-wide">คำอธิบายระดับ 1-4 (บรรทัดละระดับ ไม่บังคับ)<textarea data-f="levels" data-i="${i}" rows="4">${esc((c.levels || []).join('\n'))}</textarea></label></div>
                        <div class="pbl-actions"><button class="pbl-btn pbl-danger" type="button" data-act="rubric-del" data-i="${i}">ลบเกณฑ์</button></div></li>`).join('')}</ul>
                        <div class="pbl-actions"><button class="pbl-btn" type="button" data-act="rubric-add"${st.rubric.length >= 10 ? ' disabled' : ''}>เพิ่มเกณฑ์</button><button class="pbl-btn pbl-primary" type="submit">บันทึกเกณฑ์</button></div></form></div>`;
            }
            const syncRubric = () => body.querySelectorAll('[data-f]').forEach(el => {
                const c = st.rubric[Number(el.dataset.i)];
                if (!c) return;
                c[el.dataset.f] = el.dataset.f === 'levels' ? el.value.split('\n').map(s => s.trim()).filter(Boolean) : el.value;
            });

            // ---------------------------------------------------------------- events
            const reflPayload = f => {
                const fd = new FormData(f), rubric = {};
                for (const [k, v] of fd.entries()) if (k.startsWith('rubric:') && v !== '') rubric[k.slice(7)] = Number(v);
                return {text: fd.get('text') || null, rubric, student_id: fd.get('student_id') ? Number(fd.get('student_id')) : undefined};
            };

            on('submit', async ev => {
                const f = ev.target.closest('[data-role]');
                if (!f || !f.matches('form')) return;
                ev.preventDefault();
                const b = f.querySelector('button[type=submit]');
                if (b) b.disabled = true;
                const fd = new FormData(f);
                try {
                    const role = f.dataset.role;
                    if (role === 'create') {
                        const d = await ctx.api(A + '/projects', {method: 'POST', idempotencyKey: C.uid(), body: {title: fd.get('title'), classroom_id: Number(fd.get('classroom_id') || 0), advisor_teacher_id: fd.get('advisor_teacher_id') || null, driving_question: fd.get('driving_question') || null}});
                        location.hash = '#pbl/' + d.project.id;
                        return;
                    }
                    if (role === 'rubric') {
                        syncRubric();
                        const r = await ctx.api(A + '/rubric', {method: 'PUT', body: {criteria: st.rubric, revision: st.revision}});
                        st.rubric = r.criteria; st.revision = r.revision; paintRubric(); msg('ok', 'บันทึกเกณฑ์แล้ว');
                        return;
                    }
                    const p = st.detail.project;
                    if (role === 'project') st.detail = await ctx.api(A + '/projects/' + p.id, {method: 'PUT', body: {title: fd.get('title'), status: fd.get('status'), advisor_teacher_id: fd.get('advisor_teacher_id') || null, driving_question: fd.get('driving_question') || null, version: p.version}});
                    else if (role === 'member-add') st.detail = await ctx.api(A + '/projects/' + p.id + '/members', {method: 'POST', body: {student_id: Number(fd.get('student_id') || 0), role: fd.get('role') || null}});
                    else if (role === 'log-add') {
                        const lb = {date: fd.get('date'), entry: fd.get('entry'), by_teacher: fd.get('by_teacher') === '1'};
                        if (st.editLog) { st.detail = await ctx.api(A + '/logs/' + st.editLog.id, {method: 'PUT', body: {...lb, version: st.editLog.version}}); st.editLog = null; }
                        else st.detail = await ctx.api(A + '/projects/' + p.id + '/logs', {method: 'POST', idempotencyKey: C.uid(), body: lb});
                    } else if (role === 'artifact-add' && st.editArt) {
                        const ab = {title: fd.get('title'), kind: fd.get('kind'), version: st.editArt.version};
                        if (!st.editArt.has_file) ab.url = fd.get('url');
                        st.detail = await ctx.api(A + '/artifacts/' + st.editArt.id, {method: 'PUT', body: ab});
                        st.editArt = null;
                    } else if (role === 'artifact-add') {
                        const body = new FormData();
                        body.set('title', fd.get('title')); body.set('kind', fd.get('kind'));
                        const file = fd.get('file');
                        if (file && file.size) body.set('file', file); else if (fd.get('url')) body.set('url', fd.get('url'));
                        st.detail = await ctx.api(A + '/projects/' + p.id + '/artifacts', {method: 'POST', body, idempotencyKey: C.uid()});
                    } else if (role === 'reflection') {
                        const pay = reflPayload(f), id = Number(f.dataset.id);
                        st.detail = id ? await ctx.api(A + '/reflections/' + id, {method: 'PUT', body: {text: pay.text, rubric: pay.rubric, version: Number(f.dataset.version)}})
                            : await ctx.api(A + '/projects/' + p.id + '/reflections', {method: 'POST', body: pay});
                    }
                    msg('ok', 'บันทึกแล้ว');
                    drawProject();
                } catch (e) { await fail(e); if (b) b.disabled = false; }
            });

            on('change', ev => {
                if (ev.target.matches('[data-role="term"]')) { st.term = ev.target.value; start().catch(fail); }
            });

            on('click', async ev => {
                const b = ev.target.closest('[data-act]');
                if (!b || !root.contains(b) || b.disabled) return;
                const act = b.dataset.act, id = Number(b.dataset.id), version = Number(b.dataset.version);
                try {
                    if (act === 'rubric-add') { syncRubric(); st.rubric.push({key: 'criterion' + (st.rubric.length + 1), label: '', levels: []}); paintRubric(); return; }
                    if (act === 'rubric-del') { syncRubric(); st.rubric.splice(Number(b.dataset.i), 1); paintRubric(); return; }
                    if (act === 'edit-log') { st.editLog = st.detail.logs.find(l => l.id === id) || null; drawProject(); body.querySelector('[name=entry]')?.focus(); return; }
                    if (act === 'cancel-log') { st.editLog = null; drawProject(); return; }
                    if (act === 'edit-artifact') { st.editArt = st.detail.artifacts.find(a => a.id === id) || null; drawProject(); body.querySelector('[data-role="artifact-add"] [name=title]')?.focus(); return; }
                    if (act === 'cancel-artifact') { st.editArt = null; drawProject(); return; }
                    const p = st.detail?.project;
                    const del =async (url, text) => { if (!confirm(text)) return false; b.disabled = true; return true; };
                    if (act === 'del-project') {
                        if (!await del('', 'ลบโครงงาน "' + p.title + '" พร้อมสมาชิก บันทึก ชิ้นงานและไฟล์ทั้งหมด ใช่หรือไม่')) return;
                        await ctx.api(A + '/projects/' + p.id, {method: 'DELETE', body: {version: p.version}});
                        location.hash = '#pbl';
                        return;
                    }
                    if (act === 'save-member') {
                        const input = body.querySelector(`[data-role="member-role"][data-id="${id}"]`);
                        b.disabled = true;
                        st.detail = await ctx.api(A + '/members/' + id, {method: 'PUT', body: {role: input.value || null, version: Number(input.dataset.version)}});
                    } else if (act === 'del-member') { if (!await del('', 'นำสมาชิกคนนี้ออกจากโครงงานใช่หรือไม่')) return; st.detail = await ctx.api(A + '/members/' + id, {method: 'DELETE', body: {version}}); }
                    else if (act === 'del-log') { if (!await del('', 'ลบบันทึกนี้ใช่หรือไม่')) return; st.detail = await ctx.api(A + '/logs/' + id, {method: 'DELETE', body: {version}}); }
                    else if (act === 'del-artifact') { if (!await del('', 'ลบชิ้นงานนี้ (รวมไฟล์) ใช่หรือไม่')) return; st.detail = await ctx.api(A + '/artifacts/' + id, {method: 'DELETE', body: {version}}); }
                    else if (act === 'del-reflection') { if (!await del('', 'ลบการสะท้อนคิดและคะแนนรูบริกนี้ใช่หรือไม่')) return; st.detail = await ctx.api(A + '/reflections/' + id, {method: 'DELETE', body: {version}}); }
                    else return;
                    msg('ok', 'ดำเนินการแล้ว');
                    drawProject();
                } catch (e) { await fail(e); b.disabled = false; }
            });

            await start();
        },
    });
})();
