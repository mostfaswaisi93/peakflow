<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use App\Support\Iso\CurrencyRegistry;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * A currency one entity transacts in, and how it is shown - PF-067.
 *
 * ## Enabling is a refusal, not a filter
 *
 * The list exists so that a document in a currency the entity does not deal
 * in is **refused**, not converted at a rate nobody chose. That distinction
 * is the whole requirement: a silent conversion produces a plausible number,
 * and a plausible wrong number is the most expensive kind.
 *
 * The functional currency is always present and always enabled - a database
 * trigger mirrors it from the entity, so the two cannot disagree.
 *
 * ## The display policy touches nothing stored
 *
 * `symbol_display`, `symbol_position`, `digit_shape` and
 * `presentation_exponent` are presentation only. Amounts are stored at the
 * amount scale regardless, because storing the display value is how a ledger
 * stops balancing - the classic case being a 3-decimal currency rounded to 2
 * for a customer-facing document and then summed from the document.
 *
 * @property string $currency
 * @property bool $is_functional
 * @property bool $enabled
 * @property string $digit_shape
 */
final class EntityCurrency extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'entity_currencies';

    protected $fillable = [
        'entity_id',
        'currency',
        'is_functional',
        'enabled',
        'symbol_display',
        'symbol_position',
        'digit_shape',
        'presentation_exponent',
        'default_rate_set',
    ];

    protected $casts = [
        'is_functional' => 'boolean',
        'enabled' => 'boolean',
        'presentation_exponent' => 'integer',
    ];

    /**
     * How many decimals to SHOW.
     *
     * Falls back to the currency's own ISO 4217 exponent, which is the right
     * answer unless someone has deliberately overridden it. Not to 2: BHD,
     * IQD, JOD, KWD, LYD, OMR and TND are 3, four of them are target markets,
     * and presenting `1.23` where `1,234.000` belongs is a thousandfold error
     * that an eye slides straight over.
     */
    public function presentationDecimals(): int
    {
        return $this->presentation_exponent ?? CurrencyRegistry::exponent($this->currency);
    }

    /**
     * Whether digits should be rendered as Arabic-Indic numerals.
     *
     * A display choice, kept beside the currency rather than derived from the
     * viewer's locale, because an invoice sent to a Saudi authority and the
     * same invoice shown to an English-speaking controller must carry the
     * same figures, and deriving the shape from whoever is looking makes two
     * different documents out of one.
     */
    public function usesArabicIndicDigits(): bool
    {
        return in_array($this->digit_shape, ['arabic_indic', 'eastern_arabic_indic'], true);
    }
}
