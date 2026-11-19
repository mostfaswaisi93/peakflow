{{--
    <x-grid> - the data grid (PF-205), its keyboard model (PF-206) and its
    accessibility semantics (PF-207).

        name        the instance key. Every id inside this grid is derived
                    from it, so two grids on one page keep separate id
                    spaces - a duplicate id points a label, a `headers`
                    reference or a paste dialog at whichever copy the
                    browser saw first.
        caption     what the table IS, for a screen reader. Rendered as a
                    real <caption> and used as the grid's accessible name.
        columns     the column declaration - see below. The order here is
                    the column order; `hidden` takes a column out of the
                    render without taking it out of the numbering.

                    `width` is what a column wants; `minWidth` is what it
                    will not go below. They are different questions and the
                    second is the one that keeps a table readable: a width
                    is a preference a table will overrule to fit, and a
                    long legal name overruling the amount column is how the
                    figures end up on two lines. A figure column carries a
                    minimum already - see app.css - so `minWidth` is for
                    the ones whose content the stylesheet cannot know.
        paginator   a LengthAware, Simple or Cursor paginator. Used for the
                    page links and, when it knows one, the total.
        total       the server's row count, when it does not come from the
                    paginator. Null and unknowable means unknown, which is
                    announced as unknown rather than guessed.
        totals      footer aggregates, keyed by column key, ALREADY
                    formatted. They come from the server.
        totalsLabel the row header for that footer row.
        error       a sentence to show INSTEAD of rows, when the rows could
                    not be read. Not the same as `empty`: "no invoices match
                    this filter" says the data is fine and your question
                    returned nothing, "we could not read the invoices" says
                    you do not know what the data is, and a person shown the
                    first when the second is true concludes a supplier has
                    no bills.
        loading     show the grid's skeleton instead of rows. Where this may
                    be used is decided by docs/design/wait-budget.md, not by
                    whoever is writing the page.
        action      where an edited grid submits. Required if any column is
                    editable, because an editable grid with nowhere to
                    submit loses every number typed into it in silence.
        method      the form method for `action`.
        submit      the label of the submit button for `action`.
        filtered    whether the request that produced these rows carried a
                    filter. It decides whether the result summary takes
                    focus on load - see "announcing a filter" below.
        height      the scroller's maximum block size, as a CSS length.
        columnsUrl  where the column-visibility form submits. Defaults to
                    the current URL.
        views       saved views: [['label' => ..., 'url' => ..., 'current'
                    => bool], ...]. Rendered as links, so a view is a URL
                    somebody can send to a colleague.
        saveUrl     where "save this view" posts. Omitted, no save form.

    Slots: the default slot is the rows (<x-grid.row> / <x-grid.cell>);
    `empty` is what the page says when there are none; `filters` is a GET
    form's controls, rendered above the table; `aside` sits beside the
    result summary.

    ## A column declaration

        ['key' => 'gross', 'label' => __('الإجمالي'), 'figure' => true,
         'sortUrl' => $url, 'sort' => 'desc', 'width' => '10rem',
         'pinned' => true, 'rowheader' => true, 'hidden' => false,
         'editable' => true]

    `key` is what a cell names, `label` is the heading and the accessible
    name a cell inherits through `headers`. Everything else is optional.

    ## Why there is no row virtualization here

    PF-205 asks for 50,000 rows without degrading scroll. This product
    ships no SPA, and a virtualizer is a scroll-driven renderer: with
    scripting off it shows nothing, which for a financial list is worse
    than slow. So the answer is the other one PF-205 gives - "the grid
    never holds a full dataset": the window is bounded on the SERVER, the
    paginator moves it, and the grid REFUSES to render more than
    MAX_WINDOW rows at once rather than quietly becoming the thing that
    janks. `aria-rowcount` still tells the truth about the whole set, so
    nobody is told the 50 rows in front of them are all there is.

    ## The pinned column is above the body and below the header

    `<thead>` is the sticky element, not each `<th>`: one positioned
    element with a layer token, so the pinned leading column - which is
    `position: sticky` with no z-index of its own - paints over ordinary
    body cells (positioned beats unpositioned) and under the header
    (z-index beats auto). Making every `<th>` sticky instead gives the
    header and the pinned column the same z-index, and then the body's
    pinned cells scroll OVER the header, because equal z-index is settled
    by document order and `<tbody>` comes second.

    And the scroller carries a maximum block size on purpose. Sticky
    resolves against the nearest scroll container, so a header inside a
    container that never scrolls vertically does not stick to anything -
    it is the most common way a sticky header ships as a non-sticky one.

    ## Announcing a filter (PF-207)

    A live region's content is announced when it CHANGES. The content it
    arrives with is not announced at all, so a server-rendered "showing
    1-50 of 12,480" on a page the filter form just reloaded says nothing
    to a screen-reader user - which is exactly the moment PF-207 wants
    announced.

    So the summary is a live region for the script to write into AND,
    when the request carried a filter, it takes focus on load. That is
    the no-script path: the reader lands on the result of the thing they
    just did, in both languages' reading order, with no script at all.
--}}
@props([
    'name' => null,
    'caption' => null,
    'columns' => [],
    'paginator' => null,
    'total' => null,
    'totals' => [],
    'totalsLabel' => null,
    'error' => null,
    'loading' => false,
    'action' => null,
    'method' => 'POST',
    'submit' => null,
    'filtered' => false,
    'height' => '70dvh',
    'columnsUrl' => null,
    'views' => [],
    'saveUrl' => null,
])

