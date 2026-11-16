<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a supplier's bill - PF-133.
 *
 * @property int $line_no
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $net_amount
 * @property TaxCategory $tax_category
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $recoverable_percent
 * @property bool $reverse_charge
 */
final class BillLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'bill_lines';

    protected $fillable = [
        'entity_id', 'bill_id', 'line_no', 'item_id', 'item_code', 'description',
        'unit_code', 'quantity', 'unit_price', 'net_amount',
        'expense_account_id', 'tax_code_id', 'tax_category', 'tax_rate', 'tax_amount',
        'recoverable_percent', 'reverse_charge',
        'dimension_1', 'dimension_2', 'dimension_3', 'dimension_4',
    ];

    protected $casts = [
        'tax_category' => TaxCategory::class,
        'reverse_charge' => 'boolean',
    ];

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function net(string $currency): Money
    {
        return Money::of($this->net_amount, $currency);
    }

    /**
     * The part of this line's tax that can be claimed.
     *
     * A percentage, not a flag: partial recovery is the normal case for
     * entertainment, fuel and mixed-use assets in most jurisdictions, and a
     * boolean forces the business to split a line that the supplier did not
     * split.
     *
     * Rounded at the line, because the recoverable and non-recoverable parts
     * have to add back to the line's tax exactly - a cent that belongs to
     * neither is a cent in the input account that will not tie to the
     * return.
     */
    public function recoverableTax(string $currency): Money
    {
        $tax = Money::of($this->tax_amount, $currency);

        if ((string) $this->recoverable_percent === '100.000000') {
            return $tax;
        }

        return $tax->multipliedBy(
            (string) \Brick\Math\BigDecimal::of((string) $this->recoverable_percent)
                ->dividedBy('100', 12, \Brick\Math\RoundingMode::HalfUp),
            RoundingPolicy::halfUp(Scale::Amount),
        );
    }

    public function nonRecoverableTax(string $currency): Money
    {
        return Money::of($this->tax_amount, $currency)->minus($this->recoverableTax($currency));
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
