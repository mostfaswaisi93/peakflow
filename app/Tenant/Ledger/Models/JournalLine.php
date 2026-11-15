<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a journal entry - PF-041, PF-046.
 *
 * ## The amount is signed
 *
 * Positive is a debit, negative is a credit. One invariant - the lines sum to
 * zero - instead of two, and no "which column did this go in" bug. The UI
 * splits the sign for display; the storage does not.
 *
 * ## It does not return a float, ever
 *
 * `amount()` and `functionalAmount()` return `Money`, built from the string
 * PostgreSQL returns for a `numeric`. Casting a numeric to a PHP float to add
 * two of them is how a ledger stops balancing by a thousandth of a currency
 * unit, in a way that reproduces only with the customer's data.
 *
 * @property string $currency
 */
final class JournalLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'journal_lines';

    protected $fillable = [
        'entity_id', 'journal_entry_id', 'accounting_date', 'period_id',
        'account_id', 'amount', 'currency', 'fx_rate', 'functional_amount',
        'party_id', 'tax_code_id', 'tax_point', 'narration',
        'dimension_1', 'dimension_2', 'dimension_3', 'dimension_4',
        'dimension_5', 'dimension_6', 'dimension_7', 'dimension_8',
    ];

    /*
     | No casts on the money columns, deliberately.
     |
     | Laravel's `decimal:4` cast runs the value through a float on its way
     | back, which is the single thing the money layer exists to prevent. The
     | raw string from PostgreSQL goes straight into Money.
     */
    protected $casts = ['accounting_date' => 'date'];

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function amount(): Money
    {
        return Money::of((string) $this->attributes['amount'], $this->currency);
    }

    public function functionalAmount(string $functionalCurrency): Money
    {
        return Money::of((string) $this->attributes['functional_amount'], $functionalCurrency);
    }

    public function isDebit(): bool
    {
        return ! str_starts_with((string) $this->attributes['amount'], '-');
    }
}
