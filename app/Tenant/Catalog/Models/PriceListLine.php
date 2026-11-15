<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tier of one item's price on one list - PF-065.
 *
 * ## A price OR a discount, never both
 *
 * `unit_price` is an amount; `discount_percent` is a percentage off whatever
 * the next less specific list resolved to. A CHECK enforces exactly one,
 * because both is two answers to one question and whichever the resolver
 * reads first becomes a rule nobody wrote down.
 *
 * The discount form exists because "10% off list" is a real agreement and
 * cannot be stored as a number - the number changes whenever the underlying
 * list does, and a copy that has to be kept in step will not be.
 *
 * ## `min_quantity` is a floor
 *
 * The resolver takes the highest floor at or below the quantity ordered.
 * Ascending would pick the base price for every order and make every
 * quantity break decorative - and the symptom is a customer invoiced at list
 * price for a container load, which they notice and we do not.
 *
 * @property ?string $unit_price
 * @property ?string $discount_percent
 * @property string $min_quantity
 */
final class PriceListLine extends TenantModel
{
    protected $table = 'price_list_lines';

    protected $fillable = ['price_list_id', 'item_id', 'min_quantity', 'unit_price', 'discount_percent'];

    /*
     | No decimal casts. `unit_price` is numeric(21,8) and
     | `discount_percent` is numeric(21,12); a cast routes either through
     | PHP's float, which is the one thing the money path forbids.
     */

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

    public function isDiscount(): bool
    {
        return $this->discount_percent !== null;
    }
}
