<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Posting\Rules;

use App\Tenant\Banking\Enums\DifferenceType;

/**
 * A fee the bank took - PF-164.
 *
 * ## Why this is not administrative expenses
 *
 * It could be, and in most small ledgers it is. What that costs is
 * visibility: a customer who short-pays every invoice by the transfer fee,
 * a bank that quietly repriced its correspondent charges, a payment route
 * that costs four times the alternative - each is a number somebody could
 * act on, and each disappears into a line that also holds stationery.
 *
 * ## No approval, whatever the figure
 *
 * A bank charge is not a decision anybody made. Demanding an approval for
 * one would train people to approve without reading, which is how the
 * approvals that matter stop working.
 */
final class BankChargeDifferenceRule extends DifferenceRule
{
    public function type(): DifferenceType
    {
        return DifferenceType::BankCharge;
    }

    protected function because(): string
    {
        return 'the fee the bank took, in an account of its own so a rising charge is a number somebody can see';
    }
}
