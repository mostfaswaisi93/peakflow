<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line of a recurring template - PF-105. */
final class RecurringScheduleLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'recurring_schedule_lines';

    protected $fillable = [
        'entity_id', 'recurring_schedule_id', 'line_no', 'item_id', 'item_code',
        'description', 'unit_code', 'quantity', 'unit_price',
        'revenue_account_id', 'tax_code_id', 'tax_category', 'tax_rate',
        'service_from', 'service_until',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'service_from' => 'immutable_date',
        'service_until' => 'immutable_date',
    ];

    /** @return BelongsTo<RecurringSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RecurringSchedule::class, 'recurring_schedule_id');
    }

    /** Does this line's price come from the price list rather than the template? */
    public function resolvesItsPrice(): bool
    {
        return $this->unit_price === null;
    }
}
