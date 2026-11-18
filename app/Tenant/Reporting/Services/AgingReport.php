<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Purchasing\Services\PayablesAging;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Registers\ReportParameters;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Settlement\Services\UnappliedCash;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What is owed to us and by us, by age - PF-190, IFRS 9.
 *
 * ## What is reused, what is extended, and what is new here
 *
 * **{@see PayablesAging} is reused for the payables side, not reimplemented.**
 * It already ages each instalment from its own frozen due date, carries the
 * basis on its output, shows a supplier credit as a negative row, and
 * reconciles to the payables control account - which is PF-144's work and
 * is the same work PF-190 asks for. A second payables report here would be
 * a second answer to "what do we owe", and the two would be used to pay the
 * same supplier. It was EXTENDED by one parameter: the bucket boundaries,
 * which PF-190 makes configurable and which it previously held as a
 * constant.
 *
 * **{@see UnappliedCash} is reused and extended by one parameter.** It
 * already ages unapplied receipts and already returns `credit_buckets` -
 * the same figures with the sign they have on a customer's account -
 * written for exactly this report. It read receipts only; a payment nobody
 * has allocated is the same fact on the supplier's side, so the direction
 * is now an argument rather than a second query here.
 *
 * **{@see \App\Tenant\Reporting\Overview\Ageing} is NOT reused**, and this
 * is the one decision worth stating plainly. It is the Overview tile's
 * value object: five fixed buckets, one currency, no party breakdown, no
 * credits, no reconciliation - and it ages a document from
 * `max(due_dates)`, the LAST of its instalments. That last point is the
 * error PF-190 names in its second criterion: a bill on 30/60/90 terms aged
 * from the ninetieth day reports two months of overdue money as current.
 * Reusing it would carry that into the report people chase debts from. Its
 * DEFAULT boundaries are reused - 30/60/90, the same ones the provision
 * matrix and the payables report use - so that the tile and this report
 * still mean the same thing by "61-90" for an entity that has not changed
 * them.
 *
 * ## The basis is stated because the two answers are both correct
 *
 * The **due date** answers "what is overdue", which is what a collections
 * clerk asks. The **document date** answers "how old is this balance",
 * which is what an auditor asks about a customer who has not paid. They
 * disagree by the payment terms, and a report that does not say which it
 * used is a report two people read differently and both believe.
 *
 * ## A credit is a negative bucket, never a netting
 *
 * A customer with a 10,000 invoice 90 days old and a 10,000 unapplied
 * receipt is not a customer who owes nothing: somebody has to work out
 * which invoice that money was for, and until they do, both facts are true.
 * Netting them produces a quiet zero and the 90-day invoice disappears from
 * the one report that would have made anybody ask.
 *
 * ## The reconciliation is declared, not left to be discovered
 *
 * The aging total has to equal the control account. If it does not, one of
 * them is wrong and both are used to chase money, so the difference is in
 * the declaration at the top rather than available to whoever thinks to
 * add two columns.
 */
final class AgingReport
{
    /**
     * Where the buckets divide when nobody has decided.
     *
     * The same boundaries as `PayablesAging::BUCKETS`, `ExpectedCreditLoss`
     * and the Overview tile. Stated here as well rather than imported from
     * one of them because this report presents both sides and may not
     * depend on the payables service for the AR default: the duplication is
     * three integers, and the alternative is the AR side's default changing
     * when somebody tunes the AP service's constant.
     */
    public const array DEFAULT_BOUNDARIES = [30, 60, 90];

    /** At most this many boundaries: seven buckets is already unreadable. */
    private const MAX_BOUNDARIES = 6;

    public function __construct(
        private readonly Settings $settings,
        private readonly PayablesAging $payables,
        private readonly UnappliedCash $unapplied,
    ) {}

