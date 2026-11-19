{{--
    <x-page-header> - the top of every page inside the shell.

        title         the page's name. A bound attribute for plain text,
                      or a slot when the name needs markup - an invoice
                      number set in the figure face, a code in an LTR span.
        headingClass  extra classes for the <h1> itself, for exactly that
                      case. The base classes are not negotiable; this adds.
        eyebrow       the small line ABOVE the name: a document type, a
                      status. It is not a heading, because the page's name
                      is the name and an eyebrow in the outline makes the
                      document type look like the page.
        description   one or two sentences saying what the page is.
        aside         what belongs on the end side - the entity's
                      functional currency, a caption about how the list is
                      ordered, the page's one primary action.

    ## Why a component and not a convention

    Thirteen screens were written against the same guidance and produced
    thirteen copies of this block. Most of them agreed, which was luck.
    The difference nobody would have caught is the heading LEVEL: a
    screen-reader user navigates by headings, so a page whose title is an
    `<h2>` because somebody copied a card has no top level at all - and it
    looks identical.

    ## One `<h1>`, and it is the page's name

    Not the product's and not the section's: the shell says both already,
    so a page whose `<h1>` is "PeakFlow" leaves the reader with no heading
    that tells them where they are.

    ## The aside is not a second heading

    A chip, a caption, or one button. A header with four equally weighted
    buttons has no primary action and the reader reads all four every time
    they arrive; anything secondary belongs beside the thing it acts on.
--}}
@props([
    'title' => null,
    'headingClass' => null,
    'description' => null,
])

<div {{ $attributes->class(['page-header flex flex-wrap items-end justify-between gap-4']) }}>
    <div>
        @isset($eyebrow)
            <p class="text-label text-on-surface-muted">{{ $eyebrow }}</p>
        @endisset

        <h1 @class(['text-headline font-bold text-on-surface', $headingClass])>{{ $title }}</h1>

        @if ($description !== null)
            <p class="mt-1 max-w-2xl text-label text-on-surface-muted">{{ $description }}</p>
        @endif
    </div>

    @isset($aside)
        {{ $aside }}
    @endisset
</div>
