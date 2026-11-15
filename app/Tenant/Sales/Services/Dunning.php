<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Services\CreditControl;
use App\Tenant\Sales\Models\DunningEvent;
use App\Tenant\Sales\Models\DunningNotice;
use App\Tenant\Sales\Models\DunningPolicy;
use App\Tenant\Sales\Models\DunningPolicyStep;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\PartyCollections;
use App\Tenant\Tenancy\Models\Entity;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Chasing what is owed - PF-108, PF-109.
 *
 * ## Evaluated per invoice, sent per customer
 *
 * The step that applies is a property of an invoice: how far past due it is,
 * under the policy that customer has. What gets sent is a property of the
 * customer: one statement listing every invoice that reached a step today.
 *
 * Getting this the other way round is the common failure, and it is not a
 * cosmetic one - a customer who receives six emails from a supplier in one
 * morning telephones about the emails rather than about the money, and the
 * conversation that follows is about the supplier's systems.
 *
 * ## A dispute stops the escalation and nothing else
 *
 * The invoice is still owed, still counts towards exposure and still ages.
 * What stops is the chasing, because sending a fourth reminder about an
 * invoice the customer has formally queried is how a commercial relationship
 * ends over an administrative error. Writing it off or excluding it from
 * exposure would be the easy implementation and would let a customer keep
 * buying by disputing everything.
 *
 * ## The business calendar
 *
 * A reminder is not sent on a day the customer's office is shut: it lands at
 * the bottom of an inbox nobody reads until Sunday, and the step has been
 * spent. The run moves it to the next business day, which means a step may
 * fire a day or two after its nominal offset - and the event records the
 * actual day, because "we sent it on the 30th" is a question somebody asks.
 */
final class Dunning
{
    public function __construct(
        private readonly CreditControl $credit,
        private readonly Corrections $corrections,
        private readonly AuditTrail $audit,
        private readonly \App\Tenant\Catalog\Services\DueDateCalculator $dueDates,
    ) {}

    /**
     * The policy that applies to a customer - PF-108.
     *
     * In order: the party's own assignment, then its group's, then the
     * entity default. A party with none and an entity with no default is not
     * chased at all, which is the right answer: a system that invented a
     * policy would start emailing customers nobody set up.
     */
    public function policyFor(string $entityId, Party $party): ?DunningPolicy
    {
        $assigned = DB::connection('tenant')->table('party_dunning_policies')
            ->where('entity_id', $entityId)
            ->where('party_id', (string) $party->getKey())
            ->value('dunning_policy_id');

        if ($assigned !== null) {
            $policy = DunningPolicy::query()->whereKey((string) $assigned)->where('active', true)->first();

            if ($policy !== null) {
                return $policy;
            }
        }

        $group = (string) ($party->party_group ?? '');

        if ($group !== '') {
            $byGroup = DunningPolicy::query()
                ->where('entity_id', $entityId)
                ->where('party_group', $group)
                ->where('active', true)
                ->first();

            if ($byGroup !== null) {
                return $byGroup;
            }
        }

        return DunningPolicy::query()
            ->where('entity_id', $entityId)
            ->where('is_default', true)
            ->where('active', true)
            ->first();
    }

