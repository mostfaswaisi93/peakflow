<?php

declare(strict_types=1);

namespace App\Platform\Enums;

enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * Does this status entitle the tenant to work?
     *
     * `PastDue` deliberately DOES. Cutting a paying customer off the moment a
     * card fails is how a billing hiccup becomes a business interruption; the
     * grace window in config/tenancy.php is what eventually suspends them.
     */
    public function entitlesAccess(): bool
    {
        return match ($this) {
            self::Trial, self::Active, self::PastDue => true,
            self::Suspended, self::Expired, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => __('تجريبي'),
            self::Active => __('نشط'),
            self::PastDue => __('متأخر السداد'),
            self::Suspended => __('موقوف'),
            self::Expired => __('منتهي'),
            self::Cancelled => __('ملغى'),
        };
    }
}
