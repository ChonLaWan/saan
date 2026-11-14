'use strict';
// leave-duty module (M14 leave, M24 duty): SaanWork pages "leave", "leave-approve", "duty".
// Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners on the page root).
(() => {
    const C = window.SaanCore;
    const esc = v => C.esc(v);
    const role = () => { try { return JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').role || ''; } catch { return ''; } };
    const approver = () => role() === 'admin' || role() === 'principal';
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const L = '/api/leave', D = '/api/duty';

    const STATUS = {draft: 'ร่าง', submitted: 'รออนุมัติ', approved: 'อนุมัติแล้ว', rejected: 'ไม่อนุมัติ', withdrawn: 'ถอนคำขอแล้ว', cancel_requested: 'ขอยกเลิก (รออนุมัติ)', cancelled: 'ยกเลิกแล้ว'};
    const ACT = {submit: 'ส่งใบลา', withdraw: 'ถอนคำขอ', approve: 'อนุมัติ', reject: 'ไม่อนุมัติ', request_cancel: 'ขอยกเลิก', approve_cancel: 'อนุมัติให้ยกเลิก', reject_cancel: 'ไม่อนุมัติการยกเลิก', edit: 'แก้ไข', delete: 'ลบร่าง', resubmit: 'แก้ไขและยื่นใหม่'};
    const NEEDS_REASON = new Set(['reject', 'request_cancel', 'reject_cancel']);
    const NEEDS_CONFIRM = new Set(['withdraw', 'delete', 'approve_cancel']);
    const HIST = {'leave.create': 'สร้างใบลา', 'leave.update': 'แก้ไขร่าง', 'leave.submit': 'ส่งใบลา', 'leave.withdraw': 'ถอนคำขอ', 'leave.approve': 'อนุมัติ', 'leave.reject': 'ไม่อนุมัติ',
        'leave.request_cancel': 'ขอยกเลิก', 'leave.approve_cancel': 'อนุมัติการยกเลิก', 'leave.reject_cancel': 'ไม่อนุมัติการยกเลิก', 'leave.attach': 'แนบไฟล์', 'leave.detach': 'ลบไฟล์แนบ',
        'leave.resubmit': 'ยื่นซ้ำ', 'leave.resubmitted': 'ถูกยื่นซ้ำเป็นใบใหม่', 'leave.download': 'ดาวน์โหลดไฟล์แนบ'};
    const KINDS = {morning: 'เวรเช้า', lunch: 'เวรกลางวัน', evening: 'เวรเย็น', overnight: 'เวรกลางคืน', event: 'เวรกิจกรรม', other: 'เวรอื่น ๆ'};
    const SWAP_ACT = {accept: 'ตอบรับ', decline: 'ปฏิเสธ', cancel: 'ยกเลิกคำขอ', approve: 'อนุมัติ', reject: 'ไม่อนุมัติ'};
    const DAYS = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

    const dshort = iso => C.date(iso, {day: 'numeric', month: 'short', year: 'numeric'});
    const pad = n => String(n).padStart(2, '0');
    const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const parse = s => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(); };
    const addDays = (s, n) => { const d = parse(s); d.setDate(d.getDate() + n); return iso(d); };
    const dow = s => DAYS[parse(s).getDay()];
    const num = v => (Math.round(Number(v) * 10) / 10).toString();
    const range = r => r.starts_on === r.ends_on ? dshort(r.starts_on) : dshort(r.starts_on) + ' - ' + dshort(r.ends_on);
    const fyNow = () => { const t = C.today(); return Number(t.slice(0, 4)) + (Number(t.slice(5, 7)) >= 10 ? 1 : 0) + 543; };
    const opt = (pairs, sel) => pairs.map(([v, l]) => `<option value="${esc(v)}"${String(v) === String(sel ?? '') ? ' selected' : ''}>${esc(l)}</option>`).join('');
    const badge = (cls, text) => `<span class="ld-badge ${esc(cls)}">${esc(text)}</span>`;

    // ------------------------------------------------------------------ shared shell
    function notice(root, kind, html) {
        const n = root.querySelector('.ld-msg');
        if (n) n.innerHTML = html ? `<div class="ld-notice ld-${esc(kind)}" role="${kind === 'error' ? 'alert' : 'status'}">${html}</div>` : '';
        if (html && n) n.scrollIntoView?.({block: 'nearest'});
    }
    const ok = (root, text) => notice(root, 'ok', esc(text));
    function fail(root, e, reload) {
        if (!e || e.name === 'AbortError') return;
        if (e.status === 409) {
            notice(root, 'error', `<p>${esc(e.message)}</p><button type="button" class="ld-btn" data-act="reload">โหลดข้อมูลล่าสุด</button>`);
            return;
        }
        const list = e.data?.errors ? Object.values(e.data.errors).flat() : [e.message || 'เกิดข้อผิดพลาด'];
        notice(root, 'error', `<ul>${list.map(x => `<li>${esc(x)}</li>`).join('')}</ul>`);
    }

    // Attaches delegated click/submit/change handlers once per render of the page root.
    function wire(root, ctx, h) {
        root.addEventListener('click', async ev => {
            const b = ev.target.closest('[data-act]');
            if (!b || !root.contains(b)) return;
            ev.preventDefault();
            if (b.dataset.act === 'reload') { notice(root, 'ok', ''); await h.reload(); return; }
            if (b.disabled) return;
            b.disabled = true;
            try { await h.click(b.dataset.act, b); } catch (e) { fail(root, e); } finally { b.disabled = false; }
        }, {signal: ctx.signal});
        root.addEventListener('submit', async ev => {
            const f = ev.target.closest('form[data-form]');
            if (!f || !root.contains(f)) return;
            ev.preventDefault();
            const btn = ev.submitter || f.querySelector('button[type=submit]');
            const all = [...f.querySelectorAll('button')];
            all.forEach(x => { x.disabled = true; });
            try { await h.submit(f.dataset.form, f, btn); } catch (e) { fail(root, e); } finally { all.forEach(x => { x.disabled = false; }); }
        }, {signal: ctx.signal});
        root.addEventListener('change', ev => { h.change?.(ev.target); }, {signal: ctx.signal});
        root.addEventListener('input', ev => { h.input?.(ev.target); }, {signal: ctx.signal});
    }

    // ------------------------------------------------------------------ leave cards
    function leaveCard(r, mode) {
        const half = r.half_day ? (r.half_day === 'am' ? ' (ครึ่งวันเช้า)' : ' (ครึ่งวันบ่าย)') : '';
        const acts = (r.can || []).map(a => `<button type="button" class="ld-btn${a === 'approve' || a === 'submit' ? ' primary' : ''}${a === 'reject' || a === 'delete' ? ' danger' : ''}" data-act="leave-${esc(a)}" data-id="${r.id}" data-version="${r.version}">${esc(ACT[a] || a)}</button>`).join('');
        const file = r.has_attachment ? `<a class="ld-btn" href="${esc(base() + L + '/requests/' + r.id + '/attachment')}" download>ไฟล์แนบ${r.attachment_name ? ' (' + esc(r.attachment_name) + ')' : ''}</a>` : '';
        return `<article class="ld-card" data-card="${r.id}">
            <header class="ld-card-head"><b>${esc(r.type_label)}</b>${badge('ld-s-' + r.status, r.status_label || STATUS[r.status] || r.status)}</header>
            ${mode === 'approve' ? `<p class="ld-who">${esc(r.teacher_name || '')}</p>` : ''}
            <p class="ld-dates">${esc(range(r))}${esc(half)} <span class="ld-days">${esc(num(r.days))} วันทำการ</span></p>
            <p class="ld-reason">${esc(r.reason)}</p>
            ${r.cancel_reason && ['cancel_requested', 'cancelled'].includes(r.status) ? `<p class="ld-note">เหตุผลที่ขอยกเลิก: ${esc(r.cancel_reason)}</p>` : ''}
            ${r.decision_note ? `<p class="ld-note">หมายเหตุผู้พิจารณา: ${esc(r.decision_note)}</p>` : ''}
            ${r.resubmitted_from ? `<p class="ld-note">ยื่นซ้ำจากใบลา #${r.resubmitted_from}</p>` : ''}${r.resubmitted_to ? `<p class="ld-note">ยื่นซ้ำเป็นใบลา #${r.resubmitted_to} แล้ว</p>` : ''}
            <div class="ld-actions">${acts}${file}<button type="button" class="ld-btn" data-act="leave-detail" data-id="${r.id}">รายละเอียด</button></div>
            <div class="ld-slot" data-slot="${r.id}"></div></article>`;
    }

    function reasonForm(kind, id, version, action, required, max = kind === 'swap' ? 300 : 500) {
        const label = NEEDS_CONFIRM.has(action) && !NEEDS_REASON.has(action) ? 'ยืนยัน' : 'ส่ง';
        return `<form class="ld-inline" data-form="${esc(kind)}-reason" data-id="${id}" data-version="${version}" data-action="${esc(action)}">
            ${NEEDS_CONFIRM.has(action) && !NEEDS_REASON.has(action) ? `<p>ยืนยัน “${esc(ACT[action] || SWAP_ACT[action] || action)}” ?</p>` : `<label class="ld-field"><span>เหตุผล${required ? ' (จำเป็น)' : ' (ไม่บังคับ)'}</span><textarea name="reason" rows="2" maxlength="${max}"${required ? ' required minlength="3"' : ''}></textarea></label>`}
            <div class="ld-actions"><button type="submit" class="ld-btn primary">${label}</button><button type="button" class="ld-btn" data-act="slot-close">ยกเลิก</button></div></form>`;
    }

    function detailHtml(r) {
        const hist = (r.history || []).map(e => `<li><b>${esc(HIST[e.action] || e.action)}</b> ${esc(e.actor || '')} <time>${esc(String(e.at || '').slice(0, 16).replace('T', ' '))}</time>${e.reason ? `<br><span>${esc(e.reason)}</span>` : ''}</li>`).join('');
        const conf = (r.duty_conflicts || []).length ? `<div class="ld-notice ld-warn"><b>มีเวรในช่วงลา</b><ul>${r.duty_conflicts.map(d => `<li>${esc(dshort(d.date))} ${esc(d.starts_at)}-${esc(d.ends_at)} ${esc(d.location)}</li>`).join('')}</ul></div>` : '';
        const q = r.quota ? (r.quota.types || []).filter(t => t.code === r.type).map(t => `<p class="ld-note">โควตา${esc(t.label)} ปี ${esc(r.quota.fiscal_year)}: ใช้แล้ว ${esc(num(t.used))} รออนุมัติ ${esc(num(t.pending))}${t.quota === null ? ' (ไม่จำกัด)' : ' จาก ' + esc(num(t.quota)) + ' วัน'}</p>`).join('') : '';
        return `<div class="ld-detail">${conf}${q}<h4>ประวัติ</h4><ol class="ld-hist">${hist}</ol><button type="button" class="ld-btn" data-act="slot-close">ปิด</button></div>`;
    }

    // ------------------------------------------------------------------ page: leave (my requests)
    async function leavePage(root, ctx) {
        const st = {fy: fyNow(), types: [], quota: null, list: [], editing: null, open: false, preview: null, timer: 0};
        root.innerHTML = `<section class="ld-page glass"><h1>ใบลาของฉัน</h1><p class="ld-lead">ยื่นใบลาจากมือถือได้ ระบบนับวันทำการ (ไม่รวมเสาร์-อาทิตย์และวันหยุดของโรงเรียน) และตรวจสิทธิ์การลาให้</p>
            <div class="ld-msg" aria-live="polite"></div><div data-part="quota"></div><div class="ld-actions"><button type="button" class="ld-btn primary" data-act="form-open">ยื่นใบลาใหม่</button></div>
            <div data-part="form"></div><h2>ใบลาทั้งหมด</h2><div data-part="list"><p class="ld-loading">กำลังโหลด…</p></div></section>`;
        const part = n => root.querySelector(`[data-part=${n}]`);

        const loadTypes = async () => { st.types = (await ctx.api(L + '/types')).data; };
        const loadQuota = async () => {
            try { st.quota = await ctx.api(L + '/quota?fiscal_year=' + st.fy); } catch (e) { st.quota = e.status === 422 ? {error: e.message, types: [], fiscal_years: [fyNow()]} : null; if (!st.quota) throw e; }
        };
        const loadList = async () => { st.list = (await ctx.api(L + '/requests?scope=mine&per_page=100')).data; };
        const drawQuota = () => {
            const q = st.quota;
            if (!q) { part('quota').innerHTML = ''; return; }
            if (q.error) { part('quota').innerHTML = `<div class="ld-notice ld-warn">${esc(q.error)}</div>`; return; }
            const years = (q.fiscal_years || [st.fy]);
            part('quota').innerHTML = `<div class="ld-box"><label class="ld-field ld-inlinefield"><span>ปีงบประมาณ (พ.ศ.)</span><select data-fy>${years.map(y => `<option${y === st.fy ? ' selected' : ''}>${y}</option>`).join('')}</select></label>
                <ul class="ld-quota">${q.types.map(t => `<li><b>${esc(t.label)}</b><span>ใช้แล้ว ${esc(num(t.used))}${t.pending ? ' + รออนุมัติ ' + esc(num(t.pending)) : ''}${t.quota === null ? ' วัน (ไม่จำกัด)' : ' / ' + esc(num(t.quota)) + ' วัน'}</span>${t.quota === null ? '' : `<progress max="${esc(t.quota || 1)}" value="${esc(Math.min(t.quota, t.used + t.pending))}"></progress>`}</li>`).join('')}</ul></div>`;
        };
        const drawList = () => {
            part('list').innerHTML = st.list.length ? st.list.map(r => leaveCard(r, 'mine')).join('') : '<p class="ld-empty">ยังไม่มีใบลา</p>';
        };
        const drawForm = () => {
            if (!st.open) { part('form').innerHTML = ''; return; }
            const e = st.editing, r = e?.row || {};
            const title = e?.mode === 'resubmit' ? 'แก้ไขและยื่นใหม่ (ใบลา #' + r.id + ')' : e?.mode === 'edit' ? 'แก้ไขร่างใบลา #' + r.id : 'ยื่นใบลาใหม่';
            const half = !!r.half_day;
            part('form').innerHTML = `<form class="ld-form ld-box" data-form="leave" novalidate><h3>${esc(title)}</h3>
                <label class="ld-field"><span>ประเภทการลา</span><select name="type" required>${opt(st.types.map(t => [t.code, t.label]), r.type)}</select></label>
                <label class="ld-field"><span>วันที่เริ่มลา</span><input type="date" name="starts_on" required value="${esc(r.starts_on || C.today())}"></label>
                <label class="ld-field" data-end${half ? ' hidden' : ''}><span>ถึงวันที่</span><input type="date" name="ends_on" required value="${esc(r.ends_on || C.today())}"></label>
                <div class="ld-field ld-half"><label class="ld-check"><input type="checkbox" name="half"${half ? ' checked' : ''}> ลาครึ่งวัน</label>
                    <select name="half_day" aria-label="ช่วงของวัน"${half ? '' : ' hidden'}>${opt([['am', 'ช่วงเช้า'], ['pm', 'ช่วงบ่าย']], r.half_day)}</select></div>
                <label class="ld-field"><span>เหตุผลการลา</span><textarea name="reason" rows="3" maxlength="500" required>${esc(r.reason || '')}</textarea></label>
                ${e?.mode === 'resubmit' ? '<p class="ld-note">ไฟล์แนบเดิมจะถูกคัดลอกไปยังใบลาใหม่ เลือกไฟล์ด้านล่างเพื่อใช้ไฟล์ใหม่แทน (เช่น ใบรับรองแพทย์ฉบับใหม่)</p>' : ''}<label class="ld-field"><span>ไฟล์แนบ (PDF, JPG, PNG)</span><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">${r.has_attachment ? `<small>มีไฟล์แนบแล้ว: ${esc(r.attachment_name || '')} (เลือกไฟล์ใหม่เพื่อเปลี่ยน)</small>` : ''}</label>
                <div class="ld-preview" aria-live="polite"></div>
                <div class="ld-actions"><button type="submit" class="ld-btn primary" data-send="1">${e?.mode === 'resubmit' ? 'ยื่นใหม่' : 'ส่งใบลา'}</button><button type="submit" class="ld-btn" data-send="0">บันทึกเป็นร่าง</button><button type="button" class="ld-btn" data-act="form-close">ปิดฟอร์ม</button></div></form>`;
            queuePreview();
        };
        const reloadAll = async () => { await Promise.all([loadQuota(), loadList()]); if (ctx.alive()) { drawQuota(); drawList(); } };

        function formBody(f) {
            const v = Object.fromEntries(new FormData(f));
            const half = f.querySelector('[name=half]').checked;
            return {type: v.type, starts_on: v.starts_on, ends_on: half ? v.starts_on : v.ends_on, half_day: half ? v.half_day : null, reason: String(v.reason || '').trim()};
        }
        function queuePreview() {
            clearTimeout(st.timer);
            st.timer = setTimeout(async () => {
                const f = part('form').querySelector('form');
                if (!f || !ctx.alive()) return;
                const b = formBody(f), box = f.querySelector('.ld-preview');
                if (!b.starts_on || !b.ends_on) { box.textContent = ''; return; }
                const qs = new URLSearchParams({type: b.type, starts_on: b.starts_on, ends_on: b.ends_on});
                if (b.half_day) qs.set('half_day', b.half_day);
                if (st.editing?.row?.id && st.editing.mode === 'edit') qs.set('except_id', st.editing.row.id);
                try {
                    const p = await ctx.api(L + '/preview?' + qs);
                    if (!ctx.alive()) return;
                    const skipped = p.skipped.map(s => s.reason === 'holiday' ? dshort(s.date) + ' (' + (s.title || 'วันหยุด') + ')' : null).filter(Boolean);
                    const q = p.quota;
                    box.className = 'ld-preview ld-ok';
                    box.innerHTML = `<b>${esc(num(p.days))} ${p.count_mode === 'calendar' ? 'วัน (นับต่อเนื่อง)' : 'วันทำการ'}</b> (ปีงบประมาณ ${esc(p.fiscal_year)})${skipped.length ? `<br>ไม่นับวันหยุด: ${esc(skipped.join(', '))}` : ''}${q.quota === null ? '' : `<br>โควตาคงเหลือหลังลาครั้งนี้ ${esc(num(q.remaining))} วัน`}${p.needs_attachment ? '<br><b>ต้องแนบไฟล์ (เช่น ใบรับรองแพทย์) ก่อนส่งใบลา</b>' : ''}`;
                    if (q.quota !== null && q.remaining < 0) box.className = 'ld-preview ld-warn';
                } catch (e) {
                    if (e?.name === 'AbortError') return;
                    box.className = 'ld-preview ld-error';
                    box.textContent = e.message || '';
                }
            }, 300);
        }

        async function saveForm(f, send) {
            const body = formBody(f), e = st.editing;
            if (!body.reason) { f.querySelector('[name=reason]').focus(); throw new Error('กรุณาระบุเหตุผลการลา'); }
            let row;
            // resubmit creates a linked DRAFT; from then on it follows the same attach -> submit path as a new request
            if (e?.mode === 'resubmit') row = await ctx.api(`${L}/requests/${e.row.id}/resubmit`, {method: 'POST', body: {...body, version: e.row.version}, idempotencyKey: st.key ||= C.uid()});
            else if (e?.mode === 'edit') row = await ctx.api(`${L}/requests/${e.row.id}`, {method: 'PUT', body: {...body, version: e.row.version}});
            else row = await ctx.api(L + '/requests', {method: 'POST', body, idempotencyKey: st.key ||= C.uid()});
            st.key = null;
            st.editing = {mode: 'edit', row}; // from now on retries edit this draft instead of creating another
            const file = f.querySelector('[name=file]')?.files?.[0];
            try {
                if (file) {
                    const fd = new FormData(); fd.append('version', row.version); fd.append('file', file);
                    row = await ctx.api(`${L}/requests/${row.id}/attachment`, {method: 'POST', body: fd});
                    st.editing = {mode: 'edit', row};
                }
                if (send) row = await ctx.api(`${L}/requests/${row.id}/transition`, {method: 'POST', body: {action: 'submit', version: row.version}, idempotencyKey: C.uid()});
            } catch (err) {
                await reloadAll();
                if (ctx.alive()) { drawForm(); }
                throw err;
            }
            st.open = false; st.editing = null; drawForm(); await reloadAll();
            ok(root, send ? 'ส่งใบลาแล้ว รอการพิจารณา' : 'บันทึกร่างแล้ว');
        }

        async function leaveAction(action, id, version, reason) {
            await ctx.api(`${L}/requests/${id}/transition`, {method: 'POST', body: {action, version, reason: reason || null}, idempotencyKey: C.uid()});
            await reloadAll();
            ok(root, 'ดำเนินการแล้ว');
        }

        const h = {
            reload: async () => { await Promise.all([loadTypes(), reloadAll()]); },
            async click(act, b) {
                const id = Number(b.dataset.id), version = Number(b.dataset.version);
                const row = st.list.find(r => r.id === id);
                const slot = () => root.querySelector(`[data-slot="${id}"]`);
                if (act === 'form-open') { st.open = true; st.editing = null; drawForm(); part('form').scrollIntoView?.({block: 'nearest'}); return; }
                if (act === 'form-close') { st.open = false; st.editing = null; drawForm(); return; }
                if (act === 'slot-close') { const s = b.closest('.ld-slot'); if (s) s.innerHTML = ''; return; }
                if (act === 'leave-detail') { const r = await ctx.api(`${L}/requests/${id}`); if (ctx.alive()) slot().innerHTML = detailHtml(r); return; }
                if (act === 'leave-edit') { st.editing = {mode: 'edit', row}; st.open = true; drawForm(); return; }
                if (act === 'leave-resubmit') { st.editing = {mode: 'resubmit', row}; st.open = true; drawForm(); return; }
                if (act === 'leave-submit') { await leaveAction('submit', id, version); return; }
                if (act.startsWith('leave-')) {
                    const a = act.slice(6);
                    slot().innerHTML = reasonForm('leave', id, version, a, NEEDS_REASON.has(a));
                    slot().querySelector('textarea,button')?.focus();
                }
            },
            async submit(kind, f, btn) {
                if (kind === 'leave') { await saveForm(f, btn?.dataset.send === '1'); return; }
                if (kind === 'leave-reason') {
                    const id = Number(f.dataset.id), version = Number(f.dataset.version), a = f.dataset.action, reason = f.querySelector('[name=reason]')?.value.trim() || null;
                    if (a === 'delete') { await ctx.api(`${L}/requests/${id}`, {method: 'DELETE', body: {version}}); await reloadAll(); ok(root, 'ลบร่างแล้ว'); return; }
                    await leaveAction(a, id, version, reason);
                }
            },
            change(el) {
                if (el.dataset?.fy !== undefined) { st.fy = Number(el.value); loadQuota().then(() => ctx.alive() && drawQuota()).catch(e => fail(root, e)); return; }
                const f = el.closest?.('form[data-form=leave]');
                if (!f) return;
                if (el.name === 'half') {
                    f.querySelector('[name=half_day]').hidden = !el.checked;
                    f.querySelector('[data-end]').hidden = el.checked;
                }
                if (el.name === 'starts_on') { const end = f.querySelector('[name=ends_on]'); if (!end.value || end.value < el.value || f.querySelector('[name=half]').checked) end.value = el.value; }
                if (['type', 'starts_on', 'ends_on', 'half', 'half_day'].includes(el.name)) queuePreview();
            },
        };
        wire(root, ctx, h);
        await Promise.all([loadTypes(), loadQuota(), loadList()]);
        if (!ctx.alive()) return;
        drawQuota(); drawList();
    }

    // ------------------------------------------------------------------ page: leave-approve
    async function approvePage(root, ctx) {
        const st = {tab: 'pending', list: [], report: null, types: [], fy: fyNow(), filter: {status: '', teacher_id: ''}, teachers: []};
        root.innerHTML = `<section class="ld-page glass"><h1>อนุมัติใบลา</h1><div class="ld-msg" aria-live="polite"></div>
            <div class="ld-tabs" role="tablist"><button type="button" role="tab" class="ld-btn" data-act="tab" data-tab="pending">รออนุมัติ</button><button type="button" role="tab" class="ld-btn" data-act="tab" data-tab="all">ใบลาทั้งหมด</button><button type="button" role="tab" class="ld-btn" data-act="tab" data-tab="quota">โควตาและประเภทการลา</button></div>
            <div data-part="body"><p class="ld-loading">กำลังโหลด…</p></div></section>`;
        const body = () => root.querySelector('[data-part=body]');
        const markTab = () => root.querySelectorAll('[data-act=tab]').forEach(b => b.setAttribute('aria-selected', b.dataset.tab === st.tab ? 'true' : 'false'));

        async function load() {
            markTab();
            if (st.tab === 'pending') {
                const [a, b] = await Promise.all([ctx.api(`${L}/requests?scope=all&status=submitted&per_page=100`), ctx.api(`${L}/requests?scope=all&status=cancel_requested&per_page=100`)]);
                st.list = [...b.data, ...a.data];
            } else if (st.tab === 'all') {
                if (!st.teachers.length) st.teachers = (await ctx.api(D + '/teachers')).data;
                const qs = new URLSearchParams({scope: 'all', per_page: '100', fiscal_year: String(st.fy)});
                if (st.filter.status) qs.set('status', st.filter.status);
                if (st.filter.teacher_id) qs.set('teacher_id', st.filter.teacher_id);
                st.list = (await ctx.api(`${L}/requests?` + qs)).data;
                st.years ||= (await ctx.api(`${L}/quota/report?fiscal_year=${st.fy}`)).fiscal_years;
            } else {
                st.report = await ctx.api(`${L}/quota/report?fiscal_year=${st.fy}`);
                st.types = (await ctx.api(L + '/types')).data;
            }
            if (ctx.alive()) draw();
        }

        function draw() {
            if (st.tab === 'pending') {
                body().innerHTML = st.list.length ? st.list.map(r => leaveCard(r, 'approve')).join('') : '<p class="ld-empty">ไม่มีใบลาที่รอพิจารณา</p>';
            } else if (st.tab === 'all') {
                body().innerHTML = `<form class="ld-filter ld-box" data-form="filter"><label class="ld-field"><span>ปีงบประมาณ</span><select name="fy">${(st.years || [st.fy]).map(y => `<option${y === st.fy ? ' selected' : ''}>${y}</option>`).join('')}</select></label>
                    <label class="ld-field"><span>สถานะ</span><select name="status"><option value="">ทั้งหมด</option>${opt(Object.entries(STATUS).filter(([k]) => k !== 'draft'), st.filter.status)}</select></label>
                    <label class="ld-field"><span>ครู</span><select name="teacher_id"><option value="">ทุกคน</option>${opt(st.teachers.map(t => [t.id, t.name]), st.filter.teacher_id)}</select></label>
                    <button type="submit" class="ld-btn primary">กรอง</button></form>${st.list.length ? st.list.map(r => leaveCard(r, 'approve')).join('') : '<p class="ld-empty">ไม่พบใบลา</p>'}`;
            } else {
                const rep = st.report;
                body().innerHTML = `<div class="ld-box"><label class="ld-field ld-inlinefield"><span>ปีงบประมาณ (พ.ศ.)</span><select data-fy>${rep.fiscal_years.map(y => `<option${y === st.fy ? ' selected' : ''}>${y}</option>`).join('')}</select></label></div>
                    <div class="ld-tablewrap"><table class="ld-table"><thead><tr><th>ครู</th>${rep.types.map(t => `<th>${esc(t.label)}${t.yearly_quota_days === null ? '' : ' (' + esc(num(t.yearly_quota_days)) + ')'}</th>`).join('')}<th>รวมใช้แล้ว</th></tr></thead><tbody>
                    ${rep.rows.map(r => `<tr><th scope="row">${esc(r.name)}</th>${rep.types.map(t => { const c = r.by_type[t.code]; return `<td data-label="${esc(t.label)}"${c.over ? ' class="ld-over"' : ''}>${esc(num(c.used))}${c.pending ? ' <small>(+' + esc(num(c.pending)) + ')</small>' : ''}</td>`; }).join('')}<td data-label="รวมใช้แล้ว">${esc(num(r.used_total))}</td></tr>`).join('')}</tbody></table></div>
                    <h2>ประเภทการลา</h2><p class="ld-lead">ค่าเริ่มต้นเป็นระเบียบทั่วไปของข้าราชการ โรงเรียนแก้โควตาและเงื่อนไขไฟล์แนบให้ตรงกับระเบียบของตนได้</p>
                    ${st.types.map(t => typeForm(t)).join('')}${typeForm(null)}`;
            }
        }
        const typeForm = t => `<form class="ld-box ld-typeform" data-form="type" data-id="${t ? t.id : ''}" data-version="${t ? t.version : ''}">
            ${t ? `<b>${esc(t.code)}</b>` : '<b>เพิ่มประเภทการลา</b><label class="ld-field"><span>รหัส (ตัวพิมพ์เล็กภาษาอังกฤษ)</span><input name="code" pattern="[a-z][a-z0-9_]{1,29}" required></label>'}
            <label class="ld-field"><span>ชื่อ</span><input name="label" required maxlength="120" value="${esc(t?.label || '')}"></label>
            <label class="ld-field"><span>โควตาต่อปี (วันทำการ ว่าง = ไม่จำกัด)</span><input name="yearly_quota_days" type="number" min="0" max="999" step="0.5" value="${esc(t?.yearly_quota_days ?? '')}"></label>
            <label class="ld-field"><span>ต้องแนบไฟล์เมื่อลาเกิน (วัน ว่าง = ไม่บังคับ)</span><input name="needs_attachment_after_days" type="number" min="0" max="99" step="0.5" value="${esc(t?.needs_attachment_after_days ?? '')}"></label>
            <label class="ld-field"><span>วิธีนับวันลา</span><select name="count_mode">${opt([['working', 'นับเฉพาะวันทำการ (ไม่รวมเสาร์-อาทิตย์และวันหยุด)'], ['calendar', 'นับต่อเนื่องทุกวัน (รวมวันหยุด เช่น ลาคลอด ลาอุปสมบท)']], t?.count_mode || 'working')}</select></label>
            <div class="ld-actions"><button type="submit" class="ld-btn primary">${t ? 'บันทึก' : 'เพิ่ม'}</button>${t ? `<button type="button" class="ld-btn danger" data-act="type-delete" data-id="${t.id}" data-version="${t.version}">ลบ</button>` : ''}</div></form>`;

        async function action(act, id, version, reason) {
            await ctx.api(`${L}/requests/${id}/transition`, {method: 'POST', body: {action: act, version, reason: reason || null}, idempotencyKey: C.uid()});
            await load(); ok(root, 'ดำเนินการแล้ว');
        }
        const h = {
            reload: load,
            async click(act, b) {
                const id = Number(b.dataset.id), version = Number(b.dataset.version);
                const slot = () => root.querySelector(`[data-slot="${id}"]`);
                if (act === 'tab') { st.tab = b.dataset.tab; await load(); return; }
                if (act === 'slot-close') { const s = b.closest('.ld-slot'); if (s) s.innerHTML = ''; return; }
                if (act === 'leave-detail') { const r = await ctx.api(`${L}/requests/${id}`); if (ctx.alive()) slot().innerHTML = detailHtml(r); return; }
                if (act === 'leave-approve') { await action('approve', id, version); return; }
                if (act === 'type-delete') { await ctx.api(`${L}/types/${id}`, {method: 'DELETE', body: {version}}); await load(); ok(root, 'ลบประเภทการลาแล้ว'); return; }
                if (act.startsWith('leave-')) {
                    const a = act.slice(6);
                    slot().innerHTML = reasonForm('leave', id, version, a, NEEDS_REASON.has(a));
                    slot().querySelector('textarea,button')?.focus();
                }
            },
            async submit(kind, f) {
                if (kind === 'leave-reason') { await action(f.dataset.action, Number(f.dataset.id), Number(f.dataset.version), f.querySelector('[name=reason]')?.value.trim()); return; }
                if (kind === 'filter') { const v = Object.fromEntries(new FormData(f)); st.fy = Number(v.fy); st.filter = {status: v.status, teacher_id: v.teacher_id}; await load(); return; }
                if (kind === 'type') {
                    const v = Object.fromEntries(new FormData(f)), id = f.dataset.id;
                    const body = {label: v.label, yearly_quota_days: v.yearly_quota_days === '' ? null : Number(v.yearly_quota_days), needs_attachment_after_days: v.needs_attachment_after_days === '' ? null : Number(v.needs_attachment_after_days), count_mode: v.count_mode || 'working'};
                    if (id) await ctx.api(`${L}/types/${id}`, {method: 'PUT', body: {...body, version: Number(f.dataset.version)}});
                    else await ctx.api(L + '/types', {method: 'POST', body: {...body, code: v.code}, idempotencyKey: C.uid()});
                    await load(); ok(root, 'บันทึกประเภทการลาแล้ว');
                }
            },
            change(el) { if (el.dataset?.fy !== undefined) { st.fy = Number(el.value); load().catch(e => fail(root, e)); } },
        };
        wire(root, ctx, h);
        await load();
    }

    // ------------------------------------------------------------------ page: duty
    async function dutyPage(root, ctx) {
        const today = C.today();
        const st = {tab: 'roster', from: today, to: addDays(today, 13), teacher: '', teachers: [], duties: [], swaps: [], scope: approver() ? 'approval' : 'mine', add: false};
        root.innerHTML = `<section class="ld-page glass"><h1>เวร</h1><div class="ld-msg" aria-live="polite"></div>
            <div class="ld-tabs" role="tablist"><button type="button" role="tab" class="ld-btn" data-act="tab" data-tab="roster">ตารางเวร</button><button type="button" role="tab" class="ld-btn" data-act="tab" data-tab="swaps">แลกเวร</button></div>
            <div data-part="body"><p class="ld-loading">กำลังโหลด…</p></div></section>`;
        const body = () => root.querySelector('[data-part=body]');
        const tname = id => (st.teachers.find(t => t.id === Number(id)) || {}).name || '';

        async function load() {
            root.querySelectorAll('[data-act=tab]').forEach(b => b.setAttribute('aria-selected', b.dataset.tab === st.tab ? 'true' : 'false'));
            if (!st.teachers.length) st.teachers = (await ctx.api(D + '/teachers')).data;
            if (st.tab === 'roster') {
                const qs = new URLSearchParams({from: st.from, to: st.to});
                if (st.teacher) qs.set('teacher_id', st.teacher);
                st.duties = (await ctx.api(D + '/duties?' + qs)).data;
            } else {
                st.swaps = (await ctx.api(`${D}/swaps?scope=${st.scope}`)).data;
            }
            if (ctx.alive()) draw();
        }

        const dutyForm = (d) => `<form class="ld-box ld-inline" data-form="duty" data-id="${d ? d.id : ''}" data-version="${d ? d.version : ''}">
            <label class="ld-field"><span>ครู</span><select name="teacher_id" required>${opt(st.teachers.map(t => [t.id, t.name]), d?.teacher_id)}</select></label>
            <label class="ld-field"><span>วันที่</span><input type="date" name="date" required value="${esc(d?.date || today)}"></label>
            <div class="ld-two"><label class="ld-field"><span>เริ่ม</span><input type="time" name="starts_at" required value="${esc(d?.starts_at || '08:00')}"></label>
            <label class="ld-field"><span>สิ้นสุด</span><input type="time" name="ends_at" required value="${esc(d?.ends_at || '08:30')}"></label></div>
            <label class="ld-field"><span>จุดเวร</span><input name="location" required maxlength="120" value="${esc(d?.location || '')}"></label>
            <label class="ld-field"><span>ประเภท</span><select name="kind">${opt(Object.entries(KINDS), d?.kind || 'other')}</select></label>
            <div class="ld-actions"><button type="submit" class="ld-btn primary">${d ? 'บันทึก' : 'เพิ่มเวร'}</button><button type="button" class="ld-btn" data-act="${d ? 'slot-close' : 'add-close'}">ยกเลิก</button></div></form>`;

        function dutyCard(d) {
            const flags = [];
            if (d.status === 'cancelled') flags.push(badge('ld-s-cancelled', 'ยกเลิกแล้ว'));
            if (d.leave_conflict) flags.push(badge('ld-s-warn', d.leave_conflict.status === 'cancel_requested' ? 'ครูลาอยู่ (รออนุมัติยกเลิกลา)' : 'ครูลาในช่วงนี้ ต้องหาคนแทน'));
            if (d.open_swap) flags.push(badge('ld-s-submitted', d.open_swap.status === 'requested' ? 'ขอแลกเวร (รอเพื่อนตอบรับ)' : 'ขอแลกเวร (รออนุมัติ)'));
            const acts = (d.can || []).map(a => ({swap: `<button type="button" class="ld-btn" data-act="duty-swap" data-id="${d.id}" data-version="${d.version}">ขอแลกเวร</button>`,
                edit: `<button type="button" class="ld-btn" data-act="duty-edit" data-id="${d.id}" data-version="${d.version}">แก้ไข</button>`,
                cancel: `<button type="button" class="ld-btn danger" data-act="duty-cancel" data-id="${d.id}" data-version="${d.version}">ยกเลิกเวร</button>`,
                delete: `<button type="button" class="ld-btn danger" data-act="duty-delete" data-id="${d.id}" data-version="${d.version}">ลบ</button>`}[a] || '')).join('');
            return `<article class="ld-card${d.mine ? ' ld-mine' : ''}${d.status === 'cancelled' ? ' ld-dim' : ''}" data-card="${d.id}">
                <header class="ld-card-head"><b>${esc(d.starts_at)}-${esc(d.ends_at)} ${esc(d.location)}</b><span>${esc(KINDS[d.kind] || d.kind)}</span></header>
                <p class="ld-who">${esc(d.teacher_name || '')}${d.mine ? ' (ฉัน)' : ''}</p>${flags.length ? `<p>${flags.join(' ')}</p>` : ''}
                ${d.status === 'cancelled' ? `<p class="ld-note">เหตุผล: ${esc(d.cancelled_reason || '')}${d.replaced_by_name ? ' · ครูแทน: ' + esc(d.replaced_by_name) : ''}</p>` : ''}
                <div class="ld-actions">${acts}</div><div class="ld-slot" data-slot="${d.id}"></div></article>`;
        }

        function swapCard(s) {
            const acts = (s.can || []).map(a => `<button type="button" class="ld-btn${a === 'approve' || a === 'accept' ? ' primary' : ''}${a === 'reject' || a === 'decline' ? ' danger' : ''}" data-act="swap-${esc(a)}" data-id="${s.id}" data-version="${s.version}">${esc(SWAP_ACT[a])}</button>`).join('');
            return `<article class="ld-card" data-card="swap-${s.id}"><header class="ld-card-head"><b>${esc(s.from_name || '')} → ${esc(s.to_name || '')}</b>${badge('ld-s-' + (s.status === 'accepted' ? 'submitted' : s.status === 'approved' ? 'approved' : s.status === 'requested' ? 'draft' : 'rejected'), s.status_label)}</header>
                ${s.duty ? `<p class="ld-dates">${esc(dow(s.duty.date))} ${esc(dshort(s.duty.date))} ${esc(s.duty.starts_at)}-${esc(s.duty.ends_at)} ${esc(s.duty.location)}</p>` : ''}
                ${s.note ? `<p class="ld-reason">${esc(s.note)}</p>` : ''}${s.decision_note ? `<p class="ld-note">หมายเหตุผู้พิจารณา: ${esc(s.decision_note)}</p>` : ''}
                <div class="ld-actions">${acts}</div><div class="ld-slot" data-slot="swap-${s.id}"></div></article>`;
        }

        function draw() {
            if (st.tab === 'swaps') {
                body().innerHTML = `${approver() ? `<div class="ld-actions"><button type="button" class="ld-btn${st.scope === 'approval' ? ' primary' : ''}" data-act="scope" data-scope="approval">รออนุมัติ</button><button type="button" class="ld-btn${st.scope === 'all' ? ' primary' : ''}" data-act="scope" data-scope="all">ทั้งหมด</button><button type="button" class="ld-btn${st.scope === 'mine' ? ' primary' : ''}" data-act="scope" data-scope="mine">ของฉัน</button></div>` : ''}
                    ${st.swaps.length ? st.swaps.map(swapCard).join('') : '<p class="ld-empty">ไม่มีคำขอแลกเวร</p>'}`;
                return;
            }
            const days = [...new Set(st.duties.map(d => d.date))];
            body().innerHTML = `<form class="ld-filter ld-box" data-form="range"><label class="ld-field"><span>ตั้งแต่</span><input type="date" name="from" value="${esc(st.from)}" required></label>
                <label class="ld-field"><span>ถึง</span><input type="date" name="to" value="${esc(st.to)}" required></label>
                <label class="ld-field"><span>ครู</span><select name="teacher"><option value="">ทุกคน</option>${opt(st.teachers.map(t => [t.id, t.name]), st.teacher)}</select></label>
                <button type="submit" class="ld-btn primary">ดูตาราง</button></form>
                ${approver() ? (st.add ? dutyForm(null) : '<div class="ld-actions"><button type="button" class="ld-btn primary" data-act="add-open">เพิ่มเวร</button></div>') : ''}
                ${days.length ? days.map(day => `<h3 class="ld-day">${esc(dow(day))} ${esc(dshort(day))}${day === today ? ' (วันนี้)' : ''}</h3>${st.duties.filter(d => d.date === day).map(dutyCard).join('')}`).join('') : '<p class="ld-empty">ไม่มีเวรในช่วงนี้</p>'}`;
        }

        const swapForm = (id) => `<form class="ld-inline" data-form="swap-new" data-id="${id}"><label class="ld-field"><span>แลกกับ</span><select name="to_teacher_id" required><option value="">เลือกเพื่อนครู</option>${opt(st.teachers.map(t => [t.id, t.name]), '')}</select></label>
            <label class="ld-field"><span>หมายเหตุ (ไม่บังคับ)</span><textarea name="note" rows="2" maxlength="300"></textarea></label>
            <div class="ld-actions"><button type="submit" class="ld-btn primary">ส่งคำขอ</button><button type="button" class="ld-btn" data-act="slot-close">ยกเลิก</button></div></form>`;
        const cancelForm = (d) => `<form class="ld-inline" data-form="duty-cancel" data-id="${d.id}" data-version="${d.version}"><label class="ld-field"><span>เหตุผลที่ยกเลิกเวร (จำเป็น)</span><textarea name="reason" rows="2" maxlength="300" required minlength="3"></textarea></label>
            <label class="ld-field"><span>ครูแทน (ไม่บังคับ)</span><select name="replaced_by_teacher_id"><option value="">ไม่ระบุ</option>${opt(st.teachers.filter(t => t.id !== d.teacher_id).map(t => [t.id, t.name]), '')}</select></label>
            <div class="ld-actions"><button type="submit" class="ld-btn danger">ยกเลิกเวร</button><button type="button" class="ld-btn" data-act="slot-close">ปิด</button></div></form>`;

        async function swapAction(a, id, version, reason) {
            await ctx.api(`${D}/swaps/${id}/transition`, {method: 'POST', body: {action: a, version, reason: reason || null}, idempotencyKey: C.uid()});
            await load(); ok(root, 'ดำเนินการแล้ว');
        }
        const h = {
            reload: load,
            async click(act, b) {
                const id = Number(b.dataset.id), version = Number(b.dataset.version);
                const slot = key => root.querySelector(`[data-slot="${key}"]`);
                const d = st.duties.find(x => x.id === id);
                if (act === 'tab') { st.tab = b.dataset.tab; await load(); return; }
                if (act === 'scope') { st.scope = b.dataset.scope; await load(); return; }
                if (act === 'slot-close') { const s = b.closest('.ld-slot'); if (s) s.innerHTML = ''; return; }
                if (act === 'add-open') { st.add = true; draw(); return; }
                if (act === 'add-close') { st.add = false; draw(); return; }
                if (act === 'duty-swap') { slot(id).innerHTML = swapForm(id); return; }
                if (act === 'duty-edit') { slot(id).innerHTML = dutyForm(d); return; }
                if (act === 'duty-cancel') { slot(id).innerHTML = cancelForm(d); return; }
                if (act === 'duty-delete') { slot(id).innerHTML = `<form class="ld-inline" data-form="duty-delete" data-id="${id}" data-version="${version}"><p>ยืนยันลบเวรนี้ ?</p><div class="ld-actions"><button type="submit" class="ld-btn danger">ลบ</button><button type="button" class="ld-btn" data-act="slot-close">ยกเลิก</button></div></form>`; return; }
                if (act === 'swap-accept' || act === 'swap-approve' || act === 'swap-decline' || act === 'swap-cancel') { await swapAction(act.slice(5), id, version); return; }
                if (act === 'swap-reject') { slot('swap-' + id).innerHTML = reasonForm('swap', id, version, 'reject', true); }
            },
            async submit(kind, f) {
                const id = Number(f.dataset.id), version = Number(f.dataset.version), v = Object.fromEntries(new FormData(f));
                if (kind === 'range') { st.from = v.from; st.to = v.to; st.teacher = v.teacher; await load(); return; }
                if (kind === 'duty') {
                    const body = {teacher_id: Number(v.teacher_id), date: v.date, starts_at: v.starts_at, ends_at: v.ends_at, location: String(v.location).trim(), kind: v.kind};
                    if (id) await ctx.api(`${D}/duties/${id}`, {method: 'PUT', body: {...body, version}});
                    else await ctx.api(D + '/duties', {method: 'POST', body, idempotencyKey: C.uid()});
                    st.add = false; await load(); ok(root, 'บันทึกเวรแล้ว'); return;
                }
                if (kind === 'duty-cancel') {
                    await ctx.api(`${D}/duties/${id}/cancel`, {method: 'POST', body: {version, reason: String(v.reason).trim(), replaced_by_teacher_id: v.replaced_by_teacher_id ? Number(v.replaced_by_teacher_id) : null}});
                    await load(); ok(root, 'ยกเลิกเวรแล้ว'); return;
                }
                if (kind === 'duty-delete') { await ctx.api(`${D}/duties/${id}`, {method: 'DELETE', body: {version}}); await load(); ok(root, 'ลบเวรแล้ว'); return; }
                if (kind === 'swap-new') {
                    await ctx.api(`${D}/duties/${id}/swaps`, {method: 'POST', body: {to_teacher_id: Number(v.to_teacher_id), note: String(v.note || '').trim() || null}, idempotencyKey: C.uid()});
                    st.tab = 'swaps'; st.scope = 'mine'; await load(); ok(root, 'ส่งคำขอแลกเวรแล้ว รอเพื่อนครูตอบรับ'); return;
                }
                if (kind === 'swap-reason') await swapAction(f.dataset.action, id, version, String(v.reason || '').trim());
            },
        };
        wire(root, ctx, h);
        await load();
    }

    window.SaanWork.register('leave', {title: 'ใบลา', render: leavePage});
    window.SaanWork.register('leave-approve', {title: 'อนุมัติใบลา', render: approvePage});
    window.SaanWork.register('duty', {title: 'เวร', render: dutyPage});
})();
