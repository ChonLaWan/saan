'use strict';
// Core work page #module-settings (admin): edits settings declared in module.json "settings_schema" via /api/module-settings.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const ROLES = {admin: 'ผู้ดูแลระบบ', editor: 'ผู้ดูแลเว็บไซต์', principal: 'ผู้อำนวยการ', teacher: 'ครู'};
    const CSS = `.ms-wrap{display:grid;gap:1rem}.ms-card{padding:1rem;border-radius:var(--radius,16px)}.ms-card h2{margin:0 0 .75rem;font-size:1.1rem}
.ms-item{display:grid;gap:.5rem;padding:.8rem 0;border-top:1px solid var(--line,#c4ccbe)}.ms-item:first-of-type{border-top:0}
.ms-item label.ms-l,.ms-item .ms-l{font-weight:600}.ms-help{margin:0;font-size:.85rem;opacity:.8}.ms-key{font-size:.75rem;opacity:.65;font-family:ui-monospace,monospace}
.ms-item input[type=text],.ms-item input[type=number],.ms-item select,.ms-item textarea{width:100%;max-width:32rem;min-height:44px;padding:.5rem .7rem;border:1px solid var(--line,#c4ccbe);border-radius:10px;background:var(--sheet,#fffdf5);color:inherit;font:inherit;box-sizing:border-box}
.ms-item textarea{min-height:9rem;font-family:ui-monospace,monospace;font-size:.85rem}
.ms-users{max-height:14rem;overflow:auto;border:1px solid var(--line,#c4ccbe);border-radius:10px;padding:.4rem;display:grid;gap:.1rem;background:var(--sheet,#fffdf5)}
.ms-users label{display:flex;gap:.5rem;align-items:center;min-height:40px;padding:0 .4rem}.ms-users small{opacity:.7}
.ms-row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}.ms-btn{min-height:44px;padding:0 1rem;border-radius:999px;border:1px solid var(--ink,#163b30);background:var(--ink,#163b30);color:var(--sheet,#fffdf5);font:inherit;cursor:pointer}
.ms-btn.alt{background:transparent;color:inherit}.ms-btn:focus-visible,.ms-item input:focus-visible,.ms-item select:focus-visible,.ms-item textarea:focus-visible{outline:2px solid var(--accent,#b83317);outline-offset:2px}
.ms-state{font-size:.85rem}.ms-state.bad{color:var(--danger,#a72d1b)}.ms-badge{font-size:.75rem;padding:.1rem .5rem;border-radius:999px;border:1px solid var(--line,#c4ccbe)}`;

    function toast(text, bad) {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    }

    function control(it, users, id) {
        const v = it.value;
        if (it.type === 'bool') return `<label class="ms-row"><input type="checkbox" id="${id}" data-ctl${v ? ' checked' : ''}> <span>เปิดใช้งาน</span></label>`;
        if (it.type === 'int') return `<input type="number" inputmode="numeric" id="${id}" data-ctl min="${esc(it.min)}" max="${esc(it.max)}" step="1" value="${esc(v)}">`;
        if (it.type === 'text' && it.options) return `<select id="${id}" data-ctl>${Object.entries(it.options).map(([k, l]) => `<option value="${esc(k)}"${k === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
        if (it.type === 'text') return `<input type="text" id="${id}" data-ctl maxlength="${esc(it.maxlength)}" value="${esc(v)}">`;
        if (it.type === 'users') {
            const hint = new Set(it.roles_hint || []), sel = new Set(v || []);
            const allowed = it.roles && it.roles.length ? new Set(it.roles) : null;
            const sorted = users.filter(u => !allowed || allowed.has(u.role)).sort((a, b) => (hint.has(b.role) - hint.has(a.role)) || a.name.localeCompare(b.name, 'th'));
            return `<div class="ms-users" role="group" aria-labelledby="${id}-l">${sorted.length ? sorted.map(u => `<label><input type="checkbox" data-user="${esc(u.id)}"${sel.has(u.id) ? ' checked' : ''}> <span>${esc(u.name)} <small>${esc(ROLES[u.role] || u.role)}</small></span></label>`).join('') : '<small>ไม่มีบัญชีที่ใช้งานอยู่</small>'}</div>`;
        }
        return `<textarea id="${id}" data-ctl spellcheck="false">${esc(JSON.stringify(v ?? null, null, 2))}</textarea>`;
    }

    function read(it, box) {
        if (it.type === 'bool') return box.querySelector('[data-ctl]').checked;
        if (it.type === 'users') return [...box.querySelectorAll('[data-user]:checked')].map(c => Number(c.dataset.user));
        const raw = box.querySelector('[data-ctl]').value;
        if (it.type === 'int') return raw.trim() === '' ? null : Number(raw);
        if (it.type === 'json') return JSON.parse(raw); // throws SyntaxError: shown to the user
        return raw;
    }

    function itemHtml(it, users, n) {
        const id = 'ms-' + n;
        return `<div class="ms-item" data-key="${esc(it.key)}"><div><span class="ms-l" id="${id}-l">${esc(it.label)}</span> ${it.is_default ? '<span class="ms-badge">ค่าเริ่มต้น</span>' : ''}</div>
            ${it.help ? `<p class="ms-help">${esc(it.help)}</p>` : ''}${it.type === 'users' && it.roles_hint?.length ? `<p class="ms-help">มักมอบให้บทบาท: ${esc(it.roles_hint.map(r => ROLES[r] || r).join(', '))}</p>` : ''}
            ${control(it, users, id).replace('<input ', `<input aria-labelledby="${id}-l" `).replace('<select ', `<select aria-labelledby="${id}-l" `).replace('<textarea ', `<textarea aria-labelledby="${id}-l" `)}
            <div class="ms-row"><button type="button" class="ms-btn" data-save>บันทึก</button>${it.default !== null && it.default !== undefined && it.type !== 'users' ? '<button type="button" class="ms-btn alt" data-default>ใส่ค่าเริ่มต้น</button>' : ''}<span class="ms-state" role="status"></span></div>
            <span class="ms-key">${esc(it.key)}</span></div>`;
    }

    window.SaanWork.register('module-settings', {
        title: 'ตั้งค่าโมดูล',
        async render(root, ctx) {
            if (!document.querySelector('#ms-style')) { const s = document.createElement('style'); s.id = 'ms-style'; s.textContent = CSS; document.head.append(s); }
            const data = await ctx.api('/api/module-settings');
            if (!ctx.alive()) return;
            let n = 0;
            root.innerHTML = `<section class="ms-wrap"><div class="glass ms-card"><h1 style="margin:0 0 .4rem;font-size:1.4rem">ตั้งค่าโมดูล</h1><p class="ms-help">การตั้งค่าที่เดิมแก้ได้เฉพาะในโค้ด การเปลี่ยนแต่ละรายการบันทึกแยกกันและมีประวัติการแก้ไข</p></div>
                ${data.groups.map(g => `<div class="glass ms-card"><h2>${esc(g.name)}</h2>${g.items.map(it => itemHtml(it, data.users, ++n)).join('')}</div>`).join('') || '<div class="glass ms-card">ยังไม่มีโมดูลที่ประกาศการตั้งค่า</div>'}</section>`;
            const items = new Map(data.groups.flatMap(g => g.items).map(it => [it.key, it]));
            root.firstElementChild.addEventListener('click', async e => {
                const btn = e.target.closest('button[data-save],button[data-default]');
                if (!btn) return;
                const box = btn.closest('.ms-item'), it = items.get(box.dataset.key), state = box.querySelector('.ms-state');
                const say = (t, bad) => { state.textContent = t; state.classList.toggle('bad', !!bad); };
                if (btn.hasAttribute('data-default')) {
                    const d = it.default, c = box.querySelector('[data-ctl]');
                    if (it.type === 'bool') c.checked = d === true; else if (it.type === 'json') c.value = JSON.stringify(d, null, 2); else c.value = String(d);
                    say('ใส่ค่าเริ่มต้นแล้ว กดบันทึกเพื่อใช้งาน'); return;
                }
                let value;
                try { value = read(it, box); } catch { say('รูปแบบ JSON ไม่ถูกต้อง', true); return; }
                btn.disabled = true; say('กำลังบันทึก...');
                try {
                    const fresh = await ctx.api('/api/module-settings', {method: 'PUT', body: {key: it.key, version: it.version, value}, idempotencyKey: window.SaanCore.uid()});
                    if (!ctx.alive()) return;
                    items.set(it.key, fresh);
                    const holder = document.createElement('div'); holder.innerHTML = itemHtml(fresh, data.users, ++n);
                    box.replaceWith(holder.firstElementChild);
                    holder.remove();
                    toast('บันทึกการตั้งค่าแล้ว');
                } catch (err) {
                    if (err?.name === 'AbortError' || !ctx.alive()) return;
                    if (err.status === 409 && err.current?.key === it.key) {
                        items.set(it.key, err.current);
                        const holder = document.createElement('div'); holder.innerHTML = itemHtml(err.current, data.users, ++n);
                        const next = holder.firstElementChild; box.replaceWith(next);
                        const st = next.querySelector('.ms-state'); st.textContent = 'ค่านี้ถูกแก้ไขโดยผู้อื่น แสดงค่าล่าสุดแล้ว ตรวจสอบก่อนบันทึกอีกครั้ง'; st.classList.add('bad');
                        return;
                    }
                    say(err.message || 'บันทึกไม่สำเร็จ', true);
                } finally { btn.disabled = false; }
            });
        },
    });
})();
