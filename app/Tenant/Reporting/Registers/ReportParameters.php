<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Registers;

use Illuminate\Support\Carbon;

/**
 * What a register was run with, printed on the register - PF-188.
 *
 * ## Why the parameters travel with the figures
 *
 * Two people run "the account activity for May" and get different totals.
 * One of them filtered to a cost centre, one included the adjusting
 * period, one asked for a party. Every one of those is a correct answer
 * to a different question, and a page showing only figures gives the two
 * of them no way to discover which question each asked - so the argument
 * is about the ledger rather than about the filter, which is where it
 * actually is.
 *
 * ## A FILTER is a parameter, and that is why this exists beside PF-198
 *
 * {@see \App\Tenant\Reporting\Export\ReportBasis} carries the six
 * parameters every statement has - entity, period, currency, adjusting
 * treatment, as-of - and fixes its digest over exactly those. A register
 * adds filters, and a filter changes every figure on the output: the
 * activity for one cost centre and the activity for the whole entity are
 * not the same report. A digest that could not express the filter would
 * call those two the same question, which is worse than having no digest
 * at all, because somebody would rely on it.
 *
 * So this carries the statement parameters AND the filters and digests
 * both. When PF-198's basis grows a channel for filters, this composes it
 * instead - one definition of "what was this run with", which is the whole
 * point of having one.
 *
 * ## The run clock is printed and is NOT in the digest
 *
 * The digest answers "did these two runs ask the same question". The wall
 * clock is not part of the question: a register of a closed period run
 * twice an hour apart is the same report, and a digest that moved with the
 * clock would never match itself - so nobody would ever compare two, and
 * the one guarantee PF-198 asks for would be unavailable in the one case
 * it was written for.
 *
 * The clock is still printed, because "when was this taken" is the first
 * thing asked of a printout found in a file six months later.
 */
final readonly class ReportParameters
{
    /**
     * @param  array<string, ?string>  $filters  filter name => the value as a reader sees it
     */
    private function __construct(
        public string $report,
        public string $entityId,
        public string $from,
        public string $until,
        public string $currency,
        public bool $includesAdjusting,
        public string $runAt,
        public array $filters,
    ) {}

    /**
     * @param  array<string, ?string>  $filters
     */
    public static function for(
        string $report,
        string $entityId,
        Carbon $from,
        Carbon $until,
        string $currency,
        bool $includesAdjusting = true,
        array $filters = [],
        ?Carbon $runAt = null,
    ): self {
        /*
         | The filters are sorted by name, so the digest cannot depend on
         | the order a caller happened to build them in.
         |
         | Two screens passing the same two filters in different orders
         | would otherwise produce two digests for one question, and the
         | person comparing them has no way to see why - the printed
         | blocks are identical.
         */
        $named = array_filter($filters, static fn (?string $value): bool => $value !== null);
        ksort($named);

        return new self(
            report: $report,
            entityId: $entityId,
            from: $from->toDateString(),
            until: $until->toDateString(),
            currency: $currency,
            includesAdjusting: $includesAdjusting,
            runAt: ($runAt ?? Carbon::now())->toIso8601String(),
            filters: $named,
        );
    }

    /**
     * A stable identifier for the QUESTION, filters included.
     *
     * The key order is fixed by this array literal rather than by
     * whatever order anything upstream used, because a digest that
     * depended on insertion order would differ between two runs that
     * asked exactly the same thing - and nobody would ever work out why.
     */
    public function digest(): string
    {
        return hash('sha256', json_encode([
            'report' => $this->report,
            'entity' => $this->entityId,
            'from' => $this->from,
            'until' => $this->until,
            'currency' => $this->currency,
            'adjusting' => $this->includesAdjusting,
            'filters' => $this->filters,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'report' => $this->report,
            'entity_id' => $this->entityId,
            'from' => $this->from,
            'until' => $this->until,
            'currency' => $this->currency,
            'includes_adjusting' => $this->includesAdjusting,
            'filters' => $this->filters,
            'run_at' => $this->runAt,
            'digest' => $this->digest(),
            'why' => 'Every figure below is an answer to these parameters and to no others. The digest '.
                'covers the parameters and the filters and not the run time, so two runs of the same '.
                'question over a closed period carry the same digest and can be compared.',
        ];
    }

    /**
     * The block a reader sees, in both languages.
     *
     * @return list<array{label_ar: string, label_en: string, value: string}>
     */
    public function lines(): array
    {
        $lines = [
            ['label_ar' => 'التقرير', 'label_en' => 'Report', 'value' => $this->report],
            ['label_ar' => 'من', 'label_en' => 'From', 'value' => $this->from],
            ['label_ar' => 'إلى', 'label_en' => 'Until', 'value' => $this->until],
            ['label_ar' => 'العملة', 'label_en' => 'Currency', 'value' => $this->currency],
            [
                'label_ar' => 'قيود فترة التسوية',
                'label_en' => 'Adjusting entries',
                /*
                 | Spelled out rather than ticked.
                 |
                 | "Adjusting entries ☑" reads as a setting somebody left
                 | on. "included" reads as a statement about the figures
                 | beside it, which is what it is.
                 */
                'value' => $this->includesAdjusting ? 'included' : 'excluded',
            ],
        ];

        foreach ($this->filters as $name => $value) {
            $lines[] = [
                'label_ar' => 'مُرشَّح: '.$name,
                'label_en' => 'Filtered by '.$name,
                'value' => (string) $value,
            ];
        }

        /*
         | And a line saying there was no filter at all.
         |
         | An unfiltered register and a register whose filter block did
         | not print look identical on paper, and the difference is every
         | figure on it. Saying "none" costs a line and removes the
         | question.
         */
        if ($this->filters === []) {
            $lines[] = ['label_ar' => 'المرشِّحات', 'label_en' => 'Filters', 'value' => 'none'];
        }

        $lines[] = ['label_ar' => 'لحظة التشغيل', 'label_en' => 'Run at', 'value' => $this->runAt];
        $lines[] = [
            'label_ar' => 'بصمة السؤال',
            'label_en' => 'Question digest',
            'value' => substr($this->digest(), 0, 16),
        ];

        return $lines;
    }
}
