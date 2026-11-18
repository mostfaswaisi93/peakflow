<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ExpenseNature;
use App\Tenant\Ledger\Enums\FinancialStatement;
use App\Tenant\Ledger\Enums\ProfitOrLossBand;
use App\Tenant\Ledger\Enums\StatementLine;
use App\Tenant\Ledger\Enums\StatementSection;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use Illuminate\Support\Carbon;

/**
 * The statement of profit or loss and other comprehensive income - PF-185.
 *
 * ## Two analyses of the same expenses, and both are required
 *
 * IAS 1.99 lets an entity present expenses by nature or by function, and
 * 1.104 says one presenting by function must also disclose them by nature.
 * So `byFunction()` and `byNature()` are not two views of a preference:
 * an entity needs both, and a product offering only the first cannot
 * produce a compliant set of statements.
 *
 * Neither is derived from the other. They are two groupings of the same
 * rows, over two independent columns of the chart, and they agree only in
 * their total - which is asserted, because two analyses of one set of
 * expenses that reach different totals is the clearest possible evidence
 * that an account is classified wrong on one axis.
 *
 * ## The subtotals are boundaries, not formulas
 *
 * Gross margin, operating result and profit before tax are running totals
 * at the edges of the bands declared on `StatementLine`. Writing them as
 * `revenue - cost_of_sales` would be a second definition of the statement's
 * shape, and the first line added to the wrong side of it would produce a
 * subtotal that is wrong and a total that is right - the hardest arithmetic
 * error to see, because the bottom still agrees with the ledger.
 *
 * ## OCI is after profit, and split
 *
 * IAS 1.82A. An item of other comprehensive income folded into profit
 * overstates the result and the error never unwinds, because the
 * reclassification that would have corrected it is exactly what did not
 * happen. The split by whether an item may ever be reclassified is part of
 * the requirement, not a nicety: presenting them together tells the reader
 * every one of them is still to come.
 */
final class StatementOfProfitOrLoss
{
    public function __construct(private readonly TrialBalanceReport $trialBalance) {}

    /**
     * The statement for a period, with its comparative.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function forPeriod(
        string $entityId,
        string $functionalCurrency,
        Carbon $from,
        Carbon $until,
        ?Carbon $comparativeFrom = null,
        ?Carbon $comparativeUntil = null,
        bool $includeAdjusting = true,
    ): array {
        $current = $this->movements($entityId, $functionalCurrency, $from, $until, $includeAdjusting);

        $comparative = null;

        if ($comparativeUntil !== null) {
            if ($comparativeUntil->gte($from)) {
                throw ReportRefused::because(
                    'The comparative period overlaps the one being reported. Profit or loss is a '.
                    'MOVEMENT over a period, so an overlap counts the same revenue in both columns and '.
                    'the comparison reads as growth that did not happen.',
                );
            }

            $comparative = $this->movements(
                $entityId,
                $functionalCurrency,
                $comparativeFrom ?? $comparativeUntil->copy()->startOfYear(),
                $comparativeUntil,
                $includeAdjusting,
            );
        }

        $byFunction = $this->byFunction($current, $comparative, $functionalCurrency);
        $byNature = $this->byNature($current, $comparative, $functionalCurrency);

        return [
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'comparative_until' => $comparativeUntil?->toDateString(),
            'currency' => $functionalCurrency,
            'includes_adjusting' => $includeAdjusting,
            'by_function' => $byFunction,
            'by_nature' => $byNature,
            'declaration' => $this->declare($byFunction, $byNature, $functionalCurrency),
        ];
    }

    /**
     * The period's movement on every profit-and-loss account.
     *
     * ## Why the movement and not the closing balance
     *
     * A revenue account's closing balance is the year to date, not the
     * period. A statement built from closing balances would report January's
     * revenue again in February, and the comparative column would make it
     * look like nothing had grown. `TrialBalanceReport` already computes
     * closing minus opening, which is the one definition under which the
     * figure cannot disagree with the balance sheet.
     *
     * @return list<array<string, mixed>>
     */
    private function movements(
        string $entityId,
        string $currency,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting,
    ): array {
        $report = $this->trialBalance->forPeriod($entityId, $currency, $from, $until, $includeAdjusting);

        return array_values(array_filter(
            $report['rows'],
            static function (array $row): bool {
                $line = $row['statement_line'] === null
                    ? null
                    : StatementLine::tryFrom((string) $row['statement_line']);

                return $line !== null && $line->statement() === FinancialStatement::ProfitOrLoss;
            },
        ));
    }

