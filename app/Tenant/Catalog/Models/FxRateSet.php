<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;

/**
 * A coexisting set of rates - PF-068.
 *
 * Three of them, seeded by the migration, because all three are needed by the
 * first entity that trades abroad:
 *
 *   market           the rate on the day, for transactions
 *   monthly_average  for translating profit or loss under IAS 21
 *   statutory        what a tax authority mandates, which is frequently not
 *                    the rate the treasury actually dealt at
 *
 * They are a table rather than an enum because a jurisdiction can require a
 * fourth, and because the staleness threshold differs per set: a market rate
 * three days old is a problem and a monthly average three days after month
 * end is simply the current one.
 *
 * @property string $code
 * @property int $staleness_threshold_days
 */
final class FxRateSet extends TenantModel
{
    protected $table = 'fx_rate_sets';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'name', 'purpose', 'staleness_threshold_days', 'is_default', 'active'];

    protected $casts = [
        'staleness_threshold_days' => 'integer',
        'is_default' => 'boolean',
        'active' => 'boolean',
    ];

    /*
     | HasUlids would generate a key for a table whose key is a human-written
     | code. The trait comes from TenantModel, so it is switched off here
     | rather than removed there.
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
