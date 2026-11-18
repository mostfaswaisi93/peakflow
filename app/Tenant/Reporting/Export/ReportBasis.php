<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Export;

use Illuminate\Support\Carbon;

/**
 * What a report was run with, printed on the report - PF-198.
 *
 * ## Why this is on the output and not in a log
 *
 * Two people run "the trial balance" and get different numbers. One of
 * them included the adjusting period and the other did not, or one asked
 * as at the 30th and the other as at the 31st, or one is looking at a
 * presentation currency. Every one of those is a correct answer to a
 * different question, and a page that shows only the figures gives the
 * two of them no way to discover which question each asked.
 *
 * So the parameters travel with the figures. On screen, in the CSV, in
 * the PDF, in the JSON - the same block, so a printout handed across a
 * desk carries its own provenance.
 *
 * ## The fingerprint, and what it does NOT promise
 *
 * `digest()` is a hash of the parameters, not of the data. Two runs with
 * the same digest asked the same question; they return the same numbers
 * only while the underlying period is closed, which is exactly the
 * guarantee PF-198 asks for and the only one that is true. A hash of the
 * figures would be a different and weaker thing: it would say two reports
 * agree without saying they are comparable.
 *
 * ## `as_of` is a parameter, and the clock is not
 *
 * These are two different facts and this class used to conflate them. It
 * defaulted `as_of` to `Carbon::now()` and put the result in the digest -
 * so in production two runs of the same question over a closed period
 * NEVER carried the same digest, which is the one guarantee the digest
 * exists for. Every pair of runs looked like two different questions. The
 * check could not see it either, because two calls a millisecond apart
 * share a timestamp to the second.
 *
 * They are separate now. `asOf` is what the caller ASKED for and is part
 * of the question: a balance sheet as at the 30th and one as at the 31st
 * are different reports. `ranAt` is when the button was pressed, is always
 * recorded, and is not in the digest - a register of a closed period run
 * twice an hour apart is the same report, and "when was this taken" is
 * provenance rather than a parameter.
 *
 * A caller who supplies no `asOf` is asking "as the data stands", which is
 * one question however many times it is asked.
 *
 * ## Filters are parameters too
 *
 * A filter changes every figure on the output: the activity for one cost
 * centre and the activity for the whole entity are not the same report. A
 * digest that could not express a filter would call those two the same
 * question, which is worse than having no digest, because somebody would
 * rely on it.
 *
 * {@see \App\Tenant\Reporting\Registers\ReportParameters} composes this
 * rather than defining a second digest. There was briefly one each, and
 * the two disagreed about the clock - which is how a product ends up with
 * two fingerprints for one report and no way to say which is right.
 */
