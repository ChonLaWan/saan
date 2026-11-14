'use strict';
// Generated from ui-icons.json; PHP and browser screens share these exact paths.
(() => {
    const paths=Object.freeze({"arrow-right":"M5 12h14m-6-6 6 6-6 6","arrow-left":"M19 12H5m6-6-6 6 6 6","arrow-up-right":"M7 17 17 7M7 7h10v10","arrow-down":"M12 5v14m-6-6 6 6 6-6","chevron-right":"m9 6 6 6-6 6","chevron-left":"m15 6-6 6 6 6","chevron-down":"m6 9 6 6 6-6","chevron-up":"m6 15 6-6 6 6"});
    window.SchoolIcons=Object.freeze({render(name='arrow-right'){
        const key=Object.hasOwn(paths,name)?name:'arrow-right';
        return `<svg class="ui-icon ui-icon--${key}" data-ui-icon="${key}" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="${paths[key]}"/></svg>`;
    }});
})();
