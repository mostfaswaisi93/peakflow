<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What the bank says happened - PF-148.
 *
 * ## It is immutable, and that is not a style choice
 *
 * A bank transaction is a third party's assertion. A line we disagree with is
 * a line to dispute with the bank: editing it locally produces a statement
 * that reconciles perfectly to our ledger and to nothing the bank has ever
 * sent. A trigger refuses an update to any imported column and `DELETE` is
 * revoked from the application role.
 *
 * Everything we think lives in {@see BankTransactionEnrichment} - separate
 * nullable columns beside it, never a rewrite.
 *
 * ## Two dates
 *
 * `booking_date` is when the bank moved it and is what the GL posts on.
 * `value_date` is when it earns or costs interest, and is what forecasting
 * and FX use. They differ by days on a cross-border payment and by weeks on
 * a cheque, so a schema with one date has to choose which of interest and
 * the trial balance to be wrong about.
 *
 * @property string $amount
 * @property string $currency
 * @property int $statement_sequence
 * @property string $identity_key
 * @property bool $is_provisional
 * @property array<string, mixed>|null $remittance_structured
 */
final class BankTransaction extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'bank_transactions';

    /**
     * The id alone is not the key: the table is partitioned on the booking
     * date, so a row is addressed by both.
     */
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'entity_id', 'bank_account_id', 'bank_statement_id',
        'booking_date', 'value_date', 'amount', 'currency',
        'counterparty_name', 'counterparty_account', 'counterparty_bic',
        'remittance_unstructured', 'remittance_structured',
        'bank_reference', 'end_to_end_id', 'mandate_id',
        'code_domain', 'code_family', 'code_subfamily', 'bank_code_proprietary',
        'is_reversal', 'reverses_reference',
        'charges_amount', 'instructed_amount', 'instructed_currency', 'exchange_rate',
        'statement_sequence', 'is_provisional', 'superseded_by_id',
        'source_format', 'imported_at', 'source_payload',
    ];

    protected $casts = [
        'booking_date' => 'immutable_date',
        'value_date' => 'immutable_date',
        'imported_at' => 'immutable_datetime',
        'is_reversal' => 'boolean',
        'is_provisional' => 'boolean',
        'statement_sequence' => 'integer',
        'remittance_structured' => 'array',
        'source_payload' => 'array',
    ];

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<BankStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /** @return HasOne<BankTransactionEnrichment, $this> */
    public function enrichment(): HasOne
    {
        return $this->hasOne(BankTransactionEnrichment::class, 'bank_transaction_id');
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    /** Money in. Positive is a receipt, which is how the ledger reads it too. */
    public function isCredit(): bool
    {
        return $this->money()->isPositive();
    }

    public function isDebit(): bool
    {
        return ! $this->isCredit();
    }

    /**
     * The ISO 20022 code, as the three parts the bank sent.
     *
     * Not flattened: the routing in PF-155 reads the family, and a single
     * string would have to be re-split by everything that uses it.
     */
    public function code(): ?string
    {
        if ($this->code_domain === null) {
            return null;
        }

        return implode('/', array_filter([
            $this->code_domain,
            $this->code_family,
            $this->code_subfamily,
        ]));
    }

    /**
     * The remittance text, structured first.
     *
     * Structured remittance is the highest-quality signal a payer can give
     * and most do not give it, so both are kept and this prefers the better
     * one without discarding the other.
     */
    public function remittance(): ?string
    {
        $structured = $this->remittance_structured;

        if (\is_array($structured) && $structured !== []) {
            $references = [];

            foreach ($structured as $entry) {
                if (\is_array($entry) && isset($entry['reference'])) {
                    $references[] = (string) $entry['reference'];
                }
            }

            if ($references !== []) {
                return implode(' ', $references);
            }
        }

        return $this->remittance_unstructured;
    }
}
