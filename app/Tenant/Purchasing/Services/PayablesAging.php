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
    /** The bucket boundaries, in days past the basis date. */
    public const BUCKETS = [30, 60, 90];

    public function __construct(private readonly Settings $settings) {}

    /**
     * The aging report.
     *
     * @param  'due_date'|'document_date'|null  $basis  null reads the entity's setting
     * @return array<string, mixed>
     */
    public function report(
        string $entityId,
        ?Carbon $asOf = null,
        ?string $basis = null,
        ?string $partyId = null,
    ): array {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $basis ??= (string) $this->settings->get('aging.basis', $entityId);

        $bills = Bill::query()
            ->where('entity_id', $entityId)
            ->whereIn('status', ['approved', 'posted'])
            ->when($partyId !== null, static fn ($q) => $q->where('party_id', $partyId))
            ->with(['lines', 'creditNotes', 'creditAllocations', 'supplier'])
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
                $bucket = $this->bucketFor($age);
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

                $buckets[$currency] ??= $this->emptyBuckets($currency);
                $buckets[$currency][$bucket] = $buckets[$currency][$bucket]->plus($instalment['outstanding']);

                $totals[$currency] = ($totals[$currency] ?? Money::zero($currency))
                    ->plus($instalment['outstanding']);

                $bySupplier[$party] ??= [
                    'supplier' => (string) ($bill->supplierName() ?? $party),
                    'buckets' => [],
                    'totals' => [],
                ];

                $bySupplier[$party]['buckets'][$currency] ??= $this->emptyBuckets($currency);
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
     * `(payables / purchases in the window) x days`. The window matters and
     * is stated: DPO over a quarter and DPO over a year are different
     * numbers, and a ratio quoted without its window is a ratio nobody can
     * reproduce.
     *
     * Returns null rather than zero where there were no purchases. A DPO of
     * zero reads as "we pay immediately", which is the opposite of what an
     * empty window means.
     *
     * @return array<string, mixed>
     */
    public function daysPayableOutstanding(string $entityId, Carbon $from, Carbon $to): array
    {
        $days = (int) $from->diffInDays($to, absolute: true) + 1;

        $purchases = BigDecimal::of((string) (DB::connection('tenant')->table('bills')
            ->where('entity_id', $entityId)
            ->where('document_type', '380')
            ->whereIn('status', ['approved', 'posted'])
            ->whereBetween('posting_date', [$from->toDateString(), $to->toDateString()])
            ->sum('net_amount') ?? '0'));

        $credits = BigDecimal::of((string) (DB::connection('tenant')->table('bills')
            ->where('entity_id', $entityId)
            ->where('document_type', '381')
            ->whereIn('status', ['approved', 'posted'])
            ->whereBetween('posting_date', [$from->toDateString(), $to->toDateString()])
            ->sum('net_amount') ?? '0'));

        $net = $purchases->minus($credits);

        if (! $net->isPositive()) {
            return [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'purchases' => (string) $net,
                'dpo' => null,
                'why' => 'No purchases in the window, so there is nothing to divide by. A DPO of zero '.
                    'would read as "we pay immediately", which is the opposite of what an empty window '.
                    'means.',
            ];
        }

        $payables = $this->controlAccountBalance($entityId, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'purchases' => (string) $net,
            'payables' => (string) $payables->amount,
            'dpo' => (string) BigDecimal::of((string) $payables->amount)
                ->dividedBy($net, 6, RoundingMode::HalfUp)
                ->multipliedBy((string) $days)
                ->toScale(1, RoundingMode::HalfUp),
        ];
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

    /** What a bill still owes, net of payments and allocated credits. */
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

        return $bill->outstanding();
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

    /** The accounts-payable control account's balance, as a positive amount owed. */
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
            ->where('e.accounting_date', '<=', $asOf->toDateString())
            ->sum('l.amount');

        /*
         | Negated, because a payable is a credit balance.
         |
         | The ledger stores one signed amount - a credit is negative - and
         | the aging report talks about what is owed as a positive number,
         | which is how a person reads it.
         */
        return Money::of((string) ($sum ?? '0'), $currency === '' ? 'SAR' : $currency)->negated();
    }

    /** @return array<string, Money> */
    private function emptyBuckets(string $currency): array
    {
        $buckets = ['current' => Money::zero($currency)];
        $previous = 0;

        foreach (self::BUCKETS as $boundary) {
            $buckets[sprintf('%d-%d', $previous + 1, $boundary)] = Money::zero($currency);
            $previous = $boundary;
        }

        $buckets[sprintf('%d+', $previous)] = Money::zero($currency);

        return $buckets;
    }

    private function bucketFor(int $age): string
    {
        if ($age <= 0) {
            return 'current';
        }

        $previous = 0;

        foreach (self::BUCKETS as $boundary) {
            if ($age <= $boundary) {
                return sprintf('%d-%d', $previous + 1, $boundary);
            }

            $previous = $boundary;
        }

        return sprintf('%d+', $previous);
    }
}
