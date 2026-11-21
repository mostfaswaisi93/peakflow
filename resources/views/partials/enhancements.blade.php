{{--
    The progressive enhancements, inlined once per page - PF-206, PF-212.

    Included by <x-grid> and by the form components; it dedupes ITSELF, with
    a named `@once`, so a page carrying four grids and a form carries one
    copy.

    The id is named rather than left to Blade. An `@once` with no id gets a
    random one per compiled occurrence, so the grid's `@once` and the
    currency field's `@once` were two different ids - and a page with a grid
    and an amount on it inlined the whole file twice, with a duplicate
    element id, which is how this was found.

    ## Inlined rather than loaded through the asset pipeline

    `@vite()` on a missing manifest entry throws, which turns a forgotten
    build into a 500 on a page whose only loss should have been the arrow
    keys. An enhancement that can take the page down with it is not an
    enhancement. The precedent is partials/theme-boot.blade.php, which
    inlines for a different reason - the first paint - and the cost here is
    the same: one copy of the file per response, uncached.

    ## The strings are rendered, not written in the script

    A literal in a .js file is a string that never reaches the translator,
    and in this product that means an English sentence appearing in an
    Arabic interface with nothing to report it. So every sentence the
    keyboard layer can say is rendered here, through `__()`, and passed in.

    They are COUNT labels - "عدد الخلايا المحدَّدة: 12" - rather than
    counted sentences, because Arabic agreement depends on the last two
    digits of the number and ICU is what resolves that. ICU runs on the
    server; a script announcing "12 خلية" would have to reimplement the
    rule in the browser, which is how a plural comes to be wrong only
    above 100.

    The digits stay Western, which is PF-202's default: the shape is a
    tenant display setting and anything submitted, exported or copied is
    Western regardless.
--}}
@php
    $pfUiStrings = [
        'selected' => __('عدد الخلايا المحدَّدة: :count'),
        'copied' => __('عدد الخلايا المنسوخة: :count'),
        'willChange' => __('عدد الخلايا التي ستتغير: :count'),
        'skipped' => __('عدد الخلايا غير القابلة للتعديل: :count'),
        'pasted' => __('عدد الخلايا التي تغيّرت: :count'),
        'reverted' => __('أُعيد التعديل الأخير'),
    ];

    /*
     | JSON_HEX_TAG is what keeps a catalog line from closing the script.
     |
     | The strings come from this repository's own catalogs today, and the
     | day one of them comes from a tenant's settings is the day the
     | absence of this flag becomes an injected script. Escaping it here
     | costs nothing and does not depend on remembering.
     */
    $pfUiBehaviour = 'window.pfUiStrings = '
        .json_encode($pfUiStrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        .";\n"
        .file_get_contents(resource_path('js/enhancements.js'));
@endphp

@once('pf-ui-behaviour')
    {{--
        `@cspNonce`, and it was missing - PF-237, PF-206.

        `script-src` is a nonce and nothing else: no `'self'`, no
        `'unsafe-inline'`, no hashes. So this script, inlined without one,
        was REFUSED BY THE BROWSER on every page that rendered a grid or a
        currency field - which is every list in the product.

        Nothing reported it. The markup was correct, so `bin/verify grid`
        compared the markup it reads and found the hooks it expected; the
        suites that exercise the keyboard model inspect the source of this
        file rather than running it; and a page whose arrow keys do nothing
        still renders, still scrolls and still submits. The only symptom was
        a keyboard model that existed in the repository and not in a
        browser.

        Measured after the fix, in Chromium against the showcase workspace:
        with the nonce absent `window.pfUi` is undefined and ArrowRight on a
        cell moves nothing; with it present the roving focus, type-to-edit,
        Enter and Tab all behave as PF-206 describes.
    --}}
    <script id="pf-ui-behaviour" @cspNonce>{!! $pfUiBehaviour !!}</script>
@endonce
