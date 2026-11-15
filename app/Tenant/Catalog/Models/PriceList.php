<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An effective-dated set of prices - PF-065.
 *
 * ## Specificity, then priority
 *
 * A list for one party beats a list for their group, which beats a general
 * list. That order is not configurable, deliberately: an order a tenant can
 * reorder is an order nobody can explain, and "why is this price 94.50" is
 * asked every week. `priority` breaks ties WITHIN a tier of specificity,
 * which is where a tenant's own judgement belongs.
 *
 * ## `valid_to` null means still in force
 *
 * Rather than a far-future sentinel, which works and then appears on screens
 * as 9999-12-31 and in exports as a date somebody has to explain.
 *
 * @property ?string $party_id
 * @property ?string $party_group
 * @property int $priority
 */
final class PriceList extends TenantModel
{
    protected $table = 'price_lists';

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'currency',
        'party_id', 'party_group', 'valid_from', 'valid_to', 'priority', 'active',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'priority' => 'integer',
        'active' => 'boolean',
    ];

    /** @return HasMany<PriceListLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PriceListLine::class, 'price_list_id');
    }

    /**
     * Lists in force on a date.
     *
     * A DATE, never "now". A credit note issued in April against a March
     * invoice uses March's price, and a scope that reads the clock reprices
     * history whenever a list is edited.
     *
     * @param  Builder<PriceList>  $query
     * @return Builder<PriceList>
     */
    public function scopeInForceOn(Builder $query, Carbon $date): Builder
    {
        return $query->where('active', true)
            ->where('valid_from', '<=', $date->toDateString())
            ->where(fn (Builder $inner) => $inner
                ->whereNull('valid_to')
                ->orWhere('valid_to', '>=', $date->toDateString()));
    }

    /** 3 for a party list, 2 for a group list, 1 for a general one. */
    public function specificity(): int
    {
        return match (true) {
            $this->party_id !== null => 3,
            $this->party_group !== null => 2,
            default => 1,
        };
    }
}
