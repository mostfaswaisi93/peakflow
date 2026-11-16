<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What a category of expense is allowed to be - PF-143.
 *
 * ## A rate, a ceiling, or both
 *
 * Mileage and per diem have a rate and no ceiling. A meal has a ceiling and
 * no rate. A hotel has both in some businesses. A table that forced one shape
 * on all of them would make the other categories carry a column that means
 * nothing - and a column that means nothing is a column somebody fills in.
 *
 * ## Dated, because rates change
 *
 * A mileage rate changes annually in most jurisdictions. A claim for last
 * November has to be paid at last November's rate, so the policy has a window
 * and the rate that applied is **copied onto the claim line** rather than
 * looked up again. See {@see ExpenseClaimLine::$rate}.
 *
 * @property string $category
 * @property string|null $rate
 * @property string|null $ceiling_per_line
 * @property string $receipt_required_above
 * @property string $recoverable_percent
 */
final class ExpensePolicy extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'expense_policies';

    protected $fillable = [
        'entity_id', 'category', 'currency', 'rate', 'rate_unit',
        'ceiling_per_line', 'ceiling_per_claim', 'receipt_required_above',
        'recoverable_percent', 'expense_account_id',
        'effective_from', 'effective_to', 'active', 'note',
    ];

    protected $casts = [
        'effective_from' => 'immutable_date',
        'effective_to' => 'immutable_date',
        'active' => 'boolean',
    ];

    public function appliesOn(CarbonInterface $day): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->effective_from->gt($day)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->gte($day);
    }

    public function hasRate(): bool
    {
        return $this->rate !== null;
    }

    /**
     * Whether a receipt is required for this amount.
     *
     * `receipt_required_above` defaults to zero, which means always. That is
     * the honest default: a business that wants to waive receipts under a
     * small figure is making a decision, and it should be visible as one
     * rather than inherited from whatever the code happened to assume.
     */
    public function receiptRequiredFor(Money $amount): bool
    {
        return $amount->amount->isGreaterThan(BigDecimal::of((string) $this->receipt_required_above));
    }

    public function exceedsLineCeiling(Money $amount): bool
    {
        if ($this->ceiling_per_line === null) {
            return false;
        }

        return $amount->amount->isGreaterThan(BigDecimal::of((string) $this->ceiling_per_line));
    }

    public function ceiling(): ?Money
    {
        return $this->ceiling_per_line === null
            ? null
            : Money::of((string) $this->ceiling_per_line, (string) $this->currency);
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $pieces = [];

        if ($this->rate !== null) {
            $pieces[] = sprintf('%s per %s', (string) $this->rate, (string) $this->rate_unit);
        }

        if ($this->ceiling_per_line !== null) {
            $pieces[] = $locale === 'ar'
                ? sprintf('بحدّ أقصى %s للبند', (string) $this->ceiling_per_line)
                : sprintf('up to %s per line', (string) $this->ceiling_per_line);
        }

        /*
         | Zero per cent IS "no entitlement".
         |
         | There is deliberately no `recoversTax()` helper: it read as a
         | control and was not one - the decision is made in
         | `ExpenseClaims::taxDecision()` from the percentage itself, and a
         | method that merely restates "is it zero" is a second place a
         | check can bite without changing any behaviour. Measured: biting
         | such a helper left the suite green.
         */
        if (BigDecimal::of((string) $this->recoverable_percent)->isZero()) {
            $pieces[] = $locale === 'ar' ? 'ضريبة غير قابلة للخصم' : 'tax not recoverable';
        }

        return $pieces === []
            ? (string) $this->category
            : sprintf('%s (%s)', (string) $this->category, implode(', ', $pieces));
    }

    public static function parseDay(string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }
}
