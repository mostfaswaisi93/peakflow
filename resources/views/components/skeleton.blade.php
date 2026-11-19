{{--
    <x-skeleton> - the shape of what is coming - PF-280.

        of     table | tile | text   what the content will be
        rows   how many rows of it (default 5)
        cols   for `table`: the column widths, as a list of fractions

    ## It is the shape, not a grey box

    A table skeleton has the table's columns at the table's widths; a tile
    skeleton has the tile's rows. A single grey rectangle tells the person
    that something is loading, which they knew; the shape tells them what
    they are about to read, and lets them start deciding where to look.

    ## It does not shimmer

    The shimmer is the part of a skeleton that is decoration, and the house
    rule is that nothing loops. The placeholder is the information and it is
    all here - which also means there is nothing to stop under
    `prefers-reduced-motion`, so the reduced path and the ordinary one are
    the same component rather than two that have to be kept in step.

    ## It occupies the content's box

    Every dimension here is the one the real content uses - the row height
    comes from the density tokens, the same as `<x-grid>`'s rows - so the
    content replaces the skeleton without moving anything. A skeleton that
    shifts the layout when it resolves is worse than an empty space: the
    person has already started reading, and the line they were on moves.

    ## Where it may be used

    Only where the wait has been MEASURED to be long enough to matter.
    `docs/design/wait-budget.md` holds the measurements and the threshold,
    and `SkeletonRules` refuses a screen that uses this without one.
--}}
@props([
    'of' => 'text',
    'rows' => 5,
    'cols' => null,
])

@php
    $rows = max(1, min(20, (int) $rows));

    /*
     | Column widths as fractions, so the skeleton's columns are the grid's
     | columns rather than a row of equal grey bars. A list of five figures
     | and a name does not look like six identical rectangles.
     */
    $cols = is_array($cols) && $cols !== []
        ? $cols
        : ['28%', '18%', '14%', '14%', '14%', '12%'];
@endphp

<div {{ $attributes->class(['pf-skeleton']) }} aria-busy="true" aria-live="polite">
    {{-- One sentence for a screen reader, and nothing for the eye: the
         shapes below are aria-hidden, because reading out eleven empty
         boxes is not a description of anything. --}}
    <span class="sr-only">{{ __('جارٍ تحميل المحتوى') }}</span>

    <div aria-hidden="true">
        @if ($of === 'table')
            <div class="pf-skeleton__row pf-skeleton__row--head">
                @foreach ($cols as $width)
                    <span class="pf-skeleton__cell" style="inline-size: {{ $width }}"></span>
                @endforeach
            </div>

            @for ($row = 0; $row < $rows; $row++)
                <div class="pf-skeleton__row">
                    @foreach ($cols as $width)
                        <span class="pf-skeleton__cell" style="inline-size: {{ $width }}"></span>
                    @endforeach
                </div>
            @endfor
        @elseif ($of === 'tile')
            <div class="pf-skeleton__tile">
                <span class="pf-skeleton__cell pf-skeleton__cell--label"></span>
                <span class="pf-skeleton__cell pf-skeleton__cell--figure"></span>
                <span class="pf-skeleton__cell pf-skeleton__cell--note"></span>
            </div>
        @else
            @for ($row = 0; $row < $rows; $row++)
                <span class="pf-skeleton__cell pf-skeleton__cell--line"></span>
            @endfor
        @endif
    </div>
</div>
