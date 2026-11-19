<!DOCTYPE html>
@php($theme = \App\Support\Ui\ThemePreference::forGuest(request()))
{{-- The density from the cookie only, for the same reason as the theme: this
     layout must not touch the user, and a page explaining that a workspace
     cannot be reached still opens at the density this browser was last at. --}}
@php($density = \App\Support\Ui\DensityPreference::forGuest(request()))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr' }}"
      class="{{ $theme->htmlClass() }}"
      data-density="{{ $density->value }}"@if ($theme->dataTheme() !== null) data-theme="{{ $theme->dataTheme() }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $theme->colorScheme() }}">

    {{--
        This layout needs no TENANT.

        It is used by the pages that explain why someone cannot proceed,
        including the one shown after a tenant could not be resolved - so
        anything here that required a resolved tenant would fail to render the
        explanation of why resolution failed, and the user would get a redirect
        loop instead of a sentence.

        Which is also why the theme comes from the `theme` COOKIE only
        (ThemePreference::forGuest) and never from the signed-in user: asking
        the guard for the user queries the tenant database, which on the
        tenant-could-not-be-resolved page does not exist. There is no form and
        no CSRF token, because nothing on these pages is submitted.
    --}}
    <title>{{ $title ?? config('app.name') }}</title>

    @include('partials.brand-meta')
    @include('partials.theme-boot')
    @vite(['resources/css/app.css'])
</head>
{{-- `aurora` is the sign-in page's backdrop (UX-031): static light sources
     that follow the theme, and a plain page in the high-contrast themes.

     `brand` (default on) puts the logo above the page. Off where the page
     carries it itself (sign-in), and off on a customer's document, which is
     the ISSUER'S document - the platform signs it in a footer, not a header. --}}
<body @class(['min-h-dvh bg-background text-on-background antialiased', 'aurora' => $aurora ?? false])>
    <main class="mx-auto flex min-h-dvh max-w-xl flex-col justify-center px-6 py-16">
        @if ($brand ?? true)
            <x-brand.logo size="small" class="mb-10 h-8 self-center"/>
        @endif
        {{ $slot ?? '' }}
        @yield('content')
    </main>
</body>
</html>
