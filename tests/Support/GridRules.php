<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Throwable;

/**
 * The data grid, its keyboard model, its semantics and the form system -
 * PF-205, PF-206, PF-207, PF-212.
 *
 * ## Why this renders the components instead of reading them
 *
 * Every claim these components make is a property of the HTML a page gets.
 * `aria-rowcount` is the size of the set or it is the size of the window;
 * a cell names its column or it does not; exactly one cell is in the tab
 * order or a 50-row grid is 350 tab stops. None of that is visible in the
 * template, because the template is a loop over a declaration assembled
 * somewhere else and half of it is conditional.
 *
 * So each check renders a real fixture through Blade - the component tags
 * a page writes, not a view path - and reads the result with XPath. A
 * regular expression over the source would pass on a `role="grid"` sitting
 * inside a comment, and it cannot see what two components produce
 * TOGETHER, which is where the interesting failures are: the errors
 * summary linking to the id the field actually rendered, the keyboard
 * layer reading a hook the markup actually writes.
 *
 * ## What it cannot check, said plainly
 *
 * It does not press a key. There is no browser here, so the keyboard model
 * is checked at its two edges: the markup contract it depends on (every
 * `data-pf-*` hook the script reads is rendered, and every hook rendered
 * is read) and the one piece of the script that transforms a value rather
 * than moving focus, which is run in `node`. The behaviour in between -
 * that `ArrowDown` really moves down - needs the browser test PF-206 asks
 * for and a person who has operated it once, which CONTRIBUTING.md
 * requires in the pull request. This file is deliberately not written so
 * as to look like it has done that.
 */
final class GridRules
{
    /**
     * The hooks the keyboard layer and the currency input read.
     *
     * Not a list to maintain: it is parsed out of the script, and compared
     * against what the components render. It is here as a constant only to
     * name the pattern once.
     */
    private const HOOK_PATTERN = '/data-pf-[a-z-]+/';

    /** The script-only controls. Anything else must work without script. */
    private const SCRIPT_ONLY_CONTROLS = [
        'data-pf-grid-copy',
        'data-pf-grid-paste-apply',
        'data-pf-grid-paste-cancel',
    ];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $rules = [
            'the grid is a grid, and every part of it says which part it is' => fn (): array => $this->theStructureIsDeclared(),
            'aria-rowcount is the size of the set, not of the window' => fn (): array => $this->rowCountIsTheWholeSet(),
            'and an unknown total is announced as unknown, not as the window' => fn (): array => $this->unknownTotalsSaySo(),
            'a hidden column keeps its number and loses its cells' => fn (): array => $this->hiddenColumnsKeepTheirNumber(),
            'a sorted column says so in the attribute and in words' => fn (): array => $this->sortIsAnnounced(),
            'every cell names its column' => fn (): array => $this->everyCellNamesItsColumn(),
            'exactly one cell is in the tab order' => fn (): array => $this->oneTabStop(),
            'the result summary is a live region, and takes focus after a filter' => fn (): array => $this->theSummaryIsAnnounced(),
            'a figure column stays a figure column, and nothing re-aligns it' => fn (): array => $this->figuresAreNotRealigned(),
            'nothing the grid renders is a physical direction' => fn (): array => $this->noPhysicalDirections(),
            'no fixture renders the same id twice' => fn (): array => $this->noDuplicateIds(),
            'an editable cell is a real field in a real form' => fn (): array => $this->editingIsAForm(),
            'the footer total is the server\'s, printed as given' => fn (): array => $this->totalsComeFromTheServer(),
            'a group heading is a real row, and its subtotal is the server\'s' => fn (): array => $this->groupsCarryServerSubtotals(),
            'neither the grid nor the keyboard layer does arithmetic on a figure' => fn (): array => $this->theGridDoesNoArithmetic(),
            'the grid costs the same number of queries at 5 rows as at 500' => fn (): array => $this->theQueryBudgetIsFlat(),
            'every hook the script reads is rendered, and every hook rendered is read' => fn (): array => $this->hooksAgree(),
            'every sentence the script can say comes from the translator' => fn (): array => $this->scriptStringsAreTranslated(),
            'the keyboard model declares every key PF-206 names' => fn (): array => $this->everyKeyIsHandled(),
            'a read-only grid does not offer editing' => fn (): array => $this->aReadOnlyGridPromisesNothing(),
            'the no-script path is real: only the named controls need script' => fn (): array => $this->theNoScriptPathIsReal(),
            'the behaviour is inlined once a page, however many grids' => fn (): array => $this->behaviourIsInlinedOnce(),
            'the grid refuses what it cannot render honestly' => fn (): array => $this->theGridRefuses(),
            'empty, loading and error are three states and the grid owns all three' => fn (): array => $this->theThreeStates(),
            'a conditional class reaches the element it was written on' => fn (): array => $this->conditionalClassesSurvive(),
            'every control has a real label, named by for and id' => fn (): array => $this->everyControlHasALabel(),
            'two copies of a field do not share an id' => fn (): array => $this->idsAreDerivedFromTheInstance(),
            'the error summary links to the field it names' => fn (): array => $this->theSummaryLinksToTheField(),
            'an error reaches the reader, and never by colour alone' => fn (): array => $this->errorsAreAnnounced(),
            'the currency input takes what a person pastes' => fn (): array => $this->theCurrencyInputAcceptsPaste(),
            'a fiscal preset arrives resolved, and shows what it resolved to' => fn (): array => $this->presetsArriveResolved(),
            'the form system refuses what it cannot name' => fn (): array => $this->theFormRefuses(),
        ];

        $out = [];

        foreach ($rules as $name => $rule) {
            try {
                $out[$name] = $rule();
            } catch (Throwable $e) {
                $out[$name] = [sprintf(
                    'the check could not run: %s: %s',
                    $e::class,
                    Str::limit((string) preg_replace('/\s+/', ' ', $e->getMessage()), 300),
                )];
            }
        }

        // The one check that runs the script rather than reading it. It
        // renames itself when there is no engine to run it in, because a
        // check that quietly did nothing would read as a pass.
        [$digits, $violations] = $this->digitsAreNormalised();
        $out[$digits] = $violations;

