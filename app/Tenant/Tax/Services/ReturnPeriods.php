<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Tenant\Tax\Enums\FilingFrequency;
use App\Tenant\Tax\Exceptions\ReturnPeriodRefused;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generates return periods and decides which one a document falls into - PF-085.
 *
 * ## By the tax point, never by the accounting date
 *
 * A document issued on 1 June for a supply delivered on 30 May belongs to
 * May's return in every regime that takes the tax point from the delivery -
 * and it belongs to June's accounting period, because that is when it was
 * raised. Using one date for both questions puts a supply in the wrong return
 * about as often as month ends fall near a weekend.
 *
 * ## A tax point in a filed period is refused, not moved
 *
 * The tempting behaviour is to put it in the next open period, and it is
 * silent: both returns are then mis-stated, nobody chose it, and nothing
 * records it. A correction to a filed period is an adjustment that carries a
 * reference to the period it corrects, which is PF-087.
 *
 * ## Periods are generated from the registration, not on demand
 *
 * A period created by whichever document arrived first would take its
 * frequency from a guess, and the frequency decides the window every later
 * document falls into. So generation is explicit, reads the frequency the
 * registration declares, and refuses when there isn't one.
 */
final class ReturnPeriods
{
    /**
     * Generate a calendar year's periods for one registration.
     *
     * @return list<ReturnPeriod>
     *
     * @throws ReturnPeriodRefused
     */
    public function generateYear(Entity $entity, string $jurisdiction, int $year): array
    {
        $entityId = (string) $entity->getKey();
        $firstDay = CarbonImmutable::create($year, 1, 1);

        \assert($firstDay instanceof CarbonImmutable);

        $registration = $this->registration($entity, $jurisdiction, $firstDay);

        $frequency = $registration->filing_frequency === null
            ? null
            : FilingFrequency::tryFrom((string) $registration->filing_frequency);

        if ($frequency === null) {
            throw ReturnPeriodRefused::noFrequencyDeclared($jurisdiction);
        }

        return DB::connection('tenant')->transaction(function () use ($entityId, $jurisdiction, $year, $frequency): array {
            $created = [];

            foreach ($frequency->periodsIn($year) as $window) {
                $existing = ReturnPeriod::query()
                    ->where('entity_id', $entityId)
                    ->where('jurisdiction', $jurisdiction)
                    ->where('label', $window['label'])
                    ->exists();

                if ($existing) {
                    throw ReturnPeriodRefused::alreadyGenerated($window['label'], $jurisdiction);
                }

                $created[] = ReturnPeriod::create([
                    'entity_id' => $entityId,
                    'jurisdiction' => $jurisdiction,
                    'frequency' => $frequency->value,
                    'label' => $window['label'],
                    'state' => 'open',
                    'period' => ReturnPeriod::range($window['from'], $window['until']),

                    /*
                     | The due date is deliberately not computed.
                     |
                     | Every regime sets it differently - a fixed day of the
                     | following month, a number of days after the period
                     | ends, the next working day when that falls on a
                     | weekend or a public holiday - and a wrong due date is
                     | a late filing penalty. It is set when the regime's
                     | rule is recorded, and null until then, which is
                     | visibly unknown rather than quietly wrong.
                     */
                    'due_on' => null,
                ]);
            }

            return $created;
        });
    }

    /** The period a tax point falls into, if one exists. */
    public function forTaxPoint(string $entityId, string $jurisdiction, CarbonInterface $taxPoint): ?ReturnPeriod
    {
        return ReturnPeriod::query()
            ->where('entity_id', $entityId)
            ->where('jurisdiction', $jurisdiction)
            ->effectiveOn($taxPoint)
            ->first();
    }

    /**
     * The period a document may be reported in, or a refusal saying why not.
     *
     * @throws ReturnPeriodRefused
     */
    public function assertReportable(string $entityId, string $jurisdiction, CarbonInterface $taxPoint): ReturnPeriod
    {
        $period = $this->forTaxPoint($entityId, $jurisdiction, $taxPoint);

        if ($period === null) {
            throw ReturnPeriodRefused::noPeriodCovers($jurisdiction, $taxPoint->toDateString());
        }

        if ($period->isFiled()) {
            throw ReturnPeriodRefused::alreadyFiled(
                $period->label, $jurisdiction, $taxPoint->toDateString(),
            );
        }

        return $period;
    }

    /**
     * Mark a period filed.
     *
     * The submission reference is the authority's, and it is what a query
     * from them quotes back - so it is recorded at filing rather than being
     * something somebody attaches to an email afterwards.
     *
     * @throws ReturnPeriodRefused
     */
    public function file(
        ReturnPeriod $period,
        string $userId,
        ?string $submissionReference = null,
        ?CarbonInterface $at = null,
    ): ReturnPeriod {
        if ($period->isFiled()) {
            throw ReturnPeriodRefused::cannotFileAnEmptyPeriod($period->label);
        }

        $period->update([
            'state' => 'filed',
            'filed_at' => ($at ?? CarbonImmutable::now())->toDateTimeString(),
            'filed_by' => $userId,
            'submission_reference' => $submissionReference,
        ]);

        return $period->refresh();
    }

    /**
     * The first open period starting after this one - where an adjustment to a
     * filed period lands (PF-087).
     */
    public function nextOpenAfter(ReturnPeriod $period): ?ReturnPeriod
    {
        return ReturnPeriod::query()
            ->where('entity_id', $period->entity_id)
            ->where('jurisdiction', $period->jurisdiction)
            ->where('state', 'open')
            ->whereRaw('lower(period) >= ?::date', [$period->endsBefore()?->toDateString()])
            ->orderByRaw('lower(period)')
            ->first();
    }

    /** @throws ReturnPeriodRefused */
    private function registration(Entity $entity, string $jurisdiction, CarbonInterface $on): TaxRegistration
    {
        $registration = $entity->taxRegistrationOn($jurisdiction, $on);

        if ($registration === null) {
            throw ReturnPeriodRefused::notRegistered($jurisdiction, $on->toDateString());
        }

        return $registration;
    }
}
