<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\FinancialStatement;
use App\Tenant\Ledger\Enums\Maturity;
use App\Tenant\Ledger\Enums\StatementLine;
use App\Tenant\Ledger\Enums\StatementSection;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use Illuminate\Support\Carbon;

/**
 * The statement of financial position - PF-184.
 *
 * ## It is a grouping, not a report
 *
 * Every figure here comes from the trial balance, and every heading comes
 * from the chart's own IAS 1 classification. Nothing in this class knows an
 * account code. That is the whole design: a report that knew which codes to
 * add up would be wrong the first time somebody added an account, and the
 * person who added it would have no reason to think they had broken a
 * statement.
 *
 * ## What "assets equal liabilities plus equity" actually asserts
 *
 * It is not a restatement of the trial balance's own balance check. The
 * trial balance asserts debits equal credits over EVERY account, profit and
 * loss included. This statement covers only the accounts classified onto it,
 * so the two can disagree - and the gap is exactly the result for the
 * period, which has not yet been closed into retained earnings.
 *
 * So the equation is asserted AFTER the result is carried in, and a failure
 * means one of three things: an account is classified onto the wrong side,
 * an account is not classified at all, or the ledger itself is broken. The
 * statement says which, rather than printing a difference and leaving the
 * reader to guess.
 *
 * ## Unclassified is a section, not a silence
 *
 * A postable account with no statement line appears under its own heading
 * with its balance. Dropping it would make the statement balance in the one
 * case where it must not: a balance exists, it is somewhere, and a statement
 * that omits it is a statement that is wrong by exactly that amount and says
 * it is right.
 */
final class StatementOfFinancialPosition
{
    public function __construct(private readonly TrialBalanceReport $trialBalance) {}

    /**
     * The statement at a date, with the comparative one period earlier.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function asAt(
        string $entityId,
        string $functionalCurrency,
        Carbon $from,
        Carbon $until,
        ?Carbon $comparativeFrom = null,
        ?Carbon $comparativeUntil = null,
        bool $includeAdjusting = true,
    ): array {
        $current = $this->trialBalance->forPeriod(
            $entityId,
            $functionalCurrency,
            $from,
            $until,
            $includeAdjusting,
        );

        $comparative = null;

        if ($comparativeUntil !== null) {
            if ($comparativeUntil->gte($until)) {
                throw ReportRefused::because(
                    'The comparative period ends on or after the one being reported. A comparative '.
                    'column that is not earlier is the same column twice, and a reader comparing them '.
                    'would conclude nothing moved.',
                );
            }

            $comparative = $this->trialBalance->forPeriod(
                $entityId,
                $functionalCurrency,
                $comparativeFrom ?? $comparativeUntil->copy()->startOfYear(),
                $comparativeUntil,
                $includeAdjusting,
            );
        }

        $sections = $this->sections($current, $comparative, $functionalCurrency);
        $result = $this->resultForTheYear($current, $comparative, $functionalCurrency);

        return [
            'as_at' => $until->toDateString(),
            'comparative_as_at' => $comparativeUntil?->toDateString(),
            'currency' => $functionalCurrency,
            'includes_adjusting' => $includeAdjusting,
            'basis' => $current['basis'],
            'sections' => $sections,
            'result_for_the_period' => $result,
            'totals' => $this->totals($sections, $result, $functionalCurrency),
            'declaration' => $this->declare($sections, $result, $functionalCurrency),
        ];
    }

    /**
     * Every section, with its lines and the accounts under each.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $comparative
     * @return list<array<string, mixed>>
     */
    private function sections(array $current, ?array $comparative, string $currency): array
    {
        $rows = $this->byLine($current);
        $previous = $comparative === null ? [] : $this->byLine($comparative);

        $sections = [];

        foreach ([StatementSection::Assets, StatementSection::Liabilities, StatementSection::Equity] as $section) {
            $lines = [];

            foreach (StatementLine::cases() as $line) {
                if ($line->statement() !== FinancialStatement::FinancialPosition || $line->section() !== $section) {
                    continue;
                }

                $accounts = $rows[$line->value] ?? [];
                $comparativeAccounts = $previous[$line->value] ?? [];

                /*
                 | A line with nothing on it in EITHER column is left out.
                 |
                 | IAS 1.31 is explicit that an entity need not present
                 | information that is not material, and a statement padded
                 | with every line the standard allows is one nobody reads
                 | to the bottom. A line that was present last year and is
                 | empty this year stays, because its disappearance is the
                 | information.
                 */
                if ($accounts === [] && $comparativeAccounts === []) {
                    continue;
                }

                $lines[] = [
                    'line' => $line->value,
                    'label_ar' => $line->label('ar'),
                    'label_en' => $line->label('en'),
                    'reference' => $line->reference(),
                    'maturity' => $line->maturity()->value,
                    'accounts' => $this->presented($accounts, $section, $currency),
                    'amount' => (string) $this->sum($accounts, $section, $currency)->amount,
                    'comparative' => $comparative === null
                        ? null
                        : (string) $this->sum($comparativeAccounts, $section, $currency)->amount,
                ];
            }

            $sections[] = [
                'section' => $section->value,
                'label_ar' => $section->label('ar'),
                'label_en' => $section->label('en'),
                'maturities' => $this->groupByMaturity($lines),
                'total' => (string) $this->total($lines, $currency)->amount,
                'comparative_total' => $comparative === null
                    ? null
                    : (string) $this->total($lines, $currency, 'comparative')->amount,
            ];
        }

        $sections[] = $this->unclassified($current, $comparative, $currency);

        return $sections;
    }

