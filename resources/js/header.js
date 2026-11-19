/*
 * The header's menus and the company switcher - enhancement only.
 *
 * Inlined by layouts/app.blade.php with the request's CSP nonce. Nothing
 * depends on it: the menus are <details> and open and close without it, and
 * the company switcher has a <noscript> submit button. What it adds is what
 * a person expects of a menu and a <details> does not do on its own.
 *
 * - A click anywhere outside an open header menu closes it.
 * - Escape closes it and puts focus back on the button that opened it, so a
 *   keyboard user is not left focused inside something that disappeared.
 * - The company <select> submits when it changes. This cannot be an
 *   onchange= attribute: the CSP sends script-src-attr 'none', which refuses
 *   every inline handler.
 */
(() => {
    const openMenus = () => document.querySelectorAll('details[name="pf-header"][open]');

    document.addEventListener('click', (event) => {
        for (const menu of openMenus()) {
            if (!menu.contains(event.target)) {
                menu.open = false;
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        for (const menu of openMenus()) {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        }
    });

    for (const select of document.querySelectorAll('select[data-submit-on-change]')) {
        select.addEventListener('change', () => select.form?.requestSubmit());
    }
})();
