<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use App\Tenant\Tax\Enums\TaxCategory;

/**
 * One line, as the tax calculator needs to see it - PF-082.
 *
 * Its net is the line amount AFTER line-level discounts and charges, because
 * those belong to the line and the tax engine should not be apportioning
 * something that was already allocated. Document-level adjustments are a
 * separate input, for the opposite reason: they belong to no line and have to
 * be spread before tax.
 *
 * The rate travels with the line rather than being looked up here. The lookup
 * is `TaxCodes::resolve()`, which needs a date and a jurisdiction, and a
 * calculator that resolved its own rates would be a calculator nobody can
 * exercise on a case they thought of.
 */
final readonly class TaxableLine
{
    public function __construct(
        public Money $net,
        public TaxCategory $category,
        public string $ratePercent,
        public ?string $reasonCode = null,
    ) {}

    public static function of(
        Money $net,
        TaxCategory $category,
        string $ratePercent = '0',
        ?string $reasonCode = null,
    ): self {
        return new self($net, $category, $ratePercent, $reasonCode);
    }

    /**
     * The group this line belongs to: EN 16931's category AND rate.
     *
     * Both, because `BG-23` is one entry per unique combination. A document
     * with 15% standard and 5% standard lines has two entries, and a
     * breakdown keyed on category alone would merge them and report a rate
     * that applies to neither.
     */
    public function groupKey(): string
    {
        return $this->category->value.'@'.$this->normalisedRate();
    }

    /** The rate with trailing zeros settled, so `15` and `15.00` are one group. */
    public function normalisedRate(): string
    {
        return (string) BigDecimal::of($this->ratePercent)->strippedOfTrailingZeros();
    }
}
