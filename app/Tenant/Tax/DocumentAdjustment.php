<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Support\Money\Money;

/**
 * A document-level allowance or charge, to be spread before tax - PF-082.
 *
 * ## Why it cannot simply be a line
 *
 * Because it has no rate of its own. A 7% end-of-document discount on an
 * invoice carrying 15% and 5% lines reduces the taxable amount of both, in
 * proportion - and the proportion is what makes it a document-level
 * adjustment rather than a line. Treating it as a line means choosing a rate
 * for it, and any choice is wrong for part of the document.
 *
 * EN 16931 models these as `BG-20` (document level allowances) and `BG-21`
 * (document level charges), each with its own rate group. PeakFlow apportions
 * instead, which gives the same tax totals and avoids asking a user which
 * rate their discount belongs to.
 */
final readonly class DocumentAdjustment
{
    private function __construct(
        public Money $amount,
        public bool $isCharge,
        public string $reason,
    ) {}

    /** Reduces the taxable amount. A discount. */
    public static function allowance(Money $amount, string $reason = ''): self
    {
        return new self($amount->abs(), false, $reason);
    }

    /** Increases it. Freight, handling, a late-order surcharge. */
    public static function charge(Money $amount, string $reason = ''): self
    {
        return new self($amount->abs(), true, $reason);
    }

    /** The signed effect on the taxable amount. */
    public function signed(): Money
    {
        return $this->isCharge ? $this->amount : $this->amount->negated();
    }
}
