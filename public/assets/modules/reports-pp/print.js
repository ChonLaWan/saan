'use strict';
// reports-pp print pages: the toolbar button opens the browser print dialog (no inline handlers).
document.addEventListener('click', ev => {
    if (ev.target.closest('[data-print]')) window.print();
});
