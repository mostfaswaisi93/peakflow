<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payment terms as data - PF-066.
 *
 * ## `kind` is the whole model
 *
 * "Net 30" is one of five shapes, and the other four cannot be expressed as
 * a number of days:
 *
 *   immediate     on receipt
 *   net           n days from the document date
 *   end_of_month  the end of the document's month, THEN n days - which
 *                 differs from "30 days" by up to a month depending on when
 *                 in the month the invoice was issued
 *   fixed_day     the 25th of the following month, which most government
 *                 buyers and many retailers require
 *   instalments   several due dates on one document
 *
 * A single `days` column covers the first and silently mis-states the rest.
 * The mis-statement surfaces as an aging report that disagrees with the
 * customer's own ledger, which is a conversation nobody wins.
 *
 * @property string $kind
 * @property int $days
 * @property ?int $day_of_month
 * @property int $months_forward
 * @property ?string $discount_percent
 * @property ?int $discount_days
 */
final class PaymentTerm extends TenantModel
{
    protected $table = 'payment_terms';

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'kind', 'days', 'day_of_month',
        'months_forward', 'reference_date_code',
        'discount_percent', 'discount_days', 'active',
    ];

    protected $casts = [
        'days' => 'integer',
        'day_of_month' => 'integer',
        'months_forward' => 'integer',
        'discount_days' => 'integer',
        'active' => 'boolean',
        /*
         | NOT cast to a float or a decimal cast.
         |
         | `discount_percent` is numeric(21,12) and a cast would hand it
         | back through PHP's float, which is the one thing the money path
         | forbids. It stays a string and goes into BigDecimal.
         */
    ];

    /** @return HasMany<PaymentTermInstalment, $this> */
    public function instalments(): HasMany
    {
        return $this->hasMany(PaymentTermInstalment::class, 'payment_term_id');
    }

    public function hasInstalments(): bool
    {
        return $this->kind === 'instalments';
    }

    public function offersEarlySettlementDiscount(): bool
    {
        return $this->discount_percent !== null && $this->discount_days !== null;
    }

    /**
     * A one-line description, for a document and a dropdown.
     *
     * Built from the fields rather than stored, so it cannot drift from the
     * terms it describes - a stored label that says "Net 30" beside a term
     * somebody changed to 45 days is worse than no label.
     */
    public function describe(): string
    {
        $base = match ($this->kind) {
            'immediate' => 'Due on receipt',
            'net' => sprintf('Net %d days', $this->days),
            'end_of_month' => sprintf('End of month plus %d days', $this->days),
            'fixed_day' => sprintf(
                'Day %d of %s',
                $this->day_of_month ?? 1,
                $this->months_forward === 0 ? 'the same month' : sprintf('%d month(s) later', $this->months_forward),
            ),
            'instalments' => sprintf('%d instalments', $this->instalments()->count()),
            default => $this->kind,
        };

        return $this->offersEarlySettlementDiscount()
            ? sprintf('%s, %s%% within %d days', $base, rtrim(rtrim((string) $this->discount_percent, '0'), '.'), $this->discount_days)
            : $base;
    }
}
