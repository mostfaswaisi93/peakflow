{{--
    <x-grid.group> - a group heading with its subtotals (PF-205).

        index     the row's position in the RENDERED window, zero-based,
                  exactly as <x-grid.row> takes it. A group heading IS a
                  row: it is counted in `aria-rowcount`, it is announced
                  with a row number, and the page that knows how many
                  groups it emitted is the only thing that can number
                  them.
        label     the group's name - the account, the party, the period.
        totals    the group's subtotals, keyed by column key, ALREADY
                  formatted. The server's figures, printed as given.

    ## Why the subtotals come from the server

    For the same reason the footer's do: a subtotal summed from the loaded
    page is a figure that agrees with the rows above it and disagrees with
    the group, and the reader has no way to see which they are looking at.
    A grouped report where the groups sum to less than the total is a
    report somebody has to reconcile by hand.

    ## One cell per column, never a colspan

    The obvious shape is a label spanning the leading columns and then the
    figures, and it makes the cell positions in a group row different from
    every other row - so the keyboard model's columns stop lining up at
    each heading, and `aria-colindex` has to be computed from a span. One
    cell per column costs an empty cell or two and keeps the grid a grid.
--}}
@aware([
    'columns' => [],
    'name' => null,
    'paginator' => null,
])

@props([
    'index' => 0,
    'label' => null,
    'totals' => [],
])

@php
    if (! is_string($label) || trim($label) === '') {
        throw new \RuntimeException(
            '<x-grid.group>: needs a `label`. An unnamed group heading is a band of shading, and what it '.
            'groups is then a guess from the order of the rows.'
        );
    }

    $first = $paginator !== null && method_exists($paginator, 'firstItem')
        ? ($paginator->firstItem() ?? 1)
        : 1;

    $rowIndex = (int) $first + (int) $index + 1;

    $visible = array_values(array_filter(
        $columns,
        static fn (array $column): bool => ! ($column['hidden'] ?? false),
    ));

    $keys = array_map(static fn (array $one): string => (string) ($one['key'] ?? ''), $columns);
    $gridId = 'grid-'.$name;

    $extraClass = \Illuminate\Support\Arr::toCssClasses((array) $attributes->get('class', []));
    $attributes = $attributes->except('class');
@endphp

<tr role="row"
    data-pf-row="group-{{ $index }}"
    aria-rowindex="{{ $rowIndex }}"
    {{ $attributes->class([
        'h-(--row-height) bg-surface-container-low font-semibold text-on-surface',
        $extraClass => $extraClass !== '',
    ]) }}>
    @foreach ($visible as $position => $column)
        @php($colIndex = (int) array_search($column['key'], $keys, true) + 1)

        @if ($position === 0)
            <th role="rowheader"
                scope="row"
                id="{{ $gridId }}-group-{{ $index }}"
                aria-colindex="{{ $colIndex }}"
                tabindex="-1"
                data-pf-cell="{{ $column['key'] }}"
                @class([
                    'px-(--row-padding-inline) py-(--row-padding-block) text-start',
                    'sticky start-0 bg-surface-container-low' => (bool) ($column['pinned'] ?? false),
                ])>{{ $label }}</th>
        @else
            {{--
                A subtotal cell names its column AND its group.

                "1,000.00" read alone is meaningless; read as "the total,
                commissions, 1,000.00" it is the number the reader came
                for. The order of the two ids is the order they are read.
            --}}
            <td role="gridcell"
                headers="{{ $gridId }}-col-{{ $column['key'] }} {{ $gridId }}-group-{{ $index }}"
                aria-colindex="{{ $colIndex }}"
                tabindex="-1"
                data-pf-cell="{{ $column['key'] }}"
                @if (($totals[$column['key']] ?? null) !== null) data-pf-value="{{ $totals[$column['key']] }}" @endif
                @if (($column['figure'] ?? false)) data-figure @endif
                class="px-(--row-padding-inline) py-(--row-padding-block)">
                {{ $totals[$column['key']] ?? '' }}
            </td>
        @endif
    @endforeach
</tr>
