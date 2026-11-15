<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document- or line-level allowance or charge - EN 16931 BG-20/BG-21, PF-091.
 *
 * `invoice_line_id` null means document level. A discount at the foot of an
 * invoice has no rate of its own and is apportioned across the rate groups
 * before tax, which is PF-082's business; this row is what was agreed and why.
 *
 * @property bool $is_charge
 * @property string $amount
 * @property string|null $percentage
 * @property string $reason
 * @property string|null $reason_code
 */
final class InvoiceAllowanceCharge extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'invoice_allowances_charges';

    protected $fillable = [
        'entity_id', 'invoice_id', 'invoice_line_id', 'is_charge',
        'amount', 'percentage', 'reason', 'reason_code', 'tax_category', 'tax_rate',
    ];

    protected $casts = ['is_charge' => 'boolean'];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isDocumentLevel(): bool
    {
        return $this->invoice_line_id === null;
    }

    /** The signed effect on the taxable amount. */
    public function signed(string $currency): Money
    {
        $amount = Money::of($this->amount, $currency);

        return $this->is_charge ? $amount : $amount->negated();
    }
}
