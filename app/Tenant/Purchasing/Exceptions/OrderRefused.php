<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Exceptions;

/**
 * Why an order, receipt or match was refused - PF-131, PF-132, PF-135.
 *
 * The messages say what to do about it rather than what went wrong, because
 * the person reading one is a buyer or a warehouse clerk and "constraint
 * violation" is not an instruction.
 */
final class OrderRefused extends \RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }

    public static function theOrderIsNotConfirmed(string $label, string $status): self
    {
        return new self(sprintf(
            'Order %s is %s, so nothing can be received against it. A receipt against a draft order '.
            'records an arrival nobody authorised, and against a closed one records an arrival whose '.
            'commitment was already released.',
            $label,
            $status,
        ));
    }

    public static function aReceiptOfNothing(): self
    {
        return new self(
            'A receipt with no lines records no arrival. If the delivery was empty, the thing to record '.
            'is that nothing came - which is a note on the order, not a receipt.',
        );
    }

    public static function theLineBelongsToAnotherOrder(): self
    {
        return new self(
            'That line belongs to a different order. A receipt spanning two orders cannot release the '.
            'right commitment on either, because the quantities would be attributed to whichever order '.
            'the code happened to load first.',
        );
    }
}
