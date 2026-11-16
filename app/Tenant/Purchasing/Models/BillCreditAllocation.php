<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit note used against a bill - PF-134.
 *
 * ## It posts nothing, and that is the point
 *
 * The credit note and the bill are both in `accounts_payable` against the
 * same party. Netting two items inside one control account for one
 * counterparty changes no balance anywhere: this row is what makes the
 * open-item statement right, not an accounting event. A system that posted a
 * journal for it would write two entries that cancel, and somebody would
 * spend an afternoon working out why the ledger has twice as many lines as
 * it has transactions.
 *
 * @property string $amount
 * @property string $currency
 */
final class BillCreditAllocation extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'bill_credit_allocations';

    protected $fillable = [
        'entity_id', 'credit_note_id', 'bill_id', 'currency', 'amount',
        'allocated_at', 'allocated_by', 'note',
    ];

    protected $casts = [
        'allocated_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Bill, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'credit_note_id');
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
