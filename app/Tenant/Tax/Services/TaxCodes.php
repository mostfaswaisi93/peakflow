<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Exceptions\TaxCodeRefused;
use App\Tenant\Tax\Models\TaxCode;
use App\Tenant\Tax\Models\TaxRate;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolving a tax code on a date, and changing a rate - PF-075, PF-076.
 *
 * ## Resolution is by TAX POINT, never by today
 *
 * This is the whole reason the rates are versioned. A document issued today
 * for a supply made in June resolves June's rate, and a document reprinted in
 * 2030 resolves the rate that was in force when it was issued. A resolver that
 * reads "the active row" gets today's rate onto a historical invoice, and the
 * invoice is wrong in a way that only shows when somebody compares it to the
 * return it was filed on.
 *
 * There is deliberately no method here called `current()`.
 *
 * ## A rate change is two writes in one transaction, in one order
 *
 * The open version's range is closed at the new version's start, and the new
 * row is inserted. Done in the other order, the exclusion constraint refuses
 * the insert - which is the constraint working, and is why this is a method
 * rather than a note in a runbook.
 *
 * The ranges are half-open (`[from,until)`), so a successor's start IS its
 * predecessor's end: they abut exactly, with no gap and no overlap, and no
 * call site does arithmetic on "the day before".
 *
 * Strictly, closing the old range is an edit. PF-076 says a rate change never
 * edits the old version, and what that means precisely is that the RATE is
 * never edited - the validity window is what changes, because an open-ended
 * row has to stop being open-ended for a successor to exist.
 */
final class TaxCodes
{
    /**
     * The code and the rate version in force on a date.
     *
     * @return array{TaxCode, TaxRate}
     *
     * @throws TaxCodeRefused
     */
    public function resolve(string $code, CarbonInterface $taxPoint): array
    {
        $taxCode = TaxCode::query()->where('code', $code)->first();

        if ($taxCode === null) {
            throw TaxCodeRefused::unknown($code);
        }

        $versions = $taxCode->rates()->effectiveOn($taxPoint)->get();

        if ($versions->isEmpty()) {
            throw TaxCodeRefused::noneInForce($code, $taxPoint->toDateString());
        }

        /*
         | More than one is impossible to commit, and checked anyway.
         |
         | The exclusion constraint is the authority. A resolver that trusted
         | it would silently pick a row at random the day the constraint is
         | dropped by a migration somebody wrote in a hurry - and "picks a row
         | at random" means the same invoice computed twice produces two
         | different tax amounts.
         */
        if ($versions->count() > 1) {
            throw TaxCodeRefused::ambiguous($code, $taxPoint->toDateString(), $versions->count());
        }

        return [$taxCode, $versions->first()];
    }

    /** The code and version in force, or null where there is none. */
    public function find(string $code, CarbonInterface $taxPoint): ?array
    {
        try {
            return $this->resolve($code, $taxPoint);
        } catch (TaxCodeRefused) {
            return null;
        }
    }

    /** The rate as a percentage - `15.000000` means fifteen percent. */
    public function rateOn(string $code, CarbonInterface $taxPoint): BigDecimal
    {
        [, $version] = $this->resolve($code, $taxPoint);

        return $version->percentage();
    }

    /**
     * Every version of a code, oldest first.
     *
     * The history is the answer to "what rate did we charge in June", which is
     * a question asked by an auditor rather than by the application.
     *
     * @return Collection<int, TaxRate>
     */
    public function versionsOf(string $code): Collection
    {
        $taxCode = TaxCode::query()->where('code', $code)->first();

        if ($taxCode === null) {
            return new Collection();
        }

        return $taxCode->rates()->orderByRaw('lower(validity)')->get();
    }

