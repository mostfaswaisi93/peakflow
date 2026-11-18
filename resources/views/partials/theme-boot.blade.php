{{--
    The Automatic theme's first-paint resolver - UX-004.

    Included in <head>, BEFORE @vite and the body. For every explicit theme the
    server has already written the class and data-theme onto <html>, so this
    renders nothing at all. For Automatic it inlines resources/js/theme-boot.js,
    which picks the concrete theme from the device's preferences before
    anything is painted.

    Inlined rather than loaded with a src: a request would let the browser
    paint while it waited, and the one frame painted in the wrong theme is
    exactly what this exists to prevent.

    It carries the request's CSP nonce - PF-237. `script-src` is a nonce and
    nothing else, with no `unsafe-inline`, so this tag is the reason the
    nonce mechanism exists at all: without it the resolver is silently
    blocked and the only symptom is one frame painted in the wrong theme,
    which is exactly the failure this partial was written to prevent and the
    last thing anybody would attribute to a header.
--}}
@if ($theme === \App\Support\Ui\Theme::Automatic)
    <script @cspNonce>{!! file_get_contents(resource_path('js/theme-boot.js')) !!}</script>
@endif
