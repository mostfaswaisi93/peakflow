{{--
    The application shell's navigation - PF-204.

    ## Two layouts, one list

    A column beside the content from `lg` up; a closed disclosure above it
    below that. `<details>` rather than a scripted drawer, because PF-204
    asks for a shell that still renders its content with no JavaScript, and
    a drawer whose only opener is a click handler is a navigation that
    disappears when a script fails to load - on the one page type where
    that matters most, because there is nothing else to click.

    ## Why the column is `<nav>` and the disclosure is not

    Two elements with the same landmark role and the same label are two
    "Main navigation" entries in a screen reader's landmark list, and the
    reader cannot tell which one is the hidden copy. So only the one that
    is actually displayed at a given width carries the landmark: the column
    is `<nav>` and is `hidden` below `lg`; the disclosure is a plain
    `<details>` and is `lg:hidden`.

    ## It is a courtesy, not a control

    Every item here is already authorized at its route. The permissions in
    `Navigation` decide what is SHOWN, so a user is not offered a page that
    will refuse them - but a menu that was the only thing in the way would
    be a menu somebody types past.
--}}
{{--
    ## Rendered in two places, one part each

    `part` is `column` (the `<nav>` beside the content, from `lg` up) or
    `disclosure` (the menu button at the start of the header, below `lg`).
    The header owns the button so a narrow screen gets one bar, not a header
    and a second navy bar under it; the panel hangs from the header, which
    is its positioned ancestor. Called without `part`, both render, as
    before.
--}}
@php($part ??= 'both')
@php($sections = \App\Support\Ui\Navigation::forCurrentUser())

@if ($sections !== [] && $part !== 'disclosure')
    <nav aria-label="{{ __('التنقل الرئيسي') }}"
         class="hidden shrink-0 border-e border-outline bg-sidebar p-4 lg:block lg:w-64">
        {{--
            Sticky, and scrollable on its own.

            The ledger sections are long, and a sidebar that scrolled with
            the page would put the section the user is in off screen
            exactly when they are working through a list that is longer
            than the viewport.
        --}}
        {{--
            `overscroll-contain` so reaching the end of the menu does not
            start scrolling the page behind it - the gesture that loses a
            user their place in a long list without ever looking like it did.
        --}}
        {{-- `top-20` clears the sticky header (4rem) with the same 1rem gap
             the column had before the header stuck. --}}
        <div class="scrollbar-on-sidebar sticky top-20 max-h-[calc(100dvh-6rem)] overflow-y-auto overscroll-contain pe-1">
            @include('partials.nav-list', ['sections' => $sections])
        </div>
    </nav>
@endif

@if ($sections !== [] && $part !== 'column')
    {{--
        The narrow-screen menu: an icon button in the header, a panel under it.

        `name="pf-header"` makes it one of the header's exclusive group, so
        opening it closes the theme, density and account menus and the other
        way round - the browser's own accordion, no script.

        The label is the button's accessible name, hidden visually: three
        lines are the one icon everybody reads without a word under it.
    --}}
    <details name="pf-header" class="group lg:hidden">
        <summary class="-ms-2 flex size-10 list-none items-center justify-center rounded-button text-on-surface-muted
                        transition-colors duration-(--duration-fast) ease-(--ease-standard)
                        hover:bg-surface-hover hover:text-on-surface group-open:bg-surface-selected group-open:text-on-surface
                        focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring
                        [&::-webkit-details-marker]:hidden">
            <x-icon name="menu" class="size-6 group-open:hidden"/>
            <x-icon name="close" class="hidden size-6 group-open:block"/>
            <span class="sr-only">{{ __('التنقل الرئيسي') }}</span>
        </summary>

        <div class="scrollbar-on-sidebar absolute inset-x-0 top-full z-(--z-drawer) max-h-[calc(100dvh-7rem)] overflow-y-auto overscroll-contain
                    border-b border-outline bg-sidebar px-4 py-5 shadow-e3 sm:px-6">
            @include('partials.nav-list', ['sections' => $sections])
        </div>
    </details>
@endif
