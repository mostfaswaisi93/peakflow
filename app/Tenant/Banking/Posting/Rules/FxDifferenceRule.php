<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Posting\Rules;

use App\Tenant\Banking\Enums\DifferenceType;

/**
 * The rate moved between invoice and payment - PF-164.
 *
 * ## It is a real gain or loss, not an adjustment to the sale
 *
 * The sale happened at the invoiced figure in the invoice currency, and it
 * is still that. What changed is what that figure is worth in ours, and
 * that difference is a financing outcome - posting it against revenue
 * would make the margin on the sale move with the exchange rate, which
 * reports the sales team on the currency market.
 *
 * ## Approval above tolerance
 *
 * Unlike a bank charge. An exchange difference large enough to need one is
 * usually not an exchange difference: it is a payment at a rate nobody
 * agreed, or the wrong document, or a figure entered in the wrong
 * currency, and all three want somebody to look.
 *
 * ## Distinct from revaluation
 *
 * This is **realized** - the money arrived. The unrealized revaluation of
 * an open balance is `ControlRole::FxRevaluation` and a different account
 * precisely so the two can never be added together by accident.
 */
final class FxDifferenceRule extends DifferenceRule
{
    public function type(): DifferenceType
    {
        return DifferenceType::FxDifference;
    }

    protected function because(): string
    {
        return 'the movement between the rate the document was booked at and the rate the money arrived at';
    }
}
