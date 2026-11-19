<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Models\FiscalYear;
use App\Tenant\Ledger\Services\Budgets;
use App\Tenant\Purchasing\Services\PurchaseOrders;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Registers\ReportParameters;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Budget against actual, with what is already committed - PF-193.
 *
 * ## What is reused rather than recomputed
 *
 * **The actual is {@see GeneralLedgerDetail::activity()}'s movement.** It
 * is the one definition of what moved in a window: posted and reversed
 * only, the adjusting period in or out by parameter, dimension filters
 * resolved and refused when they name nothing, and its opening plus
 * movement equals its closing by a check that compares it to the trial
 * balance. A second aggregate here would be a second answer to "what did
 * this account spend", and the two would be compared to each other by the
 * first person who noticed.
 *
 * **The budget is {@see Budgets}' rows.** Reporting may not write, so the
 * version, the lock and the supersession all live in `Ledger`; this reads
 * the lines of whichever version it is told to, and by default of the one
 * that is approved.
 *
 * **The commitment is {@see PurchaseOrders::commitment()}'s.** Which
 * orders are open and how much is still committed on each line is
 * PF-131's answer and there is one of it. What this does is group those
 * rows by the account they will post to, which is the question a budget
 * asks of them.
 *
 * ## A committed budget is not an available one
 *
 * The criterion's own words, and the reason the third column exists. A
 * department that has spent 80% of its budget and committed the other 30%
 * is over before anybody has done anything wrong, and a report showing
 * only the actual says it has 20% left. So `available` is the budget less
 * the actual AND less the commitment, and it is stated as its own figure
 * rather than left for a reader to subtract.
 *
 * ## The sign cannot say whether a variance is good
 *
 * `actual - budget` is signed the way the ledger signs it, so an expense
 * that overran is positive and revenue that overshot is negative. Those
 * are the opposite judgements from the same sign, and a report that
 * coloured one of them red would be wrong about half its rows. The
 * direction therefore comes from the account's TYPE and is stated as a
 * word - `over_budget` or `under_budget` - beside a `favourable` flag
 * that says which of the two is the good news for that kind of account.
 *
 * ## The percentage is arithmetic on money, not a float
 *
 * `BigDecimal` all the way, to two decimals, half up. And where the
 * budget is zero there is no percentage at all: a division by zero
 * reported as 0% reads as a line that is exactly on budget, which is the
 * opposite of what unbudgeted spending is. It comes back null with the
 * reason beside it, and the line is material whatever the threshold says.
 */
