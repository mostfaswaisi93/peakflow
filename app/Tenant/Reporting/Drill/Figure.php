<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Drill;

use App\Tenant\Reporting\Exceptions\ReportRefused;
use Illuminate\Support\Carbon;

/**
 * A number on a report, named well enough to be explained - PF-189.
 *
 * ## Why a figure is a value and not a method per report
 *
 * PF-189 asks for one drill-down, not one per report. The only way to have
 * one is for every report to describe its figures in the same words, so
 * this is those words: which accounts the figure covers, over which dates,
 * on which basis. A statement of financial position, a cash flow statement
 * and an account activity register all produce the same three facts, and a
 * drill-down that took a report name would need a branch per report - which
 * is the per-report implementation the task forbids, written one `if` at a
 * time.
 *
 * ## A BALANCE and a MOVEMENT are different figures
 *
 * This is the distinction a drill-down most often gets wrong, and it is
 * invisible when it does. "Trade receivables, 41,000" on a statement of
 * financial position is every line that ever reached those accounts; the
 * same line on a cash flow statement is the movement over one period. Drill
 * into the balance with the period's bounds and the lines come to the
 * movement - a smaller number, correct for a different question, and the
 * reader concludes the report is wrong.
 *
 * So the two are separate constructors and the basis is on the output.
 * {@see asAt()} has no lower bound, which is what "a balance" means;
 * {@see forPeriod()} has both.
 *
 * ## The kinds are a closed set
 *
 * Because each one has to be turned into a set of account codes, and a
 * kind nobody has written that translation for would drill into nothing
 * and present it as an explanation. An unknown kind is refused here, where
 * the caller can see it, rather than in a query that returns no rows.
 */
final readonly class Figure
{
    /**
     * `account` one account's code. `statement_line` every account on an
     * IAS 1 line. `cash_flow_activity` every account whose line belongs to
     * one IAS 7 activity. `account_type` asset, liability, equity, revenue
     * or expense - the coarsest grouping any report shows.
     */
    public const array KINDS = ['account', 'statement_line', 'cash_flow_activity', 'account_type'];

    private function __construct(
        public string $report,
        public string $kind,
        public string $value,
        public string $entityId,
        public string $currency,
        public ?Carbon $from,
        public Carbon $until,
        public bool $includesAdjusting,
        public ?string $labelAr,
        public ?string $labelEn,
    ) {}

    /**
     * A balance: everything that ever reached these accounts, up to a date.
     *
     * @throws ReportRefused
     */
    public static function asAt(
        string $report,
        string $kind,
        string $value,
        string $entityId,
        string $currency,
        Carbon $until,
        bool $includesAdjusting = true,
        ?string $labelAr = null,
        ?string $labelEn = null,
    ): self {
        return new self(
            report: $report,
            kind: self::assertKind($kind),
            value: $value,
            entityId: $entityId,
            currency: $currency,
            from: null,
            until: $until,
            includesAdjusting: $includesAdjusting,
            labelAr: $labelAr,
            labelEn: $labelEn,
        );
    }

    /**
     * A movement: what these accounts did between two dates.
     *
     * @throws ReportRefused
     */
    public static function forPeriod(
        string $report,
        string $kind,
        string $value,
        string $entityId,
        string $currency,
        Carbon $from,
        Carbon $until,
        bool $includesAdjusting = true,
        ?string $labelAr = null,
        ?string $labelEn = null,
    ): self {
        if ($until->lt($from)) {
            throw ReportRefused::because(
                'A figure cannot cover a period that ends before it starts. Drilling into one would '.
                'return no lines, which reads as a figure nothing explains - and that is the one '.
                'conclusion PF-189 exists to make impossible.',
            );
        }

        return new self(
            report: $report,
            kind: self::assertKind($kind),
            value: $value,
            entityId: $entityId,
            currency: $currency,
            from: $from,
            until: $until,
            includesAdjusting: $includesAdjusting,
            labelAr: $labelAr,
            labelEn: $labelEn,
        );
    }

    public function isBalance(): bool
    {
        return $this->from === null;
    }

    /** What the bounds mean, for the reader who is comparing two numbers. */
    public function basis(): string
    {
        return $this->isBalance()
            ? sprintf(
                'a balance: every posted line on these accounts up to %s, with no lower bound, because '.
                'a balance is the whole history and not one period of it',
                $this->until->toDateString(),
            )
            : sprintf(
                'a movement: the posted lines on these accounts between %s and %s',
                $this->from?->toDateString() ?? '',
                $this->until->toDateString(),
            );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'report' => $this->report,
            'kind' => $this->kind,
            'value' => $this->value,
            'label_ar' => $this->labelAr,
            'label_en' => $this->labelEn,
            'entity_id' => $this->entityId,
            'currency' => $this->currency,
            'from' => $this->from?->toDateString(),
            'until' => $this->until->toDateString(),
            'includes_adjusting' => $this->includesAdjusting,
            'is_balance' => $this->isBalance(),
            'basis' => $this->basis(),
        ];
    }

    /** @throws ReportRefused */
    private static function assertKind(string $kind): string
    {
        if (! \in_array($kind, self::KINDS, true)) {
            throw ReportRefused::because(sprintf(
                '"%s" is not a kind of figure this product can explain. They are %s. An unknown kind '.
                'would resolve to no accounts, and a drill-down that returns nothing looks exactly like '.
                'a figure with nothing behind it.',
                $kind,
                implode(', ', self::KINDS),
            ));
        }

        return $kind;
    }
}
