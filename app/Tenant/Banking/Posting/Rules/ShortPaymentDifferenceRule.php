<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Posting\Rules;

use App\Tenant\Banking\Enums\DifferenceType;

/**
 * The customer paid less, and we are not chasing it - PF-164.
 *
 * ## A credit loss, not a discount
 *
 * The sale happened at the invoiced figure and the customer did not pay
 * all of it. Restating revenue instead would make the margin on that sale
 * look correct while the cash never arrived - and the pattern of which
 * customers short-pay, which is the useful information here, would be
 * spread across the revenue accounts where nobody can see it.
 *
 * ## Approval, always, whatever the figure
 *
 * The amount is not what makes this a decision. Deciding that a customer
 * need not pay is what makes it one, and a tolerance that let small ones
 * through without a name against them would be a tolerance for deciding
 * that quietly - one invoice at a time, each looking like housekeeping.
 */
final class ShortPaymentDifferenceRule extends DifferenceRule
{
    public function type(): DifferenceType
    {
        return DifferenceType::ShortPayment;
    }

    protected function because(): string
    {
        return 'the part of the invoice the customer did not pay and we have decided not to chase';
    }
}
