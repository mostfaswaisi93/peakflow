<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line of an offer - PF-089. */
final class QuoteLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'quote_lines';

    protected $fillable = [
        'entity_id', 'quote_id', 'line_no', 'item_id', 'item_code', 'supply_type', 'description',
        'unit_code', 'quantity', 'unit_price', 'net_amount',
        'revenue_account_id', 'tax_code_id', 'tax_category', 'tax_rate',
    ];

    protected $casts = ['line_no' => 'integer'];

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function net(): Money
    {
        return Money::of((string) $this->net_amount, (string) $this->quote?->currency);
    }

    /**
     * What this line says, as a comparable tuple - PF-089.
     *
     * Used by the version comparison. The item, the description, the
     * quantity, the price and the tax treatment: everything a customer would
     * notice changed between two versions, and nothing that is an artefact
     * of the row being rewritten - no id, no timestamps, no line number,
     * because moving a line up the page is not a change to the offer.
     *
     * @return array<string, string|null>
     */
    public function comparable(): array
    {
        return [
            'item_code' => $this->item_code === null ? null : (string) $this->item_code,
            'description' => trim((string) $this->description),
            'quantity' => (string) $this->quantity,
            'unit_code' => (string) $this->unit_code,
            'unit_price' => (string) $this->unit_price,
            'net_amount' => (string) $this->net_amount,
            'tax_category' => $this->tax_category === null ? null : (string) $this->tax_category,
            'tax_rate' => $this->tax_rate === null ? null : (string) $this->tax_rate,
        ];
    }
}
