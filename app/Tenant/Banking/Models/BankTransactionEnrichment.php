<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What we think a bank transaction is - PF-148.
 *
 * A table of its own rather than nullable columns on the transaction,
 * because the transaction is a third party's assertion and this is ours. The
 * separation is what makes "the bank said X" and "we decided Y" two answers
 * that can both be read a year later, and it is what lets the transaction
 * itself be append-only.
 *
 * `party_source` records how the party was decided - the account number on
 * file, a rule, or a person - because "we recognise this account" and
 * "somebody chose from a list" are the same party at different levels of
 * confidence, and the second is the one an auditor asks about.
 *
 * @property string|null $party_source
 * @property string|null $extracted_reference
 */
final class BankTransactionEnrichment extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'bank_transaction_enrichment';

    protected $fillable = [
        'entity_id', 'bank_transaction_id', 'booking_date',
        'party_id', 'party_source', 'normalised_counterparty',
        'extracted_reference', 'category', 'note', 'enriched_by',
    ];

    protected $casts = [
        'booking_date' => 'immutable_date',
    ];

    /** @return BelongsTo<BankTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function wasDecidedByAPerson(): bool
    {
        return $this->party_source === 'human';
    }
}
