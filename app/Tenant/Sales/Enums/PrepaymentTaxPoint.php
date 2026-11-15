<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Enums;

/**
 * When a jurisdiction taxes money taken before performance - PF-099.
 *
 * The difference is a quarter's cash rather than a rounding. Taxing at the
 * final invoice under a regime that taxes on receipt files a return short by
 * the tax on every prepayment taken in the period - and the authority
 * already has the other side of it, because the customer claimed the input
 * tax from the prepayment invoice.
 */
enum PrepaymentTaxPoint: string
{
    /** The tax point is the earlier of payment and invoice. */
    case AtPrepayment = 'at_prepayment';

    /** The prepayment is a cash movement; the supply is taxed when it is invoiced. */
    case AtFinalInvoice = 'at_final_invoice';

    public function taxesThePrepayment(): bool
    {
        return $this === self::AtPrepayment;
    }

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::AtPrepayment => $arabic
                ? 'تُستحق الضريبة عند الدفعة المقدمة'
                : 'the tax falls due on the prepayment',
            self::AtFinalInvoice => $arabic
                ? 'تُستحق الضريبة عند الفاتورة النهائية'
                : 'the tax falls due on the final invoice',
        };
    }
}
