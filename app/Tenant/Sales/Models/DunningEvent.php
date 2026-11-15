<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step, applied to one invoice, on one day - PF-108.
 *
 * Where the idempotency lives: `(invoice, step, day)` is unique, so cron
 * firing twice, a retry after a timeout and somebody running the command by
 * hand all produce one event.
 *
 * @property array<int, string> $actions_taken
 */
final class DunningEvent extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'dunning_events';

    protected $fillable = [
        'entity_id', 'invoice_id', 'dunning_policy_step_id', 'dunning_notice_id',
        'party_id', 'occurred_on', 'days_past_due', 'amount_due', 'currency',
        'actions_taken', 'fee_invoice_id',
    ];

    protected $casts = [
        'occurred_on' => 'immutable_date',
        'days_past_due' => 'integer',
        'actions_taken' => 'array',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<DunningPolicyStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(DunningPolicyStep::class, 'dunning_policy_step_id');
    }

    public function amountDue(): Money
    {
        return Money::of((string) $this->amount_due, (string) $this->currency);
    }
}
