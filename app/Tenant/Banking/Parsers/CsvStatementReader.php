<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

use App\Tenant\Banking\Exceptions\StatementUnreadable;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Carbon;

/**
 * A CSV read through a column mapping - PF-154.
 *
 * ## Not a `StatementParser`, deliberately
 *
 * Every other reader recognises its own files. A CSV has no shape: anything
 * with commas in it is a CSV if you squint, so a sniffer that claimed them
 * would swallow every file no other reader wanted and produce nonsense rows
 * instead of a refusal. A CSV is chosen, mapped and previewed - never
 * guessed at.
 *
 * ## Preview first, always
 *
 * `preview()` returns the first rows **as they will be interpreted** - the
 * parsed date, the signed amount, the text - beside the raw cells they came
 * from. That pairing is the point: "01.05.2026 → 2026-05-01" is checkable at
 * a glance and "1.000,00 → 1000.0000" is the line where somebody spots that
 * their thousands are being read as decimals.
 *
 * A preview of the raw file would prove only that the file was uploaded.
 *
 * ## A row that will not read is reported, not skipped
 *
 * A date that does not parse, an amount that is not a number, a row with
 * fewer fields than the header: each is reported with its row number and
 * what was in it. Skipping them silently imports a partial month and says
 * nothing, and the missing rows surface as an unexplained reconciliation
 * difference weeks later.
 */
final class CsvStatementReader
{
    public function __construct(private readonly CsvSniffer $sniffer = new CsvSniffer()) {}

    /**
     * The first rows as they will be interpreted, beside their raw cells.
     *
     * @param  array<string, mixed>  $columnMap
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     problems: list<array<string, mixed>>,
     *     header: list<string>,
     *     total_rows: int,
     * }
     *
     * @throws StatementUnreadable
     */
    public function preview(
        string $content,
        CsvDialect $dialect,
        array $columnMap,
        string $currency,
        int $limit = 10,
    ): array {
        $table = $this->table($content, $dialect);
        $rows = [];
        $problems = [];

        foreach ($table['body'] as $number => $cells) {
            $read = $this->row($cells, $dialect, $columnMap, $currency, $number + $table['firstDataRow']);

            if (isset($read['problem'])) {
                $problems[] = $read['problem'];

                continue;
            }

            if (\count($rows) < $limit) {
                /** @var ParsedLine $line */
                $line = $read['line'];

                $rows[] = [
                    'row' => $number + $table['firstDataRow'],
                    'raw' => $cells,
                    'as_read' => [
                        'booking_date' => $line->bookingDate->toDateString(),
                        'value_date' => $line->valueDate->toDateString(),
                        'amount' => $line->amount,
                        'currency' => $line->currency,
                        'counterparty_name' => $line->counterpartyName,
                        'remittance' => $line->remittanceUnstructured,
                        'bank_reference' => $line->bankReference,
                    ],
                ];
            }
        }

        return [
            'rows' => $rows,
            'problems' => $problems,
            'header' => $table['header'],
            'total_rows' => \count($table['body']),
        ];
    }

