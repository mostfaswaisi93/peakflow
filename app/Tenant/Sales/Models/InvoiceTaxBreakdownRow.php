<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One frozen row of an invoice's VAT breakdown - EN 16931 BG-23, PF-091.
 *
 * Stored rather than recomputed, for the same reason as the party snapshot:
 * PF-082 computes it from rates that change, and reprinting a 2019 invoice
 * after a rate rise must not show the new rate against the old total.
 *
 * @property TaxCategory $category
 * @property string $rate
 * @property string $taxable_amount
 * @property string $tax_amount
 * @property string|null $exemption_reason_code
 * @property string|null $exemption_reason_text
 * @property string|null $report_box
 */
final class InvoiceTaxBreakdownRow extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'invoice_tax_breakdown';

    protected $fillable = [
        'entity_id', 'invoice_id', 'category', 'rate',
        'taxable_amount', 'tax_amount',
        'exemption_reason_code', 'exemption_reason_text', 'report_box',
    ];

    protected $casts = ['category' => TaxCategory::class];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function taxable(string $currency): Money
    {
        return Money::of($this->taxable_amount, $currency);
    }

    public function tax(string $currency): Money
    {
        return Money::of($this->tax_amount, $currency);
    }
}
