'use strict';
// Admin page "อัปเดตระบบ" (/admin/updates): installed versions, new release, progress, mode, manual upload, history, backups.
// The page holds no state of its own that the server does not have: a reload resumes from GET /api/site/updates/self.
// The administrator's password lives in a variable only while one download+install run is going; it is never stored.
// Every value shown is escaped with SaanCore.esc.
(() => {
    const {api, esc, date} = window.SaanCore || {};
    const $ = s => document.querySelector(s);
    const box = {installed: $('#upd-installed [data-installed]'), nw: $('#upd-new [data-new]'), steps: $('#upd-run [data-steps]'), line: $('#upd-run [data-progress-line]'), bars: $('#upd-run [data-bars]'), log: $('#upd-run [data-log]'),
        manual: $('#upd-manual [data-manual]'), history: $('#upd-history [data-history]'), backups: $('#upd-backups [data-backups]'), notice: $('#upd-auto-notice')};
    const dlg = $('#upd-dialog'); const form = $('[data-mode-form]');
    if (!api || !box.installed || !dlg || !form) return;

    const STEPS = [['check', 'ตรวจ'], ['download', 'ดาวน์โหลด'], ['backup', 'สำรอง'], ['stage', 'พักไฟล์'], ['swap', 'สลับไฟล์'], ['migrate', 'ปรับฐานข้อมูล'], ['health', 'ตรวจสุขภาพ'], ['done', 'เสร็จ']];
    const MODE_TEXT = {off: 'ปิด', notify: 'แจ้งเตือนรุ่นใหม่', auto_patch: 'อัปเดตแพตช์อัตโนมัติ'};
    let S = null;          // last status from the server
    let manual = null;     // packages of the manual folder (GET /api/site/updates)
    let flow = {phase: 'idle'}; // this tab's own run: idle | download | install | wait | done | failed
    let busy = false, pollTimer = null;

    const mb = b => b == null ? '-' : (b / 1048576 >= 10 ? Math.round(b / 1048576) : (b / 1048576).toFixed(1)) + ' MB';
    const when = iso => iso ? date(iso, {day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'}) + ' น.' : '-';
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const showErr = (el, msg) => { if (!el) return; el.textContent = msg || ''; el.hidden = !msg; };

    // ---------------------------------------------------------------- password dialog (one reusable dialog)
    function ask({title, text, okLabel, error}) {
        return new Promise(resolve => {
            const q = s => dlg.querySelector(s);
            q('[data-title]').textContent = title; q('[data-text]').textContent = text; q('[data-password]').value = ''; q('[data-ok]').textContent = okLabel || 'ยืนยัน';
            showErr(q('[data-error]'), error || '');
            let settled = false; const done = v => { if (settled) return; settled = true; if (dlg.open) dlg.close(); resolve(v); };
            q('form').onsubmit = ev => { ev.preventDefault(); done({password: q('[data-password]').value}); };
            q('[data-cancel]').onclick = () => done(null);
            dlg.onclose = () => done(null);
            dlg.showModal(); q('[data-password]').focus();
        });
    }
    const isPasswordError = e => !!(e && e.data && e.data.errors && e.data.errors.current_password);

    // ---------------------------------------------------------------- render
    function renderInstalled() {
        const v = S.versions; const upd = new Set((S.plan.packages || []).map(p => p.key));
        const db = v.database && v.database.version ? esc((v.database.driver === 'mariadb' ? 'MariaDB' : v.database.driver === 'mysql' ? 'MySQL' : v.database.driver) + ' ' + v.database.version) : 'ไม่ทราบ';
        const mods = S.modules || [];
        box.installed.removeAttribute('aria-busy');
        box.installed.innerHTML = `<dl class="upd-dl"><div><dt>สาน (Saan)</dt><dd>${esc(v.saan)}</dd></div><div><dt>Laravel</dt><dd>${esc(v.laravel)}</dd></div><div><dt>PHP</dt><dd>${esc(v.php)}</dd></div><div><dt>ฐานข้อมูล</dt><dd>${db}</dd></div></dl>
<details class="upd-modules"><summary>โมดูลที่ติดตั้ง (${mods.length})</summary><ul>${mods.map(m => `<li>${esc(m.name)} <b>${esc(m.version)}</b>${m.active ? '' : ' <small>(ปิดอยู่)</small>'}${upd.has(m.id) ? ' <span class="upd-badge">มีรุ่นใหม่</span>' : ''}</li>`).join('') || '<li>ไม่พบโมดูล</li>'}</ul></details>`;
        const a = S.auto && S.auto.notice;
        if (a && a.text) { box.notice.hidden = false; box.notice.className = 'account-alert' + (a.kind === 'ok' || a.kind === 'skipped' ? '' : ' error'); box.notice.textContent = a.text; } else box.notice.hidden = true;
    }

    function renderNew() {
        const p = S.plan, f = S.feed; let h = '';
        box.nw.removeAttribute('aria-busy');
        if (!f.enabled) h += '<p>การตรวจรุ่นใหม่ปิดอยู่ ระบบไม่เชื่อมต่อภายนอก เลือกโหมด "แจ้งเตือนรุ่นใหม่" ที่ส่วนการตั้งค่าเพื่อเริ่มตรวจ หรือติดตั้งจากไฟล์ที่อัปโหลดเอง</p>';
        else {
            const last = f.last_check;
            h += `<p class="upd-note">ตรวจล่าสุด ${f.checked_at ? esc(when(f.checked_at)) : (last && last.at ? esc(when(last.at)) : 'ยังไม่เคยตรวจ')}${last && last.ok === false ? ' <span class="upd-error-text">(ไม่สำเร็จ: ' + esc(last.error || '') + ')</span>' : ''}${f.stale ? ' <span class="upd-error-text">(เก่ากว่า 7 วัน ตรวจใหม่ก่อนติดตั้ง)</span>' : ''}</p>`;
            if (!p.any) h += `<p class="upd-ok">${f.checked_at ? 'เป็นรุ่นล่าสุดแล้ว' : 'กด "ตรวจรุ่นใหม่" เพื่อดูว่ามีรุ่นใหม่หรือไม่'}</p>`;
        }
        if (p.any) {
            h += `<table class="upd-table"><thead><tr><th scope="col">ส่วนที่อัปเดต</th><th scope="col">รุ่น</th><th scope="col">ขนาด</th><th scope="col">ประเภท</th></tr></thead><tbody>${p.packages.map(x => {
                const kind = x.same_minor ? 'แพตช์' : (x.type === 'core' ? 'รุ่นรอง/รุ่นหลัก' : 'รุ่นรอง/รุ่นหลัก');
                const state = x.downloaded ? ' · ดาวน์โหลดแล้ว' : (x.part_bytes ? ' · ดาวน์โหลดไว้บางส่วน' : '');
                return `<tr><th scope="row" data-label="ส่วนที่อัปเดต">${esc(x.name)}${x.summary ? `<small class="upd-sum">${esc(x.summary)}</small>` : ''}</th><td data-label="รุ่น">${esc(x.from || '-')} &rarr; <b>${esc(x.to)}</b>${x.notes_url ? ` <a href="${esc(x.notes_url)}" target="_blank" rel="noopener noreferrer">บันทึกรุ่น</a>` : ''}</td><td data-label="ขนาด">${x.bytes ? esc(mb(x.bytes)) : '-'}</td><td data-label="ประเภท">${esc(kind)}${esc(state)}</td></tr>`;
            }).join('')}</tbody></table>`;
            if (p.laravel && p.laravel.changes) h += `<p class="upd-laravel">Laravel (ตัวกลางของระบบ): <b>${esc(p.laravel.from)}</b> &rarr; <b>${esc(p.laravel.to)}</b></p>`;
            if (p.downloadable) h += `<p class="upd-note">ต้องดาวน์โหลดอีก ${esc(mb(p.download_bytes))} (รวมทุกแพ็กเกจ ${esc(mb(p.total_bytes))}) ลำดับติดตั้ง: ${p.packages.map(x => esc(x.name)).join(' &rarr; ')}</p>`;
            else h += '<p class="upd-note">ฟีดนี้ยังไม่มีข้อมูลดาวน์โหลดของรุ่นนี้ ติดตั้งจากไฟล์ที่อัปโหลดเอง (ส่วนด้านล่าง)</p>';
        }
        const show = (p.blockers || []).filter(b => !(b.code === 'feed_off'));
        if (show.length) h += `<ul class="upd-blockers">${show.map(b => `<li>${esc(b.message)}</li>`).join('')}</ul>`;
        if (S.running) h += '<p class="upd-note" role="status">มีการอัปเดตกำลังทำงานอยู่ (จากอีกหน้าต่างหรืองานอัตโนมัติ) หน้านี้แสดงความคืบหน้าให้ ไม่เริ่มซ้ำ</p>';
        else if ((S.downloads || []).some(d => d.part) && !busy) h += `<p class="upd-note">มีไฟล์ที่ดาวน์โหลดค้างไว้ กดปุ่มเดิมเพื่อทำต่อ</p>`;
        box.nw.innerHTML = h;
        const install = $('[data-act="install"]'), retry = $('[data-act="retry-auto"]');
        install.disabled = busy || S.running || !p.ready;
        install.textContent = (S.downloads || []).some(d => d.part) ? 'ดาวน์โหลดต่อและติดตั้ง' : 'ดาวน์โหลดและติดตั้ง';
        $('[data-act="check"]').disabled = busy || !f.enabled;
        retry.hidden = !Object.values((S.auto && S.auto.attempts) || {}).some(t => t.state === 'failed');
    }

    function stepState() {
        // which steps are done and which one is going on: this tab's own run first, else the newest update folder of the server
        const lu = S.last_update, sn = lu ? lu.steps : [];
        const live = S.running || flow.phase === 'install' || flow.phase === 'wait';
        const done = {check: false, download: false, backup: sn.includes('database_backup'), stage: sn.includes('staged'), swap: sn.includes('files_written'), migrate: sn.includes('health_ok'), health: sn.includes('health_ok'), done: lu && lu.state === 'completed'};
        const rolled = lu && lu.state === 'rolled_back' && (live || flow.phase === 'failed' || flow.phase === 'wait');
        if (flow.phase !== 'idle' || live) done.check = true;
        if (flow.phase === 'install' || flow.phase === 'wait' || flow.phase === 'done' || live || sn.length) done.download = true;
        if (!live && flow.phase === 'idle' && lu && lu.state !== 'running') {
            // an old finished update: show it as history, not as progress
            return {done: Object.fromEntries(STEPS.map(s => [s[0], lu.state === 'completed'])), current: null, rolled: lu.state === 'rolled_back', idle: true};
        }
        let current = null;
        if (!live && flow.phase === 'idle') return {done, current: null, rolled, idle: true}; // nothing is going on: no step is highlighted
        if (flow.phase === 'download') current = 'download';
        else for (const [k] of STEPS) if (!done[k]) { current = k; break; }
        return {done, current, rolled, idle: flow.phase === 'idle' && !live};
    }

    function renderSteps() {
        const st = stepState();
        box.steps.innerHTML = STEPS.map(([k, label], i) => {
            const last = k === 'done'; const text = last && st.rolled ? 'คืนรุ่นเดิม' : label;
            const cls = st.rolled && last ? 'is-failed' : st.done[k] ? 'is-done' : st.current === k ? 'is-current' : '';
            const mark = cls === 'is-done' ? '(เสร็จแล้ว)' : cls === 'is-current' ? '(กำลังทำ)' : cls === 'is-failed' ? '(ไม่สำเร็จ)' : '(รอ)';
            return `<li class="upd-step ${cls}"${cls === 'is-current' ? ' aria-current="step"' : ''}><span class="upd-step-n" aria-hidden="true">${i + 1}</span><span class="upd-step-t">${esc(text)} <span class="upd-vh">${mark}</span></span></li>`;
        }).join('');
        let line = 'ยังไม่มีการอัปเดตที่กำลังทำงาน';
        if (flow.phase === 'download') line = `กำลังดาวน์โหลด ${flow.index} จาก ${flow.total}: ${flow.label || ''}`;
        else if (flow.phase === 'install') line = `กำลังติดตั้ง ${flow.index} จาก ${flow.total}: ${flow.label || ''} (ระหว่างสลับไฟล์เว็บไซต์จะปิดชั่วคราว หน้านี้รอให้เอง)`;
        else if (flow.phase === 'wait') line = flow.message || 'เว็บไซต์ปิดชั่วคราวเพื่อสลับไฟล์ (หน้าแจ้งปรับปรุงระบบ รหัส 503) หน้านี้ตรวจซ้ำเองทุกไม่กี่วินาที ไม่ต้องกดซ้ำ';
        else if (flow.phase === 'done') line = flow.message || 'เสร็จแล้ว';
        else if (flow.phase === 'failed') line = flow.message || 'ไม่สำเร็จ';
        else if (S.running) line = 'มีการอัปเดตกำลังทำงานอยู่ (อีกหน้าต่างหรืองานอัตโนมัติ) หน้านี้ตรวจความคืบหน้าให้เอง';
        else if (S.last_update && S.last_update.state === 'rolled_back') line = `อัปเดตล่าสุด (${S.last_update.id || ''} ${S.last_update.from || '-'} เป็น ${S.last_update.to || ''}) ไม่สำเร็จ ระบบคืนรุ่นเดิมแล้ว${S.last_update.error ? ': ' + S.last_update.error : ''}`;
        else if (S.last_update && S.last_update.state === 'completed') line = `อัปเดตล่าสุดสำเร็จ: ${S.last_update.id || ''} ${S.last_update.from || '-'} เป็น ${S.last_update.to || ''} (${when(S.last_update.completed_at)})`;
        box.line.textContent = line;
        box.line.className = 'upd-progress-line' + (flow.phase === 'failed' ? ' upd-error-text' : '');
        box.bars.innerHTML = flow.phase === 'download' && flow.bytes ? `<label>${esc(flow.label || '')}<progress max="${flow.bytes}" value="${flow.received || 0}"></progress> <small>${esc(mb(flow.received || 0))} / ${esc(mb(flow.bytes))}</small></label>` : '';
    }

    function renderSettings() {
        const m = S.mode;
        form.querySelectorAll('input[name=mode]').forEach(r => { r.checked = r.value === m.mode; });
        form.elements.window_start.value = m.window.start; form.elements.window_end.value = m.window.end;
        const hint = form.querySelector('[data-window-hint]');
        hint.textContent = m.mode === 'auto_patch' ? (m.in_window ? 'ตอนนี้อยู่ในช่วงเวลาติดตั้งอัตโนมัติ' : 'ช่วงถัดไปเริ่ม ' + when(m.next_window)) : 'ใช้เฉพาะโหมดอัปเดตแพตช์อัตโนมัติ ค่าเริ่มต้น 02:00 ถึง 05:00';
        const host = document.querySelector('[data-feed-host]'); if (host && S.feed.host) host.textContent = S.feed.host;
        const inc = document.querySelector('[data-incoming]'); if (inc) inc.textContent = S.incoming;
    }

    function renderHistory() {
        box.history.removeAttribute('aria-busy');
        const rows = S.history || []; const label = {completed: 'สำเร็จ', rolled_back: 'คืนรุ่นเดิม', started: 'ยังไม่ปิดงาน'};
        box.history.innerHTML = rows.length ? `<table class="upd-table"><thead><tr><th scope="col">เวลา</th><th scope="col">แพ็กเกจ</th><th scope="col">รุ่น</th><th scope="col">ผล</th></tr></thead><tbody>${rows.map(h => `<tr><td data-label="เวลา">${esc(h.created_at)}</td><th scope="row" data-label="แพ็กเกจ">${esc(h.package_id)}</th><td data-label="รุ่น">${esc(h.from_version || '-')} &rarr; ${esc(h.to_version)}</td><td data-label="ผล">${esc(label[h.status] || h.status)}</td></tr>`).join('')}</tbody></table>` : '<p>ยังไม่มีประวัติการอัปเดต</p>';
        if ((S.unfinished || []).length) box.history.innerHTML += `<p class="upd-error-text">มีการอัปเดตที่ค้างอยู่ (${esc(S.unfinished[0].to || '')}) ระบบปิดงานให้เองภายในหนึ่งนาทีเมื่อตั้ง cron ไว้ หรือรัน <code>php artisan saan:update --recover</code></p>`;
    }

    function renderBackups() {
        box.backups.removeAttribute('aria-busy');
        const b = S.backups;
        box.backups.innerHTML = `<p class="account-help">ไฟล์สำรองที่ระบบทำก่อนอัปเดตแต่ละครั้ง (เก็บ ${b.keep} รุ่นล่าสุดหลังอัปเดตสำเร็จ) แต่ละไฟล์เป็นสำเนาข้อมูลของโรงเรียนทั้งชุด เก็บในที่ปลอดภัยและลบเมื่อไม่ต้องใช้แล้ว</p>${b.count ? `<p>มี ${b.count} ไฟล์ รวม ${esc(mb(b.bytes))}</p><ul class="upd-files">${b.files.map(f => `<li>${esc(f.file)} <small>${esc(mb(f.bytes))} · ${esc(when(new Date(f.modified * 1000).toISOString()))}</small></li>`).join('')}</ul><div class="upd-actions"><button type="button" data-act="purge" data-keep="${b.keep}">ลบไฟล์เก่า เก็บ ${b.keep} รุ่นล่าสุด</button><button type="button" data-act="purge" data-keep="0">ลบทั้งหมด</button></div>` : '<p>ยังไม่มีไฟล์สำรองก่อนอัปเดต</p>'}<p class="upd-error" data-backup-error role="alert" hidden></p>`;
    }

    function renderManual() {
        box.manual.removeAttribute('aria-busy');
        if (!manual) { box.manual.innerHTML = '<p>ตรวจแพ็กเกจไม่สำเร็จ</p>'; return; }
        box.manual.innerHTML = manual.length ? manual.map(p => `<div class="upd-pkg"><p><span class="upd-badge ${p.valid && !p.problems.length ? 'on' : 'warn'}">${p.valid ? (p.problems.length ? 'ยังติดตั้งไม่ได้' : 'พร้อมติดตั้ง') : 'ไม่ผ่านการตรวจ'}</span> <b>${esc(p.file)}</b> <small>${esc(mb(p.bytes))}</small></p><p class="upd-note">${p.valid ? esc(p.type + ' ' + p.id + ' ' + (p.current_version || '-') + ' → ' + p.version) : esc(p.error)}</p>${p.valid ? p.problems.map(x => `<p class="upd-error-text">${esc(x)}</p>`).join('') : ''}${p.valid && !p.problems.length ? `<button type="button" data-act="manual" data-file="${esc(p.file)}" data-id="${esc(p.id)}" data-to="${esc(p.version)}" ${busy || S.running ? 'disabled' : ''}>ติดตั้งไฟล์นี้</button>` : ''}</div>`).join('') : '<p>ยังไม่มีไฟล์แพ็กเกจรอติดตั้งในโฟลเดอร์นี้</p>';
    }

    function renderAll() { renderInstalled(); renderNew(); renderSteps(); renderSettings(); renderHistory(); renderBackups(); renderManual(); }

    // ---------------------------------------------------------------- loading and polling
    async function load() {
        try { S = await api('/api/site/updates/self'); renderAll(); }
        catch (e) { if (!S) { box.installed.innerHTML = `<p class="upd-error">${esc(e.message)}</p>`; } else { box.line.textContent = e.status === 503 ? 'เว็บไซต์ปิดชั่วคราวเพื่ออัปเดต กำลังรอ…' : e.message; } throw e; }
        schedulePoll();
    }
    async function loadManual() {
        try { manual = (await api('/api/site/updates')).packages; } catch { manual = null; }
        if (S) renderManual();
    }
    function schedulePoll() {
        clearTimeout(pollTimer);
        if (busy) return;
        if (S && S.running) pollTimer = setTimeout(() => load().catch(() => schedulePoll()), 3000);
    }

    // ---------------------------------------------------------------- install one package (existing apply endpoint)
    function logLines(lines) { if (!lines || !lines.length) return; box.log.hidden = false; box.log.textContent += (box.log.textContent ? '\n' : '') + lines.join('\n'); box.log.scrollTop = box.log.scrollHeight; }

    /** Waits until the site answers again and the update folder says what happened to this package. */
    async function waitSettled(pkg) {
        flow.phase = 'wait'; flow.message = ''; renderSteps();
        const t0 = Date.now(); let unsettled = 0;
        while (Date.now() - t0 < 45 * 60000) {
            await sleep(3000);
            try { S = await api('/api/site/updates/self'); } catch (e) { flow.message = e.status === 503 || !e.status ? 'เว็บไซต์ปิดชั่วคราวเพื่อสลับไฟล์ (หน้าแจ้งปรับปรุงระบบ รหัส 503) หน้านี้รอและตรวจซ้ำให้เอง' : e.message; renderSteps(); continue; }
            renderSteps();
            if (S.running) { flow.message = 'ระบบกำลังสลับไฟล์และปรับฐานข้อมูล รอสักครู่'; continue; }
            const lu = S.last_update;
            if (lu && lu.id === pkg.id && lu.to === pkg.to) {
                if (lu.state === 'completed') return {ok: true};
                if (lu.state === 'rolled_back') return {ok: false, message: 'ติดตั้ง ' + pkg.id + ' ' + pkg.to + ' ไม่สำเร็จ ระบบคืนรุ่นเดิมแล้ว' + (lu.error ? ': ' + lu.error : '')};
                if (++unsettled > 25) return {ok: false, message: 'การอัปเดตยังไม่ปิดงาน ระบบจะปิดงานให้เองภายในหนึ่งนาทีเมื่อตั้ง cron ไว้ (หรือรัน php artisan saan:update --recover) แล้วโหลดหน้านี้ใหม่'};
            } else if (++unsettled > 25) return {ok: false, message: 'ไม่พบผลของการติดตั้ง ' + pkg.id + ' ' + pkg.to + ' โหลดหน้านี้ใหม่เพื่อดูประวัติ'};
        }
        return {ok: false, message: 'รอนานเกินไป โหลดหน้านี้ใหม่เพื่อดูผล'};
    }

    async function installOne(pkg, password) {
        try {
            const r = await api('/api/site/updates/apply', {method: 'POST', body: {file: pkg.zip, current_password: password}});
            logLines(r.log);
            return {ok: true, to: r.to};
        } catch (e) {
            if (isPasswordError(e)) return {ok: false, password: true, message: e.message};
            if (e.status === 422 || e.status === 409) { logLines(e.data && e.data.log); return {ok: false, message: e.message}; }
            // the web server or a proxy cut the request (the update itself keeps running on the server): follow it by polling
            return waitSettled(pkg);
        }
    }

    // ---------------------------------------------------------------- the main run: download everything, then install in order
    async function runAll() {
        const plan = S.plan; if (!plan.ready) return;
        let r = await ask({title: 'ดาวน์โหลดและติดตั้ง', text: 'ระบบจะดาวน์โหลด ' + plan.packages.length + ' แพ็กเกจ (' + mb(plan.download_bytes) + ') ตรวจลายเซ็น สำรองข้อมูล แล้วติดตั้งตามลำดับ ระหว่างสลับไฟล์เว็บไซต์จะปิดชั่วคราว ยืนยันด้วยรหัสผ่านของคุณ', okLabel: 'ดาวน์โหลดและติดตั้ง'});
        while (r) {
            busy = true; box.log.textContent = ''; box.log.hidden = true; flow = {phase: 'download', index: 1, total: plan.packages.length};
            const out = await runWith(plan, r.password);
            if (out && out.password) { busy = false; flow = {phase: 'idle'}; r = await ask({title: 'ดาวน์โหลดและติดตั้ง', text: 'รหัสผ่านไม่ถูกต้อง กรอกใหม่อีกครั้ง', okLabel: 'ดาวน์โหลดและติดตั้ง', error: out.message}); continue; }
            break;
        }
        busy = false; schedulePoll();
        try { await load(); } catch { /* the site may still be closing: the poll goes on */ }
        loadManual();
    }

    async function runWith(plan, password) {
        try {
            for (let i = 0; i < plan.packages.length; i++) {
                const p = plan.packages[i]; flow = {phase: 'download', index: i + 1, total: plan.packages.length, label: p.name + ' ' + p.to, bytes: p.bytes, received: 0}; renderSteps();
                for (;;) {
                    let res;
                    try { res = await api('/api/site/updates/self/download', {method: 'POST', body: {package: p.key, current_password: password}}); }
                    catch (e) { if (isPasswordError(e)) return {password: true, message: e.message}; throw e; }
                    flow.received = res.received; renderSteps();
                    if (res.status === 'ready') break;
                }
            }
            S = await api('/api/site/updates/self'); // the install order now follows the real requirements of the downloaded packages
            const order = S.plan.packages;
            for (let i = 0; i < order.length; i++) {
                const p = order[i]; flow = {phase: 'install', index: i + 1, total: order.length, label: p.name + ' ' + p.to}; renderSteps();
                const r = await installOne(p, password);
                if (r.password) return r;
                if (!r.ok) { flow = {phase: 'failed', message: r.message}; renderSteps(); return null; }
                logLines(['[OK] ' + p.name + ' ' + p.to]);
            }
            flow = {phase: 'done', message: 'อัปเดตเสร็จแล้ว ทุกแพ็กเกจติดตั้งและผ่านการตรวจสุขภาพ'};
        } catch (e) {
            flow = {phase: 'failed', message: e.message + (flow.phase === 'download' ? ' (ไฟล์ที่ดาวน์โหลดไว้แล้วยังอยู่ กดปุ่มเดิมเพื่อทำต่อ)' : '')};
        }
        renderSteps();
        return null;
    }

    async function runManual(btn) {
        const pkg = {zip: btn.dataset.file, id: btn.dataset.id, to: btn.dataset.to};
        let r = await ask({title: 'ติดตั้ง ' + pkg.zip, text: 'ระหว่างติดตั้งเว็บไซต์จะปิดชั่วคราว ระบบสำรองข้อมูลก่อนและคืนรุ่นเดิมเองถ้าไม่สำเร็จ ยืนยันด้วยรหัสผ่านของคุณ', okLabel: 'ติดตั้ง'});
        while (r) {
            busy = true; box.log.textContent = ''; box.log.hidden = true; flow = {phase: 'install', index: 1, total: 1, label: pkg.zip}; renderSteps(); renderManual();
            const out = await installOne(pkg, r.password);
            if (out.password) { busy = false; flow = {phase: 'idle'}; r = await ask({title: 'ติดตั้ง ' + pkg.zip, text: 'รหัสผ่านไม่ถูกต้อง กรอกใหม่อีกครั้ง', okLabel: 'ติดตั้ง', error: out.message}); continue; }
            flow = out.ok ? {phase: 'done', message: 'ติดตั้ง ' + pkg.zip + ' เสร็จแล้ว'} : {phase: 'failed', message: out.message};
            break;
        }
        busy = false;
        try { await load(); } catch { /* still closing */ }
        loadManual();
    }

    // ---------------------------------------------------------------- events
    document.addEventListener('click', async ev => {
        const b = ev.target.closest('button[data-act]'); if (!b || b.disabled || !S) return;
        const act = b.dataset.act;
        if (act === 'check' || act === 'retry-auto') {
            b.disabled = true; showErr($('[data-new-error]'), '');
            try { S = await api('/api/site/updates/self/check', {method: 'POST', body: act === 'retry-auto' ? {retry_auto: true} : {}}); renderAll(); }
            catch (e) { showErr($('[data-new-error]'), e.message); }
            b.disabled = false; renderNew(); return;
        }
        if (act === 'install') { await runAll(); return; }
        if (act === 'manual') { await runManual(b); return; }
        if (act === 'purge') {
            const keep = +b.dataset.keep; let r = await ask({title: keep ? 'ลบไฟล์สำรองเก่า' : 'ลบไฟล์สำรองทั้งหมด', text: keep ? 'ลบไฟล์สำรองก่อนอัปเดตทั้งหมดยกเว้น ' + keep + ' รุ่นล่าสุด' : 'ลบไฟล์สำรองก่อนอัปเดตทุกไฟล์ จะเอาข้อมูลกลับจากไฟล์เหล่านี้ไม่ได้อีก', okLabel: 'ลบ'});
            while (r) {
                try { await api('/api/site/updates/backups/purge', {method: 'POST', body: {keep, current_password: r.password}}); await load(); return; }
                catch (e) { r = await ask({title: 'ลบไฟล์สำรอง', text: 'ยืนยันด้วยรหัสผ่านของคุณ', okLabel: 'ลบ', error: e.message}); }
            }
        }
    });

    form.addEventListener('submit', async ev => {
        ev.preventDefault();
        const mode = form.querySelector('input[name=mode]:checked'); const err = form.querySelector('[data-mode-error]'); showErr(err, '');
        if (!mode) { showErr(err, 'เลือกโหมดก่อน'); return; }
        let r = await ask({title: 'บันทึกการตั้งค่า', text: 'โหมด: ' + MODE_TEXT[mode.value] + (mode.value === 'auto_patch' ? ' ช่วงเวลา ' + form.elements.window_start.value + ' ถึง ' + form.elements.window_end.value : ''), okLabel: 'บันทึก'});
        while (r) {
            try { S = await api('/api/site/updates/self/mode', {method: 'PUT', body: {mode: mode.value, window_start: form.elements.window_start.value, window_end: form.elements.window_end.value, current_password: r.password}}); renderAll(); return; }
            catch (e) { r = await ask({title: 'บันทึกการตั้งค่า', text: 'ยืนยันด้วยรหัสผ่านของคุณ', okLabel: 'บันทึก', error: e.message}); }
        }
    });

    window.addEventListener('beforeunload', ev => { if (busy && flow.phase === 'download') { ev.preventDefault(); ev.returnValue = ''; } });
    load().catch(() => {}); loadManual();
})();
