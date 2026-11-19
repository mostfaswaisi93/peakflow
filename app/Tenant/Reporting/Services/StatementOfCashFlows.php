<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\CashFlowActivity;
use App\Tenant\Ledger\Enums\FinancialStatement;
use App\Tenant\Ledger\Enums\StatementLine;
use App\Tenant\Ledger\Enums\StatementSection;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use Illuminate\Support\Carbon;

/**
 * The statement of cash flows, both ways - PF-186.
 *
 * ## Why two methods and not the one the reader asked for
 *
 * IAS 7.18 permits either. This product computes both every time, because
 * they are independent: the indirect method starts from profit and works
 * through the movement in every balance-sheet account; the direct method
 * reads the cash and bank accounts themselves. They share no arithmetic,
 * so when they agree the number is worth something, and when they do not
 * one of them is wrong and the statement says so.
 *
 * A product that presented whichever was asked for would present a wrong
 * figure in exactly the case where the other one knew.
 *
 * ## The difference is almost always a non-cash item nobody tagged
 *
 * Which is why the non-cash movements are listed rather than dropped. A
 * revaluation, a translation difference, a provision raised against
 * profit: each changes a balance-sheet account without moving a penny, and
 * the indirect method would report it as a cash flow unless it is taken
 * out. Taking it out silently is how a statement reconciles by hiding its
 * own evidence - so they are excluded AND shown, with their accounts.
 *
 * ## What "cash" means here
 *
 * The accounts on the `cash_and_cash_equivalents` line, and nothing else -
 * IAS 7.7. An overdraft repayable on demand belongs in it too, and this
 * product does not model one yet; when it does, it joins that line rather
 * than acquiring a rule of its own here.
 */
final class StatementOfCashFlows
{
    public function __construct(
        private readonly TrialBalanceReport $trialBalance,
        private readonly CashMovements $cash,
    ) {}

    /**
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
                'The period ends before it starts. A cash flow is a movement between two dates, and '.
                'reversed, every inflow would be reported as an outflow.',
            );
        }

        $report = $this->trialBalance->forPeriod(
            $entityId,
            $functionalCurrency,
            $from,
            $until,
            $includeAdjusting,
        );

        $nonCash = $this->cash->nonCashTransactions($entityId, $from, $until, $includeAdjusting);
        $indirect = $this->indirect($report, $functionalCurrency, $nonCash);
        $direct = $this->direct($entityId, $from, $until, $functionalCurrency, $includeAdjusting);

        return [
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'currency' => $functionalCurrency,
            'includes_adjusting' => $includeAdjusting,
            'indirect' => $indirect,
            'direct' => $direct,
            'opening_cash' => $this->cashBalance($report, 'opening', $functionalCurrency),
            'closing_cash' => $this->cashBalance($report, 'closing', $functionalCurrency),
            'declaration' => $this->declare($indirect, $direct, $report, $functionalCurrency),
        ];
    }

    /**
     * Profit, then everything that stood between it and the cash.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, string>  $nonCash
     * @return array<string, mixed>
     */
    private function indirect(array $report, string $currency, array $nonCash): array
    {
        $result = $this->resultForThePeriod($report, $currency);

        $sections = [];
        $net = Money::zero($currency);

        foreach (CashFlowActivity::activities() as $activity) {
            $lines = [];
            $subtotal = Money::zero($currency);

            foreach ($this->movementsByLine($report, $activity, $currency, $nonCash) as $line => $amount) {
                $statementLine = StatementLine::from($line);

                $lines[] = [
                    'line' => $line,
                    'label_ar' => $statementLine->label('ar'),
                    'label_en' => $statementLine->label('en'),
                    'amount' => (string) $amount->amount,
                ];

                $subtotal = $subtotal->plus($amount);
            }

            /*
             | The result for the period opens the operating section.
             |
             | IAS 7.18(b): the indirect method starts from profit or loss
             | and adjusts it. Putting it anywhere else would make the
             | operating section a list of working-capital movements with
             | no reason for any of them.
             */
            if ($activity === CashFlowActivity::Operating) {
                array_unshift($lines, [
                    'line' => 'result_for_the_period',
                    'label_ar' => 'نتيجة الفترة',
                    'label_en' => 'Result for the period',
                    'amount' => (string) $result->amount,
                ]);

                $subtotal = $subtotal->plus($result);
            }

            $sections[] = [
                'activity' => $activity->value,
                'label_ar' => $activity->label('ar'),
                'label_en' => $activity->label('en'),
                'reference' => $activity->reference(),
                'lines' => $lines,
                'subtotal' => (string) $subtotal->amount,
            ];

            $net = $net->plus($subtotal);
        }

        return [
            'sections' => $sections,
            'non_cash' => $this->nonCashItems($report, $currency, $nonCash),
            'net_movement' => (string) $net->amount,
        ];
    }