        return $out;
    }

    /* =====================================================================
     |  The fixtures
     |
     |  Written as a page writes them - component tags, a paginator, a
     |  slot of rows - because the thing under test is what a page gets.
     | ================================================================== */

    /** @return list<array{key: string, label: string, figure?: bool, pinned?: bool, rowheader?: bool, hidden?: bool, sortUrl?: string, sort?: string, width?: string, editable?: bool}> */
    private function columns(bool $editable = false): array
    {
        return [
            ['key' => 'number', 'label' => 'الرقم', 'pinned' => true, 'rowheader' => true, 'sortUrl' => '/invoices?sort=number', 'sort' => 'desc'],
            ['key' => 'party', 'label' => 'العميل', 'sortUrl' => '/invoices?sort=party'],
            ['key' => 'notes', 'label' => 'ملاحظات', 'hidden' => true],
            ['key' => 'gross', 'label' => 'الإجمالي', 'figure' => true, 'width' => '10rem', 'editable' => $editable],
        ];
    }

    private function paginator(int $total = 12480, int $page = 3, int $perPage = 50): LengthAwarePaginator
    {
        return new LengthAwarePaginator(['a', 'b'], $total, $perPage, $page, ['path' => '/invoices']);
    }

    /**
     * The read-only grid: two rows, a hidden column, a sort, a total, a
     * footer, a filter form and a saved view.
     */
    private function listGrid(bool $filtered = false): string
    {
        return $this->render(<<<'BLADE'
            <x-grid name="invoices" :caption="$caption" :columns="$columns" :paginator="$paginator"
                    :filtered="$filtered" :totals="$totals" :views="$views" save-url="/views">
                <x-slot:filters>
                    <label for="q">بحث</label>
                    <input id="q" name="q" type="search" dir="auto">
                    <button type="submit">بحث</button>
                </x-slot:filters>

                @foreach ($rows as $index => $row)
                    <x-grid.row :index="$index" :row-key="$row['id']" :selected="false">
                        <x-grid.cell column="number"><a href="/invoices/{{ $row['id'] }}">{{ $row['number'] }}</a></x-grid.cell>
                        <x-grid.cell column="party">{{ $row['party'] }}</x-grid.cell>
                        <x-grid.cell column="notes">{{ $row['notes'] }}</x-grid.cell>
                        <x-grid.cell column="gross" :value="$row['raw']">{{ $row['gross'] }}</x-grid.cell>
                    </x-grid.row>
                @endforeach

                <x-slot:empty>أول فاتورة تُنشَأ من المبيعات.</x-slot:empty>
            </x-grid>
            BLADE, [
            'caption' => 'فواتير هذه الشركة، الأحدث أولًا',
            'columns' => $this->columns(),
            'paginator' => $this->paginator(),
            'filtered' => $filtered,
            'totals' => ['gross' => '9,999,999.00'],
            'views' => [['label' => 'المتأخرة', 'url' => '/invoices?view=overdue', 'current' => true]],
            'rows' => [
                ['id' => 'inv-1', 'number' => 'INV-0001', 'party' => 'شركة الأفق', 'notes' => 'لا شيء', 'gross' => '1,234.56 SAR', 'raw' => '1234.56'],
                ['id' => 'inv-2', 'number' => 'INV-0002', 'party' => 'مؤسسة النهر', 'notes' => 'لا شيء', 'gross' => '7.00 SAR', 'raw' => '7.00'],
            ],
        ]);
    }

    /** The editable grid: the same shape, with a column that is typed into. */
    private function editableGrid(): string
    {
        return $this->render(<<<'BLADE'
            <x-grid name="lines" :caption="$caption" :columns="$columns" :total="2"
                    action="/invoices/1/lines" :submit="'احفظ'">
                @foreach ($rows as $index => $row)
                    <x-grid.row :index="$index" :row-key="$row['id']">
                        <x-grid.cell column="number">{{ $row['number'] }}</x-grid.cell>
                        <x-grid.cell column="party">{{ $row['party'] }}</x-grid.cell>
                        <x-grid.cell column="notes">{{ $row['notes'] }}</x-grid.cell>
                        <x-grid.cell column="gross" :value="$row['raw']" :input="['required' => true]">SAR</x-grid.cell>
                    </x-grid.row>
                @endforeach
                <x-slot:empty>لا سطور بعد.</x-slot:empty>
            </x-grid>
            BLADE, [
            'caption' => 'سطور الفاتورة',
            'columns' => $this->columns(true),
            'rows' => [
                ['id' => 'line-1', 'number' => '1', 'party' => 'صنف', 'notes' => '', 'raw' => '1234.5678'],
                ['id' => 'line-2', 'number' => '2', 'party' => 'صنف آخر', 'notes' => '', 'raw' => '7.0000'],
            ],
        ]);
    }

    private function cursorGrid(): string
    {
        return $this->render(<<<'BLADE'
            <x-grid name="statement" :caption="$caption" :columns="$columns" :paginator="$paginator">
                <x-grid.row :index="0" row-key="mv-1">
                    <x-grid.cell column="number">MV-1</x-grid.cell>
                    <x-grid.cell column="party">البنك</x-grid.cell>
                    <x-grid.cell column="notes">—</x-grid.cell>
                    <x-grid.cell column="gross" value="10.00">10.00</x-grid.cell>
                </x-grid.row>
                <x-slot:empty>لا حركات بعد.</x-slot:empty>
            </x-grid>
            BLADE, [
            'caption' => 'حركات الحساب البنكي',
            'columns' => $this->columns(),
            'paginator' => new CursorPaginator(['a'], 1, null, ['path' => '/statement']),
        ]);
    }

    private function emptyGrid(): string
    {
        return $this->render(<<<'BLADE'
            <x-grid name="invoices" :caption="$caption" :columns="$columns" :total="0">
                <x-slot:empty>أول فاتورة تُنشَأ من المبيعات، أو من عرض سعر قبله العميل.</x-slot:empty>
            </x-grid>
            BLADE, ['caption' => 'الفواتير', 'columns' => $this->columns()]);
    }

    /**
     * Two grids and a currency field in ONE render.
     *
     * `@once` is per response, so two grids rendered separately each carry
     * their own copy of the behaviour and nothing is proved. A page is one
     * render, so the fixture has to be one too.
     */
    private function onePage(): string
    {
        return $this->render(<<<'BLADE'
            <x-grid name="first" caption="الأولى" :columns="$columns">
                <x-grid.row :index="0" row-key="a">
                    <x-grid.cell column="number">1</x-grid.cell>
                    <x-grid.cell column="party">طرف</x-grid.cell>
                    <x-grid.cell column="notes">—</x-grid.cell>
                    <x-grid.cell column="gross" value="1.00">1.00</x-grid.cell>
                </x-grid.row>
                <x-slot:empty>لا شيء.</x-slot:empty>
            </x-grid>

            <x-grid name="second" caption="الثانية" :columns="$columns">
                <x-grid.row :index="0" row-key="b">
                    <x-grid.cell column="number">2</x-grid.cell>
                    <x-grid.cell column="party">طرف آخر</x-grid.cell>
                    <x-grid.cell column="notes">—</x-grid.cell>
                    <x-grid.cell column="gross" value="2.00">2.00</x-grid.cell>
                </x-grid.row>
                <x-slot:empty>لا شيء.</x-slot:empty>
            </x-grid>

            <x-form.currency name="amount" instance="aside" label="المبلغ" currency="SAR" value="1.00"/>
            BLADE, ['columns' => $this->columns()]);
    }

    private function formFixture(): string
    {
        return $this->render(<<<'BLADE'
            <form method="POST" action="/parties">
                <x-form.errors :bag="$bag" instance="party"/>
                <x-form.field name="code" instance="party" :label="$codeLabel" dir="auto"
                              maxlength="24" required :error="$codeError" :hint="$codeHint"/>
                <x-form.field name="country" instance="party" as="select" :label="$countryLabel"
                              :options="['SA' => 'السعودية', 'AE' => 'الإمارات']" value="SA"/>
                <x-form.field name="note" instance="party" as="textarea" :label="$noteLabel" dir="auto"/>
                <x-form.currency name="limit" instance="party" :label="$limitLabel" currency="KWD" :exponent="3" value="1234.567"/>
                <x-form.date-range name="window" instance="party" :legend="$legend" :presets="$presets"
                                   from="2026-01-01" to="2026-03-31"/>
                <button type="submit">حفظ</button>
            </form>
            BLADE, [
            'bag' => new MessageBag(['code' => ['الرمز مستخدم بالفعل.'], 'limit' => ['حدّ الائتمان مطلوب.']]),
            'codeLabel' => 'الرمز',
            'codeError' => 'الرمز مستخدم بالفعل.',
            'codeHint' => 'يظهر على المستندات.',
            'countryLabel' => 'الدولة',
            'noteLabel' => 'ملاحظة',
            'limitLabel' => 'حدّ الائتمان',
            'legend' => 'الفترة',
            'presets' => [
                ['key' => 'last-period', 'label' => 'الفترة السابقة', 'from' => '2026-09-01', 'to' => '2026-09-30', 'current' => true],
                ['key' => 'ytd', 'label' => 'من بداية السنة', 'from' => '2026-04-01', 'to' => '2026-11-18', 'current' => false],
            ],
        ]);
    }

    /* =====================================================================
     |  PF-207 - the semantics
     | ================================================================== */

    /** @return list<string> */
    private function theStructureIsDeclared(): array
    {
        $problems = [];

        foreach (['the list grid' => $this->listGrid(), 'the editable grid' => $this->editableGrid()] as $what => $html) {
            $xpath = $this->xpath($html);

            if ($this->count($xpath, '//table[@role="grid"]') !== 1) {
                $problems[] = sprintf('%s does not render exactly one table with role="grid".', $what);
            }

            /*
             | `role="grid"` REPLACES the table's own semantics, so every
             | part of the structure has to be restated. A browser that
             | honours the role stops exposing the rows and cells it was
             | not told about, and the grid is then reported as having no
             | rows at all - which is a worse outcome than not using the
             | role in the first place.
             */
            foreach ([
                '//table[@role="grid"]//tr[not(@role="row")]' => 'a <tr> with no role="row"',
                '//table[@role="grid"]//td[not(@role)]' => 'a <td> with no role',
                '//table[@role="grid"]//th[not(@role)]' => 'a <th> with no role',
                '//table[@role="grid"]/thead//th[not(@role="columnheader")]' => 'a header cell that is not a columnheader',
                '//table[@role="grid"]//tr[not(@aria-rowindex)]' => 'a row with no aria-rowindex',
            ] as $query => $what_) {
                $found = $this->count($xpath, $query);

                if ($found > 0) {
                    $problems[] = sprintf('%s renders %d × %s.', $what, $found, $what_);
                }
            }

            if ($this->count($xpath, '//table[@role="grid"]/caption') !== 1) {
                $problems[] = sprintf('%s has no <caption>, so the grid has no accessible name.', $what);
            }

            $labelled = $this->attribute($xpath, '//table[@role="grid"]/@aria-labelledby');
            $caption = $this->attribute($xpath, '//table[@role="grid"]/caption/@id');

            if ($labelled === null || $labelled !== $caption) {
                $problems[] = sprintf(
                    '%s does not name itself from its own caption (aria-labelledby="%s", caption id="%s").',
                    $what,
                    (string) $labelled,
                    (string) $caption,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function rowCountIsTheWholeSet(): array
    {
        $problems = [];
        $xpath = $this->xpath($this->listGrid());

        $rowCount = (int) $this->attribute($xpath, '//table[@role="grid"]/@aria-rowcount');
        $rendered = $this->count($xpath, '//tbody/tr[@data-pf-row]');

        /*
         | 12,480 rows, a window of two, and the header row counted.
         |
         | The defect PF-207 names is a grid that reports the window: a
         | screen-reader user is then told the 50 rows in front of them
         | are the whole ledger. So the number has to be the SET, and the
         | only honest way to check it is to render a window that is
         | nothing like the total.
         */
        if ($rowCount !== 12481) {
            $problems[] = sprintf(
                'aria-rowcount is %d for a set of 12,480 with %d rows rendered. It must be the total plus the '.
                'header row (12,481); reporting the window tells a screen-reader user the page is the whole set.',
                $rowCount,
                $rendered,
            );
        }

        if ($rowCount === $rendered || $rowCount === $rendered + 1) {
            $problems[] = 'aria-rowcount is the number of rendered rows, which is the exact defect PF-207 names.';
        }

        // Page 3 of 50 per page: the first row of the window is row 101 of
        // the set, and row 102 of the grid once the header is counted.
        $firstIndex = (int) $this->attribute($xpath, '(//tbody/tr[@data-pf-row])[1]/@aria-rowindex');

        if ($firstIndex !== 102) {
            $problems[] = sprintf(
                'the first row of page 3 carries aria-rowindex="%d". It is row 101 of the set and row 102 of the '.
                'grid; numbering the window from 1 tells the reader they are at the top of the list.',
                $firstIndex,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function unknownTotalsSaySo(): array
    {
        $xpath = $this->xpath($this->cursorGrid());
        $rowCount = $this->attribute($xpath, '//table[@role="grid"]/@aria-rowcount');

        if ($rowCount !== '-1') {
            return [sprintf(
                'a cursor-paginated grid reports aria-rowcount="%s". A cursor paginator does not know the total, '.
                'and ARIA spells unknown as -1: any other number is a count the grid invented.',
                (string) $rowCount,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function hiddenColumnsKeepTheirNumber(): array
    {
        $problems = [];
        $html = $this->listGrid();
        $xpath = $this->xpath($html);

        $colCount = (int) $this->attribute($xpath, '//table[@role="grid"]/@aria-colcount');

        if ($colCount !== 4) {
            $problems[] = sprintf(
                'aria-colcount is %d where four columns are declared and one is hidden. The count is the full '.
                'set: a reader told there are three columns cannot know one was turned off.',
                $colCount,
            );
        }

        $indexes = [];

        foreach ($this->nodes($xpath, '(//tbody/tr[@data-pf-row])[1]/*[@aria-colindex]') as $cell) {
            $indexes[] = (int) $cell->getAttribute('aria-colindex');
        }

        if ($indexes !== [1, 2, 4]) {
            $problems[] = sprintf(
                'the rendered cells of a row carry aria-colindex %s. The hidden third column must leave a gap: '.
                'renumbering 1,2,3 says the fourth column is the third one.',
                implode(',', $indexes) === '' ? '(none)' : implode(',', $indexes),
            );
        }

        /*
         | Out of the TABLE, not out of the page.
         |
         | The hidden column is still listed in the visibility form, with
         | its box unticked - which is the only way anybody turns it back
         | on. A check that scanned the whole response would demand the
         | column be unmentionable and take the control with it.
         */
        if (str_contains($this->text($xpath, '//thead'), 'ملاحظات')) {
            $problems[] = 'the hidden column\'s heading is still in the table head.';
        }

        if ($this->count($xpath, '//form//input[@value="notes"]') !== 1) {
            $problems[] = 'the hidden column is not offered in the visibility form, so nothing can turn it back on.';
        }

        if ($this->attribute($xpath, '//form//input[@value="notes"]/@checked') !== null) {
            $problems[] = 'the hidden column is ticked in the visibility form, which says it is being shown.';
        }

        if ($this->count($xpath, '//tbody//*[@data-pf-cell="notes"]') !== 0) {
            $problems[] = 'the hidden column\'s cells are still rendered, so hiding a column only hid its heading.';
        }

        // And the page's row loop said nothing about visibility: the
        // fixture writes all four cells. If that had to change, column
        // visibility would be a decision every page re-implements.
        if (! str_contains($this->listGrid(), 'invoices_cols[]')) {
            $problems[] = 'there is no column-visibility control, so a hidden column can only be hidden in code.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function sortIsAnnounced(): array
    {
        $problems = [];
        $xpath = $this->xpath($this->listGrid());

        $sorted = $this->attribute($xpath, '//th[@id="grid-invoices-col-number"]/@aria-sort');
        $sortable = $this->attribute($xpath, '//th[@id="grid-invoices-col-party"]/@aria-sort');
        $plain = $this->attribute($xpath, '//th[@id="grid-invoices-col-gross"]/@aria-sort');

        if ($sorted !== 'descending') {
            $problems[] = sprintf('the sorted column reports aria-sort="%s", not "descending".', (string) $sorted);
        }

        if ($sortable !== 'none') {
            $problems[] = sprintf('a sortable column that is not sorted reports aria-sort="%s", not "none".', (string) $sortable);
        }

        if ($plain !== null) {
            $problems[] = 'a column that cannot be sorted carries aria-sort, which offers a reader an action that does not exist.';
        }

        /*
         | The direction is in words as well as in the glyph.
         |
         | A caret is a shape and a colour. It survives neither a
         | printout, nor forced-colours mode, nor a screen reader - and
         | "sorted by" is exactly the state somebody checks before
         | reading a column of figures.
         */
        if ($this->count($xpath, '//th[@id="grid-invoices-col-number"]//span[@class="sr-only"]') === 0) {
            $problems[] = 'the sorted column says its direction with a glyph only; nothing says it in words.';
        }

        if ($this->count($xpath, '//th[@id="grid-invoices-col-number"]//a[@href]') !== 1) {
            $problems[] = 'sorting is not a link, so it does not work without script and the sorted list has no URL.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function everyCellNamesItsColumn(): array
    {
        $problems = [];

        foreach (['the list grid' => $this->listGrid(), 'the editable grid' => $this->editableGrid()] as $what => $html) {
            $xpath = $this->xpath($html);
            $headers = [];

            foreach ($this->nodes($xpath, '//thead//th[@id]') as $header) {
                $headers[$header->getAttribute('id')] = trim($header->textContent);
            }

            foreach ($this->nodes($xpath, '//tbody//*[@data-pf-cell]') as $cell) {
                $named = $cell->getAttribute('headers');

                if ($named === '') {
                    $problems[] = sprintf(
                        '%s: the cell in column "%s" names no column. A bare "1,234.56" read on its own is '.
                        'meaningless, and the column name is the missing half of it.',
                        $what,
                        $cell->getAttribute('data-pf-cell'),
                    );

                    continue;
                }

                if (! array_key_exists($named, $headers)) {
                    $problems[] = sprintf(
                        '%s: the cell in column "%s" points headers="%s" at an id no header carries, so the '.
                        'reference resolves to nothing and reports nothing.',
                        $what,
                        $cell->getAttribute('data-pf-cell'),
                        $named,
                    );

                    continue;
                }

                if ($headers[$named] === '') {
                    $problems[] = sprintf('%s: the header "%s" a cell points at has no text.', $what, $named);
                }
            }

            /*
             | And the name is NOT carried by an sr-only prefix in the
             | cell. That is the obvious alternative and it ends up in
             | the clipboard: the TSV this grid copies for a spreadsheet
             | would carry the column name in front of every figure.
             */
            if ($this->count($xpath, '//tbody//*[@data-pf-cell]//*[contains(@class, "sr-only")]') > 0) {
                $problems[] = sprintf(
                    '%s puts screen-reader-only text inside a cell. It is read, it is copied, and the pasted '.
                    'column in the spreadsheet is then text rather than numbers.',
                    $what,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function oneTabStop(): array
    {
        $problems = [];

        foreach (['the list grid' => $this->listGrid(), 'the editable grid' => $this->editableGrid()] as $what => $html) {
            $xpath = $this->xpath($html);
            $stops = $this->count($xpath, '//*[@data-pf-cell][@tabindex="0"]');
            $cells = $this->count($xpath, '//*[@data-pf-cell]');
            $parked = $this->count($xpath, '//*[@data-pf-cell][@tabindex="-1"]');

            if ($stops !== 1) {
                $problems[] = sprintf(
                    '%s has %d cells in the tab order out of %d. A grid is one composite widget: %d tab stops '.
                    'is what a user meets between the filter and the paginator.',
                    $what,
                    $stops,
                    $cells,
                    $stops,
                );
            }

            if ($stops + $parked !== $cells) {
                $problems[] = sprintf(
                    '%s leaves %d cells with no tabindex at all, so the roving tab stop cannot reach them.',
                    $what,
                    $cells - $stops - $parked,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theSummaryIsAnnounced(): array
    {
        $problems = [];
        $plain = $this->xpath($this->listGrid());
        $filtered = $this->xpath($this->listGrid(true));

        $region = '//*[@id="grid-invoices-summary"]';

        if ($this->attribute($plain, $region.'/@role') !== 'status') {
            $problems[] = 'the result summary is not role="status", so the keyboard layer has nowhere to announce.';
        }

        if ($this->attribute($plain, $region.'/@aria-live') !== 'polite') {
            $problems[] = 'the result summary is not a polite live region.';
        }

        $text = (string) preg_replace('/\s+/u', ' ', trim($this->text($plain, $region)));
        $paginator = $this->paginator();

        /*
         | The numbers are the paginator's own, and the first of them is
         | not 1.
         |
         | Page three of a 12,480-row list shows rows 101 onward, and a
         | summary that counts the window from 1 is the same lie as an
         | `aria-rowcount` of 50: it tells the reader where they are in
         | the page rather than in the set.
         */
        foreach ([(string) $paginator->firstItem(), (string) $paginator->lastItem(), '12480'] as $number) {
            if (! str_contains($text, $number)) {
                $problems[] = sprintf('the summary ("%s") does not say %s, which the paginator reports.', $text, $number);
            }
        }

        if (preg_match('/(?<!\d)1\s*[–-]/u', $text) === 1) {
            $problems[] = sprintf('the summary ("%s") numbers the window from 1 rather than from its place in the set.', $text);
        }

        /*
         | A live region does not announce what it loaded with.
         |
         | So the filter result is reached the only way a page without
         | script can reach it: focus. Without this, "announcing the
         | result of a filter change" is a claim nothing keeps.
         */
        if ($this->attribute($filtered, $region.'/@autofocus') === null) {
            $problems[] = 'after a filter the summary does not take focus, and a live region announces nothing it '.
                'arrived with - so the result of the filter reaches a screen-reader user through nothing at all.';
        }

        if ($this->attribute($filtered, $region.'/@tabindex') !== '-1') {
            $problems[] = 'the summary is not focusable, so the autofocus lands nowhere.';
        }

        if ($this->attribute($plain, $region.'/@autofocus') !== null) {
            $problems[] = 'the summary steals focus on a page nobody filtered, which moves a reader away from the '.
                'top of the page on every ordinary load.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function figuresAreNotRealigned(): array
    {
        $problems = [];
        $xpath = $this->xpath($this->listGrid());

        foreach (['//th[@id="grid-invoices-col-gross"]', '//tbody//*[@data-pf-cell="gross"]'] as $query) {
            foreach ($this->nodes($xpath, $query) as $cell) {
                if (! $cell->hasAttribute('data-figure')) {
                    $problems[] = sprintf(
                        'the figure column renders <%s> without data-figure, so it loses tabular numerals and the '.
                        'thousands places stop lining up.',
                        $cell->nodeName,
                    );
                }

                $class = $cell->getAttribute('class');

                foreach (['text-end', 'text-start', 'text-right', 'text-left'] as $utility) {
                    if (preg_match('/(?<![\w-])'.preg_quote($utility, '/').'(?![\w-])/', $class) === 1) {
                        $problems[] = sprintf(
                            'the figure column carries "%s". `.figure` already aligns it, and the reason it does '.
                            'it in CSS is that digits do not flip: a logical alignment left-aligns every figure in '.
                            'Arabic and destroys the thousands-place scan.',
                            $utility,
                        );
                    }
                }
            }
        }

        foreach ($this->nodes($xpath, '//tbody//*[@data-pf-cell="party"]') as $cell) {
            if (! str_contains($cell->getAttribute('class'), 'text-start')) {
                $problems[] = 'a text column is not start-aligned, so it reads from the wrong edge in one of the two languages.';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function noPhysicalDirections(): array
    {
        $problems = [];
        $fixtures = [
            'the list grid' => $this->listGrid(),
            'the editable grid' => $this->editableGrid(),
            'the empty grid' => $this->emptyGrid(),
            'the form' => $this->formFixture(),
        ];

        foreach ($fixtures as $what => $html) {
            // The inlined script is JavaScript, not markup, and it is
            // checked on its own terms elsewhere in this file.
            $markup = (string) preg_replace('#<script id="pf-ui-behaviour">.*?</script>#s', '', $html);

            if (preg_match_all('/(?<![\w-])(?:[\w-]+:)*(ml-|mr-|pl-|pr-|text-left|text-right|border-l-|border-r-|left-|right-)/', $markup, $m) === 1
                || isset($m[0]) && $m[0] !== []) {
                foreach (array_unique($m[0]) as $match) {
                    $problems[] = sprintf(
                        '%s renders "%s". The interface is Arabic first, so a physical direction is the wrong side '.
                        'half the time; use ms-, me-, ps-, pe-, text-start, text-end, border-s, border-e, start-, end-.',
                        $what,
                        $match,
                    );
                }
            }

            if (preg_match('/style="[^"]*(?:margin|padding|border)-(?:left|right)\s*:/i', $markup) === 1) {
                $problems[] = sprintf('%s writes a physical property into a style attribute.', $what);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function noDuplicateIds(): array
    {
        $problems = [];

        $fixtures = [
            'the list grid' => $this->listGrid(),
            'the editable grid' => $this->editableGrid(),
            'the form' => $this->formFixture(),
            // Two grids and an amount in ONE render: the case the instance
            // key exists for.
            'two grids on one page' => $this->onePage(),
        ];

        foreach ($fixtures as $what => $html) {
            $seen = [];

            foreach ($this->nodes($this->xpath($html), '//*[@id]') as $node) {
                $id = $node->getAttribute('id');
                $seen[$id] = ($seen[$id] ?? 0) + 1;
            }

            foreach ($seen as $id => $times) {
                if ($times > 1) {
                    $problems[] = sprintf(
                        '%s renders id="%s" %d times. A duplicate id points a <label for>, a headers reference '.
                        'or an error link at whichever copy the browser saw first, and nothing looks broken.',
                        $what,
                        $id,
                        $times,
                    );
                }
            }
        }

        return $problems;
    }

    /* =====================================================================
     |  PF-205 / PF-206 - editing, totals, the budget
     | ================================================================== */

    /** @return list<string> */
    private function editingIsAForm(): array
    {
        $problems = [];
        $html = $this->editableGrid();
        $xpath = $this->xpath($html);

        $form = '//form[@action="/invoices/1/lines"]';

        if ($this->count($xpath, $form) !== 1) {
            return ['the editable grid renders no form, so there is nowhere for a typed figure to go.'];
        }

        if (strtoupper((string) $this->attribute($xpath, $form.'/@method')) !== 'POST') {
            $problems[] = 'the editing form is not a POST.';
        }

        if ($this->count($xpath, $form.'//input[@name="_token"]') !== 1) {
            $problems[] = 'the editing form carries no CSRF token, so every submission is rejected.';
        }

        if ($this->count($xpath, $form.'//button[@type="submit"]') !== 1) {
            $problems[] = 'the editing form has no submit button, so the only way to save is a key nobody is told about.';
        }

        $inputs = $this->nodes($xpath, $form.'//*[@data-pf-editable]//input');

        if (count($inputs) !== 2) {
            $problems[] = sprintf('two editable cells rendered %d inputs.', count($inputs));
        }

        foreach ($inputs as $input) {
            $name = $input->getAttribute('name');

            if (preg_match('/\Alines\[line-\d\]\[gross\]\z/', $name) !== 1) {
                $problems[] = sprintf(
                    'an editable cell is named "%s". The name has to carry the record and the column, or the '.
                    'server receives a figure it cannot attribute to anything.',
                    $name,
                );
            }

            /*
             | The server's value is the input's ATTRIBUTE, which is what
             | makes Esc revert to it: `defaultValue` is the attribute,
             | and a value a script wrote is not a value a script can
             | undo to.
             */
            if ($input->getAttribute('value') === '') {
                $problems[] = sprintf('the editable cell "%s" renders no value, so Esc has nothing to revert to.', $name);
            }

            if ($input->getAttribute('type') === 'number') {
                $problems[] = 'an editable cell is type="number", which drops a pasted "1,234.56" on some browsers '.
                    'and steps a monetary value by one whole unit.';
            }

            if ($input->getAttribute('aria-labelledby') === '') {
                $problems[] = sprintf('the editable cell "%s" has no accessible name.', $name);
            }

            // Four decimals in, four decimals out: nothing on the way to
            // the field may round what the ledger stores.
            if ($name === 'lines[line-1][gross]' && $input->getAttribute('value') !== '1234.5678') {
                $problems[] = sprintf(
                    'the editable cell rendered "%s" for a stored 1234.5678. A grid that rounds on the way IN '.
                    'submits the rounded figure back.',
                    $input->getAttribute('value'),
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function totalsComeFromTheServer(): array
    {
        /*
         | The fixture's footer disagrees with its rows on purpose.
         |
         | 1,234.56 and 7.00 do not add up to 9,999,999.00, and the grid
         | must print what the server gave it anyway. If the component
         | ever starts summing the loaded page, the footer will quietly
         | become the page's total rather than the set's - a figure that
         | is wrong and that adds up, which is the one kind of wrong a
         | reader cannot see.
         */
        $xpath = $this->xpath($this->listGrid());
        $footer = trim($this->text($xpath, '//tfoot'));

        if (! str_contains($footer, '9,999,999.00')) {
            return [sprintf(
                'the footer reads "%s" where the server gave 9,999,999.00. The aggregate is the server\'s: a '.
                'footer summed from the loaded page is a total that disagrees with the set and still adds up.',
                (string) preg_replace('/\s+/u', ' ', $footer),
            )];
        }

        return [];
    }

    /**
     * A grouped grid - PF-205.
     *
     * The fixture's subtotal disagrees with the rows under it on purpose,
     * for the same reason the footer's does: a subtotal summed from the
     * loaded page agrees with what is on screen and disagrees with the
     * group, and a grouped report whose groups do not add up to its total
     * is reconciled by hand.
     *
     * @return list<string>
     */
    private function groupsCarryServerSubtotals(): array
    {
        $problems = [];
        $html = $this->render(<<<'BLADE'
            <x-grid name="ledger" caption="أرصدة مجمَّعة" :columns="$columns" :total="3">
                <x-grid.group :index="0" label="العمولات" :totals="['gross' => '8,888.88']"/>
                <x-grid.row :index="1" row-key="l-1">
                    <x-grid.cell column="number">1</x-grid.cell>
                    <x-grid.cell column="party">طرف</x-grid.cell>
                    <x-grid.cell column="notes">—</x-grid.cell>
                    <x-grid.cell column="gross" value="1.00">1.00</x-grid.cell>
                </x-grid.row>
                <x-slot:empty>لا شيء.</x-slot:empty>
            </x-grid>
            BLADE, ['columns' => $this->columns()]);

        $xpath = $this->xpath($html);
        $group = '//tr[@data-pf-row="group-0"]';

        if ($this->count($xpath, $group) !== 1) {
            return ['a group heading does not render as a row of the grid.'];
        }

        if ($this->attribute($xpath, $group.'/@role') !== 'row' || $this->attribute($xpath, $group.'/@aria-rowindex') === null) {
            $problems[] = 'the group heading is not a row with a row number, so a screen reader cannot place it '.
                'among the rows it groups.';
        }

        $header = $this->text($xpath, $group.'/th[@role="rowheader"]');

        if (trim($header) !== 'العمولات') {
            $problems[] = sprintf('the group heading reads "%s" rather than its label.', trim($header));
        }

        $subtotal = trim($this->text($xpath, $group.'/td[@data-pf-cell="gross"]'));

        if ($subtotal !== '8,888.88') {
            $problems[] = sprintf(
                'the group subtotal reads "%s" where the server gave 8,888.88. A subtotal computed from the '.
                'loaded page agrees with the rows on screen and disagrees with the group.',
                $subtotal,
            );
        }

        $named = (string) $this->attribute($xpath, $group.'/td[@data-pf-cell="gross"]/@headers');

        foreach (explode(' ', $named) as $id) {
            if ($id === '' || $this->count($xpath, sprintf('//*[@id="%s"]', $id)) === 0) {
                $problems[] = sprintf('the subtotal cell names "%s", which nothing on the page has.', $id);
            }
        }

        if (! str_contains($named, 'col-gross') || ! str_contains($named, 'group-0')) {
            $problems[] = sprintf(
                'the subtotal cell names "%s"; it has to name its column AND its group, or "8,888.88" is read '.
                'with nothing to say what it is the total of.',
                $named,
            );
        }

        // The cells of a group row line up with every other row's: a
        // colspan here moves every column after it out of step with the
        // keyboard model.
        $cells = $this->count($xpath, $group.'/*[@data-pf-cell]');

        if ($cells !== 3) {
            $problems[] = sprintf('the group row renders %d cells where its rows render 3.', $cells);
        }

        if ($this->count($xpath, $group.'/*[@colspan]') !== 0) {
            $problems[] = 'the group row uses a colspan, which takes its cells out of step with the data rows.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theGridDoesNoArithmetic(): array
    {
        $problems = [];

        $forbidden = [
            'Money::' => 'formats or computes money',
            'number_format' => 'formats a number',
            'array_sum' => 'sums a column',
            '->sum(' => 'sums a column',
            'round(' => 'rounds a figure',
        ];

        /*
         | And the script carries figures without computing them.
         |
         | `toFixed`, `Math.round` and `parseFloat` are how a value gets
         | rounded on its way into a field, and a paste handler that
         | rounds is the same defect as a grid that formats: the figure
         | submitted back is the rounded one, it is plausible, and nothing
         | reports it. `Math.floor` is allowed and is used on pixels.
         |
         | This one is a prohibition on the SOURCE rather than a
         | measurement, and deliberately so: the digit normaliser is run
         | in node by the check below, but the paste path is wiring around
         | a DOM this file cannot build - so what can be asserted about it
         | is that it contains no arithmetic to be wrong.
         */
        $script = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', $this->script());

        foreach (['toFixed' => 'rounds a figure', 'Math.round' => 'rounds a figure', 'parseFloat' => 'parses a figure as a float'] as $needle => $what) {
            if (str_contains($script, $needle)) {
                $problems[] = sprintf(
                    'resources/js/enhancements.js %s ("%s"). A figure here is carried, never computed: the '.
                    'server owns rounding, and IEEE 754 cannot represent 0.1.',
                    $what,
                    $needle,
                );
            }
        }

        foreach ([
            'resources/views/components/grid.blade.php',
            'resources/views/components/grid/row.blade.php',
            'resources/views/components/grid/cell.blade.php',
        ] as $file) {
            $source = (string) file_get_contents(base_path($file));
            // The prose explains why none of this is here, so the prose
            // is not scanned - a check that read its own explanation
            // would report the sentence documenting the rule.
            $source = (string) preg_replace('/\{\{--.*?--\}\}|\/\*.*?\*\//su', '', $source);

            foreach ($forbidden as $needle => $what) {
                if (str_contains($source, $needle)) {
                    $problems[] = sprintf(
                        '%s %s ("%s"). Presentation of a figure happens once, in the page that knows the '.
                        'currency and its exponent; a second implementation in the grid will disagree with it.',
                        $file,
                        $what,
                        $needle,
                    );
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theQueryBudgetIsFlat(): array
    {
        $counted = 0;

        /*
         | Counted through the event, not a query log.
         |
         | `DB::listen` registers on the dispatcher and needs no
         | connection, so this check runs with no database at all - which
         | it has to, because the components it renders need none.
         */
        DB::listen(static function () use (&$counted): void {
            $counted++;
        });

        $measure = function (int $rows) use (&$counted): int {
            $counted = 0;
            $this->gridOf($rows);

            return $counted;
        };

        $five = $measure(5);
        $fiveHundred = $measure(500);

        if ($five !== $fiveHundred) {
            return [sprintf(
                'the grid cost %d queries at 5 rows and %d at 500. A per-row query is invisible at the size '.
                'somebody develops against and is the page that times out at month end.',
                $five,
                $fiveHundred,
            )];
        }

        if ($five !== 0) {
            return [sprintf(
                'the grid issued %d queries while rendering rows it was handed. It is given its data; a query '.
                'from inside the component is a query no page can see or budget for.',
                $five,
            )];
        }

        return [];
    }

    private function gridOf(int $rows): string
    {
        return $this->render(<<<'BLADE'
            <x-grid name="budget" :caption="$caption" :columns="$columns" :total="50000">
                @foreach (range(1, $rows) as $index)
                    <x-grid.row :index="$index - 1" :row-key="'r-'.$index">
                        <x-grid.cell column="number">{{ $index }}</x-grid.cell>
                        <x-grid.cell column="party">طرف</x-grid.cell>
                        <x-grid.cell column="notes">—</x-grid.cell>
                        <x-grid.cell column="gross" value="1.00">1.00</x-grid.cell>
                    </x-grid.row>
                @endforeach
                <x-slot:empty>لا شيء.</x-slot:empty>
            </x-grid>
            BLADE, ['caption' => 'الميزانية', 'columns' => $this->columns(), 'rows' => $rows]);
    }

    /* =====================================================================
     |  PF-206 - the keyboard model, at its two edges
     | ================================================================== */

    /** @return list<string> */
    private function hooksAgree(): array
    {
        $problems = [];
        $script = $this->script();

        preg_match_all(self::HOOK_PATTERN, $script, $inScript);
        $read = array_values(array_unique($inScript[0]));

        $rendered = [];

        foreach ([$this->listGrid(), $this->editableGrid(), $this->formFixture()] as $html) {
            $markup = (string) preg_replace('#<script id="pf-ui-behaviour">.*?</script>#s', '', $html);

            foreach ($this->nodes($this->xpath($markup), '//*') as $node) {
                foreach ($this->attributesOf($node) as $attribute) {
                    if (str_starts_with($attribute, 'data-pf-')) {
                        $rendered[$attribute] = true;
                    }
                }
            }
        }

        $rendered = array_keys($rendered);

        /*
         | The two-way comparison is the whole point.
         |
         | There is no browser here, so "the arrow keys work" cannot be
         | asserted. What CAN be asserted is that the contract between
         | the markup and the script is whole in both directions: a hook
         | the script reads and nothing renders is a behaviour that is
         | silently dead, and a hook rendered that nothing reads is a
         | behaviour somebody removed and left the markup for. Both have
         | exactly the same symptom - a key that does nothing - and
         | neither shows up in any other check.
         */
        foreach (array_diff($read, $rendered) as $orphan) {
            $problems[] = sprintf(
                'the script reads [%s] and no component renders it, so whatever it drives is dead markup - and '.
                'the symptom is a key that does nothing.',
                $orphan,
            );
        }

        foreach (array_diff($rendered, $read) as $orphan) {
            $problems[] = sprintf(
                'the components render [%s] and the script never reads it, so the markup is carrying a hook for '.
                'a behaviour that is gone.',
                $orphan,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function scriptStringsAreTranslated(): array
    {
        $problems = [];
        $script = $this->script();

        // Every key the script can announce...
        preg_match_all("/say\('([a-zA-Z]+)'/", $script, $used);
        // ...against the ones the partial renders through the translator.
        preg_match_all("/'([a-zA-Z]+)' => __\(/", (string) file_get_contents(base_path('resources/views/partials/enhancements.blade.php')), $given);

        foreach (array_diff(array_unique($used[1]), $given[1]) as $missing) {
            $problems[] = sprintf(
                'the script announces "%s" and the partial renders no translated string for it, so the '.
                'announcement is an empty live region: nothing is said and nothing errors.',
                $missing,
            );
        }

        foreach (array_diff($given[1], array_unique($used[1])) as $unused) {
            $problems[] = sprintf('the partial renders the string "%s", which the script never says.', $unused);
        }

        /*
         | And nothing the user HEARS is written in the script.
         |
         | Scanning the file for sentence-shaped literals was the first
         | version of this and it was worse than nothing: `'use strict'`
         | is a sentence by any regular expression, and a pattern loose
         | enough to find a real one ran across a regex literal and
         | reported half the file. So the invariant is narrow and exact
         | instead - every announcement goes through `say()`, and `say()`
         | can only return what the partial translated.
         */
        if (preg_match_all('/announce\(([^;]*?)\);/s', $script, $calls, PREG_SET_ORDER)) {
            foreach ($calls as $call) {
                $argument = trim($call[1]);

                if (str_contains($argument, 'say(') || $argument === "''" || preg_match('/\A[A-Za-z_$][\w.$]*\z/', $argument) === 1) {
                    continue;
                }

                $problems[] = sprintf(
                    'the script announces %s, which does not come from say() - so it is a string the '.
                    'translator never saw, read aloud in an Arabic interface.',
                    Str::limit((string) preg_replace('/\s+/', ' ', $argument), 80),
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function everyKeyIsHandled(): array
    {
        /*
         | The comments are stripped first.
         |
         | The first version of this scanned the whole file, and the file
         | explains in prose why the inline direction is READ rather than
         | assumed - so replacing `getComputedStyle(table).direction` with
         | `true`, which mirrors every arrow key in Arabic, left the check
         | passing on the sentence documenting the rule. A check satisfied
         | by its own explanation is worse than no check.
         */
        $script = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', $this->script());
        $problems = [];

        /*
         | A DECLARATION check, and said so on purpose.
         |
         | It asserts the script handles each key PF-206 names - not that
         | the handler is right. Pressing the key needs the browser test
         | PF-206 asks for; this is the part that catches the handler
         | somebody deleted in a refactor, which is the failure that
         | otherwise ships unnoticed because nothing else mentions it.
         */
        $required = [
            "'ArrowUp'" => 'arrow navigation up',
            "'ArrowDown'" => 'arrow navigation down',
            "'ArrowLeft'" => 'arrow navigation along the inline axis',
            "'ArrowRight'" => 'arrow navigation along the inline axis',
            "'Home'" => 'Home, to the start of the row',
            "'End'" => 'End, to the end of the row',
            "'PageUp'" => 'PageUp',
            "'PageDown'" => 'PageDown',
            "'Enter'" => 'Enter, which commits and moves down',
            "'Tab'" => 'Tab, which commits and moves along',
            "'Escape'" => 'Esc, which reverts the cell',
            'ctrlKey' => 'Ctrl, for the to-bounds moves, copy and undo',
            'shiftKey' => 'Shift, for range selection',
            "'paste'" => 'paste from a spreadsheet',
            'defaultValue' => 'the value Esc reverts to',
            'getComputedStyle' => 'the inline direction, read rather than assumed',
            '.direction' => 'the inline direction, read rather than assumed',
        ];

        foreach ($required as $needle => $what) {
            if (! str_contains($script, $needle)) {
                $problems[] = sprintf('the keyboard layer handles no %s (%s is nowhere in it).', $what, $needle);
            }
        }

        // Ctrl+C and Ctrl+Z, each with the letter an Arabic layout
        // produces on the same physical key - `event.key` is the
        // character, not the position.
        foreach (["'c'" => 'copy', "'z'" => 'undo'] as $letter => $what) {
            if (! str_contains($script, $letter)) {
                $problems[] = sprintf('there is no Ctrl+%s handler, so %s has no keyboard equivalent.', trim($letter, "'"), $what);
            }
        }

        if (! str_contains($script, 'setData') || ! str_contains($script, "'\\t'")) {
            $problems[] = 'nothing writes tab-separated text to the clipboard, so a copied range does not paste '.
                'into a spreadsheet as columns.';
        }

        return $problems;
    }

    /**
     * The grid's own description tells the truth about the grid.
     *
     * A read-only list that says "type in a cell to begin editing" sends a
     * screen-reader user hunting for a behaviour that is not there, and
     * nothing tells them it never was. The description is also where the
     * keyboard model is DISCOVERED: a shortcut nobody is told about is a
     * shortcut nobody uses, which is why it is the table's
     * aria-describedby and not a tooltip.
     *
     * @return list<string>
     */
    private function aReadOnlyGridPromisesNothing(): array
    {
        $problems = [];

        foreach ([
            'the list grid' => [$this->listGrid(), false],
            'the editable grid' => [$this->editableGrid(), true],
        ] as $what => [$html, $editable]) {
            $xpath = $this->xpath($html);
            $described = (string) $this->attribute($xpath, '//table[@role="grid"]/@aria-describedby');

            if ($described === '') {
                $problems[] = sprintf('%s describes itself with nothing, so its keyboard model is undiscoverable.', $what);

                continue;
            }

            $text = $this->text($xpath, sprintf('//*[@id="%s"]', $described));

            if (trim($text) === '') {
                $problems[] = sprintf('%s points aria-describedby at an element with no text.', $what);

                continue;
            }

            $offersEditing = str_contains($text, 'التعديل');

            if ($editable && ! $offersEditing) {
                $problems[] = 'the editable grid does not say that typing in a cell begins editing, so the one '.
                    'behaviour a finance user needs most is undiscoverable.';
            }

            if (! $editable && $offersEditing) {
                $problems[] = 'the read-only grid offers editing in its description. Nothing in it takes a '.
                    'value, so a reader is sent looking for a behaviour that is not there.';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theNoScriptPathIsReal(): array
    {
        $problems = [];

        foreach ([
            'the list grid' => $this->listGrid(),
            'the editable grid' => $this->editableGrid(),
        ] as $what => $html) {
            $markup = (string) preg_replace('#<script id="pf-ui-behaviour">.*?</script>#s', '', $html);
            $xpath = $this->xpath($markup);

            foreach ($this->nodes($xpath, '//a') as $link) {
                if ($link->getAttribute('href') === '') {
                    $problems[] = sprintf('%s renders an <a> with no href, which is a tab stop that goes nowhere.', $what);
                }
            }

            foreach ($this->nodes($xpath, '//form') as $form) {
                if ($form->getAttribute('method') === '') {
                    $problems[] = sprintf('%s renders a form with no method.', $what);
                }
            }

            /*
             | Every control that needs script is named here.
             |
             | This is the check that keeps the no-script path real: a new
             | `<button type="button">` is a control that does nothing
             | without JavaScript, and adding one has to be a decision
             | somebody writes down rather than a line in a template. The
             | three that are allowed are conveniences over the keyboard
             | model, and the grid is fully usable without them.
             */
            foreach ($this->nodes($xpath, '//button[@type="button"]') as $button) {
                $named = array_intersect(self::SCRIPT_ONLY_CONTROLS, $this->attributesOf($button));

                if ($named === []) {
                    $problems[] = sprintf(
                        '%s renders a <button type="button"> that is not one of the declared script-only '.
                        'controls (%s). Without script it does nothing at all, so either it needs a no-script '.
                        'equivalent or it belongs on that list with a reason.',
                        $what,
                        implode(', ', self::SCRIPT_ONLY_CONTROLS),
                    );
                }
            }

            foreach ($this->nodes($xpath, '//*[@onclick or @onchange or @onsubmit or @onkeydown]') as $node) {
                $problems[] = sprintf('%s writes an inline event handler on <%s>.', $what, $node->nodeName);
            }
        }

        // The editable grid's fields, the thing a user types into, are
        // plain inputs in a plain form - so with no script at all the
        // screen is still a working editor.
        $xpath = $this->xpath($this->editableGrid());

        foreach ($this->nodes($xpath, '//*[@data-pf-editable]') as $cell) {
            if ($this->count($xpath, './/input[@name]', $cell) !== 1) {
                $problems[] = 'an editable cell holds no named <input>, so with scripting off it cannot be typed into.';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function behaviourIsInlinedOnce(): array
    {
        $problems = [];
        $page = $this->onePage();
        $copies = substr_count($page, 'id="pf-ui-behaviour"');

        if ($copies !== 1) {
            $problems[] = sprintf(
                'a page with two grids and a currency field inlines the behaviour %d times. It is the same '.
                'file every time, so each extra copy is bytes on every response for nothing.',
                $copies,
            );
        }

        if (! str_contains($page, 'window.pfUiStrings')) {
            $problems[] = 'the inlined behaviour carries no strings, so every announcement it makes is empty.';
        }

        if (! preg_match('/window\.pfUiStrings = \{.*?\p{Arabic}/u', $page)) {
            $problems[] = 'the strings the script was given are not the translated ones.';
        }

        if (preg_match('#</script#i', (string) json_encode(['x' => '</script>'], JSON_HEX_TAG)) === 1) {
            $problems[] = 'the strings are not escaped for a <script> context.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theGridRefuses(): array
    {
        $cases = [
            'an editable column with nowhere to submit' => [
                '<x-grid name="g" caption="ج" :columns="$columns"><x-grid.row :index="0" row-key="1">'
                .'<x-grid.cell column="a" value="1"/></x-grid.row><x-slot:empty>لا شيء</x-slot:empty></x-grid>',
                ['columns' => [['key' => 'a', 'label' => 'أ', 'editable' => true]]],
                'no `action`',
            ],
            'no rows and no words about the first one' => [
                '<x-grid name="g" caption="ج" :columns="$columns"></x-grid>',
                ['columns' => [['key' => 'a', 'label' => 'أ']]],
                'what produces the first row',
            ],
            'a window past the cap' => [
                '<x-grid name="g" caption="ج" :columns="$columns">@foreach (range(1, 501) as $i)'
                .'<x-grid.row :index="$i - 1" :row-key="$i"><x-grid.cell column="a">x</x-grid.cell></x-grid.row>'
                .'@endforeach<x-slot:empty>لا شيء</x-slot:empty></x-grid>',
                ['columns' => [['key' => 'a', 'label' => 'أ']]],
                'capped at 500',
            ],
            'a cell naming a column that does not exist' => [
                '<x-grid name="g" caption="ج" :columns="$columns"><x-grid.row :index="0" row-key="1">'
                .'<x-grid.cell column="nope">x</x-grid.cell></x-grid.row><x-slot:empty>لا شيء</x-slot:empty></x-grid>',
                ['columns' => [['key' => 'a', 'label' => 'أ']]],
                'no column is called',
            ],
            'an editable cell in a row with no key' => [
                '<x-grid name="g" caption="ج" :columns="$columns" action="/x"><x-grid.row :index="0">'
                .'<x-grid.cell column="a" value="1"/></x-grid.row><x-slot:empty>لا شيء</x-slot:empty></x-grid>',
                ['columns' => [['key' => 'a', 'label' => 'أ', 'editable' => true]]],
                'needs a `rowKey`',
            ],
            'a column with no label' => [
                '<x-grid name="g" caption="ج" :columns="$columns"><x-slot:empty>لا شيء</x-slot:empty></x-grid>',
                ['columns' => [['key' => 'a']]],
                'has no `label`',
            ],
            'a grid with no instance key' => [
                '<x-grid caption="ج" :columns="$columns"><x-slot:empty>لا شيء</x-slot:empty></x-grid>',
                ['columns' => [['key' => 'a', 'label' => 'أ']]],
                'needs a `name`',
            ],
            'a saved view carrying CSS instead of a width' => [
                '<x-grid name="g" caption="ج" :columns="$columns"><x-slot:empty>لا شيء</x-slot:empty></x-grid>',
                ['columns' => [['key' => 'a', 'label' => 'أ', 'width' => '1rem;position:fixed']]],
                'not a plain CSS length',
            ],
        ];

        return $this->eachRefuses($cases);
    }

    /**
     * A class written as an array arrives on the element.
     *
     * Every page in this repository writes a conditional class as
     * `@class(['border-s-2 border-draft-stripe' => $invoice->isDraft()])`,
     * and the component form of it - `:class="[...]"` - hands the
     * attribute bag an ARRAY. A component that merges its own classes
     * then throws "Array to string conversion" from inside
     * ComponentAttributeBag, with its own name nowhere in the message.
     *
     * This is here because it happened: the draft stripe is the first
     * thing the invoice list needs from a cell, and it was a 500.
     *
     * @return list<string>
     */
    private function conditionalClassesSurvive(): array
    {
        $problems = [];

        $cases = [
            'the grid' => ['<x-grid name="g" :caption="$c" :columns="$columns" :class="[\'mt-8\' => true]"><x-slot:empty>لا شيء</x-slot:empty></x-grid>', 'mt-8'],
            'a row' => ['<x-grid name="g" :caption="$c" :columns="$columns"><x-grid.row :index="0" row-key="1" :class="[\'bg-surface-sunken\' => true]"><x-grid.cell column="a">x</x-grid.cell></x-grid.row><x-slot:empty>لا شيء</x-slot:empty></x-grid>', 'bg-surface-sunken'],
            'a cell' => ['<x-grid name="g" :caption="$c" :columns="$columns"><x-grid.row :index="0" row-key="1"><x-grid.cell column="a" :class="[\'border-s-2 border-draft-stripe\' => true]">x</x-grid.cell></x-grid.row><x-slot:empty>لا شيء</x-slot:empty></x-grid>', 'border-draft-stripe'],
            'a field' => ['<x-form.field name="n" :label="$c" :class="[\'max-w-sm\' => true]"/>', 'max-w-sm'],
            'an amount' => ['<x-form.currency name="m" :label="$c" currency="SAR" :class="[\'max-w-xs\' => true]"/>', 'max-w-xs'],
            'a date range' => ['<x-form.date-range name="w" :legend="$c" :class="[\'gap-6\' => true]"/>', 'gap-6'],
        ];

        foreach ($cases as $what => [$template, $expected]) {
            try {
                $html = $this->render($template, ['c' => 'نص', 'columns' => [['key' => 'a', 'label' => 'أ']]]);
            } catch (Throwable $e) {
                $problems[] = sprintf(
                    '%s refuses a class written as an array: %s. Every page here writes a conditional class '.
                    'that way, so the first one somebody writes is a 500.',
                    $what,
                    Str::limit((string) preg_replace('/\s+/', ' ', $e->getMessage()), 120),
                );

                continue;
            }

            if (! str_contains($html, $expected)) {
                $problems[] = sprintf('%s dropped the class "%s" it was given.', $what, $expected);
            }
        }

        return $problems;
    }

    /* =====================================================================
     |  PF-212 - the form system
     | ================================================================== */

    /** @return list<string> */
    private function everyControlHasALabel(): array
    {
        $problems = [];

        foreach ([
            'the form' => $this->formFixture(),
            'the list grid' => $this->listGrid(),
            'the editable grid' => $this->editableGrid(),
        ] as $what => $html) {
            $markup = (string) preg_replace('#<script id="pf-ui-behaviour">.*?</script>#s', '', $html);
            $xpath = $this->xpath($markup);

            $labels = [];

            foreach ($this->nodes($xpath, '//label[@for]') as $label) {
                $labels[$label->getAttribute('for')] = trim($label->textContent);
            }

            foreach ($this->nodes($xpath, '//input | //select | //textarea') as $control) {
                $type = strtolower($control->getAttribute('type'));

                if ($type === 'hidden' || $control->getAttribute('name') === '_token') {
                    continue;
                }

                $id = $control->getAttribute('id');

                // A cell's input is named by its column header, which is
                // the grid's own mechanism and is checked there.
                if ($control->getAttribute('aria-labelledby') !== '') {
                    continue;
                }

                if ($id === '' || ! array_key_exists($id, $labels)) {
                    $problems[] = sprintf(
                        '%s renders a <%s name="%s"> that no <label for> names. It renders perfectly and is '.
                        'noticed only by somebody using a screen reader.',
                        $what,
                        $control->nodeName,
                        $control->getAttribute('name'),
                    );

                    continue;
                }

                if ($labels[$id] === '') {
                    $problems[] = sprintf('%s has a <label for="%s"> with no text in it.', $what, $id);
                }

                if ($control->getAttribute('placeholder') !== '' && $labels[$id] === '') {
                    $problems[] = sprintf('%s names a control with a placeholder, which is not a name.', $what);
                }
            }

            /*
             | And no <label> names nothing - the other half of the same
             | defect, and the one CONTRIBUTING.md lists as a hard
             | refusal.
             */
            $ids = [];

            foreach ($this->nodes($xpath, '//*[@id]') as $node) {
                $ids[$node->getAttribute('id')] = true;
            }

            foreach (array_keys($labels) as $for) {
                if (! array_key_exists($for, $ids)) {
                    $problems[] = sprintf('%s renders <label for="%s">, and nothing on the page has that id.', $what, $for);
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function idsAreDerivedFromTheInstance(): array
    {
        $problems = [];

        $one = $this->render('<x-form.field name="amount" instance="first" :label="$l"/>', ['l' => 'المبلغ']);
        $two = $this->render('<x-form.field name="amount" instance="second" :label="$l"/>', ['l' => 'المبلغ']);
        $again = $this->render('<x-form.field name="amount" instance="first" :label="$l"/>', ['l' => 'المبلغ']);

        if ($this->attribute($this->xpath($one), '//input/@id') === $this->attribute($this->xpath($two), '//input/@id')) {
            $problems[] = 'two instances of the same field render the same id, so the second label focuses the first field.';
        }

        /*
         | And the id is DERIVED, not generated.
         |
         | A random id would dodge the collision and lose the other half:
         | the errors summary has to be able to work out the same id
         | without being told it, and a link to `#field-xk3p` that was
         | regenerated on the response the user is reading points at
         | nothing.
         */
        if ($one !== $again) {
            $problems[] = 'the same field rendered twice produces different markup, so its id is generated rather '.
                'than derived - and the error summary cannot link to a field whose id changes.';
        }

        if ($this->attribute($this->xpath($one), '//input/@id') !== 'field-first-amount') {
            $problems[] = 'the id is not derived from the instance key and the name.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theSummaryLinksToTheField(): array
    {
        $problems = [];
        $xpath = $this->xpath($this->formFixture());

        $targets = [];

        foreach ($this->nodes($xpath, '//*[@role="alert"]//a[@href]') as $link) {
            $targets[] = ltrim($link->getAttribute('href'), '#');
        }

        if ($targets === []) {
            return ['the error summary lists no links, so a reader has to hunt for the field it is about.'];
        }

        $ids = [];

        foreach ($this->nodes($xpath, '//*[@id]') as $node) {
            $ids[$node->getAttribute('id')] = true;
        }

        foreach ($targets as $target) {
            if (! array_key_exists($target, $ids)) {
                $problems[] = sprintf(
                    'the error summary links to "#%s", and nothing on the page has that id. An anchor to a '.
                    'missing id scrolls nothing and reports nothing, so the summary looks right and does '.
                    'nothing - which is why the two components derive the id the same way.',
                    $target,
                );
            }
        }

        if (! in_array('field-party-code', $targets, true) || ! in_array('field-party-limit', $targets, true)) {
            $problems[] = sprintf(
                'the summary links to %s; it has to name the field each message is about, including the one '.
                'rendered by <x-form.currency>.',
                implode(', ', $targets),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function errorsAreAnnounced(): array
    {
        $problems = [];
        $xpath = $this->xpath($this->formFixture());

        $summary = '//*[@role="alert"]';

        if ($this->count($xpath, $summary) === 0) {
            return ['nothing on a refused form carries role="alert".'];
        }

        if ($this->attribute($xpath, $summary.'/@autofocus') === null || $this->attribute($xpath, $summary.'/@tabindex') !== '-1') {
            $problems[] = 'the error summary does not take focus. A live region announces a CHANGE, and this '.
                'summary arrives with the response - so without focus the refusal reaches a screen-reader user '.
                'through nothing at all.';
        }

        $field = '//input[@id="field-party-code"]';

        if ($this->attribute($xpath, $field.'/@aria-invalid') !== 'true') {
            $problems[] = 'a field with an error is not marked aria-invalid.';
        }

        $described = (string) $this->attribute($xpath, $field.'/@aria-describedby');

        if (! str_contains($described, 'field-party-code-error')) {
            $problems[] = 'a field with an error does not point aria-describedby at the message, so the message '.
                'is seen and never read.';
        }

        foreach (explode(' ', $described) as $id) {
            if ($id !== '' && $this->count($xpath, sprintf('//*[@id="%s"]', $id)) === 0) {
                $problems[] = sprintf('aria-describedby names "%s", which nothing on the page has.', $id);
            }
        }

        /*
         | And the message is not a colour (WCAG 1.4.1).
         |
         | The glyph and the words carry it; the colour is on top of
         | them. A message told apart by its colour is told apart by
         | nothing in forced-colours mode, on a printout, or to a reader
         | who cannot see it.
         */
        $message = $this->nodes($xpath, '//*[@id="field-party-code-error"]');

        if ($message === []) {
            $problems[] = 'the inline message is missing.';
        } else {
            $text = trim($message[0]->textContent);

            if (! str_contains($text, 'الرمز مستخدم بالفعل.')) {
                $problems[] = 'the inline message does not carry the message.';
            }

            if (! str_contains($message[0]->ownerDocument?->saveHTML($message[0]) ?: '', 'aria-hidden="true"')) {
                $problems[] = 'the inline message has no glyph beside the words, so colour is the only thing '.
                    'that distinguishes it.';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theCurrencyInputAcceptsPaste(): array
    {
        $problems = [];
        $xpath = $this->xpath($this->formFixture());
        $field = '//input[@id="field-party-limit"]';

        $expected = [
            'type' => 'text',
            'inputmode' => 'decimal',
            'dir' => 'ltr',
        ];

        foreach ($expected as $attribute => $value) {
            $actual = $this->attribute($xpath, $field.'/@'.$attribute);

            if ($actual !== $value) {
                $problems[] = sprintf('the currency input has %s="%s" where it needs "%s".', $attribute, (string) $actual, $value);
            }
        }

        if ($this->attribute($xpath, $field.'/@pattern') !== null) {
            $problems[] = 'the currency input carries a pattern, which refuses a pasted "1,234.56" in the '.
                'browser before the server - the only place the exponent is known - ever sees it.';
        }

        if ($this->attribute($xpath, $field.'/@readonly') !== null || $this->attribute($xpath, $field.'/@onpaste') !== null) {
            $problems[] = 'the currency input blocks a paste, which is how an IBAN or an amount gets retyped '.
                'with a digit changed.';
        }

        if ($this->attribute($xpath, $field.'/@data-pf-currency') !== 'KWD') {
            $problems[] = 'the currency input does not name its currency, so nothing can group its digits and '.
                'nobody can see which currency the amount is in.';
        }

        // Three decimals in a three-decimal currency, rendered as given.
        if ($this->attribute($xpath, $field.'/@value') !== '1234.567') {
            $problems[] = sprintf(
                'the currency input rendered "%s" for 1234.567. A field that truncates the third decimal is '.
                'wrong by a tenth of a fils on every dinar amount, consistently, and reports nothing.',
                (string) $this->attribute($xpath, $field.'/@value'),
            );
        }

        $described = (string) $this->attribute($xpath, $field.'/@aria-describedby');

        if ($this->count($xpath, sprintf('//*[@id="%s"]', $described)) === 0 && ! str_contains($described, ' ')) {
            $problems[] = 'the currency input describes itself with an id nothing has.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function presetsArriveResolved(): array
    {
        $problems = [];
        $xpath = $this->xpath($this->formFixture());

        if ($this->count($xpath, '//fieldset/legend') === 0) {
            $problems[] = 'the date range has no legend, so two date fields beside each other are two dates '.
                'rather than a range.';
        }

        foreach (['last-period' => '2026-09-01', 'ytd' => '2026-04-01'] as $key => $date) {
            $label = $this->text($xpath, sprintf('//label[@for="field-party-window-preset-%s"]', $key));

            if (! str_contains($label, $date)) {
                $problems[] = sprintf(
                    'the preset "%s" does not show the range it resolved to (%s). The dates are what make it '.
                    'trustworthy: "this quarter" against a fiscal year that starts in April is not the '.
                    'Gregorian one, and a reader cannot tell which they asked for.',
                    $key,
                    $date,
                );
            }
        }

        foreach (['from', 'to'] as $end) {
            if ($this->count($xpath, sprintf('//input[@id="field-party-window-%s"][@type="date"]', $end)) !== 1) {
                $problems[] = sprintf('there is no %s date input for a range no preset covers.', $end);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theFormRefuses(): array
    {
        return $this->eachRefuses([
            'a control with no label' => [
                '<x-form.field name="code"/>',
                [],
                'has no `label`',
            ],
            'a control with no name' => [
                '<x-form.field :label="$l"/>',
                ['l' => 'الرمز'],
                'needs a `name`',
            ],
            'an amount with no currency' => [
                '<x-form.currency name="amount" :label="$l"/>',
                ['l' => 'المبلغ'],
                'three-letter `currency`',
            ],
            'a date range with no legend' => [
                '<x-form.date-range name="window"/>',
                [],
                'has no `legend`',
            ],
            'a preset the server did not resolve' => [
                '<x-form.date-range name="window" :legend="$l" :presets="$presets"/>',
                ['l' => 'الفترة', 'presets' => [['key' => 'this-quarter', 'label' => 'هذا الربع']]],
                'arrives unresolved',
            ],
        ]);
    }

    /* =====================================================================
     |  Running the script
     | ================================================================== */

    /**
     * The digit normaliser, run rather than read.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function digitsAreNormalised(): array
    {
        $name = 'a pasted amount arrives as the server expects it';
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($node === '') {
            return [$name.' (no node, so the normaliser was NOT run)', []];
        }

        /*
         | The script is loaded into a stub of the two globals it touches
         | at load time, and then its one published function is called.
         |
         | Measured rather than reasoned about, because this is the only
         | code in the file that changes a VALUE on its way to a money
         | field: `parseFloat('١٢٣')` is NaN, a pasted European separator
         | is not a decimal point, and a fourth decimal that the ledger
         | stores must survive being typed.
         */
        $harness = <<<'JS'
        const fs = require('fs');
        const source = fs.readFileSync(process.argv[2], 'utf8');
        const window = { pfUiStrings: {} };
        const document = {
            readyState: 'complete',
            querySelectorAll: () => [],
            addEventListener: () => {},
        };
        const navigator = {};
        new Function('window', 'document', 'navigator', source)(window, document, navigator);
        const cases = [
            ['١٢٣٤٫٥٦', '1234.56'],
            ['۱۲۳', '123'],
            ['1,234.56', '1,234.56'],
            ['1٬234٫5', '1234.5'],
            ['1234.5678', '1234.5678'],
            ['-١٢.٣', '-12.3'],
            ['1 234', '1234'],
        ];
        const bad = cases
            .filter(([input, want]) => window.pfUi.westernize(input) !== want)
            .map(([input, want]) => input + ' -> ' + window.pfUi.westernize(input) + ' (wanted ' + want + ')');
        process.stdout.write(JSON.stringify(bad));
        JS;

        $harnessFile = tempnam(sys_get_temp_dir(), 'pf-digits-').'.cjs';
        file_put_contents($harnessFile, $harness);

        $output = shell_exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($node),
            escapeshellarg($harnessFile),
            escapeshellarg(base_path('resources/js/enhancements.js')),
        ));

        @unlink($harnessFile);

        $decoded = json_decode((string) $output, true);

        if (! is_array($decoded)) {
            return [$name, [sprintf(
                'the normaliser could not be run at all: %s',
                Str::limit((string) preg_replace('/\s+/', ' ', (string) $output), 300),
            )]];
        }

        /** @var list<string> $decoded */
        return [$name, array_map(static fn (mixed $one): string => sprintf(
            '%s. A money field that receives the wrong digits is wrong by a factor nobody can explain, and the '.
            'figure looks like a figure.',
            is_string($one) ? $one : '(unreadable)',
        ), $decoded)];
    }

    /* =====================================================================
     |  Plumbing
     | ================================================================== */

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * Empty, loading and error are different facts - PF-278.
     *
     * ## Why the pair that matters is empty against error
     *
     * "No invoices match this filter" says the data is fine and your
     * question returned nothing. "We could not read the invoices" says you
     * do not know what the data is. They are opposite facts, and a person
     * shown the first when the second is true concludes a supplier has no
     * bills - then does not pay them.
     *
     * So this asserts the grid renders them differently and announces the
     * error, rather than that each exists. Two states that both print a
     * sentence in the middle of an empty table are one state wearing two
     * names.
     *
     * ## And that the grid owns them
     *
     * A page writing its own error block is a page that will write a
     * different one, and the difference will be in whether it is announced.
     *
     * @return list<string>
     */
    private function theThreeStates(): array
    {
        $columns = [
            ['key' => 'number', 'label' => 'A'],
            ['key' => 'gross', 'label' => 'B', 'figure' => true],
        ];

        $render = fn (string $attributes): string => $this->render(
            '<x-grid name="s" caption="C" :columns="$columns" '.$attributes.
            '><x-slot:empty>NOTHING-MATCHED</x-slot:empty></x-grid>',
            ['columns' => $columns],
        );

        $violations = [];

        $empty = $render('');
        $error = $render(':error="\'COULD-NOT-READ\'"');
        $loading = $render(':loading="true"');

        if (! str_contains($empty, 'NOTHING-MATCHED')) {
            $violations[] = 'a grid with no rows did not render its empty slot';
        }

        if (! str_contains($error, 'COULD-NOT-READ')) {
            $violations[] = 'a grid given an error did not render it';
        }

        /*
         | The error must not ALSO be the empty state.
         |
         | This is the whole check: a grid that falls through to "no results"
         | after printing the error has told the person both, and the one
         | they act on is the one that sounds like an answer.
         */
        if (str_contains($error, 'NOTHING-MATCHED')) {
            $violations[] = 'a grid given an error also rendered its empty state, so the page says both '.
                '"we could not read this" and "there is nothing here". The second sounds like an answer '.
                'and is the one somebody acts on';
        }

        if (! str_contains($error, 'role="alert"')) {
            $violations[] = 'the error is not announced. It arrives with the page, so a screen reader '.
                'that is not told reads a table with no rows and no reason';
        }

        if (! str_contains($loading, 'pf-skeleton')) {
            $violations[] = 'a loading grid does not render the skeleton, so a page that needs one has '.
                'to write its own - and the one it writes will be a grey box rather than this table';
        }

        if (str_contains($loading, 'NOTHING-MATCHED')) {
            $violations[] = 'a loading grid also rendered its empty state, so a table that is still '.
                'arriving says there is nothing in it';
        }

        /*
         | The skeleton's columns are the grid's columns.
         |
         | `of="table"` with this grid's widths, not the component's
         | defaults: a placeholder with six equal bars over a two-column
         | table is a placeholder for a different table.
         */
        if (substr_count($loading, 'pf-skeleton__cell') < 2) {
            $violations[] = 'the loading skeleton does not carry this grid\'s columns, so the shape that '.
                'appears is not the shape that arrives';
        }

        return $violations;
    }

    private function render(string $template, array $data = []): string
    {
        return Blade::render($template, $data);
    }

    private function script(): string
    {
        return (string) file_get_contents(base_path('resources/js/enhancements.js'));
    }

    /**
     * Each case renders, and each one must refuse with the words given.
     *
     * The words matter: a refusal nobody can act on is a 500 somebody
     * reports as "the page is broken", and the whole point of refusing is
     * that the next person is told what to do instead.
     *
     * @param  array<string, array{0: string, 1: array<string, mixed>, 2: string}>  $cases
     * @return list<string>
     */
    private function eachRefuses(array $cases): array
    {
        $problems = [];

        foreach ($cases as $what => [$template, $data, $expected]) {
            try {
                $this->render($template, $data);
                $problems[] = sprintf('%s rendered happily, and nothing refused it.', $what);
            } catch (Throwable $e) {
                $message = (string) preg_replace('/\s+/', ' ', $e->getMessage());

                if (! str_contains($message, $expected)) {
                    $problems[] = sprintf(
                        '%s was refused with "%s", which does not say "%s" - so the refusal does not tell the '.
                        'next person what to do.',
                        $what,
                        Str::limit($message, 160),
                        $expected,
                    );
                }
            }
        }

        return $problems;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // The charset meta is what keeps Arabic text from being read as
        // Latin-1 - without it every string in every assertion is mojibake.
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /** @return list<DOMElement> */
    private function nodes(DOMXPath $xpath, string $query, ?DOMElement $context = null): array
    {
        $found = $context === null ? $xpath->query($query) : $xpath->query($query, $context);
        $out = [];

        if ($found === false) {
            return [];
        }

        foreach ($found as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private function count(DOMXPath $xpath, string $query, ?DOMElement $context = null): int
    {
        $found = $context === null ? $xpath->query($query) : $xpath->query($query, $context);

        return $found === false ? 0 : $found->length;
    }

    private function attribute(DOMXPath $xpath, string $query): ?string
    {
        $found = $xpath->query($query);

        if ($found === false || $found->length === 0) {
            return null;
        }

        $first = $found->item(0);

        return $first === null ? null : $first->nodeValue;
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        $found = $xpath->query($query);

        if ($found === false || $found->length === 0) {
            return '';
        }

        $first = $found->item(0);

        return $first === null ? '' : $first->textContent;
    }

    /** @return list<string> */
    private function attributesOf(DOMElement $element): array
    {
        $out = [];

        foreach ($element->attributes as $attribute) {
            $out[] = $attribute->nodeName;
        }

        return $out;
    }
}