    /**
     * The lines of a section, split current / non-current - IAS 1.60.
     *
     * Equity has neither, and gets one group labelled as not applicable
     * rather than an empty "current" heading - which would read as a
     * classification somebody made.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function groupByMaturity(array $lines): array
    {
        $groups = [];

        foreach ([Maturity::Current, Maturity::NonCurrent, Maturity::NotApplicable] as $maturity) {
            $inGroup = array_values(array_filter(
                $lines,
                static fn (array $line): bool => $line['maturity'] === $maturity->value,
            ));

            if ($inGroup === []) {
                continue;
            }

            $groups[] = [
                'maturity' => $maturity->value,
                'label_ar' => $maturity->label('ar'),
                'label_en' => $maturity->label('en'),
                'lines' => $inGroup,
            ];
        }

        return $groups;
    }

    /**
     * The postable accounts nobody has classified.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $comparative
     * @return array<string, mixed>
     */
    private function unclassified(array $current, ?array $comparative, string $currency): array
    {
        $accounts = $this->unclassifiedRows($current);
        $previous = $comparative === null ? [] : $this->unclassifiedRows($comparative);

        /*
         | Presented at their natural sign, not flipped.
         |
         | There is no section to flip them into: that is the point. A
         | reader has to see the balance as the ledger holds it, because
         | the decision they are about to make is which side it belongs on.
         */
        $total = Money::zero($currency);

        foreach ($accounts as $row) {
            $total = $total->plus(Money::of((string) $row['closing'], $currency));
        }

        $comparativeTotal = Money::zero($currency);

        foreach ($previous as $row) {
            $comparativeTotal = $comparativeTotal->plus(Money::of((string) $row['closing'], $currency));
        }

        return [
            'section' => 'unclassified',
            'label_ar' => 'حسابات غير مصنَّفة',
            'label_en' => 'Unclassified accounts',
            'maturities' => $accounts === [] ? [] : [[
                'maturity' => Maturity::NotApplicable->value,
                'label_ar' => Maturity::NotApplicable->label('ar'),
                'label_en' => Maturity::NotApplicable->label('en'),
                'lines' => [[
                    'line' => 'unclassified',
                    'label_ar' => 'حسابات غير مصنَّفة',
                    'label_en' => 'Unclassified accounts',
                    'reference' => 'IAS 1.54',
                    'maturity' => Maturity::NotApplicable->value,
                    'accounts' => array_map(
                        static fn (array $row): array => [
                            'code' => $row['code'],
                            'name_ar' => $row['name_ar'],
                            'name_en' => $row['name_en'],
                            'amount' => (string) $row['closing'],
                        ],
                        $accounts,
                    ),
                    'amount' => (string) $total->amount,
                    'comparative' => $comparative === null ? null : (string) $comparativeTotal->amount,
                ]],
            ]],
            'total' => (string) $total->amount,
            'comparative_total' => $comparative === null ? null : (string) $comparativeTotal->amount,
        ];
    }

