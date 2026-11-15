<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Rate;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One stored exchange rate - PF-068.
 *
 * ## There is no `inverse()` here either
 *
 * The absence is the design. `App\Support\Money\Rate` refuses to convert in
 * the direction it was not stored for, and this model offers no way to
 * produce the missing direction from the one it has. If `EUR -> SAR` is
 * needed, a `EUR -> SAR` row is fetched; `1 / (SAR -> EUR)` is never
 * computed, because the reciprocal of a rounded number is not the rate and
 * the difference shows up as an unexplainable consolidation variance.
 *
 * ## Why this model is read-mostly
 *
 * A stored rate is immutable - a database trigger refuses an UPDATE to the
 * rate, its direction, its date, its set or its provider. A correction is a
 * new row that supersedes this one. So this model has no `update` path for
 * those columns at all, and the only writes are the supersession pointer and
 * the withdrawal note.
 *
 * @property string $rate_set
 * @property string $from_currency
 * @property string $to_currency
 * @property string $provider
 */
final class FxRate extends TenantModel
{
    protected $table = 'fx_rates';

    protected $fillable = [
        'rate_set',
        'from_currency',
        'to_currency',
        'as_of_date',
        'rate',
        'provider',
        'fetched_at',
        'source_payload',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'fetched_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'source_payload' => 'array',
    ];

    /**
     * The rate as a value object, which is the only form that can convert.
     *
     * The model is a row; `Rate` is the thing that knows a conversion has a
     * direction and refuses the wrong one. Nothing in the application should
     * multiply by `$model->rate` directly - that is the arithmetic `Rate`
     * exists to make impossible to get backwards.
     */
    public function toRate(): Rate
    {
        return new Rate(
            from: $this->from_currency,
            to: $this->to_currency,
            value: (string) $this->rate,
            date: new DateTimeImmutable((string) $this->as_of_date?->toDateString()),
            provider: $this->provider,
            rateSet: $this->rate_set,
        );
    }

    /**
     * Rates still in force: not withdrawn, not superseded by a correction.
     *
     * @param  Builder<FxRate>  $query
     * @return Builder<FxRate>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('withdrawn_at')->whereNull('superseded_by_id');
    }
}