    /**
     * The functional analysis: cost of sales, distribution, administration.
     *
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>|null  $comparative
     * @return array<string, mixed>
     */
    private function byFunction(array $current, ?array $comparative, string $currency): array
    {
        $rows = $this->groupBy($current, 'statement_line');
        $previous = $comparative === null ? [] : $this->groupBy($comparative, 'statement_line');

        $bands = [];
        $running = Money::zero($currency);
        $runningComparative = Money::zero($currency);
        $profit = null;
        $profitComparative = null;

        foreach (ProfitOrLossBand::cases() as $band) {
            $lines = [];

            foreach (StatementLine::cases() as $line) {
                if ($line->band() !== $band) {
                    continue;
                }

                $accounts = $rows[$line->value] ?? [];
                $comparativeAccounts = $previous[$line->value] ?? [];

                if ($accounts === [] && $comparativeAccounts === []) {
                    continue;
                }

                $amount = $this->sum($accounts, $line->section(), $currency);
                $was = $this->sum($comparativeAccounts, $line->section(), $currency);

                $running = $running->plus($amount);
                $runningComparative = $runningComparative->plus($was);

                $lines[] = [
                    'line' => $line->value,
                    'label_ar' => $line->label('ar'),
                    'label_en' => $line->label('en'),
                    'reference' => $line->reference(),
                    'may_be_reclassified' => $line->mayBeReclassified(),
                    'accounts' => $this->presented($accounts, $line->section(), $currency),
                    'amount' => (string) $amount->amount,
                    'comparative' => $comparative === null ? null : (string) $was->amount,
                ];
            }

            /*
             | Profit for the period is captured at the TAX boundary.
             |
             | Everything after it is other comprehensive income, which is
             | not profit - so the running total has to be read before OCI
             | is added to it, or "profit for the period" silently becomes
             | total comprehensive income and the statement of changes in
             | equity reconciles to the wrong figure.
             */
            if ($band === ProfitOrLossBand::Tax) {
                $profit = $running;
                $profitComparative = $runningComparative;
            }

            if ($lines === [] && $band !== ProfitOrLossBand::Tax) {
                continue;
            }

            $bands[] = [
                'band' => $band->value,
                'lines' => $lines,
                'subtotal' => $band->closingSubtotal(),
                'subtotal_label_ar' => $band->subtotalLabel('ar'),
                'subtotal_label_en' => $band->subtotalLabel('en'),
                'subtotal_reference' => $band->subtotalReference(),
                'amount' => (string) $running->amount,
                'comparative' => $comparative === null ? null : (string) $runningComparative->amount,
            ];
        }

        return [
            'bands' => $bands,
            'profit_for_the_period' => (string) ($profit ?? Money::zero($currency))->amount,
            'profit_for_the_period_comparative' => $comparative === null
                ? null
                : (string) ($profitComparative ?? Money::zero($currency))->amount,
            'total_comprehensive_income' => (string) $running->amount,
            'total_comprehensive_income_comparative' => $comparative === null
                ? null
                : (string) $runningComparative->amount,
        ];
    }

