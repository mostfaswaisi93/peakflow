<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instalment of a payment term - PF-066.
 *
 * ## A proportion, not an amount
 *
 * The terms are attached to a party before any invoice exists, so the
 * schedule cannot hold amounts. The amounts are allocated at issue, with the
 * residual on the last instalment - see DueDateCalculator.
 *
 * ## Each instalment has its own shape
 *
 * "A third on order, a third in 30 days, a third at the end of the following
 * month" is three different kinds in one schedule, which is why the kind,
 * days and day-of-month are per instalment rather than on the term.
 *
 * @property int $sequence
 * @property string $proportion
 * @property string $kind
 */
final class PaymentTermInstalment extends TenantModel
{
    protected $table = 'payment_term_instalments';

    protected $fillable = [
        'payment_term_id', 'sequence', 'proportion',
        'kind', 'days', 'day_of_month', 'months_forward',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'days' => 'integer',
        'day_of_month' => 'integer',
        'months_forward' => 'integer',
        // `proportion` stays a string: it is numeric(21,12), and a cast
        // would route it through PHP's float.
    ];

    /** @return BelongsTo<PaymentTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }
}
