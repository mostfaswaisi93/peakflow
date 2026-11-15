<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which account plays which control role, in one entity - PF-038.
 *
 * @property ControlRole $role
 */
final class AccountRole extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'account_roles';

    protected $fillable = ['entity_id', 'role', 'account_id'];

    protected $casts = ['role' => ControlRole::class];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
