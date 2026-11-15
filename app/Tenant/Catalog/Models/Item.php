<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use App\Support\Iso\UnitRegistry;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something that can appear on an invoice line - PF-064.
 *
 * ## `type` is not a category
 *
 * `good`, `service`, `digital_service`. It drives PLACE OF SUPPLY, which
 * decides which country's VAT applies and at what rate - a digital service
 * sold to a consumer in another member state is taxed where the consumer is,
 * and the same sale of a physical good is not.
 *
 * Getting it wrong produces no error. It produces a return filed in the
 * wrong country, and the correction is a voluntary disclosure.
 *
 * ## Shared across the tenant, with per-entity accounts
 *
 * The same item is sold by every company in the group; which revenue account
 * it lands in is a property of each company's chart, and the charts differ.
 * So there is no entity_id here and `item_entity_defaults` has one.
 *
 * @property string $type
 * @property string $unit_code
 * @property string $recognition
 * @property ?int $recognition_months
 */
final class Item extends TenantModel
{
    use SoftDeletes;

    protected $table = 'items';

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'description_ar', 'description_en',
        'type', 'unit_code', 'recognition', 'recognition_months',
        'sellable', 'purchasable', 'active',
    ];

    protected $casts = [
        'recognition_months' => 'integer',
        'sellable' => 'boolean',
        'purchasable' => 'boolean',
        'active' => 'boolean',
    ];

    /** @return HasMany<ItemClassification, $this> */
    public function classifications(): HasMany
    {
        return $this->hasMany(ItemClassification::class);
    }

    /** @return HasMany<ItemEntityDefault, $this> */
    public function entityDefaults(): HasMany
    {
        return $this->hasMany(ItemEntityDefault::class);
    }

    /**
     * Whether place of supply follows the CUSTOMER rather than the supplier.
     *
     * The question the type exists to answer. A digital service is taxed
     * where the customer is; a good is taxed where it is supplied from or
     * delivered to depending on the regime, and a conventional service
     * follows its own rules.
     *
     * The real determination belongs to the tax engine, which knows the
     * jurisdictions involved. This is the item's contribution to it, and
     * naming it here is what keeps the type from being read as a label.
     */
    public function placeOfSupplyFollowsCustomer(): bool
    {
        return $this->type === 'digital_service';
    }

    public function unitName(string $locale = 'en'): string
    {
        return UnitRegistry::name($this->unit_code, $locale);
    }

    public function isRecognisedOverTime(): bool
    {
        return $this->recognition !== 'immediate';
    }

    public function name(string $locale = 'en'): string
    {
        return str_starts_with($locale, 'ar') ? (string) $this->name_ar : (string) $this->name_en;
    }
}
