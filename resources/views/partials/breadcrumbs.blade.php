{{--
    Where you are, derived rather than declared - PF-204.

    ## Why the pages do not write their own

    A breadcrumb written per page is a third list of the product's shape,
    after the routes and the menu, and it is the one nobody notices has
    drifted: the page still works, it just claims to live somewhere it
    does not. So the trail is a function of the current route name and
    `Navigation`, which means a page moved to another section gets a
    correct trail with no edit, and a page that is in no section at all
    gets no trail - which is the right answer, and visible.

    ## One link, not three

    The trail is Home / Section / Page, and only Home is a link. The
    section is a heading in the menu beside it, not a page of its own, and
    a breadcrumb offering a link that goes nowhere teaches people that
    breadcrumbs are decoration.
--}}
@php
    $current = \Illuminate\Support\Facades\Route::currentRouteName();
    $trail = null;

    foreach (\App\Support\Ui\Navigation::sections() as $section) {
        foreach ($section['items'] as $item) {
            if ($item['route'] !== null && \App\Support\Ui\Navigation::isCurrent($item['route'], $current)) {
                $trail = ['section' => $section, 'item' => $item];

                break 2;
            }
        }
    }
@endphp

@if ($trail !== null && $trail['item']['route'] !== 'overview')
    <nav aria-label="{{ __('مسار التنقل') }}" class="mb-4">
        <ol role="list" class="flex flex-wrap items-center gap-1.5 text-caption text-on-surface-muted">
            <li>
                <a href="{{ route('overview') }}"
                   class="rounded-(--radius-field) underline decoration-outline-strong underline-offset-2
                          hover:text-on-surface hover:decoration-current
                          focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    {{ __('نظرة عامة') }}
                </a>
            </li>

            {{-- The separator is generated, so it is never read aloud. --}}
            <li aria-hidden="true" class="text-on-surface-subtle">/</li>

            <li>{{ __($trail['section']['label_ar']) }}</li>

            <li aria-hidden="true" class="text-on-surface-subtle">/</li>

            {{--
                The last crumb is the page, and is marked as such.

                `aria-current="page"` on a trail that ends where you are
                is how a screen reader announces "you are here" rather
                than reading a fourth link the user cannot follow.
            --}}
            <li aria-current="page" class="font-medium text-on-surface">
                {{ __($trail['item']['label_ar']) }}
            </li>
        </ol>
    </nav>
@endif
