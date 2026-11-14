'use strict';
// personnel module: staff pages #portfolio (teacher portfolio) and #personnel (HR register, folders, quota) for the /work shell.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const api = (ctx, url, opts) => ctx.api(url, opts);
    const REVIEW = {pending: 'รอตรวจ', approved: 'ตรวจรับแล้ว', revise: 'ต้องแก้ไข'};
    const STATUS = {active: 'ใช้งาน', archived: 'เก็บถาวร', deleted: 'ลบแล้ว'};
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const href = path => base() + path;
    const mb = n => (n / 1048576).toFixed(n >= 10485760 ? 0 : 1);
    const bytes = n => n >= 1073741824 ? (n / 1073741824).toFixed(2) + ' GB' : n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
    const beYear = () => Number(window.SaanCore.today().slice(0, 4)) + 543; // server date (Asia/Bangkok), never the visitor's clock
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 4000);
    };
    const field = (label, control) => `<label class="per-field"><span>${esc(label)}</span>${control}</label>`;
    const mount = root => { const host = document.createElement('div'); host.className = 'per-host'; root.append(host); return host; };
    const opts = (items, current, blank) => (blank !== undefined ? `<option value="">${esc(blank)}</option>` : '')
        + items.map(([k, v]) => `<option value="${esc(k)}"${String(k) === String(current) ? ' selected' : ''}>${esc(v)}</option>`).join('');

    function conflict(err, reload) {
        const box = document.createElement('div');
        box.className = 'per-conflict'; box.setAttribute('role', 'alert');
        box.innerHTML = `<p>${esc(err.message)}</p><button type="button" class="per-btn">โหลดข้อมูลล่าสุด</button>`;
        box.querySelector('button').addEventListener('click', () => { box.remove(); reload(); });
        document.querySelector('#work-view')?.prepend(box);
    }
    function fail(err, reload) {
        if (err?.name === 'AbortError') return;
        if (err?.status === 413) { toast('ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ กรุณาบีบอัดหรือแบ่งไฟล์เป็นหลายส่วนแล้วอัปโหลดทีละส่วน', true); return; }
        if (err?.status === 409 && err.current) conflict(err, reload); else toast(err?.message || 'เกิดข้อผิดพลาด', true);
    }
    const meter = (used, quota, label) => {
        const pct = quota ? Math.min(100, Math.round(used / quota * 100)) : 0;
        return `<div class="per-meter${pct >= 90 ? ' full' : ''}"><div class="per-meter-bar" role="progressbar" aria-label="${esc(label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"><span style="width:${pct}%"></span></div>
            <small>${esc(label)} ${esc(bytes(used))}${quota ? ' จาก ' + esc(bytes(quota)) + ' (' + pct + '%)' : ' (ไม่จำกัดโควตา)'}</small></div>`;
    };

    // ---------------- #portfolio ----------------
    async function portfolio(root, ctx) {
        const target = ctx.parts[0] && /^\d+$/.test(ctx.parts[0]) ? ctx.parts[0] : '';
        const state = {folder_id: '', year: '', page: 1};
        let me = await api(ctx, '/api/personnel/me' + (target ? '?teacher_id=' + target : ''));
        if (!ctx.alive()) return;
        const host = mount(root);
        if (!me.teacher) { host.innerHTML = '<section class="per glass"><h1>แฟ้มสะสมงาน</h1><p class="per-empty">บัญชีนี้ยังไม่ได้ผูกกับทะเบียนบุคลากร จึงไม่มีแฟ้มสะสมงานของตนเอง ผู้ดูแลเลือกดูแฟ้มของครูได้จากเมนูทะเบียนบุคลากร</p></section>'; return; }
        const folderName = id => me.folders.find(f => f.id === id)?.label || '-';
        const maxFor = id => (me.folders.find(f => f.id === Number(id))?.max_file_mb || 0) * 1048576;
        host.innerHTML = `<section class="per glass"><div class="per-head"><h1>แฟ้มสะสมงาน${me.own ? '' : ' ของ ' + esc(me.teacher.name)}</h1>
            <div class="per-actions"><a class="per-btn" href="#" data-zip>ดาวน์โหลดทั้งปีเป็น zip</a></div></div>
            <div id="per-usage"></div>
            ${me.own ? `<form class="per-form" id="per-upload" novalidate><h2>อัปโหลดไฟล์</h2>
                ${field('แฟ้ม', '<select name="folder_id" required>' + opts(me.folders.map(f => [f.id, f.label]), '') + '</select>')}
                ${field('ปี (พ.ศ.)', `<input name="year" type="number" inputmode="numeric" min="${esc(me.limits.year_min)}" max="${esc(me.limits.year_max)}" value="${beYear()}" required>`)}
                ${field('ไฟล์ (PDF, DOCX, XLSX, PPTX, JPG, PNG, MP4)', '<input name="file" type="file" accept=".pdf,.docx,.xlsx,.pptx,.jpg,.jpeg,.png,.mp4" required>')}
                <p class="per-hint" id="per-limit" aria-live="polite"></p>
                <button type="submit" class="per-btn primary">อัปโหลด</button></form>` : ''}
            <form class="per-filters" id="per-filter">
                ${field('แฟ้ม', '<select name="folder_id">' + opts(me.folders.map(f => [f.id, f.label]), '', 'ทุกแฟ้ม') + '</select>')}
                ${field('ปี (พ.ศ.)', '<input name="year" type="number" inputmode="numeric" placeholder="ทุกปี">')}
            </form>
            <p class="per-count" id="per-count" aria-live="polite"></p><div id="per-rows"></div><div class="per-pager" id="per-pager"></div></section>`;
        const q = sel => host.querySelector(sel);
        let seq = 0, data = {data: [], meta: {total: 0, per_page: 50}};
        const renderUsage = () => { q('#per-usage').innerHTML = meter(me.usage.used_bytes, me.usage.quota_bytes, 'พื้นที่ที่ใช้'); };
        const row = f => {
            const own = me.own;
            const reviewer = me.is_reviewer && !own && f.status !== 'deleted';
            return `<article class="per-file" data-id="${esc(f.id)}" data-version="${esc(f.version)}">
                <div class="per-file-main"><strong>${esc(f.original_name)}</strong>
                <small>${esc(folderName(f.folder_id))} | ปี ${esc(f.year)} | ${esc(bytes(f.size))}${f.status !== 'active' ? ' | ' + esc(STATUS[f.status] || f.status) : ''}</small></div>
                <span class="per-badge ${esc(f.review_status)}">${esc(REVIEW[f.review_status] || f.review_status)}</span>
                ${f.review_note ? `<p class="per-note">${esc(f.review_note)}${f.reviewed_by ? ' (' + esc(f.reviewed_by) + ')' : ''}</p>` : ''}
                <div class="per-file-actions"><a class="per-btn" href="${esc(href('/api/personnel/files/' + f.id + '/download'))}">ดาวน์โหลด</a>
                ${own && f.status !== 'deleted' && !(f.review_status === 'approved') ? '<button type="button" class="per-btn danger" data-del>ลบ</button>' : ''}</div>
                ${reviewer ? `<form class="per-review" data-review><select name="review_status" aria-label="ผลการตรวจ">${opts(Object.entries(REVIEW), f.review_status)}</select>
                    <input name="review_note" maxlength="1000" placeholder="ข้อความถึงครู (ต้องกรอกเมื่อให้แก้ไข)" value="${esc(f.review_note || '')}" aria-label="ข้อความตรวจ">
                    <button type="submit" class="per-btn">บันทึกผลตรวจ</button></form>` : ''}</article>`;
        };
        const render = () => {
            renderUsage();
            const last = Math.max(1, Math.ceil(data.meta.total / data.meta.per_page));
            q('#per-count').textContent = `พบ ${data.meta.total} ไฟล์`;
            q('#per-rows').innerHTML = data.data.length ? '<div class="per-files">' + data.data.map(row).join('') + '</div>' : '<p class="per-empty">ยังไม่มีไฟล์ตามเงื่อนไข</p>';
            q('#per-pager').innerHTML = last > 1 ? `<button type="button" class="per-btn" data-page="${state.page - 1}" ${state.page <= 1 ? 'disabled' : ''}>ก่อนหน้า</button><span>หน้า ${state.page} / ${last}</span><button type="button" class="per-btn" data-page="${state.page + 1}" ${state.page >= last ? 'disabled' : ''}>ถัดไป</button>` : '';
            const zip = q('[data-zip]'), y = state.year || beYear();
            zip.href = href('/api/personnel/teachers/' + me.teacher.id + '/zip?year=' + encodeURIComponent(y) + (state.folder_id ? '&folder_id=' + encodeURIComponent(state.folder_id) : ''));
            zip.textContent = 'ดาวน์โหลดปี ' + y + ' เป็น zip';
            limitHint();
        };
        const reload = async () => {
            const mine = ++seq;
            try {
                const p = new URLSearchParams(Object.entries({...state, per_page: 50, teacher_id: me.teacher.id}).filter(([, v]) => v !== '' && v !== null));
                const [files, fresh] = await Promise.all([api(ctx, '/api/personnel/files?' + p), api(ctx, '/api/personnel/me?teacher_id=' + me.teacher.id)]);
                if (mine !== seq || !ctx.alive()) return;
                data = files; me = fresh; render();
            } catch (e) { if (mine === seq) fail(e, reload); }
        };
        const limitHint = () => {
            const form = q('#per-upload'); if (!form) return;
            const max = maxFor(form.elements.folder_id.value), srv = me.limits.server_max_bytes, u = me.usage;
            const left = u && u.quota_bytes ? ` พื้นที่ของคุณเหลือ ${bytes(Math.max(0, u.quota_bytes - u.used_bytes))}` : '';
            q('#per-limit').textContent = `ไฟล์ต่อแฟ้มนี้ไม่เกิน ${mb(max)} MB${srv ? ' (เซิร์ฟเวอร์รับครั้งละไม่เกิน ' + mb(srv) + ' MB)' : ''} ถ้าใหญ่กว่านี้ให้บีบอัดหรือแบ่งไฟล์เป็นหลายส่วน.${left}`;
        };
        q('#per-filter').addEventListener('submit', e => e.preventDefault());
        q('#per-filter').addEventListener('input', e => { state[e.target.name] = e.target.value; state.page = 1; clearTimeout(reload.t); reload.t = setTimeout(reload, 250); });
        host.addEventListener('click', async e => {
            const pg = e.target.closest('[data-page]'); if (pg && !pg.disabled) { state.page = Number(pg.dataset.page); reload(); return; }
            const del = e.target.closest('[data-del]');
            if (del) {
                const card = del.closest('[data-id]');
                if (!window.confirm('ลบไฟล์นี้? ไฟล์จะถูกลบออกจากที่เก็บและคืนพื้นที่')) return;
                try { await api(ctx, '/api/personnel/files/' + card.dataset.id, {method: 'DELETE', body: {version: Number(card.dataset.version)}}); toast('ลบไฟล์แล้ว'); reload(); }
                catch (err) { fail(err, reload); }
            }
        });
        host.addEventListener('submit', async e => {
            const rv = e.target.closest('[data-review]');
            if (rv) {
                e.preventDefault();
                const card = rv.closest('[data-id]');
                try {
                    await api(ctx, '/api/personnel/files/' + card.dataset.id + '/review', {method: 'PUT', body: {version: Number(card.dataset.version), review_status: rv.elements.review_status.value, review_note: rv.elements.review_note.value}});
                    toast('บันทึกผลตรวจแล้ว'); reload();
                } catch (err) { fail(err, reload); }
                return;
            }
            if (e.target.id !== 'per-upload') return;
            e.preventDefault();
            const form = e.target, file = form.elements.file.files[0], btn = form.querySelector('button[type=submit]');
            if (!file) { toast('กรุณาเลือกไฟล์', true); return; }
            const max = maxFor(form.elements.folder_id.value), srv = me.limits.server_max_bytes;
            if (max && file.size > max) { toast(`ไฟล์ใหญ่ ${mb(file.size)} MB เกินกำหนดของแฟ้มนี้ (${mb(max)} MB) กรุณาบีบอัดหรือแบ่งไฟล์`, true); return; }
            if (srv && file.size > srv - 65536) { toast(`ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (${mb(srv)} MB) กรุณาบีบอัดหรือแบ่งไฟล์`, true); return; }
            const u = me.usage, fu = u && u.folders.find(x => x.folder_id === Number(form.elements.folder_id.value));
            if (u && u.quota_bytes && u.used_bytes + file.size > u.quota_bytes) { toast(`พื้นที่แฟ้มสะสมงานเหลือ ${bytes(Math.max(0, u.quota_bytes - u.used_bytes))} ไม่พอสำหรับไฟล์นี้ (${bytes(file.size)})`, true); return; }
            if (fu && fu.quota_bytes && fu.used_bytes + file.size > fu.quota_bytes) { toast(`พื้นที่ของแฟ้มนี้เหลือ ${bytes(Math.max(0, fu.quota_bytes - fu.used_bytes))} ไม่พอสำหรับไฟล์นี้ (${bytes(file.size)})`, true); return; }
            const body = new FormData();
            body.append('folder_id', form.elements.folder_id.value); body.append('year', form.elements.year.value); body.append('file', file);
            btn.disabled = true;
            try {
                await api(ctx, '/api/personnel/files', {method: 'POST', body, idempotencyKey: window.SaanCore.uid()});
                toast('อัปโหลดแล้ว'); form.elements.file.value = ''; state.page = 1; await reload();
            } catch (err) { fail(err, reload); } finally { btn.disabled = false; }
        });
        q('#per-upload')?.elements.folder_id.addEventListener('change', limitHint);
        limitHint();
        await reload();
    }

    // ---------------- #personnel ----------------
    async function personnel(root, ctx) {
        const sub = ctx.parts[0] || '';
        if (sub === 'folders') return folders(root, ctx);
        if (/^\d+$/.test(sub)) return profile(root, ctx, Number(sub));
        const state = {q: '', page: 1};
        const host = mount(root);
        host.innerHTML = `<section class="per glass"><div class="per-head"><h1>ทะเบียนบุคลากร</h1><div class="per-actions"><a class="per-btn" href="#personnel/folders">แฟ้มและโควตา</a></div></div>
            <div id="per-summary"></div>
            <form class="per-filters" id="per-filter">${field('ค้นหา', '<input name="q" type="search" maxlength="80" placeholder="ชื่อ ตำแหน่ง กลุ่มงาน">')}</form>
            <p class="per-count" id="per-count" aria-live="polite"></p><div id="per-rows"></div><div class="per-pager" id="per-pager"></div></section>`;
        const q = sel => host.querySelector(sel);
        let seq = 0;
        const reload = async () => {
            const mine = ++seq;
            try {
                const [list, sum] = await Promise.all([api(ctx, '/api/personnel/staff?' + new URLSearchParams({...state, per_page: 50})), api(ctx, '/api/personnel/summary')]);
                if (mine !== seq || !ctx.alive()) return;
                q('#per-summary').innerHTML = `<p class="per-hint">ไฟล์รอตรวจ ${esc(sum.pending_review)} ไฟล์ | โควตาต่อคน ${sum.teacher_quota_mb ? esc(sum.teacher_quota_mb) + ' MB' : 'ไม่จำกัด'} | ไฟล์แฟ้มสะสมงานรวม ${esc(bytes(sum.school.personnel_bytes))}</p>`;
                q('#per-count').textContent = `พบ ${list.meta.total} คน`;
                q('#per-rows').innerHTML = list.data.length ? '<div class="per-files">' + list.data.map(t => `<article class="per-file">
                    <div class="per-file-main"><strong><a href="#personnel/${esc(t.teacher_id)}">${esc(t.name)}</a></strong>
                    <small>${esc(t.profile?.position || 'ยังไม่กรอกตำแหน่ง')}${t.profile?.work_group ? ' | ' + esc(t.profile.work_group) : ''}${t.profile && !t.profile.show_in_directory ? ' | ซ่อนจากเว็บไซต์' : ''}</small></div>
                    ${meter(t.used_bytes, t.quota_bytes, t.files + ' ไฟล์')}
                    ${t.pending_review ? `<span class="per-badge pending">รอตรวจ ${esc(t.pending_review)}</span>` : ''}
                    <div class="per-file-actions"><a class="per-btn" href="#portfolio/${esc(t.teacher_id)}">เปิดแฟ้มสะสมงาน</a></div></article>`).join('') + '</div>' : '<p class="per-empty">ไม่พบบุคลากร</p>';
                const last = Math.max(1, Math.ceil(list.meta.total / list.meta.per_page));
                q('#per-pager').innerHTML = last > 1 ? `<button type="button" class="per-btn" data-page="${state.page - 1}" ${state.page <= 1 ? 'disabled' : ''}>ก่อนหน้า</button><span>หน้า ${state.page} / ${last}</span><button type="button" class="per-btn" data-page="${state.page + 1}" ${state.page >= last ? 'disabled' : ''}>ถัดไป</button>` : '';
            } catch (e) { if (mine === seq) fail(e, reload); }
        };
        q('#per-filter').addEventListener('submit', e => e.preventDefault());
        q('#per-filter').addEventListener('input', e => { state.q = e.target.value.trim(); state.page = 1; clearTimeout(reload.t); reload.t = setTimeout(reload, 300); });
        host.addEventListener('click', e => { const pg = e.target.closest('[data-page]'); if (pg && !pg.disabled) { state.page = Number(pg.dataset.page); reload(); } });
        await reload();
    }

    async function profile(root, ctx, teacherId) {
        let d = await api(ctx, '/api/personnel/staff/' + teacherId);
        if (!ctx.alive()) return;
        const host = mount(root);
        const render = () => {
            const p = d.profile || {education: [], show_in_directory: true, directory_order: 0};
            host.innerHTML = `<section class="per glass"><div class="per-head"><h1>${esc(d.teacher.name)}</h1><div class="per-actions"><a class="per-btn" href="#personnel">กลับ</a><a class="per-btn" href="#portfolio/${esc(teacherId)}">แฟ้มสะสมงาน</a></div></div>
                ${meter(d.usage.used_bytes, d.usage.quota_bytes, 'พื้นที่แฟ้มสะสมงาน')}
                <form class="per-form" id="per-profile" novalidate>
                    ${field('ตำแหน่ง (เช่น ครู ครูชำนาญการ ครูชำนาญการพิเศษ)', `<input name="position" maxlength="120" value="${esc(p.position || '')}">`)}
                    ${field('วิทยฐานะ', `<input name="academic_standing" maxlength="120" value="${esc(p.academic_standing || '')}">`)}
                    ${field('กลุ่มสาระ / กลุ่มงาน', `<input name="work_group" maxlength="120" value="${esc(p.work_group || '')}">`)}
                    ${field('ฝ่าย / แผนก', `<input name="department" maxlength="120" value="${esc(p.department || '')}">`)}
                    ${field('วันที่เริ่มปฏิบัติงาน', `<input name="start_date" type="date" value="${esc(p.start_date || '')}">`)}
                    ${field('การศึกษา (หนึ่งบรรทัดต่อหนึ่งวุฒิ: ระดับ | ปริญญา | สาขา | สถาบัน | ปี พ.ศ.)', `<textarea name="education" rows="3" maxlength="2000">${esc((p.education || []).map(e => [e.level, e.degree, e.major, e.institution, e.year].map(x => x ?? '').join(' | ')).join('\n'))}</textarea>`)}
                    <label class="per-check"><input type="checkbox" name="show_in_directory"${p.show_in_directory ? ' checked' : ''}><span>แสดงในทำเนียบบุคลากรบนเว็บไซต์ (เฉพาะชื่อ ตำแหน่ง กลุ่มงาน รูป)</span></label>
                    ${field('ลำดับในทำเนียบ (เลขน้อยขึ้นก่อน)', `<input name="directory_order" type="number" min="-9999" max="9999" value="${esc(p.directory_order ?? 0)}">`)}
                    <button type="submit" class="per-btn primary">บันทึกข้อมูล</button></form>
                ${d.profile ? `<form class="per-form" id="per-photo" novalidate><h2>รูปบนเว็บไซต์</h2>${p.photo_url ? `<img class="per-photo" src="${esc(p.photo_url)}" alt="รูป ${esc(d.teacher.name)}" width="120" height="120">` : '<p class="per-hint">ยังไม่มีรูป</p>'}
                    ${field('เลือกรูป (JPG, PNG, WebP ไม่เกิน 5 MB ระบบจะย่อและตัดเป็นสี่เหลี่ยมจัตุรัส)', '<input name="photo" type="file" accept="image/jpeg,image/png,image/webp">')}
                    <div class="per-actions"><button type="submit" class="per-btn">อัปโหลดรูป</button>${p.photo_url ? '<button type="button" class="per-btn danger" data-rmphoto>ลบรูป</button>' : ''}</div></form>` : '<p class="per-hint">บันทึกข้อมูลก่อน จึงเพิ่มรูปได้</p>'}</section>`;
        };
        const reload = async () => { try { d = await api(ctx, '/api/personnel/staff/' + teacherId); if (ctx.alive()) render(); } catch (e) { fail(e, reload); } };
        const parseEdu = text => text.split('\n').map(l => l.trim()).filter(Boolean).slice(0, 10).map(l => {
            const [level, degree, major, institution, year] = l.split('|').map(s => s.trim());
            const row = {level, degree, major, institution}; if (/^\d{4}$/.test(year || '')) row.year = Number(year);
            return Object.fromEntries(Object.entries(row).filter(([, v]) => v));
        });
        host.addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.target;
            try {
                if (f.id === 'per-profile') {
                    const body = {position: f.elements.position.value, academic_standing: f.elements.academic_standing.value, work_group: f.elements.work_group.value, department: f.elements.department.value,
                        start_date: f.elements.start_date.value || null, education: parseEdu(f.elements.education.value), show_in_directory: f.elements.show_in_directory.checked,
                        directory_order: Number(f.elements.directory_order.value || 0)};
                    if (d.profile) body.version = d.profile.version;
                    await api(ctx, '/api/personnel/staff/' + teacherId, {method: 'PUT', body}); toast('บันทึกแล้ว'); await reload();
                } else if (f.id === 'per-photo') {
                    const file = f.elements.photo.files[0]; if (!file) { toast('กรุณาเลือกรูป', true); return; }
                    if (file.size > 5 * 1048576) { toast('รูปต้องไม่เกิน 5 MB', true); return; }
                    const body = new FormData(); body.append('version', d.profile.version); body.append('photo', file);
                    await api(ctx, '/api/personnel/staff/' + teacherId + '/photo', {method: 'POST', body}); toast('อัปโหลดรูปแล้ว'); await reload();
                }
            } catch (err) { fail(err, reload); }
        });
        host.addEventListener('click', async e => {
            if (!e.target.closest('[data-rmphoto]')) return;
            try { await api(ctx, '/api/personnel/staff/' + teacherId + '/photo', {method: 'DELETE', body: {version: d.profile.version}}); toast('ลบรูปแล้ว'); await reload(); }
            catch (err) { fail(err, reload); }
        });
        render();
    }

    async function folders(root, ctx) {
        let [list, sum] = await Promise.all([api(ctx, '/api/personnel/folders'), api(ctx, '/api/personnel/summary')]);
        if (!ctx.alive()) return;
        const host = mount(root);
        const render = () => {
            host.innerHTML = `<section class="per glass"><div class="per-head"><h1>แฟ้มและโควตา</h1><div class="per-actions"><a class="per-btn" href="#personnel">กลับ</a></div></div>
                <form class="per-form" id="per-quota">${field('โควตารวมต่อครู 1 คน (MB, 0 = ไม่จำกัด)', `<input name="teacher_quota_mb" type="number" min="0" max="1048576" value="${esc(sum.teacher_quota_mb)}">`)}
                    <p class="per-hint">โควตารวมทั้งโรงเรียนตั้งที่ SAAN_STORAGE_QUOTA_MB (ใช้ร่วมกับคลังสื่อ) ตอนนี้ใช้ไป ${esc(bytes(sum.school.used_bytes))}${sum.school.quota_bytes ? ' จาก ' + esc(bytes(sum.school.quota_bytes)) : ''}</p>
                    <button type="submit" class="per-btn primary">บันทึกโควตา</button></form>
                <h2>แฟ้มสะสมงาน</h2><div class="per-files">${list.data.map(f => `<form class="per-file per-folder" data-id="${esc(f.id)}" data-version="${esc(f.version)}">
                    <div class="per-folder-grid">${field('ชื่อแฟ้ม', `<input name="label" maxlength="160" value="${esc(f.label)}" required>`)}
                    ${field('เก็บกี่ปี (ว่าง = ไม่ย้ายเข้าที่เก็บถาวร)', `<input name="retention_years" type="number" min="1" max="100" value="${esc(f.retention_years ?? '')}">`)}
                    ${field('ไฟล์ใหญ่สุด (MB)', `<input name="max_file_mb" type="number" min="1" max="2048" value="${esc(f.max_file_mb)}" required>`)}
                    ${field('โควตาแฟ้มต่อคน (MB, ว่าง = ไม่แยก)', `<input name="quota_mb_per_teacher" type="number" min="1" value="${esc(f.quota_mb_per_teacher ?? '')}">`)}</div>
                    <div class="per-actions"><button type="submit" class="per-btn">บันทึก</button><button type="button" class="per-btn danger" data-delfolder>ลบแฟ้ม</button></div></form>`).join('')}</div>
                <form class="per-form" id="per-newfolder"><h2>เพิ่มแฟ้ม</h2>${field('ชื่อแฟ้ม', '<input name="label" maxlength="160" required>')}${field('ไฟล์ใหญ่สุด (MB)', '<input name="max_file_mb" type="number" min="1" max="2048" value="50" required>')}
                    <button type="submit" class="per-btn primary">เพิ่มแฟ้ม</button></form>
                <p class="per-hint">ไฟล์เก่ากว่าอายุเก็บของแฟ้มจะถูกย้ายเข้าที่เก็บถาวรโดยคำสั่ง saan:personnel-retention (ไม่ลบไฟล์)</p></section>`;
        };
        const reload = async () => { try { [list, sum] = await Promise.all([api(ctx, '/api/personnel/folders'), api(ctx, '/api/personnel/summary')]); if (ctx.alive()) render(); } catch (e) { fail(e, reload); } };
        const num = v => v === '' ? null : Number(v);
        host.addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.target;
            try {
                if (f.id === 'per-quota') { await api(ctx, '/api/personnel/settings', {method: 'PUT', body: {teacher_quota_mb: Number(f.elements.teacher_quota_mb.value || 0)}}); toast('บันทึกโควตาแล้ว'); await reload(); }
                else if (f.id === 'per-newfolder') { await api(ctx, '/api/personnel/folders', {method: 'POST', body: {label: f.elements.label.value, max_file_mb: Number(f.elements.max_file_mb.value)}}); toast('เพิ่มแฟ้มแล้ว'); await reload(); }
                else if (f.dataset.id) {
                    await api(ctx, '/api/personnel/folders/' + f.dataset.id, {method: 'PUT', body: {version: Number(f.dataset.version), label: f.elements.label.value, retention_years: num(f.elements.retention_years.value),
                        max_file_mb: Number(f.elements.max_file_mb.value), quota_mb_per_teacher: num(f.elements.quota_mb_per_teacher.value)}});
                    toast('บันทึกแล้ว'); await reload();
                }
            } catch (err) { fail(err, reload); }
        });
        host.addEventListener('click', async e => {
            const b = e.target.closest('[data-delfolder]'); if (!b) return;
            const f = b.closest('[data-id]');
            if (!window.confirm('ลบแฟ้มนี้? ลบได้เฉพาะแฟ้มที่ยังไม่มีไฟล์')) return;
            try { await api(ctx, '/api/personnel/folders/' + f.dataset.id, {method: 'DELETE', body: {version: Number(f.dataset.version)}}); toast('ลบแฟ้มแล้ว'); await reload(); }
            catch (err) { fail(err, reload); }
        });
        render();
    }

    window.SaanWork.register('portfolio', {title: 'แฟ้มสะสมงาน', render: portfolio});
    window.SaanWork.register('personnel', {title: 'ทะเบียนบุคลากร', render: personnel});
})();
