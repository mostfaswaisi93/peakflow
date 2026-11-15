<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Models;

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

    protected $table = 'tax_rates';

    protected $fillable = ['entity_id', 'tax_code_id', 'rate', 'source', 'validity'];

    /** @return BelongsTo<TaxCode, $this> */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }

    /**
     * The versions in force on a date.
     *
     * `@>` is PostgreSQL's containment operator, and it is the same test the
     * exclusion constraint uses - so the query and the constraint cannot
     * disagree about what "in force" means.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEffectiveOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query->whereRaw('validity @> ?::date', [$date->toDateString()]);
    }

    /**
     * Build a validity range, half-open at the end.
     *
     * `$until` is the first day the version is NO LONGER in force, which reads
     * oddly and is the form that composes: a successor's `from` is its
     * predecessor's `until`, so the two abut exactly and no arithmetic on
     * "the day before" happens at any call site.
     */
    public static function range(CarbonInterface $from, ?CarbonInterface $until = null): string
    {
        return sprintf(
            '[%s,%s)',
            CarbonImmutable::parse($from->toDateString())->toDateString(),
            $until === null ? '' : CarbonImmutable::parse($until->toDateString())->toDateString(),
        );
    }

    /** The first day this version applies. */
    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->bound(0));
    }

    /**
     * The first day this version no longer applies, or null while open-ended.
     *
     * Deliberately not "the last day it applies": the stored bound is
     * exclusive, and converting it to an inclusive one here would put the
     * off-by-one back in the place this model exists to remove it from.
     * {@see lastDay()} is there for the one caller that genuinely wants to
     * print it.
     */
    public function endsBefore(): ?CarbonImmutable
    {
        $upper = $this->bound(1);

        return $upper === '' ? null : CarbonImmutable::parse($upper);
    }

    /** The last day this version applies, for display. */
    public function lastDay(): ?CarbonImmutable
    {
        return $this->endsBefore()?->subDay();
    }

    public function isOpenEnded(): bool
    {
        return $this->endsBefore() === null;
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

    /** One bound of the stored range: 0 is the lower, 1 the upper. */
    private function bound(int $which): string
    {
        $inner = trim((string) $this->validity, '[]()');
        $parts = explode(',', $inner);

        return trim($parts[$which] ?? '', '"');
    }
}
