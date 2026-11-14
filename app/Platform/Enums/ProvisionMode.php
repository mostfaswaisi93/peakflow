<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * How a tenant's database came to exist.
 *
 * Recorded on the tenant rather than inferred from config, because THE
 * BACKUP AND RESTORE PROCEDURE DIFFERS between them - and per-tenant
 * point-in-time restore is the requirement that chose database-per-tenant
 * over row-level security in the first place (ADR-0003), so knowing which
 * mode a tenant is in is not a detail.
 */
enum ProvisionMode: string
{
    /** PeakFlow issued CREATE DATABASE / CREATE ROLE / GRANT. */
    case Create = 'create';

    /** A pre-created database was attached, for hosting without those privileges. */
    case Link = 'link';

    /** Can PeakFlow drop this database as part of tenant deletion? */
    public function ownsDatabase(): bool
    {
        return $this === self::Create;
    }

    public function label(): string
    {
        return match ($this) {
            self::Create => __('قاعدة بيانات مُنشأة'),
            self::Link => __('قاعدة بيانات مرتبطة'),
        };
    }
}
