<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Support\Money\Money;
use JsonSerializable;

/**
 * A document's VAT breakdown and its totals - PF-082.
 *
 * ## `BR-CO-14` is structural here, and asserted anyway
 *
 * The tax total is the sum of the entries, because that is how it is built -
 * there is no second accumulator to drift from it. {@see satisfiesBrCo14()}
 * recomputes it anyway, because "it cannot differ by construction" is a claim
 * about today's code and the rule is a claim about the document. A check that
 * only held while nobody refactored would be worth very little.
 *
 * ## Totals, by their EN 16931 numbers
 *
 * - `BT-109` sum of line nets, after document-level adjustments
 * - `BT-110` the tax total
 * - `BT-112` the gross: `BT-109` + `BT-110`
 */
final readonly class TaxBreakdown implements JsonSerializable
{
    /** @param  list<TaxBreakdownEntry>  $entries */
    public function __construct(
        public array $entries,
        public Money $net,
        public Money $tax,
    ) {}

    public function gross(): Money
    {
        return $this->net->plus($this->tax);
    }

    /** The tax total recomputed from the entries. */
    public function sumOfEntries(): Money
    {
        $sum = Money::zero($this->tax->currency);

        foreach ($this->entries as $entry) {
            $sum = $sum->plus($entry->taxAmount);
        }

        return $sum;
    }

    /** The taxable amounts recomputed from the entries. */
    public function sumOfTaxableAmounts(): Money
    {
        $sum = Money::zero($this->net->currency);

        foreach ($this->entries as $entry) {
            $sum = $sum->plus($entry->taxableAmount);
        }

        return $sum;
    }

    public function satisfiesBrCo14(): bool
    {
        return $this->tax->equals($this->sumOfEntries());
    }

    public function entryFor(string $category, string $ratePercent): ?TaxBreakdownEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->category->value === $category && $entry->ratePercent === $ratePercent) {
                return $entry;
            }
        }

        return null;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'BT-109' => $this->net->jsonSerialize(),
            'BT-110' => $this->tax->jsonSerialize(),
            'BT-112' => $this->gross()->jsonSerialize(),
            'BG-23' => array_map(
                static fn (TaxBreakdownEntry $entry): array => $entry->jsonSerialize(),
                $this->entries,
            ),
        ];
    }
}