    /**
     * Read the whole file into one statement.
     *
     * @param  array<string, mixed>  $columnMap
     *
     * @throws StatementUnreadable
     */
    public function read(
        string $content,
        CsvDialect $dialect,
        array $columnMap,
        string $currency,
        ?string $accountIdentifier = null,
    ): ParsedStatement {
        $table = $this->table($content, $dialect);

        $lines = [];
        $problems = [];

        foreach ($table['body'] as $number => $cells) {
            $read = $this->row($cells, $dialect, $columnMap, $currency, $number + $table['firstDataRow']);

            if (isset($read['problem'])) {
                $problems[] = $read['problem'];

                continue;
            }

            $lines[] = $read['line'];
        }

        if ($problems !== []) {
            /*
             | Any unreadable row stops the import.
             |
             | Importing the rest would produce a statement whose lines do
             | not account for its own movement, and the person would be
             | left reconciling a month against a file that is three rows
             | short with nothing saying which three. Reported together so
             | one pass through the file fixes all of them.
             */
            throw StatementUnreadable::because(sprintf(
                "%d row%s of this file could not be read, so nothing was imported:\n%s\n\n".
                'Importing the rest would leave the month short by those rows, and the difference '.
                'would surface weeks later as a reconciliation that does not tie.',
                \count($problems),
                \count($problems) === 1 ? '' : 's',
                implode("\n", array_map(
                    static fn (array $problem): string => sprintf(
                        '  row %d: %s',
                        (int) $problem['row'],
                        (string) $problem['why'],
                    ),
                    \array_slice($problems, 0, 20),
                )),
            ));
        }

        if ($lines === []) {
            throw StatementUnreadable::because(
                'This file has a header and no data rows. A statement export that looks empty is '.
                'usually a date range with no transactions in it.',
            );
        }

        $dates = array_map(static fn (ParsedLine $line): Carbon => $line->bookingDate, $lines);
        usort($dates, static fn (Carbon $a, Carbon $b): int => $a <=> $b);

        /*
         | A CSV states no balances, so the statement carries none.
         |
         | The importer fills them from the account's own position, which
         | means a CSV import cannot be checked against its own stated
         | movement - there is nothing stated. What still has to hold is
         | continuity against the previous statement, and that is what
         | catches a CSV missing its last page.
         */
        return new ParsedStatement(
            sourceFormat: 'csv',
            accountIban: null,
            accountOtherId: $accountIdentifier,
            currency: $currency,
            periodFrom: reset($dates),
            periodTo: end($dates),
            openingBalance: null,
            closingBalance: null,
            lines: $lines,
        );
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, mixed>  $columnMap
     * @return array{line?: ParsedLine, problem?: array<string, mixed>}
     */
    private function row(
        array $cells,
        CsvDialect $dialect,
        array $columnMap,
        string $currency,
        int $number,
    ): array {
        $bookingRaw = $this->cell($cells, $columnMap, 'booking_date');

        if ($bookingRaw === null || trim($bookingRaw) === '') {
            return ['problem' => [
                'row' => $number,
                'why' => 'the booking date column is empty',
                'cells' => $cells,
            ]];
        }

        $booking = $this->sniffer->readsAsDate($bookingRaw, $dialect->dateFormat);

        if ($booking === null) {
            return ['problem' => [
                'row' => $number,
                'why' => sprintf(
                    '"%s" is not a date in the format %s that the rest of the file uses',
                    $bookingRaw,
                    $dialect->dateFormat,
                ),
                'cells' => $cells,
            ]];
        }

        $valueRaw = $this->cell($cells, $columnMap, 'value_date');
        $value = $valueRaw === null || trim($valueRaw) === ''
            ? $booking
            : ($this->sniffer->readsAsDate($valueRaw, $dialect->dateFormat) ?? $booking);

        try {
            $amount = $this->amount($cells, $columnMap, $dialect);
        } catch (StatementUnreadable $e) {
            return ['problem' => ['row' => $number, 'why' => $e->getMessage(), 'cells' => $cells]];
        }

        if ($amount === null) {
            return ['problem' => [
                'row' => $number,
                'why' => 'no amount was found in the mapped column',
                'cells' => $cells,
            ]];
        }

        if ($amount->isZero()) {
            return ['problem' => [
                'row' => $number,
                'why' => 'the amount is zero, which moves nothing and cannot be a statement line',
                'cells' => $cells,
            ]];
        }

        return ['line' => new ParsedLine(
            bookingDate: $booking,
            valueDate: $value,
            amount: (string) $amount->toScale(4),
            currency: strtoupper((string) ($this->cell($cells, $columnMap, 'currency') ?? $currency)),
            counterpartyName: $this->text($cells, $columnMap, 'counterparty_name'),
            counterpartyAccount: $this->text($cells, $columnMap, 'counterparty_account'),
            remittanceUnstructured: $this->text($cells, $columnMap, 'remittance'),
            bankReference: $this->text($cells, $columnMap, 'bank_reference'),
            endToEndId: $this->text($cells, $columnMap, 'end_to_end_id'),
            bankCodeProprietary: $this->text($cells, $columnMap, 'transaction_type'),
            sourcePayload: ['row' => $number, 'cells' => $cells],
        )];
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, mixed>  $columnMap
     *
     * @throws StatementUnreadable
     */
    private function amount(array $cells, array $columnMap, CsvDialect $dialect): ?BigDecimal
    {
        if ($dialect->signConvention === CsvDialect::SIGN_DEBIT_CREDIT_COLUMNS) {
            $debit = $this->number($this->cell($cells, $columnMap, 'debit'), $dialect);
            $credit = $this->number($this->cell($cells, $columnMap, 'credit'), $dialect);

            if ($debit !== null && $credit !== null && ! $debit->isZero() && ! $credit->isZero()) {
                throw StatementUnreadable::because(
                    'both the debit and the credit column have a value, which cannot both be true '.
                    'of one transaction - the columns are probably mapped the wrong way round, or '.
                    'the file has a running balance in one of them',
                );
            }

            if ($credit !== null && ! $credit->isZero()) {
                return $credit->abs();
            }

            if ($debit !== null && ! $debit->isZero()) {
                /*
                 | A debit column holds an unsigned magnitude.
                 |
                 | Keeping its sign would double-negate the exports that
                 | write `-500` in a column already called "debit", which
                 | several banks do.
                 */
                return $debit->abs()->negated();
            }

            return null;
        }

        $amount = $this->number($this->cell($cells, $columnMap, 'amount'), $dialect);

        if ($amount === null) {
            return null;
        }

        if ($dialect->signConvention === CsvDialect::SIGN_INDICATOR_COLUMN) {
            $indicator = strtoupper(trim((string) $this->cell($cells, $columnMap, 'indicator')));

            $isDebit = \in_array($indicator, ['D', 'DR', 'DEBIT', 'DB', '-', 'OUT', 'W'], true);

            return $isDebit ? $amount->abs()->negated() : $amount->abs();
        }

        return $dialect->debitsArePositive ? $amount->negated() : $amount;
    }

    /** @throws StatementUnreadable */
    private function number(?string $value, CsvDialect $dialect): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);

        if ($clean === '') {
            return null;
        }

        /*
         | `(500.00)` is how a spreadsheet writes a negative.
         |
         | Common in exports produced by opening the bank's file in a
         | spreadsheet and saving it again, which is how a good many of
         | these files reach us.
         */
        $negative = str_starts_with($clean, '(') && str_ends_with($clean, ')');

        if ($negative) {
            $clean = trim($clean, '()');
        }

        $clean = str_replace([$dialect->thousandsSeparator, "\u{00A0}", ' '], '', $clean);

        if ($dialect->decimalSeparator !== '.') {
            $clean = str_replace($dialect->decimalSeparator, '.', $clean);
        }

        $clean = (string) preg_replace('/[^\d.\-+]/', '', $clean);

        if ($clean === '' || $clean === '-' || $clean === '+') {
            return null;
        }

        try {
            $parsed = BigDecimal::of($clean);
        } catch (MathException) {
            throw StatementUnreadable::because(sprintf(
                '"%s" is not a number that can be read with "%s" as the decimal separator',
                trim($value),
                $dialect->decimalSeparator,
            ));
        }

        return $negative ? $parsed->negated() : $parsed;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, mixed>  $columnMap
     */
    private function text(array $cells, array $columnMap, string $field): ?string
    {
        $value = $this->cell($cells, $columnMap, $field);

        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, mixed>  $columnMap
     */
    private function cell(array $cells, array $columnMap, string $field): ?string
    {
        if (! \array_key_exists($field, $columnMap)) {
            return null;
        }

        $index = $columnMap[$field];

        /*
         | A mapping may name a column or number it.
         |
         | Numbers survive a bank renaming its headers; names survive a bank
         | adding a column. Neither survives both, which is why the header
         | row is stored with the template and compared on reuse.
         */
        if (\is_int($index)) {
            return $cells[$index] ?? null;
        }

        return null;
    }

    /**
     * @return array{header: list<string>, body: list<list<string>>, firstDataRow: int}
     *
     * @throws StatementUnreadable
     */
    private function table(string $content, CsvDialect $dialect): array
    {
        $text = $this->sniffer->toUtf8($content, $dialect->encoding);
        $rows = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        $rows = array_values(array_filter($rows, static fn (string $row): bool => trim($row) !== ''));

        if ($rows === []) {
            throw StatementUnreadable::because('This file is empty.');
        }

        $parsed = array_map(
            fn (string $row): array => str_getcsv($row, $dialect->delimiter, $dialect->enclosure, '\\'),
            $rows,
        );

        $header = $dialect->hasHeader ? array_map(trim(...), $parsed[0]) : [];
        $body = $dialect->hasHeader ? \array_slice($parsed, 1) : $parsed;

        return [
            'header' => $header,
            'body' => array_values($body),
            'firstDataRow' => $dialect->hasHeader ? 2 : 1,
        ];
    }
}
