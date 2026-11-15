<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

/**
 * One line of a manual journal entry as the user has it so far - PF-055.
 *
 * ## Why this is not a PostingLine
 *
 * A `PostingLine` is valid by construction: it holds a `Money`, an account or
 * a role, and nothing that does not parse. A line being typed is none of
 * those things. It has an account code somebody is half way through, an
 * amount that is currently "1,2", and a dimension that may not exist.
 *
 * Refusing to represent that is what forces a screen to keep its own shadow
 * copy of the entry, and the shadow copy is where the two versions of the
 * truth come from. So this holds strings, says what is wrong with them, and
 * converts to a `PostingLine` only once it is valid.
 *
 * ## Every field is nullable and nothing is thrown
 *
 * A line with no account and no amount is an empty row at the bottom of the
 * grid, which is normal and is not an error until somebody tries to post.
 */
final readonly class DraftLine
{
    /**
     * @param  array<int, string|null>  $dimensions  axis (1-8) => value id
     */
    public function __construct(
        public ?string $accountCode = null,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $narration = null,
        public array $dimensions = [],
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $dimensions = [];

        foreach (range(1, 8) as $axis) {
            $value = $row['dimension_'.$axis] ?? ($row['dimensions'][$axis] ?? null);

            if ($value !== null && $value !== '') {
                $dimensions[$axis] = (string) $value;
            }
        }

        return new self(
            accountCode: self::text($row['account_code'] ?? $row['account'] ?? null),
            amount: self::text($row['amount'] ?? null),
            currency: self::currency($row['currency'] ?? null),
            narration: self::text($row['narration'] ?? null),
            dimensions: $dimensions,
        );
    }

    /**
     * Whether the row is empty, which is not the same as invalid.
     *
     * The grid always has a blank row at the bottom. Treating it as an error
     * would mean the screen showed a problem with a row nobody has touched.
     */
    public function isBlank(): bool
    {
        return $this->accountCode === null
            && $this->amount === null
            && $this->narration === null
            && $this->dimensions === [];
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function currency(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : mb_strtoupper($text);
    }
}
