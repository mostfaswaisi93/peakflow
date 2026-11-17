/*
 * Resolves the Automatic theme before the first paint - UX-004.
 *
 * Inlined into <head> by resources/views/partials/theme-boot.blade.php, ONLY
 * when the server rendered data-theme="auto". It is a blocking script ahead of
 * the stylesheet and the body, so nothing is painted in the wrong theme; every
 * explicit theme is rendered by the server and never runs this.
 *
 * Kept tiny and dependency-free on purpose: anything it waited for would be a
 * frame painted in the wrong colours.
 */
(() => {
    const root = document.documentElement;
    const dark = window.matchMedia('(prefers-color-scheme: dark)');
    const more = window.matchMedia('(prefers-contrast: more)');

    const apply = () => {
        root.classList.toggle('dark', dark.matches);
        // `prefers-contrast: more` picks the high-contrast theme of the same
        // lightness - someone who asked their device for more contrast should
        // not have to find a setting to get it.
        root.dataset.theme = more.matches ? (dark.matches ? 'hc-dark' : 'hc-light') : 'auto';
    };

    apply();

    // Follow the device if it changes while the page is open - a laptop that
    // switches to dark at sunset should not leave this tab behind.
    dark.addEventListener('change', apply);
    more.addEventListener('change', apply);
})();
