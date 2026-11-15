<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a dunning policy - PF-108.
 *
 * @property int $days_past_due
 * @property string $channel
 * @property array<int, string> $actions
 */
final class DunningPolicyStep extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'dunning_policy_steps';

    protected $fillable = [
        'entity_id', 'dunning_policy_id', 'sequence', 'days_past_due',
        'channel', 'template', 'actions', 'late_fee_percent', 'late_fee_minimum',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'days_past_due' => 'integer',
        'actions' => 'array',
    ];

    /** @return BelongsTo<DunningPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(DunningPolicy::class, 'dunning_policy_id');
    }

    public function does(string $action): bool
    {
        return \in_array($action, (array) $this->actions, true);
    }

    /** Is this step a courtesy reminder before the invoice is even due? */
    public function isBeforeDue(): bool
    {
        return $this->days_past_due < 0;
    }
}
