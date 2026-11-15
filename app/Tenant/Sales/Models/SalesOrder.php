<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\OrderState;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agreement to supply, with no accounting consequences - PF-090.
 *
 * @property OrderState $status
 * @property string $reference
 */
final class SalesOrder extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'sales_orders';

    protected $fillable = [
        'entity_id', 'reference', 'party_id', 'status',
        'order_date', 'requested_delivery', 'customer_reference',
        'currency', 'net_amount', 'tax_amount', 'gross_amount', 'presentation_exponent',
        'profile', 'terms', 'note', 'quote_id',
        'confirmed_at', 'confirmed_by', 'completed_at', 'cancelled_at', 'cancelled_reason',
    ];

    protected $casts = [
        'status' => OrderState::class,
        'order_date' => 'date',
        'requested_delivery' => 'date',
        'confirmed_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'presentation_exponent' => 'integer',
    ];

    /** @return HasMany<SalesOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function gross(): Money
    {
        return Money::of((string) $this->gross_amount, (string) $this->currency);
    }

    /**
     * What is left to invoice, by value.
     *
     * Summed from the lines rather than held on the order, because the lines
     * are where the balance is tracked - and a second total on the header
     * would be a second answer to the same question.
     */
    public function uninvoicedNet(): Money
    {
        $remaining = Money::zero((string) $this->currency);

        foreach ($this->lines()->get() as $line) {
            $remaining = $remaining->plus($line->remainingNet());
        }

        return $remaining;
    }

    public function isFullyInvoiced(): bool
    {
        return $this->uninvoicedNet()->isZero();
    }
}
