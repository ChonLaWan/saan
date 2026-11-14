'use strict';
// complaints module (F1): SaanWork page "complaints" = case list, case page, annual report. Every interpolated value goes through SaanCore.esc; no inline handlers.
// Routes: #complaints (list), #complaints/<id> (case), #complaints/report (annual report).
(() => {
    const C = window.SaanCore;
    const esc = v => C.esc(v);
    const A = '/api/complaints';
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const day = v => (v ? C.date(String(v).slice(0, 10)) : '-');
    const stamp = v => (v ? C.date(v, {day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'}) : '-');
    const opt = (pairs, sel, blank) => (blank ? `<option value="">${esc(blank)}</option>` : '') + pairs.map(([v, l]) => `<option value="${esc(v)}"${String(v) === String(sel ?? '') ? ' selected' : ''}>${esc(l)}</option>`).join('');
    const bytes = n => (n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');

    window.SaanWork.register('complaints', {
        title: 'ร้องเรียนและรับเรื่อง',
        async render(root, ctx) {
            const st = {meta: null, filters: {category: '', status: '', overdue: '', q: '', page: 1}};
            root.innerHTML = `<section class="cmp-work glass"><h1>ร้องเรียนและรับเรื่อง</h1>
                <nav class="cmp-tabs" aria-label="เมนูย่อย"><a href="#complaints" data-tab="list">รายการเรื่อง</a><a href="#complaints/report" data-tab="report">รายงานประจำปีงบประมาณ</a></nav>
                <div class="cmp-msg" role="status"></div><div class="cmp-body">กำลังโหลด...</div></section>`;
            const body = root.querySelector('.cmp-body');
            const msgBox = root.querySelector('.cmp-msg');
            const msg = (kind, text) => { msgBox.innerHTML = text ? `<div class="cmp-note cmp-${esc(kind)}" role="${kind === 'error' ? 'alert' : 'status'}">${esc(text)}</div>` : ''; };
            const part = ctx.parts[0] || '';
            root.querySelectorAll('.cmp-tabs a').forEach(a => { if ((a.dataset.tab === 'report') === (part === 'report')) a.setAttribute('aria-current', 'page'); });

            try { st.meta = await ctx.api(A + '/meta'); }
            catch (e) {
                if (!ctx.alive()) return;
                body.innerHTML = `<p class="cmp-note cmp-error" role="alert">${esc(e.status === 403 ? 'บัญชีนี้ไม่ได้รับสิทธิ์ดูแลเรื่องร้องเรียน ผู้ดูแลระบบกำหนดสิทธิ์ได้ที่ตั้งค่าโมดูล' : e.message)}</p>`;
                return;
            }
            if (!ctx.alive()) return;
            const warnings = (st.meta.warnings || []).map(w => `<p class="cmp-note cmp-warn">${esc(w)}</p>`).join('');

            // ------------------------------------------------------------ list
            const qs = () => {
                const p = new URLSearchParams();
                Object.entries(st.filters).forEach(([k, v]) => { if (v !== '' && v !== null && !(k === 'page' && Number(v) === 1)) p.set(k, v); });
                return p.toString();
            };
            async function list() {
                const res = await ctx.api(A + '/cases' + (qs() ? '?' + qs() : ''));
                if (!ctx.alive()) return;
                const m = st.meta;
                const rows = res.data.map(c => `<tr><td data-label="เลขรับเรื่อง"><a href="#complaints/${esc(c.id)}">${esc(c.receipt_no)}</a></td>
                    <td data-label="ประเภท">${esc(c.category_label)}</td><td data-label="หัวข้อ">${esc(c.subject)}${c.anonymous ? ' <span class="cmp-tag">ไม่ระบุชื่อ</span>' : ''}</td>
                    <td data-label="สถานะ"><span class="cmp-status cmp-s-${esc(c.status)}">${esc(c.status_label)}</span></td>
                    <td data-label="กำหนดตอบ">${esc(day(c.due_on))}${c.overdue ? ' <span class="cmp-tag cmp-late">เกินกำหนด</span>' : ''}</td>
                    <td data-label="ผู้รับผิดชอบ">${esc(c.assignee ? c.assignee.name : '-')}</td></tr>`).join('');
                const pages = Math.max(1, Math.ceil(res.total / res.per_page));
                body.innerHTML = `${warnings}
                    <form class="cmp-filter" data-role="filter" novalidate>
                        <label>ค้นหา<input type="search" name="q" maxlength="100" value="${esc(st.filters.q)}" placeholder="เลขรับเรื่องหรือหัวข้อ"></label>
                        <label>ประเภท<select name="category">${opt(Object.entries(m.categories), st.filters.category, 'ทุกประเภท')}</select></label>
                        <label>สถานะ<select name="status">${opt(Object.entries(m.statuses), st.filters.status, 'ทุกสถานะ')}</select></label>
                        <label class="cmp-check"><input type="checkbox" name="overdue" value="1"${st.filters.overdue ? ' checked' : ''}> เฉพาะเกินกำหนด</label>
                        <button class="cmp-btn cmp-primary" type="submit">กรอง</button>
                        <a class="cmp-btn" href="${esc(base() + A + '/cases/export' + (qs() ? '?' + qs() : ''))}">ส่งออก CSV (ข้อมูลรหัสเท่านั้น)</a>
                    </form>
                    <p class="cmp-count">${esc(res.total)} เรื่อง</p>
                    ${res.data.length ? `<div class="cmp-scroll"><table class="cmp-table cmp-cards"><thead><tr><th scope="col">เลขรับเรื่อง</th><th scope="col">ประเภท</th><th scope="col">หัวข้อ</th><th scope="col">สถานะ</th><th scope="col">กำหนดตอบ</th><th scope="col">ผู้รับผิดชอบ</th></tr></thead><tbody>${rows}</tbody></table></div>` : '<p>ไม่พบเรื่องตามเงื่อนไข</p>'}
                    <div class="cmp-pager"><button class="cmp-btn" data-act="page" data-p="${Number(st.filters.page) - 1}"${st.filters.page <= 1 ? ' disabled' : ''}>ก่อนหน้า</button>
                    <span>หน้า ${esc(st.filters.page)} / ${esc(pages)}</span><button class="cmp-btn" data-act="page" data-p="${Number(st.filters.page) + 1}"${st.filters.page >= pages ? ' disabled' : ''}>ถัดไป</button></div>`;
            }

            // ------------------------------------------------------------ case
            let cur = null;
            const block = (title, inner) => `<section class="cmp-box"><h2>${esc(title)}</h2>${inner}</section>`;
            function caseView(d) {
                cur = d;
                const c = d.case;
                const contact = c.anonymous ? '<p>ผู้ร้องไม่ระบุชื่อ ไม่มีช่องทางติดต่อ</p>' : (c.contact ? `<dl class="cmp-dl"><dt>ชื่อ</dt><dd>${esc(c.contact.name || '-')}</dd><dt>อีเมล</dt><dd>${esc(c.contact.email || '-')}</dd><dt>โทรศัพท์</dt><dd>${esc(c.contact.phone || '-')}</dd></dl>` : '<p>ไม่มีข้อมูลติดต่อ</p>');
                const closed = c.status === 'closed';
                body.innerHTML = `${warnings}<p><a href="#complaints">&larr; กลับรายการ</a></p>
                <div class="cmp-head"><h2>${esc(c.receipt_no)} <span class="cmp-status cmp-s-${esc(c.status)}">${esc(c.status_label)}</span>${c.overdue ? ' <span class="cmp-tag cmp-late">เกินกำหนด</span>' : ''}</h2>
                    <p>${esc(c.category_label)} · รับเรื่อง ${esc(stamp(c.received_at))} · กำหนดตอบ ${esc(day(c.due_on))} (เกณฑ์ ${esc(c.sla_days)} วัน)</p></div>
                ${block('เรื่องที่แจ้ง', `<h3>${esc(c.subject)}</h3><p class="cmp-text">${esc(c.detail)}</p>`)}
                ${block('ผู้ร้อง', contact)}
                ${block('ไฟล์แนบ', d.files.length ? `<ul class="cmp-files">${d.files.map(f => `<li><a href="${esc(base() + A + '/cases/' + c.id + '/files/' + f.id)}">${esc(f.name)}</a> <small>${esc(f.mime)} · ${esc(bytes(f.size))}</small></li>`).join('')}</ul>` : '<p>ไม่มีไฟล์แนบ</p>')}
                ${block('การมอบหมายและกำหนดเวลา', `<form class="cmp-inline" data-role="assign" novalidate><label>ผู้รับผิดชอบ<select name="assignee_id">${opt(d.assignable.map(u => [u.id, u.name]), c.assignee?.id, 'ยังไม่มอบหมาย')}</select></label><button class="cmp-btn cmp-primary" type="submit">บันทึกผู้รับผิดชอบ</button></form>
                    <form class="cmp-inline" data-role="due" novalidate><label>กำหนดตอบ<input type="date" name="due_on" value="${esc(c.due_on)}" required></label><button class="cmp-btn" type="submit">เปลี่ยนกำหนด</button></form>`)}
                ${block('ผู้ถูกร้อง (ซ่อนเรื่องนี้จากบัญชีเหล่านี้)', `<form data-role="accused" novalidate><p class="cmp-help">บัญชีที่เลือกจะมองไม่เห็น ค้นหาไม่พบ ส่งออกไม่ได้ และมอบหมายเรื่องนี้ให้ไม่ได้ แม้มีสิทธิ์เจ้าหน้าที่หรือผู้บริหาร</p>
                    <div class="cmp-checks">${d.staff.map(u => `<label class="cmp-check"><input type="checkbox" name="user_ids" value="${esc(u.id)}"${d.accused.some(a => a.id === u.id) ? ' checked' : ''}> ${esc(u.name)}</label>`).join('')}</div>
                    <button class="cmp-btn" type="submit">บันทึกรายชื่อผู้ถูกร้อง</button></form>`)}
                ${block('คำตอบถึงผู้ร้อง (แสดงในหน้าตรวจสอบสถานะสาธารณะ)', `<form data-role="reply" novalidate><label class="cmp-field">ข้อความ (ห้ามใส่ชื่อบุคคลหรือข้อมูลส่วนตัว)<textarea name="text" rows="5" maxlength="4000"${closed ? ' disabled' : ''}>${esc(c.public_reply || '')}</textarea></label>
                    <button class="cmp-btn cmp-primary" type="submit"${closed ? ' disabled' : ''}>บันทึกและเผยแพร่คำตอบ</button></form>`)}
                ${block('รหัสติดตาม', `<p class="cmp-help">ระบบไม่เก็บรหัสติดตามเดิม หากผู้ร้องที่ระบุช่องทางติดต่อตรวจสถานะไม่ได้ (เช่น หลังเปลี่ยนกุญแจระบบ) ออกรหัสใหม่ได้ รหัสเดิมจะใช้ไม่ได้ทันที และรหัสใหม่แสดงครั้งเดียว</p><div class="cmp-inline"><button class="cmp-btn" data-act="reissue">ออกรหัสติดตามใหม่</button></div>`)}
                ${block('สถานะ',`<div class="cmp-inline">${closed ? '<button class="cmp-btn" data-act="reopen">เปิดเรื่องใหม่</button>' : '<button class="cmp-btn cmp-primary" data-act="close">ปิดเรื่อง</button>'}</div>`)}
                ${block('บันทึกภายใน (ไม่แสดงต่อผู้ร้อง)', `<ul class="cmp-notes">${d.notes.map(n => `<li><strong>${esc(n.user)}</strong> <small>${esc(stamp(n.created_at))}</small><p class="cmp-text">${esc(n.body)}</p></li>`).join('') || '<li>ยังไม่มีบันทึก</li>'}</ul>
                    <form data-role="note" novalidate><label class="cmp-field">เพิ่มบันทึก<textarea name="body" rows="3" maxlength="4000" required></textarea></label><button class="cmp-btn" type="submit">เพิ่มบันทึก</button></form>`)}
                ${block('ประวัติการดำเนินการ', `<ol class="cmp-log">${d.actions.map(a => `<li><strong>${esc(a.label)}</strong> โดย ${esc(a.actor)} <small>${esc(stamp(a.created_at))}</small></li>`).join('')}</ol>`)}`;
            }
            async function loadCase(id) {
                const d = await ctx.api(A + '/cases/' + encodeURIComponent(id));
                if (!ctx.alive()) return;
                caseView(d);
            }
            async function act(path, payload, ok) {
                msg('', '');
                try {
                    const d = await ctx.api(A + '/cases/' + cur.case.id + '/' + path, {method: 'POST', body: payload, idempotencyKey: path === 'notes' ? C.uid() : undefined});
                    if (!ctx.alive()) return;
                    caseView(d);
                    msg('ok', d.new_tracking_code ? ok + ' ' + d.new_tracking_code + ' (แสดงครั้งเดียว กรุณาจดและแจ้งผู้ร้อง)' : ok);
                } catch (e) {
                    if (!ctx.alive()) return;
                    if (e.status === 409) { msg('error', 'มีการแก้ไขเรื่องนี้จากอีกหน้าจอ โหลดข้อมูลล่าสุดให้แล้ว กรุณาทำรายการอีกครั้ง'); await loadCase(cur.case.id).catch(() => {}); } else msg('error', e.message);
                }
            }

            // ------------------------------------------------------------ report
            async function report() {
                const res = await ctx.api(A + '/reports');
                if (!ctx.alive()) return;
                const rows = res.data.map(r => `<tr><td data-label="ปีงบประมาณ">${esc(r.label)}</td><td data-label="จำนวนเรื่อง">${esc(r.total)}</td>
                    <td data-label="เผยแพร่">${r.status === 'published' ? 'เผยแพร่แล้ว ' + esc(stamp(r.published_at)) : 'ยังไม่เผยแพร่'}</td>
                    <td data-label="การทำงาน"><button class="cmp-btn" data-act="show-report" data-y="${esc(r.fiscal_year)}">ดูรายงาน</button>
                    <a class="cmp-btn" href="${esc(base() + A + '/reports/' + r.fiscal_year + '/csv')}">CSV</a>
                    <button class="cmp-btn cmp-primary" data-act="publish" data-y="${esc(r.fiscal_year)}">${r.status === 'published' ? 'อัปเดตรายงานที่เผยแพร่' : 'เผยแพร่รายงาน'}</button>
                    ${r.status === 'published' ? `<button class="cmp-btn" data-act="unpublish" data-y="${esc(r.fiscal_year)}">เลิกเผยแพร่</button>` : ''}</td></tr>`).join('');
                body.innerHTML = `<p>รายงานนับเรื่องตามปีงบประมาณ (1 ตุลาคม ถึง 30 กันยายน) แยกประเภทและสถานะ พร้อมเวลาดำเนินการเฉลี่ย ไม่มีข้อมูลส่วนบุคคล เมื่อเผยแพร่แล้ว ITA ข้อ O14 จะลิงก์ไปยังรายงานล่าสุดโดยอัตโนมัติ</p>
                    <div class="cmp-scroll"><table class="cmp-table cmp-cards"><thead><tr><th scope="col">ปีงบประมาณ</th><th scope="col">จำนวนเรื่อง</th><th scope="col">สถานะการเผยแพร่</th><th scope="col">การทำงาน</th></tr></thead><tbody>${rows}</tbody></table></div><div class="cmp-report" aria-live="polite"></div>`;
            }
            async function showReport(y) {
                const r = await ctx.api(A + '/reports/' + encodeURIComponent(y));
                if (!ctx.alive()) return;
                const st2 = Object.entries(st.meta.statuses);
                const line = row => `<tr><th scope="row">${esc(row.label)}</th><td>${esc(row.total)}</td>${st2.map(([k]) => `<td>${esc(row.by_status[k])}</td>`).join('')}<td>${row.avg_days === null ? '-' : esc(row.avg_days)}</td></tr>`;
                body.querySelector('.cmp-report').innerHTML = `<h2>${esc(r.label)}</h2><div class="cmp-scroll"><table class="cmp-table"><thead><tr><th scope="col">ประเภท</th><th scope="col">ทั้งหมด</th>${st2.map(([, l]) => `<th scope="col">${esc(l)}</th>`).join('')}<th scope="col">เวลาเฉลี่ย (วัน)</th></tr></thead><tbody>${Object.values(r.rows).map(line).join('')}${line(r.totals)}</tbody></table></div>`;
            }

            // ------------------------------------------------------------ events
            root.addEventListener('submit', async ev => {
                const f = ev.target.closest('form[data-role]');
                if (!f) return;
                ev.preventDefault();
                const role = f.dataset.role;
                const fd = new FormData(f);
                if (role === 'filter') {
                    st.filters = {q: fd.get('q') || '', category: fd.get('category') || '', status: fd.get('status') || '', overdue: fd.get('overdue') ? '1' : '', page: 1};
                    try { await list(); } catch (e) { msg('error', e.message); }
                    return;
                }
                if (!cur) return;
                const v = cur.case.version;
                if (role === 'assign') await act('assign', {version: v, assignee_id: fd.get('assignee_id') ? Number(fd.get('assignee_id')) : null}, 'บันทึกผู้รับผิดชอบแล้ว');
                else if (role === 'due') await act('due', {version: v, due_on: String(fd.get('due_on') || '')}, 'เปลี่ยนกำหนดเวลาแล้ว');
                else if (role === 'accused') await act('accused', {version: v, user_ids: fd.getAll('user_ids').map(Number)}, 'บันทึกรายชื่อผู้ถูกร้องแล้ว');
                else if (role === 'reply') await act('reply', {version: v, text: String(fd.get('text') || '')}, 'บันทึกคำตอบแล้ว ผู้ร้องเห็นได้ที่หน้าตรวจสอบสถานะ');
                else if (role === 'note') await act('notes', {body: String(fd.get('body') || '')}, 'เพิ่มบันทึกแล้ว');
            });
            root.addEventListener('click', async ev => {
                const b = ev.target.closest('[data-act]');
                if (!b || b.tagName === 'A') return;
                const a = b.dataset.act;
                try {
                    if (a === 'page') { st.filters.page = Math.max(1, Number(b.dataset.p)); await list(); }
                    else if (a === 'close') { if (window.confirm('ปิดเรื่องนี้หรือไม่')) await act('close', {version: cur.case.version}, 'ปิดเรื่องแล้ว'); }
                    else if (a === 'reopen') await act('reopen', {version: cur.case.version}, 'เปิดเรื่องใหม่แล้ว');
                    else if (a === 'reissue') { if (window.confirm('ออกรหัสติดตามใหม่ รหัสเดิมจะใช้ไม่ได้ทันที')) await act('track-code', {version: cur.case.version}, 'รหัสติดตามใหม่คือ'); }
                    else if (a === 'show-report') await showReport(b.dataset.y);
                    else if (a === 'publish' || a === 'unpublish') {
                        if (a === 'publish' && !window.confirm('เผยแพร่ตัวเลขของปีงบประมาณ ' + b.dataset.y + ' บนเว็บไซต์สาธารณะหรือไม่')) return;
                        await ctx.api(A + '/reports/' + encodeURIComponent(b.dataset.y) + '/publish', {method: 'POST', body: {action: a}});
                        if (!ctx.alive()) return;
                        msg('ok', a === 'publish' ? 'เผยแพร่รายงานแล้ว' : 'เลิกเผยแพร่รายงานแล้ว');
                        await report();
                    }
                } catch (e) { if (ctx.alive()) msg('error', e.message); }
            });

            try {
                if (part === 'report') await report();
                else if (/^\d+$/.test(part)) await loadCase(part);
                else await list();
            } catch (e) {
                if (!ctx.alive()) return;
                body.innerHTML = `<p class="cmp-note cmp-error" role="alert">${esc(e.status === 404 ? 'ไม่พบเรื่องนี้' : e.message)}</p><p><a href="#complaints">กลับรายการ</a></p>`;
            }
        },
    });
})();