    /**
     * The same figure, read from the cash accounts instead.
     *
     * @return array<string, mixed>
     */
    private function direct(
        string $entityId,
        Carbon $from,
        Carbon $until,
        string $currency,
        bool $includeAdjusting,
    ): array {
        $byActivity = $this->cash->byCounterpartActivity($entityId, $from, $until, $includeAdjusting);

        $sections = [];
        $net = Money::zero($currency);

        foreach (CashFlowActivity::activities() as $activity) {
            $amount = Money::of($byActivity[$activity->value] ?? '0', $currency);

            $sections[] = [
                'activity' => $activity->value,
                'label_ar' => $activity->label('ar'),
                'label_en' => $activity->label('en'),
                'reference' => $activity->reference(),
                'amount' => (string) $amount->amount,
            ];

            $net = $net->plus($amount);
        }

        /*
         | Cash moved against cash is excluded, not netted.
         |
         | A transfer from the current account to the deposit account moves
         | money between two things the statement calls cash. IAS 7.7 says
         | the statement reports the movement IN cash, so a transfer inside
         | it is not a flow - and a direct method that counted both sides
         | would report an inflow and an outflow that never happened.
         */
        return [
            'sections' => $sections,
            'internal_transfers' => $byActivity[CashFlowActivity::Cash->value] ?? '0',
            'unclassified' => $byActivity['unclassified'] ?? '0',
            'net_movement' => (string) $net->amount,
        ];
    }

    /**
     * The movement on every account of one activity, grouped by line.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, string>  $nonCash
     * @return array<string, Money>
     */
    private function movementsByLine(
        array $report,
        CashFlowActivity $activity,
        string $currency,
        array $nonCash,
    ): array {
        $byLine = [];

        foreach ($report['rows'] as $row) {
            $line = $row['statement_line'] === null
                ? null
                : StatementLine::tryFrom((string) $row['statement_line']);

            if ($line === null || $line->statement() !== FinancialStatement::FinancialPosition) {
                continue;
            }

            if ($line->cashFlowActivity() !== $activity) {
                continue;
            }

            /*
             | An asset's increase CONSUMES cash; a liability's provides it.
             |
             | The ledger stores an asset's balance positive and a
             | liability's negative, so negating the movement gives the
             | effect on cash for both in one expression - a receivable
             | that grew by 100 is -100 of cash, a payable that grew by 100
             | is +100. Writing the two cases separately is how a report
             | acquires one of them with the wrong sign.
             */
            /*
             | The non-cash part of this account's movement comes out first.
             |
             | IAS 7.43 excludes the whole TRANSACTION, not one leg of it.
             | A machine bought on credit moves property and payables and no
             | money; subtracting only the leg whose own line is non-cash
             | leaves the other one reported as a cash flow that never
             | happened - which is how the two methods were found to
             | disagree by exactly that leg.
             */
            $movement = Money::of((string) $row['movement'], $currency)
                ->minus(Money::of($nonCash[(string) $row['code']] ?? '0', $currency));

            $effect = $movement->negated();

            $byLine[$line->value] = isset($byLine[$line->value])
                ? $byLine[$line->value]->plus($effect)
                : $effect;
        }

        return array_filter($byLine, static fn (Money $amount): bool => ! $amount->isZero());
    }

