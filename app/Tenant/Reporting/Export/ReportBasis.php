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
 * ## `as_of` is a parameter, not `now()`
 *
 * A report that read the clock would be unreproducible by construction,
 * and the difference would show up as a one-row change weeks later with
 * nothing to explain it. Where a caller does not supply one the report
 * records the instant it used, so re-running it with that value reproduces
 * it exactly.
 */
final readonly class ReportBasis
{
    public function __construct(
        public string $entityId,
        public string $entityCode,
        public string $from,
        public string $until,
        public string $functionalCurrency,
        public ?string $presentationCurrency,
        public bool $includesAdjusting,
        public string $asOf,
    ) {}

    public static function for(
        string $entityId,
        string $entityCode,
        Carbon $from,
        Carbon $until,
        string $functionalCurrency,
        ?string $presentationCurrency = null,
        bool $includesAdjusting = true,
        ?Carbon $asOf = null,
    ): self {
        return new self(
            entityId: $entityId,
            entityCode: $entityCode,
            from: $from->toDateString(),
            until: $until->toDateString(),
            functionalCurrency: $functionalCurrency,
            presentationCurrency: $presentationCurrency,
            includesAdjusting: $includesAdjusting,
            asOf: ($asOf ?? Carbon::now())->toIso8601String(),
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
            'entity' => $this->entityId,
            'from' => $this->from,
            'until' => $this->until,
            'functional' => $this->functionalCurrency,
            'presentation' => $this->presentationCurrency,
            'adjusting' => $this->includesAdjusting,
            'as_of' => $this->asOf,
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
            'entity_id' => $this->entityId,
            'entity_code' => $this->entityCode,
            'from' => $this->from,
            'until' => $this->until,
            'functional_currency' => $this->functionalCurrency,
            'presentation_currency' => $this->presentationCurrency,
            'includes_adjusting' => $this->includesAdjusting,
            'as_of' => $this->asOf,
            'digest' => $this->digest(),
        ];
    }

    /**
     * @return list<array{label_ar: string, label_en: string, value: string}>
     */
    public function lines(): array
    {
        return [
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
            ['label_ar' => 'لحظة التشغيل', 'label_en' => 'Run as of', 'value' => $this->asOf],
            [
                'label_ar' => 'بصمة المعايير',
                'label_en' => 'Parameter digest',
                'value' => substr($this->digest(), 0, 16),
            ],
        ];
    }
}
