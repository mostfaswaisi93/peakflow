<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Enums;

/**
 * How a jurisdiction expects an issued document to be corrected - PF-098.
 *
 * Not a user preference. Both mechanisms produce the same balances; what
 * differs is which document the authority expects to receive and match.
 */
enum CorrectionMechanism: string
{
    /** The invoice stands; a credit or debit note adjusts it. */
    case CreditNote = 'credit_note';

    /** A replacement document carries the corrected figures. */
    case CorrectedInvoice = 'corrected_invoice';

    /**
     * Either is accepted.
     *
     * Declared rather than inferred: a regime that genuinely accepts both is
     * a regime where forcing a choice would be inventing a rule.
     */
    case Both = 'both';

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::CreditNote => $arabic ? 'إشعار دائن أو مدين' : 'a credit or debit note',
            self::CorrectedInvoice => $arabic ? 'فاتورة مصححة بديلة' : 'a replacement corrected invoice',
            self::Both => $arabic ? 'أي من الطريقتين' : 'either mechanism',
        };
    }
}
