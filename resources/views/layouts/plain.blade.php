<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr' }}"
      class="{{ session('theme') === 'dark' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">

    {{--
        This layout needs no TENANT.

        It is used by the pages that explain why someone cannot proceed,
        including the one shown after a tenant could not be resolved - so
        anything here that required a resolved tenant would fail to render the
        explanation of why resolution failed, and the user would get a redirect
        loop instead of a sentence.

        It does read `session('theme')`, which is safe: StartSession has
        already run by the time a view renders, and the session store is the
        platform connection rather than the tenant's. There is no form and no
        CSRF token, because nothing on these pages is submitted.
    --}}
    <title>{{ $title ?? config('app.name') }}</title>

    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-background text-on-background antialiased">
    <main class="mx-auto flex min-h-dvh max-w-xl flex-col justify-center px-6 py-16">
        {{ $slot ?? '' }}
        @yield('content')
    </main>
</body>
</html>
