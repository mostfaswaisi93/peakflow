<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * What a period will accept - PF-050.
 *
 * The order of the cases is the order of the lifecycle, and the only
 * backwards transition is `closed` to `open`, which costs a permission, a
 * step-up assertion and a written reason.
 */
enum PeriodState: string
{
    /** Exists so nobody is blocked in January. Accepts nothing. */
    case Future = 'future';

    case Open = 'open';

    /** The operational close has happened; the audit has not finished. */
    case SoftClosed = 'soft_closed';

    case Closed = 'closed';

    /**
     * Filed with an authority. Never again, by anyone, and there is
     * deliberately no application path back - a product that can un-lock a
     * filed period will be asked to, at 6pm, by someone senior.
     */
    case Locked = 'locked';

    public function acceptsPostings(): bool
    {
        return $this === self::Open;
    }

    public function acceptsAdjustments(): bool
    {
        return $this === self::Open || $this === self::SoftClosed;
    }

    public function isTerminal(): bool
    {
        return $this === self::Locked;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Future => [self::Open],
            self::Open => [self::SoftClosed, self::Closed],
            self::SoftClosed => [self::Open, self::Closed],
            self::Closed => [self::Open, self::Locked],
            self::Locked => [],
        };
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::Future => $locale === 'ar' ? 'مستقبلية' : 'future',
            self::Open => $locale === 'ar' ? 'مفتوحة' : 'open',
            self::SoftClosed => $locale === 'ar' ? 'مقفلة مبدئياً' : 'soft closed',
            self::Closed => $locale === 'ar' ? 'مقفلة' : 'closed',
            self::Locked => $locale === 'ar' ? 'مقفلة نهائياً' : 'locked',
        };
    }
}
