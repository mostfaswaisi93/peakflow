<!DOCTYPE html>
@php($theme = \App\Support\Ui\ThemePreference::forUser(request(), auth()->user()))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr' }}"
      class="{{ $theme->htmlClass() }}"@if ($theme->dataTheme() !== null) data-theme="{{ $theme->dataTheme() }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $theme->colorScheme() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title . ' · ' . config('app.name') : config('app.name') }}</title>
    @include('partials.brand-meta')
    @include('partials.theme-boot')
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-background text-on-background antialiased">
    {{-- Skip link: the first thing a keyboard user meets, and the switcher
         sits between them and the content otherwise. --}}
    <a href="#content"
       class="sr-only focus:not-sr-only focus:absolute focus:z-(--z-skip) focus:m-3 focus:rounded-lg focus:bg-surface focus:px-4 focus:py-2 focus:text-on-surface focus:outline-2 focus:outline-focus-ring">
        {{ __('تخطَّ إلى المحتوى') }}
    </a>

    @if (session('impersonated_by'))
        {{--
            The impersonation banner - PF-036.

            Deliberately loud, deliberately unmissable, and deliberately NOT
            dismissible. An operator acting inside a customer's workspace must
            never forget which they are in, and a banner that can be closed is
            a banner that is closed within the first minute.

            It names the operator, because the people whose workspace this is
            may be looking over their shoulder, and "someone from support" is
            not an answer anybody finds reassuring.
        --}}
        <div role="alert"
             class="sticky top-0 z-(--z-floating) border-b-2 border-warning bg-warning px-6 py-2 text-center text-sm font-medium text-on-warning">
            {{ __('أنت تتصفح بالنيابة عن هذه المساحة بصفة :operator. كل إجراء يُسجَّل.', [
                'operator' => session('impersonated_by'),
            ]) }}
        </div>
    @endif

    <header class="border-b border-outline bg-surface">
        <div @class(['mx-auto flex flex-wrap items-center gap-4 px-6 py-3', ($wide ?? false) ? 'max-w-7xl' : 'max-w-5xl'])>
            {{-- The small logo: 32 px high is about 172 px wide, inside the
                 kit's 120-200 px band for the small artwork. One wordmark in
                 both languages now, so there is one width to check. The link
                 is the way home from anywhere. --}}
            <a href="{{ route('overview') }}"
               class="-my-1 rounded-lg py-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                <x-brand.logo size="small" class="h-8"/>
            </a>

            @include('partials.entity-switcher')
            @include('partials.period-state')

            <div class="ms-auto flex items-center gap-4">
                @include('partials.theme-switcher')
                <span class="text-sm text-on-surface-muted">{{ auth()->user()?->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="rounded-lg px-3 py-1.5 text-sm text-on-surface-muted transition-colors duration-(--duration-fast)
                                   hover:bg-surface-hover hover:text-on-surface
                                   focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                        {{ __('تسجيل الخروج') }}
                    </button>
                </form>
            </div>
        </div>
    </header>

    {{--
        The live region for entity changes.

        Separate from the switcher and present on EVERY page, because the
        announcement has to happen on the page the user lands on after the
        switch - a region inside the control they just left would be
        re-rendered before anything read it.
    --}}
    <div role="status" aria-live="polite" class="sr-only">
        @if (session('entity_switched'))
            {{ __('تم التبديل إلى :entity', ['entity' => session('entity_switched')]) }}
        @endif
        @if (session('theme_switched'))
            {{ __('تم تغيير المظهر إلى :theme', ['theme' => session('theme_switched')]) }}
        @endif
    </div>

    {{--
        The shell: navigation beside the content, or above it - PF-204.

        `lg:flex` and nothing else. The sidebar partial renders a column
        that is `hidden` below `lg` and a disclosure that is `lg:hidden`,
        so the same markup is a row of two on a wide screen and a stack of
        two on a narrow one, with no second layout to keep in step.

        `min-w-0` on the content column is load-bearing: a flex item
        defaults to `min-width: auto`, so one wide table pushes the column
        past the viewport and takes the sidebar off screen with it. The
        symptom is a page that scrolls sideways and a navigation nobody
        can reach, which is how it was found.
    --}}
    <div class="lg:flex">
        @include('partials.sidebar')

        <div class="min-w-0 flex-1">
            {{-- `wide` is for grids that need the room (the Overview's Bento
                 grid); reading pages keep the narrower measure. --}}
            <main id="content" tabindex="-1" @class(['mx-auto px-6 py-10', ($wide ?? false) ? 'max-w-7xl' : 'max-w-5xl'])>
                @yield('content')
            </main>
        </div>
    </div>

    {{--
        The active entity, on every PRINTED page.

        A printout that does not say which company it is for is the failure
        this prevents: a trial balance for the wrong subsidiary looks exactly
        like a trial balance for the right one.
    --}}
    <footer class="hidden print:fixed print:inset-x-0 print:bottom-0 print:block print:border-t print:border-outline print:px-6 print:py-2 print:text-xs">
        {{ app('currentEntity')?->legal_name }} · {{ app('currentEntity')?->code }}
    </footer>
</body>
</html>
