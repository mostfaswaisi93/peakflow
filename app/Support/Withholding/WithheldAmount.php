<?php

declare(strict_types=1);

namespace App\Support\Withholding;

use App\Support\Money\Money;

/**
 * A deduction a payer took, as settlement sees it - PF-083.
 *
 * ## Why a value and not Tax's own object
 *
 * Tax has `WithholdingDeduction`, which carries the rate row's id and the
 * source it was quoted from - provenance the certificate needs and the
 * caller does not. Settlement needs four things: what the base was, what
 * was kept back, at what rate, and under which jurisdiction. Passing the
 * richer object across the boundary would make every caller depend on a
 * shape that exists for Tax's reasons.
 *
 * Carried back to `certify()` rather than recomputed there, so the figure
 * that was posted and the figure on the certificate cannot differ by a
 * rounding mode.
 */
final readonly class WithheldAmount
{
    public function __construct(
        public Money $base,
        public Money $withheld,
        public string $ratePercent,
        public string $jurisdiction,
    ) {}

    /** Is this a configured rate of zero - an exemption, rather than nothing to do? */
    public function isZero(): bool
    {
        return $this->withheld->isZero();
    }
}
