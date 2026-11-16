<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mismatch, with the figures in it - PF-135.
 *
 * ## Why the figures are columns and not only prose
 *
 * `expected`, `actual` and `tolerance` are stored separately from `detail`
 * so the queue can be sorted by how wrong each one is. An exception list
 * ordered by date puts a two-cent rounding difference above a thousand-unit
 * over-billing, and the person working the queue has to open every one to
 * find out which matters.
 *
 * The prose matters too: "billed 120 units, received 100" is what a person
 * needs to act, and "the bill is on hold pending review" makes them open
 * three documents to find out what is wrong. The person who opens them is
 * usually not the person who knows.
 *
 * @property string $kind
 * @property string|null $expected
 * @property string|null $actual
 * @property string|null $tolerance
 * @property string $detail
 * @property string|null $resolution
 */
final class ThreeWayMatchException extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'three_way_match_exceptions';

    protected $fillable = [
        'entity_id', 'bill_id', 'bill_line_id', 'purchase_order_line_id',
        'kind', 'expected', 'actual', 'tolerance', 'detail',
        'resolved_at', 'resolved_by', 'resolution',
    ];

    protected $casts = [
        'resolved_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return BelongsTo<BillLine, $this> */
    public function billLine(): BelongsTo
    {
        return $this->belongsTo(BillLine::class, 'bill_line_id');
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
