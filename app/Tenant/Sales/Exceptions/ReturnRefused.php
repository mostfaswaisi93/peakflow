<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use RuntimeException;

/**
 * Goods that cannot come back, and why - PF-252.
 *
 * Every refusal here is read at a counter, by somebody holding a parcel
 * with a customer in front of them. So each one says what is wrong with
 * THIS return rather than naming a rule: "that line sold 10 and 10 has
 * already come back" is actionable, and "quantity exceeds permitted"
 * sends them to a manager.
 */
final class ReturnRefused extends RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }

    public static function anUnissuedInvoice(string $status): self
    {
        return new self(sprintf(
            'Goods come back against an issued invoice, and this one is %s. There is nothing to credit '.
            'yet - change the draft instead.',
            $status,
        ));
    }

    public static function aReturnThatIsNotADraft(string $state): self
    {
        return new self(sprintf(
            'This return is already %s, so its lines are what the credit note was built from. Open '.
            'another return for anything else that comes back.',
            $state,
        ));
    }

    public static function aReturnOfNothing(): self
    {
        return new self(
            'This return has no lines, so there is nothing to credit and nothing to put back on a shelf.',
        );
    }

    /**
     * The sale never moved stock, so the cost it comes back at is unknown.
     *
     * Deliberately a refusal rather than a guess. The alternatives are
     * today's average cost, which restores inventory at a figure the sale
     * never took out, and the selling price, which would put margin into
     * inventory. Both are wrong in a way that only a count finds.
     */
    public static function aSaleThatNeverMovedStock(string $description): self
    {
        return new self(sprintf(
            'The sale of "%s" did not move stock, so nobody knows what this unit cost when it left - and '.
            'bringing it back at today\'s average would restore inventory at a figure the sale never took '.
            'out of it. Record the issue against the invoice line first.',
            $description,
        ));
    }

    public static function aLineOfAnotherInvoice(string $lineId): self
    {
        return new self(sprintf(
            'Line %s belongs to a different invoice from the one this return names, so crediting it here '.
            'would credit a document nobody is looking at.',
            $lineId,
        ));
    }

    public static function anUnstockedItem(string $description): self
    {
        return new self(sprintf(
            '"%s" is not stocked, so there is no shelf to put it back on. Credit it with a credit note '.
            'rather than a return.',
            $description,
        ));
    }
}
