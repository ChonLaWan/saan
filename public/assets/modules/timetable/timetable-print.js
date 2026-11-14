'use strict';
// timetable print page: the button calls window.print(); no inline handlers.
(() => {
    document.addEventListener('DOMContentLoaded', () => {
        const b = document.querySelector('#tt-print-btn');
        if (b) b.addEventListener('click', () => window.print());
    });
})();
