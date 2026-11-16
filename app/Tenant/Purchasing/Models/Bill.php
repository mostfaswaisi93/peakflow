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
 * A supplier's bill - PF-133.
 *
 * ## Three dates, and the accessors do not blur them
 *
 * {@see $supplier_document_date} is theirs, {@see $tax_point} is the
 * jurisdiction's and {@see $posting_date} is ours. There is deliberately no
 * `date` accessor: a method that picked one would be read by whoever came
 * next as "the date of the bill", and the three answer different questions.
 *
 * @property string $supplier_document_number
 * @property string $supplier_document_key
 * @property string $status
 * @property string $currency
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $gross_amount
 * @property string $recoverable_tax_amount
 * @property string $non_recoverable_tax_amount
 * @property string $payable_amount
 * @property string $paid_amount
 * @property array<string, mixed>|null $supplier_snapshot
 * @property array<string, mixed>|null $terms_snapshot
 * @property array<string, mixed>|null $tax_decision_snapshot
 */
final class Bill extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'bills';

    protected $fillable = [
        'entity_id', 'document_type', 'status', 'party_id',
        'supplier_document_number', 'supplier_document_key', 'supplier_document_date',
        'tax_point', 'posting_date', 'return_period_label',
        'currency', 'fx_rate', 'fx_source', 'fx_rate_date',
        'net_amount', 'tax_amount', 'gross_amount',
        'recoverable_tax_amount', 'non_recoverable_tax_amount',
        'payable_amount', 'paid_amount',
        'payment_term_id', 'terms_snapshot', 'supplier_snapshot', 'tax_decision_snapshot',
        'profile', 'note', 'created_by', 'approved_at', 'approved_by',
        'posted_at', 'journal_entry_id', 'source',
        'match_status', 'matched_at',
    ];

    protected $casts = [
        'supplier_document_date' => 'immutable_date',
        'tax_point' => 'immutable_date',
        'posting_date' => 'immutable_date',
        'fx_rate_date' => 'immutable_date',
        'approved_at' => 'immutable_datetime',
        'posted_at' => 'immutable_datetime',
        'matched_at' => 'immutable_datetime',
        'terms_snapshot' => 'array',
        'supplier_snapshot' => 'array',
        'tax_decision_snapshot' => 'array',
    ];

    /** @return HasMany<BillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('line_no');
    }

    /** @return HasMany<BillTaxBreakdownRow, $this> */
    public function taxBreakdown(): HasMany
    {
        return $this->hasMany(BillTaxBreakdownRow::class);
    }

    /** @return HasMany<BillDuplicateWarning, $this> */
    public function duplicateWarnings(): HasMany
    {
        return $this->hasMany(BillDuplicateWarning::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function net(): Money
    {
        return Money::of($this->net_amount, $this->currency);
    }

    public function tax(): Money
    {
        return Money::of($this->tax_amount, $this->currency);
    }

    public function gross(): Money
    {
        return Money::of($this->gross_amount, $this->currency);
    }

    public function recoverableTax(): Money
    {
        return Money::of($this->recoverable_tax_amount, $this->currency);
    }

    public function nonRecoverableTax(): Money
    {
        return Money::of($this->non_recoverable_tax_amount, $this->currency);
    }

    public function outstanding(): Money
    {
        return $this->gross()->minus(Money::of($this->paid_amount, $this->currency));
    }

    /** The supplier's legal name as it stood when the bill was entered. */
    public function supplierName(): ?string
    {
        $frozen = $this->supplier_snapshot['legal_name'] ?? null;

        return \is_string($frozen) ? $frozen : $this->supplier?->legal_name;
    }

    /**
     * Normalise a supplier's document number for comparison.
     *
     * `INV-001`, `inv 001` and `INV001` are the same document from the
     * supplier's point of view, and a unique constraint on the raw string
     * catches only the lazy half of the duplicate problem. Letters and
     * digits, upper-cased: everything a human would call punctuation goes.
     *
     * Leading zeros are KEPT. `INV-001` and `INV-1` are frequently different
     * documents from the same supplier, and collapsing them would refuse a
     * real invoice - a false refusal is worse than a near-match warning,
     * because the warning asks a person and the refusal does not.
     */
    public static function normaliseDocumentNumber(?string $number): string
    {
        if ($number === null) {
            return '';
        }

        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));
    }
}
