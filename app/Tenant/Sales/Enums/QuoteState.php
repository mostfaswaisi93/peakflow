<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Enums;

/**
 * Where a quote is in its life - PF-089.
 *
 * ## Why `superseded` is a state and not a flag
 *
 * A revised quote leaves the previous version in the record, and that
 * version is neither open nor refused: it is the price that used to be
 * offered. A customer reading an email from last week is reading it, so it
 * cannot be deleted, and it must not be acceptable.
 */
enum QuoteState: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Superseded = 'superseded';

    /** Can the customer still act on it? */
    public function isOpen(): bool
    {
        return $this === self::Sent;
    }

    /** Is this the end of the road for this version? */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Accepted, self::Declined, self::Expired, self::Superseded => true,
            self::Draft, self::Sent => false,
        };
    }

    /**
     * The states a quote may move to from here.
     *
     * A draft can be sent or revised. A sent quote can be accepted,
     * declined, expired or revised. Everything else is over: re-opening an
     * expired quote would change what a customer was told without leaving a
     * record of the change, which is what a revision is for.
     *
     * @return list<self>
     */
    public function mayBecome(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Superseded],
            self::Sent => [self::Accepted, self::Declined, self::Expired, self::Superseded],
            default => [],
        };
    }

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::Draft => $arabic ? 'مسودة' : 'draft',
            self::Sent => $arabic ? 'مُرسَل' : 'sent',
            self::Accepted => $arabic ? 'مقبول' : 'accepted',
            self::Declined => $arabic ? 'مرفوض' : 'declined',
            self::Expired => $arabic ? 'منتهي الصلاحية' : 'expired',
            self::Superseded => $arabic ? 'مُستبدَل' : 'superseded',
        };
    }
}
