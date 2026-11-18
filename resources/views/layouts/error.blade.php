<!DOCTYPE html>
{{--
    The layout for the pages shown when something has already failed - PF-015.

    ## Why this is not `layouts.plain`

    `plain` needs no tenant, which is most of the way there, and it reads
    `session('theme')` and pulls the compiled CSS through `@vite`. Both are
    fine on a page that is explaining a *business* refusal and neither is safe
    on a 500 or a 503:

    - The session store is the PLATFORM database. If that is what broke, the
      session middleware has already failed before any view renders, so
      `session()` throws inside the handler that was rendering the explanation
      - and Laravel falls back to a bare "Server Error" string with no way
      back.
    - `@vite` reads the build manifest. A deploy that failed between pushing
      code and building assets is exactly the kind of incident that produces a
      500, and an error page that throws because the manifest is missing is an
      error page that does not exist when it is needed.

    So this layout touches nothing: no session, no database, no auth, no build
    artifact. The CSS is inline and small, the dark variant comes from
    `prefers-color-scheme` rather than from a stored preference, and the
    direction comes from the locale the request already resolved.

    ## It is deliberately plain

    An error page is read by somebody who is already annoyed. The design goal
    is that the sentence is legible and the way back is obvious, in that order.
--}}
{{--
    The locale and direction are computed here rather than passed in, so the
    five pages cannot disagree. Both read the vendored language registry and
    the resolved locale; neither touches a database.
--}}
@php($locale = app()->getLocale())
<html lang="{{ $locale }}" dir="{{ \App\Support\Iso\LanguageRegistry::direction($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }}</title>
    {{-- Static files only - no manifest read, no database - so the icon and
         logo survive the failures this page explains. --}}
    @include('partials.brand-meta')
    {{-- The nonce, because `style-src` is `self` plus a nonce and this page
         cannot use the compiled stylesheet: the failures it explains include
         the build manifest being unreadable. --}}
    <style @cspNonce>
        /*
         | The brand palette as plain hex, because this page cannot rely on the
         | compiled stylesheet. The values are the same roles app.css gives the
         | light and dark themes: Mist and Midnight Navy, the deep brand teal for
         | links on light, Glacier Teal on dark.
         */
        :root {
            color-scheme: light dark;
            --bg: #F3F6FA;
            --fg: #0F1E3D;
            --muted: #525A65;
            --line: #D8DDE3;
            --accent: #01665D;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #04070B;
                --fg: #EBEFF3;
                --muted: #BFC5CD;
                --line: #242B33;
                --accent: #2DD4BF;
            }
        }

        .brand { display: block; block-size: 2rem; inline-size: auto; margin-block-end: 2.5rem; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--bg);
            color: var(--fg);
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
            line-height: 1.65;
        }

        main { max-width: 34rem; inline-size: 100%; }

        .code {
            font-size: 0.8125rem;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-block-end: 0.5rem;
        }

        h1 {
            font-size: 1.375rem;
            font-weight: 600;
            margin: 0 0 0.75rem;
        }

        p { margin: 0 0 1rem; color: var(--muted); }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-block-start: 1.5rem;
        }

        a {
            display: inline-block;
            padding: 0.5rem 1rem;
            border: 1px solid var(--line);
            border-radius: 0.5rem;
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }

        a:hover { border-color: var(--accent); }

        a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

        .reference {
            margin-block-start: 2rem;
            padding-block-start: 1rem;
            border-block-start: 1px solid var(--line);
            font-size: 0.8125rem;
            color: var(--muted);
        }

        .reference code {
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
            direction: ltr;
            unicode-bidi: isolate;
        }
    </style>
</head>
<body>
<main>
    {{-- The small logo, light or dark by the system preference this page
         follows; the Arabic artwork for an Arabic page. --}}
    {{-- One wordmark in every language - see <x-brand.logo> for why. --}}
    @php($brandBase = 'logo-small')
    <picture>
        <source srcset="{{ asset('brand/'.$brandBase.'-color-on-dark.svg') }}" media="(prefers-color-scheme: dark)">
        <img class="brand" src="{{ asset('brand/'.$brandBase.'-color-on-light.svg') }}" alt="{{ __('بيك فلو') }}">
    </picture>

    <p class="code">{{ $status }}</p>
    <h1>{{ $title }}</h1>
    <p>{{ $body }}</p>

    <div class="actions">
        @yield('actions')
    </div>

@php($correlationId = \App\Support\Telemetry\CorrelationId::current())

    @if ($correlationId !== null && $correlationId !== '')
        {{--
            The correlation id, isolated.

            It is the only thing that connects what the user saw to what the
            logs recorded, and it is ASCII inside a right-to-left paragraph -
            so it is isolated, or the digits reorder around the Arabic and the
            value a user reads out over the phone is not the value we logged.
        --}}
        <p class="reference">
            {{ __('مرجع الحادثة') }} <code>{{ $correlationId }}</code>
        </p>
    @endif
</main>
</body>
</html>
