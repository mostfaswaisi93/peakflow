<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

/**
 * What a bank's CSV actually looks like - PF-154.
 *
 * ## Every one of these is a guess that can be overridden
 *
 * A European file with `1.234,56` and a separate debit column is the normal
 * case, not the edge case - and the same four bytes, `1.234`, are one
 * thousand two hundred and thirty four in Frankfurt and one and a bit in
 * London. No amount of cleverness settles that from a single value.
 *
 * So detection proposes and the person confirms, against a **preview of the
 * rows as they will be interpreted**. A detector that silently got it wrong
 * imports a year of transactions off by a factor of a thousand, and every
 * one of them looks plausible.
 *
 * ## Sign convention is the one people get wrong twice
 *
 * Three conventions are all common: one signed column; two columns, debit
 * and credit; one unsigned column with a direction column beside it. The
 * second is the one that quietly halves a statement, because a reader
 * expecting one amount column finds the debit column empty on credit rows
 * and imports zero.
 */
final readonly class CsvDialect
{
    public const SIGN_SIGNED = 'signed';

    public const SIGN_DEBIT_CREDIT_COLUMNS = 'debit_credit_columns';

    public const SIGN_INDICATOR_COLUMN = 'indicator_column';

    public function __construct(
        public string $delimiter = ',',
        public string $enclosure = '"',
        public string $decimalSeparator = '.',
        public string $thousandsSeparator = '',
        public string $dateFormat = 'Y-m-d',
        public string $encoding = 'UTF-8',
        public bool $hasHeader = true,
        public string $signConvention = self::SIGN_SIGNED,
        /** Where a debit is written without a sign, as most European exports do. */
        public bool $debitsArePositive = false,
    ) {}

    /** @param array<string, mixed> $overrides */
    public function with(array $overrides): self
    {
        return new self(
            delimiter: (string) ($overrides['delimiter'] ?? $this->delimiter),
            enclosure: (string) ($overrides['enclosure'] ?? $this->enclosure),
            decimalSeparator: (string) ($overrides['decimal_separator'] ?? $this->decimalSeparator),
            thousandsSeparator: (string) ($overrides['thousands_separator'] ?? $this->thousandsSeparator),
            dateFormat: (string) ($overrides['date_format'] ?? $this->dateFormat),
            encoding: (string) ($overrides['encoding'] ?? $this->encoding),
            hasHeader: (bool) ($overrides['has_header'] ?? $this->hasHeader),
            signConvention: (string) ($overrides['sign_convention'] ?? $this->signConvention),
            debitsArePositive: (bool) ($overrides['debits_are_positive'] ?? $this->debitsArePositive),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'delimiter' => $this->delimiter,
            'enclosure' => $this->enclosure,
            'decimal_separator' => $this->decimalSeparator,
            'thousands_separator' => $this->thousandsSeparator,
            'date_format' => $this->dateFormat,
            'encoding' => $this->encoding,
            'has_header' => $this->hasHeader,
            'sign_convention' => $this->signConvention,
            'debits_are_positive' => $this->debitsArePositive,
        ];
    }

    /**
     * What each setting was decided from, in words.
     *
     * Shown beside the preview, because "detected" with nothing behind it is
     * what somebody clicks past - and the one that matters, the decimal
     * separator, is exactly the one where seeing the reasoning makes the
     * mistake obvious.
     *
     * @param  array<string, string>  $evidence
     * @return array<string, string>
     */
    public function explain(array $evidence = []): array
    {
        return array_merge([
            'delimiter' => sprintf('"%s" occurs the same number of times in every row.', $this->delimiter),
            'decimal_separator' => sprintf(
                'Amounts use "%s" before the last two digits%s.',
                $this->decimalSeparator,
                $this->thousandsSeparator === ''
                    ? ' and no grouping separator'
                    : sprintf(' and "%s" for grouping', $this->thousandsSeparator),
            ),
            'date_format' => sprintf('Dates parse as %s in every row.', $this->dateFormat),
            'sign_convention' => match ($this->signConvention) {
                self::SIGN_DEBIT_CREDIT_COLUMNS => 'Debits and credits are in separate columns, and exactly one of them is filled on each row.',
                self::SIGN_INDICATOR_COLUMN => 'Amounts are unsigned and a separate column says which way each one went.',
                default => 'Amounts carry their own sign.',
            },
        ], $evidence);
    }
}
