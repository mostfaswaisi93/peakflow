<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;

/**
 * A rate feed, and where it sits in the precedence order - PF-069.
 *
 * Precedence rather than a single configured feed, because the requirement
 * here is unusual: a rate feed outage **must not stop a business trading**.
 * An invoice that cannot be issued because a third party's API is down is a
 * worse outcome than an invoice issued at yesterday's rate, and the second
 * one is correctable.
 *
 * So the fetch walks providers in order, falls back to the last known rate,
 * and raises an alert. The alert is the part that is easy to drop and the
 * part that matters: silently trading on a week-old rate is how a month of
 * invoices acquires a difference nobody can locate.
 *
 * @property string $code
 * @property int $precedence
 * @property int $consecutive_failures
 */
final class FxProvider extends TenantModel
{
    protected $table = 'fx_providers';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'precedence',
        'active',
        'supplies_rate_sets',
        'last_success_at',
        'last_failure_at',
        'last_failure_reason',
        'consecutive_failures',
    ];

    protected $casts = [
        'precedence' => 'integer',
        'active' => 'boolean',
        'supplies_rate_sets' => 'array',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'consecutive_failures' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return [];
    }

    public function supplies(string $rateSet): bool
    {
        $sets = $this->supplies_rate_sets;

        // Null means "everything it is asked for". A provider that supplies
        // nothing would be configured inactive, not configured with an empty
        // list, so an empty list is read as unrestricted rather than as a
        // provider that is silently skipped every time.
        return $sets === null || $sets === [] || in_array($rateSet, $sets, true);
    }
}
