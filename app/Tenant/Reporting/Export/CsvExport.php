<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Export;

/**
 * A report as CSV, written so a spreadsheet cannot be weaponised - PF-197.
 *
 * ## The attack this exists to stop
 *
 * A cell whose text begins with `=`, `+`, `-`, `@`, a tab or a carriage
 * return is a FORMULA to Excel, LibreOffice and Google Sheets. So a
 * customer who names their company `=cmd|'/c calc'!A1` has not broken this
 * product at all - they have planted a payload in it, and it fires on the
 * accountant's laptop when somebody exports the party list and opens it.
 * The accountant is the victim, the export is the delivery, and nothing in
 * the ledger was ever wrong.
 *
 * OWASP calls it CSV injection. It is not a parsing bug and no amount of
 * quoting prevents it: a quoted `"=1+1"` is still a formula, because the
 * quotes are consumed by the CSV reader before the spreadsheet sees the
 * cell. The only fix is to change what the cell STARTS with.
 *
 * ## Why a leading apostrophe and not stripping the character
 *
 * Stripping it would silently change a customer's data - a supplier
 * genuinely called `-Alpha` would export as `Alpha`, and the person
 * reconciling two systems would spend an afternoon on it. A leading
 * apostrophe is the spreadsheet's own "this is text" marker: Excel and
 * LibreOffice both consume it on open and display the original string.
 *
 * ## The BOM, which is not optional here
 *
 * Excel on Windows reads a CSV without a byte-order mark in the system's
 * legacy code page, so every Arabic name arrives as mojibake. The BOM
 * costs three bytes and is the difference between a usable export and a
 * screen of question marks - which the person who exported it reports as
 * "the Arabic is broken in your product".
 *
 * ## CRLF, per RFC 4180
 *
 * The standard says CRLF and Excel's own importer is the strictest reader
 * in practice. A file of LF-separated rows opens as one row in some
 * versions, which looks like data loss.
 */
final class CsvExport
{
    /**
     * The characters a spreadsheet reads as "this cell is a formula".
     *
     * Tab and carriage return are in the list because a cell beginning
     * with either is trimmed by the spreadsheet BEFORE the formula test,
     * so `"\t=1+1"` is a formula that this list would otherwise miss.
     */
    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function render(array $headers, array $rows): string
    {
        $out = self::BOM;
        $out .= $this->line($headers);

        foreach ($rows as $row) {
            $out .= $this->line($row);
        }

        return $out;
    }

    /** @param list<string|int|float|null> $cells */
    private function line(array $cells): string
    {
        return implode(',', array_map($this->cell(...), $cells))."\r\n";
    }

    private function cell(string|int|float|null $value): string
    {
        $text = $value === null ? '' : (string) $value;

        $text = $this->neutralize($text);

        /*
         | Quote when the content needs it, and only then - RFC 4180.
         |
         | A file that quotes everything is valid and is harder to read in
         | a diff, and every figure column becomes a string in half the
         | importers that exist. So the rule is the standard's: quote if
         | the cell holds a comma, a quote or a line break.
         */
        if (preg_match('/[",\r\n]/', $text) !== 1) {
            return $text;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }

    /**
     * Make a cell text, whatever it says.
     *
     * The apostrophe goes on INSIDE the quoting, so a value that also
     * needs quoting gets both and neither undoes the other. Getting that
     * order wrong produces `'"=1+1"` - a quoted cell whose first character
     * is an apostrophe to the CSV reader and nothing to the spreadsheet.
     */
    private function neutralize(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        return \in_array($text[0], self::DANGEROUS, true) ? "'".$text : $text;
    }
}
