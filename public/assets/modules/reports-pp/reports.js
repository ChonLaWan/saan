'use strict';
// reports-pp module (M10/M12): SaanWork page "reports". Print pages are server-rendered Blade (/print/pp/*).
// Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners on the page root).
(() => {
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
const BASE = '/api/reports-pp';
    const esc = v => window.SaanCore.esc(v);
    const termLabel = t => t ? `ปีการศึกษา ${t.year} ภาคเรียนที่ ${t.term}` : '';
    const KINDS = {pp5: 'ปพ.5 รายวิชา', pp6: 'ปพ.6 รายบุคคล', summary: 'สรุปผลรายห้อง'};

    function problem(box, e) {
        if (!e || e.name === 'AbortError') return;
        const list = e.data?.errors ? Object.values(e.data.errors).flat() : [e.status === 409 ? 'ข้อมูลถูกแก้ไขโดยผู้อื่น กรุณาโหลดใหม่แล้วลองอีกครั้ง' : (e.message || 'เกิดข้อผิดพลาด')];
        box.innerHTML = `<div class="rp-notice rp-error" role="alert"><ul>${list.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>`;
    }
    const ok = (box, text) => { box.innerHTML = `<div class="rp-notice rp-ok" role="status">${esc(text)}</div>`; };

    window.SaanWork.register('reports', {
        title: 'ปพ. และรายงานผลการเรียน',
        render: async (root, ctx) => {
            root.innerHTML = '<section class="rp-page glass"><div class="rp-msg" aria-live="polite"></div><div class="rp-body"><p>กำลังโหลด…</p></div></section>';
            const page = root.querySelector('.rp-page'), msg = page.querySelector('.rp-msg'), body = page.querySelector('.rp-body');
            const call = (url, opts = {}) => window.SaanCore.api(BASE + url, {...opts, signal: ctx.signal});
            let data = null, detail = null; // detail = {type:'special'|'remarks', ...}

            const courseCard = c => `<li class="rp-card"><strong>${esc(c.subject.code)} ${esc(c.subject.name)}</strong>
                <span>${esc(c.classroom.name)}${c.teacher ? ' · ' + esc(c.teacher.name) : ''}</span>
                <span class="rp-actions">
                    <a href="${esc(base())}/print/pp/course/${esc(c.id)}" target="_blank" rel="noopener">พิมพ์ ปพ.5</a>
                    <a href="${esc(base())}${BASE}/courses/${esc(c.id)}/csv">CSV</a>
                    <button type="button" data-act="special" data-id="${esc(c.id)}">ผล ร / มผ / มส</button>
                </span></li>`;
            const roomCard = k => {
                const q = `?term_id=${encodeURIComponent(data.term.id)}`;
                return `<li class="rp-card"><strong>ห้อง ${esc(k.name)}</strong>
                <span class="rp-actions">
                    <a href="${esc(base())}/print/pp/classroom/${esc(k.id)}${q}" target="_blank" rel="noopener">พิมพ์ ปพ.6 ทั้งห้อง</a>
                    <a href="${esc(base())}/print/pp/classroom/${esc(k.id)}/summary${q}" target="_blank" rel="noopener">สรุปผลรายห้อง</a>
                    <a href="${esc(base())}${BASE}/classrooms/${esc(k.id)}/csv${q}">CSV ปพ.6</a>
                    <a href="${esc(base())}${BASE}/classrooms/${esc(k.id)}/summary/csv${q}">CSV สรุป</a>
                    <button type="button" data-act="remarks" data-id="${esc(k.id)}">หมายเหตุครูประจำชั้น</button>
                </span></li>`;
            };
            const settingsForm = () => {
                const s = data.settings, signers = [...s.signers];
                while (signers.length < 4) signers.push({label: '', name: ''});
                const tpl = (data.templates || []).map(t => `<li>${esc(KINDS[t.kind] || t.kind)} ตั้งแต่ปีการศึกษา ${esc(t.year_be)}: ${esc(t.options.title || '')}</li>`).join('') || '<li>ยังไม่มีเทมเพลตเฉพาะปี (ใช้แบบมาตรฐาน)</li>';
                return `<details class="rp-settings"><summary>ตั้งค่ารายงาน (ผู้อำนวยการ / ผู้ดูแลระบบ)</summary>
                    <form data-form="settings">
                        <label class="rp-check"><input type="checkbox" name="show_rank"${s.show_rank ? ' checked' : ''}> แสดงอันดับ GPA ในรายงาน</label>
                        <fieldset><legend>ช่องลงนามท้ายรายงาน</legend>
                        ${signers.map((g, i) => `<div class="rp-row"><input name="label${i}" value="${esc(g.label)}" maxlength="80" placeholder="ตำแหน่ง"><input name="name${i}" value="${esc(g.name)}" maxlength="120" placeholder="ชื่อ (เว้นว่างได้)"></div>`).join('')}
                        </fieldset><button type="submit">บันทึกการตั้งค่า</button></form>
                    <form data-form="template">
                        <fieldset><legend>เทมเพลตแยกตามปีการศึกษา</legend>
                        <label>ประเภท<select name="kind">${Object.entries(KINDS).map(([k, l]) => `<option value="${esc(k)}">${esc(l)}</option>`).join('')}</select></label>
                        <label>ใช้ตั้งแต่ปีการศึกษา (พ.ศ.)<input name="year_be" type="number" min="2500" max="2700" value="${esc(data.term.year)}"></label>
                        <label>หัวรายงาน<input name="title" maxlength="120"></label>
                        <label>หมายเหตุท้ายรายงาน<input name="footer_note" maxlength="300"></label>
                        <label>ขนาดตัวอักษร (pt)<input name="font_size" type="number" min="10" max="16" value="12"></label>
                        <label class="rp-check"><input type="checkbox" name="show_components" checked> แสดงคะแนนรายองค์ประกอบ</label>
                        <label class="rp-check"><input type="checkbox" name="show_attributes" checked> แสดงคุณลักษณะ</label>
                        <label class="rp-check"><input type="checkbox" name="show_attendance" checked> แสดงสรุปเวลาเรียน</label>
                        </fieldset><button type="submit">บันทึกเทมเพลต</button>
                        <p class="rp-hint">รายงานของแต่ละปีใช้เทมเพลตปีนั้น หรือปีก่อนหน้าที่ใกล้ที่สุด จึงไม่เปลี่ยนตามเมื่อปีใหม่ปรับแบบ</p>
                        <ul>${tpl}</ul></form></details>`;
            };
            const draw = () => {
                const t = data.term;
                body.innerHTML = `<h1>ปพ. และรายงานผลการเรียน</h1>
                    <label class="rp-field"><span>ภาคเรียน</span><select data-act="term">${data.terms.map(x => `<option value="${esc(x.id)}"${x.id === t.id ? ' selected' : ''}>${esc(termLabel(x))}${x.current ? ' (ปัจจุบัน)' : ''}</option>`).join('')}</select></label>
                    <h2>รายวิชา (ปพ.5)</h2>${data.courses.length ? `<ul class="rp-list">${data.courses.map(courseCard).join('')}</ul>` : '<p class="rp-empty">ไม่มีรายวิชาที่มอบหมายให้คุณในภาคเรียนนี้</p>'}
                    <h2>ห้องเรียน (ปพ.6 และสรุปผล)</h2>${data.classrooms.length ? `<ul class="rp-list">${data.classrooms.map(roomCard).join('')}</ul>` : '<p class="rp-empty">คุณไม่ได้เป็นครูประจำชั้นของห้องใด</p>'}
                    <div class="rp-detail"></div>
                    ${data.manager ? settingsForm() : ''}
                    <p class="rp-hint">แบบพิมพ์เป็นรูปแบบเทียบเท่า ปพ.5 / ปพ.6 ของระบบ ไม่ใช่แบบทางการของเขตพื้นที่ ใช้ปุ่มพิมพ์ในหน้ารายงานเพื่อพิมพ์หรือบันทึกเป็น PDF</p>`;
            };
            const load = async termId => {
                data = await call(termId ? '/overview?term_id=' + encodeURIComponent(termId) : '/overview');
                if (ctx.alive()) draw();
            };

            const detailBox = () => body.querySelector('.rp-detail');
            async function openSpecial(id) {
                const r = await call(`/courses/${encodeURIComponent(id)}/special`);
                if (!ctx.alive()) return;
                detail = {type: 'special', id, rows: r.data};
                detailBox().innerHTML = `<h2>ผลพิเศษ ${esc(r.course.subject.code)} ${esc(r.course.subject.name)} (${esc(r.course.classroom)})</h2>
                    <p class="rp-hint">ร = ไม่สมบูรณ์, มผ = ไม่ผ่านเกณฑ์, มส = ไม่มีสิทธิ์เข้ารับการประเมิน ผลพิเศษไม่นำไปคำนวณ GPA</p>
                    <ul class="rp-list">${r.data.map(s => `<li class="rp-card" data-student="${esc(s.id)}" data-version="${esc(s.version)}">
                        <strong>${esc(s.code)} ${esc(s.name)}</strong>
                        <select data-f="code" aria-label="ผลพิเศษ"><option value="">(ไม่มี)</option>${['ร', 'มผ', 'มส'].map(c => `<option value="${esc(c)}"${s.special === c ? ' selected' : ''}>${esc(c)}</option>`).join('')}</select>
                        <input data-f="note" maxlength="200" value="${esc(s.note || '')}" placeholder="หมายเหตุ" aria-label="หมายเหตุ">
                        <button type="button" data-act="save-special">บันทึก</button></li>`).join('')}</ul>`;
            }
            async function openRemarks(id) {
                const r = await call(`/classrooms/${encodeURIComponent(id)}/remarks?term_id=${encodeURIComponent(data.term.id)}`);
                if (!ctx.alive()) return;
                detail = {type: 'remarks'};
                detailBox().innerHTML = `<h2>หมายเหตุครูประจำชั้น ห้อง ${esc(r.classroom.name)} (${esc(termLabel(r.term))})</h2>
                    <ul class="rp-list">${r.data.map(s => `<li class="rp-card" data-student="${esc(s.id)}" data-version="${esc(s.version)}" data-term="${esc(r.term.id)}">
                        <strong>${esc(s.code)} ${esc(s.name)}</strong>
                        <a href="${esc(base())}/print/pp/student/${esc(s.id)}?term_id=${esc(r.term.id)}" target="_blank" rel="noopener">พิมพ์ ปพ.6</a>
                        <textarea data-f="remark" maxlength="500" rows="2" aria-label="หมายเหตุ">${esc(s.remark)}</textarea>
                        <button type="button" data-act="save-remark">บันทึก</button></li>`).join('')}</ul>`;
            }

            body.addEventListener('click', async ev => {
                const b = ev.target.closest('button[data-act]');
                if (!b) return;
                const li = b.closest('li');
                try {
                    if (b.dataset.act === 'special') await openSpecial(b.dataset.id);
                    else if (b.dataset.act === 'remarks') await openRemarks(b.dataset.id);
                    else if (b.dataset.act === 'save-special') {
                        // a past term needs a reason (audited); the server refuses without one
                        const curTerm = (data.terms || []).find(t => t.current);
                        let reason = null;
                        if (curTerm && data.term && data.term.id !== curTerm.id) {
                            reason = (window.prompt('ภาคเรียนที่ผ่านมา กรุณาระบุเหตุผลที่แก้ผลพิเศษ') || '').trim();
                            if (!reason) return;
                        }
                        const r = await call(`/courses/${encodeURIComponent(detail.id)}/special`, {method: 'PUT', body: {student_id: Number(li.dataset.student), code: li.querySelector('[data-f=code]').value || null, note: li.querySelector('[data-f=note]').value || null, version: Number(li.dataset.version), reason}});
                        li.dataset.version = r.version;
                        ok(msg, 'บันทึกผลพิเศษแล้ว');
                    } else if (b.dataset.act === 'save-remark') {
                        const r = await call('/remarks', {method: 'PUT', body: {student_id: Number(li.dataset.student), term_id: Number(li.dataset.term), remark: li.querySelector('[data-f=remark]').value, version: Number(li.dataset.version)}});
                        li.dataset.version = r.version;
                        ok(msg, 'บันทึกหมายเหตุแล้ว');
                    }
                } catch (e) {
                    // 409: take the server's current version and values so the next save can succeed
                    if (e && e.status === 409 && e.current && li) {
                        li.dataset.version = e.current.version ?? 0;
                        const set = (sel, v) => { const el = li.querySelector(sel); if (el) el.value = v ?? ''; };
                        if (b.dataset.act === 'save-special') { set('[data-f=code]', e.current.code); set('[data-f=note]', e.current.note); }
                        else if (b.dataset.act === 'save-remark') set('[data-f=remark]', e.current.remark);
                    }
                    problem(msg, e);
                }
            });
            body.addEventListener('change', async ev => {
                const s = ev.target.closest('select[data-act="term"]');
                if (!s) return;
                try { await load(s.value); } catch (e) { problem(msg, e); }
            });
            body.addEventListener('submit', async ev => {
                const f = ev.target.closest('form[data-form]');
                if (!f) return;
                ev.preventDefault();
                const v = n => f.elements[n];
                try {
                    if (f.dataset.form === 'settings') {
                        const signers = [];
                        for (let i = 0; i < 6; i++) if (v('label' + i) && v('label' + i).value.trim()) signers.push({label: v('label' + i).value.trim(), name: v('name' + i).value.trim()});
                        data.settings = await call('/settings', {method: 'PUT', body: {show_rank: v('show_rank').checked, signers, version: data.settings.version}});
                        ok(msg, 'บันทึกการตั้งค่าแล้ว');
                    } else {
                        const kind = v('kind').value, year = Number(v('year_be').value);
                        const cur = (data.templates || []).find(t => t.kind === kind && t.year_be === year);
                        const options = {footer_note: v('footer_note').value, font_size: Number(v('font_size').value), show_components: v('show_components').checked, show_attributes: v('show_attributes').checked, show_attendance: v('show_attendance').checked};
                        if (v('title').value.trim()) options.title = v('title').value.trim();
                        await call('/templates', {method: 'PUT', body: {kind, year_be: year, version: cur ? cur.version : 0, options}});
                        await load(data.term.id);
                        ok(msg, 'บันทึกเทมเพลตแล้ว');
                    }
                } catch (e) {
                    if (e && e.status === 409 && e.current && f.dataset.form === 'settings' && e.current.version !== undefined) data.settings = e.current;
                    problem(msg, e);
                }
            });

            try { await load(null); } catch (e) { problem(msg, e); body.innerHTML = ''; }
        },
    });
})();
