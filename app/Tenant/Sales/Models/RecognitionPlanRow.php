<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned release of deferred revenue - PF-107.
 *
 * Written when the document is issued and read by the recognition run,
 * rather than computed at every run: a formula re-evaluated monthly is how
 * two runs disagree after a credit note, a rate change or a reopened period -
 * and "show me how you arrived at this month's revenue" is a question about a
 * list.
 */
final class RecognitionPlanRow extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'revenue_recognition_plan';

    protected $fillable = [
        'entity_id', 'invoice_id', 'invoice_line_id', 'recognise_on',
        'currency', 'amount', 'status', 'revenue_account_id',
        'journal_entry_id', 'recognised_at', 'note',
    ];

    protected $casts = [
        'recognise_on' => 'immutable_date',
        'recognised_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class, 'invoice_line_id');
    }

    public function money(): Money
    {
        return Money::of((string) $this->amount, (string) $this->currency);
    }

    public function isPlanned(): bool
    {
        return (string) $this->status === 'planned';
    }
}
