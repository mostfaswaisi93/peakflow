<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billing moment of a schedule, claimed before any work is done - PF-106.
 *
 * The row is written first and the invoice afterwards, so the claim exists
 * before the work: a generator interrupted between the two leaves an
 * occurrence with no invoice, which the next run retries, rather than an
 * invoice nobody has a record of asking for.
 */
final class RecurringOccurrence extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'recurring_occurrences';

    protected $fillable = [
        'entity_id', 'recurring_schedule_id', 'occurrence_date', 'status',
        'invoice_id', 'attempts', 'last_error', 'generated_at', 'failed_at',
    ];

    protected $casts = [
        'occurrence_date' => 'immutable_date',
        'generated_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
        'attempts' => 'integer',
    ];

    /** @return BelongsTo<RecurringSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RecurringSchedule::class, 'recurring_schedule_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
