{{--
    <x-grid.cell> - one cell of <x-grid>, and the unit the keyboard model
    moves between.

        column  the key of the column this cell belongs to. It is how the
                cell inherits the column's heading, its alignment, its
                number in the full column set and whether it is editable -
                so a page's row loop says nothing about any of them twice.
        value   the cell's value as a SPREADSHEET should receive it, when
                that is not the text on screen. "1,234.56 SAR" pasted into
                Excel is a string; "1234.56" is a number.
        input   extra attributes for the editable cell's <input>, as an
                array - e.g. ['step' => '0.01', 'required' => true].

    A cell whose column is hidden renders nothing at all, so column
    visibility never reaches the page's loop: the page writes every cell
    it has, and the grid decides which are shown.

    ## How a cell gets its accessible name (PF-207)

    Through `headers`, pointing at the column's `<th id>`. A bare
    "1,234.56" read on its own is meaningless, and the column name is the
    missing half.

    Not with a `<span class="sr-only">` prefix inside the cell, which is
    the obvious alternative and is wrong twice: on a 50-row grid it adds
    350 elements that exist only to be read, and it lands in the
    clipboard - the TSV the grid copies for a spreadsheet would carry the
    column name in front of every figure in every cell.

    ## Exactly one cell is a tab stop

    A grid is one composite widget, so it takes ONE place in the tab
    order and the arrow keys move inside it (the roving tabindex of
    WAI-ARIA 1.2). Fifty rows of seven focusable cells would otherwise be
    350 tab stops between the filter and the paginator.

    The first cell of the first row is the one that carries
    `tabindex="0"`, and the keyboard layer moves it from there. With no
    script that cell is still reachable, which is what makes the
    no-script path real: Tab lands on the grid, and everything the grid
    offers - a link in a cell, a sort, a page, an editable field - is a
    real element reachable with Tab from there.
--}}
@aware([
    'columns' => [],
    'name' => null,
    'index' => 0,
    'rowKey' => null,
])

@props([
    'column' => null,
    'value' => null,
    'input' => [],
])

@php
    $keys = array_map(static fn (array $one): string => (string) ($one['key'] ?? ''), $columns);
    $position = array_search($column, $keys, true);

    if ($position === false) {
        /*
         | A cell naming a column the grid does not declare is a cell in
         | the wrong place, and the symptom without this refusal is a row
         | whose figures sit one column over from their headings - which
         | reads as a data error rather than as a template error.
         */
        throw new \RuntimeException(sprintf(
            '<x-grid.cell>: no column is called "%s". The grid declares: %s.',
            (string) $column,
            $keys === [] ? '(none)' : implode(', ', $keys),
        ));
    }

    $definition = $columns[$position];
    $hidden = (bool) ($definition['hidden'] ?? false);
    $isFigure = (bool) ($definition['figure'] ?? false);
    $isEditable = (bool) ($definition['editable'] ?? false);
    $isRowHeader = (bool) ($definition['rowheader'] ?? false);
    $isPinned = (bool) ($definition['pinned'] ?? false);

    $gridId = 'grid-'.$name;
    $headerId = $gridId.'-col-'.$definition['key'];

    // The number in the FULL set, so a hidden column leaves a gap in the
    // numbering rather than renumbering the ones after it.
    $colIndex = (int) $position + 1;

    // The first visible column, which is where the single tab stop sits.
    $firstVisible = null;

    foreach ($columns as $one) {
        if (! ($one['hidden'] ?? false)) {
            $firstVisible = (string) $one['key'];

            break;
        }
    }

    $isTabStop = (int) $index === 0 && $definition['key'] === $firstVisible;

    if ($isEditable && ! is_string($rowKey) && ! is_int($rowKey)) {
        throw new \RuntimeException(sprintf(
            '<x-grid.cell>: the column "%s" is editable, so its row needs a `rowKey`. Without one the '.
            'input has no name that identifies a record, and the server receives a figure it cannot '.
            'attribute to anything.',
            (string) $column,
        ));
    }

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

    $tag = $isRowHeader ? 'th' : 'td';
    $role = $isRowHeader ? 'rowheader' : 'gridcell';
@endphp

@if (! $hidden)
<{{ $tag }} role="{{ $role }}"
    @if ($isRowHeader) scope="row" @endif
    headers="{{ $headerId }}"
    aria-colindex="{{ $colIndex }}"
    tabindex="{{ $isTabStop ? '0' : '-1' }}"
    data-pf-cell="{{ $definition['key'] }}"
    @if ($isEditable) data-pf-editable @endif
    @if ($value !== null) data-pf-value="{{ $value }}" @endif
    @if ($isFigure) data-figure @endif
    {{ $attributes->class([
        'px-(--row-padding-inline) py-(--row-padding-block) align-middle',
        'text-start' => ! $isFigure && ! $isEditable,
        'sticky start-0 bg-surface' => $isPinned,
        $extraClass => $extraClass !== '',
    ]) }}>
    @if ($isEditable)
        {{--
            An editable cell is an input, always - not a display that
            swaps to an input when you type.

            The swap needs a script to exist at all, so with scripting
            off the grid would be read-only, and a line grid somebody
            cannot type into is not a degraded version of this screen,
            it is a different one. Being an input from the start makes
            "type to edit" the browser's own behaviour, Tab and paste
            native, and the keyboard layer a navigation aid over
            something that already works.

            `defaultValue` is what Esc reverts to, which is why the
            server's value is in the attribute rather than written by a
            script.
        --}}
        <input type="text"
               name="{{ $name }}[{{ $rowKey }}][{{ $definition['key'] }}]"
               value="{{ $value }}"
               @if ($isFigure) inputmode="decimal" dir="ltr" @endif
               aria-labelledby="{{ $headerId }}"
               @foreach ($input as $attribute => $setting)
                   @if ($setting === true) {{ $attribute }} @elseif ($setting !== false && $setting !== null) {{ $attribute }}="{{ $setting }}" @endif
               @endforeach
               @class([
                   'w-full min-w-0 rounded-field border border-outline-control bg-surface-sunken',
                   'px-2 py-1 text-body text-on-surface',
                   'focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring',
                   'figure' => $isFigure,
               ])>
        @if (trim((string) $slot) !== '')
            <span class="ms-1 text-caption text-on-surface-muted">{{ $slot }}</span>
        @endif
    @else
        {{ $slot }}
    @endif
</{{ $tag }}>
@endif
