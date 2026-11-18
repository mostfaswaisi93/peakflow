<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One tier of one item's price on one list - PF-065, PF-254.
 *
 * ## This row is an IDENTITY, not a price
 *
 * This list, this item, from this quantity. The price itself lives in
 * `price_points`, one row per change, with the date it takes effect, the
 * person and the reason - PF-254 moved it there because "what did this
 * cost in March" is asked by a customer holding an invoice, and a column
 * that is overwritten cannot answer it.
 *
 * The old `unit_price` and `discount_percent` columns were dropped rather
 * than kept as a denormalised current price. Two places holding one truth
 * is how they come to disagree, and the resolver reading one while
 * history read the other is the failure that task exists to remove.
 *
 * A point is a price OR a discount off whatever the next less specific
 * list resolved to, never both. The discount form exists because "10% off
 * list" is a real agreement and cannot be stored as a number - the number
 * changes whenever the underlying list does.
 *
 * ## `min_quantity` is a floor
 *
 * The resolver takes the highest floor at or below the quantity ordered.
 * Ascending would pick the base price for every order and make every
 * quantity break decorative - and the symptom is a customer invoiced at list
 * price for a container load, which they notice and we do not.
 *
 * @property string $min_quantity
 */
final class PriceListLine extends TenantModel
{
    protected $table = 'price_list_lines';

    protected $fillable = ['price_list_id', 'item_id', 'min_quantity'];

    /** @return BelongsTo<PriceList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return HasMany<PricePoint, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(PricePoint::class, 'price_list_line_id')->orderByDesc('effective_from');
    }
}
