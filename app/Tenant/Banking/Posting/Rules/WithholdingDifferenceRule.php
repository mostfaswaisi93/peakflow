<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Posting\Rules;

use App\Tenant\Banking\Enums\DifferenceType;

/**
 * Tax the customer deducted before paying us - PF-164, resolved by PF-083.
 *
 * ## This is the expensive one to get wrong
 *
 * A customer in a withholding jurisdiction pays 95 against an invoice for
 * 100 and keeps 5 for the tax authority, against which they issue a
 * certificate. That 5 is **our money**: a credit we claim against our own
 * liability when we file.
 *
 * Treated as a short payment it is written off to bad debt, the credit is
 * never claimed, and the loss is a few percent of revenue in every
 * withholding jurisdiction the business sells into - quietly, with every
 * individual write-off looking like a small customer dispute.
 *
 * ## Which is why it resolves elsewhere
 *
 * The rate, the certificate and the claim belong to withholding tax
 * (PF-083). This rule moves the money to the right control account so the
 * receivable closes correctly; the certificate is what makes the credit
 * claimable, and a credit sitting here without one is a credit the
 * authority disallows. The classifier says so rather than treating the
 * posting as the end of it.
 */
final class WithholdingDifferenceRule extends DifferenceRule
{
    public function type(): DifferenceType
    {
        return DifferenceType::Withholding;
    }

    protected function because(): string
    {
        return 'tax the customer deducted at source, which is a credit against our own liability';
    }
}
