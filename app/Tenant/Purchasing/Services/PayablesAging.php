<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What we owe, how old it is, and whether the report can be trusted - PF-144.
 *
 * ## The basis is a decision and the report states it
 *
 * The **due date** answers "what is overdue", which is the question a
 * payables clerk asks. The **document date** answers "how old is this
 * balance", which is what an auditor asks about a supplier who has not been
 * paid. Both are legitimate, they disagree by the payment terms, and a report
 * that does not say which it used is a report two people read differently and
 * both believe.
 *
 * So `aging.basis` is an entity setting, every row carries the date it was
 * aged from, and the report's header says which basis produced it.
 *
 * ## Instalments are aged separately, which is the part most reports get
 * wrong
 *
 * A bill on 30/60/90 terms has three due dates. Aging the whole bill from the
 * last of them hides two months of overdue money; aging it from the first
 * makes two thirds of it look overdue when it is not due at all. Neither is
 * a rounding error - on a large bill it is the difference between a supplier
 * who is owed nothing yet and one who is about to stop delivering.
 *
 * The instalment amounts come from the **frozen** terms snapshot, so an
 * aging report run next month ages the bill exactly as it aged it this
 * month. See {@see Bills::freezeTerms()}.
 *
 * ## The reconciliation is the report's own test
 *
 * The aging total must equal the accounts-payable control account's balance.
 * If it does not, one of them is wrong, and both are used to pay people. So
 * the report computes both and says so in its own output rather than leaving
 * somebody to notice: a difference is a bug, and a report that hides its own
 * disagreement with the ledger is worse than no report.
 */
final class PayablesAging
{
    /**
     * The DEFAULT bucket boundaries, in days past the basis date.
     *
     * The ones `Ageing`, `ExpectedCreditLoss` and the Overview tile already
     * use, so that an entity which has not decided means the same thing by
     * "61-90" on all four.
     */
    public const BUCKETS = [30, 60, 90];

    public function __construct(private readonly Settings $settings) {}

