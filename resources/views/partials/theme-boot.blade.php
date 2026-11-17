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
--}}
@if ($theme === \App\Support\Ui\Theme::Automatic)
    <script>{!! file_get_contents(resource_path('js/theme-boot.js')) !!}</script>
@endif