    /**
     * The period's result, which has not been closed into equity yet.
     *
     * ## Why the statement carries it rather than ignoring it
     *
     * Revenue and expense accounts close into retained earnings at year
     * end. Before that happens their balances are real and are nowhere on
     * the statement of financial position, so assets would exceed
     * liabilities plus equity by exactly the profit - and a statement that
     * reported that difference as an imbalance would be crying wolf on
     * every date except the day after a year-end close.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $comparative
     * @return array<string, mixed>
     */
    private function resultForTheYear(array $current, ?array $comparative, string $currency): array
    {
        return [
            'amount' => (string) $this->unclosedResult($current, $currency)->amount,
            'comparative' => $comparative === null
                ? null
                : (string) $this->unclosedResult($comparative, $currency)->amount,
        ];
    }

    /** @param array<string, mixed> $report */
    private function unclosedResult(array $report, string $currency): Money
    {
        $result = Money::zero($currency);

        foreach ($report['rows'] as $row) {
            $line = $row['statement_line'] === null
                ? null
                : StatementLine::tryFrom((string) $row['statement_line']);

            if ($line === null || $line->statement() !== FinancialStatement::ProfitOrLoss) {
                continue;
            }

            /*
             | Income is a credit and expense a debit, so the ledger's own
             | signed balance already IS the result: summing them without
             | flipping gives a negative for a profit, which is then
             | negated once, here, where the sentence can say so.
             */
            $result = $result->plus(Money::of((string) $row['closing'], $currency));
        }

        return $result->negated();
    }

