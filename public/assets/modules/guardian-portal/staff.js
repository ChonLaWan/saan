'use strict';
// guardian-portal: SaanWork page "guardians" (links for guardians of the homeroom, acknowledgement status, printable slip).
// Every interpolated value goes through SaanCore.esc; no inline handlers (delegated listeners on one box per render).
(() => {
    const BASE = '/api/guardian-portal';
    const esc = v => window.SaanCore.esc(v);
    const role = () => { try { return JSON.parse(document.querySelector('#work-boot')?.textContent || '{}').role || ''; } catch { return ''; } };
    const date = iso => window.SaanCore.date(String(iso || '').slice(0, 10), {day: 'numeric', month: 'short', year: 'numeric'});
    const LINK = {pending: 'รอเปิด', used: 'เปิดแล้ว', expired: 'หมดอายุ', revoked: 'ยกเลิกแล้ว'};
    const REL = {father: 'บิดา', mother: 'มารดา', grandparent: 'ปู่ย่าตายาย', relative: 'ญาติ', sibling: 'พี่น้อง', other: 'อื่น ๆ'};

    function linkCell(label, l, purpose) {
        if (!l) return `<span class="gps-muted">${esc(label)}: ยังไม่เคยสร้าง</span>`;
        const revoke = (l.status === 'pending' || l.status === 'used') ? ` <button type="button" class="gps-btn gps-small" data-act="revoke" data-id="${esc(l.id)}" data-version="${esc(l.version)}">${l.status === 'used' ? 'ยกเลิกสิทธิ์ที่เปิดอยู่' : 'ยกเลิก'}</button>` : '';
        return `<span>${esc(label)}: <span class="gps-badge ${l.status}">${esc(LINK[l.status] || l.status)}</span> <span class="gps-muted">${esc(date(l.status === 'used' ? l.used_at : l.expires_at))}</span>${revoke}</span>`;
    }

    window.SaanWork.register('guardians', {
        title: 'ลิงก์ผู้ปกครอง',
        async render(root, ctx) {
            const info = await ctx.api(BASE + '/context');
            if (!ctx.alive()) return;
            const st = {room: Number(ctx.parts[0]) || info.classrooms[0]?.id || 0, data: null, slip: null, msg: null};
            root.innerHTML = '<section class="gps-page glass"><h1>ลิงก์ผู้ปกครอง</h1><div class="gps-msg" aria-live="polite"></div><div class="gps-body"></div></section>';
            const box = root.querySelector('.gps-page');
            const body = box.querySelector('.gps-body');
            const flash = (kind, text) => { st.msg = text ? {kind, text} : null; box.querySelector('.gps-msg').innerHTML = text ? `<div class="gps-note ${kind}" role="${kind === 'ok' ? 'status' : 'alert'}">${esc(text)}</div>` : ''; };

            function draw() {
                const rooms = info.classrooms;
                if (!rooms.length) { body.innerHTML = '<p class="gps-muted">ไม่พบห้องเรียนที่คุณดูแลได้</p>'; return; }
                const settings = info.can_manage_settings
                    ? `<label class="gps-check"><input type="checkbox" data-change="restricted"${info.settings.restricted_can_view ? ' checked' : ''}> <span>อนุญาตให้ผู้ปกครองที่ถูกจำกัดสิทธิ์รับนักเรียนดูข้อมูลได้</span></label>` : '';
                const slip = st.slip ? `<div class="gps-slip" role="region" aria-label="ใบลิงก์ผู้ปกครอง"><h2>${esc(st.slip.title)}</h2><p>${esc(st.slip.who)}</p>`
                    + `<p>เปิดลิงก์นี้บนโทรศัพท์ ใช้ได้ครั้งเดียว หมดอายุ ${esc(date(st.slip.expires_at))}</p><p class="gps-url"><code>${esc(st.slip.url)}</code></p>`
                    + '<p class="gps-noprint"><button type="button" class="gps-btn" data-act="copy">คัดลอกลิงก์</button> <button type="button" class="gps-btn" data-act="print">พิมพ์ใบลิงก์</button> <button type="button" class="gps-btn" data-act="close-slip">ปิด</button></p>'
                    + '<p class="gps-muted gps-noprint">ลิงก์แสดงครั้งเดียวเท่านั้น หากปิดแล้วต้องสร้างใหม่ (ลิงก์เก่าที่ยังไม่ได้เปิดจะถูกยกเลิก)</p></div>' : '';
                const students = (st.data?.students || []).map(s => {
                    const a = s.acks;
                    const KIND = {behavior: 'พฤติกรรม', attendance_month: 'การมาเรียน', term_result: 'ผลการเรียน'};
                    const notes = (s.notes || []).length ? '<ul class="gps-notes">' + s.notes.map(n => `<li><b>${esc(n.guardian || 'ผู้ปกครอง')}</b> (${esc(KIND[n.kind] || n.kind)}${n.ref_month ? ' ' + esc(n.ref_month) : ''}, ${esc(date(n.at))}): ${esc(n.note)}</li>`).join('') + '</ul>' : '';
                    const guardians = s.guardians.length ? s.guardians.map(g => `<div class="gps-guardian"><div class="gps-row"><b>${esc(g.name)}</b> <span class="gps-muted">${esc(REL[g.relationship] || g.relationship)}${g.is_primary ? ' · ผู้ปกครองหลัก' : ''}</span></div>`
                        + (g.may_view ? `<div>${linkCell('ลิงก์ดูข้อมูล', g.view_link)}</div><div>${linkCell('ลิงก์ตั้งบัญชี', g.invite_link)}</div>`
                            + `<div class="gps-muted">บัญชี: ${g.account ? (g.account.active ? 'มีบัญชี' : 'ปิดใช้งาน') + (g.account.last_login_at ? ' · เข้าล่าสุด ' + esc(date(g.account.last_login_at)) : '') : 'ยังไม่มี'}${g.account?.active ? ` <button type="button" class="gps-btn gps-small" data-act="disable-account" data-guardian="${esc(g.guardian_id)}" data-version="${esc(g.account.version)}">ปิดบัญชี</button>` : ''}</div>`
                            + `<div class="gps-actions"><button type="button" class="gps-btn gps-primary" data-act="create" data-purpose="view" data-student="${esc(s.student_id)}" data-guardian="${esc(g.guardian_id)}">สร้างลิงก์ดูข้อมูล</button>`
                            + `<button type="button" class="gps-btn" data-act="create" data-purpose="invite" data-student="${esc(s.student_id)}" data-guardian="${esc(g.guardian_id)}">สร้างลิงก์ตั้งบัญชี</button></div>`
                            : '<div class="gps-muted">ถูกจำกัดสิทธิ์ ไม่เปิดให้ดูข้อมูล</div>') + '</div>').join('')
                        : '<p class="gps-muted">ยังไม่มีผู้ปกครองในทะเบียน</p>';
                    return `<article class="gps-card"><h2>${esc(s.student_name)}</h2><p class="gps-muted">รับทราบพฤติกรรม ${esc(a.behavior_acked)}/${esc(a.behavior_total)} · การมาเรียนเดือนนี้ ${a.attendance_month_acked ? 'รับทราบแล้ว' : 'ยังไม่รับทราบ'} · ผลการเรียน ${esc(a.term_results_acked)} ภาค</p>${notes}${guardians}</article>`;
                }).join('');
                body.innerHTML = `<div class="gps-filters"><label class="gps-field"><span>ห้องเรียน</span><select data-change="room">${rooms.map(r => `<option value="${esc(r.id)}"${r.id === st.room ? ' selected' : ''}>${esc(r.name)}</option>`).join('')}</select></label>`
                    + `<label class="gps-field"><span>อายุลิงก์ (วัน)</span><input type="number" min="1" max="${esc(info.link_days.max)}" value="${esc(st.days ?? info.link_days.default)}" data-change="days" inputmode="numeric"></label></div>${settings}${slip}${students || '<p class="gps-muted">ไม่มีนักเรียนในห้องนี้</p>'}`;
            }

            async function refresh() {
                if (!st.room) { draw(); return; }
                try { st.data = await ctx.api(`${BASE}/classrooms/${st.room}/guardians`); } catch (e) { if (e.name === 'AbortError') return; flash('err', e.message); }
                if (ctx.alive()) draw();
            }

            box.addEventListener('click', async ev => {
                const b = ev.target.closest('[data-act]');
                if (!b || !box.contains(b) || b.disabled) return;
                ev.preventDefault();
                const act = b.dataset.act;
                if (act === 'close-slip') { st.slip = null; draw(); return; }
                if (act === 'print') { window.print(); return; }
                if (act === 'copy') { try { await navigator.clipboard.writeText(st.slip.url); flash('ok', 'คัดลอกลิงก์แล้ว'); } catch { flash('err', 'คัดลอกไม่ได้ กรุณาเลือกข้อความลิงก์แล้วคัดลอกเอง'); } return; }
                b.disabled = true;
                try {
                    flash('ok', '');
                    if (act === 'create') {
                        const days = Math.max(1, Math.min(info.link_days.max, Number(st.days) || info.link_days.default));
                        const r = await ctx.api(BASE + '/links', {method: 'POST', body: {student_id: Number(b.dataset.student), guardian_id: Number(b.dataset.guardian), purpose: b.dataset.purpose, days}});
                        const s = st.data.students.find(x => x.student_id === Number(b.dataset.student));
                        const g = s?.guardians.find(x => x.guardian_id === Number(b.dataset.guardian));
                        st.slip = {url: r.url, expires_at: r.expires_at, title: r.purpose === 'invite' ? 'ลิงก์ตั้งบัญชีผู้ปกครอง' : 'ลิงก์ดูข้อมูลบุตรหลาน', who: `ผู้ปกครองของ ${s?.student_name || ''} (${g?.name || ''})`};
                    } else if (act === 'disable-account') {
                        await ctx.api(`${BASE}/accounts/${b.dataset.guardian}`, {method: 'PUT', body: {version: Number(b.dataset.version), active: false}});
                        flash('ok', 'ปิดบัญชีผู้ปกครองแล้ว');
                    } else if (act === 'revoke') {
                        await ctx.api(`${BASE}/links/${b.dataset.id}`, {method: 'DELETE', body: {version: Number(b.dataset.version)}});
                        flash('ok', 'ยกเลิกลิงก์แล้ว');
                    }
                    await refresh();
                } catch (e) {
                    if (e.name === 'AbortError') return;
                    flash('err', e.status === 409 ? e.message + ' (โหลดรายการใหม่แล้ว)' : e.message);
                    if (e.status === 409) await refresh();
                } finally { b.disabled = false; }
            });
            box.addEventListener('change', async ev => {
                const t = ev.target.closest('[data-change]');
                if (!t || !box.contains(t)) return;
                if (t.dataset.change === 'room') { st.room = Number(t.value); st.slip = null; location.hash = '#guardians/' + st.room; return; }
                if (t.dataset.change === 'days') { st.days = Number(t.value); return; }
                if (t.dataset.change === 'restricted') {
                    try { const r = await ctx.api(BASE + '/settings', {method: 'PUT', body: {restricted_can_view: t.checked}}); info.settings = r.settings; flash('ok', 'บันทึกการตั้งค่าแล้ว'); await refresh(); }
                    catch (e) { t.checked = !t.checked; flash('err', e.message); }
                }
            });
            await refresh();
        },
    });
})();
