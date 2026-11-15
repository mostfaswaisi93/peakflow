<?php

declare(strict_types=1);

namespace App\Support\Database\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * A row that is in force for a period, stored as a half-open `daterange`.
 *
 * ## Why a range and not two nullable dates
 *
 * `[2018-01-01,2020-07-01)` is inclusive at the start and exclusive at the
 * end, so it abuts `[2020-07-01,)` without overlapping it and without leaving
 * a gap. "In force on a date" is then `validity @> date` - ONE containment
 * test that the model, the query and the exclusion constraint all share.
 *
 * Two nullable columns need a COALESCE in every place that reads them, and the
 * day one of those places is written with `<` while another uses `<=` is the
 * day a document resolves two versions of the same thing. PF-076 learned that
 * on tax rates; PF-079 reuses the shape rather than the lesson.
 *
 * ## The upper bound is "no longer applies", not "last day"
 *
 * Which reads oddly and is the form that composes: a successor's lower bound
 * IS its predecessor's upper bound, so no call site anywhere does arithmetic
 * on "the day before". {@see lastDay()} exists for the one caller that wants
 * to print it.
 *
 * Expects a `validity` column of type `daterange`, an exclusion constraint
 * refusing overlaps over whatever identity the row has, and CHECKs refusing an
 * empty range and an unbounded start.
 */
trait HasValidityRange
{
    /**
     * The column holding the range.
     *
     * Overridable because the word is not always right: a tax code's row is
     * VALID for a period, while a return's row IS a period, and calling the
     * second one `validity` would make every query read as though it were
     * asking the first question.
     */
    protected static function validityColumn(): string
    {
        return 'validity';
    }

    /** @param  Builder<covariant static>  $query */
    public function scopeEffectiveOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query->whereRaw(
            sprintf('%s @> ?::date', static::validityColumn()),
            [$date->toDateString()],
        );
    }

    /**
     * Build a validity range, half-open at the end.
     *
     * `$until` is the first day the row is NO LONGER in force.
     */
    public static function range(CarbonInterface $from, ?CarbonInterface $until = null): string
    {
        return sprintf(
            '[%s,%s)',
            CarbonImmutable::parse($from->toDateString())->toDateString(),
            $until === null ? '' : CarbonImmutable::parse($until->toDateString())->toDateString(),
        );
    }

    /** The first day this row applies. */
    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->bound(0));
    }

    /** The first day this row no longer applies, or null while open-ended. */
    public function endsBefore(): ?CarbonImmutable
    {
        $upper = $this->bound(1);

        return $upper === '' ? null : CarbonImmutable::parse($upper);
    }

    /** The last day it DOES apply, for the one caller that prints it. */
    public function lastDay(): ?CarbonImmutable
    {
        return $this->endsBefore()?->subDay();
    }

    public function isOpenEnded(): bool
    {
        return $this->endsBefore() === null;
    }

    public function isEffectiveOn(CarbonInterface $date): bool
    {
        $day = CarbonImmutable::parse($date->toDateString());

        return $day->greaterThanOrEqualTo($this->startsOn())
            && ($this->endsBefore() === null || $day->lessThan($this->endsBefore()));
    }

    /** One bound of the stored range: 0 is the lower, 1 the upper. */
    private function bound(int $which): string
    {
        $inner = trim((string) $this->{static::validityColumn()}, '[]()');
        $parts = explode(',', $inner);

        return trim($parts[$which] ?? '', '"');
    }
}
