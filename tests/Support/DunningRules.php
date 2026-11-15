<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Services\CreditControl;
use App\Tenant\Sales\Models\DunningEvent;
use App\Tenant\Sales\Models\DunningNotice;
use App\Tenant\Sales\Models\DunningPolicy;
use App\Tenant\Sales\Models\DunningPolicyStep;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\Dunning;
use App\Tenant\Sales\Services\Issuer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Chasing what is owed - PF-108, PF-109.
 *
 * ## The claim that shapes everything else
 *
 * Evaluated per invoice, sent per customer. A customer with six overdue
 * invoices gets one statement listing six, not six emails - and the person
 * who receives six emails from a supplier in one morning telephones about
 * the emails rather than about the money, which is a conversation about the
 * supplier's systems.
 *
 * ## A dispute stops the escalation and nothing else
 *
 * The invoice is still owed, still counts towards exposure and still ages.
 * Writing it off or excluding it from exposure would be the easy
 * implementation and would let a customer keep buying by disputing
 * everything.
 */
final class DunningRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['dunning (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'the policy is the party\'s, then its group\'s, then the default' => fn (): array => $this->policyResolution(),
                'a step applies when the invoice reaches its offset' => fn (): array => $this->theOffset(),
                'six overdue invoices produce one statement' => fn (): array => $this->onePerCustomer(),
                'a second run on the same day sends nothing' => fn (): array => $this->idempotent(),
                'a disputed invoice stops escalating and is reported' => fn (): array => $this->disputed(),
                'nothing is sent on a day the business is shut' => fn (): array => $this->businessDays(),
                'a late fee is its own document, with its basis on it' => fn (): array => $this->lateFee(),
                'a fee is never charged twice by the same step' => fn (): array => $this->feeOnce(),
                'a step can place a credit hold, and it is recorded' => fn (): array => $this->creditHold(),
                'the policy lifts its own hold and leaves a person\'s alone' => fn (): array => $this->holdRelease(),
                'a promise to pay is dated, owned and recorded' => fn (): array => $this->promises(),
                'the worklist is ordered by value at risk' => fn (): array => $this->worklist(),
                'a paid or credited invoice is not chased' => fn (): array => $this->settled(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function policyResolution(): array
    {
        $violations = [];
        $dunning = app(Dunning::class);

        $default = $this->policy('DEFAULT', isDefault: true);
        $group = $this->policy('DISTRIB', group: 'distributors');
        $own = $this->policy('SPECIAL');

        $party = $this->party;
        \assert($party !== null);

        if ((string) $dunning->policyFor($this->entityId, $party)?->code !== 'DEFAULT') {
            $violations[] = 'a party with no policy and no group did not get the entity default';
        }

        $party->update(['party_group' => 'distributors']);

        if ((string) $dunning->policyFor($this->entityId, $party->refresh())?->code !== 'DISTRIB') {
            $violations[] = 'a party in a group with a policy got the default instead';
        }

        DB::connection('tenant')->table('party_dunning_policies')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'party_id' => (string) $party->getKey(),
            'dunning_policy_id' => (string) $own->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ((string) $dunning->policyFor($this->entityId, $party->refresh())?->code !== 'SPECIAL') {
            $violations[] = 'a party with its own policy got its group\'s instead';
        }

        /*
         | And no policy at all means no chasing.
         |
         | A system that invented one would start emailing customers nobody
         | set up, which is the kind of thing a business finds out about
         | from a customer.
         */
        DunningPolicy::query()->where('entity_id', $this->entityId)->update(['active' => false]);

        if ($dunning->policyFor($this->entityId, $party->refresh()) !== null) {
            $violations[] = 'a policy was found after every one was deactivated';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theOffset(): array
    {
        $violations = [];
        $policy = $this->policy('DEFAULT', isDefault: true);
        $invoice = $this->overdueInvoice();
        $dunning = app(Dunning::class);

        // Day 5: the 7-day step has not been reached.
        $early = $dunning->run($this->entity(), $this->businessDay($this->date->copy()->addDays(5)));

        if ($early['notices'] !== []) {
            $violations[] = 'a step at seven days past due fired on day five';
        }

        // Day 8: it has.
        $report = $dunning->run($this->entity(), $this->businessDay($this->date->copy()->addDays(38)));

        if ($report['notices'] === []) {
            $violations[] = 'no notice was sent when the invoice was well past the first offset: '.
                json_encode($report['skipped']);

            return $violations;
        }

        $event = DunningEvent::query()->where('invoice_id', (string) $invoice->getKey())->first();

        if ($event === null) {
            return [...$violations, 'no event was recorded'];
        }

        if ((int) $event->days_past_due < 7) {
            $violations[] = sprintf(
                'the event records %d days past due and the step needs seven',
                (int) $event->days_past_due,
            );
        }

        if ((string) $event->amount_due !== (string) $invoice->payable_amount) {
            $violations[] = 'the event does not record what was owed at the time, which is the figure '.
                'the statement showed';
        }

        /*
         | And the highest step reached is the one that fires - once.
         |
         | A run that has been down for a week must send the step the
         | invoice has actually reached, not walk it through four reminders
         | in four minutes.
         */
        $events = DunningEvent::query()->where('invoice_id', (string) $invoice->getKey())->count();

        if ($events !== 1) {
            $violations[] = sprintf(
                '%d steps fired at once for one invoice. A run that has been down for a week must '.
                'not walk a customer through four reminders in four minutes',
                $events,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function onePerCustomer(): array
    {
        $violations = [];
        $this->policy('DEFAULT', isDefault: true);

        for ($i = 0; $i < 6; $i++) {
            $this->overdueInvoice();
        }

        $report = app(Dunning::class)->run($this->entity(), $this->businessDay($this->date->copy()->addDays(40)));

        if (\count($report['notices']) !== 1) {
            $violations[] = sprintf(
                '%d notices were produced for one customer with six overdue invoices. The person who '.
                'receives six emails from a supplier in one morning telephones about the emails rather '.
                'than about the money',
                \count($report['notices']),
            );
        }

        $notice = DunningNotice::query()->where('entity_id', $this->entityId)->first();

        if ($notice === null) {
            return [...$violations, 'no notice row was written'];
        }

        if ((int) $notice->invoice_count !== 6) {
            $violations[] = sprintf('the statement lists %d invoices and six were due', (int) $notice->invoice_count);
        }

        $total = DunningEvent::query()->where('dunning_notice_id', (string) $notice->getKey())->sum('amount_due');

        if ((string) Money::of((string) $total, 'SAR')->amount !== (string) $notice->total_due) {
            $violations[] = 'the statement total does not match the invoices on it';
        }

        if ($notice->sent_at === null) {
            $violations[] = 'the notice was not marked sent, so a retry would send it again';
        }

        return $violations;
    }

    /** @return list<string> */
    public function idempotent(): array
    {
        $violations = [];
        $this->policy('DEFAULT', isDefault: true);
        $this->overdueInvoice();

        $on = $this->businessDay($this->date->copy()->addDays(40));

        $first = app(Dunning::class)->run($this->entity(), $on);
        $second = app(Dunning::class)->run($this->entity(), $on);

        if ($first['notices'] === []) {
            return ['the first run sent nothing'];
        }

        if ($second['notices'] !== []) {
            $violations[] = 'the second run on the same day sent another notice. Cron fires daily, '.
                'somebody runs it by hand while looking into a complaint, and a retry lands after a '.
                'timeout - the customer must not be chased twice in a morning';
        }

        $notices = DunningNotice::query()->where('entity_id', $this->entityId)->count();
        $events = DunningEvent::query()->where('entity_id', $this->entityId)->count();

        if ($notices !== 1 || $events !== 1) {
            $violations[] = sprintf('%d notices and %d events exist after two runs', $notices, $events);
        }

        if ($second['skipped'] === []) {
            $violations[] = 'the second run does not say why it sent nothing, so a quiet run cannot '.
                'be told from a broken one';
        }

        return $violations;
    }

    /** @return list<string> */
    public function disputed(): array
    {
        $violations = [];
        $this->policy('DEFAULT', isDefault: true);
        $invoice = $this->overdueInvoice();

        app(CreditControl::class)->dispute(
            $this->entityId,
            'invoice',
            (string) $invoice->getKey(),
            $this->party,
            'quality',
            'the goods arrived damaged and we are returning them',
            Money::of((string) $invoice->payable_amount, 'SAR'),
        );

        $report = app(Dunning::class)->run($this->entity(), $this->businessDay($this->date->copy()->addDays(40)));

        if ($report['notices'] !== []) {
            $violations[] = 'a disputed invoice was chased. Sending a fourth reminder about an invoice '.
                'the customer has formally queried is how a commercial relationship ends over an '.
                'administrative error';
        }

        $explained = false;

        foreach ($report['skipped'] as $skip) {
            if (str_contains((string) $skip['why'], 'disputed')) {
                $explained = true;
            }
        }

        if (! $explained) {
            $violations[] = 'the run does not say the invoice was skipped because it is disputed';
        }

        $onReport = app(Dunning::class)->disputeReport($this->entityId);

        if ($onReport === []) {
            $violations[] = 'the disputed invoice does not appear on the dispute report, so it has '.
                'stopped escalating and nobody is looking at it';
        }

        /*
         | And it is still owed.
         |
         | Excluding it from exposure would let a customer keep buying by
         | disputing everything.
         */
        $exposure = app(CreditControl::class)->exposure(
            $this->entityId,
            (string) $this->party?->getKey(),
            'SAR',
        );

        if (Money::of((string) $exposure['exposure'], 'SAR')->isZero()) {
            $violations[] = 'a disputed invoice dropped out of the exposure, so a customer could keep '.
                'buying by disputing everything';
        }

        return $violations;
    }

    /** @return list<string> */
    public function businessDays(): array
    {
        $this->policy('DEFAULT', isDefault: true);
        $this->overdueInvoice();

        /*
         | Friday, which is a weekend day under the default Gulf policy.
         |
         | A reminder sent then lands at the bottom of an inbox nobody reads
         | until Sunday, and the step has been spent.
         */
        $friday = CarbonImmutable::parse($this->date->toDateString())->addDays(40)->next(CarbonImmutable::FRIDAY);

        $report = app(Dunning::class)->run($this->entity(), $friday);

        if ($report['notices'] !== []) {
            return ['a notice was sent on a day the business is shut'];
        }

        $explained = false;

        foreach ($report['skipped'] as $skip) {
            if (str_contains((string) $skip['why'], 'business day')) {
                $explained = true;
            }
        }

        return $explained
            ? []
            : ['the run does not say it was not a business day, so a quiet Friday reads as a broken job'];
    }

    /** @return list<string> */
    public function lateFee(): array
    {
        $violations = [];
        $this->policy('DEFAULT', isDefault: true, withFee: true);
        $invoice = $this->overdueInvoice();

        $report = app(Dunning::class)->run($this->entity(), $this->businessDay($this->date->copy()->addDays(10)));

        if ($report['fees'] === []) {
            return ['no late fee was raised: '.json_encode($report['skipped'])];
        }

        $fee = Invoice::query()
            ->where('entity_id', $this->entityId)
            ->where('document_type', '383')
            ->first();

        if ($fee === null) {
            return [...$violations, 'the fee is not a document, so it cannot be credited or disputed'];
        }

        /*
         | A debit note, which is what makes it creditable and disputable.
         |
         | A fee bolted onto the original invoice would change a document
         | that has been issued, and a fee held in a column is a charge the
         | customer cannot query.
         */
        if ((string) $fee->series_key !== 'DBN') {
            $violations[] = sprintf('the fee is in the %s series', (string) $fee->series_key);
        }

        $description = (string) $fee->lines()->value('description');

        foreach (['%', (string) $invoice->number] as $needle) {
            if (! str_contains($description, (string) $needle)) {
                $violations[] = sprintf(
                    'the fee line does not show "%s": "%s". A fee with no arithmetic on it is a number '.
                    'the customer telephones about',
                    (string) $needle,
                    $description,
                );
            }
        }

        // 2% of 1,150.00 is 23.00.
        if ((string) $fee->net_amount !== '23.0000') {
            $violations[] = sprintf('a 2%% fee on 1,150.00 is 23.0000 and the document says %s', (string) $fee->net_amount);
        }

        $event = DunningEvent::query()->where('invoice_id', (string) $invoice->getKey())->first();

        if ($event === null || (string) $event->fee_invoice_id !== (string) $fee->getKey()) {
            $violations[] = 'the dunning event does not point at the fee it raised';
        }

        if (! \in_array('late_fee', (array) ($event?->actions_taken ?? []), true)) {
            $violations[] = 'the event does not record that it charged a fee';
        }

        return $violations;
    }

    /** @return list<string> */
    public function feeOnce(): array
    {
        $policy = $this->policy('DEFAULT', isDefault: true, withFee: true);
        $invoice = $this->overdueInvoice();

        $on = $this->businessDay($this->date->copy()->addDays(10));
        app(Dunning::class)->run($this->entity(), $on);

        // The same step, a day later. The run's own step guard stops this
        // one, so it proves the outer defence rather than the fee's.
        app(Dunning::class)->run($this->entity(), $this->businessDay($on->copy()->addDays(1)));

        /*
         | And then the fee is charged directly, which is the path its own
         | guard protects.
         |
         | Measured: with the guard removed, two runs still produce one fee -
         | `stepFor` never offers the step a second time. So the guard is
         | not reachable through `run()`, and a check that only ran the job
         | would pass with the guard deleted. `chargeLateFee` is public,
         | a fee can be raised by hand from the worklist, and this is the
         | call that finds out.
         */
        app(Dunning::class)->chargeLateFee(
            $invoice->refresh(),
            $policy->steps()->where('sequence', 1)->firstOrFail(),
            app(Dunning::class)->outstandingOn($invoice->refresh()),
            $on->copy()->addDays(2),
        );

        $fees = Invoice::query()
            ->where('entity_id', $this->entityId)
            ->where('document_type', '383')
            ->count();

        return $fees === 1
            ? []
            : [sprintf(
                '%d late fees were charged by one step. A customer charged the same fee twice in a '.
                'month disputes the fee and stops reading the reminders',
                $fees,
            )];
    }

    /** @return list<string> */
    public function creditHold(): array
    {
        $violations = [];
        $this->policy('DEFAULT', isDefault: true, withHold: true);
        $this->overdueInvoice();

        $report = app(Dunning::class)->run($this->entity(), $this->businessDay($this->date->copy()->addDays(10)));

        if ($report['holds'] === []) {
            return ['no credit hold was placed: '.json_encode($report['skipped'])];
        }

        $event = DB::connection('tenant')->table('credit_hold_events')
            ->where('entity_id', $this->entityId)
            ->where('party_id', (string) $this->party?->getKey())
            ->orderByDesc('at')
            ->first();

        if ($event === null) {
            return [...$violations, 'the hold left no record'];
        }

        if ((string) $event->direction !== 'placed') {
            $violations[] = 'the hold event does not say it was placed';
        }

        foreach (['dunning', 'past due'] as $needle) {
            if (! str_contains((string) $event->reason, $needle)) {
                $violations[] = sprintf(
                    'the hold reason does not mention "%s": "%s". A hold nobody can explain is one the '.
                    'customer argues with',
                    $needle,
                    (string) $event->reason,
                );
            }
        }

        if ($event->exposure_at_decision === null) {
            $violations[] = 'the hold does not record the exposure it was decided on, so "why was '.
                'this customer put on hold in March" cannot be answered with the March figures';
        }

        return $violations;
    }

    /** @return list<string> */
    public function holdRelease(): array
    {
        $violations = [];
        $this->policy('DEFAULT', isDefault: true, withHold: true);
        $invoice = $this->overdueInvoice();
        $dunning = app(Dunning::class);
        $credit = app(CreditControl::class);

        $on = $this->businessDay($this->date->copy()->addDays(10));
        $dunning->run($this->entity(), $on);

        if ($credit->currentHold($this->entityId, (string) $this->party?->getKey()) === null) {
            return ['the step did not place a hold, so there is nothing to release'];
        }

        /*
         | Paid in full, by crediting it: the fixture has no cash receipt
         | yet (Phase 08), and what matters here is that nothing is
         | outstanding.
         */
        app(Issuer::class)->issue(
            app(\App\Tenant\Sales\Services\Crediting::class)->full($invoice->refresh(), $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        $report = $dunning->run($this->entity(), $this->businessDay($on->copy()->addDays(2)));

        if ($report['released'] === []) {
            $violations[] = 'the hold was not lifted when nothing was overdue. A customer who paid on '.
                'Thursday and cannot order on Monday telephones about the hold, and the person who '.
                'could lift it is on holiday';
        }

        if ($credit->currentHold($this->entityId, (string) $this->party?->getKey()) !== null) {
            $violations[] = 'the party is still on hold after the release';
        }

        $lift = DB::connection('tenant')->table('credit_hold_events')
            ->where('entity_id', $this->entityId)
            ->where('direction', 'lifted')
            ->orderByDesc('at')
            ->first();

        if ($lift === null || (string) $lift->source !== 'dunning') {
            $violations[] = 'the lift is not attributed to the policy that placed it';
        }

        /*
         | And a hold a PERSON placed is not lifted by the job.
         |
         | A credit decision, a legal matter, a customer in administration:
         | a nightly job that lifts those because an invoice got paid is a
         | job that overrides the credit manager.
         */
        $credit->hold(
            $this->entityId,
            $this->party,
            'the customer is in administration',
            'SAR',
        );

        $after = app(Dunning::class)->run($this->entity(), $this->businessDay($on->copy()->addDays(3)));

        if ($after['released'] !== []) {
            $violations[] = 'the run lifted a hold a person placed by hand';
        }

        if ($credit->currentHold($this->entityId, (string) $this->party?->getKey()) === null) {
            $violations[] = 'a manual hold did not survive the dunning run, so a credit decision is '.
                'undone by a nightly job';
        }

        return $violations;
    }

    /** @return list<string> */
    public function promises(): array
    {
        $violations = [];
        $dunning = app(Dunning::class);
        $owner = (string) \Illuminate\Support\Str::ulid();

        $row = $dunning->assignOwner($this->entityId, $this->party, $owner);

        if ((string) $row->owner_user_id !== $owner) {
            $violations[] = 'the collections owner was not recorded, and a worklist with no owner is '.
                'a list nobody works';
        }

        $promise = $dunning->promiseToPay(
            $this->entityId,
            $this->party,
            CarbonImmutable::parse($this->date->toDateString())->addDays(14),
            Money::of('1150.0000', 'SAR'),
            'the finance director will release it with the month-end run',
            CarbonImmutable::parse($this->date->toDateString()),
        );

        if ($promise->promised_on?->toDateString() !== $this->date->copy()->addDays(14)->toDateString()) {
            $violations[] = 'the promise date was not recorded';
        }

        if ((string) $promise->owner_user_id !== $owner) {
            $violations[] = 'recording the promise lost the owner, so the two cannot be one row';
        }

        // A promise already in the past is a mistake, not a promise.
        try {
            $dunning->promiseToPay(
                $this->entityId,
                $this->party,
                CarbonImmutable::parse($this->date->toDateString())->subDays(3),
                Money::of('1150.0000', 'SAR'),
                null,
                CarbonImmutable::parse($this->date->toDateString()),
            );

            $violations[] = 'a promise dated in the past was accepted, which is a broken promise '.
                'recorded as a plan';
        } catch (\InvalidArgumentException) {
            // As intended.
        }

        try {
            $dunning->promiseToPay(
                $this->entityId,
                $this->party,
                CarbonImmutable::parse($this->date->toDateString())->addDays(14),
                Money::of('0.0000', 'SAR'),
                null,
                CarbonImmutable::parse($this->date->toDateString()),
            );

            $violations[] = 'a promise to pay nothing was accepted';
        } catch (\InvalidArgumentException) {
            // As intended.
        }

        /*
         | And the promise does not stop the chasing.
         |
         | The easy implementation of "they promised to pay on the 30th" is
         | to go quiet until the 30th: the promise is broken, nothing
         | happens, and the next reminder goes out whenever somebody
         | notices.
         */
        $this->policy('DEFAULT', isDefault: true);
        $this->overdueInvoice();

        $report = $dunning->run($this->entity(), $this->businessDay($this->date->copy()->addDays(10)));

        if ($report['notices'] === []) {
            $violations[] = 'a customer who promised to pay stopped being chased, which is how a '.
                'debt goes quiet: '.json_encode($report['skipped']);
        }

        $recorded = DB::connection('tenant')->table('audit_log')
            ->where('entity_id', $this->entityId)
            ->whereIn('action', ['collections.owner_assigned', 'collections.promise_recorded'])
            ->count();

        if ($recorded < 2) {
            $violations[] = sprintf(
                '%d of the two collections decisions reached the audit trail, and "who said this '.
                'customer would pay on the 30th" is a question somebody asks',
                $recorded,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function worklist(): array
    {
        $violations = [];
        $this->policy('DEFAULT', isDefault: true);

        $small = Party::create([
            'code' => 'DUN-S-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'legal_name' => 'Small Debtor LLC',
            'is_customer' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        // The big one: three invoices. The small one: one, older.
        $this->overdueInvoice();
        $this->overdueInvoice();
        $this->overdueInvoice();
        $this->overdueInvoice(backdatedBy: 10, party: $small);

        $worklist = app(Dunning::class)->worklist(
            $this->entityId,
            'SAR',
            CarbonImmutable::parse($this->date->toDateString())->addDays(95),
        );

        if (\count($worklist) !== 2) {
            return [sprintf('the worklist has %d rows and two customers are overdue', \count($worklist))];
        }

        if ((string) $worklist[0]['party'] === (string) $small->code) {
            $violations[] = 'the worklist puts the smaller debt first. Ordered by age, a worklist '.
                'spends the morning on fifty riyals - it is ordered by value at risk';
        }

        if ((int) $worklist[0]['oldest_days'] <= 0) {
            $violations[] = 'the worklist does not say how old the oldest invoice is';
        }

        /*
         | A broken promise sorts first, whatever the value.
         |
         | Somebody said they would pay and did not, which is the one signal
         | worth interrupting the order for.
         */
        \App\Tenant\Sales\Models\PartyCollections::create([
            'entity_id' => $this->entityId,
            'party_id' => (string) $small->getKey(),
            'promised_on' => $this->date->copy()->addDays(50)->toDateString(),
            'promised_amount' => '1150.0000',
            'currency' => 'SAR',
        ]);

        $reordered = app(Dunning::class)->worklist(
            $this->entityId,
            'SAR',
            CarbonImmutable::parse($this->date->toDateString())->addDays(95),
        );

        if ((string) $reordered[0]['party'] !== (string) $small->code) {
            $violations[] = 'a customer who broke a promise to pay does not sort first';
        }

        if (($reordered[0]['broken_promise'] ?? false) !== true) {
            $violations[] = 'the worklist does not flag the broken promise';
        }

        return $violations;
    }

    /** @return list<string> */
    public function settled(): array
    {
        $violations = [];
        $this->policy('DEFAULT', isDefault: true);
        $invoice = $this->overdueInvoice();

        // Credited in full: nothing is owed.
        app(Issuer::class)->issue(
            app(\App\Tenant\Sales\Services\Crediting::class)->full($invoice, $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        $outstanding = app(Dunning::class)->outstandingOn($invoice->refresh());

        if (! $outstanding->isZero()) {
            $violations[] = sprintf(
                'a fully credited invoice still shows %s outstanding',
                (string) $outstanding->amount,
            );
        }

        $report = app(Dunning::class)->run($this->entity(), $this->businessDay($this->date->copy()->addDays(40)));

        if ($report['notices'] !== []) {
            $violations[] = 'a credited invoice was chased, which is the letter that ends a '.
                'relationship over an administrative error';
        }

        return $violations;
    }

    /** A business day on or after a date, so a check is not defeated by a weekend. */
    private function businessDay(\Carbon\CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse(
            app(\App\Tenant\Catalog\Services\DueDateCalculator::class)
                ->nextBusinessDay($this->entityId, $date)
                ->toDateString(),
        );
    }

    private function policy(
        string $code,
        bool $isDefault = false,
        ?string $group = null,
        bool $withFee = false,
        bool $withHold = false,
    ): DunningPolicy {
        $policy = DunningPolicy::create([
            'entity_id' => $this->entityId,
            'code' => $code,
            'name_ar' => 'سياسة المتابعة',
            'name_en' => 'Collection policy',
            'party_group' => $group,
            'is_default' => $isDefault,
            'active' => true,
        ]);

        DunningPolicyStep::create([
            'entity_id' => $this->entityId,
            'dunning_policy_id' => (string) $policy->getKey(),
            'sequence' => 1,
            'days_past_due' => 7,
            'channel' => 'email',
            'template' => 'reminder',
            'actions' => array_values(array_filter([
                $withFee ? 'late_fee' : null,
                $withHold ? 'credit_hold' : null,
            ])),
            'late_fee_percent' => $withFee ? '2.000000' : null,
        ]);

        DunningPolicyStep::create([
            'entity_id' => $this->entityId,
            'dunning_policy_id' => (string) $policy->getKey(),
            'sequence' => 2,
            'days_past_due' => 30,
            'channel' => 'statement',
            'template' => 'final-notice',
            'actions' => ['to_collections'],
        ]);

        return $policy;
    }

    /**
     * An invoice that is already overdue, without editing anything.
     *
     * ## Why the terms snapshot is NOT backdated
     *
     * The first version of this fixture issued an invoice and then edited
     * its frozen terms to put the due date in the past. The database refused
     * it - "a document that should not have been issued is credited or
     * corrected, never edited" - which is PF-091 doing exactly its job.
     *
     * So the invoice is issued on the fixture's own date with its real
     * terms, due on receipt, and the RUN is given a date some days later.
     * Which is what an overdue invoice is: not a document with a tampered
     * date, but a document whose due date has passed.
     *
     * `$backdatedBy` dates the DRAFT earlier, which is allowed and is how
     * one debt becomes genuinely older than another: a draft is a document
     * somebody is still writing, and its date is one of the things they are
     * still writing.
     */
    private function overdueInvoice(int $backdatedBy = 0, ?Party $party = null): Invoice
    {
        $draft = $this->draft();
        $changes = [];

        if ($party !== null) {
            $changes['party_id'] = (string) $party->getKey();
        }

        if ($backdatedBy !== 0) {
            $on = $this->date->copy()->subDays($backdatedBy)->toDateString();
            $changes['issue_date'] = $on;
            $changes['tax_point'] = $on;
        }

        if ($changes !== []) {
            $draft->update($changes);
        }

        return app(Issuer::class)->issue(
            $draft->refresh(),
            $this->entity(),
            $this->actorId(),
            $this->decision,
            $this->terms,
        );
    }
}
