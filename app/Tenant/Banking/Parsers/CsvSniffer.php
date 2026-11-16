<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

use App\Tenant\Banking\Exceptions\StatementUnreadable;
use Illuminate\Support\Carbon;

/**
 * What a CSV's dialect looks like, proposed - PF-154.
 *
 * ## Every answer here is a proposal
 *
 * Nothing this class decides is applied without somebody seeing a preview of
 * the rows as they will be interpreted. That is not politeness: `1.234` is a
 * thousand in one country and one in another, and a detector that guessed
 * silently imports a year off by a factor of a thousand with every row
 * looking plausible.
 *
 * ## The decimal separator is decided across the whole file
 *
 * One value settles nothing. `1.234` is ambiguous; `1.234,56` is not, and
 * `1,234` next to `12,5` is not either. So the separators are decided from
 * every amount-looking value at once, by which reading leaves no value
 * contradicting another - and where both readings survive, the file is
 * reported as ambiguous rather than guessed at.
 */
final class CsvSniffer
{
    private const DATE_FORMATS = [
        'Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd-m-Y', 'Y/m/d',
        'd/m/y', 'm/d/y', 'd.m.y', 'Ymd', 'd M Y', 'j M Y', 'd-M-Y',
    ];

    /**
     * @return array{dialect: CsvDialect, evidence: array<string, string>, warnings: list<string>}
     *
     * @throws StatementUnreadable
     */
    public function sniff(string $content): array
    {
        $warnings = [];

        /*
         | Is it text at all, before asking which text.
         |
         | Windows-1252 is single-byte, so `mb_check_encoding` accepts any
         | byte sequence as valid 1252 - a .xlsx renamed .csv "decodes"
         | perfectly and then fails the row count, and the person is told
         | their date range looks empty. Which sends them back to the bank
         | to re-export a file that was never the problem.
         */
        $this->assertItIsText($content);

        $encoding = $this->encoding($content, $warnings);
        $text = $this->toUtf8($content, $encoding);

        $rows = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        $rows = array_values(array_filter($rows, static fn (string $row): bool => trim($row) !== ''));

        if (\count($rows) < 2) {
            throw StatementUnreadable::because(
                'This file has fewer than two non-empty lines, so there is nothing to import and '.
                'nothing to detect a layout from. A statement export that looks empty is usually a '.
                'date range with no transactions in it.',
            );
        }

        $delimiter = $this->delimiter($rows);
        $parsed = array_map(
            fn (string $row): array => str_getcsv($row, $delimiter, '"', '\\'),
            \array_slice($rows, 0, 200),
        );

        $hasHeader = $this->hasHeader($parsed);
        $body = $hasHeader ? \array_slice($parsed, 1) : $parsed;

        [$decimal, $thousands, $separatorEvidence] = $this->separators($body, $warnings);
        [$dateFormat, $dateEvidence] = $this->dateFormat($body, $warnings);
        [$convention, $signEvidence] = $this->signConvention($parsed, $hasHeader);

        return [
            'dialect' => new CsvDialect(
                delimiter: $delimiter,
                decimalSeparator: $decimal,
                thousandsSeparator: $thousands,
                dateFormat: $dateFormat,
                encoding: $encoding,
                hasHeader: $hasHeader,
                signConvention: $convention,
            ),
            'evidence' => array_filter([
                'delimiter' => sprintf(
                    '"%s" splits every row into the same number of fields.',
                    $delimiter === "\t" ? '\t' : $delimiter,
                ),
                'decimal_separator' => $separatorEvidence,
                'date_format' => $dateEvidence,
                'sign_convention' => $signEvidence,
                'encoding' => sprintf('The bytes decode as %s.', $encoding),
                'has_header' => $hasHeader
                    ? 'The first row has no amount and no date in it, so it is column names.'
                    : 'The first row parses as data, so there is no header.',
            ]),
            'warnings' => $warnings,
        ];
    }

