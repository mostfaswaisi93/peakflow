<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * A tax posting that will not be made - PF-084.
 */
final class TaxPostingRefused extends RuntimeException
{
    public static function recoverableExceedsTheTax(string $recoverable, string $tax): self
    {
        return new self(sprintf(
            'The recoverable part is %s and the tax charged was %s. A business cannot reclaim more than '.
            'it was charged, and the entry would still balance - the excess would simply sit in the '.
            'input account as a claim the authority will refuse.',
            $recoverable,
            $tax,
        ));
    }

    public static function irrecoverableTaxNeedsAnAccount(string $amount): self
    {
        return new self(sprintf(
            'There is %s of irrecoverable tax and no account to post it to. It must not go to the input '.
            'account: that account is supposed to equal what the business is claiming back, and tax it '.
            'cannot reclaim sitting in it overstates the claim by exactly that amount every month, in a '.
            'figure nobody can derive without re-reading the documents. It is a cost of what was '.
            'bought, so it belongs on an expense account the caller names.',
            $amount,
        ));
    }

    public static function aTaxCodeNeedsATaxPoint(string $rule): self
    {
        return new self(sprintf(
            'The %s posting carries a tax code and no tax point. The tax point is what decides which '.
            'RETURN the line falls into - a supply delivered on 30 May and invoiced on 1 June is in '.
            'June\'s books and May\'s return - so a tax-bearing line without one is a line no return '.
            'can see. The database pairs the two columns; this says which event field is missing.',
            $rule,
        ));
    }

    public static function aTaxPointNeedsATaxCode(string $rule): self
    {
        return new self(sprintf(
            'The %s posting carries a tax point and no tax code. A tax point on a line that bears no '.
            'tax means nothing, and it would put the line in a return\'s drill-down with no box to '.
            'belong to.',
            $rule,
        ));
    }

    public static function reverseChargeNeedsBothSides(): self
    {
        return new self(
            'A reverse charge posts both the output and the input tax, and this event gave only one. The '.
            'half most systems forget is the output side, and forgetting it reports an input claim with '.
            'no corresponding liability - which is the shape of a refund request an authority '.
            'investigates.',
        );
    }

    public static function withholdingExceedsThePayment(string $withheld, string $gross): self
    {
        return new self(sprintf(
            'The withholding of %s is larger than the %s being settled. The supplier would be shown as '.
            'overpaid and the authority as owed more than was deducted.',
            $withheld,
            $gross,
        ));
    }
}
