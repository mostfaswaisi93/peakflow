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
 * An internal request, before anything is committed - PF-130.
 *
 * ## It is one step further back than an order
 *
 * An order commits the business to a supplier at a price. A requisition does
 * not even name the supplier: it says somebody needs something, roughly what
 * it will cost, and why. So it posts nothing, commits nothing, and its prices
 * are named `estimated_` throughout - because the supplier has not quoted.
 *
 * ## The justification is the reason the step exists
 *
 * Strip it out and a requisition is a purchase order with extra clicks. What
 * makes it worth having is that somebody wrote down why and somebody else
 * read it, which is the only thing a budget holder can act on three months
 * later when asked why the department spent what it did.
 *
 * @property string|null $number
 * @property string $status
 * @property string $justification
 * @property string $currency
 * @property string $estimated_amount
 */
final class PurchaseRequisition extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'purchase_requisitions';

    protected $fillable = [
        'entity_id', 'number', 'status', 'requested_by', 'requested_on', 'needed_by',
        'justification', 'currency', 'estimated_amount',
        'dimension_1', 'dimension_2', 'dimension_3', 'dimension_4',
        'preferred_party_id', 'submitted_at',
        'approved_at', 'approved_by', 'rejected_at', 'rejected_by', 'rejection_reason',
        'converted_at', 'purchase_order_id', 'note',
    ];

    protected $casts = [
        'requested_on' => 'immutable_date',
        'needed_by' => 'immutable_date',
        'submitted_at' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
        'rejected_at' => 'immutable_datetime',
        'converted_at' => 'immutable_datetime',
    ];

    /** The subject type the approval engine knows a requisition by. */
    public const SUBJECT_TYPE = 'requisition';

    /** @return HasMany<PurchaseRequisitionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Party, $this> */
    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'preferred_party_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isConverted(): bool
    {
        return $this->status === 'converted';
    }

    public function estimate(): Money
    {
        return Money::of($this->estimated_amount, $this->currency);
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

    public function label(): string
    {
        return (string) ($this->number ?? $this->getKey());
    }
}
