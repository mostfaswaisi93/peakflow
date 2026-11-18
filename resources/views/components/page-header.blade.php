{{--
    <x-page-header> - the top of every page inside the shell.

        title        the page's name, already translated
        description  one sentence, optional
        actions      the slot for the page's primary action, optional

    ## Why a component and not a convention

    A page header built by hand on each screen is twelve headers, and by
    the fourth they disagree about the heading level, the spacing and where
    the primary action sits. The heading level is the part that matters
    beyond taste: a screen reader user navigates by headings, and a page
    whose title is an <h2> because somebody copied a card is a page with no
    top level at all.

    ## The action is on the end side, and there is one

    The slot takes one button, not a row of them. A header with four
    equally weighted buttons has no primary action, and the user reads all
    four every time they arrive. Anything secondary belongs beside the
    thing it acts on.
--}}
@props([
    'title',
    'description' => null,
])

<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        <h1 class="text-title font-semibold text-on-surface">{{ $title }}</h1>

        @if ($description !== null)
            <p class="mt-1 max-w-prose text-body text-on-surface-muted">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset
</header>
