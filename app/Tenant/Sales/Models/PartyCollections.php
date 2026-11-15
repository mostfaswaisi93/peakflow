<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who is chasing this customer, and what they promised - PF-109.
 *
 * A worklist with no owner is a list nobody works, and a promise with no
 * date is a conversation nobody follows up.
 */
final class PartyCollections extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'party_collections';

    protected $fillable = [
        'entity_id', 'party_id', 'owner_user_id', 'promised_on',
        'promised_amount', 'currency', 'promise_note', 'in_collections_since', 'note',
    ];

    protected $casts = [
        'promised_on' => 'immutable_date',
        'in_collections_since' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function hasBrokenItsPromise(?\DateTimeInterface $on = null): bool
    {
        if ($this->promised_on === null) {
            return false;
        }

        $on = $on === null ? now()->startOfDay() : \Illuminate\Support\Carbon::parse($on)->startOfDay();

        return $this->promised_on->lt($on);
    }
}
