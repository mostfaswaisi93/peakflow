<!DOCTYPE html>
@php($theme = \App\Support\Ui\ThemePreference::forUser(request(), auth()->user()))
@php($density = \App\Support\Ui\DensityPreference::forUser(request(), auth()->user()))
{{--
    The density is on <html> as the SERVER writes it - PF-203.

    Not applied by a script on load: the density decides the row height, the
    cell padding and the body font step, so a page that is painted at one
    density and corrected at another is a table that resizes while somebody is
    already reading down a column. The attribute is in the element the
    stylesheet is matched against before the first paint, and nothing has to
    run for that to be true.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr' }}"
      class="{{ $theme->htmlClass() }}"
      data-density="{{ $density->value }}"@if ($theme->dataTheme() !== null) data-theme="{{ $theme->dataTheme() }}"@endif>
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

    {{--
        The banner and the header stick as ONE block.

        Two separate sticky elements at `top-0` slide over each other the
        moment the page scrolls, and the one underneath is the impersonation
        banner - the one thing on the page that must never be covered.
    --}}
    <div class="sticky top-0 z-(--z-sticky) print:static">
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
                 class="border-b-2 border-warning bg-warning px-6 py-2 text-center text-sm font-medium text-on-warning">
                {{ __('أنت تتصفح بالنيابة عن هذه المساحة بصفة :operator. كل إجراء يُسجَّل.', [
                    'operator' => session('impersonated_by'),
                ]) }}
            </div>
        @endif

        {{--
            The header - one bar at every width.

            A grid of three named areas, so each part is written ONCE and
            only moves:

            - below `md`  start | . | end, then the context on its own row
            - from `md`   start | context | end, all on one row

            The context (the active company and today's period) moves to a
            row of its own on a phone rather than into a menu. It is there to
            stop a document being posted into the wrong company, and a fact
            behind a tap is a fact nobody checks.

            Full width rather than the content's measure: it sits over the
            sidebar too, and from `lg` the start area is the sidebar's width,
            so the company starts where the page does.
        --}}
        {{-- One-line php directives only in this file, never the block form:
             Blade compiles a php block from the FIRST php directive in the file
             to the block's end, so a block here swallows the theme and density
             lines at the top - and it does so before comments are removed, so
             not even a comment may spell the directives out. --}}
        @php($activeEntity = app()->bound('currentEntity') ? app('currentEntity') : null)
        @php($userName = (string) (auth()->user()?->name ?? ''))
        @php($userEmail = (string) (auth()->user()?->email ?? ''))
        @php($initials = collect(preg_split('/\s+/u', trim($userName)) ?: [])->filter()->take(2)->map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))->implode(''))

        <header class="relative grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-2 border-b border-outline bg-surface px-4
                       [grid-template-areas:'start_._end'_'context_context_context']
                       sm:gap-x-3 sm:px-6
                       md:[grid-template-areas:'start_context_end']
                       lg:ps-4 lg:pe-6">
            <div class="flex h-16 items-center gap-1 [grid-area:start] lg:w-60">
                @include('partials.sidebar', ['part' => 'disclosure'])

                {{-- The way home from anywhere. The kit's own sizes: the
                     monogram under 120 px, the small logo (32 px high, about
                     172 px wide) inside its 120-200 px band.

                     The small logo where there is room for it: on `sm`, where
                     the company has a row of its own, and from `lg`, where the
                     start area is the sidebar's width. Between `md` and `lg`
                     the company shares the one row, and the 140 px the
                     monogram saves are what keep its name whole. --}}
                <a href="{{ route('overview') }}"
                   class="flex items-center rounded-button p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    <span class="sm:hidden md:block lg:hidden"><x-brand.logo size="monogram" class="h-8"/></span>
                    <span class="hidden sm:block md:hidden lg:block"><x-brand.logo size="small" class="h-8"/></span>
                </a>
            </div>

            {{-- Only where there is a company. A page that belongs to the
                 person - their security settings - has none, and an empty
                 context row is a bordered strip of nothing under the bar. --}}
            @if ($activeEntity !== null)
                <div class="-mx-4 flex min-w-0 items-center gap-2 overflow-x-auto border-t border-outline-subtle px-4 py-2 [grid-area:context]
                            sm:-mx-6 sm:px-6
                            md:mx-0 md:overflow-visible md:border-0 md:px-0 md:py-0">
                    @include('partials.entity-switcher')
                    @include('partials.period-state')
                </div>
            @endif

            <div class="flex h-16 items-center gap-1 [grid-area:end] sm:gap-2">
                @include('partials.density-switcher')
                @include('partials.theme-switcher')

                <span aria-hidden="true" class="mx-1 hidden h-6 w-px bg-outline sm:block"></span>

                {{--
                    The account menu: who is signed in, and the way out.

                    Initials rather than a photograph, because there is no
                    photograph - and a generic silhouette tells two people
                    sharing a screen nothing. The name is words from `lg` up
                    and a screen reader's at every width.
                --}}
                <details name="pf-header" class="group relative">
                    <summary title="{{ $userName }}"
                             class="flex h-10 list-none items-center gap-2 rounded-full p-1 text-on-surface
                                    transition-colors duration-(--duration-fast) ease-(--ease-standard)
                                    hover:bg-surface-hover group-open:bg-surface-selected
                                    focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring
                                    lg:pe-3 [&::-webkit-details-marker]:hidden">
                        <span aria-hidden="true"
                              class="grid size-8 shrink-0 place-items-center rounded-full bg-primary text-caption font-semibold text-on-primary">{{ $initials }}</span>
                        <span class="sr-only lg:not-sr-only lg:max-w-40 lg:truncate lg:text-label lg:font-medium">{{ $userName }}</span>
                        <x-icon name="chevron-down"
                                class="hidden size-4 text-on-surface-muted transition-transform duration-(--duration-fast) ease-(--ease-standard) group-open:rotate-180 lg:block"/>
                    </summary>

                    <div class="absolute end-0 z-(--z-popover) mt-2 w-72 rounded-card border border-outline bg-surface-container-high p-2 shadow-e3">
                        <div class="flex items-center gap-3 px-3 py-2">
                            <span aria-hidden="true"
                                  class="grid size-10 shrink-0 place-items-center rounded-full bg-primary text-label font-semibold text-on-primary">{{ $initials }}</span>
                            <div class="min-w-0">
                                <p class="truncate text-body font-medium text-on-surface">{{ $userName }}</p>
                                @if ($userEmail !== '')
                                    <p class="truncate text-caption text-on-surface-muted">{{ $userEmail }}</p>
                                @endif
                            </div>
                        </div>

                        <div aria-hidden="true" class="my-1 h-px bg-outline-subtle"></div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-body text-on-surface
                                           transition-colors duration-(--duration-fast) ease-(--ease-standard)
                                           hover:bg-surface-hover
                                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                <x-icon name="logout" class="size-5 text-on-surface-muted rtl:-scale-x-100"/>
                                {{ __('تسجيل الخروج') }}
                            </button>
                        </form>
                    </div>
                </details>
            </div>
        </header>
    </div>

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
        @if (session('density_switched'))
            {{ __('تم تغيير الكثافة إلى :density', ['density' => session('density_switched')]) }}
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
        @include('partials.sidebar', ['part' => 'column'])

        <div class="min-w-0 flex-1">
            {{-- `wide` is for grids that need the room (the Overview's Bento
                 grid); reading pages keep the narrower measure. --}}
            <main id="content" tabindex="-1" @class(['mx-auto px-6 py-10', ($wide ?? false) ? 'max-w-7xl' : 'max-w-5xl'])>
                @include('partials.breadcrumbs')
                @yield('content')
            </main>
        </div>
    </div>

    {{--
        The active entity, on every PRINTED page.

        A printout that does not say which company it is for is the failure
        this prevents: a trial balance for the wrong subsidiary looks exactly
        like a trial balance for the right one.

        Asked with `bound()` first, as every partial in the header does. A page
        that belongs to the PERSON rather than to a company - their security
        settings - is served with no entity in the container, and an unguarded
        `app('currentEntity')` there is a 500 instead of a page.
    --}}
    @if ($activeEntity !== null)
        <footer class="hidden print:fixed print:inset-x-0 print:bottom-0 print:block print:border-t print:border-outline print:px-6 print:py-2 print:text-xs">
            {{ $activeEntity->legal_name }} · {{ $activeEntity->code }}
        </footer>
    @endif

    {{-- The loader, in the DOM and hidden, so that showing it is one
         attribute rather than a render while the page is being replaced.
         `aria-live="polite"` on the wrapper and not on the component: the
         component already carries `role="status"`, and a live region inside
         a live region is announced twice. --}}
    <div id="pf-navigation-loader" hidden
         class="fixed inset-0 z-(--z-toast) flex items-center justify-center bg-surface">
        <x-brand.loader :label="__('جارٍ الانتقال')" />
    </div>

    {{-- The header's menus close on Escape and on a click outside, and the
         company switcher submits on change - js/header.js. Last, so the
         elements it binds to exist; nothing waits for it. --}}
    <script @cspNonce>{!! file_get_contents(resource_path('js/header.js')) !!}</script>

    {{-- And the loader is revealed on a navigation - js/navigation-loader.js.
         The 400ms wait is in the stylesheet, not here, so a fast page never
         shows one and no timer races a navigation. --}}
    <script @cspNonce>{!! file_get_contents(resource_path('js/navigation-loader.js')) !!}</script>
</body>
</html>
