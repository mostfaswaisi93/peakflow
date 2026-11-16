<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What arrived - PF-132.
 *
 * ## The accrual is what makes a period-end cutoff honest
 *
 * Goods arrive on the 29th and the invoice arrives on the 4th. Without an
 * accrual the expense lands in the wrong month, and the balance sheet omits
 * a liability that exists: somebody has delivered and will be paid.
 *
 * So a receipt may post `Dr expense / Cr goods_received_not_invoiced`, and
 * the bill that matches it reverses that entry. Which means a GRNI balance
 * that does not clear is a receipt nobody was ever billed for - a question
 * worth asking, and one no accrual journal posted by hand each month can
 * answer, because a manual accrual is reversed in full on the 1st whether or
 * not the invoice ever came.
 *
 * ## It is optional per entity
 *
 * A business that bills within days of receiving does not need it, and the
 * entries are noise in their ledger. A business that closes a period between
 * the two cannot do without it. `journal_entry_id` is null in the first case
 * and set in the second, and the three-way match works either way.
 *
 * @property string|null $number
 * @property string|null $delivery_note
 * @property string|null $journal_entry_id
 * @property string|null $reversal_entry_id
 */
final class GoodsReceipt extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'goods_receipts';

    protected $fillable = [
        'entity_id', 'number', 'purchase_order_id', 'received_on', 'received_by',
        'delivery_note', 'journal_entry_id', 'reversal_entry_id', 'note',
    ];

    protected $casts = [
        'received_on' => 'immutable_date',
    ];

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function isAccrued(): bool
    {
        return $this->journal_entry_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversal_entry_id !== null;
    }

    public function label(): string
    {
        return (string) ($this->number ?? $this->getKey());
    }
}
