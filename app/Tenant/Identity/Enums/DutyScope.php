<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Enums;

/**
 * What "the same thing" means for a segregation-of-duties rule - PF-033.
 *
 * The scope is the whole substance of the rule. "The same person may not
 * prepare and release a payment" is a useful control; "the same person may not
 * prepare any payment and release any payment" would stop a two-person finance
 * team from working at all, and so would be switched off within a week.
 */
enum DutyScope: string
{
    /** The same document: this bill, this invoice, this return. */
    case Object = 'object';

    /**
     * The same counterparty, across documents.
     *
     * This is the one that catches the fraud the others miss: changing a
     * supplier's bank details and then releasing a payment to that supplier
     * are two duties on two different objects, and the conflict only exists
     * when you look at the party they share.
     */
    case Party = 'party';

    /** The same accounting period: posting into it, then closing it. */
    case Period = 'period';

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::Object => $locale === 'ar' ? 'نفس المستند' : 'the same document',
            self::Party => $locale === 'ar' ? 'نفس الطرف' : 'the same counterparty',
            self::Period => $locale === 'ar' ? 'نفس الفترة' : 'the same period',
        };
    }
}
