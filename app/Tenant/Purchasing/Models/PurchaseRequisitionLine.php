<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a requisition - PF-130.
 *
 * The prices are named `estimated_` because they are. The supplier has not
 * quoted, and a column called `unit_price` would be read as a price somebody
 * agreed - which is how an estimate ends up on a purchase order unchanged and
 * then in a dispute about what was ordered.
 *
 * @property int $line_no
 * @property string $description
 * @property string $quantity
 * @property string $estimated_unit_price
 * @property string $estimated_amount
 */
final class PurchaseRequisitionLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'purchase_requisition_lines';

    protected $fillable = [
        'entity_id', 'purchase_requisition_id', 'line_no', 'item_id', 'description',
        'line_type', 'unit_code', 'quantity', 'estimated_unit_price', 'estimated_amount',
        'expense_account_id', 'needed_by',
        'dimension_1', 'dimension_2', 'dimension_3', 'dimension_4',
    ];

    protected $casts = [
        'needed_by' => 'immutable_date',
    ];

    /** @return BelongsTo<PurchaseRequisition, $this> */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /** @return array<int, string|null> */
    public function dimensions(): array
    {
        return [
            $this->dimension_1 === null ? null : (string) $this->dimension_1,
            $this->dimension_2 === null ? null : (string) $this->dimension_2,
            $this->dimension_3 === null ? null : (string) $this->dimension_3,
            $this->dimension_4 === null ? null : (string) $this->dimension_4,
        ];
    }
}
