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
@php($sections = \App\Support\Ui\Navigation::forCurrentUser())

@if ($sections !== [])
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
        <div class="scrollbar-on-sidebar sticky top-4 max-h-[calc(100dvh-5rem)] overflow-y-auto overscroll-contain pe-1">
            @include('partials.nav-list', ['sections' => $sections])
        </div>
    </nav>

    <details class="border-b border-outline bg-sidebar lg:hidden">
        <summary class="flex cursor-pointer items-center gap-2 px-6 py-3 text-body text-on-sidebar
                        marker:content-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus-ring">
            <x-icon name="menu" class="size-5"/>
            <span>{{ __('التنقل الرئيسي') }}</span>
        </summary>

        <div class="px-4 pb-4">
            @include('partials.nav-list', ['sections' => $sections])
        </div>
    </details>
@endif
