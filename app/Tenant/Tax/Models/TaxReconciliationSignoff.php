<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Models;

use App\Support\Money\Money;
use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * A controller accepting an unexplained tax difference - PF-088.
 *
 * @property string $jurisdiction
 * @property string $period_label
 * @property string|null $return_label
 * @property string $currency
 * @property string $accepted_amount
 * @property string $reason
 */
final class TaxReconciliationSignoff extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'tax_reconciliation_signoffs';

    protected $fillable = [
        'entity_id', 'jurisdiction', 'period_label', 'return_label',
        'currency', 'accepted_amount', 'reason', 'signed_by', 'signed_at',
    ];

    protected $casts = ['signed_at' => 'immutable_datetime'];

    public function accepted(): Money
    {
        return Money::of($this->accepted_amount, $this->currency);
    }
}
