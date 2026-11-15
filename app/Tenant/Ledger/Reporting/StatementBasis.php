<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Reporting;

use App\Support\Lang\ArabicCount;
use Illuminate\Support\Carbon;

/**
 * What a statement was built from, in words - PF-051.
 *
 * ## A boolean in a payload is not a statement saying what it did
 *
 * `includes_adjusting: false` is the right fact and it is not a declaration.
 * It sits in an array that a screen may ignore, a PDF may not print and an
 * export may drop - and the one place it matters is the printed page somebody
 * puts in front of an auditor.
 *
 * So the basis is a value object that renders a sentence, in both languages,
 * and every report carries it. A statement that does not say whether it
 * includes period 13 cannot be compared to another one: the same entity, the
 * same date, two different figures, and nothing on either page to say why.
 *
 * ## Whether any adjusting entries EXIST is part of it
 *
 * "Excluding adjusting entries" on a year that has none is the same figure as
 * including them, and saying so settles an argument before it starts. It also
 * catches the real mistake in the other direction: a statement marked
 * "excluding" on a year with forty adjustments is one somebody will compare
 * to the filed accounts and find short.
 *
 * ## IAS 10, which is why this is not presentation detail
 *
 * Adjusting entries are what the auditor asked for after the year ended. A
 * figure that includes them is the reported figure; one that excludes them is
 * the operational figure the business was running on. Both are legitimate and
 * they are not interchangeable, and a page that does not say which one it is
 * showing is a page that will be quoted as the other.
 */
final readonly class StatementBasis
{
    private function __construct(
        public bool $includesAdjusting,
        public int $adjustingEntries,
        public string $asAt,
        public ?string $from,
        public string $currency,
        public string $source,
    ) {}

    /**
     * @param  string  $source  'journal' or 'read_model' - which side the
     *                          figures were read from
     */
    public static function of(
        bool $includesAdjusting,
        int $adjustingEntries,
        Carbon $asAt,
        ?Carbon $from,
        string $currency,
        string $source = 'journal',
    ): self {
        return new self(
            includesAdjusting: $includesAdjusting,
            adjustingEntries: $adjustingEntries,
            asAt: $asAt->toDateString(),
            from: $from?->toDateString(),
            currency: $currency,
            source: $source,
        );
    }

    /**
     * Whether the choice made any difference to the figures.
     *
     * False when there are no adjusting entries in range, which is the common
     * case during the year and the one where "excluding adjusting entries"
     * reads as a warning about nothing.
     */
    public function choiceMattered(): bool
    {
        return $this->adjustingEntries > 0;
    }

    /** The declaration a statement prints. */
    public function declaration(string $locale): string
    {
        return $locale === 'ar' ? $this->arabic() : $this->english();
    }

    /**
     * Both languages at once, for a payload that does not know the reader.
     *
     * @return array{
     *     includes_adjusting: bool,
     *     adjusting_entries: int,
     *     choice_mattered: bool,
     *     as_at: string,
     *     from: ?string,
     *     currency: string,
     *     source: string,
     *     declaration_ar: string,
     *     declaration_en: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'includes_adjusting' => $this->includesAdjusting,
            'adjusting_entries' => $this->adjustingEntries,
            'choice_mattered' => $this->choiceMattered(),
            'as_at' => $this->asAt,
            'from' => $this->from,
            'currency' => $this->currency,
            /*
             | Which side the figures came from - PF-058.
             |
             | `read_model` is a maintained cache that BalanceRebuilder proves
             | against the journal; `journal` is the journal itself. They
             | agree, and they are different claims: anybody reconciling a
             | figure that looks wrong needs to know which one they are
             | looking at before they start.
             */
            'source' => $this->source,
            'declaration_ar' => $this->arabic(),
            'declaration_en' => $this->english(),
        ];
    }

    private function english(): string
    {
        $period = $this->from === null
            ? sprintf('as at %s', $this->asAt)
            : sprintf('for the period %s to %s', $this->from, $this->asAt);

        if (! $this->choiceMattered()) {
            return sprintf(
                'Stated in %s %s. No adjusting entries fall in this range, so including or excluding '.
                'them gives the same figures.',
                $this->currency,
                $period,
            );
        }

        return sprintf(
            'Stated in %s %s, %s the %d adjusting %s in the adjusting period.',
            $this->currency,
            $period,
            $this->includesAdjusting ? 'including' : 'EXCLUDING',
            $this->adjustingEntries,
            $this->adjustingEntries === 1 ? 'entry' : 'entries',
        );
    }

    private function arabic(): string
    {
        $period = $this->from === null
            ? sprintf('كما في %s', $this->asAt)
            : sprintf('للفترة من %s إلى %s', $this->from, $this->asAt);

        if (! $this->choiceMattered()) {
            return sprintf(
                'بعملة %s %s. لا توجد قيود تسوية في هذا النطاق، فتضمينها أو استثناؤها يعطي الأرقام ذاتها.',
                $this->currency,
                $period,
            );
        }

        /*
         | The counted noun, through the shared helper.
         |
         | Arabic counts 1 as a singular, 2 as a dual, 3 to 10 as a broken
         | plural and 11 upwards as a singular again. The rules were written
         | inline here first, and the second place that needed them got them
         | wrong - so there is one implementation now.
         */
        $entries = ArabicCount::adjustingEntries($this->adjustingEntries);

        return sprintf(
            'بعملة %s %s، %s %s في فترة التسوية.',
            $this->currency,
            $period,
            $this->includesAdjusting ? 'بتضمين' : 'باستثناء',
            $entries,
        );
    }
}
