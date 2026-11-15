<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Tax\Exceptions\AdjustmentRefused;
use App\Tenant\Tax\Models\ReturnAdjustment;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Facades\DB;

/**
 * Corrections to filed returns, and the schedule that accompanies them - PF-087.
 *
 * ## Where a correction goes
 *
 * Into the next OPEN period, with a reference to the period it corrects. Not
 * into the filed return, which cannot change - and not into whichever period
 * somebody happens to be working in, because a correction reported two
 * quarters late is a correction the authority will ask about.
 *
 * ## Some regimes amend instead, and this refuses rather than guessing
 *
 * `config/jurisdictions.php` declares which, per profile. Reporting a
 * next-period adjustment where the regime expects an amended return files a
 * figure the authority cannot match against anything; the refusal says so and
 * names the regime's own procedure.
 *
 * The thresholds some regimes set - correct in the next return below an
 * amount, amend above it - are deliberately not modelled. That is a figure per
 * regime per year, and a wrong one picks the wrong procedure silently.
 */
final class ReturnAdjustments
{
    public function __construct(
        private readonly ReturnPeriods $periods,
        private readonly ReturnBoxes $boxes,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Record a correction to a filed period.
     *
     * @throws AdjustmentRefused
     */
    public function record(
        Entity $entity,
        ReturnPeriod $filed,
        string $box,
        Money $net,
        Money $tax,
        string $reason,
        ?string $userId = null,
        ?string $profile = null,
    ): ReturnAdjustment {
        if (! $filed->isFiled()) {
            throw AdjustmentRefused::thePeriodIsNotFiled($filed->label);
        }

        $profile ??= (string) config('jurisdictions.default', 'generic');

        if ($this->regimeAmends($profile)) {
            throw AdjustmentRefused::thisRegimeAmends($profile, $filed->label);
        }

        /*
         | Long enough, and more than one word.
         |
         | A length test alone let "adjustment" through - exactly ten
         | characters - which is the word this rule exists to refuse. A single
         | word is never an explanation, whatever its length:
         | "reclassification" is seventeen characters and says nothing.
         */
        $trimmed = trim($reason);

        if (mb_strlen($trimmed) < 20 || ! str_contains($trimmed, ' ')) {
            throw AdjustmentRefused::theReasonIsTooShort();
        }

        if ($net->isZero() && $tax->isZero()) {
            throw AdjustmentRefused::itChangesNothing();
        }

        $form = $this->boxes->formFor((string) $filed->jurisdiction);

        if (! isset($this->boxes->boxes($form)[$box])) {
            throw AdjustmentRefused::unknownBox($box, $form);
        }

        $into = $this->periods->nextOpenAfter($filed);

        if ($into === null) {
            throw AdjustmentRefused::noOpenPeriod((string) $filed->jurisdiction, $filed->label);
        }

        return DB::connection('tenant')->transaction(function () use (
            $entity, $filed, $into, $box, $net, $tax, $reason, $userId,
        ): ReturnAdjustment {
            $adjustment = ReturnAdjustment::create([
                'entity_id' => (string) $entity->getKey(),
                'jurisdiction' => (string) $filed->jurisdiction,
                'corrects_period_id' => (string) $filed->getKey(),
                'reported_in_period_id' => (string) $into->getKey(),
                'box' => $box,
                'currency' => $net->currency,
                'net_amount' => (string) $net,
                'tax_amount' => (string) $tax,
                'reason' => trim($reason),
                'created_by' => $userId,
            ]);

            /*
             | Its own audit record, which is half the point.
             |
             | A correction is a new statement rather than an edit, and the
             | trail is what shows who made it and when - the filed return
             | keeps its own record, untouched.
             */
            $this->audit->record(
                category: 'tax',
                action: 'return.adjusted',
                subjectType: 'return_adjustment',
                subjectId: (string) $adjustment->getKey(),
                subjectLabel: sprintf('%s %s corrects %s', $filed->jurisdiction, $into->label, $filed->label),
                after: [
                    'box' => $box,
                    'net' => (string) $net,
                    'tax' => (string) $tax,
                    'corrects' => $filed->label,
                    'reported_in' => $into->label,
                ],
                reason: trim($reason),
                entityId: (string) $entity->getKey(),
            );

            return $adjustment;
        });
    }

    /**
     * The schedule that accompanies a return.
     *
     * Grouped by the period being corrected and then by box, which is the
     * shape an authority's schedule takes: it asks what you are correcting
     * before it asks where in the form.
     *
     * @return list<array{corrects: string, box: string, net: string, tax: string, reason: string}>
     */
    public function scheduleFor(string $entityId, ReturnPeriod $period): array
    {
        $adjustments = ReturnAdjustment::query()
            ->with('corrects')
            ->where('entity_id', $entityId)
            ->where('reported_in_period_id', (string) $period->getKey())
            ->orderBy('corrects_period_id')
            ->orderBy('box')
            ->get();

        $schedule = [];

        foreach ($adjustments as $adjustment) {
            $schedule[] = [
                'corrects' => (string) ($adjustment->corrects?->label ?? ''),
                'box' => $adjustment->box,
                'net' => (string) $adjustment->net(),
                'tax' => (string) $adjustment->tax(),
                'reason' => $adjustment->reason,
            ];
        }

        return $schedule;
    }

    /**
     * What the adjustments add to a period's tax, by box.
     *
     * The return's own arithmetic needs this: a correction reported in a
     * period is part of what that period owes, and a net due computed without
     * it is the wrong figure filed on purpose.
     *
     * @return array<string, Money>
     */
    public function taxByBox(string $entityId, ReturnPeriod $period, string $currency): array
    {
        $totals = [];

        $adjustments = ReturnAdjustment::query()
            ->where('entity_id', $entityId)
            ->where('reported_in_period_id', (string) $period->getKey())
            ->get();

        foreach ($adjustments as $adjustment) {
            $box = $adjustment->box;
            $totals[$box] = ($totals[$box] ?? Money::zero($currency))->plus($adjustment->tax());
        }

        return $totals;
    }

    /** Does this regime amend the original return rather than adjust the next? */
    public function regimeAmends(string $profile): bool
    {
        $declared = config('jurisdictions.return_correction.'.$profile);

        return $declared === 'amended_return';
    }
}
