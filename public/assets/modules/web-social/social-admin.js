'use strict';
// web-social module: CMS screen #social (Facebook page block for the homepage).
(() => {
    window.SaanModulePages = (window.SaanModulePages || []).concat(['social']);
    window.SaanHomeSections = Object.assign(window.SaanHomeSections || {}, {'module:web-social': 'ข่าวจากเพจ Facebook'});
    const previous = window.CmsComplete?.render; if (!previous) return;
    const esc = s => window.SaanCore.esc(s);
    async function page(root, route) {
        let state = await request('/api/social'); if (location.hash !== route) return;
        root.innerHTML = `<div class="cms-heading"><div><h1>ข่าวจากเพจ Facebook</h1><p>แสดงโพสต์ล่าสุดของเพจโรงเรียนบนหน้าแรก ไม่ต้องใช้ access token ผู้ชมกดเพื่อโหลดเอง</p></div></div>
            <p id="social-error" class="desk-error" role="alert"></p><p id="social-status" role="status"></p>
            <form id="social-form" class="desk-editor-card glass"><label class="release-check"><input type="checkbox" name="enabled" ${state.enabled ? 'checked' : ''}><span>เปิดบล็อก Facebook</span></label>
            <label class="desk-field"><span>ลิงก์เพจ Facebook</span><input name="facebook_url" type="url" value="${esc(state.facebook_url)}" maxlength="300" placeholder="https://www.facebook.com/ชื่อเพจของโรงเรียน"></label>
            <label class="desk-field"><span>หัวข้อบล็อก</span><input name="heading" value="${esc(state.heading)}" maxlength="120" required></label>
            <label class="desk-field"><span>ความสูง (พิกเซล)</span><input name="height" type="number" min="300" max="1200" value="${Number(state.height) || 640}" required></label>
            <p class="desk-help">หลังบันทึก ให้เปิด <a href="#homepage">จัดหน้าแรก</a> แล้วเปิดบล็อก "ข่าวจากเพจ Facebook" และเผยแพร่ ไม่ใส่ access token หรือรหัสผ่านใดๆ ในหน้านี้</p>
            <button class="primary" type="submit">บันทึก</button></form>`;
        const form = root.querySelector('#social-form');
        form.onsubmit = async e => { e.preventDefault(); if (!form.reportValidity()) return; root.querySelector('#social-error').textContent = '';
            try { state = await request('/api/social', 'PUT', {version: state.version, enabled: form.elements.enabled.checked, facebook_url: form.elements.facebook_url.value.trim(), heading: form.elements.heading.value.trim(), height: Number(form.elements.height.value)}); if (location.hash !== route) return; root.querySelector('#social-status').textContent = 'บันทึกแล้ว'; }
            catch (err) { root.querySelector('#social-error').textContent = err.message; } };
    }
    window.CmsComplete.render = async parts => {
        if (parts[0] === 'social') { window.CmsComplete.leave(); const root = document.querySelector('#cms-view'), route = location.hash; root.innerHTML = '<div class="loading glass">กำลังอ่านการตั้งค่า…</div>';
            try { return await page(root, route); } catch (err) { if (location.hash === route) root.innerHTML = `<div class="desk-editor-card glass"><p class="desk-error" role="alert">${esc(err.message)}</p></div>`; } return; }
        return previous(parts);
    };
})();