    /**
     * Rows grouped by statement line, for the position lines only.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, list<array<string, mixed>>>
     */
    private function byLine(array $report): array
    {
        $grouped = [];

        foreach ($report['rows'] as $row) {
            $line = $row['statement_line'] === null
                ? null
                : StatementLine::tryFrom((string) $row['statement_line']);

            if ($line === null || $line->statement() !== FinancialStatement::FinancialPosition) {
                continue;
            }

            $grouped[$line->value][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function unclassifiedRows(array $report): array
    {
        return array_values(array_filter(
            $report['rows'],
            static fn (array $row): bool => $row['statement_line'] === null
                || StatementLine::tryFrom((string) $row['statement_line']) === null,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array<string, mixed>>
     */
    private function presented(array $accounts, StatementSection $section, string $currency): array
    {
        return array_map(
            fn (array $row): array => [
                'code' => (string) $row['code'],
                'name_ar' => (string) $row['name_ar'],
                'name_en' => (string) $row['name_en'],
                'amount' => (string) $this->present($row, $section, $currency)->amount,
            ],
            $accounts,
        );
    }

    /**
     * One account's balance, in the sign the statement presents it in.
     *
     * A liability's natural balance is a credit, which the ledger stores
     * negative; "Liabilities 62,790" is how a statement reads. The flip
     * lives on the section so that exactly one place decides it - a report
     * that forgot would print equity negative and still balance, which is
     * the failure nobody spots because the total is right.
     *
     * @param  array<string, mixed>  $row
     */
    private function present(array $row, StatementSection $section, string $currency): Money
    {
        $balance = Money::of((string) $row['closing'], $currency);

        return $section->presentationSign() === -1 ? $balance->negated() : $balance;
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     */
    private function sum(array $accounts, StatementSection $section, string $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($accounts as $row) {
            $total = $total->plus($this->present($row, $section, $currency));
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function total(array $lines, string $currency, string $key = 'amount'): Money
    {
        $total = Money::zero($currency);

        foreach ($lines as $line) {
            if ($line[$key] === null) {
                continue;
            }

            $total = $total->plus(Money::of((string) $line[$key], $currency));
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private function totals(array $sections, array $result, string $currency): array
    {
        $of = static function (array $sections, string $name) use ($currency): Money {
            foreach ($sections as $section) {
                if ($section['section'] === $name) {
                    return Money::of((string) $section['total'], $currency);
                }
            }

            return Money::zero($currency);
        };

        $assets = $of($sections, StatementSection::Assets->value);
        $liabilities = $of($sections, StatementSection::Liabilities->value);
        $equity = $of($sections, StatementSection::Equity->value)
            ->plus(Money::of((string) $result['amount'], $currency));

        return [
            'assets' => (string) $assets->amount,
            'liabilities' => (string) $liabilities->amount,
            'equity' => (string) $equity->amount,
            'liabilities_and_equity' => (string) $liabilities->plus($equity)->amount,
            'difference' => (string) $assets->minus($liabilities->plus($equity))->amount,
            'unclassified' => (string) $of($sections, 'unclassified')->amount,
        ];
    }

    /**
     * What the reader has to be told before anything else.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private function declare(array $sections, array $result, string $currency): array
    {
        $totals = $this->totals($sections, $result, $currency);
        $difference = Money::of($totals['difference'], $currency);
        $unclassified = Money::of($totals['unclassified'], $currency);

        if ($difference->isZero() && $unclassified->isZero()) {
            return [
                'severity' => 'ok',
                'en' => sprintf(
                    'Assets of %s equal liabilities and equity. Every account is classified.',
                    Money::of($totals['assets'], $currency)->toPresentation(),
                ),
                'ar' => 'الأصول تساوي الالتزامات وحقوق الملكية، وكل حساب مصنَّف.',
            ];
        }

        /*
         | The unclassified balance is named FIRST when there is one.
         |
         | It is the explanation for the difference in almost every case,
         | and it is the one a person can act on: somebody classifies the
         | account and the statement is right. Leading with "the ledger may
         | be broken" when the real answer is "three accounts need a line"
         | sends the reader to the wrong place.
         */
        if (! $unclassified->isZero()) {
            return [
                'severity' => 'warning',
                'en' => sprintf(
                    'This statement is incomplete. %s sits on accounts with no IAS 1 classification, so it '.
                    'is shown under "Unclassified accounts" and is in no section total. Classify those '.
                    'accounts in the chart; until then assets differ from liabilities and equity by %s.',
                    $unclassified->abs()->toPresentation(),
                    $difference->abs()->toPresentation(),
                ),
                'ar' => 'هذه القائمة غير مكتملة: توجد أرصدة على حسابات بلا تصنيف وفق المعيار الدولي 1، '.
                    'فظهرت تحت «حسابات غير مصنَّفة» ولم تدخل في أي مجموع. صنِّف تلك الحسابات في دليل الحسابات.',
            ];
        }

        return [
            'severity' => 'critical',
            'en' => sprintf(
                'Assets differ from liabilities and equity by %s, and every account is classified. The '.
                'difference is not a missing classification, so either an account is on the wrong side '.
                'of the statement or the ledger does not balance. Run `ledger:verify`.',
                $difference->abs()->toPresentation(),
            ),
            'ar' => 'الأصول لا تساوي الالتزامات وحقوق الملكية، وكل الحسابات مصنَّفة. الفرق ليس تصنيفًا '.
                'ناقصًا، فإمّا أن حسابًا وُضع في الجانب الخطأ أو أن الدفاتر نفسها غير متوازنة. '.
                'شغِّل ledger:verify.',
        ];
    }
}