    /**
     * Whether this is text at all.
     *
     * A NUL byte settles it: no text encoding a bank exports produces one,
     * and every container format does. The magic numbers are there for the
     * message rather than the decision - "this is a spreadsheet, export CSV
     * from the bank instead" is actionable, and "not valid text" is not.
     *
     * @throws StatementUnreadable
     */
    private function assertItIsText(string $content): void
    {
        $head = substr($content, 0, 8192);

        $looksLike = match (true) {
            str_starts_with($content, "PK\x03\x04") => 'a ZIP container, which is what an .xlsx or .ods file is',
            str_starts_with($content, '%PDF') => 'a PDF',
            str_starts_with($content, "\xD0\xCF\x11\xE0") => 'an old-format Excel .xls file',
            str_starts_with($content, "\x1F\x8B") => 'a gzip archive',
            default => null,
        };

        if ($looksLike === null && ! str_contains($head, "\0")) {
            return;
        }

        throw StatementUnreadable::because(sprintf(
            'This file is %s, not text, so there is nothing in it to read as a CSV. Saving a '.
            'spreadsheet with a .csv extension does not change its contents - export it again as '.
            'CSV from the bank, or from the spreadsheet use "Save as" and choose CSV rather than '.
            'renaming the file.',
            $looksLike ?? 'binary',
        ));
    }

    /**
     * The delimiter that splits every row the same way.
     *
     * Consistency rather than frequency: a remittance full of commas makes
     * the comma the most frequent character in a semicolon-delimited file,
     * and counting occurrences picks it every time.
     *
     * @param  list<string>  $rows
     *
     * @throws StatementUnreadable
     */
    private function delimiter(array $rows): string
    {
        $sample = \array_slice($rows, 0, 50);
        $best = null;
        $bestFields = 1;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = [];

            foreach ($sample as $row) {
                $counts[] = \count(str_getcsv($row, $candidate, '"', '\\'));
            }

            $unique = array_unique($counts);
            $fields = $counts[0] ?? 1;

            if (\count($unique) === 1 && $fields > $bestFields) {
                $best = $candidate;
                $bestFields = $fields;
            }
        }

        if ($best !== null) {
            return $best;
        }