    /**
     * Run the policies for an entity, as of a date - PF-108.
     *
     * @return array{
     *     date: string,
     *     notices: list<array{party: string, invoices: int, total: string, step: int, channel: string}>,
     *     skipped: list<array{invoice: string, why: string}>,
     *     fees: list<array{invoice: string, fee: string, document: string}>,
     *     holds: list<string>,
     *     released: list<string>,
     * }
     */
    public function run(Entity $entity, ?CarbonImmutable $asOf = null): array
    {
        $entityId = (string) $entity->getKey();
        $today = ($asOf ?? CarbonImmutable::now((string) ($entity->timezone ?? 'UTC')))->startOfDay();

        $report = [
            'date' => $today->toDateString(),
            'notices' => [],
            'skipped' => [],
            'fees' => [],
            'holds' => [],
            'released' => [],
        ];

        /*
         | Not on a day the business is shut - PF-108.
         |
         | A reminder sent on a Friday in the Gulf lands at the bottom of an
         | inbox nobody reads until Sunday, and the step has been spent. The
         | run does nothing and says so; the step fires on the next business
         | day, which is why the event records the day it actually happened
         | rather than the offset it was nominally due on.
         */
        if (! $this->dueDates->isBusinessDay($entityId, Carbon::parse($today->toDateString()))) {
            $report['skipped'][] = [
                'invoice' => '-',
                'why' => sprintf(
                    '%s is not a business day for this entity, so nothing was sent',
                    $today->toDateString(),
                ),
            ];

            return $report;
        }

        /*
         | Grouped by customer from the start.
         |
         | Not "every overdue invoice, then group the emails afterwards":
         | the policy is the customer's, the statement is the customer's,
         | and a loop over invoices that sent as it went is how six emails
         | get sent.
         */
        $overdue = $this->overdueByParty($entityId, $today);

        foreach ($overdue as $partyId => $invoices) {
            $party = Party::query()->find((string) $partyId);

            if ($party === null) {
                continue;
            }

            $policy = $this->policyFor($entityId, $party);

            if ($policy === null) {
                foreach ($invoices as $invoice) {
                    $report['skipped'][] = [
                        'invoice' => (string) $invoice['invoice']->number,
                        'why' => 'no dunning policy applies to this customer',
                    ];
                }

                continue;
            }

            $outcome = $this->runFor($entity, $party, $policy, $invoices, $today);

            if ($outcome['notice'] !== null) {
                $report['notices'][] = $outcome['notice'];
            }

            $report['skipped'] = [...$report['skipped'], ...$outcome['skipped']];
            $report['fees'] = [...$report['fees'], ...$outcome['fees']];
            $report['holds'] = [...$report['holds'], ...$outcome['holds']];
        }

        $report['released'] = $this->releaseHolds($entity, array_keys($overdue));

        return $report;
    }

    /**
     * Lift the holds the policy placed and no longer justifies - PF-109.
     *
     * ## Why the release is automatic and the placement is not reversible
     *
     * A hold that has to be lifted by hand is a customer who paid on
     * Thursday and cannot order on Monday, and the person who could lift it
     * is on holiday. So the policy that placed it takes it off as soon as
     * nothing is overdue.
     *
     * ## And only its own
     *
     * A hold a person placed - a credit decision, a legal matter, a customer
     * in administration - is not lifted by a nightly job because an invoice
     * got paid. `source` is how the two are told apart, and `manual` is the
     * default for exactly this reason.
     *
     * @param  list<string>  $stillOverdue  party ids with something overdue today
     * @return list<string>
     */
    private function releaseHolds(Entity $entity, array $stillOverdue): array
    {
        $entityId = (string) $entity->getKey();
        $released = [];

        $held = DB::connection('tenant')->table('party_entity_settings')
            ->where('entity_id', $entityId)
            ->where('credit_hold', true)
            ->pluck('party_id');

        foreach ($held as $partyId) {
            if (\in_array((string) $partyId, $stillOverdue, true)) {
                continue;
            }

            $hold = $this->credit->currentHold($entityId, (string) $partyId);

            if ($hold === null || $hold['source'] !== 'dunning') {
                continue;
            }

            $party = Party::query()->find((string) $partyId);

            if ($party === null) {
                continue;
            }

            $this->credit->hold(
                $entityId,
                $party,
                'dunning: nothing is overdue, so the hold this policy placed is lifted',
                (string) $entity->functional_currency,
                lift: true,
                source: 'dunning',
            );

            $released[] = (string) $party->code;
        }

        return $released;
    }

