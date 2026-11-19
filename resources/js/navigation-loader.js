/*
 | The loader, shown while the browser is fetching the next page - PF-279.
 |
 | ## Why a script at all
 |
 | The browser's own progress is a thread in the tab bar on a desktop and
 | nothing at all on a phone. Between a click and the next paint the product
 | looks frozen, and the longer the page takes the more it looks broken
 | rather than slow.
 |
 | ## It does not decide when to appear
 |
 | This only takes the `hidden` off. The 400ms wait is a CSS animation delay,
 | so a page that arrives in 80ms shows nothing: the element is revealed,
 | the delay has not elapsed, the document is replaced, and nobody sees a
 | flash. Putting the delay in a `setTimeout` here would mean a timer racing
 | a navigation, and the loser is sometimes the timer firing into a page
 | that is already gone.
 |
 | ## What counts as a navigation
 |
 | An ordinary link to our own pages, and a form that is not opening a new
 | tab. Downloads do not replace the page, so a loader shown for one stays
 | on screen until the person navigates away - which is why a link that
 | downloads is left out rather than guessed at.
 */
(() => {
    const loader = document.getElementById('pf-navigation-loader');

    if (loader === null) {
        return;
    }

    const show = () => loader.removeAttribute('hidden');
    const hide = () => loader.setAttribute('hidden', '');

    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;

        if (link === null || event.defaultPrevented) {
            return;
        }

        // A modified click is the person asking for a second tab, and this
        // page is staying where it is.
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
            return;
        }

        if (link.target === '_blank' || link.hasAttribute('download')) {
            return;
        }

        const href = link.getAttribute('href') ?? '';

        // A fragment moves within this page; a scheme we do not own leaves
        // the product entirely and may not even replace the tab.
        if (href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:')) {
            return;
        }

        if (link.origin !== window.location.origin) {
            return;
        }

        show();
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
            return;
        }

        if (form.target === '_blank' || form.hasAttribute('data-pf-no-loader')) {
            return;
        }

        show();
    });

    /*
     | Back from the cache is a page that is already here.
     |
     | `pageshow` with `persisted` fires when the browser restores a page it
     | kept, and it restores the DOM as it was - loader visible, because it
     | was visible when the page was left. Hiding on every pageshow rather
     | than only the persisted one also covers the first paint.
     */
    window.addEventListener('pageshow', hide);
})();
