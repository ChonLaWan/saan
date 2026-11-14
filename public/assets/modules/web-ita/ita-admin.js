'use strict';
// web-ita module: CMS screen #ita (years list) and #ita/<id> (editor). Uses the CMS globals request/CMS at call time.
(() => {
    window.SaanModulePages = (window.SaanModulePages || []).concat(['ita']);
    const previous = window.CmsComplete?.render; if (!previous) return;
    const esc = s => window.SaanCore.esc(s);
    const fiscalNow = () => { const d = new Date(); return d.getFullYear() + 543 + (d.getMonth() >= 9 ? 1 : 0); };
    const toast = (m, bad) => { const t = document.querySelector('#cms-toast'); if (t) { t.textContent = m; t.classList.toggle('error', !!bad); t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 3200); } };

    async function list(root, route) {
        const data = await request('/api/ita/years'); if (location.hash !== route) return;
        const years = data.data, next = fiscalNow();
        root.innerHTML = `<div class="cms-heading"><div><h1>ITA การเปิดเผยข้อมูลสาธารณะ</h1><p>จัดหัวข้อและรายการตามตัวชี้วัดของแต่ละปีงบประมาณ แล้วเผยแพร่ที่หน้า <a href="${CMS.base}/portal/ita" target="_blank" rel="noopener">/portal/ita</a></p></div></div>
            <section class="desk-editor-card glass"><h2>เพิ่มปีงบประมาณ</h2><form id="ita-create" class="desk-form"><label class="desk-field"><span>ปีงบประมาณ (พ.ศ.)</span><input name="fiscal_year" type="number" min="2560" max="2700" value="${years.some(y => y.fiscal_year === next) ? next + 1 : next}" required></label>
            <label class="desk-field"><span>เริ่มจาก</span><select name="copy_from"><option value="">หัวข้อเริ่มต้นแบบกลาง (แก้ตามประกาศปีนั้น)</option>${years.map(y => `<option value="${y.id}">คัดลอกโครงจากปี ${y.fiscal_year} (ไม่คัดลอกไฟล์/ลิงก์)</option>`).join('')}</select></label>
            <label class="desk-field"><span><input type="checkbox" name="preset" value="1"> ใช้ชุดตัวชี้วัด O1-O18 พร้อมลิงก์อัตโนมัติ (ใช้เมื่อไม่ได้คัดลอกจากปีอื่น)</span></label><button class="primary" type="submit">สร้างปีงบประมาณ</button></form><p class="desk-help">หัวข้อเริ่มต้นเป็นหมวดกว้าง ๆ ไม่ใช่รายการตัวชี้วัดทางการ ให้ปรับรหัสและชื่อหัวข้อตามประกาศ ITA ของปีนั้น</p></section>
            <section class="desk-editor-card glass"><h2>ปีที่มีอยู่</h2>${years.length ? `<div class="release-health-list">${years.map(y => `<div><span class="tag ${y.status === 'published' ? '' : 'warm'}">${y.status === 'published' ? 'เผยแพร่แล้ว' : 'ฉบับร่าง'}</span><strong>ปีงบประมาณ ${y.fiscal_year}</strong><p>${esc(y.title)} · ${y.items} รายการ</p><a class="quiet-button" href="#ita/${y.id}">แก้ไข</a></div>`).join('')}</div>` : '<p class="desk-help">ยังไม่มีข้อมูล ITA</p>'}</section>`;
        root.querySelector('#ita-create').onsubmit = async e => {
            e.preventDefault(); const f = e.currentTarget; if (!f.reportValidity()) return;
            try { const r = await request('/api/ita/years', 'POST', {fiscal_year: Number(f.elements.fiscal_year.value), copy_from: f.elements.copy_from.value ? Number(f.elements.copy_from.value) : null, preset: f.elements.preset.checked}); if (location.hash === route) location.hash = 'ita/' + r.year.id; }
            catch (err) { toast(err.message, true); }
        };
    }

    // Automatic sources come from the server (read response): {key: label} and {key: [option names]}.
    let SOURCES = {}, SOURCE_FIELDS = {};
    const OPT_LABEL = {category: 'slug หมวดข่าว (เว้นว่าง = ทุกหมวด)', stage: 'ขั้นตอน: results (ประกาศผลและสัญญา) tor invitation award contract cancelled information (เว้นว่าง = ทุกขั้นตอน)', slug: 'slug (ตัวอักษรอังกฤษ ตัวเลข และ -)'};
    const STATUS = {complete: ['ครบ', ''], 'auto-ok': ['ลิงก์อัตโนมัติ', ''], missing: ['ขาด', 'warm'], 'link-broken': ['ลิงก์เสีย', 'warm']};
    function sourceBlock(item) {
        const opt = (SOURCE_FIELDS[item.source] || [])[0] || '';
        const r = item.resolved || {};
        const note = item.source && r.mode === 'auto' ? (r.ok ? `ลิงก์: ${esc(r.path)} · ${esc(r.evidence)}` : `ยังไม่มีข้อมูล: ${esc(r.reason)}`) : '';
        return `<label class="desk-field"><span>ลิงก์อัตโนมัติจากระบบ</span><select data-f="source"><option value="">กรอกเอง (ลิงก์ ไฟล์ หรือข้อความ)</option>${Object.entries(SOURCES).map(([k, l]) => `<option value="${esc(k)}" ${item.source === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
            <label class="desk-field" data-opt-wrap ${opt ? '' : 'hidden'}><span data-opt-label>${esc(OPT_LABEL[opt] || '')}</span><input data-f="opt" data-opt-name="${esc(opt)}" value="${esc((item.source_opts || {})[opt] || '')}" maxlength="80"></label>
            <p class="desk-help" data-source-note ${item.source ? '' : 'hidden'}>${note || 'ถ้ากรอกลิงก์หรือแนบไฟล์ด้านล่าง ระบบจะใช้ที่กรอกเองก่อนเสมอ'}</p>`;
    }
    function itemRow(item, ti, ii) {
        const file = item.media ? `${esc(item.media.original_name || ('ไฟล์ #' + item.media_id))}` : (item.media_id ? 'ไฟล์ #' + item.media_id : 'ยังไม่แนบไฟล์');
        return `<div class="ita-item-row" data-item="${ti}.${ii}"><label class="desk-field"><span>ชื่อรายการ</span><input data-f="title" value="${esc(item.title)}" maxlength="300" required></label>${sourceBlock(item)}
            <label class="desk-field"><span>ลิงก์ (https หรือ /portal/...)</span><input data-f="url" value="${esc(item.url || '')}" maxlength="1000" placeholder="https://"></label>
            <div class="ita-file"><span data-file-label>${file}</span><input type="hidden" data-f="media_id" value="${item.media_id || ''}"><button type="button" data-pick>เลือกไฟล์จากคลัง</button><button type="button" data-clear-file ${item.media_id ? '' : 'hidden'}>นำไฟล์ออก</button></div>
            <label class="desk-field"><span>หมายเหตุ</span><input data-f="note" value="${esc(item.note || '')}" maxlength="500"></label><button type="button" data-remove-item aria-label="ลบรายการ">ลบรายการ</button></div>`;
    }
    function topicBlock(topic, ti) {
        return `<details class="desk-editor-card glass ita-topic-edit" open data-topic="${ti}"><summary>${esc(topic.code)} ${esc(topic.title) || 'หัวข้อใหม่'} <small>${topic.items.length} รายการ</small></summary>
            <div class="desk-form-grid"><label class="desk-field"><span>รหัสตัวชี้วัด (ถ้ามี)</span><input data-t="code" value="${esc(topic.code)}" maxlength="20" placeholder="เช่น O1"></label><label class="desk-field"><span>ชื่อหัวข้อ</span><input data-t="title" value="${esc(topic.title)}" maxlength="300" required></label></div>
            <div data-items>${topic.items.map((it, ii) => itemRow(it, ti, ii)).join('')}</div>
            <div class="ita-topic-actions"><button type="button" data-add-item>เพิ่มรายการ</button><button type="button" data-move="-1">เลื่อนขึ้น</button><button type="button" data-move="1">เลื่อนลง</button><button type="button" data-remove-topic>ลบหัวข้อ</button></div></details>`;
    }

    async function editor(root, id, route) {
        let state = await request('/api/ita/years/' + id); if (location.hash !== route) return;
        SOURCES = state.sources || {}; SOURCE_FIELDS = state.source_fields || {};
        let topics = state.topics.map(t => ({code: t.code, title: t.title, items: t.items.map(i => ({...i}))}));
        const sig = ts => JSON.stringify(ts.map(t => [t.code, t.title, t.items.map(i => [i.title, i.url || null, i.media_id || null, i.note || '', i.source || null, i.source_opts || null])]));
        let savedSig = sig(topics);
        const collect = () => { const out = []; root.querySelectorAll('[data-topic]').forEach(tb => { const t = {code: tb.querySelector('[data-t=code]').value.trim(), title: tb.querySelector('[data-t=title]').value.trim(), items: []}; tb.querySelectorAll('.ita-item-row').forEach(r => { const g = f => r.querySelector(`[data-f=${f}]`).value.trim(); const src = g('source') || null, optEl = r.querySelector('[data-f=opt]'), optName = optEl.dataset.optName, optVal = optEl.value.trim(); t.items.push({title: g('title'), url: g('url') || null, media_id: /^\d+$/.test(g('media_id')) ? Number(g('media_id')) : null, note: g('note'), source: src, source_opts: src && optName && optVal ? {[optName]: optVal} : null, media: topics[Number(r.dataset.item.split('.')[0])]?.items[Number(r.dataset.item.split('.')[1])]?.media || null}); }); out.push(t); }); return out; };
        const draw = () => {
            const y = state.year, published = y.status === 'published';
            root.innerHTML = `<div class="cms-heading"><div><h1>ITA ปีงบประมาณ ${y.fiscal_year}</h1><p>${published ? 'เผยแพร่แล้ว การบันทึกจะแสดงบนเว็บไซต์ทันที' : 'ฉบับร่าง ผู้ชมยังไม่เห็น'}</p></div><div class="cms-heading-actions"><a class="quiet-button" href="#ita">กลับรายการปี</a><a class="quiet-button" href="${CMS.base}/portal/ita/${y.fiscal_year}" target="_blank" rel="noopener">ดูหน้าเว็บ</a></div></div>
                <p id="ita-error" class="desk-error" role="alert"></p><form id="ita-form"><section class="desk-editor-card glass"><label class="desk-field"><span>ชื่อหน้า</span><input name="title" value="${esc(y.title)}" maxlength="200" required></label></section>
                <div id="ita-topics">${topics.map(topicBlock).join('')}</div>
                <div class="desk-form-actions"><button type="button" id="ita-add-topic">เพิ่มหัวข้อ</button><button type="submit" class="primary">บันทึก</button><label class="desk-field ita-check-year"><span>ปีที่ตรวจ</span><select id="ita-check-year"><option value="${y.fiscal_year}" selected>ปีงบประมาณ ${y.fiscal_year}</option></select></label><button type="button" id="ita-check">ตรวจความครบ O1-O18 (ที่บันทึกแล้ว)</button><button type="button" id="ita-status">${published ? 'หยุดเผยแพร่' : 'เผยแพร่ปีนี้'}</button></div></form>
                <section class="desk-editor-card glass" id="ita-check-result" aria-live="polite" hidden></section>`;
            const form = root.querySelector('#ita-form');
            root.querySelector('#ita-add-topic').onclick = () => { topics = collect(); topics.push({code: '', title: '', items: []}); draw(); };
            root.querySelectorAll('[data-topic]').forEach(tb => { const ti = Number(tb.dataset.topic);
                tb.querySelector('[data-add-item]').onclick = () => { topics = collect(); topics[ti].items.push({title: '', url: null, media_id: null, note: ''}); draw(); };
                tb.querySelector('[data-remove-topic]').onclick = () => { if (!confirm('ลบหัวข้อนี้และรายการทั้งหมดในหัวข้อ?')) return; topics = collect(); topics.splice(ti, 1); draw(); };
                tb.querySelectorAll('[data-move]').forEach(b => b.onclick = () => { topics = collect(); const j = ti + Number(b.dataset.move); if (j < 0 || j >= topics.length) return; [topics[ti], topics[j]] = [topics[j], topics[ti]]; draw(); });
                tb.querySelectorAll('.ita-item-row').forEach(r => { const ii = Number(r.dataset.item.split('.')[1]);
                    r.querySelector('[data-f=source]').onchange = e => { const opt = (SOURCE_FIELDS[e.target.value] || [])[0] || '', el = r.querySelector('[data-f=opt]'); el.dataset.optName = opt; el.value = ''; r.querySelector('[data-opt-label]').textContent = OPT_LABEL[opt] || ''; r.querySelector('[data-opt-wrap]').hidden = !opt; const n = r.querySelector('[data-source-note]'); n.hidden = !e.target.value; n.textContent = e.target.value ? 'บันทึกแล้วกด "ตรวจความครบ" เพื่อดูว่าลิงก์อัตโนมัติมีข้อมูลหรือไม่ ถ้ากรอกลิงก์หรือแนบไฟล์ ระบบจะใช้ที่กรอกเองก่อนเสมอ' : ''; };
                    r.querySelector('[data-remove-item]').onclick = () => { topics = collect(); topics[ti].items.splice(ii, 1); draw(); };
                    r.querySelector('[data-clear-file]').onclick = () => { r.querySelector('[data-f=media_id]').value = ''; r.querySelector('[data-file-label]').textContent = 'ยังไม่แนบไฟล์'; r.querySelector('[data-clear-file]').hidden = true; };
                    r.querySelector('[data-pick]').onclick = () => window.CmsComplete.pickMedia('document', mediaId => { if (!r.isConnected || !Number.isInteger(mediaId)) return; r.querySelector('[data-f=media_id]').value = String(mediaId); r.querySelector('[data-file-label]').textContent = 'ไฟล์ #' + mediaId + ' (จะแสดงชื่อหลังบันทึก)'; r.querySelector('[data-clear-file]').hidden = false; });
                });
            });
            form.onsubmit = async e => { e.preventDefault(); if (!form.reportValidity()) return; await save(); };
                        // the check reads what is SAVED (never saves for you: on a published year a save goes live at once); unsaved edits are flagged instead
            const yearSelect = root.querySelector('#ita-check-year');
            request('/api/ita/years').then(d => { if (!yearSelect.isConnected) return; const cur = yearSelect.value; const ys = d.data.map(x => x.fiscal_year); if (!ys.includes(fiscalNow())) ys.push(fiscalNow()); yearSelect.innerHTML = ys.sort((a, b) => b - a).map(v => `<option value="${v}" ${String(v) === cur ? 'selected' : ''}>ปีงบประมาณ ${v}</option>`).join(''); }).catch(() => {});
            const box = root.querySelector('#ita-check-result');
            box.onclick = e => {
                const b = e.target.closest('[data-fix]'); if (!b) return;
                const fix = b.dataset.fixPath || '';
                if (fix !== '/cms#ita/' + state.year.id) { location.hash = fix.split('#')[1] || 'ita'; return; }
                const code = b.dataset.fix, n = String(Number(code.slice(1))), rows = [...root.querySelectorAll('.ita-item-row')];
                const target = [...root.querySelectorAll('[data-topic]')].find(tb => tb.querySelector('[data-t=code]').value.replace(/[\s-]+/g, '').toUpperCase().replace(/^O0+/, 'O') === code)
                    || rows.find(r => new RegExp('^O\\s*-?\\s*0*' + n + '(?!\\d)', 'i').test(r.querySelector('[data-f=title]').value.trim()))?.closest('[data-topic]');
                if (!target) { toast('ยังไม่มีหัวข้อรหัส ' + code + ' ในปีนี้ กด "เพิ่มหัวข้อ" แล้วใส่รหัสนี้', true); return; }
                target.open = true; target.scrollIntoView({block: 'start'}); target.querySelector('input')?.focus();
            };
            root.querySelector('#ita-check').onclick = async () => {
                const fy = Number(yearSelect.value) || state.year.fiscal_year;
                try {
                    const r = await request('/api/ita/check?year=' + fy); if (location.hash !== route) return; const s = r.summary;
                    const dirty = fy === state.year.fiscal_year && sig(collect()) !== savedSig;
                    box.innerHTML = `<h2>ผลตรวจความครบ ปีงบประมาณ ${r.fiscal_year}</h2>${dirty ? '<p class="desk-error" role="alert">มีการแก้ที่ยังไม่บันทึก ผลนี้ตรวจจากข้อมูลที่บันทึกไว้แล้ว กด "บันทึก" ก่อนตรวจอีกครั้งถ้าต้องการรวมการแก้นั้น</p>' : ''}${r.year_id && !r.published ? '<p class="desk-help" role="status">ปีนี้ยังเป็นฉบับร่าง ผู้ชมยังไม่เห็น จึงยังไม่ถือว่าผ่านจนกว่าจะเผยแพร่</p>' : ''}<p role="status"><strong>ครบ ${s.complete}</strong> · ลิงก์อัตโนมัติ ${s.auto_ok} · <strong>ขาด ${s.missing}</strong> · <strong>ลิงก์เสีย ${s.link_broken}</strong> จากทั้งหมด ${s.total} ข้อ</p>
                        <div class="release-health-list">${r.items.map(i => `<div><span class="tag ${STATUS[i.status][1]}">${STATUS[i.status][0]}</span><strong>${esc(i.code)} ${esc(i.title)}</strong><p>${esc(i.reason)}${i.evidence ? ' · ' + esc(i.evidence) : ''}</p>${i.status === 'complete' || i.status === 'auto-ok' ? '' : `<button type="button" class="quiet-button" data-fix="${esc(i.code)}" data-fix-path="${esc(i.fix_path)}">แก้ไขรายการนี้</button>`}</div>`).join('')}</div>`;
                    box.hidden = false; box.scrollIntoView({block: 'nearest'});
                } catch (err) { root.querySelector('#ita-error').textContent = err.message; }
            };
root.querySelector('#ita-status').onclick = async () => { try { if (!(await save(true))) return; state = await request('/api/ita/years/' + id + '/status', 'POST', {version: state.year.version, action: state.year.status === 'published' ? 'unpublish' : 'publish'}); if (location.hash !== route) return; topics = state.topics.map(t => ({code: t.code, title: t.title, items: t.items})); savedSig = sig(topics); draw(); toast(state.year.status === 'published' ? 'เผยแพร่ ITA ปีนี้แล้ว' : 'หยุดเผยแพร่แล้ว'); } catch (err) { root.querySelector('#ita-error').textContent = err.message; } };
            async function save(quiet = false) {
                if (!form.reportValidity()) return false; topics = collect();
                try { state = await request('/api/ita/years/' + id, 'PUT', {version: state.year.version, title: form.elements.title.value.trim(), topics: topics.map(t => ({code: t.code, title: t.title, items: t.items.map(i => ({title: i.title, url: i.url, media_id: i.media_id, note: i.note, source: i.source || null, source_opts: i.source_opts || null}))}))});
                    if (location.hash !== route) return false; topics = state.topics.map(t => ({code: t.code, title: t.title, items: t.items})); savedSig = sig(topics); if (!quiet) { draw(); toast('บันทึก ITA แล้ว'); } return true; }
                catch (err) { root.querySelector('#ita-error').textContent = err.message; return false; }
            }
        };
        draw();
    }

    window.CmsComplete.render = async parts => {
        if (parts[0] === 'ita') {
            window.CmsComplete.leave(); const root = document.querySelector('#cms-view'), route = location.hash;
            root.innerHTML = '<div class="loading glass">กำลังอ่านข้อมูล ITA…</div>';
            try { return /^\d+$/.test(parts[1] || '') ? await editor(root, Number(parts[1]), route) : await list(root, route); }
            catch (err) { if (location.hash === route) root.innerHTML = `<div class="desk-editor-card glass"><h1>ITA</h1><p class="desk-error" role="alert">${esc(err.message)}</p></div>`; }
            return;
        }
        return previous(parts);
    };
})();