@php
    /*
     | The window cap.
     |
     | A grid handed 5,000 rows renders them, scrolls badly and reads
     | fine, so nothing anywhere says the page is the problem. The cap
     | turns that into a refusal naming the paginator, at the point the
     | HTML is assembled, where the fix is one query away.
     */
    $maxWindow = 500;

    $refuse = static function (string $why): never {
        throw new \RuntimeException('<x-grid>: '.$why);
    };

    if (! is_string($name) || preg_match('/\A[a-z][a-z0-9-]*\z/', $name) !== 1) {
        $refuse(
            'needs a `name` of lowercase letters, digits and hyphens. Every id in the grid is derived '.
            'from it, and two grids on one page with the same ids point a cell\'s `headers` and the '.
            'paste dialog at whichever copy the browser saw first.'
        );
    }

    if (! is_string($caption) || trim($caption) === '') {
        $refuse(
            'needs a `caption` saying what the table is. It is the grid\'s accessible name, and a grid '.
            'with none is announced as "grid" - on a page that may hold two of them.'
        );
    }

    if ($columns === []) {
        $refuse('needs at least one column.');
    }

    /*
     | A width or a height out of a saved view is user data.
     |
     | It is interpolated into a `style` attribute, so an unvalidated one
     | is CSS injection by way of a feature nobody thinks of as input.
     | Only a plain length passes, and anything else is refused by name
     | rather than dropped silently.
     */
    $length = static function (mixed $value): ?string {
        return is_string($value) && preg_match('/\A\d{1,4}(?:\.\d{1,3})?(?:rem|ch|%|dvh|vh|em)\z/', $value) === 1
            ? $value
            : null;
    };

    if ($length($height) === null) {
        $refuse(sprintf('was given the height "%s", which is not a plain CSS length.', (string) $height));
    }

    $keys = [];
    $editable = false;

    foreach ($columns as $position => $column) {
        if (! is_array($column) || ! is_string($column['key'] ?? null) || $column['key'] === '') {
            $refuse(sprintf('column %s has no `key`.', (string) $position));
        }

        if (in_array($column['key'], $keys, true)) {
            $refuse(sprintf('declares the column "%s" twice, so a cell naming it is ambiguous.', $column['key']));
        }

        if (! is_string($column['label'] ?? null) || trim((string) $column['label']) === '') {
            $refuse(sprintf(
                'column "%s" has no `label`. The label is the heading AND the accessible name every cell '.
                'in the column inherits, and a bare "1,234.56" read with no column name is meaningless.',
                $column['key'],
            ));
        }

        if (($column['minWidth'] ?? null) !== null && $length($column['minWidth']) === null) {
            $refuse(sprintf(
                'declares a minWidth of "%s" for the column "%s", which is not a CSS length. A minimum '.
                'the browser cannot parse is a minimum that silently is not there.',
                (string) $column['minWidth'],
                $column['key'],
            ));
        }

        if (($column['width'] ?? null) !== null && $length($column['width']) === null) {
            $refuse(sprintf(
                'column "%s" has the width "%s", which is not a plain CSS length.',
                $column['key'],
                (string) $column['width'],
            ));
        }

        $keys[] = $column['key'];
        $editable = $editable || (bool) ($column['editable'] ?? false);
    }

    if ($editable && ! is_string($action)) {
        $refuse(
            'has an editable column and no `action`. Typing into a grid that submits nowhere loses every '.
            'figure entered, and it loses them without an error - the page simply reloads unchanged.'
        );
    }

    $gridId = 'grid-'.$name;
    $rows = (string) $slot;

    /*
     | The rendered rows are counted out of the HTML the page produced.
     |
     | The grid cannot see the page's loop - by the time this template
     | runs the rows are already a string - and the window cap and the
     | empty state both need to know whether there are any. The row
     | component always writes `data-pf-row`, so the HTML is the only
     | honest place to ask.
     */
    $rendered = substr_count($rows, 'data-pf-row=');

    if ($rendered > $maxWindow) {
        $refuse(sprintf(
            'was handed %d rows. The window is capped at %d: this product renders no virtualized list, so '.
            'a long window is a page that scrolls badly rather than a page that scrolls. Narrow the query '.
            'and let the paginator move the window.',
            $rendered,
            $maxWindow,
        ));
    }

    if ($rendered === 0 && ! isset($empty) && $error === null && ! $loading) {
        $refuse(
            'rendered no rows and was given no `empty` slot. "No results" on a page somebody opened for '.
            'the first time reads as a product that is not working; the page has to say what produces the '.
            'first row.'
        );
    }

    /*
     | The total, and what to do when there is not one.
     |
     | ARIA says `aria-rowcount` is -1 when the total is unknown, and a
     | cursor paginator genuinely does not know it. Reporting the window
     | size instead would tell a screen-reader user that the 50 rows in
     | front of them are the whole set, which is the exact defect PF-207
     | names.
     |
     | The header row counts, hence the +1: `aria-rowcount` is every row
     | in the grid, and the header is one of them.
     */
    $totalRows = $total;

    if ($totalRows === null && $paginator !== null && method_exists($paginator, 'total')) {
        $totalRows = $paginator->total();
    }

    $totalRows = is_numeric($totalRows) ? (int) $totalRows : null;
    $rowCount = $totalRows === null ? -1 : $totalRows + 1;

    $first = $paginator !== null && method_exists($paginator, 'firstItem') ? $paginator->firstItem() : null;
    $last = $paginator !== null && method_exists($paginator, 'lastItem') ? $paginator->lastItem() : null;

    $visible = array_values(array_filter($columns, static fn (array $column): bool => ! ($column['hidden'] ?? false)));

    /*
     | A `class` given as an ARRAY is flattened before it is merged - see
     | the note in resources/views/components/grid/cell.blade.php. Without
     | it, `:class="['x' => $condition]"` throws "Array to string
     | conversion" from inside the attribute bag.
     */
    $extraClass = \Illuminate\Support\Arr::toCssClasses((array) $attributes->get('class', []));
    $attributes = $attributes->except('class');

    $sortStates = ['asc' => 'ascending', 'desc' => 'descending'];
    $totalsLabel ??= __('الإجمالي');

    /*
     | The skeleton's columns are THIS grid's columns.
     |
     | A placeholder with six equal bars over a table with a name column
     | and five figures is a placeholder for a different table. Where a
     | column declares a width it is used; where it does not, a figure
     | column gets a narrow share and a text column a wide one, which is
     | the shape every table here has.
     */
    $skeletonWidths = array_map(
        static function (array $column): string {
            if (($column['width'] ?? null) !== null) {
                return (string) $column['width'];
            }

            return ($column['figure'] ?? false) === true ? '12%' : '22%';
        },
        $visible,
    );
