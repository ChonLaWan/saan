'use strict';
// learning-links module (M22): SaanWork page "learning". Every interpolated value goes through SaanCore.esc; no inline handlers.
(() => {
    const C = window.SaanCore;
    const esc = v => C.esc(v);
    const A = '/api/learning';
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');

    const opt = (pairs, sel) => pairs.map(([v, l]) => `<option value="${esc(v)}"${String(v) === String(sel ?? '') ? ' selected' : ''}>${esc(l)}</option>`).join('');

    window.SaanWork.register('learning', {
        title: 'ลิงก์การเรียนรู้',
        async render(root, ctx) {
            const st = {opts: null, links: [], scope: '', editing: null, access: null, code: null};
            root.innerHTML = '<section class="lrn-page glass"><h1>ลิงก์การเรียนรู้</h1><p class="lrn-lead">ลิงก์ระบบและแอปภายนอกสำหรับห้องเรียนหรือรายวิชา เปิดได้เฉพาะ https</p><div class="lrn-msg" role="status"></div><div class="lrn-body">กำลังโหลด...</div></section>';
            const body = root.querySelector('.lrn-body');
            const msg = (kind, text) => { const n = root.querySelector('.lrn-msg'); n.innerHTML = text ? `<div class="lrn-note lrn-${esc(kind)}" role="${kind === 'error' ? 'alert' : 'status'}">${esc(text)}</div>` : ''; };

            const query = () => {
                const [type, id] = st.scope.split(':');
                return type === 'c' ? '?classroom_id=' + encodeURIComponent(id) : type === 'k' ? '?course_id=' + encodeURIComponent(id) : '';
            };
            const classroomOfScope = () => {
                const [type, id] = st.scope.split(':');
                if (type === 'c') return Number(id);
                if (type === 'k') return st.opts.courses.find(c => c.id === Number(id))?.classroom_id || null;
                return null;
            };
            async function load() {
                if (!st.opts) st.opts = await ctx.api(A + '/options');
                if (!ctx.alive()) return;
                const res = await ctx.api(A + '/links' + query());
                if (!ctx.alive()) return;
                st.links = res.data;
                const cid = classroomOfScope();
                st.access = cid ? await ctx.api(A + '/classrooms/' + cid + '/access').catch(() => null) : null;
                if (!ctx.alive()) return;
                draw();
            }

            function draw() {
                const o = st.opts;
                const scopes = [['', o.can_school_wide ? 'ทุกห้อง (ที่เข้าถึงได้)' : 'ทุกห้องของฉัน']]
                    .concat(o.classrooms.map(c => ['c:' + c.id, 'ห้อง ' + c.name]))
                    .concat(o.courses.map(c => ['k:' + c.id, 'วิชา ' + c.label]));
                const e = st.editing;
                const cid = classroomOfScope();
                body.innerHTML = `
                <div class="lrn-box"><label class="lrn-field">เลือกห้องหรือรายวิชา<select data-role="scope">${opt(scopes, st.scope)}</select></label></div>
                ${cid ? accessBox(cid) : ''}
                <div class="lrn-box"><h2>${e && e.id ? 'แก้ไขลิงก์' : 'เพิ่มลิงก์'}</h2>
                    <form class="lrn-form" data-role="form" novalidate>
                        <label class="lrn-field">ชื่อลิงก์<input name="title" maxlength="160" required value="${esc(e?.title || '')}"></label>
                        <label class="lrn-field">ที่อยู่ลิงก์ (https://)<input name="url" type="url" inputmode="url" maxlength="500" required value="${esc(e?.url || '')}" placeholder="https://"></label>
                        <label class="lrn-field">ชนิด<select name="kind">${opt(Object.entries(o.kinds), e?.kind || 'app')}</select></label>
                        ${o.sso_clients.length ? `<label class="lrn-field">แอปนี้ใช้บัญชีโรงเรียนเข้าสู่ระบบ<select name="api_client_id">${opt([['', 'ไม่ใช่']].concat(o.sso_clients.map(c => [c.id, c.name])), e?.api_client_id)}</select></label>` : ''}
                        <label class="lrn-field">ลำดับ<input name="sort" type="number" min="0" max="9999" value="${esc(e?.sort ?? 0)}"></label>
                        ${e?.id ? '' : `<label class="lrn-field">ปลายทาง<select name="target">${opt(targets(), cid ? (st.scope.startsWith('k:') ? st.scope : 'c:' + cid) : '')}</select></label>`}
                        <div class="lrn-actions"><button class="lrn-btn lrn-primary" type="submit">บันทึก</button>${e ? '<button class="lrn-btn" type="button" data-act="cancel">ยกเลิกการแก้ไข</button>' : ''}</div>
                    </form></div>
                <div class="lrn-box"><h2>ลิงก์ทั้งหมด (${st.links.length})</h2>${list()}</div>`;
            }

            const targets = () => {
                const o = st.opts;
                return (o.can_school_wide ? [['', 'ทั้งโรงเรียน']] : []).concat(o.classrooms.map(c => ['c:' + c.id, 'ห้อง ' + c.name])).concat(o.courses.map(c => ['k:' + c.id, 'วิชา ' + c.label]));
            };

            function accessBox(cid) {
                const a = st.access;
                const link = base() + '/learn/' + cid;
                const manage = !!st.opts.classrooms.find(c => c.id === cid)?.can_manage_code;
                return `<div class="lrn-box"><h2>รหัสให้นักเรียนเปิดดูลิงก์</h2>
                    <p class="lrn-lead">หน้าสำหรับนักเรียน: <span class="lrn-url">${esc(link)}</span> นักเรียนกรอกรหัสห้อง 8 ตัวเพื่อดูชื่อและลิงก์เท่านั้น ไม่เห็นข้อมูลนักเรียน</p>
                    ${st.code ? `<p class="lrn-code" role="status">รหัสใหม่: <strong>${esc(st.code.code.slice(0, 4) + '-' + st.code.code.slice(4))}</strong> หมดอายุ ${esc(C.date(String(st.code.expires_at).slice(0, 10)))} (แสดงครั้งเดียว จดไว้ก่อนปิดหน้านี้)</p>` : ''}
                    <p>${a?.active ? 'สถานะ: เปิดใช้ หมดอายุ ' + esc(C.date(String(a.expires_at).slice(0, 10))) : a?.exists ? 'สถานะ: รหัสหมดอายุแล้ว' : 'สถานะ: ยังไม่มีรหัส'}</p>
                    ${manage ? `<div class="lrn-actions"><label class="lrn-field lrn-days">อายุรหัส (วัน)<input type="number" min="1" max="365" value="180" data-role="days"></label>
                    <button class="lrn-btn lrn-primary" type="button" data-act="issue">${a?.exists ? 'สร้างรหัสใหม่ (รหัสเดิมใช้ไม่ได้)' : 'สร้างรหัส'}</button>
                    ${a?.exists ? '<button class="lrn-btn" type="button" data-act="revoke">ยกเลิกรหัส</button>' : ''}</div>` : '<p class="lrn-lead">เฉพาะครูประจำชั้น ผู้ดูแลระบบ และผู้อำนวยการสร้างหรือยกเลิกรหัสห้องได้</p>'}</div>`;
            }

            function list() {
                if (!st.links.length) return '<p class="lrn-empty">ยังไม่มีลิงก์</p>';
                return '<ul class="lrn-list">' + st.links.map(l => `<li class="lrn-item">
                    <div><strong>${esc(l.title)}</strong>
                    <div class="lrn-meta">${esc(l.kind_label)} · ${esc(l.classroom_name ? 'ห้อง ' + l.classroom_name : 'ทั้งโรงเรียน')}${l.course_id ? ' · รายวิชา' : ''}${l.sso ? ' · <span class="lrn-sso">ใช้บัญชีโรงเรียน</span>' : ''}</div>
                    <a href="${esc(l.url)}" target="_blank" rel="noopener noreferrer" class="lrn-url">${esc(l.url)}</a></div>
                    ${l.can_edit ? `<div class="lrn-actions"><button class="lrn-btn" type="button" data-act="edit" data-id="${esc(l.id)}">แก้ไข</button><button class="lrn-btn lrn-danger" type="button" data-act="del" data-id="${esc(l.id)}">ลบ</button></div>` : ''}
                </li>`).join('') + '</ul>';
            }

            function fail(e) {
                if (!e || e.name === 'AbortError') return;
                if (e.status === 409) { msg('error', e.message + ' (โหลดข้อมูลใหม่แล้ว)'); st.editing = null; load().catch(() => {}); return; }
                msg('error', e.message || 'เกิดข้อผิดพลาด');
            }

            root.addEventListener('change', ev => {
                if (ev.target.matches('[data-role="scope"]')) { st.scope = ev.target.value; st.code = null; msg('', ''); load().catch(fail); }
            }, {signal: ctx.signal});

            root.addEventListener('submit', async ev => {
                const f = ev.target.closest('[data-role="form"]');
                if (!f) return;
                ev.preventDefault();
                const b = f.querySelector('button[type=submit]');
                b.disabled = true;
                const fd = new FormData(f);
                const payload = {title: fd.get('title'), url: fd.get('url'), kind: fd.get('kind'), sort: Number(fd.get('sort') || 0)};
                if (f.querySelector('[name=api_client_id]')) payload.api_client_id = fd.get('api_client_id') || null;
                try {
                    if (st.editing?.id) {
                        await ctx.api(A + '/links/' + st.editing.id, {method: 'PUT', body: {...payload, version: st.editing.version}});
                    } else {
                        const [type, id] = String(fd.get('target') || '').split(':');
                        if (type === 'c') payload.classroom_id = Number(id); else if (type === 'k') payload.course_id = Number(id);
                        await ctx.api(A + '/links', {method: 'POST', body: payload, idempotencyKey: C.uid()});
                    }
                    st.editing = null;
                    msg('ok', 'บันทึกแล้ว');
                    await load();
                } catch (e) { fail(e); b.disabled = false; }
            }, {signal: ctx.signal});

            root.addEventListener('click', async ev => {
                const b = ev.target.closest('[data-act]');
                if (!b || !root.contains(b) || b.disabled) return;
                const act = b.dataset.act, id = Number(b.dataset.id);
                try {
                    if (act === 'edit') { st.editing = st.links.find(l => l.id === id) || null; draw(); body.querySelector('[name=title]')?.focus(); return; }
                    if (act === 'cancel') { st.editing = null; draw(); return; }
                    b.disabled = true;
                    if (act === 'del') {
                        const l = st.links.find(x => x.id === id);
                        if (!l || !confirm('ลบลิงก์ "' + l.title + '" ใช่หรือไม่')) { b.disabled = false; return; }
                        await ctx.api(A + '/links/' + id, {method: 'DELETE', body: {version: l.version}});
                        msg('ok', 'ลบแล้ว');
                    } else if (act === 'issue') {
                        const cid = classroomOfScope();
                        const days = Number(body.querySelector('[data-role="days"]')?.value || 180);
                        if (st.access?.exists && !confirm('รหัสเดิมจะใช้ไม่ได้ทันที ต้องการสร้างรหัสใหม่ใช่หรือไม่')) { b.disabled = false; return; }
                        st.code = await ctx.api(A + '/classrooms/' + cid + '/access', {method: 'POST', body: {days}});
                    } else if (act === 'revoke') {
                        if (!confirm('ยกเลิกรหัส นักเรียนจะเปิดหน้าลิงก์ไม่ได้ ใช่หรือไม่')) { b.disabled = false; return; }
                        await ctx.api(A + '/classrooms/' + classroomOfScope() + '/access', {method: 'DELETE'});
                        st.code = null;
                        msg('ok', 'ยกเลิกรหัสแล้ว');
                    }
                    await load();
                } catch (e) { fail(e); b.disabled = false; }
            }, {signal: ctx.signal});

            await load();
        },
    });
})();
