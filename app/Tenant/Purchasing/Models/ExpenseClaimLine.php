<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a claim - PF-143.
 *
 * ## The rate is frozen, like a payment term's due dates
 *
 * A mileage rate changes annually. A claim for last November re-derived at
 * today's rate pays the wrong amount and cannot be reconciled to what was
 * approved - so the rate that applied on `incurred_on` is copied here when
 * the line is written, and the policy it came from is named.
 *
 * ## The tax decision is stored, not re-derived
 *
 * `recoverable_percent` and the sentence behind it are written at entry.
 * "Why was this claimed" has to be answerable from the line a year later,
 * when the policy has changed and the return has been filed - and
 * re-deriving it would answer with today's policy, which is not the question.
 *
 * @property string $category
 * @property string $description
 * @property string|null $quantity
 * @property string|null $rate
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $recoverable_percent
 * @property string|null $tax_decision
 * @property string|null $policy_exception
 */
final class ExpenseClaimLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'expense_claim_lines';

    protected $fillable = [
        'entity_id', 'expense_claim_id', 'line_no', 'category', 'incurred_on', 'description',
        'quantity', 'rate', 'rate_unit', 'policy_id',
        'net_amount', 'tax_code_id', 'tax_category', 'tax_rate', 'tax_amount',
        'recoverable_percent', 'expense_account_id',
        'receipt_attachment_id', 'tax_decision', 'policy_exception', 'policy_explanation',
        'dimension_1', 'dimension_2', 'dimension_3', 'dimension_4',
    ];

    protected $casts = [
        'incurred_on' => 'immutable_date',
    ];

    /** @return BelongsTo<ExpenseClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function hasReceipt(): bool
    {
        return $this->receipt_attachment_id !== null;
    }

    public function net(string $currency): Money
    {
        return Money::of($this->net_amount, $currency);
    }

    /** The part of this line's tax that may be claimed. */
    public function recoverableTax(string $currency): Money
    {
        $tax = Money::of($this->tax_amount, $currency);

        if ((string) $this->recoverable_percent === '100.000000') {
            return $tax;
        }

        if (BigDecimal::of((string) $this->recoverable_percent)->isZero()) {
            return Money::zero($currency);
        }

        return $tax->multipliedBy(
            (string) BigDecimal::of((string) $this->recoverable_percent)
                ->dividedBy('100', 12, RoundingMode::HalfUp),
            RoundingPolicy::halfUp(Scale::Amount),
        );
    }

    public function nonRecoverableTax(string $currency): Money
    {
        return Money::of($this->tax_amount, $currency)->minus($this->recoverableTax($currency));
    }

    /** @return array<int, string|null> */
    public function dimensions(): array
    {
        return [
            $this->dimension_1 === null ? null : (string) $this->dimension_1,
            $this->dimension_2 === null ? null : (string) $this->dimension_2,
            $this->dimension_3 === null ? null : (string) $this->dimension_3,
            $this->dimension_4 === null ? null : (string) $this->dimension_4,
        ];
    }
}