@endphp

{{-- The keyboard model. The partial dedupes itself, so a page with four
     grids on it carries one copy. --}}
@include('partials.enhancements')

<div {{ $attributes->class(['flex flex-col gap-3', $extraClass => $extraClass !== '']) }}>
    @isset($filters)
        {{--
            The filter form is a GET, and its own form.

            A list whose URL carries its filters is a list somebody can
            send to a colleague, and the back button returns to the rows
            they were reading. It is also why it cannot be nested inside
            the edit form below: a form inside a form is dropped by the
            parser, and the one that disappears is whichever the browser
            decides.
        --}}
        <form method="GET" class="flex flex-wrap items-end gap-3">
            {{ $filters }}
        </form>
    @endisset

    @if ($views !== [] || $saveUrl !== null)
        {{--
            Saved views - a column set, widths, sort, filters and density
            somebody keeps.

            Each saved view is a LINK, because a view is a URL: that is
            what makes it shareable inside the tenant without a second
            mechanism, and it is what makes "the view I was looking at"
            survive a reload. The <details> is the no-script disclosure;
            nothing here needs a script to open.
        --}}
        <details class="rounded-card border border-outline bg-surface">
            <summary class="flex min-h-11 cursor-pointer items-center px-3 py-2 text-label font-medium text-on-surface
                            focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                {{ __('العروض المحفوظة') }}
            </summary>

            <div class="flex flex-wrap items-end gap-3 border-t border-outline-subtle px-3 py-3">
                @foreach ($views as $view)
                    <a href="{{ $view['url'] }}"
                       @if ($view['current'] ?? false) aria-current="true" @endif
                       @class([
                           'rounded-button border px-3 py-1.5 text-label',
                           'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring',
                           'border-primary bg-primary-subtle font-medium text-on-primary-subtle' => $view['current'] ?? false,
                           'border-outline-strong text-on-surface hover:bg-surface-hover' => ! ($view['current'] ?? false),
                       ])>{{ $view['label'] }}</a>
                @endforeach

                @if ($saveUrl !== null)
                    <form method="POST" action="{{ $saveUrl }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <div>
                            <label for="{{ $gridId }}-view-name" class="block text-label font-medium text-on-surface">
                                {{ __('احفظ هذا العرض باسم') }}
                            </label>
                            <input type="text" id="{{ $gridId }}-view-name" name="view" required maxlength="60"
                                   class="min-h-(--control-height) mt-1.5 block rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
                                          focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                        </div>
                        <x-button type="submit" variant="outline">{{ __('حفظ') }}</x-button>
                    </form>
                @endif
            </div>
        </details>
    @endif

    {{--
        Column visibility, as a form.

        Hiding a column is a GET - the same shape as a filter, for the
        same reason - and a checkbox per column is the whole control. A
        hidden column keeps its number in `aria-colcount`/`aria-colindex`,
        so a screen-reader user is told they are reading columns 1, 2 and
        5 of 7 rather than 1, 2 and 3 of 3.
    --}}
    <details class="rounded-card border border-outline bg-surface">
        <summary class="flex min-h-11 cursor-pointer items-center px-3 py-2 text-label font-medium text-on-surface
                        focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
            {{ __('الأعمدة الظاهرة') }}
        </summary>

        <form method="GET" action="{{ $columnsUrl ?? url()->current() }}"
              class="flex flex-wrap items-end gap-4 border-t border-outline-subtle px-3 py-3">
            @foreach ($columns as $column)
                <span class="flex items-center gap-2">
                    <input type="checkbox" id="{{ $gridId }}-show-{{ $column['key'] }}"
                           name="{{ $name }}_cols[]" value="{{ $column['key'] }}"
                           @checked(! ($column['hidden'] ?? false))
                           class="size-4 rounded-sm border border-outline-control
                                  focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    <label for="{{ $gridId }}-show-{{ $column['key'] }}" class="text-label text-on-surface">
                        {{ $column['label'] }}
                    </label>
                </span>
            @endforeach

            <x-button type="submit" variant="outline" size="sm">{{ __('طبِّق') }}</x-button>
        </form>
    </details>

    {{--
        The result summary.

        `role="status"` for the script's announcements - a selection, a
        paste, a copy - and `tabindex="-1"` with `autofocus` when the
        request carried a filter, because a live region does not announce
        the content it loaded with.
    --}}
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div id="{{ $gridId }}-summary" role="status" aria-live="polite" aria-atomic="false"
             tabindex="-1" @if ($filtered) autofocus @endif
             class="text-caption text-on-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
            <span>
                @if ($first !== null && $last !== null && $totalRows !== null)
                    {{ __('تُعرض :first–:last من أصل :total', ['first' => $first, 'last' => $last, 'total' => $totalRows]) }}
                @elseif ($totalRows !== null)
                    {{ __('تُعرض :first–:last من أصل :total', ['first' => min(1, $rendered), 'last' => $rendered, 'total' => $totalRows]) }}
                @else
                    {{--
                        A cursor-paginated grid does not know the total,
                        and says so: it reports how many rows are shown
                        and claims nothing about how many there are.
                        Printing the window size as a total is the lie
                        `aria-rowcount="-1"` exists to avoid.

                        A count LABEL rather than a counted sentence -
                        Arabic agreement is on the last two digits of the
                        number, so "12 صف" and "13 صفًا" differ and a
                        sentence would need ICU for a figure that is only
                        ever furniture. The label form is correct for
                        every number.
                    --}}
                    {{ __('عدد الصفوف المعروضة: :count', ['count' => $rendered]) }}
                @endif
            </span>
            {{-- What the keyboard layer writes. Empty until it does. --}}
            <span data-pf-grid-status class="ms-2"></span>
        </div>

        <div class="flex items-center gap-2">
            {{--
                Copy is a button as well as a shortcut.

                Ctrl+C on a selection is how a finance user copies, and a
                keyboard-only affordance is as exclusive as a mouse-only
                one: somebody using a pointer and a screen reader has no
                way to press it.
            --}}
            <button type="button" data-pf-grid-copy
                    class="inline-flex min-h-11 items-center rounded-button border border-outline-strong px-3 py-1.5 text-label text-on-surface
                           hover:bg-surface-hover
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                {{ __('انسخ المحدَّد') }}
            </button>
            @isset($aside)
                {{ $aside }}
            @endisset
        </div>
    </div>

    {{--
        What the keys do, named as the grid's description.

        Two sentences, because a read-only list must not offer editing. A
        grid that tells a screen-reader user to "type in a cell to begin
        editing" when no cell takes a value sends them looking for a
        behaviour that is not there, and they have no way to find out that
        it never was.
    --}}
    <p id="{{ $gridId }}-keys" class="sr-only">
        @if ($editable)
            {{ __('تنقَّل بالأسهم، واكتب في الخلية لبدء التعديل. Enter يثبّت وينزل، وTab يثبّت وينتقل، وEsc يعيد الخلية، وCtrl+C ينسخ المحدَّد.') }}
        @else
            {{ __('تنقَّل بالأسهم بين الخلايا، وCtrl+C ينسخ المحدَّد.') }}
        @endif
    </p>

    @if ($action !== null)
        <form method="{{ strtoupper($method) === 'GET' ? 'GET' : 'POST' }}" action="{{ $action }}"
              class="flex flex-col gap-3">
            @if (strtoupper($method) !== 'GET')
                @csrf
                @if (! in_array(strtoupper($method), ['POST', 'GET'], true))
                    @method($method)
                @endif
            @endif
    @endif

    <div data-pf-grid-scroller class="overflow-auto rounded-card border border-outline"
         style="max-block-size: {{ $height }}">
        {{--
            `role="grid"` replaces the table's own semantics, so every
            part of the structure is restated: a browser that honours the
            role stops exposing rows and cells it is not told about, and
            the result is a grid a screen reader reports as having no
            rows at all.

            The native attributes stay beside the roles - `scope`,
            `headers`, a real <caption> - because the two mechanisms are
            read by different software and dropping either loses one of
            them.
        --}}
        <table data-pf-grid="{{ $name }}"
               role="grid"
               aria-labelledby="{{ $gridId }}-caption"
               aria-describedby="{{ $gridId }}-keys"
               aria-rowcount="{{ $rowCount }}"
               aria-colcount="{{ count($columns) }}"
               class="w-full border-collapse text-label">
            <caption id="{{ $gridId }}-caption" class="sr-only">{{ $caption }}</caption>

            @if (array_filter($columns, static fn (array $column): bool => ($column['width'] ?? null) !== null) !== [])
                {{-- Widths from the saved view, applied once per column
                     rather than on every cell. --}}
                <colgroup>
                    @foreach ($visible as $column)
                        <col @if (($column['width'] ?? null) !== null) style="inline-size: {{ $column['width'] }}" @endif>
                    @endforeach
                </colgroup>
            @endif

            <thead class="sticky top-0 z-(--z-sticky) bg-surface-sunken text-on-surface-muted">
                <tr role="row" aria-rowindex="1">
                    @foreach ($visible as $column)
                        @php
                            $isFigure = (bool) ($column['figure'] ?? false);
                            $sort = $sortStates[$column['sort'] ?? ''] ?? 'none';
                            $index = (int) array_search($column['key'], $keys, true) + 1;
                        @endphp

                        <th role="columnheader"
                            scope="col"
                            id="{{ $gridId }}-col-{{ $column['key'] }}"
                            aria-colindex="{{ $index }}"
                            @if (($column['sortUrl'] ?? null) !== null) aria-sort="{{ $sort }}" @endif
                            @if ($isFigure) data-figure @endif
                            @if (($column['minWidth'] ?? null) !== null) style="min-inline-size: {{ $column['minWidth'] }}" @endif
                            @class([
                                'px-(--row-padding-inline) py-(--row-padding-block) font-medium whitespace-nowrap',
                                'text-start' => ! $isFigure,
                                'sticky start-0 bg-surface-sunken' => (bool) ($column['pinned'] ?? false),
                            ])>
                            @if (($column['sortUrl'] ?? null) !== null)
                                {{--
                                    Sorting is a link, so it works with no
                                    script and the sorted list has a URL.

                                    The direction is in `aria-sort`, in the
                                    glyph and in words. A caret alone is
                                    colour-and-shape only: it survives
                                    neither a printout nor a reader.
                                --}}
                                <a href="{{ $column['sortUrl'] }}"
                                   class="inline-flex items-center gap-1 rounded-sm underline-offset-2 hover:underline
                                          focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                    <span>{{ $column['label'] }}</span>
                                    @if ($sort === 'ascending')
                                        <span aria-hidden="true">▲</span>
                                        <span class="sr-only">{{ __('مرتَّب تصاعديًا') }}</span>
                                    @elseif ($sort === 'descending')
                                        <span aria-hidden="true">▼</span>
                                        <span class="sr-only">{{ __('مرتَّب تنازليًا') }}</span>
                                    @else
                                        <span aria-hidden="true">↕</span>
                                        <span class="sr-only">{{ __('رتِّب بهذا العمود') }}</span>
                                    @endif
                                </a>
                            @else
                                {{ $column['label'] }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody class="grid-rows divide-y divide-outline-subtle bg-surface">
                @if ($error !== null)
                    {{--
                        An error is NOT an empty state, and the two being one
                        row of markup is how they become one in a reader's
                        mind. "No invoices match this filter" and "we could
                        not read the invoices" are opposite facts: the first
                        says the data is fine and your question returned
                        nothing, the second says you do not know what the
                        data is. A person who is shown the first when the
                        second is true concludes a supplier has no bills.

                        `role="alert"` rather than a live region, because
                        this arrives with the page rather than during it, and
                        an alert is announced on arrival.
                    --}}
                    <tr role="row" aria-rowindex="2">
                        <td role="gridcell" colspan="{{ count($visible) }}" class="p-0">
                            {{-- No icon: the kit has none for this, and a
                                 glyph invented here would be the one place
                                 in the product with its own. The colour pair
                                 is declared and measured, and the words are
                                 what carry it - WCAG 1.4.1. --}}
                            <p role="alert"
                               class="bg-danger-subtle px-(--row-padding-inline) py-8 text-start text-on-danger-subtle">
                                {{ $error }}
                            </p>
                        </td>
                    </tr>
                @elseif ($loading)
                    {{--
                        The grid's own loading state, so a page does not
                        write one. The skeleton takes this grid's columns,
                        so the shape that appears is the shape that arrives.

                        `docs/design/wait-budget.md` is what decides where
                        this may be used, and today the answer is nowhere -
                        every screen is under the threshold. It is here so
                        that the first screen that crosses it has one rather
                        than inventing one.
                    --}}
                    <tr role="row" aria-rowindex="2">
                        <td role="gridcell" colspan="{{ count($visible) }}" class="p-0">
                            <x-skeleton of="table" :rows="5" :cols="$skeletonWidths" />
                        </td>
                    </tr>
                @elseif ($rendered === 0)
                    <tr role="row" aria-rowindex="2">
                        <td role="gridcell" colspan="{{ count($visible) }}"
                            class="px-(--row-padding-inline) py-8 text-center text-on-surface-muted">
                            {{ $empty }}
                        </td>
                    </tr>
                @else
                    {!! $rows !!}
                @endif
            </tbody>

            @if ($totals !== [] && $rendered > 0)
                {{--
                    The footer aggregates are the SERVER's, printed as
                    given.

                    Summing the loaded page here would produce a total
                    that disagrees with the total of the set while looking
                    exactly like it - the one kind of wrong a reader
                    cannot see, because the column above it adds up.
                --}}
                <tfoot class="border-t-2 border-outline-strong bg-surface-sunken font-semibold text-on-surface">
                    <tr role="row" aria-rowindex="{{ $rowCount === -1 ? $rendered + 2 : $rowCount + 1 }}">
                        @foreach ($visible as $position => $column)
                            @php($index = (int) array_search($column['key'], $keys, true) + 1)

                            @if ($position === 0)
                                <th role="rowheader" scope="row" aria-colindex="{{ $index }}"
                                    @class([
                                        'px-(--row-padding-inline) py-(--row-padding-block) text-start',
                                        'sticky start-0 bg-surface-sunken' => (bool) ($column['pinned'] ?? false),
                                    ])>
                                    {{ $totals[$column['key']] ?? $totalsLabel }}
                                </th>
                            @else
                                <td role="gridcell" aria-colindex="{{ $index }}"
                                    headers="{{ $gridId }}-col-{{ $column['key'] }}"
                                    @if (($column['figure'] ?? false)) data-figure @endif
                                    class="px-(--row-padding-inline) py-(--row-padding-block)">
                                    {{ $totals[$column['key']] ?? '' }}
                                </td>
                            @endif
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($action !== null)
            <div class="flex items-center gap-3">
                <x-button type="submit">{{ $submit ?? __('حفظ') }}</x-button>
                {{--
                    The pending state belongs to whatever recalculates on
                    the server; the grid only promises that the button is
                    a real submit and that every edited cell is a real
                    field inside this form.
                --}}
            </div>
        </form>

        {{--
            The paste preview - PF-206.

            A paste into a range is the one gesture in the grid that
            changes many cells at once, so it is the one that gets a
            count and a confirmation before it commits. The dialog is
            empty markup until the script fills it, and `<dialog>` is
            what gives it a focus trap and Esc for nothing.
        --}}
        <dialog data-pf-grid-paste
                class="rounded-dialog border border-outline bg-surface p-6 text-on-surface shadow-e4 backdrop:bg-background/70">
            <h2 class="text-title font-semibold">{{ __('معاينة اللصق') }}</h2>
            <p data-pf-grid-paste-count class="mt-2 text-body text-on-surface-muted"></p>
            <ul data-pf-grid-paste-sample class="mt-3 max-h-40 overflow-auto text-label text-on-surface-muted"></ul>
            <div class="mt-5 flex items-center gap-3">
                <x-button type="button" data-pf-grid-paste-apply>{{ __('طبِّق') }}</x-button>
                <x-button type="button" variant="outline" data-pf-grid-paste-cancel>{{ __('إلغاء') }}</x-button>
            </div>
        </dialog>
    @endif

    @if ($paginator !== null && $paginator->hasPages())
        {{--
            The paginator is built from the paginator's own methods, not
            `links()`.

            The framework's view spaces its arrows with physical margins,
            so they sit on the wrong side under RTL, and its labels come
            from a `pagination.*` catalog this product does not have - so
            it prints the key. Both are invisible to whoever adds the
            one-liner and obvious to every Arabic reader.

            The page the reader is on is a <span>, not a disabled link: a
            link that goes nowhere is still a tab stop.
        --}}
        <nav aria-label="{{ $caption }}" class="flex flex-wrap items-center justify-end gap-2">
            @if ($paginator->previousPageUrl() === null)
                <span class="inline-flex min-h-11 items-center rounded-button border border-outline px-3 py-1.5 text-label text-on-surface-subtle">
                    {{ __('السابق') }}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   class="inline-flex min-h-11 items-center rounded-button border border-outline-strong px-3 py-1.5 text-label font-medium text-on-surface
                          hover:bg-surface-hover
                          focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    {{ __('السابق') }}
                </a>
            @endif

            @if ($paginator->nextPageUrl() === null)
                <span class="inline-flex min-h-11 items-center rounded-button border border-outline px-3 py-1.5 text-label text-on-surface-subtle">
                    {{ __('التالي') }}
                </span>
            @else
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   class="inline-flex min-h-11 items-center rounded-button border border-outline-strong px-3 py-1.5 text-label font-medium text-on-surface
                          hover:bg-surface-hover
                          focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    {{ __('التالي') }}
                </a>
            @endif
        </nav>
    @endif
</div>
