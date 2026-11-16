<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Enums;

/**
 * Which way the money went - PF-166.
 *
 * ## The sign lives here, not in the amount
 *
 * A payment amount is always positive and the direction says what it
 * means. The alternative - a signed amount - gives two ways to express
 * "money out" and guarantees a query somewhere reads only one of them:
 * `sum(amount)` over a mixed set is a figure that means nothing, and it is
 * a figure somebody will put on a screen.
 */
enum PaymentDirection: string
{
    case Receipt = 'receipt';
    case Disbursement = 'disbursement';

    /** Whether this direction increases the cash account. */
    public function increasesCash(): bool
    {
        return $this === self::Receipt;
    }

    public function labelEn(): string
    {
        return $this === self::Receipt ? 'receipt' : 'disbursement';
    }

    public function labelAr(): string
    {
        return $this === self::Receipt ? 'مقبوضات' : 'مدفوعات';
    }
}
