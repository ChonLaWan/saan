'use strict';
// academic module (GAP-OPS-001): SaanWork pages setup, terms, periods, classrooms, teachers, calendar.
// Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners on each editor box).
(() => {
    const BASE = '/api/academic';
    const esc = v => window.SaanCore.esc(v);
    const role = () => { try { return JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').role || ''; } catch { return ''; } };
    const canWrite = () => role() === 'admin' || role() === 'principal';
    const isAdmin = () => role() === 'admin';
    const call = (ctx, url, opts = {}) => window.SaanCore.api(BASE + url, {...opts, signal: ctx.signal});
    const date = iso => window.SaanCore.date(iso, {day: 'numeric', month: 'short', year: 'numeric'});
    const KIND_DAY = {holiday: 'วันหยุด', event: 'กิจกรรม', exam: 'สอบ', no_class: 'งดการเรียน'};
    const KIND_PERIOD = {class: 'คาบเรียน', break: 'พัก', homeroom: 'โฮมรูม'};

    const field = (label, control, hint = '') => `<label class="ac-field"><span>${esc(label)}</span>${control}${hint ? `<small>${esc(hint)}</small>` : ''}</label>`;
    const input = (name, value = '', attrs = '') => `<input name="${esc(name)}" value="${esc(value)}" ${attrs}>`;
    const options = (pairs, selected) => pairs.map(([v, l]) => `<option value="${esc(v)}"${String(v) === String(selected ?? '') ? ' selected' : ''}>${esc(l)}</option>`).join('');
    const data = form => Object.fromEntries(new FormData(form));
    const num = v => (v === '' || v == null ? null : Number(v));
    const nul = v => { const s = String(v ?? '').trim(); return s === '' ? null : s; };
    const toMin = t => { const m = /^(\d{2}):(\d{2})$/.exec(t || ''); return m ? +m[1] * 60 + +m[2] : null; };
    const fromMin = n => String(Math.floor(n / 60) % 24).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0');
    const beYear = () => Number(window.SaanCore.today().slice(0, 4)) + 543;

    function message(box, html) { const m = box.querySelector('.ac-msg'); if (m) m.innerHTML = html; }
    function ok(box, text) { message(box, `<div class="ac-notice ac-ok" role="status">${esc(text)}</div>`); }
    function clear(box) { message(box, ''); }
    function problem(box, e) {
        if (!e || e.name === 'AbortError') return;
        if (e.status === 409) {
            message(box, `<div class="ac-notice ac-conflict" role="alert"><p>${esc(e.message)}</p><button type="button" class="ac-btn" data-act="reload">โหลดข้อมูลล่าสุด</button></div>`);
            return;
        }
        const list = e.data?.errors ? Object.values(e.data.errors).flat() : [e.message || 'เกิดข้อผิดพลาด'];
        message(box, `<div class="ac-notice ac-error" role="alert"><ul>${list.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>`);
    }

    // Shared editor shell: loads state, draws, and routes delegated click/submit events to def.click / def.submit.
    function editor(box, ctx, def) {
        box.innerHTML = '<div class="ac-msg" aria-live="polite"></div><div class="ac-body"><p class="ac-loading">กำลังโหลด…</p></div>';
        const body = box.querySelector('.ac-body');
        const st = {};
        const api = (url, opts) => call(ctx, url, opts);
        const draw = () => { body.innerHTML = def.view(st); };
        // true only when the reload worked; a failed reload shows its own error and must not be overwritten by a success notice
        const refresh = async () => {
            try { await def.load(st, api); if (ctx.alive()) draw(); return true; } catch (e) { problem(box, e); return false; }
        };
        const h = {api, draw, refresh, saved: async t => { if (await refresh()) ok(box, t); }, st, box, body, ok: t => ok(box, t), clear: () => clear(box), changed: () => { if (typeof ctx.afterSave === 'function') ctx.afterSave(); }};
        box.addEventListener('click', async ev => {
            const b = ev.target.closest('[data-act]');
            if (!b || !box.contains(b)) return;
            ev.preventDefault();
            if (b.dataset.act === 'reload') { clear(box); await refresh(); return; }
            if (b.disabled) return;
            b.disabled = true;
            try { await def.click?.(b.dataset.act, b, h); } catch (e) { problem(box, e); } finally { b.disabled = false; }
        });
        box.addEventListener('submit', async ev => {
            const f = ev.target.closest('form[data-form]');
            if (!f || !box.contains(f)) return;
            ev.preventDefault();
            const btn = f.querySelector('button[type=submit]');
            if (btn) btn.disabled = true;
            try { await def.submit?.(f.dataset.form, f, h); } catch (e) { problem(box, e); } finally { if (btn) btn.disabled = false; }
        });
        return refresh();
    }

    // ---------------------------------------------------------------- terms
    const termsDef = {
        async load(st, api) { const r = await api('/terms'); st.rows = r.data; st.settingVersion = r.setting_version ?? null; },
        view(st) {
            const w = canWrite();
            const edit = st.edit ? st.rows.find(r => r.id === st.edit) : null;
            const rows = st.rows.map(r => `<tr>
                <td data-label="ปี/ภาค">${esc(r.year)}/${esc(r.term)}${r.label ? ' ' + esc(r.label) : ''}</td>
                <td data-label="เริ่ม">${esc(date(r.starts_on))}</td><td data-label="สิ้นสุด">${esc(date(r.ends_on))}</td>
                <td data-label="สถานะ">${r.is_current ? '<span class="ac-badge on">ภาคเรียนปัจจุบัน</span>' : '<span class="ac-badge off">ไม่ใช่ปัจจุบัน</span>'}</td>
                ${w ? `<td class="ac-act">${r.is_current ? '' : `<button type="button" class="ac-btn" data-act="current" data-id="${esc(r.id)}">ตั้งเป็นปัจจุบัน</button>`}
                <button type="button" class="ac-btn" data-act="edit" data-id="${esc(r.id)}">แก้ไข</button>
                <button type="button" class="ac-btn danger" data-act="delete" data-id="${esc(r.id)}">ลบ</button></td>` : ''}</tr>`).join('');
            const form = w ? `<form class="ac-card ac-form cols" data-form="term" novalidate>
                <h3>${edit ? 'แก้ไขภาคเรียน' : 'เพิ่มภาคเรียน'}</h3>
                ${field('ปีการศึกษา (พ.ศ.)', input('year', edit?.year ?? beYear(), 'type="number" min="2500" max="2700" required inputmode="numeric"'))}
                ${field('ภาคเรียนที่', `<select name="term">${options([[1, '1'], [2, '2'], [3, '3 (ภาคฤดูร้อน)']], edit?.term ?? 1)}</select>`)}
                ${field('ชื่อเรียก (ไม่บังคับ)', input('label', edit?.label ?? '', 'maxlength="60"'))}
                ${field('วันเริ่มภาคเรียน', input('starts_on', edit?.starts_on ?? '', 'type="date" required'))}
                ${field('วันสิ้นสุดภาคเรียน', input('ends_on', edit?.ends_on ?? '', 'type="date" required'))}
                <div class="ac-actions"><button type="submit" class="ac-btn primary">บันทึก</button>${edit ? '<button type="button" class="ac-btn" data-act="cancel">ยกเลิกการแก้ไข</button>' : ''}</div></form>` : '';
            const note = w ? '<p class="ac-lead">หมายเหตุ: ตารางสอน เวร และคะแนน ในหน้างานโรงเรียนยังใช้ภาคเรียนตามค่า SCHOOL_TERM_ID ของระบบ การกดตั้งเป็นปัจจุบันยังไม่เปลี่ยนภาคเรียนของหน้าเหล่านั้น</p>' : '';
            return `${form}${note}${st.rows.length ? `<table class="ac-table"><thead><tr><th>ปี/ภาค</th><th>เริ่ม</th><th>สิ้นสุด</th><th>สถานะ</th>${w ? '<th></th>' : ''}</tr></thead><tbody>${rows}</tbody></table>` : '<p class="ac-lead">ยังไม่มีภาคเรียน</p>'}`;
        },
        async click(act, b, h) {
            const id = Number(b.dataset.id);
            if (act === 'edit') { h.st.edit = id; h.draw(); return; }
            if (act === 'cancel') { h.st.edit = null; h.draw(); return; }
            if (act === 'current') { await h.api('/terms/' + id + '/current', {method: 'POST', body: {expected_version: h.st.settingVersion ?? null}}); await h.saved('ตั้งเป็นภาคเรียนปัจจุบันแล้ว'); h.changed(); return; }
            if (act === 'delete') {
                const r = h.st.rows.find(x => x.id === id);
                if (!window.confirm('ลบภาคเรียน ' + r.year + '/' + r.term + ' ?')) return;
                await h.api('/terms/' + id, {method: 'DELETE', body: {version: r.version}});
                h.st.edit = null; await h.saved('ลบภาคเรียนแล้ว'); h.changed();
            }
        },
        async submit(name, f, h) {
            const d = data(f);
            const edit = h.st.edit ? h.st.rows.find(r => r.id === h.st.edit) : null;
            const body = {year: num(d.year), term: num(d.term), label: nul(d.label), starts_on: d.starts_on, ends_on: d.ends_on};
            if (edit) await h.api('/terms/' + edit.id, {method: 'PUT', body: {...body, version: edit.version}});
            else await h.api('/terms', {method: 'POST', body});
            h.st.edit = null; await h.saved('บันทึกภาคเรียนแล้ว'); h.changed();
        },
    };

    // -------------------------------------------------------------- periods
    function syncPeriods(h) {
        h.st.rows = [...h.body.querySelectorAll('.ac-prow')].map(r => ({
            starts_at: r.querySelector('[data-f=starts_at]').value, ends_at: r.querySelector('[data-f=ends_at]').value, kind: r.querySelector('[data-f=kind]').value,
        }));
    }
    const periodsDef = {
        async load(st, api) {
            const r = await api('/periods');
            st.token = r.token;
            st.rows = r.data.map(p => ({starts_at: p.starts_at, ends_at: p.ends_at, kind: p.kind}));
        },
        view(st) {
            const w = canWrite();
            let k = 0;
            const rows = st.rows.map((r, i) => {
                const label = r.kind === 'class' ? 'คาบที่ ' + (++k) : KIND_PERIOD[r.kind] || r.kind;
                return `<div class="ac-prow" data-i="${i}"><span class="ac-plabel">${esc(label)}</span>
                    <input type="time" data-f="starts_at" value="${esc(r.starts_at)}" aria-label="เริ่ม ${esc(label)}" ${w ? '' : 'disabled'}>
                    <input type="time" data-f="ends_at" value="${esc(r.ends_at)}" aria-label="สิ้นสุด ${esc(label)}" ${w ? '' : 'disabled'}>
                    <select data-f="kind" aria-label="ชนิด ${esc(label)}" ${w ? '' : 'disabled'}>${options(Object.entries(KIND_PERIOD), r.kind)}</select>
                    ${w ? `<button type="button" class="ac-btn danger" data-act="remove" data-i="${i}">ลบแถว</button>` : ''}</div>`;
            }).join('');
            const gen = w ? `<form class="ac-card ac-form cols" data-form="gen" novalidate><h3>สร้างคาบต่อเนื่องอัตโนมัติ</h3>
                ${field('เวลาเริ่มคาบแรก', input('start', '08:30', 'type="time" required'))}
                ${field('นาทีต่อคาบ', input('len', 50, 'type="number" min="10" max="120" required'))}
                ${field('จำนวนคาบ', input('count', 7, 'type="number" min="1" max="12" required'))}
                <div class="ac-actions"><button type="submit" class="ac-btn">สร้างแถวคาบ</button></div></form>` : '';
            return `${gen}<div class="ac-card"><h3>ตารางคาบเวลา</h3>${rows || '<p class="ac-lead">ยังไม่มีคาบ</p>'}
                ${w ? '<div class="ac-actions"><button type="button" class="ac-btn" data-act="add">เพิ่มแถว</button><button type="button" class="ac-btn primary" data-act="save">บันทึกคาบเวลา</button></div>' : ''}
                <p class="ac-lead">คาบเรียนเรียงลำดับเป็นคาบที่ 1, 2, 3 ... ส่วนเวลาพักและโฮมรูมไม่นับเป็นคาบสอน เวลาต้องเรียงต่อกันและไม่ซ้อนทับ แถวพักและโฮมรูมจะมีเลขคาบตั้งแต่ 101 ขึ้นไป ไม่ใช้วางวิชาในตารางสอน</p></div>`;
        },
        async click(act, b, h) {
            if (act === 'add' || act === 'remove' || act === 'save') syncPeriods(h);
            if (act === 'add') {
                const last = h.st.rows[h.st.rows.length - 1];
                const s = last ? toMin(last.ends_at) : 510;
                h.st.rows.push({starts_at: fromMin(s ?? 510), ends_at: fromMin(((s ?? 510) + 50) % 1440), kind: 'class'});
                h.draw(); return;
            }
            if (act === 'remove') { h.st.rows.splice(Number(b.dataset.i), 1); h.draw(); return; }
            if (act === 'save') {
                const r = await h.api('/periods', {method: 'PUT', body: {token: h.st.token, periods: h.st.rows}});
                h.st.token = r.token; h.st.rows = r.data.map(p => ({starts_at: p.starts_at, ends_at: p.ends_at, kind: p.kind}));
                h.draw(); h.ok('บันทึกคาบเวลาแล้ว'); h.changed();
            }
        },
        async submit(name, f, h) {
            const d = data(f);
            const start = toMin(d.start), len = Number(d.len), count = Number(d.count);
            if (start === null || !(len >= 10) || !(count >= 1) || start + len * count > 1440) throw Object.assign(new Error('ตรวจสอบเวลาเริ่ม นาทีต่อคาบ และจำนวนคาบ'), {status: 422});
            h.st.rows = Array.from({length: count}, (_, i) => ({starts_at: fromMin(start + i * len), ends_at: fromMin(start + (i + 1) * len), kind: 'class'}));
            h.draw(); h.ok('สร้างแถวแล้ว ตรวจสอบและกดบันทึกคาบเวลา');
        },
    };

    // active teachers, plus the room's current homeroom teacher even when deactivated (labelled), so saving never drops it silently
    function teacherChoices(st, currentId) {
        const list = st.teachers.map(t => [t.id, t.name]);
        const cur = currentId == null ? null : (st.allTeachers || []).find(t => t.id === currentId);
        if (cur && !cur.is_active) list.push([cur.id, cur.name + ' (ปิดใช้งาน)']);
        return [['', 'ยังไม่กำหนด'], ...list];
    }

    // ----------------------------------------------------------- classrooms
    const classroomsDef = {
        async load(st, api) {
            const [c, t] = await Promise.all([api('/classrooms'), api('/teachers')]);
            st.rows = c.data; st.allTeachers = t.data; st.teachers = t.data.filter(x => x.is_active);
        },
        view(st) {
            const w = canWrite();
            const edit = st.edit ? st.rows.find(r => r.id === st.edit) : null;
            const teacherOptions = teacherChoices(st, edit?.homeroom_teacher_id);
            const rows = st.rows.map(r => `<tr>
                <td data-label="ห้อง">${esc(r.name)}</td><td data-label="ระดับ">${esc(r.grade_level ?? '-')}</td>
                <td data-label="ครูประจำชั้น">${esc(r.homeroom_teacher_name ?? 'ยังไม่กำหนด')}</td><td data-label="นักเรียน">${esc(r.student_count)}</td>
                <td data-label="สถานะ"><span class="ac-badge ${r.is_active ? 'on' : 'off'}">${r.is_active ? 'ใช้งาน' : 'ปิดใช้งาน'}</span></td>
                ${w ? `<td class="ac-act"><button type="button" class="ac-btn" data-act="edit" data-id="${esc(r.id)}">แก้ไข</button>
                <button type="button" class="ac-btn danger" data-act="delete" data-id="${esc(r.id)}">ลบ</button></td>` : ''}</tr>`).join('');
            const bulk = w && !edit ? `<form class="ac-card ac-form cols" data-form="bulk" novalidate><h3>สร้างหลายห้องพร้อมกัน</h3>
                ${field('ระดับชั้น', input('pattern', 'ป.1-ป.6', 'maxlength="500" required'), 'เช่น ป.1-ป.6, ม.1-ม.3 หรือ อ.1, อ.2 (คั่นด้วยจุลภาค)')}
                ${field('จำนวนห้องต่อระดับ', input('sections', 2, 'type="number" min="1" max="20" required'), 'ได้ชื่อเป็น ป.1/1, ป.1/2 ...')}
                <div class="ac-actions"><button type="submit" class="ac-btn primary">สร้างห้องเรียน</button></div></form>` : '';
            const form = w ? `<form class="ac-card ac-form cols" data-form="room" novalidate><h3>${edit ? 'แก้ไขห้องเรียน' : 'เพิ่มห้องเรียนทีละห้อง'}</h3>
                ${field('ระดับชั้น', input('grade_level', edit?.grade_level ?? '', 'maxlength="20" placeholder="เช่น ป.4"'))}
                ${field('ห้องที่', input('section', edit?.section ?? '', 'type="number" min="1" max="99"'))}
                ${field('ชื่อห้อง (ว่างไว้เพื่อสร้างอัตโนมัติ)', input('name', edit?.name ?? '', 'maxlength="60"'))}
                ${field('ครูประจำชั้น', `<select name="homeroom_teacher_id">${options(teacherOptions, edit?.homeroom_teacher_id)}</select>`)}
                ${edit ? field('สถานะ', `<select name="is_active">${options([['1', 'ใช้งาน'], ['0', 'ปิดใช้งาน']], edit.is_active ? '1' : '0')}</select>`) : ''}
                <div class="ac-actions"><button type="submit" class="ac-btn primary">บันทึก</button>${edit ? '<button type="button" class="ac-btn" data-act="cancel">ยกเลิกการแก้ไข</button>' : ''}</div></form>` : '';
            return `${bulk}${form}${st.rows.length ? `<table class="ac-table"><thead><tr><th>ห้อง</th><th>ระดับ</th><th>ครูประจำชั้น</th><th>นักเรียน</th><th>สถานะ</th>${w ? '<th></th>' : ''}</tr></thead><tbody>${rows}</tbody></table>` : '<p class="ac-lead">ยังไม่มีห้องเรียน</p>'}`;
        },
        async click(act, b, h) {
            const id = Number(b.dataset.id);
            if (act === 'edit') { h.st.edit = id; h.draw(); return; }
            if (act === 'cancel') { h.st.edit = null; h.draw(); return; }
            if (act === 'delete') {
                const r = h.st.rows.find(x => x.id === id);
                if (!window.confirm('ลบห้องเรียน ' + r.name + ' ?')) return;
                await h.api('/classrooms/' + id, {method: 'DELETE', body: {version: r.version}});
                h.st.edit = null; await h.saved('ลบห้องเรียนแล้ว'); h.changed();
            }
        },
        async submit(name, f, h) {
            const d = data(f);
            if (name === 'bulk') {
                const r = await h.api('/classrooms/bulk', {method: 'POST', body: {pattern: d.pattern, sections: num(d.sections)}, idempotencyKey: window.SaanCore.uid()});
                await h.saved('สร้างห้องเรียน ' + r.created + ' ห้อง' + (r.skipped.length ? ' ข้ามที่มีอยู่แล้ว ' + r.skipped.length + ' ห้อง' : ''));
                h.changed(); return;
            }
            const edit = h.st.edit ? h.st.rows.find(r => r.id === h.st.edit) : null;
            const body = {name: nul(d.name), grade_level: nul(d.grade_level), section: num(d.section), homeroom_teacher_id: num(d.homeroom_teacher_id)};
            if (edit) await h.api('/classrooms/' + edit.id, {method: 'PUT', body: {...body, is_active: d.is_active !== '0', version: edit.version}});
            else await h.api('/classrooms', {method: 'POST', body});
            h.st.edit = null; await h.saved('บันทึกห้องเรียนแล้ว'); h.changed();
        },
    };

    // ------------------------------------------------------------ homerooms
    const homeroomsDef = {
        async load(st, api) {
            const [c, t] = await Promise.all([api('/classrooms'), api('/teachers')]);
            st.rows = c.data.filter(r => r.is_active); st.allTeachers = t.data; st.teachers = t.data.filter(x => x.is_active);
        },
        view(st) {
            if (!st.rows.length) return '<p class="ac-lead">ยังไม่มีห้องเรียน กรุณาสร้างห้องเรียนในขั้นตอนก่อนหน้า</p>';
            if (!st.teachers.length) return '<p class="ac-lead">ยังไม่มีครู กรุณาเพิ่มครูในขั้นตอนก่อนหน้า</p>';
            const w = canWrite();
            const rows = st.rows.map(r => `<tr><td data-label="ห้อง">${esc(r.name)}</td><td data-label="ครูประจำชั้น">
                <select data-room="${esc(r.id)}" aria-label="ครูประจำชั้น ${esc(r.name)}" ${w ? '' : 'disabled'}>${options(teacherChoices(st, r.homeroom_teacher_id), r.homeroom_teacher_id)}</select></td></tr>`).join('');
            return `<table class="ac-table"><thead><tr><th>ห้อง</th><th>ครูประจำชั้น</th></tr></thead><tbody>${rows}</tbody></table>
                ${w ? '<div class="ac-actions"><button type="button" class="ac-btn primary" data-act="save">บันทึกครูประจำชั้น</button></div>' : ''}`;
        },
        async click(act, b, h) {
            if (act !== 'save') return;
            const changes = [];
            h.body.querySelectorAll('select[data-room]').forEach(s => {
                const r = h.st.rows.find(x => x.id === Number(s.dataset.room));
                const now = s.value === '' ? null : Number(s.value);
                if (r && now !== r.homeroom_teacher_id) changes.push({classroom_id: r.id, version: r.version, teacher_id: now});
            });
            if (!changes.length) { h.ok('ไม่มีการเปลี่ยนแปลง'); return; }
            await h.api('/homerooms', {method: 'PUT', body: {assignments: changes}});
            await h.saved('บันทึกครูประจำชั้น ' + changes.length + ' ห้องแล้ว'); h.changed();
        },
    };

    // ------------------------------------------------------------- teachers
    const teachersDef = {
        async load(st, api) { st.rows = (await api('/teachers')).data; st.text = st.text ?? ''; },
        view(st) {
            const w = canWrite();
            const edit = st.edit ? st.rows.find(r => r.id === st.edit) : null;
            const rows = st.rows.map(r => `<tr><td data-label="ชื่อ">${esc(r.name)}</td><td data-label="กลุ่มสาระ">${esc(r.subject_group ?? '-')}</td>
                ${w ? `<td data-label="บัญชี"><span class="ac-badge ${r.has_account ? 'on' : 'off'}">${r.has_account ? 'มีบัญชี' : 'ยังไม่มี'}</span></td>` : ''}
                <td data-label="สถานะ"><span class="ac-badge ${r.is_active ? 'on' : 'off'}">${r.is_active ? 'ใช้งาน' : 'ปิดใช้งาน'}</span></td>
                ${w ? `<td class="ac-act"><button type="button" class="ac-btn" data-act="edit" data-id="${esc(r.id)}">แก้ไข</button>
                <button type="button" class="ac-btn danger" data-act="delete" data-id="${esc(r.id)}">ลบ</button></td>` : ''}</tr>`).join('');
            let preview = '';
            if (st.preview) {
                const p = st.preview;
                preview = `<div class="ac-notice" role="status"><p>จะเพิ่ม ${esc(p.created.length)} คน${p.skipped.length ? ' ข้าม ' + esc(p.skipped.length) + ' บรรทัด' : ''}</p>
                    <ul class="ac-pre">${p.created.map(c => `<li>บรรทัด ${esc(c.line)}: ${esc(c.name)}${c.subject_group ? ' (' + esc(c.subject_group) + ')' : ''}</li>`).join('')}
                    ${p.skipped.map(s => `<li class="ac-skip">บรรทัด ${esc(s.line)} ข้าม: ${esc(s.reason)} - ${esc(s.text)}</li>`).join('')}</ul></div>`;
            }
            const paste = w && !edit ? `<div class="ac-card"><h3>วางรายชื่อครูหลายคน</h3>
                <p class="ac-lead">หนึ่งบรรทัดต่อหนึ่งคน รูปแบบ คำนำหน้า ชื่อ นามสกุล, กลุ่มสาระ เช่น นาย สมชาย ใจดี, คณิตศาสตร์</p>
                <label class="ac-field"><span>รายชื่อครู</span><textarea name="text" maxlength="60000" placeholder="นาย สมชาย ใจดี, คณิตศาสตร์">${esc(st.text)}</textarea></label>
                ${preview}<div class="ac-actions"><button type="button" class="ac-btn" data-act="preview">ตรวจสอบก่อนนำเข้า</button><button type="button" class="ac-btn primary" data-act="import">นำเข้ารายชื่อ</button></div></div>` : '';
            const form = w ? `<form class="ac-card ac-form cols" data-form="teacher" novalidate><h3>${edit ? 'แก้ไขครู' : 'เพิ่มครูทีละคน'}</h3>
                ${field('คำนำหน้า', input('prefix', edit?.prefix ?? '', 'maxlength="30" placeholder="นาย / นาง / นางสาว"'))}
                ${field('ชื่อ', input('first_name', edit ? (edit.first_name ?? edit.name) : '', 'maxlength="70" required'))}
                ${field('นามสกุล', input('last_name', edit?.last_name ?? '', 'maxlength="80"'))}
                ${field('กลุ่มสาระ', input('subject_group', edit?.subject_group ?? '', 'maxlength="120"'))}
                ${edit ? field('สถานะ', `<select name="is_active">${options([['1', 'ใช้งาน'], ['0', 'ปิดใช้งาน']], edit.is_active ? '1' : '0')}</select>`) : ''}
                <div class="ac-actions"><button type="submit" class="ac-btn primary">บันทึก</button>${edit ? '<button type="button" class="ac-btn" data-act="cancel">ยกเลิกการแก้ไข</button>' : ''}</div></form>` : '';
            return `${paste}${form}${st.rows.length ? `<table class="ac-table"><thead><tr><th>ชื่อ</th><th>กลุ่มสาระ</th>${w ? '<th>บัญชี</th>' : ''}<th>สถานะ</th>${w ? '<th></th>' : ''}</tr></thead><tbody>${rows}</tbody></table>` : '<p class="ac-lead">ยังไม่มีครู</p>'}`;
        },
        async click(act, b, h) {
            const id = Number(b.dataset.id);
            const ta = () => h.body.querySelector('textarea[name=text]');
            if (act === 'edit') { h.st.edit = id; h.draw(); return; }
            if (act === 'cancel') { h.st.edit = null; h.draw(); return; }
            if (act === 'delete') {
                const r = h.st.rows.find(x => x.id === id);
                if (!window.confirm('ลบครู ' + r.name + ' ?')) return;
                await h.api('/teachers/' + id, {method: 'DELETE', body: {version: r.version}});
                h.st.edit = null; await h.saved('ลบครูแล้ว'); h.changed(); return;
            }
            if (act === 'preview' || act === 'import') {
                h.st.text = ta()?.value ?? h.st.text;
                if (!h.st.text.trim()) throw Object.assign(new Error('กรุณาวางรายชื่อครูอย่างน้อยหนึ่งบรรทัด'), {status: 422});
                if (act === 'preview') { h.st.preview = await h.api('/teachers/bulk', {method: 'POST', body: {text: h.st.text, dry_run: true}}); h.draw(); h.clear(); return; }
                const r = await h.api('/teachers/bulk', {method: 'POST', body: {text: h.st.text}, idempotencyKey: window.SaanCore.uid()});
                h.st.text = ''; h.st.preview = null; await h.saved('นำเข้าครู ' + r.created.length + ' คน' + (r.skipped.length ? ' ข้าม ' + r.skipped.length + ' บรรทัด' : '')); h.changed();
            }
        },
        async submit(name, f, h) {
            const d = data(f);
            const edit = h.st.edit ? h.st.rows.find(r => r.id === h.st.edit) : null;
            const body = {prefix: nul(d.prefix), first_name: d.first_name, last_name: nul(d.last_name), subject_group: nul(d.subject_group)};
            if (edit) await h.api('/teachers/' + edit.id, {method: 'PUT', body: {...body, is_active: d.is_active !== '0', version: edit.version}});
            else await h.api('/teachers', {method: 'POST', body});
            h.st.edit = null; await h.saved('บันทึกครูแล้ว'); h.changed();
        },
    };

    // ------------------------------------------------------------- accounts
    const accountsDef = {
        async load(st, api) { st.rows = (await api('/teachers')).data.filter(t => t.is_active); },
        view(st) {
            if (!isAdmin()) return '<p class="ac-lead">การสร้างบัญชีครูทำได้เฉพาะผู้ดูแลระบบ ขั้นตอนนี้ไม่บังคับ ข้ามไปได้</p>';
            if (!st.rows.length) return '<p class="ac-lead">ยังไม่มีครู</p>';
            const rows = st.rows.map(t => `<tr><td data-label="ชื่อ">${esc(t.name)}</td>
                <td data-label="บัญชี">${t.has_account ? '<span class="ac-badge on">มีบัญชีแล้ว</span>'
                    : `<input type="email" data-email="${esc(t.id)}" placeholder="อีเมลของครู" aria-label="อีเมลของ ${esc(t.name)}" maxlength="254" autocomplete="off">`}</td>
                <td class="ac-act">${t.has_account ? '' : `<button type="button" class="ac-btn primary" data-act="invite" data-id="${esc(t.id)}">ส่งลิงก์เชิญ</button>`}</td></tr>`).join('');
            return `<p class="ac-lead">ระบบสร้างบัญชีและส่งลิงก์ตั้งรหัสผ่านทางอีเมล (ใช้ได้ 72 ชั่วโมง) ผู้ดูแลไม่ต้องรู้รหัสผ่านของครู ขั้นตอนนี้ไม่บังคับ</p>
                <table class="ac-table"><thead><tr><th>ชื่อ</th><th>อีเมล</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
        },
        async click(act, b, h) {
            if (act !== 'invite') return;
            const id = Number(b.dataset.id);
            const email = [...h.body.querySelectorAll('input[data-email]')].find(i => Number(i.dataset.email) === id)?.value.trim() ?? '';
            if (!email) throw Object.assign(new Error('กรุณากรอกอีเมลของครู'), {status: 422});
            const r = await h.api('/teachers/' + id + '/account', {method: 'POST', body: {email}, idempotencyKey: window.SaanCore.uid()});
            await h.saved(r.invite_sent ? 'สร้างบัญชีและส่งลิงก์เชิญไปที่ ' + r.email + ' แล้ว' : 'สร้างบัญชีแล้ว แต่ส่งอีเมลไม่สำเร็จ ส่งลิงก์ซ้ำได้จากหน้าจัดการบัญชีผู้ใช้');
            h.changed();
        },
    };

    // -------------------------------------------------------------- summary
    const summaryDef = {
        async load(st, api) { st.s = await api('/setup'); },
        view(st) {
            const s = st.s;
            const cur = s.term.current;
            const link = {term: 'terms', periods: 'periods', classrooms: 'classrooms', teachers: 'teachers', homerooms: 'setup/homerooms', accounts: 'setup/accounts'};
            const rows = s.steps.map(x => `<li><span>${esc(x.label)}: ${esc(x.count)}</span><span><span class="ac-badge ${x.done ? 'on' : 'off'}">${x.done ? 'เสร็จแล้ว' : (x.optional ? 'ข้ามได้' : 'ยังไม่ครบ')}</span>
                <a class="ac-btn" href="#${esc(link[x.key])}">เปิด</a></span></li>`).join('');
            return `<p class="ac-lead">${s.complete ? 'ข้อมูลพื้นฐานครบแล้ว พร้อมใช้งานตารางสอนและบัญชีครู' : 'ยังมีขั้นตอนที่ต้องทำให้ครบ'}</p>
                <ul class="ac-sum"><li><span>ภาคเรียนปัจจุบัน</span><strong>${cur ? esc(cur.year + '/' + cur.term) : 'ยังไม่ได้ตั้ง'}</strong></li>${rows}</ul>
                <p class="ac-lead">ห้องที่ยังไม่มีครูประจำชั้น: ${esc(s.classrooms.without_homeroom)} ห้อง</p>`;
        },
    };

    // ------------------------------------------------------------- calendar
    const calendarDef = {
        async load(st, api) {
            st.month = st.month || window.SaanCore.today().slice(0, 7);
            const [y, m] = st.month.split('-').map(Number);
            const last = new Date(y, m, 0).getDate();
            const [d, t] = await Promise.all([api(`/calendar?from=${st.month}-01&to=${st.month}-${String(last).padStart(2, '0')}`), api('/terms')]);
            st.rows = d.data; st.terms = t.data;
        },
        view(st) {
            const w = canWrite();
            const [y, m] = st.month.split('-').map(Number);
            const title = window.SaanCore.date(st.month + '-01', {month: 'long', year: 'numeric'});
            const edit = st.edit ? st.rows.find(r => r.id === st.edit) : null;
            const rows = st.rows.map(r => `<tr><td data-label="วันที่">${esc(date(r.date))}</td><td data-label="ชนิด"><span class="ac-badge">${esc(KIND_DAY[r.kind] || r.kind)}</span></td><td data-label="รายการ">${esc(r.title)}</td>
                ${w ? `<td class="ac-act"><button type="button" class="ac-btn" data-act="edit" data-id="${esc(r.id)}">แก้ไข</button><button type="button" class="ac-btn danger" data-act="delete" data-id="${esc(r.id)}">ลบ</button></td>` : ''}</tr>`).join('');
            const form = w ? `<form class="ac-card ac-form cols" data-form="day" novalidate><h3>${edit ? 'แก้ไขรายการ' : 'เพิ่มวันหยุดหรือกิจกรรม'}</h3>
                ${field('วันที่', input('date', edit?.date ?? '', 'type="date" required'))}
                ${field('ชนิด', `<select name="kind">${options(Object.entries(KIND_DAY), edit?.kind ?? 'holiday')}</select>`)}
                ${field('ชื่อรายการ', input('title', edit?.title ?? '', 'maxlength="160" required'))}
                ${field('ภาคเรียน (ไม่บังคับ)', `<select name="term_id">${options([['', 'ไม่ระบุ'], ...st.terms.map(t => [t.id, t.year + '/' + t.term])], edit?.term_id)}</select>`)}
                <div class="ac-actions"><button type="submit" class="ac-btn primary">บันทึก</button>${edit ? '<button type="button" class="ac-btn" data-act="cancel">ยกเลิกการแก้ไข</button>' : ''}</div></form>` : '';
            return `<div class="ac-actions"><button type="button" class="ac-btn" data-act="prev" aria-label="เดือนก่อนหน้า">เดือนก่อน</button><strong aria-live="polite">${esc(title)}</strong><button type="button" class="ac-btn" data-act="next" aria-label="เดือนถัดไป">เดือนถัดไป</button></div>
                ${st.rows.length ? `<table class="ac-table"><thead><tr><th>วันที่</th><th>ชนิด</th><th>รายการ</th>${w ? '<th></th>' : ''}</tr></thead><tbody>${rows}</tbody></table>` : `<p class="ac-lead">ไม่มีรายการในเดือนนี้ (${esc(y)}/${esc(m)})</p>`}${form}`;
        },
        async click(act, b, h) {
            const id = Number(b.dataset.id);
            if (act === 'prev' || act === 'next') {
                const [y, m] = h.st.month.split('-').map(Number);
                const d = new Date(y, m - 1 + (act === 'next' ? 1 : -1), 1);
                h.st.month = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'); h.st.edit = null;
                await h.refresh(); return;
            }
            if (act === 'edit') { h.st.edit = id; h.draw(); return; }
            if (act === 'cancel') { h.st.edit = null; h.draw(); return; }
            if (act === 'delete') {
                const r = h.st.rows.find(x => x.id === id);
                if (!window.confirm('ลบรายการ ' + r.title + ' ?')) return;
                await h.api('/calendar/' + id, {method: 'DELETE', body: {version: r.version}});
                h.st.edit = null; await h.saved('ลบรายการแล้ว');
            }
        },
        async submit(name, f, h) {
            const d = data(f);
            const edit = h.st.edit ? h.st.rows.find(r => r.id === h.st.edit) : null;
            const body = {date: d.date, kind: d.kind, title: d.title, term_id: num(d.term_id)};
            if (edit) await h.api('/calendar/' + edit.id, {method: 'PUT', body: {...body, version: edit.version}});
            else await h.api('/calendar', {method: 'POST', body});
            if (/^\d{4}-\d{2}/.test(d.date)) h.st.month = d.date.slice(0, 7);
            h.st.edit = null; await h.saved('บันทึกรายการแล้ว');
        },
    };

    // ---------------------------------------------------------------- pages
    function standalone(root, ctx, title, lead, def) {
        root.innerHTML = `<section class="ac-page glass"><h1>${esc(title)}</h1>${lead ? `<p class="ac-lead">${esc(lead)}</p>` : ''}
            ${canWrite() ? '<a class="ac-back" href="#setup">กลับไปขั้นตอนตั้งค่าโรงเรียน</a>' : ''}<div class="ac-box"></div></section>`;
        return editor(root.querySelector('.ac-box'), ctx, def);
    }

    const STEPS = {
        term: ['ปีการศึกษา/ภาคเรียน', 'กำหนดปีการศึกษาและภาคเรียน วันที่ต้องไม่ซ้อนกับภาคเรียนอื่น ภาคเรียนแรกจะเป็นภาคเรียนปัจจุบันให้อัตโนมัติ', termsDef],
        periods: ['คาบเวลา', 'กำหนดเวลาเริ่มและสิ้นสุดของแต่ละคาบ รวมเวลาพัก', periodsDef],
        classrooms: ['ห้องเรียน', 'สร้างห้องเรียนทีละห้องหรือหลายห้องพร้อมกันจากระดับชั้น', classroomsDef],
        teachers: ['ครู', 'เพิ่มครูทีละคนหรือวางรายชื่อจากเอกสาร', teachersDef],
        homerooms: ['ครูประจำชั้น', 'เลือกครูประจำชั้นของแต่ละห้อง', homeroomsDef],
        accounts: ['บัญชีครู (ไม่บังคับ)', 'ส่งลิงก์เชิญให้ครูตั้งรหัสผ่านเอง', accountsDef],
        summary: ['สรุป', 'ตรวจความพร้อมของข้อมูลพื้นฐานทั้งหมด', summaryDef],
    };
    const ORDER = Object.keys(STEPS);

    window.SaanWork.register('setup', {
        title: 'ตั้งค่าโรงเรียน',
        async render(root, ctx) {
            const status = await call(ctx, '/setup');
            if (!ctx.alive()) return;
            const key = ORDER.includes(ctx.parts[0]) ? ctx.parts[0] : status.next;
            const [label, lead, def] = STEPS[key];
            const i = ORDER.indexOf(key);
            root.innerHTML = `<section class="ac-wizard glass"><h1>ตั้งค่าโรงเรียน</h1><p class="ac-lead">ทำทีละขั้น บันทึกได้ทุกขั้นและกลับมาแก้ไขภายหลังได้</p>
                <nav aria-label="ขั้นตอนตั้งค่า"><ol class="ac-steps"></ol></nav><h2>ขั้นที่ ${esc(i + 1)} ${esc(label)}</h2><p class="ac-lead">${esc(lead)}</p><div class="ac-box"></div>
                <div class="ac-foot">${i > 0 ? `<a class="ac-btn" href="#setup/${esc(ORDER[i - 1])}">ย้อนกลับ</a>` : '<span></span>'}${i < ORDER.length - 1 ? `<a class="ac-btn primary" href="#setup/${esc(ORDER[i + 1])}">ถัดไป</a>` : '<span></span>'}</div></section>`;
            const drawSteps = s => {
                const rows = s.steps.map(x => [x.key, x.label, x.done, x.optional]).concat([['summary', 'สรุป', s.complete, false]]);
                root.querySelector('.ac-steps').innerHTML = rows.map(([k, l, done, optional], n) => `<li><a href="#setup/${esc(k)}" class="${done ? 'is-done' : 'is-todo'}" ${k === key ? 'aria-current="step"' : ''}>
                    <b>ขั้นที่ ${esc(n + 1)}</b><span>${esc(l)}</span><span class="ac-state">${done ? 'เสร็จแล้ว' : (optional ? 'ข้ามได้' : 'ยังไม่ครบ')}</span></a></li>`).join('');
            };
            drawSteps(status);
            const afterSave = async () => { try { const s = await call(ctx, '/setup'); if (ctx.alive()) drawSteps(s); } catch { /* the editor shows its own errors */ } };
            await editor(root.querySelector('.ac-box'), {...ctx, afterSave}, def);
        },
    });

    window.SaanWork.register('terms', {title: 'ปีการศึกษา/ภาคเรียน', render: (root, ctx) => standalone(root, ctx, 'ปีการศึกษา/ภาคเรียน', '', termsDef)});
    window.SaanWork.register('periods', {title: 'คาบเวลา', render: (root, ctx) => standalone(root, ctx, 'คาบเวลา', '', periodsDef)});
    window.SaanWork.register('classrooms', {title: 'ห้องเรียน', render: (root, ctx) => standalone(root, ctx, 'ห้องเรียน', '', classroomsDef)});
    window.SaanWork.register('teachers', {title: 'ครู', render: (root, ctx) => standalone(root, ctx, 'ครู', '', teachersDef)});
    window.SaanWork.register('calendar', {title: 'ปฏิทินโรงเรียน', render: (root, ctx) => standalone(root, ctx, 'ปฏิทินโรงเรียน', 'วันหยุด วันสอบ และกิจกรรมของโรงเรียน', calendarDef)});
})();
