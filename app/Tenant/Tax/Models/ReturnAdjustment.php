<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Models;

use App\Support\Money\Money;
use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A correction to a filed return, reported in a later one - PF-087.
 *
 * @property string $jurisdiction
 * @property string $box
 * @property string $currency
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $reason
 */
final class ReturnAdjustment extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'return_adjustments';

    protected $fillable = [
        'entity_id', 'jurisdiction', 'corrects_period_id', 'reported_in_period_id',
        'box', 'currency', 'net_amount', 'tax_amount', 'reason', 'created_by',
    ];

    /** @return BelongsTo<ReturnPeriod, $this> */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(ReturnPeriod::class, 'corrects_period_id');
    }

    /** @return BelongsTo<ReturnPeriod, $this> */
    public function reportedIn(): BelongsTo
    {
        return $this->belongsTo(ReturnPeriod::class, 'reported_in_period_id');
    }

    public function net(): Money
    {
        return Money::of($this->net_amount, $this->currency);
    }

    public function tax(): Money
    {
        return Money::of($this->tax_amount, $this->currency);
    }
}
