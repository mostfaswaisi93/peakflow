<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employee's claim - PF-143.
 *
 * ## The payable is to the employee, in a control account of its own
 *
 * `employee_payable`, not `accounts_payable`. An employee is not a supplier:
 * the balance is reported differently in most frameworks, it is frequently
 * settled through payroll rather than through the payment run, and an aging
 * report that mixed the two would show a colleague's taxi fare beside a
 * supplier whose terms are thirty days.
 *
 * It also matters for the fraud this module is about: "who do we owe money
 * to" with employees mixed into it is a list nobody reads closely, and the
 * expense claim is the cheapest place in a business to take money out of it.
 *
 * ## The claimant is a user, not a party
 *
 * Modelling an employee as a supplier puts a colleague in the list a payment
 * run reads and the aging report ages, and makes the two indistinguishable
 * for every control that works on parties - the supplier bank-detail rule in
 * particular, which is the highest-value fraud in the product.
 *
 * @property string|null $number
 * @property string $status
 * @property string $currency
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $gross_amount
 * @property string $recoverable_tax_amount
 */
final class ExpenseClaim extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'expense_claims';

    /** The subject type the approval engine knows a claim by. */
    public const SUBJECT_TYPE = 'expense_claim';

    protected $fillable = [
        'entity_id', 'number', 'status', 'claimant_user_id', 'claimed_on',
        'period_from', 'period_to', 'currency',
        'net_amount', 'tax_amount', 'gross_amount',
        'recoverable_tax_amount', 'non_recoverable_tax_amount', 'paid_amount',
        'posting_date', 'journal_entry_id',
        'submitted_at', 'approved_at', 'approved_by',
        'rejected_at', 'rejected_by', 'rejection_reason', 'posted_at', 'note',
    ];

    protected $casts = [
        'claimed_on' => 'immutable_date',
        'period_from' => 'immutable_date',
        'period_to' => 'immutable_date',
        'posting_date' => 'immutable_date',
        'submitted_at' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
        'rejected_at' => 'immutable_datetime',
        'posted_at' => 'immutable_datetime',
    ];

    /** @return HasMany<ExpenseClaimLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseClaimLine::class)->orderBy('line_no');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function net(): Money
    {
        return Money::of($this->net_amount, $this->currency);
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

    /** The lines a person has to look at before approving. */
    public function exceptions(): array
    {
        return $this->lines
            ->filter(static fn (ExpenseClaimLine $line): bool => $line->policy_exception !== null)
            ->values()
            ->all();
    }

    public function label(): string
    {
        return (string) ($this->number ?? $this->getKey());
    }
}
