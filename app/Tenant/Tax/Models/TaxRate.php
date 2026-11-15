<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Models;

use App\Support\Database\Concerns\HasValidityRange;
use App\Support\Database\TenantModel;
use App\Support\Money\Scale;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of one tax code's rate - PF-076.
 *
 * ## The validity is a range, and the range is the authority
 *
 * `[2018-01-01,2020-07-01)` is inclusive at the start and exclusive at the
 * end, so it abuts `[2020-07-01,)` without overlapping it and without leaving
 * a gap. "In force on a date" is `validity @> date` - one containment test
 * that this model and the exclusion constraint share, rather than a pair of
 * nullable columns compared with `<` in one place and `<=` in another.
 *
 * @property string $rate
 * @property string $source
 * @property string $validity
 */
final class TaxRate extends TenantModel
{
    use BelongsToEntity;
    use HasValidityRange;

    protected $table = 'tax_rates';

    protected $fillable = ['entity_id', 'tax_code_id', 'rate', 'source', 'validity'];

    /** @return BelongsTo<TaxCode, $this> */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }

    /** The rate as published: `15.000000` means fifteen percent. */
    public function percentage(): BigDecimal
    {
        return BigDecimal::of($this->rate)->toScale(Scale::Rate->value);
    }

    /**
     * The rate as a decimal fraction, for arithmetic.
     *
     * `BigDecimal` and not `bcdiv`: `bcmath` is under `suggest` in
     * composer.json, so the extension is absent on this deployment and a
     * `bc*` call is a fatal error at the moment somebody computes tax. An
     * architecture rule refuses the whole family.
     */
    public function fraction(): BigDecimal
    {
        return $this->percentage()->dividedBy('100', Scale::Rate->value, RoundingMode::Unnecessary);
    }
}
