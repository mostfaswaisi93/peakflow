<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\FinancialStatement;
use App\Tenant\Ledger\Enums\ProfitOrLossBand;
use App\Tenant\Ledger\Enums\StatementLine;
use App\Tenant\Ledger\Enums\StatementSection;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use Illuminate\Support\Carbon;

/**
 * The statement of changes in equity - PF-187.
 *
 * ## What makes this statement different from the other two
 *
 * It is the only one that has to agree with both of them. Its closing
 * column must equal the equity section of the statement of financial
 * position, and its "result for the period" row must equal the bottom of
 * the statement of profit or loss. An entity whose three statements each
 * look right on their own and do not reconcile is an entity that has
 * published three different answers, and the reader has no way to tell
 * which one the ledger actually holds.
 *
 * So the reconciliation is computed here and declared on the output,
 * rather than being left to whoever reads them side by side.
 *
 * ## Movements are classified by what caused them, not by account
 *
 * IAS 1.106 asks for the result for the period, each item of other
 * comprehensive income, and transactions with owners shown separately.
 * Those are three different kinds of event that can touch the same equity
 * account: a credit to retained earnings is a profit if it came from the
 * close and a distribution if it came from a dividend, and the two must
 * never be added together under one heading.
 *
 * The journal says which: an entry carries the posting rule that made it,
 * and the year-end close is one rule. Everything else that touches equity
 * and is not OCI is a transaction with owners - which is a definition this
 * product can defend, because the alternative is asking the person
 * entering a dividend to tick a box nobody checks.
 *
 * ## Prior-period adjustments
 *
 * IAS 8.42 restates comparatives rather than folding a correction into the
 * current year. This statement presents the restatement as its own row
 * between the opening balance and the movements, so a reader can see that
 * the opening balance as previously reported is not the one the movements
 * start from. Nothing posts to it yet - the correction mechanism is PF-191
 * - and the row exists so that when it does, there is somewhere for it to
 * go that is not "retained earnings, somehow".
 */
final class StatementOfChangesInEquity
{
    /**
     * The posting rule that closes the year.
     *
     * Named here rather than imported, because `Reporting` reads every
     * module and importing a posting rule to compare one string would make
     * this class depend on the ledger's rule classes for nothing.
     */
    private const CLOSE_RULE = 'ledger.year_end_close';

    public function __construct(
        private readonly TrialBalanceReport $trialBalance,
        private readonly EquityMovements $movements,
    ) {}

