<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice - PF-091.
 *
 * ## The accessors read the SNAPSHOT, not the relation
 *
 * {@see supplierName()} and {@see customerName()} read the frozen copy on an
 * issued document and fall back to the live party only while it is a draft.
 * That ordering is the whole design: a reprint must show what was issued, and
 * the easiest way to lose that is an accessor that joins because the snapshot
 * is "just a cache".
 *
 * @property string|null $number
 * @property string $series_key
 * @property DocumentType $document_type
 * @property string $status
 * @property string $currency
 * @property array<string, mixed>|null $supplier_snapshot
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<string, mixed>|null $terms_snapshot
 * @property array<string, mixed>|null $tax_decision_snapshot
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $gross_amount
 * @property string $prepaid_amount
 * @property string $payable_amount
 * @property int $presentation_exponent
 * @property string|null $canonical_hash
 * @property string|null $previous_hash
 * @property int|null $chain_index
 */
final class Invoice extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'invoices';

    protected $fillable = [
        'entity_id', 'number', 'series_key', 'document_type', 'status', 'party_id',
        'issue_date', 'tax_point', 'return_period_label', 'currency',
        'fx_rate', 'fx_source', 'fx_rate_date',
        'net_amount', 'tax_amount', 'gross_amount', 'prepaid_amount', 'payable_amount',
        'presentation_exponent', 'supplier_snapshot', 'customer_snapshot',
        'terms_snapshot', 'tax_decision_snapshot',
        'issued_at', 'issued_by', 'canonical', 'canonical_hash', 'previous_hash', 'chain_index',
        'profile', 'note', 'created_by', 'simplified',
    ];

    protected $casts = [
        'document_type' => DocumentType::class,
        'issue_date' => 'date',
        'tax_point' => 'date',
        'fx_rate_date' => 'date',
        'issued_at' => 'immutable_datetime',
        'supplier_snapshot' => 'array',
        'customer_snapshot' => 'array',
        'terms_snapshot' => 'array',
        'tax_decision_snapshot' => 'array',
        'presentation_exponent' => 'integer',
        'simplified' => 'boolean',
        'chain_index' => 'integer',
    ];

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    /** @return HasMany<InvoiceTaxBreakdownRow, $this> */
    public function taxBreakdown(): HasMany
    {
        return $this->hasMany(InvoiceTaxBreakdownRow::class);
    }

    /** @return HasMany<InvoiceAllowanceCharge, $this> */
    public function allowancesAndCharges(): HasMany
    {
        return $this->hasMany(InvoiceAllowanceCharge::class);
    }

    /** @return HasMany<InvoiceReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(InvoiceReference::class);
    }

    /** @return BelongsTo<Party, $this> */
    /**
     * The credit notes issued against this document - PF-097.
     *
     * Through the references, because that is where the link legally lives
     * (EN 16931 BT-25) and a second, denormalised pointer would be a second
     * answer to the same question.
     *
     * @return HasManyThrough<Invoice, InvoiceReference, $this>
     */
    public function credits(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            self::class,
            InvoiceReference::class,
            'referenced_invoice_id',
            'id',
            'id',
            'invoice_id',
        )->where('invoices.status', 'issued')
            ->where('invoice_references.kind', 'credits');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
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

    public function payable(): Money
    {
        return Money::of($this->payable_amount, $this->currency);
    }

    /**
     * The supplier's legal name as the document states it.
     *
     * From the snapshot on an issued document. The live entity is read only
     * for a draft, where there is nothing frozen yet - and a draft that shows
     * a stale name is a draft somebody is still editing.
     */
    public function supplierName(): ?string
    {
        $frozen = $this->supplier_snapshot['legal_name'] ?? null;

        return \is_string($frozen) ? $frozen : null;
    }

    public function customerName(): ?string
    {
        $frozen = $this->customer_snapshot['legal_name'] ?? null;

        return \is_string($frozen) ? $frozen : $this->party?->legal_name;
    }

    /** The customer's tax registration as it stood when the document was issued. */
    public function customerRegistration(): ?string
    {
        $identifier = $this->customer_snapshot['registration']['identifier'] ?? null;

        return \is_string($identifier) ? $identifier : null;
    }

    /** What a reader sees: the stored amount at the currency's own exponent. */
    public function displayed(string $amount): string
    {
        return (string) Money::of($amount, $this->currency)->toPresentation();
    }

    /**
     * The bytes the hash was taken over - PF-096.
     *
     * Stored rather than recomputed, so a divergence report can name the
     * field that changed rather than only that something did.
     */
    public function canonicalForm(): ?string
    {
        return $this->canonical === null ? null : (string) $this->canonical;
    }

    /** The due dates the payment terms produced, frozen at issue. */
    public function dueDates(): array
    {
        $dates = $this->terms_snapshot['due_dates'] ?? [];

        return \is_array($dates) ? $dates : [];
    }
}
