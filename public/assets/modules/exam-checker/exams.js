'use strict';
// exam-checker module: page #exams for the /work shell.
//   #exams                     list + create
//   #exams/<id>/<tab>[/<classroom>]   tab = setup | entry | import | analysis | summary | slips
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const B = '/api/exam-checker';
    const LETTERS = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ'];
    const STATUS = {draft: 'ร่าง', open: 'เปิดรับใบตอบ', closed: 'ปิดแล้ว'};
    const TABS = [['entry', 'กรอกคำตอบ'], ['import', 'นำเข้า CSV'], ['analysis', 'วิเคราะห์ข้อสอบ'], ['summary', 'สรุปผล'], ['slips', 'ใบแจ้งผล'], ['setup', 'ตั้งค่าและเฉลย']];
    const role = (() => { try { return JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').role || ''; } catch { return ''; } })();
    const isAdmin = role === 'admin' || role === 'principal';
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const num = v => (v === null || v === undefined || v === '') ? '-' : String(Math.round(v * 100) / 100);
    const letter = c => LETTERS[c - 1] || '?';
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    };
    const mount = root => { const h = document.createElement('div'); h.className = 'exm'; root.append(h); return h; };

    function fail(err, reload) {
        if (err?.name === 'AbortError') return;
        if (err?.status === 409 && reload) {
            const box = document.createElement('div');
            box.className = 'exm-conflict'; box.setAttribute('role', 'alert');
            box.innerHTML = `<p>${esc(err.message)}</p><button type="button" class="exm-btn">โหลดข้อมูลล่าสุด</button>`;
            box.querySelector('button').addEventListener('click', () => { box.remove(); reload(); });
            document.querySelector('#work-view')?.prepend(box);
        } else toast(err?.message || 'เกิดข้อผิดพลาด', true);
    }

    // client mirror of the server tokenizer: only used for the live "n / N" counter, the server is the authority
    // explicit set, same as Scoring::THAI + BLANK + digits 1-6 + A-F + '*'
    const OK = /^[กขฃคฅงจฉ๐-๖0-6A-Fa-f*_.\-–]$/u;
    const THAI_NO = {ก: 1, ข: 2, ฃ: 2, ค: 3, ฅ: 3, ง: 4, จ: 5, ฉ: 6, '๑': 1, '๒': 2, '๓': 3, '๔': 4, '๕': 5, '๖': 6};
    function check(text, count, choices) {
        const t = [...String(text).replace(/[\s,;|]+/g, '')];
        let bad = false;
        const picks = t.map(ch => {
            if (!OK.test(ch)) { bad = true; return 0; }
            const m = THAI_NO[ch] ?? (/[1-6]/.test(ch) ? +ch : /[A-Fa-f]/.test(ch) ? ch.toUpperCase().charCodeAt(0) - 64 : 0);   // 0 = blank, -1 = multiple
            if (m > choices) bad = true;
            return ch === '*' ? -1 : m;
        });
        return {n: t.length, bad, picks, ok: !bad && t.length === count};
    }
    // mirror of Scorer::score for the live "~score" in the grid; the server remains the authority
    function liveScore(picks, key, ppq) {
        let right = 0;
        key.forEach((e, i) => {
            if (e === 'cancelled') right++;
            else if ((Array.isArray(e) ? e : [e]).includes(picks[i])) right++;
        });
        return Math.round(right * ppq * 100) / 100;
    }
    function keyCount(text) {
        let n = 0, inGroup = false;
        for (const ch of String(text).replace(/[\s,;|]+/g, '')) {
            if (ch === '(' || ch === '[') { inGroup = true; n++; } else if (ch === ')' || ch === ']') inGroup = false; else if (!inGroup) n++;
        }
        return n;
    }
    const sectionsToText = s => (s || []).map(x => `${x.label}: ${x.from}-${x.to}`).join('\n');
    function textToSections(text) {
        return String(text).split('\n').map(l => l.trim()).filter(Boolean).map(l => {
            const m = /^(.+?)\s*[:,]\s*(\d+)\s*[-,]\s*(\d+)$/.exec(l);
            if (!m) throw new Error('รูปแบบส่วนย่อยไม่ถูกต้อง: ' + l + ' (เช่น ส่วน ก: 1-10)');
            return {label: m[1].trim(), from: +m[2], to: +m[3]};
        });
    }

    const api = (ctx, url, o) => ctx.api(B + url, o);

    // ---------------------------------------------------------------- list
    async function list(host, ctx) {
        const reload = () => { host.remove(); render(document.querySelector('#work-view'), ctx); };
        host.innerHTML = '<p class="exm-count">กำลังโหลด…</p>';
        const [res, subj] = await Promise.all([api(ctx, '/exams'), api(ctx, '/subjects').catch(() => ({data: []}))]);
        if (!ctx.alive()) return;
        host.innerHTML = `<section class="exm-panel glass"><div class="exm-head"><h1>ตรวจข้อสอบ</h1><button type="button" class="exm-btn primary" data-act="new">สร้างข้อสอบ</button></div>
            <div id="exm-new" hidden></div>
            ${res.data.length ? `<ul class="exm-cards">${res.data.map(e => `<li><a class="exm-card" href="#exams/${e.id}/${e.status === 'open' ? 'entry' : e.can_manage ? 'setup' : 'summary'}">
                <strong>${esc(e.title)}</strong>
                <span>${esc(e.kind_label)}${e.subject_label ? ' | ' + esc(e.subject_label) : ''}${e.grade_level ? ' | ' + esc(e.grade_level) : ''}</span>
                <span>${e.question_count} ข้อ | เต็ม ${num(e.max_score)} | ใบตอบ ${e.sheet_count}${e.mean !== null ? ' | เฉลี่ย ' + num(e.mean) : ''}</span>
                <span class="exm-badge ${esc(e.status)}">${esc(STATUS[e.status] || e.status)}</span></a></li>`).join('')}</ul>`
                : '<p class="exm-count">ยังไม่มีข้อสอบในภาคเรียนนี้ กด "สร้างข้อสอบ" เพื่อเริ่ม</p>'}</section>`;
        const slot = host.querySelector('#exm-new');
        host.addEventListener('click', ev => {
            if (!ev.target.closest('[data-act=new]')) return;
            slot.hidden = !slot.hidden;
            if (!slot.hidden && !slot.firstChild) examForm(slot, ctx, null, subj.data, e => { if (e) location.hash = '#exams/' + e.id + '/setup'; });
        });
    }

    // ---------------------------------------------------------------- create / edit form
    function examForm(slot, ctx, exam, subjects, done) {
        const e = exam || {kind: 'quiz', question_count: 40, choices_per_question: 4, points_per_question: 1, title: '', subject_label: '', grade_level: '', answer_key: '', sections: []};
        const kinds = {midterm: 'กลางภาค', final: 'ปลายภาค', onet_practice: 'ฝึกทำ O-NET', quiz: 'แบบทดสอบย่อย', other: 'อื่น ๆ'};
        const lock = exam && exam.sheet_count > 0;
        slot.innerHTML = `<form class="exm-form" novalidate>
            <label class="exm-field"><span>ชื่อข้อสอบ</span><input name="title" required maxlength="200" value="${esc(e.title)}"></label>
            <label class="exm-field"><span>ประเภท</span><select name="kind">${Object.entries(kinds).map(([k, v]) => `<option value="${k}"${k === e.kind ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
            <label class="exm-field"><span>วิชา</span><input name="subject_label" maxlength="120" list="exm-subjects" value="${esc(e.subject_label || '')}"><datalist id="exm-subjects">${(subjects || []).map(s => `<option value="${esc(s)}">`).join('')}</datalist></label>
            <label class="exm-field"><span>ระดับชั้น</span><input name="grade_level" maxlength="40" value="${esc(e.grade_level || '')}"></label>
            <label class="exm-field"><span>จำนวนข้อ (1-200)</span><input name="question_count" type="number" min="1" max="200" value="${e.question_count}"${lock ? ' disabled' : ''}></label>
            <label class="exm-field"><span>ตัวเลือกต่อข้อ (2-6)</span><input name="choices_per_question" type="number" min="2" max="6" value="${e.choices_per_question}"${lock ? ' disabled' : ''}></label>
            <label class="exm-field"><span>คะแนนต่อข้อ</span><input name="points_per_question" type="number" min="0.01" max="100" step="0.01" value="${e.points_per_question}"></label>
            <label class="exm-field wide"><span>เฉลย <small>พิมพ์ต่อกัน เช่น 13241 หรือ กขคงก ; (23) = ตอบข้อใดก็ได้ ; x = ยกคะแนนให้ทุกคน</small></span>
                <textarea name="answer_key" rows="3" spellcheck="false" autocomplete="off">${esc(e.answer_key || '')}</textarea><small class="exm-live" id="key-live" aria-live="polite"></small></label>
            <label class="exm-field wide"><span>ส่วนย่อย (ไม่บังคับ) <small>บรรทัดละส่วน เช่น ส่วน ก: 1-10</small></span><textarea name="sections" rows="3" spellcheck="false">${esc(sectionsToText(e.sections))}</textarea></label>
            <div class="exm-actions wide"><button class="exm-btn primary" type="submit">${exam ? 'บันทึกการตั้งค่า' : 'สร้างข้อสอบ'}</button></div>
            ${lock ? '<p class="exm-count wide">มีใบตอบแล้ว จึงแก้จำนวนข้อและตัวเลือกไม่ได้ ถ้าแก้เฉลยหรือคะแนน ระบบจะตรวจให้ใหม่ทุกใบอัตโนมัติ</p>' : ''}</form>`;
        const form = slot.querySelector('form');
        const live = slot.querySelector('#key-live');
        const upd = () => {
            const n = keyCount(form.answer_key.value), want = +form.question_count.value || 0;
            live.textContent = form.answer_key.value.trim() ? `เฉลย ${n} / ${want} ข้อ${n === want ? '' : ' (ยังไม่ครบ)'}` : '';
            live.classList.toggle('bad', n !== want && !!form.answer_key.value.trim());
        };
        form.addEventListener('input', upd); upd();
        form.addEventListener('submit', async ev => {
            ev.preventDefault();
            const btn = form.querySelector('button[type=submit]'); btn.disabled = true;
            try {
                const body = {title: form.title.value, kind: form.kind.value, subject_label: form.subject_label.value || null, grade_level: form.grade_level.value || null,
                    points_per_question: +form.points_per_question.value, answer_key: form.answer_key.value.trim() || null, sections: textToSections(form.sections.value)};
                if (!lock) { body.question_count = +form.question_count.value; body.choices_per_question = +form.choices_per_question.value; }
                if (exam) body.version = exam.version;
                const res = await api(ctx, exam ? '/exams/' + exam.id : '/exams', {method: exam ? 'PUT' : 'POST', body, idempotencyKey: exam ? undefined : window.SaanCore.uid()});
                toast(res.rescored ? `บันทึกแล้ว ตรวจใหม่ ${res.rescored.total} ใบ (คะแนนเปลี่ยน ${res.rescored.changed} ใบ)` : 'บันทึกแล้ว');
                done(res.exam);
            } catch (err) { fail(err, () => done(null)); } finally { btn.disabled = false; }
        });
    }

    // ---------------------------------------------------------------- detail shell
    async function detail(host, ctx) {
        const id = ctx.parts[0], tab = ctx.parts[1] || 'entry';
        const reload = () => { host.remove(); render(document.querySelector('#work-view'), ctx); };
        const {exam} = await api(ctx, '/exams/' + encodeURIComponent(id));
        if (!ctx.alive()) return;
        const tabs = TABS.filter(([k]) => k !== 'setup' || exam.can_manage);
        host.innerHTML = `<section class="exm-panel glass"><div class="exm-head"><div><a href="#exams">&larr; ข้อสอบทั้งหมด</a><h1>${esc(exam.title)}</h1>
            <p class="exm-sub">${esc(exam.kind_label)}${exam.subject_label ? ' | ' + esc(exam.subject_label) : ''} | ${exam.question_count} ข้อ | เต็ม ${num(exam.max_score)} คะแนน <span class="exm-badge ${esc(exam.status)}">${esc(STATUS[exam.status] || exam.status)}</span></p></div></div>
            <nav class="exm-tabs" aria-label="ส่วนของข้อสอบ">${tabs.map(([k, l]) => `<a href="#exams/${encodeURIComponent(id)}/${k}"${k === tab ? ' aria-current="page"' : ''}>${esc(l)}</a>`).join('')}</nav>
            <div class="exm-body"></div></section>`;
        const body = host.querySelector('.exm-body');
        const env = {ctx, exam, body, reload};
        if (tab === 'setup' && exam.can_manage) await setup(env);
        else if (tab === 'import') importTab(env);
        else if (tab === 'analysis') await report(env, 'analysis');
        else if (tab === 'summary') await report(env, 'summary');
        else if (tab === 'slips') await slips(env);
        else await entry(env);
    }

    async function classroomPicker(env, withAll) {
        const {data} = await api(env.ctx, `/exams/${env.exam.id}/classrooms`);
        const cur = env.ctx.parts[2] || '';
        return {rooms: data, cur, html: `<label class="exm-field narrow"><span>ห้องเรียน</span><select id="exm-room">${withAll ? '<option value="">ทุกห้อง</option>' : '<option value="">เลือกห้อง</option>'}${data.map(c => `<option value="${c.id}"${String(c.id) === cur ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>`};
    }
    function bindPicker(env, tab) {
        env.body.querySelector('#exm-room')?.addEventListener('change', ev => { location.hash = `#exams/${env.exam.id}/${tab}${ev.target.value ? '/' + ev.target.value : ''}`; });
    }

    // ---------------------------------------------------------------- setup
    async function setup({ctx, exam, body, reload}) {
        const [shown, subj] = await Promise.all([api(ctx, `/exams/${exam.id}/sheets?per_page=1`), api(ctx, '/subjects').catch(() => ({data: []}))]);
        const total = shown.total;
        exam.sheet_count = total;
        body.innerHTML = '<div id="exm-edit"></div><div class="exm-actions" id="exm-state"></div><p class="exm-count" id="exm-msg"></p>';
        examForm(body.querySelector('#exm-edit'), ctx, exam, subj.data, reload);
        const st = body.querySelector('#exm-state');
        const mk = (label, status) => `<button type="button" class="exm-btn" data-status="${status}">${esc(label)}</button>`;
        st.innerHTML = (exam.status !== 'open' ? mk('เปิดรับใบตอบ', 'open') : '') + (exam.status === 'open' ? mk('ปิดข้อสอบ', 'closed') + mk('กลับเป็นร่าง', 'draft') : '')
            + (exam.status === 'closed' ? mk('เปิดอีกครั้ง', 'open') : '') + '<button type="button" class="exm-btn" data-act="rescore">ตรวจใหม่ทุกใบ</button>'
            + (total === 0 ? '<button type="button" class="exm-btn danger" data-act="delete">ลบข้อสอบ</button>' : '');
        st.addEventListener('click', async ev => {
            const b = ev.target.closest('button'); if (!b) return;
            try {
                if (b.dataset.status) { await api(ctx, '/exams/' + exam.id, {method: 'PUT', body: {version: exam.version, status: b.dataset.status}}); reload(); }
                else if (b.dataset.act === 'rescore') { const r = await api(ctx, `/exams/${exam.id}/rescore`, {method: 'POST'}); toast(`ตรวจใหม่ ${r.total} ใบ คะแนนเปลี่ยน ${r.changed} ใบ`); }
                else if (b.dataset.act === 'delete' && window.confirm('ลบข้อสอบนี้?')) { await api(ctx, '/exams/' + exam.id, {method: 'DELETE', body: {version: exam.version}}); location.hash = '#exams'; }
            } catch (err) { fail(err, reload); }
        });
    }

    // ---------------------------------------------------------------- fast entry grid
    async function entry({ctx, exam, body, reload}) {
        if (exam.status === 'closed') { body.innerHTML = '<p class="exm-alert" role="alert">ข้อสอบนี้ปิดรับใบตอบแล้ว</p>'; return; }
        if (!exam.key_complete) { body.innerHTML = '<p class="exm-alert" role="alert">ข้อสอบนี้ยังไม่มีเฉลยครบ ไปที่แท็บ "ตั้งค่าและเฉลย"</p>'; return; }
        const pick = await classroomPicker({ctx, exam}, false);
        body.innerHTML = `<div class="exm-bar">${pick.html}<p class="exm-sub">พิมพ์คำตอบต่อกัน เช่น ${esc(LETTERS.slice(0, exam.choices_per_question).join(''))}... หรือ 1234 หรือ ABCD ; - = ไม่ตอบ ; * = ตอบหลายข้อ ; กด Enter เพื่อบันทึกแถวและไปคนถัดไป</p></div><div id="exm-grid"></div>`;
        bindPicker({body, exam}, 'entry');
        if (!pick.cur) { body.querySelector('#exm-grid').innerHTML = '<p class="exm-count">เลือกห้องเรียนเพื่อเริ่มกรอก</p>'; return; }
        const {data} = await api(ctx, `/exams/${exam.id}/roster?classroom_id=${encodeURIComponent(pick.cur)}`);
        if (!ctx.alive()) return;
        const rows = new Map(data.map(s => [s.code, {...s, dirty: false}]));
        const grid = body.querySelector('#exm-grid');
        grid.innerHTML = `<div class="exm-table-wrap"><table class="exm-table exm-entry"><thead><tr><th>รหัส</th><th>ชื่อ</th><th>คำตอบ</th><th>จำนวน</th><th>คะแนน</th></tr></thead><tbody>${data.map(s => `<tr data-code="${esc(s.code)}">
            <td data-label="รหัส">${esc(s.code)}</td><td data-label="ชื่อ">${esc(s.name)}</td>
            <td data-label="คำตอบ"><input class="exm-ans" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="คำตอบของ ${esc(s.name)}" value="${esc(s.sheet ? s.sheet.answers : '')}"></td>
            <td data-label="จำนวน" class="cnt"></td><td data-label="คะแนน" class="sc">${s.sheet ? num(s.sheet.score) : '-'}</td></tr>`).join('')}</tbody></table></div>
            <div class="exm-actions"><button type="button" class="exm-btn primary" id="exm-save-all">บันทึกทั้งห้อง</button><span class="exm-count" id="exm-state-msg"></span></div>`;
        const tr = code => grid.querySelector(`tr[data-code="${CSS.escape(code)}"]`);
        const refresh = tr_ => {
            const row = rows.get(tr_.dataset.code), inp = tr_.querySelector('input'), c = check(inp.value, exam.question_count, exam.choices_per_question);
            const cnt = tr_.querySelector('.cnt');
            cnt.textContent = inp.value.trim() ? `${c.n} / ${exam.question_count}` : '';
            inp.classList.toggle('bad', !!inp.value.trim() && !c.ok);
            row.dirty = inp.value.trim() !== (row.sheet ? row.sheet.answers : '') && inp.value.trim() !== '';
            tr_.classList.toggle('dirty', row.dirty);
            tr_.classList.remove('err');
            const sc = tr_.querySelector('.sc');
            if (row.dirty && c.ok && Array.isArray(exam.key)) sc.textContent = '~' + num(liveScore(c.picks, exam.key, exam.points_per_question));
            else sc.textContent = row.sheet ? num(row.sheet.score) : '-';
        };
        grid.querySelectorAll('tr[data-code]').forEach(refresh);
        async function save(codes) {
            const targets = codes.map(c => rows.get(c)).filter(r => r && r.dirty && !r.saving);   // a row in flight is never sent twice
            const send = targets.map(r => {
                const o = {student_code: r.code, answers: grid.querySelector(`tr[data-code="${CSS.escape(r.code)}"] input`).value};
                if (r.sheet) o.version = r.sheet.version;
                return o;
            });
            if (!send.length) return;
            targets.forEach(r => { r.saving = true; });
            const sentAns = new Map(send.map(o => [o.student_code, o.answers])); // what was sent: typing done while the request ran must survive the answer
            try {
                const res = await api(ctx, `/exams/${exam.id}/sheets`, {method: 'POST', body: {rows: send}, idempotencyKey: window.SaanCore.uid()});
                res.saved.forEach(s => { const r = rows.get(s.student_code); if (!r) return; r.sheet = s; r.dirty = false; const t = tr(s.student_code); t.classList.remove('err'); const inp = t.querySelector('input');
                    if (inp.value === sentAns.get(s.student_code)) { t.querySelector('.sc').textContent = num(s.score); t.classList.remove('dirty'); inp.value = s.answers; } // untouched since sent
                    refresh(t); }); // edited meanwhile: keep the typed text, it stays dirty against the new saved version
                res.errors.forEach(e => { const t = tr(e.student_code); if (t) { t.classList.add('err'); t.querySelector('.sc').textContent = e.message; } });
                grid.querySelector('#exm-state-msg').textContent = `บันทึก ${res.created + res.updated} แถว${res.errors.length ? ' มีข้อผิดพลาด ' + res.errors.length + ' แถว' : ''}`;
            } catch (err) { fail(err, reload); } finally { targets.forEach(r => { r.saving = false; }); }
        }
        grid.addEventListener('input', ev => { const t = ev.target.closest('tr[data-code]'); if (t) refresh(t); });
        grid.addEventListener('keydown', ev => {
            if (ev.key !== 'Enter' || !ev.target.classList.contains('exm-ans')) return;
            ev.preventDefault();
            const t = ev.target.closest('tr');
            save([t.dataset.code]);   // no-op while that row is still being saved
            t.nextElementSibling?.querySelector('input')?.focus();
        });
        grid.querySelector('#exm-save-all').addEventListener('click', () => save([...rows.keys()]));
        grid.querySelector('input')?.focus();
    }

    // ---------------------------------------------------------------- CSV import
    function importTab({ctx, exam, body}) {
        const n = exam.question_count;
        body.innerHTML = `<form class="exm-form" id="exm-imp" novalidate>
            <label class="exm-field wide"><span>ไฟล์ CSV (UTF-8)</span><input type="file" name="file" accept=".csv,text/csv,text/plain"></label>
            <label class="exm-field wide"><span>หรือวางข้อมูล CSV</span><textarea name="csv" rows="5" spellcheck="false" placeholder="student_code,answers&#10;DEMO-01,กขคงก"></textarea></label>
            <label class="exm-check"><input type="checkbox" name="overwrite"> เขียนทับใบตอบที่มีอยู่แล้ว</label>
            ${isAdmin ? '<label class="exm-check"><input type="checkbox" name="allow_unknown"> รับรหัสที่ไม่มีในทะเบียน (ผู้ดูแล)</label>' : ''}
            <div class="exm-actions wide"><button type="button" class="exm-btn" data-dry="1">ตรวจสอบก่อนนำเข้า</button><button type="submit" class="exm-btn primary">นำเข้า</button></div></form>
            <div id="exm-imp-res" aria-live="polite"></div>
            <details class="exm-doc"><summary>รูปแบบไฟล์</summary>
                <p>แบบง่าย: คอลัมน์ 1 = รหัสนักเรียน, คอลัมน์ 2 = คำตอบ ${n} ข้อ (ก-ฉ, 1-6, A-F ; - = ไม่ตอบ ; * = หลายข้อ) มีหรือไม่มีแถวหัวตารางก็ได้</p>
                <p>แบบเครื่องสแกน OMR: แถวหัวตาราง + หนึ่งแถวต่อใบตอบ คอลัมน์รหัส (student_code, code, id, รหัสนักเรียน) คอลัมน์ชื่อ (name, ชื่อ) ไม่บังคับ และคำตอบเป็นคอลัมน์เดียว (answers) หรือหนึ่งคอลัมน์ต่อข้อ (q1..q${n} หรือ 1..${n} หรือ ข้อ1..) ช่องว่าง = ไม่ตอบ ช่องที่มีหลายตัวอักษร (เช่น AC) = ตอบหลายข้อ ตัวคั่น , ; หรือ Tab</p></details>`;
        const form = body.querySelector('#exm-imp'), out = body.querySelector('#exm-imp-res');
        async function run(dry, btn) {
            const fd = new FormData();
            if (form.file.files[0]) fd.append('file', form.file.files[0]); else fd.append('csv', form.csv.value);
            fd.append('overwrite', form.overwrite.checked ? '1' : '0');
            if (form.allow_unknown) fd.append('allow_unknown', form.allow_unknown.checked ? '1' : '0');
            if (dry) fd.append('dry_run', '1');
            btn.disabled = true;
            try {
                const r = await api(ctx, `/exams/${exam.id}/import`, {method: 'POST', body: fd, idempotencyKey: dry ? undefined : window.SaanCore.uid()});
                out.innerHTML = `<div class="exm-card static"><strong>${dry ? 'ผลการตรวจสอบ (ยังไม่บันทึก)' : 'นำเข้าแล้ว'}</strong>
                    <span>รูปแบบ ${r.format === 'omr_csv' ? 'OMR' : 'CSV ธรรมดา'} | ${r.rows} แถว | ใหม่ ${r.created} | อัปเดต ${r.updated} | ไม่เปลี่ยน ${r.unchanged} | ผิดพลาด ${r.errors.length}</span></div>
                    ${r.errors.length ? `<div class="exm-table-wrap"><table class="exm-table"><thead><tr><th>แถว</th><th>รหัส</th><th>ปัญหา</th></tr></thead><tbody>${r.errors.map(e => `<tr><td data-label="แถว">${esc(e.row)}</td><td data-label="รหัส">${esc(e.student_code)}</td><td data-label="ปัญหา">${esc(e.message)}</td></tr>`).join('')}</tbody></table></div>` : ''}`;
            } catch (err) { fail(err); } finally { btn.disabled = false; }
        }
        form.addEventListener('submit', ev => { ev.preventDefault(); run(false, form.querySelector('button[type=submit]')); });
        form.querySelector('[data-dry]').addEventListener('click', ev => run(true, ev.currentTarget));
    }

    // ---------------------------------------------------------------- analysis + summary
    async function report(env, kind) {
        const {ctx, exam, body} = env;
        const pick = await classroomPicker(env, true);
        const q = pick.cur ? '?classroom_id=' + encodeURIComponent(pick.cur) : '';
        const r = await api(ctx, `/exams/${exam.id}/${kind}${q}`);
        if (!ctx.alive()) return;
        const exportUrl = base() + `${B}/exams/${exam.id}/export${q}`;
        let html = `<div class="exm-bar">${pick.html}<a class="exm-btn" href="${esc(exportUrl)}">ส่งออก CSV</a></div>`;
        if (kind === 'analysis') {
            html += `<p class="exm-count">ใบตอบ ${r.sheets} ใบ | กลุ่มสูง/ต่ำ ${r.group_size} ใบ (27%) | p = สัดส่วนผู้ตอบถูก, D = (ถูกกลุ่มสูง - ถูกกลุ่มต่ำ) / ขนาดกลุ่ม</p>
            <div class="exm-table-wrap"><table class="exm-table"><thead><tr><th>ข้อ</th><th>เฉลย</th><th>ถูก %</th><th>p</th><th>D</th>${LETTERS.slice(0, exam.choices_per_question).map(l => `<th>${l}</th>`).join('')}<th>เว้น</th><th>หลายข้อ</th><th>หมายเหตุ</th></tr></thead><tbody>
            ${r.items.map(i => `<tr class="${i.review ? 'review' : ''}"><td data-label="ข้อ">${i.q}</td><td data-label="เฉลย">${i.cancelled ? 'ยกเลิก' : esc(i.key.map(letter).join('/'))}</td>
                <td data-label="ถูก %">${num(i.pct)}</td><td data-label="p">${num(i.p)} ${i.p_label ? '<small>' + esc(i.p_label) + '</small>' : ''}</td><td data-label="D">${num(i.d)} ${i.d_label ? '<small>' + esc(i.d_label) + '</small>' : ''}</td>
                ${i.choices.map((c, k) => `<td data-label="${letter(k + 1)}" class="${!i.cancelled && i.key.includes(k + 1) ? 'key' : ''}">${c}</td>`).join('')}
                <td data-label="เว้น">${i.blank}</td><td data-label="หลายข้อ">${i.multiple}</td><td data-label="หมายเหตุ">${i.review ? 'ควรทบทวนข้อนี้' : ''}</td></tr>`).join('')}</tbody></table></div>`;
            if (!pick.cur && r.classrooms.length > 1) {
                html += `<h2>ร้อยละผู้ตอบถูกแยกห้อง</h2><div class="exm-table-wrap"><table class="exm-table"><thead><tr><th>ห้อง</th><th>ใบตอบ</th>${r.items.map(i => `<th>${i.q}</th>`).join('')}</tr></thead><tbody>
                ${r.classrooms.map(c => `<tr><td data-label="ห้อง">${esc(c.label)}</td><td data-label="ใบตอบ">${c.n}</td>${c.p.map((p, k) => `<td data-label="ข้อ ${k + 1}">${p === null ? '-' : Math.round(p * 100)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
            }
        } else {
            const o = r.overall, maxCount = Math.max(1, ...r.distribution.map(b => b.count));
            html += `<dl class="exm-stats">${[['ใบตอบ', o.n], ['ค่าเฉลี่ย', num(o.mean)], ['ร้อยละเฉลี่ย', num(o.mean_pct)], ['S.D.', num(o.sd)], ['มัธยฐาน', num(o.median)], ['ต่ำสุด', num(o.min)], ['สูงสุด', num(o.max)], ['คะแนนเต็ม', num(r.max_score)]]
                .map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl>
            <h2>การกระจายคะแนน (ร้อยละของคะแนนเต็ม)</h2><ul class="exm-dist">${r.distribution.map(b => `<li><span>${esc(b.label)}</span><i style="width:${Math.round(b.count / maxCount * 100)}%"></i><b>${b.count}</b></li>`).join('')}</ul>
            <h2>แยกตามห้อง</h2><div class="exm-table-wrap"><table class="exm-table"><thead><tr><th>ห้อง</th><th>ใบตอบ</th><th>เฉลี่ย</th><th>ร้อยละ</th><th>S.D.</th><th>ต่ำสุด</th><th>สูงสุด</th></tr></thead><tbody>
            ${r.classrooms.map(c => `<tr><td data-label="ห้อง">${esc(c.label)}</td><td data-label="ใบตอบ">${c.n}</td><td data-label="เฉลี่ย">${num(c.mean)}</td><td data-label="ร้อยละ">${num(c.mean_pct)}</td><td data-label="S.D.">${num(c.sd)}</td><td data-label="ต่ำสุด">${num(c.min)}</td><td data-label="สูงสุด">${num(c.max)}</td></tr>`).join('')}</tbody></table></div>
            ${r.sections.length ? `<h2>ค่าเฉลี่ยแต่ละส่วน</h2><ul class="exm-plain">${r.sections.map(s => `<li>${esc(s.label)}: ${num(s.mean)} / ${num(s.max)}</li>`).join('')}</ul>` : ''}`;
        }
        body.innerHTML = html;
        bindPicker(env, kind);
    }

    async function slips(env) {
        const pick = await classroomPicker(env, true);
        const q = pick.cur ? '?classroom_id=' + encodeURIComponent(pick.cur) : '';
        env.body.innerHTML = `<div class="exm-bar">${pick.html}<a class="exm-btn primary" target="_blank" rel="noopener" href="${esc(base() + '/exam-checker/exams/' + env.exam.id + '/slips' + q)}">เปิดใบแจ้งผล A4 เพื่อพิมพ์</a></div>
            <p class="exm-count">ใบแจ้งผลหนึ่งใบต่อหนึ่งหน้า A4 แสดงคะแนน คะแนนแต่ละส่วน และข้อที่ทำผิด (สูงสุด 300 ใบต่อครั้ง)</p>`;
        bindPicker(env, 'slips');
    }

    // ---------------------------------------------------------------- entry point
    async function render(root, ctx) {
        const host = mount(root);
        try {
            if (ctx.parts[0] && /^\d+$/.test(ctx.parts[0])) await detail(host, ctx); else await list(host, ctx);
        } catch (err) {
            if (err?.name === 'AbortError' || !ctx.alive()) return;
            host.innerHTML = `<section class="exm-panel glass" role="alert">${esc(err?.message || 'เกิดข้อผิดพลาด')}</section>`;
        }
    }
    window.SaanWork.register('exams', {title: 'ตรวจข้อสอบ', render});
})();
