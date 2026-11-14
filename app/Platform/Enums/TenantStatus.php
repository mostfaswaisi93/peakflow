<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * Where a tenant is in its life.
 *
 * `Migrating` is the one that is easy to leave out and expensive to add: a
 * tenant whose database is mid-migration must answer 503 with Retry-After
 * rather than fail on a missing column. A maintenance window that is visible
 * is tolerable; one that presents as random errors is not.
 */
enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Migrating = 'migrating';
    case Archived = 'archived';

    /** Can a request be served against this tenant at all? */
    public function isServable(): bool
    {
        return $this === self::Active;
    }

    /** Is this a documented, temporary state rather than a failure? */
    public function isTransient(): bool
    {
        return $this === self::Provisioning || $this === self::Migrating;
    }

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => __('قيد التهيئة'),
            self::Active => __('نشط'),
            self::Suspended => __('موقوف'),
            self::Expired => __('منتهي'),
            self::Migrating => __('قيد الترقية'),
            self::Archived => __('مؤرشف'),
        };
    }
}
