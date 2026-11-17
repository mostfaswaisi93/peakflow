<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr' }}"
      class="dark">
{{--
    The operator console is ALWAYS dark, and that is not a style preference.

    An operator moves between this console and impersonated tenant sessions all
    day. The two must never be mistakable for one another at a glance, because
    the actions available here affect every customer at once and the actions
    there affect one. A permanent, obvious difference in the chrome is the
    cheapest possible guard against acting in the wrong place.
--}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title . ' · Ops' : 'Ops' }}</title>
    @include('partials.brand-meta')
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-background text-on-background antialiased">
    <header class="border-b border-outline bg-sidebar text-on-sidebar">
        <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-3">
            {{-- The header is the navy sidebar colour in every theme, so the
                 logo is the artwork made for a dark background. --}}
            <x-brand.logo size="small" tone="on-dark" class="h-8"/>
            <span aria-hidden="true" class="h-5 w-px bg-on-sidebar-muted opacity-40"></span>
            <span class="font-semibold">{{ __('وحدة التشغيل') }}</span>

            @auth('ops')
                <div class="ms-auto flex items-center gap-4">
                    <span class="text-sm text-on-sidebar-muted">{{ auth('ops')->user()?->email }}</span>
                    <form method="POST" action="{{ route('ops.logout') }}">
                        @csrf
                        <button type="submit"
                                class="rounded-lg px-3 py-1.5 text-sm text-on-sidebar-muted transition-colors duration-(--duration-fast)
                                       hover:bg-sidebar-selected hover:text-on-sidebar
                                       focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                            {{ __('تسجيل الخروج') }}
                        </button>
                    </form>
                </div>
            @endauth
        </div>
    </header>

    @if (session('ops_notice'))
        <div role="status" class="mx-auto mt-4 max-w-6xl px-6">
            <p class="rounded-lg border border-outline bg-info-subtle px-4 py-3 text-sm text-on-info-subtle">
                {{ session('ops_notice') }}
            </p>
        </div>
    @endif

    <main class="mx-auto max-w-6xl px-6 py-8">
        @yield('content')
    </main>
</body>
</html>