    /**
     * The movements that moved no cash, named - IAS 7.43.
     *
     * Excluded AND shown. A statement that dropped them silently would
     * reconcile by hiding its own evidence, and the difference between the
     * two methods is almost always one of these that nothing has tagged.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, string>  $nonCash
     * @return list<array<string, string>>
     */
    private function nonCashItems(array $report, string $currency, array $nonCash): array
    {
        $names = [];

        foreach ($report['rows'] as $row) {
            $names[(string) $row['code']] = [
                'name_ar' => (string) $row['name_ar'],
                'name_en' => (string) $row['name_en'],
            ];
        }

        $items = [];

        foreach ($nonCash as $code => $amount) {
            $movement = Money::of((string) $amount, $currency);

            if ($movement->isZero()) {
                continue;
            }

            $items[] = [
                'code' => $code,
                'name_ar' => $names[$code]['name_ar'] ?? $code,
                'name_en' => $names[$code]['name_en'] ?? $code,
                'amount' => (string) $movement->negated()->amount,
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $items;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function resultForThePeriod(array $report, string $currency): Money
    {
        $result = Money::zero($currency);

        foreach ($report['rows'] as $row) {
            $line = $row['statement_line'] === null
                ? null
                : StatementLine::tryFrom((string) $row['statement_line']);

            $isProfitOrLoss = $line !== null
                && $line->statement() === FinancialStatement::ProfitOrLoss
                && $line->section() !== StatementSection::OtherComprehensiveIncome;

            if (! $isProfitOrLoss) {
                continue;
            }

            $result = $result->plus(Money::of((string) $row['movement'], $currency));
        }

        return $result->negated();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function cashBalance(array $report, string $column, string $currency): string
    {
        $total = Money::zero($currency);

        foreach ($report['rows'] as $row) {
            if ((string) ($row['statement_line'] ?? '') !== StatementLine::CashAndCashEquivalents->value) {
                continue;
            }

            $total = $total->plus(Money::of((string) $row[$column], $currency));
        }

        return (string) $total->amount;
    }

    /**
     * @param  array<string, mixed>  $indirect
     * @param  array<string, mixed>  $direct
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    private function declare(array $indirect, array $direct, array $report, string $currency): array
    {
        $byIndirect = Money::of((string) $indirect['net_movement'], $currency);
        $byDirect = Money::of((string) $direct['net_movement'], $currency);

        $opening = Money::of($this->cashBalance($report, 'opening', $currency), $currency);
        $closing = Money::of($this->cashBalance($report, 'closing', $currency), $currency);
        $actual = $closing->minus($opening);

        $unclassified = Money::of((string) $direct['unclassified'], $currency);

        if (! $unclassified->isZero()) {
            return [
                'severity' => 'warning',
                'en' => sprintf(
                    '%s of cash moved against an account with no classification, so it is in neither '.
                    'section. Classify those accounts in the chart; until then the direct method is '.
                    'short by that amount.',
                    $unclassified->abs()->format('en', false),
                ),
                'ar' => 'حركة نقدية قابلت حسابًا بلا تصنيف، فلم تدخل في أي نشاط. صنِّف تلك الحسابات في '.
                    'دليل الحسابات؛ وحتى ذلك الحين الطريقة المباشرة ناقصة بذلك المقدار.',
            ];
        }

        if (! $byIndirect->equals($byDirect)) {
            return [
                'severity' => 'critical',
                'en' => sprintf(
                    'The two methods disagree: indirect reports %s and direct reports %s. They share no '.
                    'arithmetic, so one of them is wrong - and the difference is almost always a '.
                    'non-cash movement that nothing has tagged.',
                    $byIndirect->format('en', false),
                    $byDirect->format('en', false),
                ),
                'ar' => 'الطريقتان لا تتفقان على صافي الحركة النقدية. وليس بينهما حسابٌ مشترك، فإحداهما '.
                    'خاطئة - والفرق في الغالب حركة غير نقدية لم يُصنِّفها شيء.',
            ];
        }

        if (! $byIndirect->equals($actual)) {
            return [
                'severity' => 'critical',
                'en' => sprintf(
                    'Both methods report %s and the cash accounts moved by %s. The statement agrees with '.
                    'itself and not with the bank.',
                    $byIndirect->format('en', false),
                    $actual->format('en', false),
                ),
                'ar' => 'الطريقتان تتفقان على رقم، والحسابات النقدية تحركت بغيره. القائمة متسقة مع نفسها '.
                    'ولا تتفق مع البنك.',
            ];
        }

        return [
            'severity' => 'ok',
            'en' => sprintf(
                'Cash moved by %s, and the indirect and direct methods agree on it.',
                $actual->format('en', false),
            ),
            'ar' => 'الطريقة غير المباشرة والطريقة المباشرة تتفقان على صافي حركة النقد.',
        ];
    }
}