final class BudgetVariance
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Budgets $budgets,
        private readonly GeneralLedgerDetail $activity,
        private readonly PurchaseOrders $orders,
        private readonly StatementOfProfitOrLoss $profitOrLoss,
    ) {}

    /**
     * Budget, actual, commitment and variance, by account.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function versus(
        string $entityId,
        string $functionalCurrency,
        string $fiscalYearId,
        ?string $versionId = null,
        ?Carbon $from = null,
        ?Carbon $until = null,
        ?string $materialityPercent = null,
        bool $includeAdjusting = true,
        ?Carbon $ranAt = null,
    ): array {
        $year = FiscalYear::query()
            ->where('entity_id', $entityId)
            ->whereKey($fiscalYearId)
            ->first();

        if ($year === null) {
            throw ReportRefused::because(
                'That fiscal year does not belong to this entity. A budget is set against the '.
                'calendar the accounts are kept on, so there is nothing to compare.',
            );
        }

        $version = $this->versionFor($entityId, $fiscalYearId, $versionId);
        $threshold = $this->materiality($entityId, $materialityPercent);

        /*
         | The whole fiscal year unless a narrower window is asked for.
         |
         | And the window bounds BOTH sides: the budget's periods and the
         | actual's lines. A report that bounded the actual and not the
         | budget would compare one month of spending to a year of budget
         | and call it a 92% underspend.
         */
        $from ??= Carbon::parse($year->starts_on->toDateString());
        $until ??= Carbon::parse($year->ends_on->toDateString());

        if ($until->lt($from)) {
            throw ReportRefused::because(
                'The window ends before it starts. Reversed, it holds no period at all and the '.
                'report would read as a year in which nothing was budgeted and nothing was spent.',
            );
        }

        $budget = $this->budgetedBy($version, $from, $until, $functionalCurrency, $includeAdjusting);
        $actual = $this->actualBy($entityId, $functionalCurrency, $from, $until, $includeAdjusting);
        $committed = $this->committedBy($entityId, $functionalCurrency);

        $parameters = ReportParameters::for(
            report: 'budget_versus_actual',
            entityId: $entityId,
            from: $from,
            until: $until,
            currency: $functionalCurrency,
            includesAdjusting: $includeAdjusting,
            filters: [
                'budget version' => sprintf(
                    '%s v%d (%s)',
                    (string) $version->name,
                    (int) $version->version_no,
                    (string) $version->status,
                ),
                'fiscal year' => (string) $year->label,
                'materiality' => $threshold.'%',
            ],
            runAt: $ranAt,
        );

        $codes = array_values(array_unique([
            ...array_keys($budget['by_account']),
            ...array_keys($actual),
            ...array_keys($committed['by_account']),
        ]));
        sort($codes);

        $rows = [];
        $totals = [
            'budget' => Money::zero($functionalCurrency),
            'actual' => Money::zero($functionalCurrency),
            'committed' => Money::zero($functionalCurrency),
        ];
        $material = 0;
        $unbudgeted = 0;

        foreach ($codes as $code) {
            $budgeted = $budget['by_account'][$code] ?? Money::zero($functionalCurrency);
            $spent = $actual[$code]['movement'] ?? Money::zero($functionalCurrency);
            $commitment = $committed['by_account'][$code] ?? Money::zero($functionalCurrency);

            if ($budgeted->isZero() && $spent->isZero() && $commitment->isZero()) {
                continue;
            }

            $variance = $spent->minus($budgeted);
            $percent = $this->percentage($variance, $budgeted);
            $type = (string) ($actual[$code]['type'] ?? $budget['types'][$code] ?? 'expense');

            /*
             | A row with no budget line is reported, not skipped.
             |
             | A variance report listing only budgeted accounts hides
             | every riyal spent outside the budget - which is the
             | spending nobody authorised, and the only report it would
             | ever have shown up on.
             */
            $isUnbudgeted = $budgeted->isZero() && ! $spent->isZero();

            if ($isUnbudgeted) {
                $unbudgeted++;
            }

            $isMaterial = $isUnbudgeted || ($percent !== null
                && BigDecimal::of($percent)->abs()->isGreaterThanOrEqualTo(BigDecimal::of($threshold)));

            if ($isMaterial) {
                $material++;
            }

            $rows[] = [
                'account' => $code,
                'name_ar' => (string) ($actual[$code]['name_ar'] ?? $budget['names_ar'][$code] ?? ''),
                'name_en' => (string) ($actual[$code]['name_en'] ?? $budget['names_en'][$code] ?? ''),
                'budget' => (string) $budgeted->amount,
                'actual' => (string) $spent->amount,
                'committed' => (string) $commitment->amount,
                /*
                 | Budget less actual less commitment.
                 |
                 | The criterion's own sentence: a committed budget is not
                 | an available one. Stated as a figure because a reader
                 | who has to subtract two columns will read the first one
                 | and act on it.
                 */
                'available' => (string) $budgeted->minus($spent)->minus($commitment)->amount,
                'variance' => (string) $variance->amount,
                'variance_percent' => $percent,
                'direction' => $variance->isZero()
                    ? 'on_budget'
                    : ($variance->isPositive() ? 'over_budget' : 'under_budget'),
                'favourable' => $this->favourable($type, $variance),
                'material' => $isMaterial,
                'unbudgeted' => $isUnbudgeted,
                'note' => $percent === null && ! $budgeted->isZero()
                    ? ''
                    : ($isUnbudgeted
                        ? 'Spent against an account this version budgeted nothing for, so there is no '.
                            'percentage to quote and the line is material whatever the threshold is.'
                        : ''),
            ];

            $totals['budget'] = $totals['budget']->plus($budgeted);
            $totals['actual'] = $totals['actual']->plus($spent);
            $totals['committed'] = $totals['committed']->plus($commitment);
        }

        return [
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'columns' => $this->columns(),
            'rows' => $rows,
            'currency' => $functionalCurrency,
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'version' => [
                'id' => (string) $version->id,
                'name' => (string) $version->name,
                'version_no' => (int) $version->version_no,
                'status' => (string) $version->status,
                'locked_at' => $version->locked_at === null ? null : (string) $version->locked_at,
                'approved_by' => $version->approved_by === null ? null : (string) $version->approved_by,
                'superseded_by_id' => $version->superseded_by_id === null
                    ? null
                    : (string) $version->superseded_by_id,
                'why' => (string) $version->status === 'approved'
                    ? 'The approved version, locked when it was approved: its lines cannot have '.
                        'changed since, so this comparison is reproducible.'
                    : sprintf(
                        'A %s version. Its lines may still change, so two runs of this report can '.
                        'disagree and neither is wrong - only the approved version is locked.',
                        (string) $version->status,
                    ),
            ],
            'materiality' => [
                'percent' => $threshold,
                'material_rows' => $material,
                'total_rows' => \count($rows),
                'why' => sprintf(
                    'A line is highlighted when it is %s%% or more away from its budget. Without a '.
                    'threshold every line is highlighted, which is the same as none being - and the '.
                    'threshold is on the output because two reports with the same highlighted rows '.
                    'and different thresholds are two different questions.',
                    $threshold,
                ),
            ],
            'totals' => [
                'budget' => (string) $totals['budget']->amount,
                'actual' => (string) $totals['actual']->amount,
                'committed' => (string) $totals['committed']->amount,
                'variance' => (string) $totals['actual']->minus($totals['budget'])->amount,
                'available' => (string) $totals['budget']
                    ->minus($totals['actual'])
                    ->minus($totals['committed'])->amount,
            ],
            'periods' => $budget['periods'],
            'commitments' => $committed['stated'],
            'unbudgeted_accounts' => $unbudgeted,
            'declaration' => $this->declare(
                $rows,
                $threshold,
                $material,
                $unbudgeted,
                $version,
                $committed['stated'],
                $functionalCurrency,
            ),
        ];
    }

    /**
     * Profit or loss for every dimension combination in the window - PF-193.
     *
     * ## Why one statement per combination rather than one grouped query
     *
     * Because the alternative is a second definition of a movement. Each
     * column here is produced by {@see GeneralLedgerDetail::activity()}
     * with that combination as its filter, which is the same call the
     * general ledger detail makes - so a figure on this pivot can be
     * drilled into and will come to itself, and the refusal for a
     * dimension code that does not exist is the same refusal.
     *
     * The cost is one query per combination, and it is a real cost on a
     * chart with four axes. The right fix is PF-182's read model, whose
     * grain is already entity, period, account and dimension set - not a
     * hand-written aggregate here, which is how a report becomes a second
     * source of truth.
     *
     * ## And the combinations are proved to be the whole of it
     *
     * The unfiltered statement's result is on the output beside the sum
     * of the combinations', with the difference stated. A pivot that
     * missed a combination - a line posted with no cost centre at all is
     * the usual one - would otherwise look complete and be short, and
     * every column on it would be correct.
     *
     * @param  list<string>  $axisKeys  the axes to combine, by their own keys
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function byDimension(
        string $entityId,
        string $functionalCurrency,
        Carbon $from,
        Carbon $until,
        array $axisKeys,
        bool $includeAdjusting = true,
        ?Carbon $ranAt = null,
    ): array {
        if ($axisKeys === []) {
            throw ReportRefused::because(
                'No axis was named, so there is nothing to combine. A profit or loss with no '.
                'dimension on it is the statement itself, which is its own report.',
            );
        }

        $axes = $this->axes($entityId, $axisKeys);
        $combinations = $this->combinations($entityId, $axes, $from, $until, $includeAdjusting);

        $parameters = ReportParameters::for(
            report: 'profit_or_loss_by_dimension',
            entityId: $entityId,
            from: $from,
            until: $until,
            currency: $functionalCurrency,
            includesAdjusting: $includeAdjusting,
            filters: ['axes' => implode(' x ', array_keys($axes))],
            runAt: $ranAt,
        );

        $rows = [];
        $combined = Money::zero($functionalCurrency);

        foreach ($combinations as $combination) {
            $filters = [];
            $labels = [];

            /*
             | A combination with no value on an axis is reported as
             | "unclassified", and it is FILTERED for rather than dropped.
             |
             | A line posted without a cost centre is money that was
             | spent. Dropping it makes the pivot plausible and short by
             | exactly the spending nobody classified; passing no filter
             | at all - which is what the first version did - makes the
             | unclassified column hold every line, and the pivot then
             | adds to twice the result. The register's own filter takes a
             | null for this, so the column is a real query and the
             | reconciliation below can still fail.
             */
            foreach ($combination['values'] as $axisKey => $value) {
                $filters[(string) $axisKey] = $value === null ? null : (string) $value['code'];

                if ($value !== null) {
                    $labels[] = sprintf('%s: %s', (string) $axisKey, (string) $value['code']);
                }
            }

            $result = $this->resultOf(
                $entityId,
                $functionalCurrency,
                $from,
                $until,
                $includeAdjusting,
                $filters,
            );

            $combined = $combined->plus($result['result']);

            $rows[] = [
                'combination' => $labels === [] ? 'unclassified' : implode(' / ', $labels),
                'values' => $filters,
                'revenue' => (string) $result['revenue']->amount,
                'expenses' => (string) $result['expenses']->amount,
                'result' => (string) $result['result']->amount,
                'accounts' => $result['accounts'],
            ];
        }

        /*
         | The statement, unfiltered, from the one P&L service.
         |
         | Not a sum of the rows above: the point of printing it is that
         | it was produced by a different route, so the two agreeing says
         | the pivot covers every line.
         */
        $whole = $this->profitOrLoss->forPeriod(
            $entityId,
            $functionalCurrency,
            $from,
            $until,
            includeAdjusting: $includeAdjusting,
        );

        /*
         | `profit_for_the_period` from the by-nature analysis.
         |
         | Either analysis would do - the two agree in their total, and a
         | check asserts it - and the by-nature one is used because it
         | covers every expense account including the ones with no
         | functional classification, which is the population this pivot
         | reads.
         */
        $statementResult = Money::of(
            (string) ($whole['by_nature']['profit_for_the_period'] ?? '0'),
            $functionalCurrency,
        );

        $difference = $combined->minus($statementResult);

        return [
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'columns' => $this->dimensionColumns(),
            'rows' => $rows,
            'currency' => $functionalCurrency,
            'axes' => array_keys($axes),
            'combinations' => \count($rows),
            'reconciliation' => [
                'combined' => (string) $combined->amount,
                'statement' => (string) $statementResult->amount,
                'difference' => (string) $difference->amount,
                'agrees' => $difference->isZero(),
                'why' => $difference->isZero()
                    ? 'The combinations add to the statement of profit or loss for the same window, so '.
                        'every line in it is on one of the rows above.'
                    : sprintf(
                        'The combinations add to %s and the statement says %s, a difference of %s. '.
                        'The pivot is not the whole of the result, so a department reading its own '.
                        'row cannot tell whether what is missing is theirs.',
                        (string) $combined->amount,
                        (string) $statementResult->amount,
                        (string) $difference->amount,
                    ),
            ],
            'declaration' => [
                'severity' => $difference->isZero() ? 'ok' : 'broken',
                'en' => $difference->isZero()
                    ? sprintf(
                        'Profit or loss for %d combination%s of %s, each one produced by the same '.
                        'query the general ledger detail uses, and together adding to the statement '.
                        'for this window exactly.',
                        \count($rows),
                        \count($rows) === 1 ? '' : 's',
                        implode(' x ', array_keys($axes)),
                    )
                    : sprintf(
                        'THIS PIVOT IS NOT THE WHOLE RESULT: the combinations add to %s and the '.
                        'statement of profit or loss says %s. Every column on it may be correct and '.
                        'the page is still short by %s.',
                        (string) $combined->amount,
                        (string) $statementResult->amount,
                        (string) $difference->amount,
                    ),
                'ar' => $difference->isZero()
                    ? sprintf('الأرباح والخسائر لكل تركيبة من %s، ومجموعها يطابق القائمة تمامًا.', implode(' × ', array_keys($axes)))
                    : sprintf('هذا التحليل لا يغطي كامل النتيجة: الفرق %s عن قائمة الأرباح والخسائر.', (string) $difference->amount),
            ],
        ];
    }

    /**
     * The version to report against: the one asked for, or the approved one.
     *
     * @throws ReportRefused
     */
    private function versionFor(string $entityId, string $fiscalYearId, ?string $versionId): object
    {
        if ($versionId !== null) {
            $version = DB::connection('tenant')->table('budget_versions')
                ->where('entity_id', $entityId)
                ->where('id', $versionId)
                ->first();

            if ($version === null) {
                throw ReportRefused::because(
                    'There is no budget version with that id in this entity. A variance report '.
                    'against a version that does not exist would show every actual as unbudgeted.',
                );
            }

            return $version;
        }

        $approved = $this->budgets->approved($entityId, $fiscalYearId);

        if ($approved === null) {
            /*
             | Refused rather than falling back to the newest draft.
             |
             | A draft is somebody's unfinished work, and a variance
             | report against one is a comparison whose figures change
             | without anybody posting anything. If a draft is really
             | what is wanted, it is named.
             */
            throw ReportRefused::because(
                'No budget version is approved for this fiscal year, so there is nothing a variance '.
                'can be measured against. A report against the newest draft would compare the '.
                'accounts to somebody\'s unfinished work and would change without anything being '.
                'posted - name the draft explicitly if that is what is wanted.',
            );
        }

        return $approved;
    }

    /**
     * The budgeted amount per account, for the periods in the window.
     *
     * @return array{
     *     by_account: array<string, Money>,
     *     names_ar: array<string, string>,
     *     names_en: array<string, string>,
     *     types: array<string, string>,
     *     periods: list<string>,
     * }
     */
    private function budgetedBy(
        object $version,
        Carbon $from,
        Carbon $until,
        string $currency,
        bool $includeAdjusting,
    ): array {
        /*
         | A period is in the window when it is wholly inside it.
         |
         | The budget's grain IS the period, so half a period has no row
         | to read - and apportioning one by days would be a figure
         | nobody set. The periods that were counted are printed, which
         | is what lets a reader see that a mid-month window reported
         | nothing rather than nothing having been budgeted.
         */
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT a.code,
                   a.name_ar,
                   a.name_en,
                   a.type,
                   sum(l.amount)::text AS budgeted
            FROM budget_lines l
            JOIN accounts a ON a.id = l.account_id
            JOIN periods p ON p.id = l.period_id
            WHERE l.budget_version_id = cast(? AS char(26))
              AND p.starts_on >= cast(? AS date)
              AND p.ends_on <= cast(? AS date)
              AND (cast(? AS boolean) OR p.is_adjusting = false)
            GROUP BY a.code, a.name_ar, a.name_en, a.type
            ORDER BY a.code
        SQL, [
            (string) $version->id,
            $from->toDateString(),
            $until->toDateString(),
            $includeAdjusting,
        ]);

        $periods = DB::connection('tenant')->table('periods')
            ->where('entity_id', (string) $version->entity_id)
            ->where('fiscal_year_id', (string) $version->fiscal_year_id)
            ->where('starts_on', '>=', $from->toDateString())
            ->where('ends_on', '<=', $until->toDateString())
            ->when(! $includeAdjusting, static fn ($query) => $query->where('is_adjusting', false))
            ->orderBy('ordinal')
            ->pluck('label');

        $byAccount = [];
        $namesAr = [];
        $namesEn = [];
        $types = [];

        foreach ($rows as $row) {
            $code = (string) $row->code;
            $byAccount[$code] = Money::of((string) $row->budgeted, $currency);
            $namesAr[$code] = (string) $row->name_ar;
            $namesEn[$code] = (string) $row->name_en;
            $types[$code] = (string) $row->type;
        }

        return [
            'by_account' => $byAccount,
            'names_ar' => $namesAr,
            'names_en' => $namesEn,
            'types' => $types,
            'periods' => array_map(static fn (mixed $label): string => (string) $label, $periods->all()),
        ];
    }

    /**
     * What each account actually moved, from the one definition of it.
     *
     * @return array<string, array{movement: Money, name_ar: string, name_en: string, type: string}>
     *
     * @throws ReportRefused
     */
    private function actualBy(
        string $entityId,
        string $currency,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting,
    ): array {
        $report = $this->activity->activity(
            entityId: $entityId,
            functionalCurrency: $currency,
            from: $from,
            until: $until,
            includeAdjusting: $includeAdjusting,
        );

        $types = DB::connection('tenant')->table('accounts')
            ->where('entity_id', $entityId)
            ->pluck('type', 'code');

        $actual = [];

        /** @var list<array<string, mixed>> $blocks */
        $blocks = $report['accounts'];

        foreach ($blocks as $block) {
            $code = (string) $block['code'];

            $actual[$code] = [
                'movement' => Money::of((string) $block['movement'], $currency),
                'name_ar' => (string) $block['name_ar'],
                'name_en' => (string) $block['name_en'],
                'type' => (string) ($types[$code] ?? 'expense'),
            ];
        }

        return $actual;
    }

    /**
     * Open commitments per account, in the functional currency only.
     *
     * @return array{by_account: array<string, Money>, stated: array<string, mixed>}
     */
    private function committedBy(string $entityId, string $currency): array
    {
        $report = $this->orders->commitment($entityId);

        $byAccount = [];
        $total = Money::zero($currency);
        $otherCurrency = 0;
        $unclassified = Money::zero($currency);
        $lines = 0;

        /** @var list<array<string, mixed>> $rows */
        $rows = $report['lines'];

        foreach ($rows as $row) {
            if ((string) $row['currency'] !== $currency) {
                /*
                 | Counted out and named, never converted.
                 |
                 | An order in USD against a budget in SAR needs a rate,
                 | and a rate on a commitment is a figure that changes
                 | every morning - so the report says how many it left
                 | out rather than quietly translating them.
                 */
                $otherCurrency++;

                continue;
            }

            $amount = Money::of((string) $row['remaining'], $currency);
            $code = $row['account_code'] === null ? null : (string) $row['account_code'];
            $lines++;
            $total = $total->plus($amount);

            if ($code === null || $code === '') {
                /*
                 | An order line with no expense account is stated, not
                 | spread.
                 |
                 | It is a real commitment and it cannot be shown against
                 | any budget line, so it belongs in the total and in its
                 | own figure - apportioning it across accounts would
                 | invent a commitment on each one.
                 */
                $unclassified = $unclassified->plus($amount);

                continue;
            }

            $byAccount[$code] = ($byAccount[$code] ?? Money::zero($currency))->plus($amount);
        }

        $stated = [
            'currency' => $currency,
            'total' => (string) $total->amount,
            'lines' => $lines,
            'unclassified' => (string) $unclassified->amount,
            'why' => $lines === 0
                ? 'No open purchase order commits anything in this currency.'
                : sprintf(
                    '%s is committed on %d open order line%s and has not been billed yet. A '.
                    'committed budget is not an available one: a department that has spent 80%% and '.
                    'committed the other 30%% is over before anybody has done anything wrong.',
                    (string) $total->amount,
                    $lines,
                    $lines === 1 ? '' : 's',
                ),
        ];

        if ($otherCurrency > 0) {
            $stated['excluded_other_currency'] = $otherCurrency;
        }

        return ['by_account' => $byAccount, 'stated' => $stated];
    }

    /**
     * The variance as a percentage of the budget, or null.
     *
     * `BigDecimal` throughout and `RoundingMode::HalfUp` at two decimals.
     * A percentage of money is still arithmetic on money: through a float
     * a 1,000 variance on a 3,000 budget prints as 33.33333333333329, and
     * the difference between that and 33.33 is an argument nobody can win
     * in front of an auditor.
     */
    private function percentage(Money $variance, Money $budget): ?string
    {
        if ($budget->isZero()) {
            return null;
        }

        return (string) BigDecimal::of((string) $variance->amount)
            ->dividedBy(BigDecimal::of((string) $budget->amount)->abs(), 8, RoundingMode::HalfUp)
            ->multipliedBy('100')
            ->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Whether a variance is the good news, which the sign alone cannot say.
     *
     * An expense that came in under budget and revenue that came in over
     * it are both favourable, and in the ledger's signing they have
     * opposite signs. Null where the account is neither - a balance-sheet
     * account somebody budgeted - because calling a movement on it good
     * or bad would be a judgement this report has no basis for.
     */
    private function favourable(string $type, Money $variance): ?bool
    {
        if ($variance->isZero()) {
            return true;
        }

        return match ($type) {
            'expense' => $variance->isNegative(),
            'revenue' => $variance->isNegative(),
            default => null,
        };
    }

    /**
     * The threshold, from the argument or the entity's setting.
     *
     * @throws ReportRefused
     */
    private function materiality(string $entityId, ?string $percent): string
    {
        $percent ??= $this->settings->decimal('budget.materiality_percent', $entityId);

        if (! is_numeric($percent)) {
            throw ReportRefused::because(sprintf(
                '"%s" is not a materiality threshold. A report that could not read its threshold '.
                'would highlight every line or none, and both read as a statement about the '.
                'figures.',
                $percent,
            ));
        }

        $value = BigDecimal::of($percent);

        if ($value->isNegative()) {
            throw ReportRefused::because(
                'A negative materiality threshold highlights every line, which is the same as '.
                'highlighting none - and the page then says nothing about which variances matter.',
            );
        }

        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * The axes asked for, resolved to their numbers.
     *
     * @param  list<string>  $axisKeys
     * @return array<string, int>
     *
     * @throws ReportRefused
     */
    private function axes(string $entityId, array $axisKeys): array
    {
        $axes = [];

        foreach ($axisKeys as $key) {
            $axis = DB::connection('tenant')->table('dimension_axes')
                ->where('entity_id', $entityId)
                ->where('key', $key)
                ->first(['axis', 'name_en']);

            if ($axis === null) {
                throw ReportRefused::because(sprintf(
                    'This entity has no "%s" axis. A pivot on an axis that does not exist comes back '.
                    'with one unclassified column, which reads as a business that classifies nothing.',
                    $key,
                ));
            }

            $axes[$key] = (int) $axis->axis;
        }

        return $axes;
    }

    /**
     * The combinations that actually occur in the window.
     *
     * Enumerated from the lines rather than from the cross product of
     * every value on every axis: four axes of twenty values is 160,000
     * combinations, of which a business uses perhaps forty. This query
     * finds which, and nothing else - every FIGURE on the report comes
     * from the one definition of a movement.
     *
     * @param  array<string, int>  $axes
     * @return list<array{values: array<string, ?array{code: string, name_en: string}>}>
     */
    private function combinations(
        string $entityId,
        array $axes,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting,
    ): array {
        $selects = [];
        $groups = [];
        $joins = [];

        foreach ($axes as $key => $axis) {
            $alias = 'v'.$axis;
            $selects[] = sprintf('%s.code AS code_%d, %s.name_en AS name_%d', $alias, $axis, $alias, $axis);
            $groups[] = sprintf('%s.code, %s.name_en', $alias, $alias);
            $joins[] = sprintf(
                'LEFT JOIN dimension_values %s ON %s.id = l.dimension_%d',
                $alias,
                $alias,
                $axis,
            );
        }

        $adjusting = $includeAdjusting ? '' : ' AND p.is_adjusting = false';

        $sql = sprintf(
            'SELECT %s
             FROM journal_lines l
             JOIN journal_entries e ON e.id = l.journal_entry_id
             JOIN periods p ON p.id = l.period_id
             JOIN accounts a ON a.id = l.account_id
             %s
             WHERE l.entity_id = cast(? AS char(26))
               AND e.status IN (\'posted\', \'reversed\')
               AND l.accounting_date BETWEEN cast(? AS date) AND cast(? AS date)
               AND a.type IN (\'revenue\', \'expense\')%s
             GROUP BY %s
             ORDER BY %s',
            implode(', ', $selects),
            implode("\n             ", $joins),
            $adjusting,
            implode(', ', $groups),
            implode(', ', $groups),
        );

        $rows = DB::connection('tenant')->select($sql, [
            $entityId,
            $from->toDateString(),
            $until->toDateString(),
        ]);

        $combinations = [];

        foreach ($rows as $row) {
            $values = [];

            foreach ($axes as $key => $axis) {
                $code = $row->{'code_'.$axis} ?? null;

                $values[$key] = $code === null
                    ? null
                    : ['code' => (string) $code, 'name_en' => (string) ($row->{'name_'.$axis} ?? '')];
            }

            $combinations[] = ['values' => $values];
        }

        return $combinations;
    }

    /**
     * The profit or loss of one combination, from the one definition.
     *
     * @param  array<string, ?string>|null  $filters  axis key => value code, or null for the lines with no value on that axis
     * @return array{revenue: Money, expenses: Money, result: Money, accounts: list<array<string, mixed>>}
     *
     * @throws ReportRefused
     */
    private function resultOf(
        string $entityId,
        string $currency,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting,
        ?array $filters,
    ): array {
        $report = $this->activity->activity(
            entityId: $entityId,
            functionalCurrency: $currency,
            from: $from,
            until: $until,
            dimensions: $filters,
            includeAdjusting: $includeAdjusting,
        );

        $types = DB::connection('tenant')->table('accounts')
            ->where('entity_id', $entityId)
            ->pluck('type', 'code');

        $revenue = Money::zero($currency);
        $expenses = Money::zero($currency);
        $accounts = [];

        /** @var list<array<string, mixed>> $blocks */
        $blocks = $report['accounts'];

        foreach ($blocks as $block) {
            $code = (string) $block['code'];
            $type = (string) ($types[$code] ?? '');

            if ($type !== 'revenue' && $type !== 'expense') {
                continue;
            }

            $movement = Money::of((string) $block['movement'], $currency);

            if ($movement->isZero()) {
                continue;
            }

            $type === 'revenue'
                ? $revenue = $revenue->plus($movement)
                : $expenses = $expenses->plus($movement);

            $accounts[] = [
                'account' => $code,
                'name_en' => (string) $block['name_en'],
                'type' => $type,
                'movement' => (string) $movement->amount,
            ];
        }

        /*
         | The result as a CONTRIBUTION, which is how PF-185 presents it.
         |
         | Revenue is a credit and carries a negative ledger sign, an
         | expense is a debit and carries a positive one, so the result is
         | their sum negated: a profit comes out positive. Applying a
         | presentation sign per section instead - right for the statement
         | of financial position - would make each subtotal a different
         | arithmetic from the next.
         */
        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'result' => $revenue->plus($expenses)->negated(),
            'accounts' => $accounts,
        ];
    }

    /**
     * @return list<array{key: string, label_ar: string, label_en: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'account', 'label_ar' => 'الحساب', 'label_en' => 'Account'],
            ['key' => 'name_en', 'label_ar' => 'الاسم', 'label_en' => 'Name'],
            ['key' => 'budget', 'label_ar' => 'الموازنة', 'label_en' => 'Budget'],
            ['key' => 'actual', 'label_ar' => 'الفعلي', 'label_en' => 'Actual'],
            ['key' => 'committed', 'label_ar' => 'التزامات', 'label_en' => 'Committed'],
            ['key' => 'available', 'label_ar' => 'المتاح', 'label_en' => 'Available'],
            ['key' => 'variance', 'label_ar' => 'الانحراف', 'label_en' => 'Variance'],
            ['key' => 'variance_percent', 'label_ar' => 'الانحراف %', 'label_en' => 'Variance %'],
            ['key' => 'direction', 'label_ar' => 'الاتجاه', 'label_en' => 'Direction'],
            ['key' => 'material', 'label_ar' => 'جوهري', 'label_en' => 'Material'],
        ];
    }

    /** @return list<array{key: string, label_ar: string, label_en: string}> */
    private function dimensionColumns(): array
    {
        return [
            ['key' => 'combination', 'label_ar' => 'التركيبة', 'label_en' => 'Combination'],
            ['key' => 'revenue', 'label_ar' => 'الإيرادات', 'label_en' => 'Revenue'],
            ['key' => 'expenses', 'label_ar' => 'المصروفات', 'label_en' => 'Expenses'],
            ['key' => 'result', 'label_ar' => 'النتيجة', 'label_en' => 'Result'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $commitments
     * @return array<string, string>
     */
    private function declare(
        array $rows,
        string $threshold,
        int $material,
        int $unbudgeted,
        object $version,
        array $commitments,
        string $currency,
    ): array {
        if ($rows === []) {
            return [
                'severity' => 'empty',
                'en' => sprintf(
                    'Version "%s" budgets nothing in this window and nothing was spent against it. '.
                    'A window narrower than a period has no budget row to read at all, because the '.
                    'budget\'s grain is the period and apportioning one would be a figure nobody set.',
                    (string) $version->name,
                ),
                'ar' => 'لا توجد موازنة ولا مصروفات في هذه الفترة.',
            ];
        }

        $locked = (string) $version->status === 'approved';

        return [
            'severity' => $locked ? ($unbudgeted > 0 ? 'attention' : 'ok') : 'attention',
            'en' => sprintf(
                '%d account%s, of which %d %s %s%% or more away from budget. %s %s%s',
                \count($rows),
                \count($rows) === 1 ? '' : 's',
                $material,
                $material === 1 ? 'is' : 'are',
                $threshold,
                $locked
                    ? 'The budget is the approved version and was locked when it was approved, so '.
                        'these figures cannot have moved underneath an earlier print of this report.'
                    : sprintf(
                        'THE BUDGET IS A %s VERSION, not the approved one: its lines may still '.
                        'change, so two runs of this report can disagree without anything being '.
                        'posted.',
                        mb_strtoupper((string) $version->status),
                    ),
                (string) $commitments['why'],
                $unbudgeted === 0
                    ? ''
                    : sprintf(
                        ' %d account%s had spending against no budget line at all, which is money '.
                        'nobody allocated and is reported as material whatever the threshold says.',
                        $unbudgeted,
                        $unbudgeted === 1 ? '' : 's',
                    ),
            ),
            'ar' => sprintf(
                '%d حسابًا، منها %d تجاوزت حد الجوهرية %s%%. العملة %s.%s',
                \count($rows),
                $material,
                $threshold,
                $currency,
                $locked ? ' والموازنة هي النسخة المعتمدة المقفلة.' : ' والموازنة ليست النسخة المعتمدة.',
            ),
        ];
    }
}
