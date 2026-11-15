<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Enums;

/**
 * Whether the customer accounts for tax itself - PF-078.
 *
 * ## "B2B" means registered, not large
 *
 * The question a place-of-supply rule asks is never "is this a company". It
 * is "can this customer account for the tax in its own jurisdiction", and the
 * answer is a tax registration number that has been validated. An unregistered
 * sole trader buying consulting is a consumer for this purpose, and a
 * multinational with no registration in the destination state is too.
 *
 * So there are two cases and the names say what they mean: `Business` is a
 * customer with a validated registration, `Consumer` is everyone else.
 *
 * ## Why an unvalidated number is not a third case
 *
 * Because the behaviour would be identical to `Consumer` - charge tax - and a
 * third case that behaves like the second is a case that gets treated like the
 * first by whoever writes the next match arm. The registration is validated
 * at the party (PF-061), and what reaches here is the verdict.
 *
 * That direction is deliberate: treating an unvalidated customer as a business
 * reverse-charges a supply that should have been taxed, which leaves the
 * supplier owing the tax it did not collect.
 */
enum CustomerStatus: string
{
    /** A customer with a validated tax registration in its own jurisdiction. */
    case Business = 'business';

    /** Everyone else, including a business that cannot prove it is one. */
    case Consumer = 'consumer';

    public function accountsForItsOwnTax(): bool
    {
        return $this === self::Business;
    }

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::Business => $arabic ? 'منشأة مسجلة' : 'a registered business',
            self::Consumer => $arabic ? 'مستهلك' : 'a consumer',
        };
    }
}
