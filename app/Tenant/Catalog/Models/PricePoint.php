<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a price was, from a date - PF-254.
 *
 * ## A point is a fact
 *
 * A trigger refuses UPDATE and DELETE. Editing one rewrites what a
 * customer was charged, and the invoice that quoted it does not change
 * with it - so a correction is a new point from today saying so, with
 * its own reason and its own name against it.
 *
 * ## A future `effective_from` is a scheduled change
 *
 * Not a flag, not a queued job. On the day it arrives it becomes the
 * latest point at or before the document date and the resolver reads it,
 * which is the whole mechanism - nothing to run and nothing to forget on
 * a public holiday. And because it is an ordinary row, tomorrow's price
 * is listable today: a product where a rise is invisible until it
 * happens is one where it is discovered by a customer.
 *
 * @property ?string $unit_price
 * @property ?string $discount_percent
 * @property string $effective_from
 * @property string $reason
 */
final class PricePoint extends TenantModel
{
    protected $table = 'price_points';

    protected $fillable = [
        'price_list_line_id', 'unit_price', 'discount_percent',
        'effective_from', 'reason', 'changed_by',
    ];

    /*
     | No decimal casts. `unit_price` is numeric(21,8) and
     | `discount_percent` is numeric(9,6); a cast routes either through
     | PHP's float, which is the one thing the money path forbids.
     */
    protected $casts = ['effective_from' => 'date'];

    /** @return BelongsTo<PriceListLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PriceListLine::class, 'price_list_line_id');
    }
}
