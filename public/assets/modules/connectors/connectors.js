'use strict';
// connectors module: staff page #archives (old school years, read-only) for the /work shell.
// Routes: #archives (search), #archives/view/<archive id>/<code>, #archives/manage, #archives/mapping.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const FIELD = {code: 'รหัสนักเรียน', name: 'ชื่อ-สกุล', prefix: 'คำนำหน้า', first_name: 'ชื่อ', last_name: 'นามสกุล', full_name: 'ชื่อ-สกุล', level: 'ชั้น', room: 'ห้อง', classroom: 'ห้องเรียน',
        roll_number: 'เลขที่', sex: 'เพศ', birth_date: 'วันเกิด', subject_code: 'รหัสวิชา', subject_name: 'ชื่อวิชา', term: 'ภาคเรียน', year: 'ปีการศึกษา', score: 'คะแนน', grade: 'เกรด',
        credit: 'หน่วยกิต', days_present: 'วันมาเรียน', days_absent: 'วันขาด', days_leave: 'วันลา', days_total: 'วันเรียนทั้งหมด'};
    const KIND = {student: 'ทะเบียนนักเรียน', grade: 'ผลการเรียน', attendance_summary: 'สรุปเวลาเรียน', other: 'อื่น ๆ'};
    const SEX = {M: 'ชาย', F: 'หญิง'};

    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    };
    const fail = err => { if (err?.name !== 'AbortError') toast(err?.message || 'เกิดข้อผิดพลาด', true); };
    const mount = root => { const host = document.createElement('div'); host.className = 'con-host'; root.append(host); return host; };
    const nav = (cfg, active) => {
        const tabs = [['', 'ค้นหา']];
        if (cfg.can_manage) tabs.push(['manage', 'จัดการชุดข้อมูล'], ['mapping', 'แปลงไฟล์เข้าทะเบียน']);
        return '<nav class="con-tabs" aria-label="เมนูข้อมูลปีเก่า">' + tabs.map(([k, l]) =>
            `<a class="con-tab" href="#archives${k ? '/' + k : ''}"${k === active ? ' aria-current="page"' : ''}>${esc(l)}</a>`).join('') + '</nav>';
    };
    const fieldLabel = k => FIELD[k] || k;
    const showValue = (k, v) => k === 'sex' ? (SEX[v] || v) : v;
    const base = () => (document.querySelector('meta[name=\"school-base\"]')?.content || '').replace(/\/+$/, '');
    const uid = () => (window.SaanCore.uid ? window.SaanCore.uid() : String(Date.now()) + Math.random().toString(16).slice(2));

    function dataList(d) {
        const rows = Object.entries(d).filter(([k]) => k !== 'extra').map(([k, v]) => `<div><dt>${esc(fieldLabel(k))}</dt><dd>${esc(showValue(k, v))}</dd></div>`);
        if (d.extra) for (const [k, v] of Object.entries(d.extra)) rows.push(`<div class="con-extra"><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`);
        return `<dl class="con-dl">${rows.join('')}</dl>`;
    }

    // ---------------- search ----------------
    async function search(root, ctx, cfg) {
        const host = mount(root);
        host.innerHTML = `<section class="con glass">${nav(cfg, '')}<div class="con-head"><h1>ข้อมูลปีเก่า</h1></div>
            <p class="con-sub">ค้นด้วยรหัสนักเรียนหรือชื่อ ผลที่แสดงเป็นข้อมูลอ่านอย่างเดียว ไม่ปะปนกับข้อมูลปีปัจจุบัน และระบบบันทึกว่าใครค้นอะไร${cfg.can_manage ? '' : ' (ครูค้นได้เฉพาะนักเรียนประจำชั้นของตน)'}</p>
            <form class="con-form" id="con-search"><label class="con-field"><span>รหัสนักเรียนหรือชื่อ</span><input name="q" type="search" maxlength="80" minlength="2" required autocomplete="off"></label>
            <button class="con-btn primary" type="submit">ค้นหา</button></form>
            <p class="con-count" id="con-count" aria-live="polite"></p><div id="con-results"></div></section>`;
        const form = host.querySelector('#con-search'), out = host.querySelector('#con-results'), count = host.querySelector('#con-count');
        let busy = false;
        form.addEventListener('submit', async e => {
            e.preventDefault(); if (busy) return; busy = true;
            try {
                const r = await ctx.api('/api/connectors/search?' + new URLSearchParams({q: form.elements.q.value.trim()}));
                if (!ctx.alive()) return;
                count.textContent = r.data.length ? `พบ ${r.data.length} รายการ${r.truncated ? ' (แสดงบางส่วน ระบุคำค้นให้แคบลง)' : ''}` : 'ไม่พบข้อมูล';
                out.innerHTML = r.data.map(x => `<article class="con-card"><h2>${esc(x.name || x.code)}</h2>
                    <p>รหัส ${esc(x.code)}${x.classroom ? ' · ' + esc(x.classroom) : ''}</p>
                    <p class="con-muted">${esc(x.archive_label)} (พ.ศ. ${esc(x.year_be)}) · ${esc(x.rows)} รายการ</p>
                    <a class="con-btn" href="#archives/view/${esc(x.archive_id)}/${encodeURIComponent(x.code)}">เปิดดู</a></article>`).join('');
            } catch (err) { fail(err); } finally { busy = false; }
        });
    }

    // ---------------- record card ----------------
    async function view(root, ctx, cfg) {
        const [archiveId, code] = [ctx.parts[1], ctx.parts[2]];
        const q = new URLSearchParams({archive_id: archiveId || '', code: code || ''});
        const d = await ctx.api('/api/connectors/record?' + q);
        if (!ctx.alive()) return;
        const host = mount(root);
        host.innerHTML = `<section class="con glass">${nav(cfg, 'view')}<div class="con-head"><h1>${esc(d.name || d.code)}</h1>
            <div class="con-actions"><a class="con-btn" href="${esc(base() + '/api/connectors/record/export?' + q.toString())}" download>ส่งออกประวัติ CSV</a>
            <a class="con-btn" href="${esc(base() + '/api/connectors/record/export?' + new URLSearchParams({code: code || ''}).toString())}" download>ส่งออกทุกปี</a></div></div>
            <p class="con-sub">รหัส ${esc(d.code)} · ${esc(d.archive.label)} (พ.ศ. ${esc(d.archive.year_be)}) · อ่านอย่างเดียว</p>
            ${Object.entries(d.groups).filter(([, l]) => l.length).map(([k, list]) => `<section class="con-group"><h2>${esc(KIND[k] || k)}</h2>
                ${list.map(r => `<div class="con-card">${r.key2 ? `<p class="con-muted">${esc(r.key2)}</p>` : ''}${dataList(r.data)}</div>`).join('')}</section>`).join('')}
            <a class="con-btn" href="#archives">กลับไปค้นหา</a></section>`;
    }

    // ---------------- manage ----------------
    async function manage(root, ctx, cfg) {
        if (!cfg.can_manage) { root.insertAdjacentHTML('beforeend', '<section class="con glass" role="alert">ไม่มีสิทธิ์ใช้งานส่วนนี้</section>'); return; }
        const [list, presets] = await Promise.all([ctx.api('/api/connectors/archives'), ctx.api('/api/connectors/presets')]);
        if (!ctx.alive()) return;
        const host = mount(root);
        let data = list.data;
        const draw = () => {
            host.innerHTML = `<section class="con glass">${nav(cfg, 'manage')}<div class="con-head"><h1>จัดการชุดข้อมูลปีเก่า</h1></div>
            <form class="con-form" id="con-new"><label class="con-field"><span>ชื่อชุดข้อมูล</span><input name="label" maxlength="120" required placeholder="เช่น ปีการศึกษา 2560"></label>
                <label class="con-field"><span>ปีการศึกษา (พ.ศ.)</span><input name="year_be" type="number" min="2400" max="2700" required></label>
                <label class="con-field"><span>แหล่งที่มา</span><input name="source" maxlength="200" placeholder="เช่น ไฟล์ส่งออกจากระบบเดิม"></label>
                <button class="con-btn primary" type="submit">สร้างชุดข้อมูล</button></form>
            ${cfg.is_admin ? `<label class="con-check"><input type="checkbox" id="con-teacher"${cfg.teacher_lookup ? ' checked' : ''}><span>ให้ครูค้นข้อมูลปีเก่าของนักเรียนประจำชั้นตนเอง</span></label>` : ''}
            <div id="con-list">${data.length ? data.map(a => `<article class="con-card" data-id="${esc(a.id)}"><h2>${esc(a.label)} ${a.locked ? '<span class="con-badge">ล็อก</span>' : ''}</h2>
                <p>พ.ศ. ${esc(a.year_be)} · ${esc(a.row_count)} แถว${a.source ? ' · ' + esc(a.source) : ''}</p>
                <ul class="con-files">${a.files.map(f => `<li>${esc(KIND[f.kind] || f.kind)}: ${esc(f.filename)} (${esc(f.row_count)} แถว${f.skipped ? ', ข้าม ' + esc(f.skipped) : ''})</li>`).join('') || '<li class="con-muted">ยังไม่มีไฟล์</li>'}</ul>
                ${a.locked ? '' : `<form class="con-form con-upload" data-id="${esc(a.id)}"><label class="con-field"><span>ชนิดข้อมูล</span><select name="kind">${Object.entries(KIND).map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join('')}</select></label>
                    <label class="con-field"><span>รูปแบบไฟล์</span><select name="preset">${presets.data.map(p => `<option value="${esc(p.key)}">${esc(p.label)}</option>`).join('')}</select></label>
                    <label class="con-field"><span>ไฟล์ CSV (UTF-8 หรือ Windows-874)</span><input name="file" type="file" accept=".csv,text/csv,text/plain" required></label>
                    <details class="con-adv"><summary>จับคู่คอลัมน์เอง (ขั้นสูง)</summary><label class="con-field"><span>JSON เช่น {"ID นักเรียน":"code"}</span><textarea name="mapping" rows="2"></textarea></label></details>
                    <button class="con-btn" type="submit">นำเข้าไฟล์</button></form>`}
                <div class="con-actions">${a.locked && !cfg.is_admin ? '' : `<button type="button" class="con-btn" data-lock="${esc(a.id)}">${a.locked ? 'ปลดล็อก' : 'ล็อกไม่ให้ลบ'}</button>`}
                ${a.locked ? '' : `<button type="button" class="con-btn danger" data-del="${esc(a.id)}">ลบชุดข้อมูล</button>`}</div>
                <div class="con-del" data-delbox="${esc(a.id)}" hidden></div></article>`).join('') : '<p class="con-muted">ยังไม่มีชุดข้อมูล</p>'}</div>
            <details class="con-adv"><summary>รูปแบบไฟล์ที่รองรับ</summary><ul>${presets.data.map(p => `<li><strong>${esc(p.label)}</strong><br>${esc(p.columns)}</li>`).join('')}</ul></details></section>`;
        };
        const reload = async () => { data = (await ctx.api('/api/connectors/archives')).data; if (ctx.alive()) draw(); };
        const get = id => data.find(a => String(a.id) === String(id));
        draw();
        host.addEventListener('submit', async e => {
            e.preventDefault(); const f = e.target;
            try {
                if (f.id === 'con-new') {
                    await ctx.api('/api/connectors/archives', {method: 'POST', idempotencyKey: uid(), body: {label: f.elements.label.value.trim(), year_be: Number(f.elements.year_be.value), source: f.elements.source.value.trim() || null}});
                    toast('สร้างชุดข้อมูลแล้ว');
                } else if (f.classList.contains('con-upload')) {
                    const fd = new FormData(); fd.append('kind', f.elements.kind.value); fd.append('preset', f.elements.preset.value); fd.append('file', f.elements.file.files[0]);
                    if (f.elements.mapping.value.trim()) fd.append('mapping', f.elements.mapping.value.trim());
                    const r = await ctx.api(`/api/connectors/archives/${f.dataset.id}/files`, {method: 'POST', body: fd, idempotencyKey: uid()});
                    toast(`นำเข้า ${r.imported} แถว${r.skipped ? ' ข้าม ' + r.skipped : ''}`);
                } else return;
                await reload();
            } catch (err) { fail(err); if (err?.status === 409) reload().catch(() => {}); }
        });
        host.addEventListener('change', async e => {
            if (e.target.id !== 'con-teacher') return;
            try { await ctx.api('/api/connectors/settings', {method: 'PUT', body: {teacher_lookup: e.target.checked}}); cfg.teacher_lookup = e.target.checked; toast('บันทึกการตั้งค่าแล้ว'); }
            catch (err) { e.target.checked = !e.target.checked; fail(err); }
        });
        host.addEventListener('click', async e => {
            const lock = e.target.closest('[data-lock]'), del = e.target.closest('[data-del]'), go = e.target.closest('[data-delgo]'), cancel = e.target.closest('[data-delcancel]');
            try {
                if (lock) { const a = get(lock.dataset.lock); await ctx.api('/api/connectors/archives/' + a.id, {method: 'PUT', body: {version: a.version, locked: !a.locked}}); await reload(); }
                else if (del) {
                    const a = get(del.dataset.del), box = host.querySelector(`[data-delbox="${a.id}"]`);
                    // step 2 of 2: the person types the exact phrase before the delete button works
                    box.hidden = false;
                    box.innerHTML = `<p class="con-alert">การลบนำข้อมูลปีเก่าออกถาวร ย้อนกลับไม่ได้ พิมพ์ข้อความต่อไปนี้เพื่อยืนยัน</p><p><code>ลบ ${esc(a.label)}</code></p>
                        <label class="con-field"><span>ข้อความยืนยัน</span><input data-delinput autocomplete="off"></label>
                        <div class="con-actions"><button type="button" class="con-btn danger" data-delgo="${esc(a.id)}" disabled>ลบถาวร</button><button type="button" class="con-btn" data-delcancel="${esc(a.id)}">ยกเลิก</button></div>`;
                    box.querySelector('[data-delinput]').addEventListener('input', ev => { box.querySelector('[data-delgo]').disabled = ev.target.value.trim() !== 'ลบ ' + a.label; });
                    box.querySelector('[data-delinput]').focus();
                } else if (go) {
                    const a = get(go.dataset.delgo), box = host.querySelector(`[data-delbox="${a.id}"]`);
                    await ctx.api('/api/connectors/archives/' + a.id, {method: 'DELETE', body: {version: a.version, confirm: box.querySelector('[data-delinput]').value.trim()}});
                    toast('ลบชุดข้อมูลแล้ว'); await reload();
                } else if (cancel) { const box = host.querySelector(`[data-delbox="${cancel.dataset.delcancel}"]`); box.hidden = true; box.replaceChildren(); }
            } catch (err) { fail(err); if (err?.status === 409) reload().catch(() => {}); }
        });
    }

    // ---------------- mapping importer ----------------
    async function mapping(root, ctx, cfg) {
        if (!cfg.can_manage) { root.insertAdjacentHTML('beforeend', '<section class="con glass" role="alert">ไม่มีสิทธิ์ใช้งานส่วนนี้</section>'); return; }
        const presets = await ctx.api('/api/connectors/presets');
        if (!ctx.alive()) return;
        const host = mount(root);
        host.innerHTML = `<section class="con glass">${nav(cfg, 'mapping')}<div class="con-head"><h1>แปลงไฟล์จากระบบเดิมเข้าทะเบียนนักเรียน</h1></div>
            <p class="con-sub">ขั้นที่ 1 ดูตัวอย่างการจับคู่คอลัมน์ ขั้นที่ 2 ส่งเข้าตัวอย่างของทะเบียนนักเรียน ขั้นที่ 3 ยืนยันนำเข้า (ย้อนกลับได้จากหน้านำเข้าของทะเบียน)${cfg.registry_available ? '' : ' ตอนนี้โมดูลทะเบียนนักเรียนยังไม่เปิด จึงดาวน์โหลดไฟล์ที่แปลงแล้วได้อย่างเดียว'}</p>
            <form class="con-form" id="con-map"><label class="con-field"><span>รูปแบบไฟล์</span><select name="preset">${presets.data.map(p => `<option value="${esc(p.key)}">${esc(p.label)}</option>`).join('')}</select></label>
            <label class="con-field"><span>ไฟล์ CSV (UTF-8 หรือ Windows-874)</span><input name="file" type="file" accept=".csv,text/csv,text/plain" required></label>
            <details class="con-adv"><summary>จับคู่คอลัมน์เอง (ขั้นสูง)</summary><label class="con-field"><span>JSON เช่น {"ID นักเรียน":"code"} ฟิลด์ที่ใช้ได้: ${esc(presets.fields.join(', '))}</span><textarea name="mapping" rows="2"></textarea></label></details>
            <div class="con-actions"><button class="con-btn primary" type="submit">ดูตัวอย่างการจับคู่</button></div></form>
            <div id="con-map-out" aria-live="polite"></div></section>`;
        const form = host.querySelector('#con-map'), out = host.querySelector('#con-map-out');
        const body = () => {
            const fd = new FormData(); fd.append('preset', form.elements.preset.value); fd.append('file', form.elements.file.files[0]);
            if (form.elements.mapping.value.trim()) fd.append('mapping', form.elements.mapping.value.trim());
            return fd;
        };
        let busy = false;
        const guard = async fn => { if (busy) return; busy = true; try { await fn(); } catch (err) { fail(err); } finally { busy = false; } };
        form.addEventListener('submit', e => { e.preventDefault(); guard(async () => {
            const p = await ctx.api('/api/connectors/mapping/preview', {method: 'POST', body: body()});
            if (!ctx.alive()) return;
            out.innerHTML = `<h2>ตัวอย่างการจับคู่</h2><p>${esc(p.summary.rows)} แถว · มีปัญหา ${esc(p.summary.with_issues)} แถว</p>
                <ul class="con-files">${p.mapping.map(m => `<li>${esc(m.column)} → ${esc(fieldLabel(m.field))}</li>`).join('')}</ul>
                ${p.unmapped_columns.length ? `<p class="con-muted">คอลัมน์ที่ไม่ใช้: ${p.unmapped_columns.map(esc).join(', ')}</p>` : ''}
                ${p.sample.map(s => `<div class="con-card${s.issues.length ? ' bad' : ''}"><p class="con-muted">บรรทัด ${esc(s.line)}</p>
                    <p>${esc(s.data.code)} · ${esc([s.data.prefix, s.data.first_name, s.data.last_name, s.data.display_name].filter(Boolean).join(' '))} · ${esc(s.data.classroom)}</p>
                    ${s.issues.length ? `<ul class="con-alert">${s.issues.map(i => `<li>${esc(i)}</li>`).join('')}</ul>` : ''}</div>`).join('')}
                <div class="con-actions">${cfg.registry_available ? '<button type="button" class="con-btn primary" data-stage>ส่งเข้าตัวอย่างของทะเบียน</button>' : ''}<button type="button" class="con-btn" data-csv>ดาวน์โหลด CSV ที่แปลงแล้ว</button></div><div id="con-stage"></div>`;
        }); });
        out.addEventListener('click', e => {
            if (e.target.closest('[data-csv]')) guard(async () => {
                const res = await fetch((document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '') + '/api/connectors/mapping/csv', {method: 'POST', body: body(), credentials: 'same-origin',
                    headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', Accept: 'text/csv'}});
                if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || 'ดาวน์โหลดไม่สำเร็จ');
                const a = document.createElement('a'); a.href = URL.createObjectURL(await res.blob()); a.download = 'registry-students-mapped.csv'; document.body.append(a); a.click();
                setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
            });
            if (e.target.closest('[data-stage]')) guard(async () => {
                const r = await ctx.api('/api/connectors/mapping/runs', {method: 'POST', body: body(), idempotencyKey: uid()});
                drawStage(r);
            });
            const go = e.target.closest('[data-commit]');
            if (go) guard(async () => {
                const r = await ctx.api(`/api/connectors/mapping/runs/${go.dataset.commit}/commit`, {method: 'POST', body: {version: Number(go.dataset.version)}, idempotencyKey: uid()});
                toast('นำเข้าเข้าทะเบียนแล้ว'); drawStage(r, true);
            });
        });
        function drawStage(r, done) {
            const s = r.registry.batch.summary || {}, c = s.committed;
            host.querySelector('#con-stage').innerHTML = `<h2>${done ? 'นำเข้าแล้ว' : 'ตัวอย่างจากทะเบียนนักเรียน'}</h2>
                <p>เพิ่มใหม่ ${esc(c ? c.create : s.create)} · อัปเดต ${esc(c ? c.update : s.update)} · ข้าม ${esc(c ? c.skip : s.skip)} · ผิดพลาด ${esc(s.error ?? 0)}</p>
                ${done ? '<p class="con-muted">ต้องการย้อนกลับ ให้ใช้หน้า นำเข้าข้อมูลนักเรียน ของทะเบียน</p>' : `<button type="button" class="con-btn primary" data-commit="${esc(r.run.id)}" data-version="${esc(r.run.version)}">ยืนยันนำเข้า</button>`}`;
        }
    }

    window.SaanWork.register('archives', {
        title: 'ข้อมูลปีเก่า',
        async render(root, ctx) {
            const cfg = await ctx.api('/api/connectors/config');
            if (!ctx.alive()) return;
            const page = ctx.parts[0] || '';
            if (!cfg.can_manage && !cfg.teacher_lookup) {
                root.insertAdjacentHTML('beforeend', '<section class="con glass" role="alert">ยังไม่เปิดให้ครูค้นข้อมูลปีเก่า โปรดติดต่อผู้ดูแลระบบ</section>');
                return;
            }
            try {
                if (page === 'view') await view(root, ctx, cfg);
                else if (page === 'manage') await manage(root, ctx, cfg);
                else if (page === 'mapping') await mapping(root, ctx, cfg);
                else await search(root, ctx, cfg);
            } catch (err) { if (err?.name !== 'AbortError' && ctx.alive()) root.insertAdjacentHTML('beforeend', `<section class="con glass" role="alert">${esc(err.message || 'เกิดข้อผิดพลาด')}</section>`); }
        },
    });
})();
