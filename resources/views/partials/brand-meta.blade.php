{{--
    The brand in the browser's own chrome: tab icon, home-screen icon, and the
    colour of the address bar - from the brand kit (public/, public/brand/).

    The SVG favicon first for every browser that takes it, the ICO (16 and 32)
    for the ones that do not, and Midnight Navy as the theme colour, the kit's
    primary.
--}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<meta name="theme-color" content="#0F1E3D">
