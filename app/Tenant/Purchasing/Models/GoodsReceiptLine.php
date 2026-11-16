<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What arrived on one order line - PF-132.
 *
 * ## A rejection is not a non-receipt
 *
 * Ten arrived and two were damaged: the receipt records ten with two
 * rejected, and the order line's `quantity_received` moves by eight. Both
 * figures are kept, because "we received eight" loses the fact that the
 * supplier shipped ten and owes a replacement, and that is the conversation
 * the warehouse needs to have with them.
 *
 * ## A service line is confirmed, not counted
 *
 * There is nothing on a loading bay to count. `confirmed_by` and
 * `confirmation` are the evidence a three-way match has for a service, and
 * a bill for a service with no confirmation is its own kind of exception
 * rather than a quantity mismatch.
 *
 * @property string $quantity
 * @property string $quantity_rejected
 * @property string|null $rejection_reason
 * @property string|null $confirmation
 */
final class GoodsReceiptLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'goods_receipt_lines';

    protected $fillable = [
        'entity_id', 'goods_receipt_id', 'purchase_order_line_id',
        'quantity', 'quantity_rejected', 'rejection_reason',
        'confirmed_by', 'confirmation',
    ];

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    /** What the business actually keeps - delivered less rejected. */
    public function accepted(): BigDecimal
    {
        return BigDecimal::of((string) $this->quantity)
            ->minus((string) $this->quantity_rejected);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_by !== null
            && \strlen(trim((string) $this->confirmation)) > 0;
    }
}
