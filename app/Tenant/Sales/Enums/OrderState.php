<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Enums;

/**
 * Where a sales order is - PF-090.
 *
 * `completed` is separate from `confirmed` because the difference is whether
 * anything is still owed: a confirmed order with nothing left to invoice
 * still shows against the customer's credit exposure until something says it
 * is finished, and an exposure figure that includes finished work is one
 * nobody trusts.
 */
enum OrderState: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Does it count towards the customer's exposure? */
    public function isCommitted(): bool
    {
        return $this === self::Confirmed;
    }

    public function mayBeInvoiced(): bool
    {
        return $this === self::Confirmed;
    }

    /** @return list<self> */
    public function mayBecome(): array
    {
        return match ($this) {
            self::Draft => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::Cancelled],
            default => [],
        };
    }

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::Draft => $arabic ? 'مسودة' : 'draft',
            self::Confirmed => $arabic ? 'مؤكَّد' : 'confirmed',
            self::Completed => $arabic ? 'مُنفَّذ' : 'completed',
            self::Cancelled => $arabic ? 'ملغى' : 'cancelled',
        };
    }
}