    /**
     * Every code available on a date, for a picker.
     *
     * @return Collection<int, TaxCode>
     */
    public function availableOn(CarbonInterface $taxPoint, ?string $jurisdiction = null): Collection
    {
        return TaxCode::query()
            ->where('active', true)
            ->when($jurisdiction !== null, fn ($q) => $q->where('jurisdiction', $jurisdiction))
            ->whereHas('rates', fn ($q) => $q->effectiveOn($taxPoint))
            ->orderBy('code')
            ->get();
    }

    /**
     * Introduce a code and its first rate version.
     *
     * @param  array<string, mixed>  $reportingBoxes
     */
    public function introduce(
        string $entityId,
        string $code,
        TaxCategory $category,
        string $jurisdiction,
        BigDecimal|string $rate,
        CarbonInterface $from,
        string $labelAr,
        string $labelEn,
        string $source,
        array $reportingBoxes = [],
        ?string $exemptionReasonCode = null,
        ?string $exemptionReasonAr = null,
        ?string $exemptionReasonEn = null,
    ): TaxCode {
        return DB::connection('tenant')->transaction(function () use (
            $entityId, $code, $category, $jurisdiction, $rate, $from,
            $labelAr, $labelEn, $source, $reportingBoxes,
            $exemptionReasonCode, $exemptionReasonAr, $exemptionReasonEn,
        ): TaxCode {
            $taxCode = TaxCode::query()->create([
                'entity_id' => $entityId,
                'code' => $code,
                'category' => $category,
                'jurisdiction' => strtoupper($jurisdiction),
                'label_ar' => $labelAr,
                'label_en' => $labelEn,
                'reporting_boxes' => $reportingBoxes,
                'exemption_reason_code' => $exemptionReasonCode,
                'exemption_reason_ar' => $exemptionReasonAr,
                'exemption_reason_en' => $exemptionReasonEn,
                'active' => true,
            ]);

            TaxRate::query()->create([
                'entity_id' => $entityId,
                'tax_code_id' => $taxCode->getKey(),
                'rate' => (string) $rate,
                'source' => $source,
                'validity' => TaxRate::range(CarbonImmutable::parse($from->toDateString())),
            ]);

            return $taxCode->refresh();
        });
    }

    /**
     * Change a rate: close the open version and open a new one.
     *
     * @throws TaxCodeRefused
     */
    public function supersede(
        string $code,
        BigDecimal|string $newRate,
        CarbonInterface $from,
        string $source,
    ): TaxRate {
        $from = CarbonImmutable::parse($from->toDateString());

        return DB::connection('tenant')->transaction(function () use ($code, $newRate, $from, $source): TaxRate {
            $taxCode = TaxCode::query()->where('code', $code)->lockForUpdate()->first();

            if ($taxCode === null) {
                throw TaxCodeRefused::unknown($code);
            }

            $open = $taxCode->openEndedRate();

            if ($open === null) {
                throw TaxCodeRefused::nothingOpenEnded($code);
            }

            /*
             | The new version must start AFTER the one it replaces.
             |
             | Starting on or before would mean editing history, and every
             | document issued between those dates resolved its rate from the
             | version being overwritten. The exclusion constraint would also
             | refuse it - this is here so the refusal names the reason rather
             | than quoting a constraint.
             */
            if (! $from->isAfter($open->startsOn())) {
                throw TaxCodeRefused::notInTheFuture(
                    $code,
                    $from->toDateString(),
                    $open->startsOn()->toDateString(),
                );
            }

            /*
             | Closed FIRST, then the new row inserted.
             |
             | The other order is refused by the exclusion constraint, because
             | for one moment two ranges would both be open-ended. And the new
             | version's start IS the old version's end, because the ranges are
             | half-open - so there is no "day before" to compute and no
             | off-by-one to get wrong.
             */
            $open->update(['validity' => TaxRate::range($open->startsOn(), $from)]);

            return TaxRate::query()->create([
                'entity_id' => $taxCode->entity_id,
                'tax_code_id' => $taxCode->getKey(),
                'rate' => (string) $newRate,
                'source' => $source,
                'validity' => TaxRate::range($from),
            ]);
        });
    }
}