    /**
     * One customer's run: the step per invoice, one notice, the actions.
     *
     * @param  list<array{invoice: Invoice, due_on: CarbonImmutable, outstanding: Money}>  $invoices
     * @return array{notice: array<string, mixed>|null, skipped: list<array{invoice: string, why: string}>, fees: list<array<string, string>>, holds: list<string>}
     */
    private function runFor(
        Entity $entity,
        Party $party,
        DunningPolicy $policy,
        array $invoices,
        CarbonImmutable $today,
    ): array {
        $entityId = (string) $entity->getKey();
        $steps = $policy->steps()->get();
        $due = [];
        $skipped = [];
        $fees = [];
        $holds = [];

        foreach ($invoices as $candidate) {
            $invoice = $candidate['invoice'];

            /*
             | A disputed invoice stops escalating - PF-108.
             |
             | Still owed, still in the exposure, still ageing. What stops
             | is the chasing.
             */
            if ($this->credit->dunningSuspended($entityId, 'invoice', (string) $invoice->getKey())) {
                $skipped[] = [
                    'invoice' => (string) $invoice->number,
                    'why' => 'the invoice is disputed, so it escalates no further and appears on the dispute report',
                ];

                continue;
            }

            $daysPastDue = (int) $candidate['due_on']->diffInDays($today, false);
            $decision = $this->stepFor($steps, $daysPastDue, $invoice);
            $step = $decision['step'];

            if ($step === null) {
                /*
                 | Why nothing happened, recorded - PF-108.
                 |
                 | This was a silent `continue`, and the check that caught it
                 | is right: a run that sends nothing and says nothing cannot
                 | be told from a run that is broken. Whoever is asked "did
                 | the chasing go out this morning?" has the answer in the
                 | report rather than in the code.
                 */
                $skipped[] = [
                    'invoice' => (string) $invoice->number,
                    'why' => $decision['why'],
                ];

                continue;
            }

            $due[] = ['invoice' => $invoice, 'step' => $step, 'days' => (int) $daysPastDue, 'outstanding' => $candidate['outstanding']];
        }

        if ($due === []) {
            return ['notice' => null, 'skipped' => $skipped, 'fees' => [], 'holds' => []];
        }

        $currency = (string) $due[0]['outstanding']->currency;
        $total = Money::zero($currency);
        $highest = 0;
        $channel = 'email';

        foreach ($due as $item) {
            $total = $total->plus($item['outstanding']);
            $highest = max($highest, (int) $item['step']->sequence);

            if ((int) $item['step']->sequence === $highest) {
                $channel = (string) $item['step']->channel;
            }
        }

        return DB::connection('tenant')->transaction(function () use (
            $entity, $entityId, $party, $due, $skipped, $today, $currency, $total, $highest, $channel, &$fees, &$holds
        ): array {
            /*
             | One notice per customer per day.
             |
             | Claimed first, so the events that follow have something to
             | point at - and a second run on the same day collides here
             | rather than halfway through sending.
             */
            try {
                $notice = DunningNotice::create([
                    'entity_id' => $entityId,
                    'party_id' => (string) $party->getKey(),
                    'notice_date' => $today->toDateString(),
                    'channel' => $channel,
                    'highest_sequence' => $highest,
                    'invoice_count' => \count($due),
                    'currency' => $currency,
                    'total_due' => (string) $total->amount,
                ]);
            } catch (UniqueConstraintViolationException) {
                return [
                    'notice' => null,
                    'skipped' => [...$skipped, [
                        'invoice' => (string) $party->code,
                        'why' => 'this customer has already been sent a notice today',
                    ]],
                    'fees' => [],
                    'holds' => [],
                ];
            }

            $written = 0;

            foreach ($due as $item) {
                /** @var Invoice $invoice */
                $invoice = $item['invoice'];
                /** @var DunningPolicyStep $step */
                $step = $item['step'];

                try {
                    $event = DunningEvent::create([
                        'entity_id' => $entityId,
                        'invoice_id' => (string) $invoice->getKey(),
                        'dunning_policy_step_id' => (string) $step->getKey(),
                        'dunning_notice_id' => (string) $notice->getKey(),
                        'party_id' => (string) $party->getKey(),
                        'occurred_on' => $today->toDateString(),
                        'days_past_due' => $item['days'],
                        'amount_due' => (string) $item['outstanding']->amount,
                        'currency' => $currency,
                        'actions_taken' => [],
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // This invoice already had this step today. Not an
                    // error: it is the second firing finding the first.
                    $skipped[] = [
                        'invoice' => (string) $invoice->number,
                        'why' => 'this step has already run for this invoice today',
                    ];

                    continue;
                }

                $written++;
                $taken = [];

                if ($step->does('late_fee')) {
                    $fee = $this->chargeLateFee($invoice, $step, $item['outstanding'], $today);

                    if ($fee !== null) {
                        $taken[] = 'late_fee';
                        $event->update(['fee_invoice_id' => (string) $fee->getKey()]);

                        $fees[] = [
                            'invoice' => (string) $invoice->number,
                            'fee' => (string) $fee->gross_amount,
                            'document' => (string) ($fee->number ?? '(draft)'),
                        ];
                    }
                }

                if ($step->does('credit_hold')) {
                    $this->credit->hold(
                        $entityId,
                        $party,
                        sprintf(
                            'dunning step %d: %s is %d days past due',
                            (int) $step->sequence,
                            (string) $invoice->number,
                            $item['days'],
                        ),
                        (string) $entity->functional_currency,
                        source: 'dunning',
                    );

                    $taken[] = 'credit_hold';
                    $holds[] = (string) $party->code;
                }

                if ($step->does('to_collections')) {
                    PartyCollections::query()->updateOrCreate(
                        ['entity_id' => $entityId, 'party_id' => (string) $party->getKey()],
                        ['in_collections_since' => now()],
                    );

                    $taken[] = 'to_collections';
                }

                if ($taken !== []) {
                    $event->update(['actions_taken' => $taken]);
                }
            }

            if ($written === 0) {
                // Every invoice collided: the notice has nothing to carry,
                // and a notice listing nothing is a statement the customer
                // cannot act on.
                $notice->delete();

                return ['notice' => null, 'skipped' => $skipped, 'fees' => $fees, 'holds' => $holds];
            }

            $notice->update(['invoice_count' => $written, 'sent_at' => now()]);

            $this->audit->record(
                category: 'sales',
                action: 'dunning.notice_sent',
                subjectType: 'party',
                subjectId: (string) $party->getKey(),
                subjectLabel: (string) $party->code,
                after: [
                    'invoices' => $written,
                    'total' => (string) $total->amount,
                    'step' => $highest,
                    'channel' => $channel,
                ],
                entityId: $entityId,
                partyId: (string) $party->getKey(),
            );

            return [
                'notice' => [
                    'party' => (string) $party->code,
                    'invoices' => $written,
                    'total' => (string) $total->amount,
                    'step' => $highest,
                    'channel' => $channel,
                ],
                'skipped' => $skipped,
                'fees' => $fees,
                'holds' => $holds,
            ];
        });
    }

    /**
     * A late fee as its own document - PF-109.
     *
     * A debit note against the overdue invoice, which makes it creditable
     * and disputable like any other charge: a fee bolted onto the original
     * invoice would change a document that has been issued, and a fee held
     * in a column is a charge the customer cannot query.
     */
    public function chargeLateFee(
        Invoice $invoice,
        DunningPolicyStep $step,
        Money $outstanding,
        CarbonImmutable $on,
    ): ?Invoice {
        $percent = $step->late_fee_percent === null ? null : (string) $step->late_fee_percent;
        $minimum = $step->late_fee_minimum === null
            ? null
            : Money::of((string) $step->late_fee_minimum, (string) $outstanding->currency);

        $computed = $percent === null
            ? Money::zero((string) $outstanding->currency)
            : $outstanding->multipliedBy(
                (string) \Brick\Math\BigDecimal::of($percent)->dividedBy('100', 12, \Brick\Math\RoundingMode::HalfUp),
                RoundingPolicy::halfUp(Scale::Amount),
            );

        $fee = $minimum !== null && $minimum->isGreaterThan($computed) ? $minimum : $computed;

        if (! $fee->isPositive()) {
            return null;
        }

        /*
         | Never twice for the same period - PF-109.
         |
         | A fee already charged by this step against this invoice is not
         | charged again, whatever else changed: a customer charged the same
         | fee twice in a month disputes the fee and stops reading the
         | reminders.
         */
        $already = DunningEvent::query()
            ->where('invoice_id', (string) $invoice->getKey())
            ->where('dunning_policy_step_id', (string) $step->getKey())
            ->whereNotNull('fee_invoice_id')
            ->exists();

        if ($already) {
            return null;
        }

        try {
            return $this->corrections->debitNote(
                $invoice,
                [[
                    'description' => sprintf(
                        'Late payment charge on %s: %s of %s outstanding%s',
                        (string) $invoice->number,
                        $percent === null ? 'a fixed fee' : rtrim(rtrim($percent, '0'), '.').'%',
                        (string) $outstanding->amount,
                        $minimum !== null && $minimum->isGreaterThan($computed)
                            ? sprintf(', at the minimum of %s', (string) $minimum->amount)
                            : '',
                    ),
                    'net' => (string) $fee->amount,

                    /*
                     | The basis is in the description, on the document.
                     |
                     | A fee with no arithmetic on it is a number the
                     | customer telephones about, and the person who answers
                     | has to reconstruct it from a policy they have not
                     | read.
                     */
                ]],
                Carbon::parse($on->toDateString()),
            );
        } catch (Throwable) {
            // A fee that cannot be raised - a replaced invoice, a profile
            // that forbids debit notes - is not a reason to stop the rest of
            // the run.
            return null;
        }
    }

    /**
     * The step that applies to an invoice today, or null.
     *
     * The LAST step whose offset has been reached and which has not run for
     * this invoice. Working backwards, because a run that has been down for
     * a week must send the step the invoice has actually reached rather than
     * walking it through four reminders in four minutes.
     *
     * It returns the reason as well as the step, because a step of `null`
     * has three quite different meanings - not due yet, already sent, no
     * steps configured - and a caller that cannot tell them apart reports a
     * quiet morning the same way it reports a broken job.
     *
     * @param  \Illuminate\Support\Collection<int, DunningPolicyStep>  $steps
     * @return array{step: DunningPolicyStep|null, why: string}
     */
    private function stepFor(
        \Illuminate\Support\Collection $steps,
        int $daysPastDue,
        Invoice $invoice,
    ): array {
        if ($steps->isEmpty()) {
            return ['step' => null, 'why' => 'the policy has no steps, so this invoice is never chased'];
        }

        $reached = $steps
            ->filter(static fn (DunningPolicyStep $step): bool => $daysPastDue >= $step->days_past_due)
            ->sortByDesc('sequence');

        if ($reached->isEmpty()) {
            return ['step' => null, 'why' => sprintf(
                'the invoice is %d days past due and the first step is at %d days',
                $daysPastDue,
                (int) $steps->min('days_past_due'),
            )];
        }

        foreach ($reached as $step) {
            $alreadyRun = DunningEvent::query()
                ->where('invoice_id', (string) $invoice->getKey())
                ->where('dunning_policy_step_id', (string) $step->getKey())
                ->exists();

            if ($alreadyRun) {
                // The highest step reached has already been sent, so there
                // is nothing to do until the next offset arrives.
                return ['step' => null, 'why' => sprintf(
                    'step %d was already sent for this invoice, and the next offset has not been reached',
                    (int) $step->sequence,
                )];
            }

            return ['step' => $step, 'why' => ''];
        }

        return ['step' => null, 'why' => 'no step applies'];
    }

    /**
     * The overdue invoices, grouped by customer.
     *
     * @return array<string, list<array{invoice: Invoice, due_on: CarbonImmutable, outstanding: Money}>>
     */
    private function overdueByParty(string $entityId, CarbonImmutable $today): array
    {
        $grouped = [];

        $invoices = Invoice::query()
            ->where('entity_id', $entityId)
            ->where('status', 'issued')
            ->whereIn('document_type', ['380', '383', '384', '326'])
            ->orderBy('issue_date')
            ->get();

        foreach ($invoices as $invoice) {
            $outstanding = $this->outstandingOn($invoice);

            if (! $outstanding->isPositive()) {
                continue;
            }

            $dueOn = $this->dueDateOf($invoice);

            $grouped[(string) $invoice->party_id][] = [
                'invoice' => $invoice,
                'due_on' => $dueOn,
                'outstanding' => $outstanding,
            ];
        }

        return $grouped;
    }

    /**
     * What is still owed on an invoice.
     *
     * The payable amount less anything credited against it. Settlement is
     * Phase 09's subject, so what is paid is not known here yet - and a
     * dunning run that chased paid invoices would be worse than none, which
     * is why `PaymentAllocation` will be read from this method the moment it
     * exists rather than from a second query somewhere else.
     */
    public function outstandingOn(Invoice $invoice): Money
    {
        $currency = (string) $invoice->currency;
        $payable = Money::of((string) $invoice->payable_amount, $currency);

        $credited = DB::connection('tenant')->table('invoice_credit_totals')
            ->where('invoice_id', (string) $invoice->getKey())
            ->value('gross_credited');

        $paid = DB::connection('tenant')->getSchemaBuilder()->hasTable('payment_allocations')
            ? DB::connection('tenant')->table('payment_allocations')
                ->where('invoice_id', (string) $invoice->getKey())
                ->sum('amount')
            : 0;

        return $payable
            ->minus(Money::of((string) ($credited ?: '0'), $currency))
            ->minus(Money::of((string) ($paid ?: '0'), $currency));
    }

    /**
     * When an invoice fell due.
     *
     * From the frozen terms snapshot, which is the whole point of freezing
     * it: the due date is what the document said when it was sent, not what
     * today's payment terms would produce. An instalment plan's last date is
     * the one that matters for chasing - the invoice is not overdue until
     * its final instalment is.
     */
    public function dueDateOf(Invoice $invoice): CarbonImmutable
    {
        $dates = $invoice->dueDates();
        $last = null;

        foreach ($dates as $date) {
            $candidate = CarbonImmutable::parse((string) ($date['date'] ?? ''));

            if ($last === null || $candidate->gt($last)) {
                $last = $candidate;
            }
        }

        return $last ?? CarbonImmutable::parse((string) $invoice->issue_date?->toDateString());
    }

    /**
     * The collections worklist, ordered by what is at risk - PF-109.
     *
     * @return list<array{party: string, owner: string|null, at_risk: string, oldest_days: int, promised_on: string|null, broken_promise: bool}>
     */
    public function worklist(string $entityId, string $currency, ?CarbonImmutable $asOf = null): array
    {
        $today = ($asOf ?? CarbonImmutable::now())->startOfDay();
        $rows = [];

        $overdue = $this->overdueByParty($entityId, $today);

        foreach ($overdue as $partyId => $invoices) {
            $party = Party::query()->find((string) $partyId);

            if ($party === null) {
                continue;
            }

            $atRisk = Money::zero($currency);
            $oldest = 0;

            foreach ($invoices as $item) {
                $atRisk = $atRisk->plus($item['outstanding']);
                $oldest = max($oldest, (int) $item['due_on']->diffInDays($today, false));
            }

            if (! $atRisk->isPositive() || $oldest <= 0) {
                continue;
            }

            $collections = PartyCollections::query()
                ->where('entity_id', $entityId)
                ->where('party_id', (string) $party->getKey())
                ->first();

            $rows[] = [
                'party' => (string) $party->code,
                'owner' => $collections?->owner_user_id === null ? null : (string) $collections->owner_user_id,
                'at_risk' => (string) $atRisk->amount,
                'oldest_days' => $oldest,
                'promised_on' => $collections?->promised_on?->toDateString(),
                'broken_promise' => (bool) $collections?->hasBrokenItsPromise($today),
            ];
        }

        /*
         | Ordered by value at risk, descending.
         |
         | Not by age: the oldest debt is often the smallest, and a worklist
         | ordered by age spends the morning on fifty riyals. A broken
         | promise sorts first within the value order, because somebody said
         | they would pay and did not - which is the one signal worth
         | interrupting the order for.
         */
        usort($rows, static function (array $a, array $b): int {
            if ($a['broken_promise'] !== $b['broken_promise']) {
                return $a['broken_promise'] ? -1 : 1;
            }

            return \Brick\Math\BigDecimal::of($b['at_risk'])
                ->compareTo(\Brick\Math\BigDecimal::of($a['at_risk']));
        });

        return $rows;
    }

    /**
     * Who is chasing this customer - PF-109.
     *
     * A worklist with no owner is a list nobody works: every row is
     * somebody else's problem, and the oldest debt on it is the one no
     * individual has ever been asked about.
     */
    public function assignOwner(string $entityId, Party $party, string $userId): PartyCollections
    {
        $row = PartyCollections::query()->updateOrCreate(
            ['entity_id' => $entityId, 'party_id' => (string) $party->getKey()],
            ['owner_user_id' => $userId],
        );

        $this->audit->record(
            category: 'sales',
            action: 'collections.owner_assigned',
            subjectType: 'party',
            subjectId: (string) $party->getKey(),
            subjectLabel: (string) $party->code,
            after: ['owner_user_id' => $userId],
            entityId: $entityId,
            partyId: (string) $party->getKey(),
        );

        return $row->refresh();
    }

    /**
     * What the customer said they would pay, and when - PF-109.
     *
     * ## Why a promise is recorded rather than a hold being lifted
     *
     * The easy implementation of "they promised to pay on the 30th" is to
     * stop chasing until the 30th. That is how a debt goes quiet: the
     * promise is broken, nothing happened on the 30th, and the next
     * reminder goes out whenever somebody notices.
     *
     * So the promise is recorded, the chasing continues on the policy's own
     * schedule, and a promise whose date has passed sorts to the top of the
     * worklist - which is the one signal worth interrupting the value order
     * for, because somebody said they would pay and did not.
     *
     * @throws \InvalidArgumentException if the promise is for nothing or for the past
     */
    public function promiseToPay(
        string $entityId,
        Party $party,
        CarbonImmutable $on,
        Money $amount,
        ?string $note = null,
        ?CarbonImmutable $asOf = null,
    ): PartyCollections {
        if (! $amount->isPositive()) {
            throw new \InvalidArgumentException(
                'A promise to pay nothing is not a promise. Record the dispute or the write-off instead.',
            );
        }

        $today = ($asOf ?? CarbonImmutable::now())->startOfDay();

        if ($on->startOfDay()->lt($today)) {
            throw new \InvalidArgumentException(sprintf(
                'A promise dated %s is already broken on %s. Record the date the customer actually gave.',
                $on->toDateString(),
                $today->toDateString(),
            ));
        }

        $row = PartyCollections::query()->updateOrCreate(
            ['entity_id' => $entityId, 'party_id' => (string) $party->getKey()],
            [
                'promised_on' => $on->toDateString(),
                'promised_amount' => (string) $amount->amount,
                'currency' => (string) $amount->currency,
                'promise_note' => $note,
            ],
        );

        $this->audit->record(
            category: 'sales',
            action: 'collections.promise_recorded',
            subjectType: 'party',
            subjectId: (string) $party->getKey(),
            subjectLabel: (string) $party->code,
            after: [
                'promised_on' => $on->toDateString(),
                'promised_amount' => (string) $amount->amount,
                'currency' => (string) $amount->currency,
            ],
            reason: $note,
            entityId: $entityId,
            partyId: (string) $party->getKey(),
        );

        return $row->refresh();
    }

    /** What is disputed and therefore not being chased - PF-108. */
    public function disputeReport(string $entityId): array
    {
        return DB::connection('tenant')->table('document_disputes as d')
            ->leftJoin('invoices as i', 'i.id', '=', 'd.document_id')
            ->where('d.entity_id', $entityId)
            ->where('d.status', 'open')
            ->orderByDesc('d.raised_at')
            ->get([
                'd.document_id', 'd.reason_code', 'd.detail', 'd.disputed_amount',
                'd.currency', 'd.raised_at', 'i.number', 'i.party_id',
            ])
            ->map(static fn (object $row): array => [
                'invoice' => (string) ($row->number ?? $row->document_id),
                'reason' => (string) $row->reason_code,
                'detail' => (string) $row->detail,
                'amount' => $row->disputed_amount === null ? null : (string) $row->disputed_amount,
                'raised_at' => (string) $row->raised_at,
            ])
            ->all();
    }
}
