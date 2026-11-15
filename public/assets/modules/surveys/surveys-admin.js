'use strict';
// surveys module: CMS screen #surveys (list) and #surveys/<id> (builder, results, roster). Uses the CMS globals request/CMS at call time.
(() => {
    window.SaanModulePages = (window.SaanModulePages || []).concat(['surveys']);
    const previous = window.CmsComplete?.render; if (!previous) return;
    const esc = s => window.SaanCore.esc(s);
    const MODES = {anonymous: 'ไม่ระบุตัวตน (คุกกี้ + จำกัดต่อ IP ไม่รับรองหนึ่งคนหนึ่งครั้ง)', name_email: 'กรอกชื่อ + อีเมล (ต้องกดลิงก์ยืนยันทางอีเมล)', roster_code: 'รหัสตามรายชื่อ (ใช้ได้ครั้งเดียว)'};
    const TYPES = {single: 'เลือกข้อเดียว', multi: 'เลือกได้หลายข้อ', scale: 'ระดับคะแนน', text: 'ข้อความ'};
    const STATUS = {draft: ['ฉบับร่าง', 'warm'], open: ['เปิดรับคำตอบ', ''], closed: ['ปิดแล้ว', 'warm']};
    const toast = (m, bad) => { const t = document.querySelector('#cms-toast'); if (t) { t.textContent = m; t.classList.toggle('error', !!bad); t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 3200); } };
    const errText = err => { const e = err?.errors ? Object.values(err.errors).flat().join(' ') : ''; return e || err?.message || 'เกิดข้อผิดพลาด'; };
    const pubUrl = slug => CMS.base + '/portal/surveys/' + slug;

    async function list(root, route) {
        const data = await request('/api/surveys'); if (location.hash !== route) return;
        root.innerHTML = `<div class="cms-heading"><div><h1>แบบสอบถามและเกียรติบัตร</h1><p>สร้างแบบสอบถามสาธารณะแบบไม่ต้องเข้าระบบ หนึ่งคนหนึ่งคำตอบ พร้อมเกียรติบัตรที่ตรวจสอบเลขที่ได้ที่ <a href="${esc(CMS.base)}/portal/certificates/verify" target="_blank" rel="noopener">/portal/certificates/verify</a></p></div></div>
            <section class="desk-editor-card glass"><h2>สร้างแบบสอบถามใหม่</h2><form id="srv-create" class="desk-form"><label class="desk-field"><span>ชื่อแบบสอบถาม</span><input name="title" maxlength="200" required></label>
            <label class="desk-field"><span>การระบุตัวตนผู้ตอบ</span><select name="identity_mode">${Object.entries(MODES).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}</select></label><button class="primary" type="submit">สร้างฉบับร่าง</button></form></section>
            <section class="desk-editor-card glass"><h2>แบบสอบถามทั้งหมด</h2>${data.data.length ? `<div class="srv-cards">${data.data.map(s => `<div class="srv-card"><div><span class="tag ${STATUS[s.status][1]}">${STATUS[s.status][0]}</span> <strong>${esc(s.title)}</strong></div><small>${s.responses} คำตอบ · ${s.question_count} ข้อ · ${esc(MODES[s.identity_mode] || '')}</small><small class="srv-link">${esc(pubUrl(s.slug))}</small><div><a class="quiet-button" href="#surveys/${s.id}">แก้ไข / ดูผล</a></div></div>`).join('')}</div>` : '<p class="desk-help">ยังไม่มีแบบสอบถาม</p>'}</section>`;
        root.querySelector('#srv-create').onsubmit = async e => {
            e.preventDefault(); const f = e.currentTarget; if (!f.reportValidity()) return;
            try { const r = await request('/api/surveys', 'POST', {title: f.elements.title.value.trim(), identity_mode: f.elements.identity_mode.value}); if (location.hash === route) location.hash = 'surveys/' + r.id; }
            catch (err) { toast(errText(err), true); }
        };
    }

    const nextKey = qs => { let n = qs.length + 1; const used = new Set(qs.map(q => q.key)); while (used.has('q' + n)) n++; return 'q' + n; };

    function questionBlock(q, i, locked) {
        const dis = locked ? 'disabled' : '', choice = q.type === 'single' || q.type === 'multi';
        const correct = q.type === 'multi' ? (q.correct || []) : (q.correct ? [q.correct] : []);
        return `<div class="srv-qrow" data-q="${i}" data-key="${esc(q.key)}"><div class="srv-qgrid"><label class="desk-field"><span>ข้อ ${i + 1} คำถาม</span><input data-f="label" value="${esc(q.label)}" maxlength="500" required></label>
            <label class="desk-field"><span>ชนิด</span><select data-f="type" ${dis}>${Object.entries(TYPES).map(([k, v]) => `<option value="${k}" ${q.type === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
            <label class="desk-field"><span>จำเป็นต้องตอบ</span><select data-f="required" ${dis}><option value="1" ${q.required ? 'selected' : ''}>จำเป็น</option><option value="0" ${q.required ? '' : 'selected'}>ไม่บังคับ</option></select></label></div>
            ${choice ? `<div class="srv-grid2"><label class="desk-field"><span>ตัวเลือก (หนึ่งบรรทัดต่อหนึ่งตัวเลือก)</span><textarea data-f="options" rows="4" ${dis}>${esc((q.options || []).join('\n'))}</textarea></label>
            <label class="desk-field"><span>เฉลย (ถ้าต้องการให้คะแนนอัตโนมัติ ${q.type === 'multi' ? 'หนึ่งบรรทัดต่อคำตอบที่ถูก' : 'พิมพ์ตัวเลือกที่ถูก'})</span><textarea data-f="correct" rows="3" ${dis}>${esc(correct.join('\n'))}</textarea></label></div>` : ''}
            ${q.type === 'scale' ? `<label class="desk-field"><span>ระดับสูงสุด (เริ่มที่ 1)</span><select data-f="max" ${dis}>${[3, 4, 5, 6, 7, 8, 9, 10].map(n => `<option value="${n}" ${(q.max || 5) === n ? 'selected' : ''}>${n}</option>`).join('')}</select></label>` : ''}
            <div class="srv-qactions"><button type="button" data-move="-1" ${dis}>เลื่อนขึ้น</button><button type="button" data-move="1" ${dis}>เลื่อนลง</button><button type="button" data-remove ${dis}>ลบข้อนี้</button></div></div>`;
    }

    async function editor(root, id, route) {
        let s = await request('/api/surveys/' + id); if (location.hash !== route) return;
        let questions = s.questions.map(q => ({...q}));
        const locked = () => !!s.locked, hasResponses = () => s.responses > 0;
        // CMS request() hides the HTTP status, so after any failed save/status re-read the survey: a newer version means someone saved first (409).
        // Load that copy so the next save does not conflict forever.
        const reloadAfterConflict = async () => { try { const latest = await request('/api/surveys/' + id); if (latest.version === s.version || location.hash !== route) return false; s = latest; questions = s.questions.map(q => ({...q})); draw(); root.querySelector('#srv-error').textContent = 'มีผู้แก้ไขแบบสอบถามนี้ก่อนหน้า ระบบโหลดฉบับล่าสุดแล้ว กรุณาตรวจและบันทึกอีกครั้ง'; return true; } catch { return false; } };
        const collectQuestions = () => [...root.querySelectorAll('[data-q]')].map(r => {
            const g = f => r.querySelector(`[data-f=${f}]`), lines = f => (g(f)?.value || '').split('\n').map(x => x.trim()).filter(Boolean), type = g('type').value;
            const q = {key: r.dataset.key, type, label: g('label').value.trim(), required: g('required').value === '1'};
            if (type === 'single' || type === 'multi') { q.options = lines('options'); const c = lines('correct'); if (c.length) q.correct = type === 'single' ? c[0] : c; }
            if (type === 'scale') q.max = Number(g('max')?.value || 5);
            return q;
        });
        const payload = () => {
            const f = root.querySelector('#srv-form').elements;
            const cert = f.cert_enabled.checked ? {enabled: true, title: f.cert_title.value.trim(), body_template: f.cert_body.value.trim(), signer_name: f.cert_signer.value.trim(), signer_title: f.cert_signer_title.value.trim(), min_score: f.cert_min.value === '' ? null : Number(f.cert_min.value)} : null;
            return {version: s.version, title: f.title.value.trim(), slug: f.slug.value.trim() || null, intro: f.intro.value, identity_mode: f.identity_mode.value, opens_at: f.opens_at.value || null, closes_at: f.closes_at.value || null,
                max_responses: f.max_responses.value === '' ? null : Number(f.max_responses.value), anon_ip_hour: f.anon_ip_hour.value === '' ? null : Number(f.anon_ip_hour.value), questions: collectQuestions(), certificate: cert};
        };
        // A redraw rebuilds the form from saved state; keepDraft copies the typed general/certificate fields so draw() can show them again (one use).
        let draft = null;
        const keepDraft = () => { const f = root.querySelector('#srv-form')?.elements; if (!f) return; draft = {title: f.title.value, slug: f.slug.value, intro: f.intro.value, identity_mode: f.identity_mode.value, opens_at: f.opens_at.value, closes_at: f.closes_at.value, max_responses: f.max_responses.value, anon_ip_hour: f.anon_ip_hour.value, certificate: {enabled: f.cert_enabled.checked, title: f.cert_title.value, body_template: f.cert_body.value, signer_name: f.cert_signer.value, signer_title: f.cert_signer_title.value, min_score: f.cert_min.value}}; };
        const draw = () => {
            const d = {...s, ...(draft || {})}; draft = null;
            const c = d.certificate || {}, lock = locked(), st = STATUS[s.status];
            root.innerHTML = `<div class="cms-heading"><div><h1>${esc(s.title)}</h1><p><span class="tag ${st[1]}">${st[0]}</span> ${s.responses} คำตอบ · <span class="srv-link">${esc(pubUrl(s.slug))}</span></p></div><div class="cms-heading-actions"><a class="quiet-button" href="#surveys">กลับรายการ</a><a class="quiet-button" href="${esc(pubUrl(s.slug))}" target="_blank" rel="noopener">เปิดหน้าสาธารณะ</a></div></div>
            <p id="srv-error" class="desk-error" role="alert"></p>
            <form id="srv-form"><section class="desk-editor-card glass"><h2>ข้อมูลทั่วไป</h2>
                <label class="desk-field"><span>ชื่อแบบสอบถาม</span><input name="title" value="${esc(d.title)}" maxlength="200" required></label>
                <label class="desk-field"><span>ที่อยู่ในลิงก์ (a-z 0-9 และ - แก้ได้เฉพาะฉบับร่าง)</span><input name="slug" value="${esc(d.slug)}" maxlength="80" pattern="[a-z0-9][a-z0-9-]{1,78}[a-z0-9]" ${s.status === 'draft' ? '' : 'readonly'}></label>
                <label class="desk-field"><span>คำชี้แจง</span><textarea name="intro" rows="3" maxlength="4000">${esc(d.intro)}</textarea></label>
                <label class="desk-field"><span>การระบุตัวตน${lock ? ' (ล็อกเพราะมีผู้ตอบหรือมีลิงก์ยืนยันค้างอยู่)' : ''}</span><select name="identity_mode" ${lock ? 'disabled' : ''}>${Object.entries(MODES).map(([k, v]) => `<option value="${k}" ${d.identity_mode === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
                <div class="srv-grid2"><label class="desk-field"><span>เปิดรับตั้งแต่ (เวลาไทย)</span><input type="datetime-local" name="opens_at" value="${esc(d.opens_at || '')}"></label><label class="desk-field"><span>ปิดรับเมื่อ (เวลาไทย)</span><input type="datetime-local" name="closes_at" value="${esc(d.closes_at || '')}"></label></div>
                <label class="desk-field"><span>จำนวนคำตอบสูงสุด (เว้นว่าง = ไม่จำกัด)</span><input type="number" name="max_responses" min="1" max="1000000" value="${d.max_responses ?? ''}"></label>
                <label class="desk-field"><span>โหมดไม่ระบุตัวตน: จำนวนคำตอบสูงสุดต่อ IP ต่อชั่วโมง (มือถือและห้องคอมพิวเตอร์มักใช้ IP ร่วมกัน จึงตั้งไว้สูง)</span><input type="number" name="anon_ip_hour" min="1" max="100000" value="${d.anon_ip_hour ?? 300}"></label></section>
            <section class="desk-editor-card glass"><h2>คำถาม</h2>${lock ? '<p class="desk-help">มีผู้ตอบหรือมีลิงก์ยืนยันที่ยังไม่หมดอายุ แก้ได้เฉพาะข้อความคำถาม ไม่สามารถเพิ่ม ลบ หรือเปลี่ยนชนิด/ตัวเลือก</p>' : ''}<div id="srv-questions">${questions.map((q, i) => questionBlock(q, i, lock)).join('') || '<p class="desk-help">ยังไม่มีคำถาม</p>'}</div>
                <div class="srv-qactions">${['single', 'multi', 'scale', 'text'].map(t => `<button type="button" data-add="${t}" ${lock ? 'disabled' : ''}>เพิ่มข้อ: ${TYPES[t]}</button>`).join('')}</div></section>
            <section class="desk-editor-card glass"><h2>เกียรติบัตร</h2><label class="desk-field"><span><input type="checkbox" name="cert_enabled" ${c.enabled ? 'checked' : ''}> ออกเกียรติบัตรให้ผู้ตอบ (ต้องใช้โหมดกรอกชื่อ+อีเมล หรือรหัสรายชื่อ)</span></label>
                <label class="desk-field"><span>หัวเกียรติบัตร</span><input name="cert_title" value="${esc(c.title || 'เกียรติบัตร')}" maxlength="200"></label>
                <label class="desk-field"><span>ข้อความ (ใช้ {name} {survey} {date} {no} {school})</span><textarea name="cert_body" rows="3" maxlength="1000">${esc(c.body_template || 'ขอมอบเกียรติบัตรฉบับนี้ให้ไว้เพื่อแสดงว่า {name} ได้ตอบ{survey}เรียบร้อยแล้ว')}</textarea></label>
                <div class="srv-grid2"><label class="desk-field"><span>ชื่อผู้ลงนาม</span><input name="cert_signer" value="${esc(c.signer_name || '')}" maxlength="160"></label><label class="desk-field"><span>ตำแหน่งผู้ลงนาม</span><input name="cert_signer_title" value="${esc(c.signer_title || '')}" maxlength="160"></label></div>
                <label class="desk-field"><span>คะแนนขั้นต่ำเป็นร้อยละ (ต้องตั้งเฉลยอย่างน้อยหนึ่งข้อ เว้นว่าง = ทุกคนที่ตอบได้รับ)</span><input type="number" name="cert_min" min="0" max="100" value="${c.min_score ?? ''}"></label></section>
            <div class="desk-form-actions srv-actions"><button type="submit" class="primary">บันทึก</button>
                ${s.status !== 'open' ? '<button type="button" data-status="open">เปิดรับคำตอบ</button>' : '<button type="button" data-status="close">ปิดรับคำตอบ</button>'}
                ${s.status === 'closed' && !hasResponses() ? '<button type="button" data-status="draft">กลับเป็นฉบับร่าง</button>' : ''}${hasResponses() ? '' : '<button type="button" id="srv-delete">ลบแบบสอบถาม</button>'}</div></form>
            ${s.identity_mode === 'roster_code' ? `<section class="desk-editor-card glass"><h2>รายชื่อผู้มีสิทธิ์ตอบ</h2><p class="desk-help" id="srv-roster-stat">กำลังอ่าน…</p><p class="desk-help">วางข้อมูลบรรทัดละหนึ่งคน รูปแบบ <code>รหัส,ชื่อ-สกุล</code> (คั่นด้วยจุลภาค อัฒภาค หรือแท็บ) รหัสยาว 3-40 ตัวอักษร (A-Z 0-9 . _ -) ระบบเก็บเฉพาะค่าแฮชของรหัส จึงดูรหัสย้อนหลังไม่ได้ ให้แจกรหัสจากไฟล์ต้นฉบับของท่าน</p>
                <label class="desk-field"><span>วางรายชื่อ</span><textarea id="srv-roster-text" rows="6"></textarea></label><div class="srv-actions"><button type="button" id="srv-roster-import">นำเข้ารายชื่อ</button></div></section>` : ''}
            <section class="desk-editor-card glass"><h2>ผลสรุป</h2><div id="srv-results"><p class="desk-help">กำลังอ่านผล…</p></div>
                <div class="srv-actions"><button type="button" id="srv-refresh">โหลดผลใหม่</button><a class="quiet-button" href="${esc(CMS.base)}/api/surveys/${s.id}/export" download>ดาวน์โหลด CSV${s.identity_mode === 'anonymous' ? '' : ' (มีชื่อผู้ตอบ)'}</a></div>
                ${hasResponses() ? `<h3>ลบข้อมูลผู้ตอบ (ตามคำขอ)</h3><p class="desk-help">ดาวน์โหลด CSV พร้อมรหัสคำตอบ แล้วใส่รหัสเพื่อลบคำตอบเดียว ลบแล้วกู้คืนไม่ได้ และเกียรติบัตรของคำตอบนั้นจะตรวจสอบไม่ได้อีก</p>
                <div class="srv-actions"><a class="quiet-button" href="${esc(CMS.base)}/api/surveys/${s.id}/export?with_ids=1" download>CSV พร้อมรหัสคำตอบ</a>
                <input id="srv-erase-id" type="number" min="1" placeholder="รหัสคำตอบ" aria-label="รหัสคำตอบที่จะลบ"><button type="button" id="srv-erase">ลบคำตอบนี้</button>
                ${s.status === 'closed' ? '<button type="button" id="srv-purge">ลบคำตอบทั้งหมดของแบบสอบถามนี้</button>' : '<span class="desk-help">ปิดรับคำตอบก่อนจึงลบทั้งหมดได้</span>'}</div>` : ''}</section>`;
            bind();
        };
        const bind = () => {
            const err = m => { root.querySelector('#srv-error').textContent = m || ''; };
            const form = root.querySelector('#srv-form');
            const redraw = () => { questions = collectQuestions(); draw(); };
            root.querySelectorAll('[data-add]').forEach(b => b.onclick = () => { keepDraft(); questions = collectQuestions(); const t = b.dataset.add; questions.push({key: nextKey(questions), type: t, label: '', required: false, ...(t === 'single' || t === 'multi' ? {options: ['ตัวเลือก 1', 'ตัวเลือก 2']} : {}), ...(t === 'scale' ? {max: 5} : {})}); draw(); });
            root.querySelectorAll('[data-q]').forEach(r => { const i = Number(r.dataset.q);
                r.querySelector('[data-remove]').onclick = () => { keepDraft(); questions = collectQuestions(); questions.splice(i, 1); draw(); };
                r.querySelectorAll('[data-move]').forEach(b => b.onclick = () => { keepDraft(); questions = collectQuestions(); const j = i + Number(b.dataset.move); if (j < 0 || j >= questions.length) return; [questions[i], questions[j]] = [questions[j], questions[i]]; draw(); });
                r.querySelector('[data-f=type]').onchange = () => { keepDraft(); const q = collectQuestions(); if ((q[i].type === 'single' || q[i].type === 'multi') && !q[i].options?.length) q[i].options = ['ตัวเลือก 1', 'ตัวเลือก 2']; questions = q; draw(); };
            });
            const save = async quiet => {
                if (!form.reportValidity()) return false; err('');
                try { s = await request('/api/surveys/' + id, 'PUT', payload()); if (location.hash !== route) return false; questions = s.questions.map(q => ({...q})); if (!quiet) { draw(); toast('บันทึกแบบสอบถามแล้ว'); } return true; }
                catch (e) { if (await reloadAfterConflict(e)) return false; err(errText(e)); return false; }
            };
            form.onsubmit = async e => { e.preventDefault(); await save(false); };
            root.querySelectorAll('[data-status]').forEach(b => b.onclick = async () => {
                if (!(await save(true))) return;
                try { s = await request('/api/surveys/' + id + '/status', 'POST', {version: s.version, action: b.dataset.status}); if (location.hash !== route) return; questions = s.questions.map(q => ({...q})); draw(); toast('อัปเดตสถานะแล้ว'); }
                catch (e) { if (await reloadAfterConflict(e)) return; err(errText(e)); }
            });
            const del = root.querySelector('#srv-delete');
            if (del) del.onclick = async () => { if (!confirm('ลบแบบสอบถามนี้ถาวร?')) return; try { await request('/api/surveys/' + id, 'DELETE', {version: s.version}); if (location.hash === route) location.hash = 'surveys'; } catch (e) { err(errText(e)); } };
            const refetch = async () => { keepDraft(); const kept = draft; draft = null; s = await request('/api/surveys/' + id); if (location.hash !== route) return false; questions = s.questions.map(q => ({...q})); draft = kept; draw(); return true; };
            const erase = root.querySelector('#srv-erase');
            if (erase) erase.onclick = async () => { const rid = Number(root.querySelector('#srv-erase-id').value); if (!rid) return err('ใส่รหัสคำตอบก่อน');
                if (!confirm('ลบคำตอบรหัส ' + rid + ' ถาวร?')) return;
                try { await request('/api/surveys/' + id + '/responses/' + rid, 'DELETE'); if (location.hash !== route) return; toast('ลบคำตอบแล้ว'); await refetch(); } catch (e) { err(errText(e)); } };
            const purge = root.querySelector('#srv-purge');
            if (purge) purge.onclick = async () => { const want = 'ลบคำตอบ ' + s.title; const typed = prompt('ลบคำตอบและรายชื่อผู้ตอบทั้งหมดถาวร พิมพ์ให้ตรง: ' + want); if (typed === null) return;
                try { const r = await request('/api/surveys/' + id + '/purge', 'POST', {confirm: typed}); if (location.hash !== route) return; toast('ลบคำตอบแล้ว ' + r.deleted + ' รายการ'); await refetch(); } catch (e) { err(errText(e)); } };
            const loadRoster = async () => { const el = root.querySelector('#srv-roster-stat'); if (!el) return; try { const r = await request('/api/surveys/' + id + '/roster'); if (el.isConnected) el.textContent = `ในรายชื่อ ${r.total} คน ใช้รหัสแล้ว ${r.used} คน`; } catch (e) { el.textContent = errText(e); } };
            const imp = root.querySelector('#srv-roster-import');
            if (imp) { loadRoster(); imp.onclick = async () => { const t = root.querySelector('#srv-roster-text'); if (!t.value.trim()) return; try { const r = await request('/api/surveys/' + id + '/roster', 'POST', {text: t.value}); t.value = ''; toast(`นำเข้า ${r.added} รายการ ข้าม ${r.skipped_existing_or_duplicate}` + (r.invalid_lines.length ? ` บรรทัดไม่ถูกต้อง: ${r.invalid_lines.join(', ')}` : ''), r.invalid_lines.length > 0); loadRoster(); } catch (e) { err(errText(e)); } }; }
            const loadResults = async () => {
                const box = root.querySelector('#srv-results'); if (!box) return;
                try {
                    const r = await request('/api/surveys/' + id + '/results'); if (!box.isConnected) return;
                    box.innerHTML = `<div class="srv-kpis"><div><strong>${r.total}</strong>คำตอบ</div><div><strong>${r.certificates}</strong>เกียรติบัตร</div>${r.average_score !== null ? `<div><strong>${r.average_score}</strong>คะแนนเฉลี่ย (ร้อยละ)</div>` : ''}</div>` + r.questions.map((q, i) => {
                        const max = Math.max(1, ...(q.counts || []).map(c => c.count));
                        const body = q.type === 'text' ? (q.texts.length ? `<ul class="srv-texts">${q.texts.map(t => `<li>${esc(t)}</li>`).join('')}</ul>` : '<p class="desk-help">ยังไม่มีคำตอบ</p>')
                            : q.counts.map(c => `<div class="srv-bar"><span>${esc(c.label)}</span><i style="width:${Math.round(100 * c.count / max)}%"></i><b>${c.count}</b></div>`).join('') + (q.average !== null ? `<p class="desk-help">ค่าเฉลี่ย ${q.average}</p>` : '');
                        return `<div class="srv-qrow"><strong>${i + 1}. ${esc(q.label)}</strong><small>ตอบ ${q.answered} คน</small>${body}</div>`;
                    }).join('');
                } catch (e) { if (box.isConnected) box.innerHTML = `<p class="desk-error" role="alert">${esc(errText(e))}</p>`; }
            };
            root.querySelector('#srv-refresh').onclick = loadResults;
            loadResults();
        };
        draw();
    }

    window.CmsComplete.render = async parts => {
        if (parts[0] === 'surveys') {
            window.CmsComplete.leave(); const root = document.querySelector('#cms-view'), route = location.hash;
            root.innerHTML = '<div class="loading glass">กำลังอ่านข้อมูลแบบสอบถาม…</div>';
            try { return /^\d+$/.test(parts[1] || '') ? await editor(root, Number(parts[1]), route) : await list(root, route); }
            catch (err) { if (location.hash === route) root.innerHTML = `<div class="desk-editor-card glass"><h1>แบบสอบถาม</h1><p class="desk-error" role="alert">${esc(errText(err))}</p></div>`; }
            return;
        }
        return previous(parts);
    };
})();
