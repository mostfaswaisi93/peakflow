<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a party means to ONE of your companies - PF-059, PF-062.
 *
 * A customer billed by two entities owes each of them separately, and has a
 * credit limit with each separately. Netting the two is a decision somebody
 * makes, not something the schema should do by accident.
 *
 * @property bool $credit_hold
 */
final class PartyEntitySetting extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'party_entity_settings';

    protected $fillable = [
        'entity_id', 'party_id', 'credit_limit', 'credit_hold', 'credit_hold_reason',
        'credit_hold_by_user_id', 'credit_hold_at',
        'receivable_account_id', 'payable_account_id',
    ];

    // No decimal cast on credit_limit: Laravel's runs it through a float.
    protected $casts = [
        'credit_hold' => 'boolean',
        'credit_hold_at' => 'datetime',
    ];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function creditLimit(string $currency): ?Money
    {
        $limit = $this->attributes['credit_limit'] ?? null;

        return $limit === null ? null : Money::of((string) $limit, $currency);
    }
}
