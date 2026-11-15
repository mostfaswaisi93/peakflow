<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What this document refers to - EN 16931 BG-3 and BT-25, PF-091.
 *
 * `credits`, `corrects` and `prepayment` carry legal weight and must point at
 * a document in this system: a credit note whose reference is the string "the
 * invoice from March" is a credit note nobody can match, and the database
 * refuses one. `order`, `contract`, `despatch`, `receipt` and `project` are
 * commercial and may be free text.
 *
 * @property string $kind
 * @property string|null $reference
 */
final class InvoiceReference extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'invoice_references';

    protected $fillable = [
        'entity_id', 'invoice_id', 'kind',
        'referenced_invoice_id', 'reference', 'reference_date',
    ];

    protected $casts = ['reference_date' => 'date'];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function referenced(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'referenced_invoice_id');
    }

    public function isLegal(): bool
    {
        return \in_array($this->kind, ['credits', 'corrects', 'prepayment'], true);
    }
}