    /**
     * The aging report.
     *
     * ## Why the boundaries are a parameter - PF-190
     *
     * Thirty, sixty and ninety suit a business on thirty-day terms and
     * suit nobody on fourteen: every balance lands in `1-30` and the
     * report says nothing. The boundaries are read from the entity's own
     * setting by the report that presents them, and passed in here, so
     * this service is not the place that decides and the AR and AP sides
     * of one report cannot bucket differently.
     *
     * The default is {@see BUCKETS}, which is what every caller before
     * PF-190 gets.
     *
     * @param  'due_date'|'document_date'|null  $basis  null reads the entity's setting
     * @param  list<int>|null  $boundaries  days past the basis date; null is the default
     * @return array<string, mixed>
     */
    public function report(
        string $entityId,
        ?Carbon $asOf = null,
        ?string $basis = null,
        ?string $partyId = null,
        ?array $boundaries = null,
    ): array {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $basis ??= (string) $this->settings->get('aging.basis', $entityId);
        $boundaries ??= self::BUCKETS;

        $bills = Bill::query()
            ->where('entity_id', $entityId)
            ->whereIn('status', ['approved', 'posted'])
            ->when($partyId !== null, static fn ($q) => $q->where('party_id', $partyId))
            ->with(['lines', 'creditNotes', 'creditAllocations', 'supplier'])
            ->selectRaw("bills.*, peakflow_document_open_balance('bill', bills.id)::text AS open_balance")
            ->get();

        $rows = [];
        $bySupplier = [];

        /*
         | Buckets per CURRENCY, not one set of totals.
         |
         | 1,000 USD and 1,000 EUR are not 2,000 of anything, and a report
         | that added them would be read as a figure somebody could pay. The
         | ledger's own total is in the functional currency, which is why the
         | reconciliation below works on that currency alone and says so
         | where it cannot.
         */
        $buckets = [];
        $totals = [];
        $currencies = [];

        foreach ($bills as $bill) {
            foreach ($this->instalmentsOf($bill, $basis) as $instalment) {
                if ($instalment['outstanding']->isZero()) {
                    continue;
                }

                $age = (int) Carbon::parse($instalment['aged_from'])->diffInDays($day, absolute: false);
                $bucket = $this->bucketFor($age, $boundaries);
                $currency = (string) $bill->currency;
                $party = (string) $bill->party_id;
                $currencies[$currency] = true;

                $rows[] = [
                    'bill' => (string) $bill->supplier_document_number,
                    'bill_id' => (string) $bill->getKey(),
                    'document_type' => (string) $bill->document_type,
                    'supplier' => (string) ($bill->supplierName() ?? $party),
                    'party_id' => $party,
                    'currency' => $currency,
                    'instalment' => $instalment['sequence'],
                    'instalments' => $instalment['of'],
                    'aged_from' => $instalment['aged_from'],
                    'age_days' => $age,
                    'bucket' => $bucket,
                    'outstanding' => (string) $instalment['outstanding']->amount,
                ];

                $buckets[$currency] ??= $this->emptyBuckets($currency, $boundaries);
                $buckets[$currency][$bucket] = $buckets[$currency][$bucket]->plus($instalment['outstanding']);

                $totals[$currency] = ($totals[$currency] ?? Money::zero($currency))
                    ->plus($instalment['outstanding']);

                $bySupplier[$party] ??= [
                    'supplier' => (string) ($bill->supplierName() ?? $party),
                    'buckets' => [],
                    'totals' => [],
                ];

                $bySupplier[$party]['buckets'][$currency] ??= $this->emptyBuckets($currency, $boundaries);
                $bySupplier[$party]['buckets'][$currency][$bucket] =
                    $bySupplier[$party]['buckets'][$currency][$bucket]->plus($instalment['outstanding']);

                $bySupplier[$party]['totals'][$currency] = ($bySupplier[$party]['totals'][$currency] ?? Money::zero($currency))
                    ->plus($instalment['outstanding']);
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['age_days'] <=> $a['age_days']);

        return [
            'as_of' => $day->toDateString(),
            /*
             | Stated, not assumed - PF-144.
             |
             | Two people reading the same numbers under different
             | assumptions is how a payment run pays an invoice that is not
             | due and skips one that is.
             */
            'basis' => $basis,
            /*
             | The boundaries, on the output - PF-190.
             |
             | "1-30" means nothing without them: an entity that moved the
             | boundaries to 14/30/60 produces a report whose bucket names
             | read the same and whose figures are a different question.
             */
            'boundaries' => $boundaries,
            'basis_label' => $basis === 'due_date'
                ? 'aged from each instalment\'s due date'
                : 'aged from the supplier\'s document date',
            'buckets' => array_map(
                static fn (array $set): array => array_map(static fn (Money $m): string => (string) $m->amount, $set),
                $buckets,
            ),
            'totals' => array_map(static fn (Money $m): string => (string) $m->amount, $totals),
            'rows' => $rows,
            'by_supplier' => array_map(static fn (array $supplier): array => [
                'supplier' => $supplier['supplier'],
                'buckets' => array_map(
                    static fn (array $set): array => array_map(static fn (Money $m): string => (string) $m->amount, $set),
                    $supplier['buckets'],
                ),
                'totals' => array_map(static fn (Money $m): string => (string) $m->amount, $supplier['totals']),
            ], $bySupplier),
            'reconciliation' => $this->reconcile(
                $entityId,
                $totals,
                array_keys($currencies),
                $day,
                $partyId,
            ),
        ];
    }

    /**
     * Days payable outstanding.
     *
     * `(payables / net purchases in the window) x days`. The window matters
     * and is stated: DPO over a quarter and DPO over a year are different
     * numbers, and a ratio quoted without its window is a ratio nobody can
     * reproduce.
     *
     * Returns null rather than zero where there were no purchases. A DPO of
     * zero reads as "we pay immediately", which is the opposite of what an
     * empty window means.
     *
     * ## One currency, named, and the rest counted out
     *
     * This used to sum `bills.net_amount` across every currency a supplier
     * had ever billed in. A thousand dollars and a thousand riyals made two
     * thousand of nothing, divided into a control-account balance that was
     * itself adding currencies, and the quotient printed to one decimal
     * place - which is what makes a ratio like this dangerous. It looks
     * precise. Nobody reads a number with a decimal point and wonders
     * whether its two halves were denominated in the same thing.
     *
     * The books are kept in the functional currency and the control account
     * is in it, so that is the only currency the ratio can honestly be taken
     * in. Documents in others are counted OUT and the count is reported,
     * rather than converted at today's rate - a rate today has nothing to do
     * with how long a bill from March took to pay, and converting would
     * replace a figure the reader can check with one they cannot.
     *
     * The mirror of {@see \App\Tenant\Reporting\Services\AgingReport::daysSalesOutstanding()},
     * deliberately: DSO and DPO sit beside each other on a page and get
     * subtracted from one another, so two ratios taken on different bases
     * would be worse than either one alone.
     *
     * @return array<string, mixed>
     */
    public function daysPayableOutstanding(string $entityId, Carbon $from, Carbon $to): array
    {
        $days = (int) $from->diffInDays($to, absolute: true) + 1;

        $currency = (string) DB::connection('tenant')->table('entities')
            ->where('id', $entityId)
            ->value('functional_currency');

        $currency = $currency === '' ? 'SAR' : $currency;

        $purchases = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT
                coalesce(sum(net_amount) FILTER (WHERE document_type = '380' AND currency = ?), 0)::text AS billed,
                coalesce(sum(net_amount) FILTER (WHERE document_type = '381' AND currency = ?), 0)::text AS credited,
                count(*) FILTER (WHERE currency <> ?) AS other_currency
            FROM bills
            WHERE entity_id = cast(? AS char(26))
              AND status IN ('approved', 'posted')
              AND document_type IN ('380', '381')
              AND posting_date BETWEEN cast(? AS date) AND cast(? AS date)
        SQL, [
            $currency,
            $currency,
            $currency,
            $entityId,
            $from->toDateString(),
            $to->toDateString(),
        ]);

        $net = BigDecimal::of((string) ($purchases->billed ?? '0'))
            ->minus((string) ($purchases->credited ?? '0'));

        $window = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'currency' => $currency,
            'formula' => 'payables / net purchases in the window x days in the window',
            'purchases' => (string) $net,
        ];