        throw StatementUnreadable::because(
            'No delimiter splits every row of this file into the same number of fields. That is '.
            'usually a quoted value containing a line break, or a footer row the export added '.
            'after the transactions - both of which make the file something other than the CSV it '.
            'claims to be. Open it, remove the trailing rows, and import it again.',
        );
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  list<string>  $warnings
     * @return array{0: string, 1: string, 2: string}
     */
    private function separators(array $rows, array &$warnings): array
    {
        $values = [];

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $cell = trim($cell);

                if ($cell === '' || preg_match('/^-?[\d.,\x{00A0} ]+$/u', $cell) !== 1) {
                    continue;
                }

                if (preg_match('/\d/', $cell) !== 1) {
                    continue;
                }

                /*
                 | A date is not an amount, however numeric it looks.
                 |
                 | `01.05.2026` passes every "is this a number" test and
                 | rules out the comma as a decimal separator, because its
                 | middle group is two digits rather than three. One date
                 | column is enough to make a German file read as English -
                 | which is off by a factor of a thousand on every amount,
                 | with every row still looking plausible.
                 |
                 | Found on the first European fixture this was run against.
                 */
                if ($this->looksLikeADate($cell)) {
                    continue;
                }

                $values[] = $cell;
            }
        }

        if ($values === []) {
            return ['.', '', 'No numeric values were found, so the default was kept.'];
        }

        $commaDecimal = $this->consistentAs($values, ',', '.');
        $pointDecimal = $this->consistentAs($values, '.', ',');

        if ($commaDecimal && ! $pointDecimal) {
            return [',', '.', 'Values like "1.234,56" only read correctly with "," as the decimal separator.'];
        }

        if ($pointDecimal && ! $commaDecimal) {
            return ['.', ',', 'Values like "1,234.56" only read correctly with "." as the decimal separator.'];
        }

        /*
         | Both readings survive, so the file genuinely does not say.
         |
         | Every value is something like `1.234` with no counter-example
         | anywhere. A guess here is off by a factor of a thousand and every
         | row still looks plausible, so it is reported rather than decided.
         */
        if ($commaDecimal && $pointDecimal) {
            $warnings[] =
                'The decimal separator cannot be determined from this file: every amount in it reads '.
                'correctly either way, so "1.234" is either one thousand two hundred and thirty four '.
                'or one and a bit. Check the preview against the statement you are holding before '.
                'importing - a wrong answer here is off by a factor of a thousand and every row still '.
                'looks right.';
        }

        return ['.', ',', 'Neither separator is contradicted by any value in the file.'];
    }

    /**
     * Whether every value reads correctly with this decimal separator.
     *
     * A grouping separator appears in threes and never last; a decimal
     * separator appears once and is followed by one to four digits. A value
     * that breaks either rule rules the reading out.
     *
     * @param  list<string>  $values
     */
    private function consistentAs(array $values, string $decimal, string $grouping): bool
    {
        foreach ($values as $value) {
            $value = str_replace(["\u{00A0}", ' '], '', $value);

            if (substr_count($value, $decimal) > 1) {
                return false;
            }

            $decimalAt = strrpos($value, $decimal);

            if ($decimalAt !== false) {
                $fraction = substr($value, $decimalAt + 1);

                if (! preg_match('/^\d{1,4}$/', $fraction)) {
                    return false;
                }

                if (str_contains($fraction, $grouping)) {
                    return false;
                }
            }

            $integer = $decimalAt === false ? $value : substr($value, 0, $decimalAt);
            $groups = explode($grouping, ltrim($integer, '-'));

            if (\count($groups) > 1) {
                if ($groups[0] === '' || \strlen($groups[0]) > 3) {
                    return false;
                }

                foreach (\array_slice($groups, 1) as $group) {
                    if (\strlen($group) !== 3) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  list<string>  $warnings
     * @return array{0: string, 1: string}
     */
    private function dateFormat(array $rows, array &$warnings): array
    {
        $candidates = [];

        foreach (self::DATE_FORMATS as $format) {
            $candidates[$format] = 0;
        }

        $seen = 0;

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $cell = trim($cell);

                if ($cell === '' || preg_match('/\d/', $cell) !== 1 || \strlen($cell) > 12) {
                    continue;
                }

                $matched = false;

                foreach (self::DATE_FORMATS as $format) {
                    if ($this->parsesAs($cell, $format)) {
                        $candidates[$format]++;
                        $matched = true;
                    }
                }

                if ($matched) {
                    $seen++;
                }
            }
        }

        arsort($candidates);
        $best = (string) array_key_first($candidates);

        if ($seen === 0 || $candidates[$best] === 0) {
            return ['Y-m-d', 'No column parsed as a date, so the default was kept.'];
        }

        /*
         | `03/04/2026` is the third of April and the fourth of March.
         |
         | Both readings are valid for every row of a file whose dates all
         | fall in the first twelve days of a month, and the two differ by
         | up to eleven months. Reported rather than decided.
         */
        $ties = array_keys($candidates, $candidates[$best], true);

        if (\count($ties) > 1 && $this->ambiguousPair($ties)) {
            $warnings[] = sprintf(
                'Dates in this file read equally well as %s. Every day in it is on or before the '.
                'twelfth of its month, so nothing in the file settles which is which - check the '.
                'preview against the statement before importing.',
                implode(' or ', $ties),
            );
        }

        return [$best, sprintf('%d values parse as %s.', $candidates[$best], $best)];
    }

    /** @param list<string> $formats */
    private function ambiguousPair(array $formats): bool
    {
        $dayFirst = ['d/m/Y', 'd/m/y', 'd.m.Y', 'd.m.y', 'd-m-Y'];
        $monthFirst = ['m/d/Y', 'm/d/y'];

        return array_intersect($formats, $dayFirst) !== [] && array_intersect($formats, $monthFirst) !== [];
    }

    /** Whether a value parses as a date in any format this recognises. */
    private function looksLikeADate(string $value): bool
    {
        foreach (self::DATE_FORMATS as $format) {
            if ($this->parsesAs($value, $format)) {
                return true;
            }
        }

        return false;
    }

    private function parsesAs(string $value, string $format): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{0: string, 1: string}
     */
    private function signConvention(array $rows, bool $hasHeader): array
    {
        if ($hasHeader) {
            $header = array_map(
                static fn (string $cell): string => strtolower(trim($cell)),
                $rows[0] ?? [],
            );

            $debit = $this->looksLike($header, ['debit', 'withdrawal', 'paid out', 'soll', 'مدين']);
            $credit = $this->looksLike($header, ['credit', 'deposit', 'paid in', 'haben', 'دائن']);

            if ($debit !== null && $credit !== null) {
                return [
                    CsvDialect::SIGN_DEBIT_CREDIT_COLUMNS,
                    sprintf(
                        'There is a "%s" column and a "%s" column, so each row fills one of them. A '.
                        'reader expecting a single amount column imports zero on every other row.',
                        $rows[0][$debit],
                        $rows[0][$credit],
                    ),
                ];
            }

            if ($this->looksLike($header, ['dr/cr', 'debit/credit', 'type', 'indicator', 'sign']) !== null) {
                return [
                    CsvDialect::SIGN_INDICATOR_COLUMN,
                    'A column names the direction, so the amounts are probably unsigned.',
                ];
            }
        }

        $negatives = 0;

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if (preg_match('/^\(?-[\d.,]+\)?$/', trim($cell)) === 1) {
                    $negatives++;
                }
            }
        }

        return $negatives > 0
            ? [CsvDialect::SIGN_SIGNED, sprintf('%d values carry a minus sign, so the amounts are signed.', $negatives)]
            : [CsvDialect::SIGN_SIGNED, 'No negative amounts were found; confirm in the preview that money going out shows as negative.'];
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $needles
     */
    private function looksLike(array $header, array $needles): ?int
    {
        foreach ($header as $index => $name) {
            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** @param list<list<string>> $rows */
    private function hasHeader(array $rows): bool
    {
        $first = $rows[0] ?? [];

        foreach ($first as $cell) {
            $cell = trim($cell);

            if ($cell === '') {
                continue;
            }

            if (preg_match('/^-?[\d.,]+$/', $cell) === 1) {
                return false;
            }

            foreach (self::DATE_FORMATS as $format) {
                if ($this->parsesAs($cell, $format)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $warnings
     *
     * @throws StatementUnreadable
     */
    private function encoding(string $content, array &$warnings): string
    {
        foreach ([
            "\u{FEFF}" => 'UTF-8',
            "\xFF\xFE" => 'UTF-16LE',
            "\xFE\xFF" => 'UTF-16BE',
        ] as $mark => $encoding) {
            if (str_starts_with($content, $mark)) {
                return $encoding;
            }
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return 'UTF-8';
        }

        /*
         | Not UTF-8, so it is one of the single-byte encodings banks use.
         |
         | Windows-1252 and ISO-8859-1 differ only in the range where the
         | curly quotes and the euro sign live, and both appear in bank
         | exports. 1252 is the superset, so a file that is really 8859-1
         | still decodes correctly; the reverse does not.
         */
        if (mb_check_encoding($content, 'Windows-1252')) {
            $warnings[] =
                'This file is not UTF-8. It has been read as Windows-1252, which is what most bank '.
                'exports use - check the preview for mangled accented or Arabic characters before '.
                'importing.';

            return 'Windows-1252';
        }

        throw StatementUnreadable::because(
            'The bytes in this file do not decode as UTF-8 or as Windows-1252, so it is not text '.
            'this can read - a spreadsheet saved as .xlsx and renamed .csv is the usual cause. '.
            'Export it again as CSV from the bank rather than from a spreadsheet.',
        );
    }

    public function toUtf8(string $content, string $encoding): string
    {
        $text = $encoding === 'UTF-8' ? $content : (string) mb_convert_encoding($content, 'UTF-8', $encoding);

        return (string) preg_replace('/^\x{FEFF}/u', '', $text);
    }

    /** Whether a value parses as a date in the given format, for the mapper. */
    public function readsAsDate(string $value, string $format): ?Carbon
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, trim($value));

        return $date === false ? null : Carbon::instance(\DateTime::createFromImmutable($date));
    }
}
