'use strict';
// Admin page "โมดูล": list, enable/disable, export data, update-feed switch. Every value is escaped with SaanCore.esc.
(() => {
    const {api, esc} = window.SaanCore || {};
    const list = document.getElementById('mods-list');
    const feedBox = document.querySelector('#mods-feed [data-feed]');
    const dlg = document.getElementById('mods-dialog');
    if (!api || !list || !dlg) return;
    const READY = {experimental: 'ทดลอง', beta: 'เบต้า', stable: 'เสถียร'};
    let state = {modules: [], feed: null};

    function render() {
        list.removeAttribute('aria-busy');
        list.innerHTML = state.modules.map(m => {
            const deps = m.depends_on.length ? 'ต้องใช้: ' + m.depends_on.map(d => esc(d.name) + (d.active ? '' : ' (ปิดอยู่)')).join(', ') : 'ไม่ต้องใช้โมดูลอื่น';
            const by = m.required_by.length ? ' | ถูกใช้โดย: ' + m.required_by.map(esc).join(', ') : '';
            let why = '';
            if (m.active && m.core_required) why = 'เป็นส่วนที่ระบบต้องใช้ ปิดไม่ได้';
            else if (m.active && m.required_by.length) why = 'ปิดไม่ได้ เพราะ ' + m.required_by.map(esc).join(', ') + ' ต้องใช้โมดูลนี้';
            else if (!m.active) why = esc(m.reason);
            return `<article class="mods-card" data-id="${esc(m.id)}"><div><h3>${esc(m.name)} <small>${esc(m.version)}</small></h3>
<div class="mods-meta"><span class="mods-badge ${m.active ? 'on' : ''}">${m.active ? 'เปิดใช้งาน' : 'ปิดอยู่'}</span><span class="mods-badge ${m.readiness === 'experimental' ? 'warn' : ''}">${esc(READY[m.readiness] || m.readiness)}</span>${m.families.map(f => `<span class="mods-badge">${esc(f)}</span>`).join('')}</div>
<p>${esc(m.description)}</p><p class="mods-note">${deps}${by}</p>${why ? `<p class="mods-note">${why}</p>` : ''}</div>
<div class="mods-actions">${m.active ? `<button type="button" data-act="disable" ${m.can_disable ? '' : 'disabled'}>ปิดโมดูล</button>` : `<button type="button" data-act="enable" ${m.can_enable ? '' : 'disabled'}>เปิดโมดูล</button>`}${m.has_data ? '<button type="button" data-act="export">ส่งออกข้อมูล</button>' : ''}</div></article>`;
        }).join('') || '<p>ไม่พบโมดูล</p>';
    }

    function renderFeed() {
        const f = state.feed || {}; const a = f.available; const base = (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
        feedBox.innerHTML = `<p>สถานะการตรวจรุ่นใหม่: <b>${f.enabled ? 'เปิด' : 'ปิด'}</b>${f.enabled && a && a.any ? ' · มีรุ่นใหม่' : ''}</p><p><a href="${esc(base + '/admin/updates')}">เปิดหน้า อัปเดตระบบ</a> เพื่อตรวจ ดาวน์โหลด และติดตั้ง</p>`;
    }

    // one reusable dialog: asks for the admin password (and the typed module id for experimental modules)
    function ask({title, text, confirmId, okLabel}) {
        return new Promise(resolve => {
            const q = s => dlg.querySelector(s);
            q('[data-title]').textContent = title; q('[data-text]').textContent = text;
            q('[data-confirm-wrap]').hidden = !confirmId; q('[data-confirm-id]').textContent = confirmId || ''; q('[data-confirm]').value = ''; q('[data-confirm]').required = !!confirmId;
            q('[data-password]').value = ''; q('[data-error]').hidden = true; q('[data-ok]').textContent = okLabel || 'ยืนยัน';
            const done = v => { dlg.close(); resolve(v); };
            const form = dlg.querySelector('form');
            form.onsubmit = ev => { ev.preventDefault(); done({password: q('[data-password]').value, confirm: q('[data-confirm]').value}); };
            q('[data-cancel]').onclick = () => done(null);
            dlg.onclose = () => resolve(null);
            dlg.showModal(); q(confirmId ? '[data-confirm]' : '[data-password]').focus();
        });
    }
    const showError = (msg) => { const e = dlg.open ? dlg.querySelector('[data-error]') : document.querySelector('[data-feed-error]'); if (e) { e.textContent = msg; e.hidden = false; } else alert(msg); };

    async function load() {
        try { state = await api('/api/site/modules'); render(); renderFeed(); }
        catch (e) { list.innerHTML = `<p class="mods-error">${esc(e.message)}</p>`; }
    }

    async function exportZip(id, password) {
        const base = (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
        const res = await fetch(base + '/api/site/modules/' + encodeURIComponent(id) + '/export', {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''}, body: JSON.stringify({current_password: password})});
        if (!res.ok) { let d = null; try { d = await res.json(); } catch { /* not json */ } throw new Error((d?.errors && Object.values(d.errors).flat()[0]) || d?.message || 'ส่งออกไม่สำเร็จ (' + res.status + ')'); }
        const blob = await res.blob(); const a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = 'saan-module-' + id + '.zip'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    }

    list.addEventListener('click', async ev => {
        const btn = ev.target.closest('button[data-act]'); if (!btn || btn.disabled) return;
        const m = state.modules.find(x => x.id === btn.closest('[data-id]').dataset.id); if (!m) return;
        const act = btn.dataset.act;
        const exp = act === 'enable' && m.readiness === 'experimental';
        const copy = {
            enable: {title: 'เปิดโมดูล ' + m.name, text: exp ? 'โมดูลนี้ยังเป็นรุ่นทดลอง อาจเปลี่ยนแปลงหรือมีข้อบกพร่อง ไม่แนะนำกับข้อมูลจริง' : 'ยืนยันด้วยรหัสผ่านเพื่อเปิดโมดูลนี้', okLabel: 'เปิดโมดูล'},
            disable: {title: 'ปิดโมดูล ' + m.name, text: 'ข้อมูลของโมดูลไม่ถูกลบ เปิดกลับมาใช้ใหม่ได้ ผู้ใช้จะเข้าหน้าของโมดูลนี้ไม่ได้ระหว่างที่ปิด', okLabel: 'ปิดโมดูล'},
            export: {title: 'ส่งออกข้อมูล ' + m.name, text: 'ได้ไฟล์ ZIP ของตารางโมดูลแบบ JSONL คอลัมน์รหัสผ่านและโทเคนถูกตัดออก เก็บไฟล์ไว้ในที่ปลอดภัยเพราะอาจมีข้อมูลส่วนบุคคล', okLabel: 'ดาวน์โหลด'},
        }[act];
        let r = await ask({...copy, confirmId: exp ? m.id : ''});
        while (r) {
            try {
                if (act === 'export') { await exportZip(m.id, r.password); break; }
                const out = await api('/api/site/modules/' + encodeURIComponent(m.id) + '/state', {method: 'POST', body: {enabled: act === 'enable', confirm: r.confirm, current_password: r.password}});
                state.modules = out.modules; render(); break;
            } catch (e) {
                r = await retry(copy, exp ? m.id : '', e.message);
            }
        }
    });
    // re-open the dialog with the server message so a wrong password can be corrected
    async function retry(copy, confirmId, message) {
        const r = ask({...copy, confirmId}); dlg.querySelector('[data-error]').textContent = message; dlg.querySelector('[data-error]').hidden = false; return r;
    }

    feedBox.addEventListener('click', async ev => {
        const b = ev.target.closest('button[data-feed-act]'); if (!b) return;
        try {
            if (b.dataset.feedAct === 'check') { b.disabled = true; state.feed = await api('/api/site/updates/feed/check', {method: 'POST'}); }
            else {
                const enable = !(state.feed && state.feed.enabled);
                const r = await ask({title: enable ? 'เปิดการตรวจรุ่นใหม่' : 'ปิดการตรวจรุ่นใหม่', text: enable ? 'ระบบจะเชื่อมต่อเซิร์ฟเวอร์โครงการวันละครั้งเพื่ออ่านรายการรุ่นที่ลงลายเซ็น ไม่ส่งข้อมูลโรงเรียน' : 'หยุดการเชื่อมต่อภายนอก', okLabel: 'ยืนยัน'});
                if (!r) return;
                state.feed = await api('/api/site/updates/feed', {method: 'PUT', body: {enabled: enable, current_password: r.password}});
            }
            renderFeed();
        } catch (e) { b.disabled = false; const x = feedBox.querySelector('[data-feed-error]'); x.textContent = e.message; x.hidden = false; }
    });

    load();
})();