        /*
         | Stated whenever it is not zero, and only then.
         |
         | A reader who sees no such line can take the figure as covering
         | everything in the window. A reader who sees "4 bills in another
         | currency are not in this figure" knows what they are looking at
         | and can go and look at them. The silent version of this is the
         | one that gets quoted in a board pack.
         */
        if ((int) ($purchases->other_currency ?? 0) > 0) {
            $window['excluded_other_currency'] = (int) $purchases->other_currency;
        }

        if (! $net->isPositive()) {
            return array_merge($window, [
                'dpo' => null,
                'why' => sprintf(
                    'No net purchases in %s in this window, so there is nothing to divide by. A DPO of '.
                    'zero would read as "we pay immediately", which is the opposite of what an empty '.
                    'window means.',
                    $currency,
                ),
            ]);
        }

        $payables = $this->controlAccountBalance($entityId, $to);

        return array_merge($window, [
            'payables' => (string) $payables->amount,
            'dpo' => (string) BigDecimal::of((string) $payables->amount)
                ->dividedBy($net, 6, RoundingMode::HalfUp)
                ->multipliedBy((string) $days)
                ->toScale(1, RoundingMode::HalfUp),
            'why' => sprintf(
                'The payables balance on %s divided by %s of net purchases over %d days. The balance '.
                'is the control account\'s and the purchases are the bills approved or posted in the '.
                'window, both in %s - a ratio taken across currencies would print to one decimal place '.
                'and mean nothing.',
                $to->toDateString(),
                (string) $net,
                $days,
                $currency,
            ),
        ]);
    }

    /**
     * A supplier statement, open-item or balance-forward.
     *
     * Open-item lists what is unpaid, which is what a supplier wants when
     * they are chasing. Balance-forward lists every movement from an opening
     * balance, which is what a reconciliation needs: it shows the payments
     * and credits as well, so the two sides can find where they diverged.
     *
     * Both exist because a supplier who disputes a balance is not helped by
     * a list of what we think is still open - the disagreement is usually
     * about something one side has and the other does not.
     *
     * A balance-forward statement is per **currency**, and refuses to guess.
     * A running balance that added 1,000 USD to 1,000 EUR would be a figure
     * somebody reconciles against and nobody can reproduce.
     *
     * @param  'open_item'|'balance_forward'  $form
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when a balance-forward statement
     *                                   spans currencies and none was named
     */
    public function statement(
        string $entityId,
        string $partyId,
        string $form = 'open_item',
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $currency = null,
    ): array {
        $to = ($to ?? Carbon::now())->copy()->startOfDay();

        $bills = Bill::query()
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->whereIn('status', ['approved', 'posted'])
            ->where('posting_date', '<=', $to->toDateString())
            ->with(['creditNotes', 'creditAllocations'])
            ->when($currency !== null, static fn ($q) => $q->where('currency', $currency))
            ->selectRaw("bills.*, peakflow_document_open_balance('bill', bills.id)::text AS open_balance")
            ->orderBy('supplier_document_date')
            ->get();

        if ($form === 'open_item') {
            $lines = [];
            $totals = [];

            foreach ($bills as $bill) {
                $outstanding = $this->outstandingOf($bill);

                if ($outstanding->isZero()) {
                    continue;
                }

                $lines[] = [
                    'date' => $bill->supplier_document_date->toDateString(),
                    'document' => (string) $bill->supplier_document_number,
                    'type' => (string) $bill->document_type,
                    'currency' => (string) $bill->currency,
                    'charged' => (string) $bill->gross_amount,
                    'outstanding' => (string) $outstanding->amount,
                    'due' => $this->dueDatesOf($bill),
                ];

                $currency = (string) $bill->currency;
                $totals[$currency] = ($totals[$currency] ?? Money::zero($currency))->plus($outstanding);
            }

            return [
                'form' => 'open_item',
                'as_of' => $to->toDateString(),
                'party_id' => $partyId,
                'lines' => $lines,
                'totals' => array_map(static fn (Money $m): string => (string) $m->amount, $totals),
            ];
        }

        $from = ($from ?? $to->copy()->subMonths(3))->copy()->startOfDay();

        /*
         | The opening balance is what was outstanding before the window.
         |
         | Computed from the documents rather than carried as a stored
         | figure, because a stored opening balance is a number nobody can
         | check: the first time it disagrees with the documents, there is
         | no way to tell which is wrong.
         */
        $present = $bills->pluck('currency')->map(static fn ($c): string => (string) $c)->unique()->values()->all();

        if (\count($present) > 1) {
            throw new \InvalidArgumentException(sprintf(
                'This supplier has documents in %s, and a balance-forward statement is a running '.
                'balance. Adding them would produce a figure somebody reconciles against and nobody '.
                'can reproduce, so the statement is per currency: name one.',
                implode(', ', $present),
            ));
        }

        $currency ??= $present[0] ?? (string) (DB::connection('tenant')
            ->table('entities')->where('id', $entityId)->value('functional_currency') ?? 'SAR');

        $opening = Money::zero($currency);
        $movements = [];

        foreach ($bills as $bill) {
            $isBefore = $bill->supplier_document_date->lt($from);
            $signed = $bill->isCreditNote()
                ? $bill->gross()->negated()
                : $bill->gross();

            if ($isBefore) {
                $opening = $opening->plus($signed);

                continue;
            }

            $movements[] = [
                'date' => $bill->supplier_document_date->toDateString(),
                'document' => (string) $bill->supplier_document_number,
                'type' => (string) $bill->document_type,
                'currency' => (string) $bill->currency,
                'charged' => (string) $signed->amount,
                'paid' => (string) $bill->paid_amount,
            ];
        }

        $running = $opening;

        foreach ($movements as $index => $movement) {
            $running = $running->plus(Money::of($movement['charged'], (string) $movement['currency']))
                ->minus(Money::of((string) $movement['paid'], (string) $movement['currency']));

            $movements[$index]['balance'] = (string) $running->amount;
        }

        return [
            'form' => 'balance_forward',
            'currency' => $currency,
            'from' => $from->toDateString(),
            'as_of' => $to->toDateString(),
            'party_id' => $partyId,
            'opening_balance' => (string) $opening->amount,
            'movements' => $movements,
            'closing_balance' => (string) $running->amount,
        ];
    }

    /**
     * The instalments of a bill, each with its own aging date.
     *
     * One entry for a bill on simple terms; one per instalment for a bill on
     * 30/60/90. The amounts come from the frozen snapshot, so the report
     * does not re-derive a due date that has since moved.
     *
     * @return list<array<string, mixed>>
     */
    public function instalmentsOf(Bill $bill, string $basis): array
    {
        $currency = (string) $bill->currency;
        $outstanding = $this->outstandingOf($bill);

        if ($basis === 'document_date') {
            /*
             | The document basis has one row per bill, by definition.
             |
             | "How old is this balance" is a question about the bill, not
             | about its instalments: the supplier issued it on one date
             | whatever the terms said afterwards.
             */
            return [[
                'sequence' => 1,
                'of' => 1,
                'aged_from' => $bill->supplier_document_date->toDateString(),
                'outstanding' => $outstanding,
            ]];
        }

        $dueDates = $bill->terms_snapshot['due_dates'] ?? null;

        if (! \is_array($dueDates) || $dueDates === []) {
            /*
             | No frozen terms: aged from the supplier's own date.
             |
             | Which is the honest fallback. A bill with no terms is due on
             | receipt in most jurisdictions, and inventing a due date here
             | would make the report disagree with the one the payment run
             | reads.
             */
            return [[
                'sequence' => 1,
                'of' => 1,
                'aged_from' => $bill->supplier_document_date->toDateString(),
                'outstanding' => $outstanding,
            ]];
        }

        /*
         | Each instalment, with the payment applied to the earliest first.
         |
         | Which is how a supplier applies it unless told otherwise, and the
         | alternative - spreading a payment proportionally - would leave
         | every instalment partly unpaid and every one of them in a
         | different bucket.
         */
        $remaining = $outstanding;
        $instalments = [];
        $count = \count($dueDates);

        foreach ($dueDates as $index => $due) {
            $share = isset($due['amount']) && $due['amount'] !== null
                ? Money::of((string) $due['amount'], $currency)
                : $bill->gross()->multipliedBy(
                    (string) BigDecimal::of((string) ($due['proportion'] ?? '1')),
                    RoundingPolicy::halfUp(Scale::Amount),
                );

            $unpaid = $remaining->isGreaterThan($share) ? $share : $remaining;
            $remaining = $remaining->minus($unpaid);

            $instalments[] = [
                'sequence' => (int) ($due['sequence'] ?? $index + 1),
                'of' => $count,
                'aged_from' => (string) $due['date'],
                'outstanding' => $unpaid,
            ];

            if ($remaining->isZero()) {
                break;
            }
        }

        return $instalments;
    }

    /**
     * What a bill still owes, from the one definition - PF-281.
     *
     * ## It used to ask the model, and the model could not know
     *
     * `Bill::outstanding()` is gross less `bills.paid_amount` less the two
     * credit terms - and **nothing writes `paid_amount`**. Settlement
     * records an allocation in its own table and may not reach into
     * Purchasing's models to denormalise it, so the column has been zero
     * since it was added. Every posted bill therefore aged at its full
     * gross for ever: the showcase books paid 41 of 56 bills and this
     * report still showed all 56 in full.
     *
     * `peakflow_document_open_balance` is the definition the screens and
     * the payment run already use. Asking it here is what makes "which
     * bills are still open" have one answer rather than two that both look
     * right - which is the failure `BillListController` wrote down and this
     * report was quietly demonstrating.
     *
     * The column is left in place and unused rather than dropped: it is on
     * `ExpenseClaim` too, where it IS written, and removing it from `bills`
     * is a migration with its own task.
     */
    private function outstandingOf(Bill $bill): Money
    {
        if ($bill->isCreditNote()) {
            /*
             | A credit note's outstanding is what has not been used.
             |
             | Negative in the aging, because it reduces what we owe: a
             | credit note shown as a positive balance is a supplier the
             | report says we owe money to when they owe it to us.
             */
            $used = Money::zero((string) $bill->currency);

            foreach ($bill->creditAllocations as $allocation) {
                $used = $used->plus(Money::of((string) $allocation->amount, (string) $bill->currency));
            }

            if ($bill->credits_bill_id !== null) {
                /*
                 | A credit note that names a bill is already netted there.
                 |
                 | Showing it again as a line of its own would halve the
                 | supplier's balance twice.
                 */
                return Money::zero((string) $bill->currency);
            }

            return $bill->gross()->minus($used)->negated();
        }

        $open = $bill->getAttribute('open_balance');

        if ($open === null) {
            /*
             | A caller that did not select it gets it asked for, one row.
             |
             | Slower, and still one definition. Falling back to
             | `Bill::outstanding()` here would reinstate the second answer
             | for whichever caller forgot the select, which is the shape of
             | bug this method exists to have fixed.
             */
            $open = DB::connection('tenant')->scalar(
                "select peakflow_document_open_balance('bill', ?)::text",
                [(string) $bill->getKey()],
            );
        }

        return Money::of((string) $open, (string) $bill->currency);
    }

    /** @return list<array<string, mixed>> */
    private function dueDatesOf(Bill $bill): array
    {
        $dueDates = $bill->terms_snapshot['due_dates'] ?? null;

        if (! \is_array($dueDates)) {
            return [];
        }

        return array_map(static fn (array $due): array => [
            'sequence' => (int) ($due['sequence'] ?? 1),
            'date' => (string) $due['date'],
            'amount' => $due['amount'] === null ? null : (string) $due['amount'],
        ], $dueDates);
    }

    /**
     * The report's disagreement with the ledger, stated in the report.
     *
     * A difference is a bug - in the aging, in the posting, or in a bill
     * that was approved and never posted - and all three are worth knowing
     * about before somebody pays from the report.
     *
     * Skipped where the report is filtered to one supplier, because the
     * control account carries every supplier and a partial total cannot
     * reconcile to a whole balance. Saying so is better than reporting a
     * difference that is not one.
     *
     * @return array<string, mixed>
     */
    private function reconcile(
        string $entityId,
        array $totals,
        array $currencies,
        Carbon $asOf,
        ?string $partyId,
    ): array {
        if ($partyId !== null) {
            return [
                'checked' => false,
                'why' => 'The report is filtered to one supplier, and the control account carries every '.
                    'supplier. A partial total cannot reconcile to a whole balance.',
            ];
        }

        $functional = (string) DB::connection('tenant')->table('entities')
            ->where('id', $entityId)
            ->value('functional_currency');

        $foreign = array_values(array_filter(
            $currencies,
            static fn (string $currency): bool => $currency !== $functional,
        ));

        if ($foreign !== []) {
            /*
             | Not attempted, and the report says why.
             |
             | The ledger's balance is in the functional currency and these
             | documents are not, so comparing the two would report a
             | difference that is an exchange rate rather than a defect.
             | Translating them needs each bill's rate on its tax point,
             | which arrives with PF-138 - and a reconciliation that quietly
             | translated at today's rate would move every month it was run.
             */
            return [
                'checked' => false,
                'why' => sprintf(
                    'The report holds documents in %s and the control account is in %s. Comparing them '.
                    'would report an exchange rate as a difference; translating them needs each bill\'s '.
                    'rate on its own tax point, which is PF-138\'s remaining work.',
                    implode(', ', $foreign),
                    $functional,
                ),
            ];
        }

        $agingTotal = $totals[$functional] ?? Money::zero($functional);
        $control = $this->controlAccountBalance($entityId, $asOf);
        $difference = $agingTotal->minus($control);

        return [
            'checked' => true,
            'currency' => $functional,
            'aging_total' => (string) $agingTotal->amount,
            'control_account' => (string) $control->amount,
            'difference' => (string) $difference->amount,
            'agrees' => $difference->isZero(),
            'why' => $difference->isZero()
                ? null
                : 'The aging total and the accounts-payable control account disagree. One of them is '.
                    'wrong and both are used to pay people: the usual causes are a bill approved and '.
                    'never posted, a journal posted straight to the control account, or a payment '.
                    'recorded against the ledger and not against the document.',
        ];
    }

    /**
     * The accounts-payable control account's balance, as a positive amount owed.
     *
     * ## Two things this used to get wrong, and both were invisible
     *
     * It summed `l.amount`, which is the line's amount in the DOCUMENT's
     * currency, and labelled the result with the entity's functional
     * currency. One foreign bill and the figure added 1,000 USD to 1,000 SAR
     * and called the total riyals. The aging path was saved from it by
     * {@see self::reconcile()}, which refuses a foreign currency outright -
     * but {@see self::daysPayableOutstanding()} divides by this figure and
     * had no such guard, so DPO was quietly wrong for any entity that buys
     * abroad. `functional_amount` is the column that is denominated in the
     * currency this method claims to be returning.
     *
     * And it filtered no status, so a DRAFT entry counted. A draft is
     * somebody's unfinished work: it has no period, it has not been posted,
     * and nothing downstream treats it as a balance. Counting it made the
     * control account disagree with the aging total, and the report then
     * announced that one of them was wrong and offered three causes, none of
     * which was "somebody has a journal open". The report accused the ledger
     * of an error it had not made - which is worse than a wrong number,
     * because somebody acts on it.
     *
     * `reversed` is included with `posted`: a reversal is itself a posted
     * entry carrying the opposite lines, so both sides are already in the
     * sum and excluding the reversal would leave only the original.
     */
    private function controlAccountBalance(string $entityId, Carbon $asOf): Money
    {
        $currency = (string) DB::connection('tenant')->table('entities')
            ->where('id', $entityId)
            ->value('functional_currency');

        $sum = DB::connection('tenant')->table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('account_roles as r', 'r.account_id', '=', 'l.account_id')
            ->where('e.entity_id', $entityId)
            ->where('r.role', ControlRole::AccountsPayable->value)
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.accounting_date', '<=', $asOf->toDateString())
            ->sum('l.functional_amount');

        /*
         | Negated, because a payable is a credit balance.
         |
         | The ledger stores one signed amount - a credit is negative - and
         | the aging report talks about what is owed as a positive number,
         | which is how a person reads it.
         */
        return Money::of((string) ($sum ?? '0'), $currency === '' ? 'SAR' : $currency)->negated();
    }

    /**
     * @param  list<int>  $boundaries
     * @return array<string, Money>
     */
    private function emptyBuckets(string $currency, array $boundaries): array
    {
        $buckets = ['current' => Money::zero($currency)];
        $previous = 0;

        foreach ($boundaries as $boundary) {
            $buckets[sprintf('%d-%d', $previous + 1, $boundary)] = Money::zero($currency);
            $previous = $boundary;
        }

        $buckets[sprintf('%d+', $previous)] = Money::zero($currency);

        return $buckets;
    }

    /** @param list<int> $boundaries */
    private function bucketFor(int $age, array $boundaries): string
    {
        if ($age <= 0) {
            return 'current';
        }

        $previous = 0;

        foreach ($boundaries as $boundary) {
            if ($age <= $boundary) {
                return sprintf('%d-%d', $previous + 1, $boundary);
            }

            $previous = $boundary;
        }

        return sprintf('%d+', $previous);
    }
}
