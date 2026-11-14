'use strict';
// F5a map block: the provider iframe is created only after the visitor presses "โหลดแผนที่". Without JS the block keeps its plain "เปิดในแผนที่" link.
(() => {
    const ALLOWED = ['https://www.google.com/maps', 'https://www.openstreetmap.org/export/embed.html'];
    document.querySelectorAll('[data-map-block]').forEach(block => {
        const button = block.querySelector('[data-map-load]'), stage = block.querySelector('[data-map-stage]');
        if (!button || !stage) return;
        let url;
        try { url = new URL(block.dataset.mapEmbed || '', location.href); } catch (e) { return; }
        const base = url.origin + url.pathname;
        if (url.protocol !== 'https:' || !ALLOWED.includes(base)) return; // never an address that the server did not validate
        button.hidden = false;
        button.addEventListener('click', () => {
            const frame = document.createElement('iframe');
            frame.src = url.href;
            frame.title = 'แผนที่โรงเรียน (' + (block.dataset.mapProvider || 'แผนที่') + ')';
            frame.loading = 'lazy';
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            frame.setAttribute('tabindex', '-1');
            stage.classList.add('is-loaded');
            button.remove();
            stage.insertBefore(frame, stage.firstChild);
            frame.focus();
        });
    });
})();