    /**
     * What customers owe, by age.
     *
     * @param  'due_date'|'document_date'|null  $basis  null reads the entity's setting
     * @param  list<int>|null  $boundaries  null reads the entity's setting
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function receivables(
        string $entityId,
        string $functionalCurrency,
        ?Carbon $asOf = null,
        ?string $basis = null,
        ?array $boundaries = null,
        ?string $partyId = null,
    ): array {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $basis = $this->basis($entityId, $basis);
        $boundaries = $this->boundaries($entityId, $boundaries);

        $parameters = ReportParameters::for(
            report: 'receivables_aging',
            entityId: $entityId,
            /*
             | An aging report has no lower bound, and saying so is not
             | pedantry: it is every open document whatever its age, which
             | is why the first bucket is "current" and not a date.
             */
            from: $day,
            until: $day,
            currency: $functionalCurrency,
            filters: [
                'basis' => $basis,
                'buckets' => implode('/', $boundaries),
                'party' => $partyId,
            ],
        );

        $documents = Invoice::query()
            ->where('entity_id', $entityId)
            ->where('status', 'issued')
            ->whereIn('document_type', $this->receivableTypes())
            ->where('issue_date', '<=', $day->toDateString())
            ->when($partyId !== null, static fn ($query) => $query->where('party_id', $partyId))
            // The customer's name comes from the frozen snapshot and falls
            // back to the party, so the relation is loaded once rather than
            // per document - an N+1 no functional check would notice.
            ->with('party')
            /*
             | The open balance comes from the database function, which is
             | the ONE definition of it - gross, less credit notes, less
             | allocations, less what a difference relieved, less what was
             | written off. A sum computed here would be a sixth opinion,
             | and the five terms have already drifted once when they were
             | kept in step by hand.
             */
            ->selectRaw("invoices.*, peakflow_document_open_balance('invoice', invoices.id)::text AS open_balance")
            ->orderBy('issue_date')
            ->orderBy('number')
            ->get();

        $rows = [];
        $buckets = [];
        $totals = [];
        $currencies = [];

        foreach ($documents as $document) {
            $currency = (string) $document->currency;
            $open = Money::of((string) $document->open_balance, $currency);

            if ($open->isZero()) {
                continue;
            }

            $currencies[$currency] = true;

            foreach ($this->instalmentsOf($document, $basis, $open) as $instalment) {
                /** @var Money $amount */
                $amount = $instalment['amount'];

                if ($amount->isZero()) {
                    continue;
                }

                $age = (int) Carbon::parse((string) $instalment['aged_from'])->diffInDays($day, absolute: false);
                $bucket = $this->bucketFor($age, $boundaries);

                $rows[] = [
                    'document' => (string) $document->number,
                    'document_id' => (string) $document->getKey(),
                    'document_type' => $document->document_type->value,
                    'is_credit' => $amount->isNegative(),
                    'customer' => (string) ($document->customerName() ?? $document->party_id),
                    'party_id' => (string) $document->party_id,
                    'currency' => $currency,
                    'instalment' => $instalment['sequence'],
                    'instalments' => $instalment['of'],
                    'aged_from' => (string) $instalment['aged_from'],
                    'age_days' => $age,
                    'bucket' => $bucket,
                    'outstanding' => (string) $amount->amount,
                ];

                $buckets[$currency] ??= $this->emptyBuckets($currency, $boundaries);
                $buckets[$currency][$bucket] = $buckets[$currency][$bucket]->plus($amount);
                $totals[$currency] = ($totals[$currency] ?? Money::zero($currency))->plus($amount);
            }
        }

        $documentTotals = $totals;

        /*
         | Unapplied receipts, as negative buckets in the same grid.
         |
         | Re-bucketed from the items rather than taken from PF-167's own
         | bucket set, because that set begins at "0-30" and an aging
         | report's first bucket is "current": a receipt banked this
         | morning is not thirty days of anything, and merging the two
         | would put today's money in the same column as last month's.
         */
        $unappliedCash = $this->unappliedAsCredits(
            $entityId,
            $functionalCurrency,
            $day,
            $boundaries,
            $partyId,
            'receipt',
        );

        foreach ($unappliedCash['buckets'] as $bucket => $amount) {
            $buckets[$functionalCurrency] ??= $this->emptyBuckets($functionalCurrency, $boundaries);
            $buckets[$functionalCurrency][$bucket] = $buckets[$functionalCurrency][$bucket]->plus($amount);
            $totals[$functionalCurrency] = ($totals[$functionalCurrency] ?? Money::zero($functionalCurrency))
                ->plus($amount);
        }

        usort($rows, static fn (array $a, array $b): int => [$b['age_days'], $a['document'], $a['instalment']]
            <=> [$a['age_days'], $b['document'], $b['instalment']]);

        $reconciliation = $this->reconcile(
            $entityId,
            ControlRole::AccountsReceivable,
            $documentTotals,
            array_keys($currencies),
            $functionalCurrency,
            $day,
            $partyId,
            'receivable',
        );

        return [
            'side' => 'receivable',
            'as_of' => $day->toDateString(),
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'basis' => $basis,
            'basis_label' => $this->basisLabel($basis),
            'boundaries' => $boundaries,
            'bucket_names' => array_keys($this->emptyBuckets($functionalCurrency, $boundaries)),
            'rows' => $rows,
            'buckets' => $this->flatten($buckets),
            'totals' => array_map(static fn (Money $m): string => (string) $m->amount, $totals),
            'document_totals' => array_map(static fn (Money $m): string => (string) $m->amount, $documentTotals),
            'by_party' => $this->byParty($rows, $boundaries),
            'unapplied_cash' => $unappliedCash['stated'],
            'reconciliation' => $reconciliation,
            'dso' => $this->daysSalesOutstanding(
                $entityId,
                $functionalCurrency,
                $day->copy()->subDays(89),
                $day,
            ),
            'declaration' => $this->declare(
                'receivable',
                $basis,
                $boundaries,
                $rows,
                $reconciliation,
                $unappliedCash['stated'],
                $functionalCurrency,
            ),
        ];
    }

    /**
     * What we owe suppliers, by age.
     *
     * The figures are {@see PayablesAging}'s, which is PF-144's report and
     * already does the hard part. What is added here is what PF-190 asks
     * for and that service does not have: the configurable boundaries
     * stated on the output, unapplied payments as negative buckets, DPO
     * beside the aging rather than in a separate call, and a declaration
     * that states the reconciliation instead of leaving it in a sub-array.
     *
     * @param  'due_date'|'document_date'|null  $basis
     * @param  list<int>|null  $boundaries
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function payables(
        string $entityId,
        string $functionalCurrency,
        ?Carbon $asOf = null,
        ?string $basis = null,
        ?array $boundaries = null,
        ?string $partyId = null,
    ): array {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $basis = $this->basis($entityId, $basis);
        $boundaries = $this->boundaries($entityId, $boundaries);

        $parameters = ReportParameters::for(
            report: 'payables_aging',
            entityId: $entityId,
            from: $day,
            until: $day,
            currency: $functionalCurrency,
            filters: [
                'basis' => $basis,
                'buckets' => implode('/', $boundaries),
                'party' => $partyId,
            ],
        );

        $report = $this->payables->report($entityId, $day, $basis, $partyId, $boundaries);

        /** @var list<array<string, mixed>> $rows */
        $rows = $report['rows'];

        $unappliedPayments = $this->unappliedAsCredits(
            $entityId,
            $functionalCurrency,
            $day,
            $boundaries,
            $partyId,
            'disbursement',
        );

        $buckets = [];

        foreach ($report['buckets'] as $currency => $set) {
            foreach ($set as $bucket => $amount) {
                $buckets[(string) $currency][(string) $bucket] = Money::of(
                    (string) $amount,
                    (string) $currency,
                );
            }
        }

        $totals = [];

        foreach ($report['totals'] as $currency => $amount) {
            $totals[(string) $currency] = Money::of((string) $amount, (string) $currency);
        }

        foreach ($unappliedPayments['buckets'] as $bucket => $amount) {
            $buckets[$functionalCurrency] ??= $this->emptyBuckets($functionalCurrency, $boundaries);
            $buckets[$functionalCurrency][$bucket] = $buckets[$functionalCurrency][$bucket]->plus($amount);
            $totals[$functionalCurrency] = ($totals[$functionalCurrency] ?? Money::zero($functionalCurrency))
                ->plus($amount);
        }

        /** @var array<string, mixed> $reconciliation */
        $reconciliation = $report['reconciliation'];

        return [
            'side' => 'payable',
            'as_of' => $day->toDateString(),
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'basis' => $basis,
            'basis_label' => $this->basisLabel($basis),
            'boundaries' => $boundaries,
            'bucket_names' => array_keys($this->emptyBuckets($functionalCurrency, $boundaries)),
            'rows' => $rows,
            'buckets' => $this->flatten($buckets),
            'totals' => array_map(static fn (Money $m): string => (string) $m->amount, $totals),
            'document_totals' => $report['totals'],
            'by_party' => $report['by_supplier'],
            'unapplied_cash' => $unappliedPayments['stated'],
            'reconciliation' => $reconciliation,
            /*
             | DPO is PF-144's, so there is one of it - and it is NOT the
             | mirror of the DSO above.
             |
             | {@see PayablesAging::daysPayableOutstanding()} sums
             | `bills.net_amount` across every currency and reads the
             | control account from `journal_lines.amount`, which is the
             | document-currency column. On single-currency books the two
             | ratios are comparable; on books with a foreign-currency
             | bill, DPO adds 1,000 USD to 1,000 SAR and DSO refuses to.
             |
             | Not corrected here, because a second DPO in the reporting
             | module would be a second answer to "how long do we take to
             | pay" and both would be quoted. It belongs in PF-144's
             | service, with its own check, and it is recorded here rather
             | than left for whoever first compares the two numbers.
             */
            'dpo' => $this->payables->daysPayableOutstanding(
                $entityId,
                $day->copy()->subDays(89),
                $day,
            ) + ['formula' => 'payables / net purchases in the window x days in the window'],
            'declaration' => $this->declare(
                'payable',
                $basis,
                $boundaries,
                $rows,
                $reconciliation,
                $unappliedPayments['stated'],
                $functionalCurrency,
            ),
        ];
    }

    /**
     * Days sales outstanding, with the formula and the window stated.
     *
     * `(receivables / credit sales in the window) x days in the window`.
     * The window matters and is stated: DSO over a quarter and DSO over a
     * year are different numbers, and a ratio quoted without its window is
     * a ratio nobody can reproduce.
     *
     * Returns null rather than zero where there were no sales. A DSO of
     * zero reads as "our customers pay immediately", which is the opposite
     * of what an empty window means.
     *
     * @return array<string, mixed>
     */
    public function daysSalesOutstanding(
        string $entityId,
        string $functionalCurrency,
        Carbon $from,
        Carbon $to,
    ): array {
        $days = (int) $from->diffInDays($to, absolute: true) + 1;

        /*
         | One currency, named.
         |
         | Sales in USD divided into a receivable balance in SAR is a
         | number with no meaning that still prints to one decimal place.
         | The books are kept in the functional currency and the control
         | account is in it, so that is the only currency this ratio can
         | honestly be taken in - and documents in others are counted out
         | and reported rather than converted at today's rate.
         */
        $sales = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT
                coalesce(sum(net_amount) FILTER (WHERE document_type <> '381'), 0)::text AS invoiced,
                coalesce(sum(net_amount) FILTER (WHERE document_type = '381'), 0)::text AS credited,
                count(*) FILTER (WHERE currency <> ?) AS other_currency
            FROM invoices
            WHERE entity_id = cast(? AS char(26))
              AND status = 'issued'
              AND currency = ?
              AND issue_date BETWEEN cast(? AS date) AND cast(? AS date)
        SQL, [
            $functionalCurrency,
            $entityId,
            $functionalCurrency,
            $from->toDateString(),
            $to->toDateString(),
        ]);

        $net = BigDecimal::of((string) ($sales->invoiced ?? '0'))
            ->minus((string) ($sales->credited ?? '0'));

        $window = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'currency' => $functionalCurrency,
            'formula' => 'receivables / credit sales in the window x days in the window',
            'sales' => (string) $net,
        ];

        if (! $net->isPositive()) {
            return array_merge($window, [
                'dso' => null,
                'why' => 'No credit sales in this window, so there is nothing to divide by. A DSO of '.
                    'zero would read as "our customers pay immediately", which is the opposite of what '.
                    'an empty window means.',
            ]);
        }

        $receivables = $this->controlAccountBalance(
            $entityId,
            ControlRole::AccountsReceivable,
            $functionalCurrency,
            $to,
        );

        return array_merge($window, [
            'receivables' => (string) $receivables->amount,
            'dso' => (string) BigDecimal::of((string) $receivables->amount)
                ->dividedBy($net, 6, RoundingMode::HalfUp)
                ->multipliedBy((string) $days)
                ->toScale(1, RoundingMode::HalfUp),
            'why' => sprintf(
                'The receivables balance on %s divided by %s of credit sales over %d days. The balance '.
                'is the control account\'s and the sales are the invoices issued in the window, both in '.
                '%s - a ratio taken across currencies would print to one decimal place and mean '.
                'nothing.',
                $to->toDateString(),
                (string) $net,
                $days,
                $functionalCurrency,
            ),
        ]);
    }

    /**
     * Days payable outstanding - PF-144's, so there is one of it.
     *
     * @return array<string, mixed>
     */
    public function daysPayableOutstanding(string $entityId, Carbon $from, Carbon $to): array
    {
        return $this->payables->daysPayableOutstanding($entityId, $from, $to)
            + ['formula' => 'payables / net purchases in the window x days in the window'];
    }

    /**
     * Which document types are a receivable, from the enum rather than a list.
     *
     * A hard-coded list of codes is a list that goes stale: the Overview
     * tile's `IN ('380','383','386')` omits a partial invoice and a
     * corrected one, both of which are money a customer owes. Asking the
     * enum means a type added later joins this report, and the
     * reconciliation to the control account is what proves the set is
     * right - a type wrongly in or out shows up as a difference rather
     * than as a quietly smaller total.
     *
     * The self-billed document is out because it posts as a purchase.
     *
     * @return list<string>
     */
    private function receivableTypes(): array
    {
        $types = [];

        foreach (DocumentType::cases() as $type) {
            if (! $type->isPurchaseSide()) {
                $types[] = $type->value;
            }
        }

        return $types;
    }

    /**
     * One document's instalments, each with its own aging date.
     *
     * One entry for a document on simple terms, one per instalment for a
     * document on 30/60/90. The amounts come from the frozen snapshot, so
     * a report run next month ages the document exactly as it aged it this
     * month - and a change of terms afterwards does not restate an aging
     * somebody has already chased money against.
     *
     * The payment is applied to the EARLIEST instalment first, which is
     * how a customer's payment is applied unless they say otherwise. The
     * alternative - spreading it proportionally - leaves every instalment
     * partly unpaid and every one of them in a different bucket. This is
     * the same rule {@see PayablesAging::instalmentsOf()} applies on the
     * other side; a change to it belongs in both.
     *
     * @return list<array{sequence: int, of: int, aged_from: string, amount: Money}>
     */
    private function instalmentsOf(Invoice $document, string $basis, Money $open): array
    {
        $currency = (string) $document->currency;

        if ($document->document_type->isCredit()) {
            /*
             | A credit note is one row, aged from its own date, negative.
             |
             | It has no payment terms - nothing is due on a credit - and
             | it is NEGATIVE because it reduces what the customer owes. A
             | credit shown as a positive balance is a customer the report
             | says owes money when they are owed it.
             |
             | A credit note that names an invoice is already subtracted
             | from that invoice's open balance by the one definition, so
             | it reaches this method only when it stands alone - which is
             | a credit balance on the account and exactly what PF-190
             | asks to see as a negative bucket.
             */
            return [[
                'sequence' => 1,
                'of' => 1,
                'aged_from' => $document->issue_date->toDateString(),
                'amount' => $open->negated(),
            ]];
        }

        if ($basis === 'document_date') {
            /*
             | The document basis has one row per document, by definition.
             |
             | "How old is this balance" is a question about the document,
             | not about its instalments: it was issued on one date
             | whatever the terms said afterwards.
             */
            return [[
                'sequence' => 1,
                'of' => 1,
                'aged_from' => $document->issue_date->toDateString(),
                'amount' => $open,
            ]];
        }

        $dueDates = $document->dueDates();

        if ($dueDates === []) {
            /*
             | No frozen terms: aged from the issue date.
             |
             | The honest fallback. A document with no terms is due on
             | receipt in most jurisdictions, and inventing a due date
             | here would make this report disagree with the dunning
             | schedule, which reads the same snapshot.
             */
            return [[
                'sequence' => 1,
                'of' => 1,
                'aged_from' => $document->issue_date->toDateString(),
                'amount' => $open,
            ]];
        }

        $remaining = $open;
        $count = \count($dueDates);
        $instalments = [];

        foreach ($dueDates as $index => $due) {
            if (! \is_array($due)) {
                continue;
            }

            $share = isset($due['amount']) && $due['amount'] !== null
                ? Money::of((string) $due['amount'], $currency)
                : $document->gross()->multipliedBy(
                    (string) BigDecimal::of((string) ($due['proportion'] ?? '1')),
                    RoundingPolicy::halfUp(Scale::Amount),
                );

            $unpaid = $remaining->isGreaterThan($share) ? $share : $remaining;
            $remaining = $remaining->minus($unpaid);

            $instalments[] = [
                'sequence' => (int) ($due['sequence'] ?? $index + 1),
                'of' => $count,
                'aged_from' => (string) $due['date'],
                'amount' => $unpaid,
            ];

            if ($remaining->isZero()) {
                break;
            }
        }

        return $instalments;
    }

    /**
     * Unapplied cash, re-bucketed into this report's grid, as credits.
     *
     * @param  list<int>  $boundaries
     * @param  'receipt'|'disbursement'  $direction
     * @return array{buckets: array<string, Money>, stated: array<string, mixed>}
     */
    private function unappliedAsCredits(
        string $entityId,
        string $currency,
        Carbon $day,
        array $boundaries,
        ?string $partyId,
        string $direction,
    ): array {
        $ageing = $this->unapplied->ageing($entityId, $currency, $day->toDateString(), $direction);

        $buckets = $this->emptyBuckets($currency, $boundaries);
        $total = Money::zero($currency);
        $items = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = $ageing['items'];

        foreach ($rows as $item) {
            if ($partyId !== null && (string) ($item['party_id'] ?? '') !== $partyId) {
                continue;
            }

            /** @var Money $amount */
            $amount = $item['unapplied'];
            $credit = $amount->negated();
            $age = (int) $item['age_days'];
            $bucket = $this->bucketFor($age, $boundaries);

            $buckets[$bucket] = $buckets[$bucket]->plus($credit);
            $total = $total->plus($credit);

            $items[] = [
                'payment_id' => (string) $item['payment_id'],
                'number' => (string) $item['number'],
                'value_date' => (string) $item['value_date'],
                'age_days' => $age,
                'bucket' => $bucket,
                'party_id' => $item['party_id'] === null ? null : (string) $item['party_id'],
                'party' => $item['party_name'] === null ? null : (string) $item['party_name'],
                'amount' => (string) $credit->amount,
            ];
        }

        return [
            'buckets' => $buckets,
            'stated' => [
                'direction' => $direction,
                'total' => (string) $total->amount,
                'items' => $items,
                'unidentified_payers' => (int) $ageing['unidentified_payers'],
                'why' => $items === []
                    ? 'Nothing is unapplied on this side.'
                    : sprintf(
                        '%s of %s nobody has allocated, shown as negative buckets rather than netted '.
                        'into what is owed. A customer with an old invoice and an unallocated receipt '.
                        'of the same size is not a customer who owes nothing: somebody has to work out '.
                        'which invoice the money was for, and netting them makes the old invoice '.
                        'disappear from the one report that would have made anybody ask. It sits in its '.
                        'own control account, so it is not part of the reconciliation below.',
                        (string) $total->amount,
                        $direction === 'receipt' ? 'customer money we are holding' : 'our money a supplier holds',
                    ),
            ],
        ];
    }

    /**
     * The report's disagreement with the ledger, computed by the report.
     *
     * @param  array<string, Money>  $totals
     * @param  list<string>  $currencies
     * @return array<string, mixed>
     */
    private function reconcile(
        string $entityId,
        ControlRole $role,
        array $totals,
        array $currencies,
        string $functionalCurrency,
        Carbon $asOf,
        ?string $partyId,
        string $side,
    ): array {
        if ($partyId !== null) {
            return [
                'checked' => false,
                'why' => 'The report is filtered to one party and the control account carries every '.
                    'party. A partial total cannot reconcile to a whole balance, and reporting the '.
                    'difference would report a filter as a defect.',
            ];
        }

        $foreign = array_values(array_filter(
            $currencies,
            static fn (string $currency): bool => $currency !== $functionalCurrency,
        ));

        if ($foreign !== []) {
            return [
                'checked' => false,
                'why' => sprintf(
                    'The report holds documents in %s and the control account is in %s. Comparing them '.
                    'would report an exchange rate as a difference, and translating them needs each '.
                    'document\'s rate on its own tax point.',
                    implode(', ', $foreign),
                    $functionalCurrency,
                ),
            ];
        }

        $agingTotal = $totals[$functionalCurrency] ?? Money::zero($functionalCurrency);
        $control = $this->controlAccountBalance($entityId, $role, $functionalCurrency, $asOf);
        $difference = $agingTotal->minus($control);

        return [
            'checked' => true,
            'currency' => $functionalCurrency,
            'aging_total' => (string) $agingTotal->amount,
            'control_account' => (string) $control->amount,
            'difference' => (string) $difference->amount,
            'agrees' => $difference->isZero(),
            'why' => $difference->isZero()
                ? sprintf(
                    'The open documents come to the %s control account exactly, so the aging and the '.
                    'ledger are the same number taken two ways.',
                    $side,
                )
                : sprintf(
                    'The aging total and the %s control account disagree by %s. One of them is wrong '.
                    'and both are used to chase money: the usual causes are a document issued and '.
                    'never posted, a journal posted straight to the control account, or a payment '.
                    'recorded against the ledger and not against the document.',
                    $side,
                    (string) $difference->amount,
                ),
        ];
    }

    /**
     * A control account's balance, as a positive amount owed.
     *
     * ## Posted and reversed only
     *
     * A draft entry has lines, and those lines are in no balance until it
     * is posted. Counting them here would make this reconciliation report
     * a difference that is somebody's unfinished journal - the report
     * accusing the ledger of a defect that is a draft.
     *
     * ## The functional amount, not the document one
     *
     * `amount` is what the line was denominated in. Summing it across a
     * line in USD and a line in SAR adds two different things and produces
     * a number that balances against nothing. The caller refuses the
     * comparison when foreign-currency documents are present; this column
     * is the one that would be right even if it did not.
     */
    private function controlAccountBalance(
        string $entityId,
        ControlRole $role,
        string $currency,
        Carbon $asOf,
    ): Money {
        $sum = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(l.functional_amount), 0)::text AS balance
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN account_roles r ON r.account_id = l.account_id AND r.entity_id = l.entity_id
            WHERE l.entity_id = cast(? AS char(26))
              AND r.role = ?
              AND e.status IN ('posted', 'reversed')
              AND l.accounting_date <= cast(? AS date)
        SQL, [$entityId, $role->value, $asOf->toDateString()]);

        $balance = Money::of((string) ($sum->balance ?? '0'), $currency);

        /*
         | A payable is a credit balance and is talked about as a positive
         | number owed; a receivable is a debit and already is one. The
         | ledger stores one signed column, so exactly one of the two is
         | negated - and doing it by role rather than by sign means an
         | account that happens to be the other way round is reported as
         | what it is rather than silently flipped.
         */
        return $role === ControlRole::AccountsPayable ? $balance->negated() : $balance;
    }

    /**
     * The basis, from the argument or the entity's setting.
     *
     * @throws ReportRefused
     */
    private function basis(string $entityId, ?string $basis): string
    {
        $basis ??= (string) $this->settings->get('aging.basis', $entityId);

        if (! \in_array($basis, ['due_date', 'document_date'], true)) {
            throw ReportRefused::because(sprintf(
                '"%s" is not an aging basis. It is the due date or the document date, and the two give '.
                'different answers - which is why the report states which it used rather than picking '.
                'something that looks reasonable.',
                $basis,
            ));
        }

        return $basis;
    }

    /**
     * The boundaries, from the argument or the entity's setting, checked.
     *
     * @param  list<int>|null  $boundaries
     * @return list<int>
     *
     * @throws ReportRefused
     */
    private function boundaries(string $entityId, ?array $boundaries): array
    {
        if ($boundaries === null) {
            $setting = $this->settings->get('aging.buckets', $entityId);
            $boundaries = \is_array($setting) ? array_values($setting) : self::DEFAULT_BOUNDARIES;
        }

        $checked = [];
        $previous = 0;

        foreach ($boundaries as $boundary) {
            if (! is_numeric($boundary) || (int) $boundary !== (int) (string) $boundary) {
                throw ReportRefused::because(sprintf(
                    'The aging boundaries must be whole numbers of days and "%s" is not one. A '.
                    'boundary that cannot be compared to an age would put every balance in the last '.
                    'bucket.',
                    (string) $boundary,
                ));
            }

            $value = (int) $boundary;

            if ($value <= $previous) {
                /*
                 | Strictly ascending, or a bucket is empty by construction.
                 |
                 | 30, 30, 60 produces a "31-30" bucket that nothing can
                 | ever fall into, and the report prints a column of zeros
                 | that reads as "nothing is that old".
                 */
                throw ReportRefused::because(sprintf(
                    'The aging boundaries have to ascend, and %s does not. A repeated or decreasing '.
                    'boundary makes a bucket nothing can fall into, and a column of zeros reads as '.
                    '"nothing is that old".',
                    implode(', ', array_map(static fn (mixed $b): string => (string) $b, $boundaries)),
                ));
            }

            $checked[] = $value;
            $previous = $value;
        }

        if ($checked === []) {
            throw ReportRefused::because(
                'An aging report with no boundaries has one bucket, which is a list of open documents '.
                'and not an aging. Set `aging.buckets`, or leave it unset for 30/60/90.',
            );
        }

        if (\count($checked) > self::MAX_BOUNDARIES) {
            throw ReportRefused::because(sprintf(
                '%d boundaries make %d buckets, and a grid that wide is not read - it is scrolled '.
                'past. At most %d.',
                \count($checked),
                \count($checked) + 2,
                self::MAX_BOUNDARIES,
            ));
        }

        return $checked;
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

    /**
     * @param  array<string, array<string, Money>>  $buckets
     * @return array<string, array<string, string>>
     */
    private function flatten(array $buckets): array
    {
        return array_map(
            static fn (array $set): array => array_map(
                static fn (Money $amount): string => (string) $amount->amount,
                $set,
            ),
            $buckets,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $boundaries
     * @return list<array<string, mixed>>
     */
    private function byParty(array $rows, array $boundaries): array
    {
        $parties = [];

        foreach ($rows as $row) {
            $party = (string) $row['party_id'];
            $currency = (string) $row['currency'];

            $parties[$party] ??= [
                'party_id' => $party,
                'party' => (string) $row['customer'],
                'buckets' => [],
                'totals' => [],
            ];

            $parties[$party]['buckets'][$currency] ??= $this->emptyBuckets($currency, $boundaries);
            $amount = Money::of((string) $row['outstanding'], $currency);

            $parties[$party]['buckets'][$currency][(string) $row['bucket']] =
                $parties[$party]['buckets'][$currency][(string) $row['bucket']]->plus($amount);

            $parties[$party]['totals'][$currency] = ($parties[$party]['totals'][$currency]
                ?? Money::zero($currency))->plus($amount);
        }

        return array_values(array_map(fn (array $party): array => [
            'party_id' => $party['party_id'],
            'party' => $party['party'],
            'buckets' => $this->flatten($party['buckets']),
            'totals' => array_map(static fn (Money $m): string => (string) $m->amount, $party['totals']),
        ], $parties));
    }

    private function basisLabel(string $basis): string
    {
        return $basis === 'due_date'
            ? 'aged from each instalment\'s own due date, so a document on 30/60/90 terms appears in '.
                'three buckets rather than being aged from the last of them'
            : 'aged from the document\'s own date, which answers how old the balance is rather than '.
                'what is overdue';
    }

    /**
     * What the reader is told before any figure.
     *
     * @param  list<int>  $boundaries
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $reconciliation
     * @param  array<string, mixed>  $unapplied
     * @return array<string, string>
     */
    private function declare(
        string $side,
        string $basis,
        array $boundaries,
        array $rows,
        array $reconciliation,
        array $unapplied,
        string $currency,
    ): array {
        $stated = sprintf(
            'Aged on the %s basis - %s - into buckets dividing at %s days. The two bases give different '.
            'answers, so a report that did not say which it used would be read two ways and believed '.
            'both times.',
            str_replace('_', ' ', $basis),
            $this->basisLabel($basis),
            implode(', ', array_map(static fn (int $b): string => (string) $b, $boundaries)),
        );

        /*
         | The reconciliation is in the DECLARATION, not only in its own
         | sub-array - PF-190.
         |
         | A difference between the aging and the control account is the
         | one thing on this report that means somebody must stop. Left in
         | a nested key, it is found by whoever thinks to look, which is
         | nobody: the figures above it look complete.
         */
        if (($reconciliation['checked'] ?? false) === true && ($reconciliation['agrees'] ?? false) !== true) {
            return [
                'severity' => 'broken',
                'en' => sprintf(
                    'THIS AGING DOES NOT RECONCILE TO THE LEDGER. The open documents come to %s and the '.
                    '%s control account says %s, a difference of %s. One of them is wrong and both are '.
                    'used to chase money. %s',
                    (string) $reconciliation['aging_total'],
                    $side,
                    (string) $reconciliation['control_account'],
                    (string) $reconciliation['difference'],
                    $stated,
                ),
                'ar' => sprintf(
                    'هذا التقادم لا يطابق الدفتر: الفرق %s عن حساب المراقبة. أحدهما خطأ، وكلاهما يُستخدم '.
                    'في المطالبة بالمال.',
                    (string) $reconciliation['difference'],
                ),
            ];
        }

        $reconciled = ($reconciliation['checked'] ?? false) === true
            ? 'It reconciles to the '.$side.' control account exactly.'
            : 'The reconciliation was not attempted: '.(string) ($reconciliation['why'] ?? '');

        $credits = (string) ($unapplied['total'] ?? '0');

        return [
            'severity' => ($reconciliation['checked'] ?? false) === true ? 'ok' : 'attention',
            'en' => sprintf(
                '%d open %s%s. %s %s%s',
                \count($rows),
                $side === 'receivable' ? 'receivable line' : 'payable line',
                \count($rows) === 1 ? '' : 's',
                $reconciled,
                $stated,
                Money::of($credits, $currency)->isZero()
                    ? ''
                    : ' Unapplied cash of '.$credits.' is shown as negative buckets rather than netted '.
                        'into what is owed.',
            ),
            'ar' => sprintf(
                '%d سطرًا مفتوحًا. %s الأساس: %s، والفئات تنقسم عند %s يومًا.',
                \count($rows),
                ($reconciliation['checked'] ?? false) === true
                    ? 'ويطابق حساب المراقبة تمامًا.'
                    : 'ولم تُجرَ المطابقة مع حساب المراقبة.',
                str_replace('_', ' ', $basis),
                implode('، ', array_map(static fn (int $b): string => (string) $b, $boundaries)),
            ),
        ];
    }
}
