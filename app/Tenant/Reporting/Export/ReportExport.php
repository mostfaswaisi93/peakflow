<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Export;

/**
 * A report, as a file somebody can open - PF-197, PF-198.
 *
 * ## Why this exists at all
 *
 * {@see CsvExport} was built, is correct, has a suite of its own - and had
 * no production caller anywhere. So did {@see ReportBasis}. Three of the
 * worst defects in this repository had exactly that shape: `TaxPoint::
 * derive()`, `EvidenceGate` and `factor_asserted_at` were each built,
 * ticked as done, and never reached by anything, and each stayed invisible
 * because the checks called them directly. A class with no caller is a
 * comment with syntax highlighting.
 *
 * This is the caller. It is deliberately the ONLY one: every report that
 * becomes a file goes through here, so there is one answer to "what does
 * an export of this look like" and one place where the injection
 * neutralisation, the byte-order mark and the parameter block are applied.
 *
 * ## The parameters come first, in the file, above the figures
 *
 * Not in a footer and not in the filename. A printed export gets detached
 * from whatever page produced it within about a day: somebody mails the
 * sheet, somebody pastes a column into another workbook, somebody prints
 * it for a meeting. The parameters have to be in the bytes, and above the
 * figures rather than below, because a reader who scrolls to the numbers
 * and stops has still seen them.
 *
 * A blank row separates the block from the header row. Spreadsheets detect
 * a header from the first row of a contiguous block, so without the gap
 * the parameter block becomes the header and every column is named
 * "Entity".
 *
 * ## Figures are Latin digits, always, whatever the locale
 *
 * The labels are localized and the figures are not, and that is not an
 * oversight. Arabic-Indic digits in a spreadsheet cell are TEXT: Excel
 * will not sum them, and the accountant who exported the file to total a
 * column gets zero with no error. The product's own `Money::format()`
 * exists for a screen; a file that will be computed on carries the
 * machine form.
 *
 * This is also why a figure is not thousands-separated here. `1,234.56` in
 * a CSV is two cells.
 */
final class ReportExport
{
    public function __construct(private readonly CsvExport $csv) {}

    /**
     * @param  list<array{label_ar: string, label_en: string, value: string}>  $parameterLines
     * @param  list<array{key: string, label_ar: string, label_en: string}>  $columns
     * @param  list<array<string, mixed>>  $rows  keyed by the columns' `key`
     * @param  list<array{heading: string, rows: list<array<string, mixed>>}>  $groups
     */
    public function csv(
        array $parameterLines,
        array $columns,
        array $rows = [],
        array $groups = [],
        string $locale = 'ar',
    ): string {
        $label = $locale === 'ar' ? 'label_ar' : 'label_en';

        $out = [];

        foreach ($parameterLines as $line) {
            $out[] = [$line[$label], $line['value']];
        }

        // The gap that stops the parameter block being read as the header.
        $out[] = [];

        $headers = array_map(static fn (array $c): string => (string) $c[$label], $columns);
        $keys = array_map(static fn (array $c): string => (string) $c['key'], $columns);

        if ($groups === []) {
            return $this->csv->render($headers, array_map(
                fn (array $row): array => $this->row($row, $keys),
                $rows,
            ), $out);
        }

        /*
         | A grouped report keeps its headings as rows of their own.
         |
         | The alternative is a group column repeated on every line, which
         | is what a pivot table wants and what a person reading the file
         | does not: a general ledger with the account code on all 4,000
         | rows is unreadable, and the person exporting it is reading it.
         |
         | The heading sits in the FIRST cell, so it lands under the first
         | column rather than being spread across the row - a cell merged
         | across eight columns cannot be expressed in a CSV at all, and
         | padding it with seven empty cells makes the file wider for no
         | reader's benefit.
         */
        $body = [];

        foreach ($groups as $group) {
            $body[] = [$group['heading']];

            foreach ($group['rows'] as $row) {
                $body[] = $this->row($row, $keys);
            }

            $body[] = [];
        }

        return $this->csv->render($headers, $body, $out);
    }

    /**
     * One row, in the columns' declared order.
     *
     * Read by key rather than by position, and a key the row does not
     * carry becomes an empty cell rather than shifting every cell after it
     * one column left - which is the failure that produces a file where
     * the debits are under "credit" and nothing anywhere says so.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return list<string|int|float|null>
     */
    private function row(array $row, array $keys): array
    {
        return array_map(static function (string $key) use ($row): string|int|float|null {
            $value = $row[$key] ?? null;

            if (\is_bool($value)) {
                return $value ? 'yes' : 'no';
            }

            if (\is_array($value)) {
                /*
                 | A nested value is not silently flattened into something
                 | that looks like a figure.
                 |
                 | A money object rendered as `Array` is obviously wrong and
                 | gets fixed; one rendered as its first element is wrong
                 | and is not obvious, and somebody totals the column.
                 */
                return '(not exportable)';
            }

            return $value === null ? null : (string) $value;
        }, $keys);
    }
}