    /**
     * The analysis IAS 1.104 requires alongside the functional one.
     *
     * Revenue is presented as itself at the top - IAS 1.102 analyses
     * EXPENSES, and a by-nature statement that reclassified revenue would
     * be answering a question nobody asked.
     *
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>|null  $comparative
     * @return array<string, mixed>
     */
    private function byNature(array $current, ?array $comparative, string $currency): array
    {
        $income = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static function (array $row): bool {
                $line = StatementLine::tryFrom((string) $row['statement_line']);

                return $line !== null && $line->section() === StatementSection::Income;
            },
        ));

        $expenses = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static function (array $row): bool {
                $line = StatementLine::tryFrom((string) $row['statement_line']);

                return $line !== null && $line->section() === StatementSection::Expenses;
            },
        ));

        $lines = [[
            'nature' => 'revenue',
            'label_ar' => StatementLine::Revenue->label('ar'),
            'label_en' => StatementLine::Revenue->label('en'),
            'reference' => 'IAS 1.82(a)',
            'amount' => (string) $this->sum($income($current), StatementSection::Income, $currency)->amount,
            'comparative' => $comparative === null
                ? null
                : (string) $this->sum($income($comparative), StatementSection::Income, $currency)->amount,
        ]];

        $grouped = $this->groupBy($expenses($current), 'expense_nature');
        $previous = $comparative === null ? [] : $this->groupBy($expenses($comparative), 'expense_nature');

        foreach (ExpenseNature::cases() as $nature) {
            $accounts = $grouped[$nature->value] ?? [];
            $comparativeAccounts = $previous[$nature->value] ?? [];

            if ($accounts === [] && $comparativeAccounts === []) {
                continue;
            }

            $lines[] = [
                'nature' => $nature->value,
                'label_ar' => $nature->label('ar'),
                'label_en' => $nature->label('en'),
                'reference' => $nature->reference(),
                'amount' => (string) $this->sum($accounts, StatementSection::Expenses, $currency)->amount,
                'comparative' => $comparative === null
                    ? null
                    : (string) $this->sum($comparativeAccounts, StatementSection::Expenses, $currency)->amount,
            ];
        }

        /*
         | An expense nobody has given a nature.
         |
         | Shown, never dropped - the same rule as the unclassified section
         | of the statement of financial position, and for the same reason:
         | an expense that is in the functional analysis and missing from
         | this one makes the two disagree, and a reader comparing them
         | cannot tell which is short.
         */
        $unclassified = array_values(array_filter(
            $expenses($current),
            static fn (array $row): bool => $row['expense_nature'] === null
                || ExpenseNature::tryFrom((string) $row['expense_nature']) === null,
        ));

        if ($unclassified !== []) {
            $lines[] = [
                'nature' => 'unclassified',
                'label_ar' => 'مصروفات بلا تصنيف طبيعي',
                'label_en' => 'Expenses with no nature',
                'reference' => 'IAS 1.104',
                'amount' => (string) $this->sum($unclassified, StatementSection::Expenses, $currency)->amount,
                'comparative' => null,
            ];
        }

        /*
         | A plain sum, because every line is already a contribution.
         |
         | The first version flipped the expense lines here as well, which
         | negated them twice: the by-nature total came out as revenue PLUS
         | expenses and disagreed with the functional one by double the
         | cost base. The check that compares the two analyses is what
         | would have caught it, and did.
         */
        $total = Money::zero($currency);

        foreach ($lines as $line) {
            $total = $total->plus(Money::of((string) $line['amount'], $currency));
        }

        return [
            'lines' => $lines,
            'has_unclassified' => $unclassified !== [],
            'profit_for_the_period' => (string) $total->amount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupBy(array $rows, string $key): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            if ($row[$key] === null) {
                continue;
            }

            $grouped[(string) $row[$key]][] = $row;
        }

        return $grouped;
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
     * One account's movement, as its CONTRIBUTION TO PROFIT.
     *
     * ## Why every line is negated, income and expense alike
     *
     * Income is a credit, stored negative; negating it gives a positive
     * contribution. An expense is a debit, stored positive; negating it
     * gives a negative one. So a line's presented figure is what it adds to
     * the result, and every subtotal is a plain sum - which is the only
     * arrangement in which a band boundary cannot be computed wrongly.
     *
     * Expenses therefore print negative, in parentheses, which is how an
     * IFRS statement of profit or loss prints them anyway.
     *
     * The first version of this method read the section's presentation sign
     * and applied it, which is right for the statement of financial
     * position and wrong here: there, a liability is shown positive under a
     * heading that says "Liabilities", and the two sides are compared.
     * Here everything is added into one number, so a positive expense would
     * be added to profit.
     *
     * `$section` is taken and not used for that reason: removing the
     * parameter would make the two statements look like they answer the
     * same question in two ways, and the next person would unify them.
     *
     * @param  array<string, mixed>  $row
     */
    private function present(array $row, StatementSection $section, string $currency): Money
    {
        unset($section);

        return Money::of((string) $row['movement'], $currency)->negated();
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
     * @param  array<string, mixed>  $byFunction
     * @param  array<string, mixed>  $byNature
     * @return array<string, string>
     */
    private function declare(array $byFunction, array $byNature, string $currency): array
    {
        $functional = Money::of((string) $byFunction['profit_for_the_period'], $currency);
        $natural = Money::of((string) $byNature['profit_for_the_period'], $currency);

        if ((bool) $byNature['has_unclassified']) {
            return [
                'severity' => 'warning',
                'en' => 'Some expenses have no nature, so the by-nature analysis IAS 1.104 requires is '.
                    'incomplete. They are shown under "Expenses with no nature"; classify them in the '.
                    'chart.',
                'ar' => 'بعض المصروفات بلا تصنيف طبيعي، فالتحليل بحسب الطبيعة الذي يطلبه المعيار 1.104 '.
                    'غير مكتمل. ظهرت تحت «مصروفات بلا تصنيف طبيعي»؛ صنِّفها في دليل الحسابات.',
            ];
        }

        /*
         | The two analyses must reach the same profit.
         |
         | They are two groupings of one set of rows, so a difference is not
         | a rounding artefact - it is an account classified onto one axis
         | and not the other, and it is the clearest evidence available that
         | the chart is wrong.
         */
        if (! $functional->equals($natural)) {
            return [
                'severity' => 'critical',
                'en' => sprintf(
                    'The two analyses disagree: by function the result is %s and by nature it is %s. '.
                    'They are two groupings of the same rows, so an account is classified on one axis '.
                    'and not the other.',
                    $functional->toPresentation(),
                    $natural->toPresentation(),
                ),
                'ar' => 'التحليلان لا يتفقان على النتيجة. وهما تجميعان لنفس الصفوف، فحسابٌ مصنَّف على '.
                    'محور دون الآخر.',
            ];
        }

        return [
            'severity' => 'ok',
            'en' => sprintf(
                'The result for the period is %s, and the analyses by function and by nature agree.',
                $functional->toPresentation(),
            ),
            'ar' => 'التحليل بحسب الوظيفة والتحليل بحسب الطبيعة يتفقان على نتيجة الفترة.',
        ];
    }
}
