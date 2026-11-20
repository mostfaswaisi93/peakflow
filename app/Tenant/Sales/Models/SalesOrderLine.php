<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of an order, and what is left of it - PF-090.
 *
 * The invoiced figures are maintained by a trigger when an invoice is
 * issued, and bounded by a CHECK on this row - so the cap holds against any
 * writer, not only against the code that does the adding.
 */
final class SalesOrderLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'sales_order_lines';

    protected $fillable = [
        'entity_id', 'sales_order_id', 'line_no', 'item_id', 'item_code', 'supply_type', 'description',
        'unit_code', 'quantity', 'unit_price', 'net_amount',
        'invoiced_quantity', 'invoiced_net',
        'revenue_account_id', 'tax_code_id', 'tax_category', 'tax_rate',
    ];

    protected $casts = ['line_no' => 'integer'];

    /** @return BelongsTo<SalesOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function remainingNet(): Money
    {
        return Money::of((string) $this->net_amount, (string) $this->order?->currency)
            ->minus(Money::of((string) $this->invoiced_net, (string) $this->order?->currency));
    }

    public function remainingQuantity(): BigDecimal
    {
        return BigDecimal::of((string) $this->quantity)
            ->minus(BigDecimal::of((string) $this->invoiced_quantity));
    }

    public function isFullyInvoiced(): bool
    {
        return $this->remainingNet()->isZero();
    }
}
