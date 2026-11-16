<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that identifies a counterparty - PF-160.
 *
 * Learned from a confirmed match, never from the engine's own guess: an
 * identifier the engine taught itself is a belief with no source, and two
 * confident mistakes later nobody can say where it came from.
 *
 * @property string $identifier_type
 * @property string $identifier
 */
final class PartyBankIdentifier extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'party_bank_identifiers';

    protected $fillable = [
        'id', 'entity_id', 'party_id', 'identifier_type', 'identifier',
        'confirmed_by', 'learned_from_transaction_id', 'times_seen', 'last_seen_at',
    ];

    protected $casts = [
        'times_seen' => 'integer',
        'last_seen_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