    /**
     * Opening, movements by kind, closing - per equity component.
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
        bool $includeAdjusting = true,
    ): array {
        if ($until->lt($from)) {
            throw ReportRefused::because(
                'The period ends before it starts, so the closing balance would be earlier than the '.
                'opening one and every movement between them would read as its own negative.',
            );
        }

        $report = $this->trialBalance->forPeriod(
            $entityId,
            $functionalCurrency,
            $from,
            $until,
            $includeAdjusting,
        );

        $components = $this->components($report, $functionalCurrency);
        $byKind = $this->movements->byKind(
            $entityId,
            $from,
            $until,
            array_column($components, 'code'),
            self::CLOSE_RULE,
            $includeAdjusting,
        );

        $rows = $this->rows($components, $byKind, $report, $functionalCurrency);

        return [
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'currency' => $functionalCurrency,
            'includes_adjusting' => $includeAdjusting,
            'components' => $components,
            'rows' => $rows,
            'totals' => $this->totals($rows, $components, $functionalCurrency),
            'declaration' => $this->declare($rows, $components, $functionalCurrency),
        ];
    }

    /**
     * The columns: one per equity account that has a balance or a movement.
     *
     * ## Why per ACCOUNT and not per statement line
     *
     * IAS 1.106 presents a reconciliation "for each component of equity",
     * and a component is share capital, or the translation reserve, or
     * retained earnings - which is what an equity account is. Grouping by
     * statement line would merge two reserves that an entity deliberately
     * keeps apart, and the reader could not see the movement on either.
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function components(array $report, string $currency): array
    {
        $components = [];

        foreach ($report['rows'] as $row) {
            $line = $row['statement_line'] === null
                ? null
                : StatementLine::tryFrom((string) $row['statement_line']);

            $isEquity = $line !== null && (
                $line->section() === StatementSection::Equity
                || $line->band() === ProfitOrLossBand::OtherComprehensiveIncome
            );

            if (! $isEquity) {
                continue;
            }

            /*
             | Presented the way the balance sheet presents equity.
             |
             | A credit balance is stored negative and shown positive, so
             | the closing column of this statement and the equity section
             | of the other one are the same number - which is the whole
             | point of the reconciliation below.
             */
            $components[] = [
                'code' => (string) $row['code'],
                'name_ar' => (string) $row['name_ar'],
                'name_en' => (string) $row['name_en'],
                'line' => $line?->value,
                'is_oci' => $line?->band() === ProfitOrLossBand::OtherComprehensiveIncome,
                'opening' => (string) Money::of((string) $row['opening'], $currency)->negated()->amount,
                'closing' => (string) Money::of((string) $row['closing'], $currency)->negated()->amount,
            ];
        }

        usort($components, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $components;
    }

    /**
     * One row per kind of movement, across the components - IAS 1.106.
     *
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, array<string, string>>  $byKind
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function rows(array $components, array $byKind, array $report, string $currency): array
    {
        $codes = array_column($components, 'code');

        $empty = static fn (): array => array_fill_keys($codes, '0');

        $opening = [];
        $closing = [];

        foreach ($components as $component) {
            $opening[$component['code']] = $component['opening'];
            $closing[$component['code']] = $component['closing'];
        }

        $rows = [
            $this->row('opening_balance', 'الرصيد الافتتاحي', 'Opening balance', 'IAS 1.106(d)', $opening),
            $this->row(
                'prior_period_adjustment',
                'تعديلات فترات سابقة',
                'Prior-period adjustments',
                'IAS 8.42',
                $byKind['prior_period_adjustment'] ?? $empty(),
            ),
            $this->row(
                'result_for_the_period',
                'نتيجة الفترة',
                'Result for the period',
                'IAS 1.106(d)(i)',
                $byKind['result_for_the_period'] ?? $empty(),
            ),
            $this->row(
                'other_comprehensive_income',
                'الدخل الشامل الآخر',
                'Other comprehensive income',
                'IAS 1.106(d)(ii)',
                $byKind['other_comprehensive_income'] ?? $empty(),
            ),
            $this->row(
                'transactions_with_owners',
                'معاملات مع الملّاك',
                'Transactions with owners',
                'IAS 1.106(d)(iii)',
                $byKind['transactions_with_owners'] ?? $empty(),
            ),
            $this->row('closing_balance', 'الرصيد الختامي', 'Closing balance', 'IAS 1.106(d)', $closing),
        ];

        unset($report);

        return $rows;
    }

    /**
     * @param  array<string, string>  $amounts
     * @return array<string, mixed>
     */
    private function row(string $kind, string $ar, string $en, string $reference, array $amounts): array
    {
        return [
            'kind' => $kind,
            'label_ar' => $ar,
            'label_en' => $en,
            'reference' => $reference,
            'amounts' => $amounts,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $components
     * @return array<string, string>
     */
    private function totals(array $rows, array $components, string $currency): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $total = Money::zero($currency);

            foreach ($row['amounts'] as $amount) {
                $total = $total->plus(Money::of((string) $amount, $currency));
            }

            $totals[(string) $row['kind']] = (string) $total->amount;
        }

        /*
         | The arithmetic that has to hold, per component and in total.
         |
         | Opening plus every movement equals closing. It is not a
         | restatement of the ledger's own balance check: the movements are
         | classified by what caused them, so a movement that fell into no
         | kind is lost here and nowhere else - and the statement would
         | print an opening and a closing that do not join up.
         */
        $expected = Money::of($totals['opening_balance'], $currency)
            ->plus(Money::of($totals['prior_period_adjustment'], $currency))
            ->plus(Money::of($totals['result_for_the_period'], $currency))
            ->plus(Money::of($totals['other_comprehensive_income'], $currency))
            ->plus(Money::of($totals['transactions_with_owners'], $currency));

        $totals['unexplained'] = (string) Money::of($totals['closing_balance'], $currency)
            ->minus($expected)->amount;

        unset($components);

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $components
     * @return array<string, string>
     */
    private function declare(array $rows, array $components, string $currency): array
    {
        $totals = $this->totals($rows, $components, $currency);
        $unexplained = Money::of($totals['unexplained'], $currency);

        if ($unexplained->isZero()) {
            return [
                'severity' => 'ok',
                'en' => sprintf(
                    'Equity opens at %s and closes at %s, and every movement between them is accounted '.
                    'for.',
                    Money::of($totals['opening_balance'], $currency)->toPresentation(),
                    Money::of($totals['closing_balance'], $currency)->toPresentation(),
                ),
                'ar' => 'الرصيد الافتتاحي لحقوق الملكية وكل حركة عليه حتى الرصيد الختامي مُفسَّرة.',
            ];
        }

        return [
            'severity' => 'critical',
            'en' => sprintf(
                'The opening balance and the movements do not reach the closing balance: %s is '.
                'unexplained. A movement has fallen into none of the kinds this statement presents, so '.
                'it is on the balance sheet and is not on this page.',
                $unexplained->abs()->toPresentation(),
            ),
            'ar' => 'الرصيد الافتتاحي مع الحركات لا يبلغ الرصيد الختامي: هناك فرق غير مُفسَّر. حركةٌ لم '.
                'تدخل في أيٍّ من أنواع الحركات المعروضة، فهي في الميزانية وليست في هذه القائمة.',
        ];
    }
}
