{{--
    <x-grid.row> - one data row of <x-grid>.

        index     the row's position in the RENDERED window, zero-based.
                  `$loop->index` is what a page passes.
        rowKey    the record's key. It names the row for the keyboard
                  layer and it is what an editable cell's input is named
                  after, so the server knows which record a figure
                  belongs to.
        selected  whether this row is part of the current selection.
                  Left out entirely, the row claims nothing: a row marked
                  `aria-selected="false"` tells a screen reader the grid
                  has a selection model, so a list that has none must not
                  say it.

    ## aria-rowindex is the row's place in the SET, not in the window

    Page three of a 12,480-row list renders rows 101-150, and a screen
    reader that announces them as 1-50 tells the user they are at the top
    of the list. So the index is the paginator's first item plus the
    position in the window, plus one for the header row - which
    `aria-rowcount` counts too, and the pair have to agree or the reader
    is told "row 2 of 12,480" on the first row of the set.
--}}
@aware([
    'paginator' => null,
])

@props([
    'index' => 0,
    'rowKey' => null,
    'selected' => null,
])

@php
    $first = $paginator !== null && method_exists($paginator, 'firstItem')
        ? ($paginator->firstItem() ?? 1)
        : 1;

    $rowIndex = (int) $first + (int) $index + 1;
    /*
     | A `class` given as an ARRAY is flattened before it is merged.
     |
     | `:class="['border-s-2 border-draft-stripe' => $invoice->isDraft()]"`
     | is the natural way to write a conditional class on a row or a cell -
     | it is how every page in this repository writes one - and a component
     | that merges its own classes throws "Array to string conversion" on
     | it, from inside the attribute bag, with the component's name nowhere
     | in the message. So the array is turned into a class list here, where
     | the alternative is a 500 on the first conditional class somebody
     | writes.
     */
    $extraClass = \Illuminate\Support\Arr::toCssClasses((array) $attributes->get('class', []));
    $attributes = $attributes->except('class');

@endphp

<tr role="row"
    data-pf-row="{{ $rowKey ?? $index }}"
    aria-rowindex="{{ $rowIndex }}"
    @if ($selected !== null) aria-selected="{{ $selected ? 'true' : 'false' }}" @endif
    {{ $attributes->class(['h-(--row-height)', $extraClass => $extraClass !== '']) }}>
    {{ $slot }}
</tr>
