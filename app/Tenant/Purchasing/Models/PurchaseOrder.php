<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A commitment to buy - PF-131.
 *
 * ## An order is not a transaction
 *
 * Nothing has happened yet. Nobody is owed anything, no goods have moved and
 * no expense has been incurred. So an order posts nothing: the remaining
 * commitment is a figure this class computes, not a journal entry.
 *
 * That is the distinction a surprising number of systems get wrong, usually
 * by posting an "encumbrance" to a ledger account. The result is a balance
 * sheet with a liability for something nobody owes, which reconciles to
 * nothing and has to be explained to an auditor every year.
 *
 * ## The state is on the lines
 *
 * `isFullyReceived()` and `isFullyBilled()` ask the lines, because an order
 * for ten items of which six arrived is the normal case and an order-level
 * percentage cannot say which six. See {@see PurchaseOrderLine}.
 *
 * @property string|null $number
 * @property string $status
 * @property string $currency
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $gross_amount
 * @property string|null $closed_reason
 */
final class PurchaseOrder extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'entity_id', 'number', 'series_key', 'status', 'party_id',
        'ordered_on', 'expected_on', 'currency',
        'net_amount', 'tax_amount', 'gross_amount',
        'payment_term_id', 'created_by', 'purchase_requisition_id',
        'confirmed_at', 'confirmed_by', 'closed_at', 'closed_by', 'closed_reason',
        'note',
    ];

    protected $casts = [
        'ordered_on' => 'immutable_date',
        'expected_on' => 'immutable_date',
        'confirmed_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
    ];

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_no');
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /**
     * The request this order came from, where the entity asks for one.
     *
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed' || $this->status === 'cancelled';
    }

    /** Whether a bill may be matched against this order at all. */
    public function isOpenForMatching(): bool
    {
        return $this->status === 'confirmed';
    }

    public function net(): Money
    {
        return Money::of($this->net_amount, $this->currency);
    }

    public function gross(): Money
    {
        return Money::of($this->gross_amount, $this->currency);
    }

    /**
     * What is still committed and not yet billed.
     *
     * `(ordered - billed) x price`, line by line, and never negative at the
     * order level: a line billed beyond what was ordered is an exception the
     * three-way match raises, not a negative commitment that quietly offsets
     * another line's.
     */
    public function remainingCommitment(): Money
    {
        $total = Money::zero((string) $this->currency);

        foreach ($this->lines as $line) {
            $total = $total->plus($line->remainingCommitment((string) $this->currency));
        }

        return $total;
    }

    public function isFullyReceived(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->isFullyReceived()) {
                return false;
            }
        }

        return true;
    }

    public function isFullyBilled(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->isFullyBilled()) {
                return false;
            }
        }

        return true;
    }

    public function supplierName(): ?string
    {
        return $this->supplier?->legal_name;
    }

    public function label(): string
    {
        return (string) ($this->number ?? $this->getKey());
    }
}
