<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a customer actually receives - PF-108.
 *
 * One per customer per day, listing every invoice that reached a step. A
 * customer with six overdue invoices gets one statement: the person who
 * receives six emails from a supplier in one morning telephones about the
 * emails rather than about the money.
 */
final class DunningNotice extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'dunning_notices';

    protected $fillable = [
        'entity_id', 'party_id', 'notice_date', 'channel', 'highest_sequence',
        'invoice_count', 'currency', 'total_due', 'sent_at', 'failure',
    ];

    protected $casts = [
        'notice_date' => 'immutable_date',
        'sent_at' => 'immutable_datetime',
        'highest_sequence' => 'integer',
        'invoice_count' => 'integer',
    ];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return HasMany<DunningEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DunningEvent::class, 'dunning_notice_id');
    }

    public function totalDue(): Money
    {
        return Money::of((string) $this->total_due, (string) $this->currency);
    }
}
