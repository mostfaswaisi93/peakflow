<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Enums;

/**
 * When a line's revenue is earned - PF-107, IFRS 15.
 *
 * ## Why this is per LINE and not per document
 *
 * One invoice is routinely two performance obligations: the licence that is
 * earned on delivery and the support that is earned over twelve months. A
 * document-level setting forces the business to split the invoice or to
 * misstate one of the two, and splitting it for an accounting reason is a
 * second document the customer did not ask for.
 */
enum RecognitionBasis: string
{
    /**
     * Earned when the document is issued.
     *
     * The default, and correct for most invoices: goods delivered, work
     * done, a thing sold. Nothing is deferred and nothing has to be
     * released, which is why it is also the shape that must not need
     * configuring.
     */
    case Immediate = 'immediate';

    /**
     * Earned evenly over a service period.
     *
     * The period is the line's `service_from` to `service_until`, and both
     * are required - a straight-line schedule over an unknown period is a
     * schedule nobody can compute.
     */
    case StraightLine = 'straight_line';

    /**
     * Earned when a milestone is reached.
     *
     * The milestones are rows, because somebody has to say when each one is
     * met - and a date set in advance is a straight-line schedule with extra
     * steps.
     */
    case Milestone = 'milestone';

    /**
     * Earned on delivery, which somebody confirms.
     *
     * Distinct from `milestone` only in who confirms it and against what:
     * delivery is one event with a date, and a milestone is a list.
     */
    case OnDelivery = 'on_delivery';

    public function defersRevenue(): bool
    {
        return $this !== self::Immediate;
    }

    /** Does a schedule of planned releases exist for this basis? */
    public function isScheduled(): bool
    {
        return $this === self::StraightLine;
    }

    /** Does something outside the document have to happen first? */
    public function waitsForAnEvent(): bool
    {
        return $this === self::Milestone || $this === self::OnDelivery;
    }

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::Immediate => $arabic ? 'فوري' : 'immediate',
            self::StraightLine => $arabic ? 'على مدى فترة الخدمة' : 'straight-line over the service period',
            self::Milestone => $arabic ? 'عند المراحل' : 'on milestones',
            self::OnDelivery => $arabic ? 'عند التسليم' : 'on delivery',
        };
    }
}
