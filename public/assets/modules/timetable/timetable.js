'use strict';
// timetable module (M07): SaanWork page #timetable. Views by classroom / teacher / room, teacher load, copy from a previous term, trash + undo.
// Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners on the page root).
(() => {
    const BASE = '/api/timetable';
    const esc = v => window.SaanCore.esc(v);
    const KEY = 'saan.timetable.view';

    const q = obj => Object.entries(obj).filter(([, v]) => v !== '' && v != null).map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('&');
    const opts = (rows, sel, label = r => r.name, value = r => r.id) => rows.map(r => `<option value="${esc(value(r))}"${String(value(r)) === String(sel ?? '') ? ' selected' : ''}>${esc(label(r))}</option>`).join('');
    const termLabel = t => `${t.year}/${t.term}${t.label ? ' ' + t.label : ''}${t.is_current ? ' (ปัจจุบัน)' : ''}${t.closed ? ' (สิ้นสุดแล้ว)' : ''}`;

    window.SaanWork.register('timetable', {
        title: 'ตารางสอน',
        async render(root, ctx) {
            const call = (url, o = {}) => window.SaanCore.api(BASE + url, {...o, signal: ctx.signal});
            const st = {meta: null, termId: null, view: 'classroom', target: '', lessons: [], load: [], trash: [], edit: null, copy: null, panel: '', msg: ''};
            try { const saved = JSON.parse(localStorage.getItem(KEY) || '{}'); if (['classroom', 'teacher', 'room', 'load'].includes(saved.view)) st.view = saved.view; } catch { /* storage may be blocked */ }

            root.innerHTML = '<section class="tt-page glass"><h1>ตารางสอน</h1><div class="tt-msg" aria-live="polite"></div><div class="tt-body"><p class="tt-muted">กำลังโหลด…</p></div></section>';
            const body = root.querySelector('.tt-body');
            const msgBox = root.querySelector('.tt-msg');
            const notice = (kind, html) => { msgBox.innerHTML = html ? `<div class="tt-note tt-${kind}" role="${kind === 'ok' ? 'status' : 'alert'}">${html}</div>` : ''; };
            const failure = e => {
                if (!e || e.name === 'AbortError') return;
                const list = e.data?.conflicts?.length ? e.data.conflicts.map(c => c.message) : (e.data?.errors ? Object.values(e.data.errors).flat() : [e.message || 'เกิดข้อผิดพลาด']);
                const extra = e.status === 409 && !e.data?.code ? '<button type="button" class="tt-btn" data-act="reload">โหลดข้อมูลล่าสุด</button>' : '';
                notice('err', `<ul>${list.map(x => `<li>${esc(x)}</li>`).join('')}</ul>${extra}`);
            };
            const can = () => !!st.meta?.can_write;
            const isClosed = () => !!st.meta?.terms.find(t => t.id === st.termId)?.closed;

            // a closed term answers 409 term_closed: admin confirms once and the same request is retried with confirm=true
            async function write(url, method, payload) {
                try { return await call(url, {method, body: payload, idempotencyKey: method === 'POST' ? window.SaanCore.uid() : undefined}); }
                catch (e) {
                    if (e.status === 409 && e.data?.code === 'term_closed' && st.meta.is_admin && window.confirm(e.message + '\nยืนยันการแก้ไขภาคเรียนที่สิ้นสุดแล้ว (จะบันทึกใน audit)?')) {
                        return call(url, {method, body: {...payload, confirm: true}, idempotencyKey: method === 'POST' ? window.SaanCore.uid() : undefined});
                    }
                    throw e;
                }
            }

            async function loadMeta() { st.meta = await call('/meta' + (st.termId ? '?' + q({term_id: st.termId}) : '')); st.termId = st.meta.term_id; }

            async function loadData() {
                st.lessons = []; st.load = []; st.trash = [];
                if (!st.termId) return;
                if (st.view === 'load') st.load = (await call('/load?' + q({term_id: st.termId}))).data;
                else {
                    if (st.view === 'classroom' && !st.target) st.target = String(st.meta.classrooms[0]?.id ?? '');
                    if (st.view === 'teacher') st.target = String(st.meta.my_teacher_id && !can() ? st.meta.my_teacher_id : (st.target && st.meta.teachers.some(t => String(t.id) === st.target) ? st.target : st.meta.teachers[0]?.id ?? ''));
                    if (st.view === 'room' && !st.target) st.target = st.meta.rooms[0] ?? '';
                    if (st.target) {
                        const p = st.view === 'room' ? {room: st.target} : {id: st.target};
                        st.lessons = (await call('/lessons?' + q({term_id: st.termId, view: st.view, ...p}))).lessons;
                    }
                }
                if (can() && st.panel === 'trash') st.trash = (await call('/trash?' + q({term_id: st.termId}))).data;
            }

            async function refresh(keepMsg = false) {
                try { await loadMeta(); await loadData(); if (!keepMsg) notice('', ''); if (ctx.alive()) draw(); } catch (e) { failure(e); }
            }

            // ------------------------------------------------------------ drawing
            function toolbar() {
                const m = st.meta;
                const views = [['classroom', 'ตามห้อง'], ['teacher', 'ตามครู']].concat(can() ? [['room', 'ตามสถานที่']] : []).concat([['load', 'ภาระงานครู']]);
                let pick = '';
                if (st.view === 'classroom') pick = `<label class="tt-f"><span>ห้องเรียน</span><select data-f="target">${opts(m.classrooms, st.target)}</select></label>`;
                if (st.view === 'teacher') pick = `<label class="tt-f"><span>ครู (ทุกห้องที่สอน)</span><select data-f="target">${opts(m.teachers, st.target)}</select></label>`;
                if (st.view === 'room') pick = `<label class="tt-f"><span>สถานที่</span><select data-f="target">${opts(m.rooms.map(r => ({id: r, name: r})), st.target)}</select></label>`;
                const print = (st.view === 'classroom' || st.view === 'teacher') && st.target
                    ? `<a class="tt-btn" href="${esc(document.querySelector('meta[name=school-base]')?.content?.replace(/\/+$/, '') || '')}/timetable/print/${esc(st.view)}/${esc(st.target)}?term=${esc(st.termId)}" target="_blank" rel="noopener">พิมพ์ A4</a>` : '';
                return `<div class="tt-bar">
                    <label class="tt-f"><span>ภาคเรียน</span><select data-f="term">${opts(m.terms, st.termId, termLabel)}</select></label>
                    <div class="tt-tabs" role="tablist" aria-label="มุมมองตาราง">${views.map(([k, l]) => `<button type="button" role="tab" class="tt-tab" aria-selected="${st.view === k}" data-act="view" data-view="${esc(k)}">${esc(l)}</button>`).join('')}</div>
                    ${pick}
                    <div class="tt-actions">${print}${can() ? `<button type="button" class="tt-btn" data-act="new">เพิ่มคาบ</button><button type="button" class="tt-btn" data-act="panel" data-panel="copy">คัดลอกจากภาคอื่น</button><button type="button" class="tt-btn" data-act="panel" data-panel="trash">คาบที่ลบ</button>` : ''}</div></div>
                    ${isClosed() ? '<p class="tt-note tt-warn" role="status">ภาคเรียนนี้สิ้นสุดแล้ว' + (can() ? ' การแก้ไขต้องเป็นผู้ดูแลระบบและยืนยันซ้ำ' : '') + '</p>' : ''}`;
            }

            function lessonCell(l) {
                const second = st.view === 'classroom' ? l.teacher_name : l.classroom_name;
                const room = l.room ? `<small>${esc(l.room)}</small>` : '';
                const inner = `<b>${esc(l.subject)}</b><span>${esc(second)}</span>${st.view === 'room' ? `<span>${esc(l.classroom_name)} / ${esc(l.teacher_name)}</span>` : room}`;
                return can() ? `<button type="button" class="tt-lesson" data-act="edit" data-id="${esc(l.id)}">${inner}</button>` : `<div class="tt-lesson">${inner}</div>`;
            }

            function grid() {
                const periods = st.meta.periods;
                const days = Object.keys(st.meta.days).map(Number).filter(d => d <= 5 || st.lessons.some(l => l.weekday === d));
                if (!st.target) return '<p class="tt-muted">เลือกรายการที่ต้องการดู</p>';
                const head = periods.map(p => `<th scope="col"${p.kind === 'break' ? ' class="tt-break-h"' : ''}>${p.kind === 'break' ? 'พัก' : 'คาบ ' + esc(p.number)}<small>${esc(p.starts_at)}-${esc(p.ends_at)}</small></th>`).join('');
                const rows = days.map(d => `<tr><th scope="row">${esc(st.meta.days[d])}</th>${periods.map(p => {
                    const label = `${p.kind === 'break' ? 'พัก' : 'คาบ ' + p.number} ${p.starts_at}`;
                    if (p.kind === 'break') return `<td class="tt-break" data-label="${esc(label)}">พัก</td>`;
                    const here = st.lessons.filter(l => l.weekday === d && l.period_id === p.id);
                    const add = can() && st.view !== 'load' ? `<button type="button" class="tt-add" data-act="new" data-day="${esc(d)}" data-period="${esc(p.id)}" aria-label="เพิ่มคาบ ${esc(st.meta.days[d])} ${esc(label)}">+</button>` : '';
                    return `<td data-label="${esc(label)}" class="${here.length ? 'has' : 'empty'}">${here.map(lessonCell).join('')}${here.length ? '' : add}</td>`;
                }).join('')}</tr>`).join('');
                return `<p class="tt-sum">รวม ${esc(st.lessons.length)} คาบต่อสัปดาห์</p><table class="tt-grid"><thead><tr><th scope="col">วัน</th>${head}</tr></thead><tbody>${rows}</tbody></table>`;
            }

            function loadTable() {
                if (!st.load.length) return '<p class="tt-muted">ยังไม่มีข้อมูลครู</p>';
                return `<table class="tt-table"><thead><tr><th>ครู</th><th>คาบ/สัปดาห์</th><th>วันที่สอน</th><th>ห้องที่สอน</th><th></th></tr></thead><tbody>${st.load.map(r => `<tr>
                    <td data-label="ครู">${esc(r.teacher_name)}</td><td data-label="คาบ/สัปดาห์">${esc(r.periods)}</td><td data-label="วันที่สอน">${esc(r.days)}</td><td data-label="ห้องที่สอน">${esc(r.classrooms)}</td>
                    <td><button type="button" class="tt-btn" data-act="open-teacher" data-id="${esc(r.teacher_id)}">ดูตาราง</button></td></tr>`).join('')}</tbody></table>`;
            }

            function editor() {
                const e = st.edit; if (!e) return '';
                const m = st.meta;
                const f = (label, control) => `<label class="tt-f"><span>${esc(label)}</span>${control}</label>`;
                return `<form class="tt-card tt-form" data-form="lesson" novalidate><h2>${e.id ? 'แก้ไขคาบ' : 'เพิ่มคาบ'}</h2>
                    ${f('วัน', `<select name="weekday">${opts(Object.entries(m.days).map(([k, v]) => ({id: k, name: v})), e.weekday)}</select>`)}
                    ${f('คาบ', `<select name="period_id">${opts(m.periods.filter(p => p.kind !== 'break'), e.period_id, p => `คาบ ${p.number} (${p.starts_at}-${p.ends_at})`)}</select>`)}
                    ${f('ห้องเรียน', `<select name="classroom_id">${opts(m.classrooms, e.classroom_id)}</select>`)}
                    ${f('ครู', `<select name="teacher_id">${opts(m.teachers, e.teacher_id)}</select>`)}
                    ${f('วิชา', `<input name="subject" maxlength="120" required value="${esc(e.subject)}">`)}
                    ${f('สถานที่/ห้องเรียน (ไม่บังคับ)', `<input name="room" maxlength="100" value="${esc(e.room)}">`)}
                    ${f('หมายเหตุ (ไม่บังคับ)', `<input name="note" maxlength="300" value="${esc(e.note)}">`)}
                    <div class="tt-actions"><button type="submit" class="tt-btn primary">บันทึก</button><button type="button" class="tt-btn" data-act="cancel">ยกเลิก</button>${e.id ? '<button type="button" class="tt-btn danger" data-act="delete">ลบคาบนี้</button>' : ''}</div></form>`;
            }

            function copyPanel() {
                const m = st.meta;
                const others = m.terms.filter(t => t.id !== st.termId);
                if (!others.length) return '<section class="tt-card"><h2>คัดลอกตารางจากภาคเรียนอื่น</h2><p class="tt-muted">ยังไม่มีภาคเรียนอื่นให้คัดลอก</p></section>';
                const c = st.copy;
                let report = '';
                if (c?.report) {
                    const r = c.report;
                    report = `<div class="tt-report"><p>ต้นทาง ${esc(r.total)} คาบ: คัดลอกได้ ${esc(r.copyable)} / มีอยู่แล้ว ${esc(r.duplicates)} / ชน ${esc(r.collision_count)}${r.inactive_skipped ? ' / ข้าม (ครูหรือห้องปิดใช้งาน) ' + esc(r.inactive_skipped) : ''}</p>
                        ${r.tombstones_purged ? `<p class="tt-note tt-warn">มีคาบที่ลบไว้ในภาคเรียนนี้ ${esc(r.tombstones_purged)} รายการที่ใช้ช่วงเวลาเดียวกัน การคัดลอกจะลบถาวร (เรียกคืนไม่ได้)</p>` : ''}
                        ${r.collision_count ? `<details open><summary>รายงานคาบที่ชน (${esc(r.collision_count)})</summary><ul>${r.collisions.map(x => `<li><b>${esc(x.lesson.classroom_name)} ${esc(m.days[x.lesson.weekday])} คาบ ${esc(x.lesson.period_number)} ${esc(x.lesson.subject)}</b>: ${esc(x.messages.join(' / '))}</li>`).join('')}</ul></details>` : ''}
                        ${r.copyable || r.collision_count ? `<div class="tt-actions"><button type="button" class="tt-btn primary" data-act="copy-commit">${r.collision_count ? 'คัดลอก โดยข้ามรายการที่ชน' : 'คัดลอก'} ${esc(r.copyable)} คาบ</button></div>` : ''}</div>`;
                }
                return `<section class="tt-card"><h2>คัดลอกตารางมายังภาคเรียนนี้ (${esc(termLabel(m.terms.find(t => t.id === st.termId)))})</h2>
                    <label class="tt-f"><span>คัดลอกจากภาคเรียน</span><select data-f="copy-from">${opts(others, c?.from ?? others[0].id, termLabel)}</select></label>
                    <div class="tt-actions"><button type="button" class="tt-btn" data-act="copy-preview">ดูตัวอย่างและรายงานชน</button></div>${report}</section>`;
            }

            function trashPanel() {
                return `<section class="tt-card"><h2>คาบที่ลบ (เรียกคืนได้)</h2>${st.trash.length ? `<ul class="tt-trash">${st.trash.map(l => `<li><span>${esc(l.classroom_name)} / ${esc(l.teacher_name)} / ${esc(l.subject)} / ${esc(st.meta.days[l.weekday])} คาบ ${esc(l.period_number)}</span><button type="button" class="tt-btn" data-act="restore" data-id="${esc(l.id)}" data-v="${esc(l.version)}">เรียกคืน</button></li>`).join('')}</ul>` : '<p class="tt-muted">ไม่มีรายการ</p>'}<p class="tt-muted">คาบที่ลบจะถาวรเมื่อมีคาบใหม่มาใช้ช่วงเวลาเดียวกัน</p></section>`;
            }

            function draw() {
                if (!st.meta.terms.length) { body.innerHTML = '<p class="tt-muted">ยังไม่มีภาคเรียน ตั้งค่าที่เมนู ตั้งค่าโรงเรียนก่อน</p>'; return; }
                body.innerHTML = toolbar() + editor() + (st.panel === 'copy' && can() ? copyPanel() : '') + (st.panel === 'trash' && can() ? trashPanel() : '') + (st.view === 'load' ? loadTable() : grid());
            }

            // ------------------------------------------------------------ events
            const num = v => v === '' || v == null ? null : Number(v);
            root.addEventListener('change', async ev => {
                const f = ev.target.dataset?.f; if (!f) return;
                if (f === 'copy-from') { st.copy = {from: Number(ev.target.value)}; return; }
                if (f === 'term') { st.termId = Number(ev.target.value); st.target = ''; st.edit = null; st.copy = null; }
                if (f === 'target') st.target = ev.target.value;
                await refresh();
            }, {signal: ctx.signal});

            root.addEventListener('click', async ev => {
                const b = ev.target.closest('[data-act]'); if (!b || !root.contains(b) || b.disabled) return;
                ev.preventDefault();
                const act = b.dataset.act;
                b.disabled = true;
                try {
                    if (act === 'reload') { st.edit = null; await refresh(); }
                    else if (act === 'view') {
                        st.view = b.dataset.view; st.target = ''; st.edit = null;
                        try { localStorage.setItem(KEY, JSON.stringify({view: st.view})); } catch { /* ignore */ }
                        await refresh();
                    } else if (act === 'open-teacher') { st.view = 'teacher'; st.target = b.dataset.id; await refresh(); }
                    else if (act === 'panel') { st.panel = st.panel === b.dataset.panel ? '' : b.dataset.panel; st.copy = null; await refresh(true); }
                    else if (act === 'new') {
                        const teacher = st.view === 'teacher' ? num(st.target) : st.meta.teachers[0]?.id;
                        const classroom = st.view === 'classroom' ? num(st.target) : st.meta.classrooms[0]?.id;
                        st.edit = {id: null, weekday: num(b.dataset.day) ?? 1, period_id: num(b.dataset.period) ?? st.meta.periods.find(p => p.kind !== 'break')?.id, classroom_id: classroom, teacher_id: teacher, subject: '', room: st.view === 'room' ? st.target : '', note: ''};
                        notice('', ''); draw(); body.querySelector('.tt-form [name=subject]')?.focus();
                    } else if (act === 'edit') {
                        st.edit = {...st.lessons.find(l => l.id === Number(b.dataset.id))};
                        notice('', ''); draw(); body.querySelector('.tt-form [name=subject]')?.focus();
                    } else if (act === 'cancel') { st.edit = null; notice('', ''); draw(); }
                    else if (act === 'delete') {
                        const e = st.edit;
                        if (!window.confirm('ลบคาบนี้? (เรียกคืนได้จากปุ่ม คาบที่ลบ)')) return;
                        await write('/lessons/' + e.id, 'DELETE', {term_id: e.term_id, version: e.version});
                        st.edit = null; await refresh(true); notice('ok', 'ลบแล้ว เรียกคืนได้จากปุ่ม "คาบที่ลบ"');
                    } else if (act === 'restore') {
                        await write('/lessons/' + b.dataset.id + '/restore', 'POST', {term_id: st.termId, version: Number(b.dataset.v)});
                        await refresh(true); notice('ok', 'เรียกคืนคาบแล้ว');
                    } else if (act === 'copy-preview') {
                        const from = Number(body.querySelector('[data-f=copy-from]').value);
                        const report = await call('/copy/preview', {method: 'POST', body: {from_term_id: from, to_term_id: st.termId}});
                        st.copy = {from, report}; draw();
                    } else if (act === 'copy-commit') {
                        const r = st.copy.report;
                        if (r.collision_count && !window.confirm('จะข้ามคาบที่ชน ' + r.collision_count + ' รายการ ตกลงหรือไม่?')) return;
                        const done = await write('/copy', 'POST', {from_term_id: st.copy.from, to_term_id: st.termId, skip_collisions: r.collision_count > 0});
                        st.copy = null; await refresh(true); notice('ok', 'คัดลอกแล้ว ' + done.copied + ' คาบ');
                    }
                } catch (e) { failure(e); } finally { b.disabled = false; }
            }, {signal: ctx.signal});

            root.addEventListener('submit', async ev => {
                const f = ev.target.closest('form[data-form=lesson]'); if (!f) return;
                ev.preventDefault();
                const btn = f.querySelector('button[type=submit]'); if (btn) btn.disabled = true;
                try {
                    const d = Object.fromEntries(new FormData(f));
                    const payload = {term_id: st.termId, weekday: num(d.weekday), period_id: num(d.period_id), classroom_id: num(d.classroom_id), teacher_id: num(d.teacher_id),
                        subject: String(d.subject || '').trim(), room: String(d.room || '').trim() || null, note: String(d.note || '').trim() || null};
                    if (st.edit.id) await write('/lessons/' + st.edit.id, 'PUT', {...payload, term_id: st.edit.term_id, version: st.edit.version});
                    else await write('/lessons', 'POST', payload);
                    st.edit = null; await refresh(true); notice('ok', 'บันทึกคาบแล้ว');
                } catch (e) { failure(e); } finally { if (btn) btn.disabled = false; }
            }, {signal: ctx.signal});

            await refresh();
        },
    });
})();
