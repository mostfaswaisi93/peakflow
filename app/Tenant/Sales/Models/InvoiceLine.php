<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an invoice - PF-091.
 *
 * Carries the item reference AND its own description, because changing an
 * item's name later must not rewrite history. The reference is what a report
 * groups by; the description is what the customer read.
 *
 * @property int $line_no
 * @property string|null $item_code
 * @property string $description
 * @property string $unit_code
 * @property string $quantity
 * @property string $unit_price
 * @property string $net_amount
 * @property TaxCategory $tax_category
 * @property string $tax_rate
 * @property string|null $place_of_supply
 * @property string|null $place_of_supply_rule
 */
final class InvoiceLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'invoice_lines';

    protected $fillable = [
        'entity_id', 'invoice_id', 'line_no', 'item_id', 'item_code', 'description',
        'credits_line_id',
        'unit_code', 'quantity', 'unit_price', 'net_amount', 'revenue_account_id',
        'tax_code_id', 'tax_category', 'tax_rate', 'tax_point',
        'place_of_supply', 'place_of_supply_rule',
        'recognition', 'service_from', 'service_until',
        'dimension_1', 'dimension_2', 'dimension_3', 'dimension_4',
    ];

    protected $casts = [
        'tax_category' => TaxCategory::class,
        'tax_point' => 'date',
        'service_from' => 'date',
        'service_until' => 'date',
        'line_no' => 'integer',
    ];

    /** @return BelongsTo<Invoice, $this> */
    /** The line of another document this one credits - PF-097. */
    public function creditsLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credits_line_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function net(string $currency): Money
    {
        return Money::of($this->net_amount, $currency);
    }
}