final readonly class ReportBasis
{
    /**
     * @param  array<string, string>  $filters  filter name => the value as a reader sees it
     */
    public function __construct(
        public string $report,
        public string $entityId,
        public string $entityCode,
        public string $from,
        public string $until,
        public string $functionalCurrency,
        public ?string $presentationCurrency,
        public bool $includesAdjusting,
        public ?string $asOf,
        public string $ranAt,
        public array $filters = [],
    ) {}

    /**
     * @param  array<string, ?string>  $filters
     */
    public static function for(
        string $entityId,
        string $entityCode,
        Carbon $from,
        Carbon $until,
        string $functionalCurrency,
        ?string $presentationCurrency = null,
        bool $includesAdjusting = true,
        ?Carbon $asOf = null,
        string $report = 'statement',
        array $filters = [],
        ?Carbon $ranAt = null,
    ): self {
        /*
         | Sorted by name, so the digest cannot depend on the order a
         | caller happened to build them in.
         |
         | Two screens passing the same two filters in different orders
         | would otherwise produce two digests for one question, and the
         | person comparing them has no way to see why: the printed blocks
         | are identical.
         */
        $named = array_filter($filters, static fn (?string $value): bool => $value !== null);
        ksort($named);

        return new self(
            report: $report,
            entityId: $entityId,
            entityCode: $entityCode,
            from: $from->toDateString(),
            until: $until->toDateString(),
            functionalCurrency: $functionalCurrency,
            presentationCurrency: $presentationCurrency,
            includesAdjusting: $includesAdjusting,
            asOf: $asOf?->toIso8601String(),
            ranAt: ($ranAt ?? Carbon::now())->toIso8601String(),
            filters: $named,
        );
    }

    /**
     * A stable identifier for the QUESTION this report answers.
     *
     * The key order is fixed by the array literal rather than by whatever
     * order a caller happened to build it in, because a digest that
     * depended on insertion order would differ between two runs that asked
     * exactly the same thing - and nobody would ever work out why.
     */
    public function digest(): string
    {
        return hash('sha256', json_encode([
            'report' => $this->report,
            'entity' => $this->entityId,
            'from' => $this->from,
            'until' => $this->until,
            'functional' => $this->functionalCurrency,
            'presentation' => $this->presentationCurrency,
            'adjusting' => $this->includesAdjusting,
            /*
             | The ASKED-FOR instant, which is usually null, and never the
             | clock. See the class note: the clock in here is what made
             | two runs of one question carry two digests.
             */
            'as_of' => $this->asOf,
            'filters' => $this->filters,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The block a reader sees, in both languages.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'report' => $this->report,
            'entity_id' => $this->entityId,
            'entity_code' => $this->entityCode,
            'from' => $this->from,
            'until' => $this->until,
            'functional_currency' => $this->functionalCurrency,
            'presentation_currency' => $this->presentationCurrency,
            'includes_adjusting' => $this->includesAdjusting,
            'filters' => $this->filters,
            'as_of' => $this->asOf,
            'ran_at' => $this->ranAt,
            'digest' => $this->digest(),
            'why' => 'Every figure below answers these parameters and no others. The digest covers the '.
                'parameters and the filters and NOT the run time, so two runs of the same question over '.
                'a closed period carry the same digest and can be compared.',
        ];
    }

    /**
     * @return list<array{label_ar: string, label_en: string, value: string}>
     */
    public function lines(): array
    {
        $lines = [
            ['label_ar' => 'الشركة', 'label_en' => 'Entity', 'value' => $this->entityCode],
            ['label_ar' => 'من', 'label_en' => 'From', 'value' => $this->from],
            ['label_ar' => 'إلى', 'label_en' => 'Until', 'value' => $this->until],
            [
                'label_ar' => 'العملة الوظيفية',
                'label_en' => 'Functional currency',
                'value' => $this->functionalCurrency,
            ],
            [
                'label_ar' => 'عملة العرض',
                'label_en' => 'Presentation currency',
                'value' => $this->presentationCurrency ?? $this->functionalCurrency,
            ],
            [
                'label_ar' => 'قيود فترة التسوية',
                'label_en' => 'Adjusting entries',
                /*
                 | Spelled out, not a tick.
                 |
                 | "Adjusting entries ☑" is read as a setting somebody left
                 | on. "Included" is read as a statement about what is in
                 | the figures beside it, which is what it is.
                 */
                'value' => $this->includesAdjusting ? 'included' : 'excluded',
            ],
            /*
             | Both instants, and they say different things.
             |
             | "As at" is the question and is omitted when nobody asked
             | one - printing the clock under that label is what let the
             | two be confused in the first place.
             */
            [
                'label_ar' => 'كما في',
                'label_en' => 'As at',
                'value' => $this->asOf ?? 'as the data stands',
            ],
            ['label_ar' => 'لحظة التشغيل', 'label_en' => 'Run at', 'value' => $this->ranAt],
            [
                'label_ar' => 'بصمة المعايير',
                'label_en' => 'Parameter digest',
                'value' => substr($this->digest(), 0, 16),
            ],
        ];

        foreach ($this->filters as $name => $value) {
            $lines[] = [
                'label_ar' => 'مُرشَّح: '.$name,
                'label_en' => 'Filtered by '.$name,
                'value' => $value,
            ];
        }

        /*
         | And a line saying there was no filter at all.
         |
         | An unfiltered report and a report whose filter block did not
         | print look identical on paper, and the difference is every
         | figure on it. Saying "none" costs a line and removes the
         | question.
         */
        if ($this->filters === []) {
            $lines[] = ['label_ar' => 'المرشِّحات', 'label_en' => 'Filters', 'value' => 'none'];
        }

        return $lines;
    }
}
