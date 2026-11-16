<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Posting\Rules;

use App\Tenant\Banking\Enums\DifferenceType;

/**
 * Two correct figures that differ by a unit - PF-164.
 *
 * ## The receivable is closed and the difference is named
 *
 * Not quietly shrunk. A receivable reduced to make it agree with a payment
 * is a figure that no longer traces to the invoice behind it, and the 0.01
 * nobody posted is the 0.01 that makes a control account disagree with its
 * subledger by an amount too small for anybody to investigate and too
 * persistent to go away.
 *
 * ## No approval, by design
 *
 * A rounding difference is arithmetic. An approval step on 0.01 is an
 * approval step people click through, and the habit carries to the ones
 * that matter.
 */
final class RoundingDifferenceRule extends DifferenceRule
{
    public function type(): DifferenceType
    {
        return DifferenceType::Rounding;
    }

    protected function because(): string
    {
        return 'the last unit, where two correct figures differ by one';
    }
}
