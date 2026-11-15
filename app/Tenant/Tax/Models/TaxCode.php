<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of one tax code - PF-075, PF-076.
 *
 * ## This row is the code's IDENTITY; the rates are versions of it
 *
 * `SA-VAT` is one row here, and its 5% and 15% periods are two rows in
 * `tax_rates`. The split is structural rather than tidy: a category is what a
 * code MEANS, so putting it on each rate version would let a rate change
 * silently also change the treatment - and zero-rated and exempt are
 * different supplies with different return treatment.
 *
 * The first version of this model versioned everything together and needed an
 * application-level refusal to stop exactly that. Structure that cannot
 * express the mistake beats a refusal that has to remember to.
 *
 * @property string $code
 * @property TaxCategory $category
 * @property string $jurisdiction
 * @property array<string, mixed> $reporting_boxes
 */
final class TaxCode extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'tax_codes';

    protected $fillable = [
        'entity_id', 'code', 'category', 'jurisdiction',
        'label_ar', 'label_en', 'reporting_boxes', 'exemption_reason_code',
        'exemption_reason_ar', 'exemption_reason_en', 'active',
    ];

    protected $casts = [
        'category' => TaxCategory::class,
        'reporting_boxes' => 'array',
        'active' => 'boolean',
    ];

    /** @return HasMany<TaxRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class, 'tax_code_id');
    }

    /** The version in force on a date, or null where there is none. */
    public function rateOn(CarbonInterface $date): ?TaxRate
    {
        return $this->rates()->effectiveOn($date)->first();
    }

    /**
     * The open-ended version, if there is one.
     *
     * Deliberately NOT called `current()`: "current" invites a caller to use
     * it for a document, and a document resolves its rate from its tax point.
     * This exists for the one legitimate use - finding the version a rate
     * change has to close.
     */
    public function openEndedRate(): ?TaxRate
    {
        return $this->rates()->whereRaw('upper_inf(validity)')->first();
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar'
            ? $this->label_ar
            : $this->label_en;
    }

    public function exemptionReason(?string $locale = null): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar'
            ? $this->exemption_reason_ar
            : $this->exemption_reason_en;
    }

    /** The box this code lands in on a given return, if it is on that return. */
    public function boxFor(string $return, string $side): ?string
    {
        $boxes = $this->reporting_boxes[$return] ?? null;

        if (! \is_array($boxes)) {
            return null;
        }

        $box = $boxes[$side] ?? null;

        return \is_string($box) && $box !== '' ? $box : null;
    }
}
