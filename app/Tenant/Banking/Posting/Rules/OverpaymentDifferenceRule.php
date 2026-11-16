<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Posting\Rules;

use App\Tenant\Banking\Enums\DifferenceType;

/**
 * The customer paid more than the invoice - PF-164.
 *
 * ## It is owed back, so it sits on the customer
 *
 * Against receivables with the party, which makes their balance negative -
 * which is exactly what an overpayment is. Posting it to income would
 * recognise revenue for a sale nobody made, and the customer would still
 * be owed the money: a liability hidden inside a revenue figure, found at
 * the point the customer asks for it back.
 *
 * ## Approval above tolerance
 *
 * A small overpayment is a rounding habit. A large one is usually a
 * duplicate payment or a payment meant for a different supplier, and
 * absorbing it quietly is how a customer's money ends up in our revenue
 * with nobody having decided that.
 */
final class OverpaymentDifferenceRule extends DifferenceRule
{
    public function type(): DifferenceType
    {
        return DifferenceType::Overpayment;
    }

    protected function because(): string
    {
        return 'what the customer paid beyond the invoice, which is owed back to them';
    }
}
