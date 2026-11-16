<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A statement, as a continuous object - PF-147.
 *
 * ## Continuity is asserted on the balance, not the number
 *
 * Banks number statements inconsistently: per month, per page, per download.
 * A number-based assertion fires on a bank that simply numbers differently,
 * and the first thing anybody does with a check that fires wrongly is turn
 * it off. So `sequence` is ours - the order we imported them in - and the
 * continuity trigger compares this statement's opening balance against the
 * previous one's closing balance.
 *
 * `statement_number` is the bank's own label, kept because the person
 * reconciling has the paper statement in front of them and that is what it
 * says.
 *
 * ## Superseded, never deleted
 *
 * A bank re-issues a statement - a correction, a missing entry, a reversal -
 * and the old one is still the document somebody reconciled against last
 * month. Deleting it makes that reconciliation unexplainable, so the old row
 * stays and points at its replacement.
 *
 * @property int $sequence
 * @property string $opening_balance
 * @property string $closing_balance
 * @property string $source_format
 * @property string $status
 */
final class BankStatement extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'bank_statements';

    protected $fillable = [
        'entity_id', 'bank_account_id', 'statement_number', 'sequence',
        'period_from', 'period_to', 'opening_balance', 'closing_balance', 'currency',
        'source_format', 'status', 'content_hash', 'file_name',
        'imported_at', 'imported_by',
        'superseded_by_id', 'superseded_at', 'superseded_by_user', 'supersession_reason',
        'note',
    ];

    protected $casts = [
        'period_from' => 'immutable_date',
        'period_to' => 'immutable_date',
        'imported_at' => 'immutable_datetime',
        'superseded_at' => 'immutable_datetime',
        'sequence' => 'integer',
    ];

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function opening(): Money
    {
        return Money::of($this->opening_balance, $this->currency);
    }

    public function closing(): Money
    {
        return Money::of($this->closing_balance, $this->currency);
    }

    /**
     * The movement the statement itself asserts.
     *
     * Closing less opening, which is what the lines have to add up to. A
     * statement whose lines do not sum to this is a parse that lost
     * something, and the difference is the only sign of it.
     */
    public function movement(): Money
    {
        return $this->closing()->minus($this->opening());
    }

    public function isSuperseded(): bool
    {
        return $this->status === 'superseded';
    }

    /** Whether this statement posts and reconciles, or only informs. */
    public function isProvisional(): bool
    {
        return (config('banking.formats.'.$this->source_format.'.posts') ?? true) === false;
    }

    public function label(): string
    {
        return (string) ($this->statement_number ?? sprintf('#%d', (int) $this->sequence));
    }
}
