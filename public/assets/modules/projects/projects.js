'use strict';
// projects module: staff page #projects (annual action plan, projects, activities, budget, reports) for the /work shell.
// Money is integer satang only; baht text typed by the user is parsed with a regex, never with floats.
(() => {
    if (!window.SaanWork || !window.SaanCore) return;
    const esc = v => window.SaanCore.esc(v);
    const STATUS = {proposed: 'เสนอโครงการ', approved: 'อนุมัติแล้ว', in_progress: 'กำลังดำเนินการ', done: 'เสร็จสิ้น', cancelled: 'ยกเลิก'};
    const PLAN = {draft: 'ร่าง', approved: 'ใช้งาน', closed: 'ปิดแผน'};
    const MOVE = {approved: 'อนุมัติโครงการ', proposed: 'ส่งกลับแก้ไข', in_progress: 'เริ่มดำเนินการ', done: 'จบโครงการ (เสร็จสิ้น)', cancelled: 'ยกเลิกโครงการ'};

    const fmt = s => {
        const n = Math.abs(Number(s) || 0);
        return (s < 0 ? '-' : '') + Math.floor(n / 100).toLocaleString('en-US') + '.' + String(n % 100).padStart(2, '0');
    };
    const plain = s => fmt(s).replace(/,/g, '');
    // "1,234.5" -> 123450; null when the text is not a valid amount with at most two decimals
    const toSatang = text => {
        const m = /^\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?\s*$/.exec(String(text));
        if (!m) return null;
        const baht = m[1].replace(/,/g, '');
        if (baht.length > 9) return null;
        return Number(baht) * 100 + Number((m[2] || '').padEnd(2, '0'));
    };
    const toast = (text, bad) => {
        const t = document.querySelector('#toast'); if (!t) return;
        t.textContent = text; t.classList.toggle('error', !!bad); t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    };
    const base = () => (document.querySelector('meta[name="school-base"]')?.content || '').replace(/\/+$/, '');
    const link = path => base() + path;
    const field = (label, control) => `<label class="prj-field"><span>${esc(label)}</span>${control}</label>`;
    const options = (items, cur, empty) => (empty ? `<option value="">${esc(empty)}</option>` : '')
        + items.map(([k, v]) => `<option value="${esc(k)}"${String(k) === String(cur) ? ' selected' : ''}>${esc(v)}</option>`).join('');
    const badge = st => `<span class="prj-badge ${esc(st)}">${esc(STATUS[st] || st)}</span>`;
    const pct = (spent, budget) => budget > 0 ? Math.min(100, Math.round(spent * 100 / budget)) : (spent > 0 ? 100 : 0);
    const bar = (spent, budget) => `<div class="prj-bar${spent > budget ? ' over' : ''}" role="img" aria-label="ใช้จ่าย ${esc(pct(spent, budget))} เปอร์เซ็นต์ของงบ"><i style="width:${esc(pct(spent, budget))}%"></i></div>`;

    let planId = 0; // selected plan survives navigation between list and detail

    async function guarded(fn, reload) {
        try { return await fn(); } catch (e) {
            if (e?.name === 'AbortError') return null;
            if (e?.status === 409 && reload) { toast((e.message || 'ข้อมูลถูกแก้ไขแล้ว') + ' (โหลดข้อมูลใหม่แล้ว)', true); reload(); } else toast(e?.message || 'เกิดข้อผิดพลาด', true);
            return null;
        }
    }

    function projectForm(me, p, perm, planIdValue) {
        const v = k => p ? esc(p[k] ?? '') : '';
        const isMgr = me.is_manager;
        const ownerOptions = options(me.teachers.map(t => [t.id, t.name]).concat(p && p.owner_teacher_id && !me.teachers.some(t => t.id === p.owner_teacher_id) ? [[p.owner_teacher_id, p.owner_name || ('ครู #' + p.owner_teacher_id)]] : []), p ? p.owner_teacher_id : (isMgr ? '' : me.teacher_id), isMgr ? 'เลือกครู' : '');
        return `<form class="prj-form" data-plan="${esc(planIdValue)}"><div class="prj-grid">
            ${field('รหัสโครงการ', `<input name="code" maxlength="40" required value="${v('code')}">`)}
            ${field('ชื่อโครงการ', `<input name="title" maxlength="200" required value="${v('title')}">`)}
            ${field('ผู้รับผิดชอบ', `<select name="owner_teacher_id"${perm.owner_change || !p && isMgr ? '' : ' disabled'}>${ownerOptions}</select>`)}
            ${field('กลุ่มงาน/ฝ่าย', `<input name="department" maxlength="120" value="${v('department')}">`)}
            ${field('ยุทธศาสตร์/มาตรฐาน', `<input name="strategy" maxlength="200" value="${v('strategy')}">`)}
            ${field('แหล่งงบประมาณ', `<select name="budget_source"${perm.budget ? '' : ' disabled'}>${options(me.sources.map(s => [s, s]), p ? p.budget_source : me.sources[0])}</select>`)}
            ${field('งบประมาณ (บาท)', `<input name="budget" inputmode="decimal" required value="${p ? esc(plain(p.budget_satang)) : ''}"${perm.budget ? '' : ' disabled'}>`)}
            ${field('วันเริ่ม', `<input type="date" name="starts_on" required value="${v('starts_on')}">`)}
            ${field('วันสิ้นสุด', `<input type="date" name="ends_on" required value="${v('ends_on')}">`)}</div>
            ${field('วัตถุประสงค์', `<textarea name="objective" maxlength="5000" required>${v('objective')}</textarea>`)}
            ${field('เป้าหมาย', `<textarea name="target" maxlength="5000">${v('target')}</textarea>`)}
            <button class="prj-btn primary" type="submit">${p ? 'บันทึกการแก้ไข' : 'เสนอโครงการ'}</button></form>`;
    }

    function readProject(f, p) {
        const budget = toSatang(f.budget.value);
        if (budget === null) { toast('งบประมาณต้องเป็นตัวเลข (ทศนิยมไม่เกิน 2 ตำแหน่ง)', true); return null; }
        const owner = f.owner_teacher_id.value;
        return {code: f.code.value.trim(), title: f.title.value.trim(), owner_teacher_id: owner ? Number(owner) : (p ? p.owner_teacher_id : null), department: f.department.value.trim() || null,
            strategy: f.strategy.value.trim() || null, objective: f.objective.value, target: f.target.value || null, starts_on: f.starts_on.value, ends_on: f.ends_on.value,
            budget_satang: budget, budget_source: f.budget_source.value};
    }

    // ------------------------------------------------------------ list
    async function list(root, ctx, me) {
        const plans = (await ctx.api('/api/projects/plans')).data;
        if (!ctx.alive()) return;
        if (!planId || !plans.some(p => p.id === planId)) planId = plans[0]?.id || 0;
        const host = document.createElement('div');
        host.className = 'prj-host';
        root.append(host);
        const redraw = async () => { await list(rootClear(root), ctx, me); };
        const plan = plans.find(p => p.id === planId) || null;
        const [sum, rows] = plan ? await Promise.all([ctx.api('/api/projects/summary?plan_id=' + planId), ctx.api('/api/projects/projects?plan_id=' + planId)]) : [null, {data: []}];
        if (!ctx.alive()) return;
        const planOptions = options(plans.map(p => [p.id, `ปีงบประมาณ ${p.fiscal_year_be} (${PLAN[p.status]})`]), planId);
        const t = sum?.totals;
        host.innerHTML = `<section class="prj glass"><div class="prj-head"><h1>โครงการ/แผนปฏิบัติการ</h1>
            <div class="prj-actions">${plans.length ? `<label class="prj-field"><span>แผนปีงบประมาณ</span><select id="prj-plan">${planOptions}</select></label>` : ''}
            ${plan ? `<a class="prj-btn" href="${esc(link('/api/projects/export.csv?plan_id=' + planId))}">ส่งออก CSV</a>` : ''}</div></div>
            ${plans.length ? '' : '<p class="prj-hint">ยังไม่มีแผนปฏิบัติการ' + (me.is_manager ? ' สร้างแผนของปีงบประมาณแรกได้ด้านล่าง' : ' ให้ผู้บริหารสร้างแผนก่อน') + '</p>'}
            ${plan ? `<div class="prj-sums"><div><span>งบรวม (ไม่นับที่ยกเลิก)</span><strong>${esc(fmt(t.budget_satang))}</strong></div><div><span>ใช้จ่ายแล้ว</span><strong>${esc(fmt(t.spent_satang))}</strong></div>
                <div><span>คงเหลือ</span><strong>${esc(fmt(t.remaining_satang))}</strong></div><div><span>โครงการ / เกินงบ</span><strong>${esc(t.count)} / ${esc(t.over_budget_count)}</strong></div></div>
                <div class="prj-table-wrap"><table class="prj-table"><thead><tr><th>แหล่งงบ</th><th class="n">โครงการ</th><th class="n">งบ</th><th class="n">อนุมัติแล้ว</th><th class="n">ใช้จ่าย</th><th class="n">คงเหลือ</th></tr></thead><tbody>
                ${sum.by_source.map(s => `<tr><td data-label="แหล่งงบ">${esc(s.source)}</td><td class="n" data-label="โครงการ">${esc(s.count)}</td><td class="n" data-label="งบ">${esc(fmt(s.budget_satang))}</td>
                    <td class="n" data-label="อนุมัติแล้ว">${esc(fmt(s.approved_budget_satang))}</td><td class="n" data-label="ใช้จ่าย">${esc(fmt(s.spent_satang))}</td><td class="n" data-label="คงเหลือ">${esc(fmt(s.remaining_satang))}</td></tr>`).join('')}</tbody></table></div>
                <div class="prj-actions"><label class="prj-field"><span>สถานะ</span><select id="prj-f-status">${options(Object.entries(STATUS), '', 'ทุกสถานะ')}</select></label>
                <label class="prj-field"><span>ค้นหา</span><input id="prj-f-q" type="search" maxlength="80" placeholder="รหัสหรือชื่อโครงการ"></label>
                <label class="prj-check"><input type="checkbox" id="prj-f-mine"> เฉพาะของฉัน</label></div>
                <ul class="prj-cards" id="prj-cards"></ul>
                ${plan.status !== 'closed' ? `<details class="prj-panel" id="prj-new"><summary>เสนอโครงการใหม่</summary>${projectForm(me, null, {budget: true, owner_change: me.is_manager}, planId)}</details>` : '<p class="prj-hint">แผนนี้ปิดแล้ว เป็นแบบอ่านอย่างเดียว</p>'}` : ''}
            ${me.is_manager ? planAdmin(me, plan) : ''}</section>`;

        const cards = host.querySelector('#prj-cards');
        const paint = () => {
            if (!cards) return;
            const st = host.querySelector('#prj-f-status').value, q = host.querySelector('#prj-f-q').value.trim().toLowerCase(), mine = host.querySelector('#prj-f-mine').checked;
            const shown = rows.data.filter(p => (!st || p.status === st) && (!mine || p.owner_teacher_id === me.teacher_id) && (!q || (p.title + ' ' + p.code).toLowerCase().includes(q)));
            cards.innerHTML = shown.map(p => `<li><a href="#projects/p/${esc(p.id)}"><span><strong>${esc(p.code)}</strong> ${esc(p.title)}</span>
                <span class="prj-meta">${esc(p.owner_name || 'ไม่ระบุผู้รับผิดชอบ')} · ${esc(p.budget_source)}</span>
                <span>${badge(p.status)}${p.over_budget ? ' <span class="prj-badge over">เกินงบ</span>' : ''}</span>
                ${bar(p.spent_satang, p.budget_satang)}<span class="prj-meta">ใช้ ${esc(fmt(p.spent_satang))} จากงบ ${esc(fmt(p.budget_satang))} บาท</span></a></li>`).join('')
                || '<li><p class="prj-hint" style="padding:.7rem">ไม่พบโครงการ</p></li>';
        };
        if (cards) { paint(); ['#prj-f-status', '#prj-f-q', '#prj-f-mine'].forEach(s => host.querySelector(s).addEventListener('input', paint)); }
        host.querySelector('#prj-plan')?.addEventListener('change', e => { planId = Number(e.target.value); redraw(); });
        host.querySelector('#prj-new form')?.addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.currentTarget, body = readProject(f, null);
            if (!body) return;
            f.querySelector('button').disabled = true;
            const ok = await guarded(() => ctx.api('/api/projects/projects', {method: 'POST', idempotencyKey: window.SaanCore.uid(), body: {...body, plan_id: planId}}), redraw);
            if (ok) { toast('เสนอโครงการแล้ว'); window.SaanWork.go('projects/p/' + ok.id); } else f.querySelector('button').disabled = false;
        });
        if (me.is_manager) bindPlanAdmin(host, ctx, plan, redraw, me);
    }

    const rootClear = root => { root.replaceChildren(); return root; };

    function planAdmin(me, plan) {
        const next = {draft: [['approved', 'อนุมัติแผน']], approved: [['closed', 'ปิดแผน (เป็นแบบอ่านอย่างเดียว)']], closed: me.is_admin ? [['approved', 'เปิดแผนอีกครั้ง']] : []};
        return `<details class="prj-panel"><summary>จัดการแผนปฏิบัติการ</summary>
            ${plan ? `<form id="prj-plan-rename"><div class="prj-grid">${field('ชื่อแผน', `<input name="title" maxlength="200" required value="${esc(plan.title)}"${plan.status === 'closed' ? ' disabled' : ''}>`)}</div>
                <div class="prj-actions"><button class="prj-btn small" type="submit"${plan.status === 'closed' ? ' disabled' : ''}>บันทึกชื่อแผน</button>
                ${(next[plan.status] || []).map(([to, label]) => `<button class="prj-btn small primary" type="button" data-plan-to="${esc(to)}">${esc(label)}</button>`).join('')}
                ${plan.status === 'draft' && !plan.total_count ? '<button class="prj-btn small danger" type="button" data-plan-del="1">ลบแผน</button>' : ''}</div></form>` : ''}
            <form id="prj-plan-new"><h2>สร้างแผนใหม่</h2><div class="prj-grid">${field('ปีงบประมาณ (พ.ศ.)', `<input name="fiscal_year_be" inputmode="numeric" required value="${esc(me.fiscal_year_be)}">`)}
                ${field('ชื่อแผน', `<input name="title" maxlength="200" required value="${esc('แผนปฏิบัติการประจำปีงบประมาณ ' + me.fiscal_year_be)}">`)}</div>
                <button class="prj-btn primary" type="submit">สร้างแผน</button></form></details>`;
    }

    function bindPlanAdmin(host, ctx, plan, redraw) {
        host.querySelector('#prj-plan-new')?.addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.currentTarget;
            const ok = await guarded(() => ctx.api('/api/projects/plans', {method: 'POST', idempotencyKey: window.SaanCore.uid(), body: {fiscal_year_be: Number(f.fiscal_year_be.value), title: f.title.value.trim()}}), redraw);
            if (ok) { planId = ok.id; toast('สร้างแผนแล้ว'); redraw(); }
        });
        if (!plan) return;
        host.querySelector('#prj-plan-rename')?.addEventListener('submit', async e => {
            e.preventDefault();
            const ok = await guarded(() => ctx.api('/api/projects/plans/' + plan.id, {method: 'PUT', body: {title: e.currentTarget.title.value.trim(), version: plan.version}}), redraw);
            if (ok) { toast('บันทึกแล้ว'); redraw(); }
        });
        host.querySelectorAll('[data-plan-to]').forEach(b => b.addEventListener('click', async () => {
            if (b.dataset.planTo === 'closed' && !confirm('ปิดแผนแล้วทุกโครงการจะแก้ไขไม่ได้ ยืนยัน?')) return;
            const ok = await guarded(() => ctx.api('/api/projects/plans/' + plan.id + '/status', {method: 'POST', body: {to: b.dataset.planTo, version: plan.version}}), redraw);
            if (ok) { toast('เปลี่ยนสถานะแผนแล้ว'); redraw(); }
        }));
        host.querySelector('[data-plan-del]')?.addEventListener('click', async () => {
            if (!confirm('ลบแผนนี้?')) return;
            const ok = await guarded(() => ctx.api('/api/projects/plans/' + plan.id, {method: 'DELETE', body: {version: plan.version}}), redraw);
            if (ok) { planId = 0; toast('ลบแผนแล้ว'); redraw(); }
        });
    }

    // ------------------------------------------------------------ detail
    async function detail(root, ctx, me, id) {
        const host = document.createElement('div');
        host.className = 'prj-host';
        root.append(host);
        const load = async () => {
            const d = await ctx.api('/api/projects/projects/' + encodeURIComponent(id));
            if (!ctx.alive()) return;
            draw(d);
        };
        const reload = () => guarded(load).then(() => {});
        function draw(d) {
            const p = d.project, perm = d.perm, closed = d.plan.status === 'closed';
            lastStatus = p.status;
            const acts = d.activities.map(a => activityItem(a, perm)).join('') || '<li><p class="prj-hint">ยังไม่มีกิจกรรม</p></li>';
            const reps = d.reports.map(r => reportItem(r, perm)).join('') || '<li><p class="prj-hint">ยังไม่มีรายงานผล</p></li>';
            host.innerHTML = `<section class="prj glass"><p><a href="#projects">&larr; รายการโครงการ</a></p>
                <div class="prj-head"><h1>${esc(p.code)} ${esc(p.title)}</h1><span>${badge(p.status)}${closed ? ' <span class="prj-badge cancelled">แผนปิดแล้ว</span>' : ''}</span></div>
                ${bar(p.spent_satang, p.budget_satang)}
                <div class="prj-sums"><div><span>งบโครงการ</span><strong>${esc(fmt(p.budget_satang))}</strong></div><div><span>แบ่งให้กิจกรรม</span><strong>${esc(fmt(p.allocated_satang))}</strong></div>
                    <div><span>ใช้จ่ายแล้ว</span><strong>${esc(fmt(p.spent_satang))}</strong></div><div><span>คงเหลือ${p.over_budget ? ' (เกินงบ)' : ''}</span><strong>${esc(fmt(p.remaining_satang))}</strong></div></div>
                <div class="prj-actions"><a class="prj-btn" target="_blank" rel="noopener" href="${esc(link('/api/projects/projects/' + p.id + '/form'))}">พิมพ์แบบโครงการ (A4)</a>
                    ${perm.transitions.map(to => `<button type="button" class="prj-btn small${to === 'cancelled' ? ' danger' : ' primary'}" data-to="${esc(to)}">${esc(p.status === 'done' && to === 'in_progress' ? 'เปิดดำเนินการอีกครั้ง' : (MOVE[to] || to))}</button>`).join('')}
                    ${perm.delete ? '<button type="button" class="prj-btn small danger" data-del="1">ลบโครงการ</button>' : ''}</div>
                <dl class="prj-dl"><dt>ผู้รับผิดชอบ</dt><dd>${esc(p.owner_name || '-')}</dd><dt>กลุ่มงาน/ฝ่าย</dt><dd>${esc(p.department || '-')}</dd><dt>ยุทธศาสตร์/มาตรฐาน</dt><dd>${esc(p.strategy || '-')}</dd>
                    <dt>แหล่งงบ</dt><dd>${esc(p.budget_source)}</dd><dt>ระยะเวลา</dt><dd>${esc(window.SaanCore.date(p.starts_on))} ถึง ${esc(window.SaanCore.date(p.ends_on))}</dd>
                    <dt>วัตถุประสงค์</dt><dd>${esc(p.objective)}</dd><dt>เป้าหมาย</dt><dd>${esc(p.target || '-')}</dd></dl>
                ${perm.project ? `<details class="prj-panel"><summary>แก้ไขรายละเอียดโครงการ</summary>${projectForm(me, p, perm, p.plan_id)}</details>` : ''}
                <h2>กิจกรรมและการใช้จ่าย</h2><ul class="prj-list" id="prj-acts">${acts}</ul>
                ${perm.activity ? `<details class="prj-panel"><summary>เพิ่มกิจกรรม</summary>${activityForm(null, perm, p)}</details>` : ''}
                <h2>รายงานผล</h2><ul class="prj-list" id="prj-reps">${reps}</ul>
                ${perm.report ? `<details class="prj-panel"><summary>ส่งรายงานผล</summary><form id="prj-report-new" enctype="multipart/form-data">
                    ${field('สรุปผลการดำเนินงาน', '<textarea name="summary" maxlength="5000" required></textarea>')}
                    <div class="prj-grid">${field('ผลสำเร็จ (ร้อยละ 0-100)', '<input name="outcome_pct" inputmode="numeric" maxlength="3">')}${field('หลักฐาน (PDF, JPEG, PNG ไม่เกิน 10 MB)', '<input type="file" name="evidence" accept="application/pdf,image/jpeg,image/png">')}</div>
                    <button class="prj-btn primary" type="submit">ส่งรายงาน</button></form></details>` : ''}</section>`;
            bind(d);
        }

        function activityForm(a, perm, p) {
            const v = k => a ? esc(a[k] ?? '') : '';
            return `<form class="prj-act-form"${a ? ` data-id="${esc(a.id)}" data-version="${esc(a.version)}"` : ''}><div class="prj-grid">
                ${field('กิจกรรม', `<input name="title" maxlength="200" required value="${v('title')}">`)}
                ${field('กำหนดการ', `<input type="date" name="planned_on" value="${v('planned_on')}">`)}
                ${field('งบกิจกรรม (บาท)', `<input name="budget" inputmode="decimal" required value="${a ? esc(plain(a.budget_satang)) : '0.00'}">`)}
                ${field('ใช้จ่ายจริง (บาท)', `<input name="spent" inputmode="decimal" value="${a ? esc(plain(a.spent_satang)) : '0.00'}"${['in_progress', 'done'].includes(p.status) ? '' : ' disabled'}>`)}
                ${field('วันที่ทำกิจกรรม', `<input type="date" name="done_on" max="${esc(me.today)}" value="${v('done_on')}">`)}
                ${perm.ledger ? field('เลขรายการรายจ่ายในสมุดเงินสด (ผู้บริหารเชื่อมหรือยกเลิกได้ ระบบไม่แก้สมุดเงินสด)', `<input name="fin_ledger_id" inputmode="numeric" value="${v('fin_ledger_id')}">`) : ''}</div>
                ${field('หมายเหตุ', `<input name="note" maxlength="500" value="${v('note')}">`)}
                ${field('เหตุผลที่ใช้จ่ายเกินงบ (กรอกเมื่อใช้จ่ายเกินงบกิจกรรมหรืองบโครงการ)', `<input name="over_budget_reason" maxlength="500" value="${v('over_budget_reason')}">`)}
                <div class="prj-actions"><button class="prj-btn primary" type="submit">${a ? 'บันทึกกิจกรรม' : 'เพิ่มกิจกรรม'}</button>
                ${a ? '<button type="button" class="prj-btn danger small" data-act-del="1">ลบกิจกรรม</button>' : ''}</div></form>`;
        }

        function activityItem(a, perm) {
            const p = {status: lastStatus};
            const ledger = a.ledger ? `<span class="prj-meta">สมุดเงินสด #${esc(a.fin_ledger_id)} ${esc(window.SaanCore.date(a.ledger.entry_date))} ${esc(fmt(a.ledger.amount_satang))} บาท</span>` : '';
            return `<li><div><strong>${esc(a.title)}</strong> ${a.over_budget ? '<span class="prj-badge over">เกินงบ</span>' : ''}</div>
                <span class="prj-meta">${a.planned_on ? 'กำหนด ' + esc(window.SaanCore.date(a.planned_on)) + ' · ' : ''}${a.done_on ? 'ทำเมื่อ ' + esc(window.SaanCore.date(a.done_on)) + ' · ' : ''}งบ ${esc(fmt(a.budget_satang))} · ใช้ ${esc(fmt(a.spent_satang))} บาท</span>
                ${bar(a.spent_satang, a.budget_satang)}${a.note ? `<span class="prj-meta">${esc(a.note)}</span>` : ''}${a.over_budget_reason ? `<span class="prj-meta">เหตุผลที่เกินงบ: ${esc(a.over_budget_reason)}</span>` : ''}${ledger}
                ${perm.activity ? `<details><summary>แก้ไข</summary>${activityForm(a, perm, p)}</details>` : ''}</li>`;
        }

        function reportItem(r, perm) {
            return `<li><span class="prj-meta">${esc(window.SaanCore.date(String(r.submitted_at).slice(0, 10)))} · ${esc(r.submitter || '')}${r.outcome_pct !== null ? ' · ผลสำเร็จร้อยละ ' + esc(r.outcome_pct) : ''}</span>
                <div style="white-space:pre-wrap;overflow-wrap:anywhere">${esc(r.summary)}</div>
                ${r.has_evidence && perm.evidence ? `<a class="prj-btn small" href="${esc(link('/api/projects/reports/' + r.id + '/evidence'))}">ดาวน์โหลดหลักฐาน ${esc(r.evidence_name || '')}</a>` : (r.has_evidence ? '<span class="prj-meta">มีไฟล์หลักฐาน (เฉพาะเจ้าของโครงการและผู้บริหารดูได้)</span>' : '')}
                ${perm.report ? `<details><summary>แก้ไข/ลบ</summary><form class="prj-report-form" data-id="${esc(r.id)}" data-version="${esc(r.version)}">
                    ${field('สรุปผล', `<textarea name="summary" maxlength="5000" required>${esc(r.summary)}</textarea>`)}${field('ผลสำเร็จ (ร้อยละ)', `<input name="outcome_pct" inputmode="numeric" maxlength="3" value="${esc(r.outcome_pct ?? '')}">`)}
                    <div class="prj-actions"><button class="prj-btn small primary" type="submit">บันทึก</button><button type="button" class="prj-btn small danger" data-rep-del="1">ลบรายงาน</button></div></form></details>` : ''}</li>`;
        }

        let lastStatus = '';
        function bind(d) {
            const p = d.project;
            host.querySelectorAll('[data-to]').forEach(b => b.addEventListener('click', async () => {
                const to = b.dataset.to;
                if (to === 'cancelled' && !confirm('ยกเลิกโครงการนี้?')) return;
                b.disabled = true;
                const ok = await guarded(() => ctx.api('/api/projects/projects/' + p.id + '/status', {method: 'POST', body: {to, version: p.version}}), reload);
                if (ok) { toast('เปลี่ยนสถานะแล้ว'); reload(); } else b.disabled = false;
            }));
            host.querySelector('[data-del]')?.addEventListener('click', async () => {
                if (!confirm('ลบโครงการและกิจกรรมทั้งหมดของโครงการนี้?')) return;
                const ok = await guarded(() => ctx.api('/api/projects/projects/' + p.id, {method: 'DELETE', body: {version: p.version}}), reload);
                if (ok) { toast('ลบโครงการแล้ว'); window.SaanWork.go('projects'); }
            });
            host.querySelector('.prj-panel .prj-form')?.addEventListener('submit', async e => {
                e.preventDefault();
                const f = e.currentTarget, body = readProject(f, p);
                if (!body) return;
                f.querySelector('button').disabled = true;
                const ok = await guarded(() => ctx.api('/api/projects/projects/' + p.id, {method: 'PUT', body: {...body, version: p.version}}), reload);
                if (ok) { toast('บันทึกแล้ว'); reload(); } else f.querySelector('button').disabled = false;
            });
            host.querySelectorAll('.prj-act-form').forEach(f => f.addEventListener('submit', async e => {
                e.preventDefault();
                const budget = toSatang(f.budget.value), spent = f.spent.disabled ? 0 : toSatang(f.spent.value || '0');
                if (budget === null || spent === null) return toast('จำนวนเงินต้องเป็นตัวเลข (ทศนิยมไม่เกิน 2 ตำแหน่ง)', true);
                const body = {title: f.title.value.trim(), planned_on: f.planned_on.value || null, done_on: f.done_on.value || null, budget_satang: budget, note: f.note.value.trim() || null,
                    over_budget_reason: f.over_budget_reason.value.trim() || null};
                if (!f.spent.disabled) body.spent_satang = spent;
                if (f.fin_ledger_id) body.fin_ledger_id = f.fin_ledger_id.value.trim() ? Number(f.fin_ledger_id.value) : null;
                f.querySelector('button[type=submit]').disabled = true;
                const url = f.dataset.id ? '/api/projects/activities/' + f.dataset.id : '/api/projects/projects/' + p.id + '/activities';
                if (f.dataset.id) body.version = Number(f.dataset.version);
                const ok = await guarded(() => ctx.api(url, {method: f.dataset.id ? 'PUT' : 'POST', idempotencyKey: f.dataset.id ? undefined : window.SaanCore.uid(), body}), reload);
                if (ok) { toast('บันทึกกิจกรรมแล้ว'); reload(); } else f.querySelector('button[type=submit]').disabled = false;
            }));
            host.querySelectorAll('[data-act-del]').forEach(b => b.addEventListener('click', async () => {
                const f = b.closest('form');
                if (!confirm('ลบกิจกรรมนี้?')) return;
                const ok = await guarded(() => ctx.api('/api/projects/activities/' + f.dataset.id, {method: 'DELETE', body: {version: Number(f.dataset.version)}}), reload);
                if (ok) { toast('ลบกิจกรรมแล้ว'); reload(); }
            }));
            host.querySelector('#prj-report-new')?.addEventListener('submit', async e => {
                e.preventDefault();
                const f = e.currentTarget, fd = new FormData();
                fd.append('summary', f.summary.value);
                if (f.outcome_pct.value.trim()) fd.append('outcome_pct', f.outcome_pct.value.trim());
                if (f.evidence.files[0]) fd.append('evidence', f.evidence.files[0]);
                f.querySelector('button').disabled = true;
                const ok = await guarded(() => ctx.api('/api/projects/projects/' + p.id + '/reports', {method: 'POST', idempotencyKey: window.SaanCore.uid(), body: fd}), reload);
                if (ok) { toast('ส่งรายงานแล้ว'); reload(); } else f.querySelector('button').disabled = false;
            });
            host.querySelectorAll('.prj-report-form').forEach(f => f.addEventListener('submit', async e => {
                e.preventDefault();
                const body = {summary: f.summary.value, outcome_pct: f.outcome_pct.value.trim() ? Number(f.outcome_pct.value) : null, version: Number(f.dataset.version)};
                const ok = await guarded(() => ctx.api('/api/projects/reports/' + f.dataset.id, {method: 'PUT', body}), reload);
                if (ok) { toast('บันทึกแล้ว'); reload(); }
            }));
            host.querySelectorAll('[data-rep-del]').forEach(b => b.addEventListener('click', async () => {
                const f = b.closest('form');
                if (!confirm('ลบรายงานนี้และไฟล์หลักฐาน?')) return;
                const ok = await guarded(() => ctx.api('/api/projects/reports/' + f.dataset.id, {method: 'DELETE', body: {version: Number(f.dataset.version)}}), reload);
                if (ok) { toast('ลบรายงานแล้ว'); reload(); }
            }));
        }
        await load();
    }

    window.SaanWork.register('projects', {
        title: 'โครงการ/แผนปฏิบัติการ',
        async render(root, ctx) {
            const me = await ctx.api('/api/projects/me');
            if (!ctx.alive()) return;
            if (ctx.parts[0] === 'p' && /^\d+$/.test(ctx.parts[1] || '')) return detail(root, ctx, me, ctx.parts[1]);
            return list(root, ctx, me);
        },
    });
})();
