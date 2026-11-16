<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Purchasing\Exceptions\ApprovalRefused;
use App\Tenant\Purchasing\Models\ApprovalRequest;
use App\Tenant\Purchasing\Models\ApprovalRule;
use App\Tenant\Purchasing\Models\ApprovalRuleStep;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Services\ApprovalEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The approval engine - PF-136.
 *
 * ## The hole that matters
 *
 * An approval is not a flag. It is a decision about a **specific version**
 * of a document, and the failure is the edit afterwards: approved at 1,000,
 * changed to 100,000, paid. Every system that stores approval as a boolean
 * has this hole, and it is invisible because the document looks approved and
 * is.
 *
 * So the heaviest check here is the one that edits a bill mid-chain and
 * requires the chain to **start again from the beginning** - not from the
 * step it was on, because the earlier approvers also approved something
 * else.
 *
 * ## And the two ways a quorum is faked
 *
 * One person pressing approve twice, and the requester approving their own
 * document. Both have a dedicated failing case, because both are what
 * somebody does at five on a Friday.
 */
final class ApprovalRules
{
    use \Tests\Support\Concerns\EntersBills;

    private string $requester = '';

    private string $manager = '';

    private string $director = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['approvals (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();

            $this->requester = (string) Str::ulid();
            $this->manager = (string) Str::ulid();
            $this->director = (string) Str::ulid();

            return $this->runEachInASavepoint([
                'a document with no matching rule is refused' => fn (): array => $this->noRule(),
                'a chain is compiled from the matching rules, in order' => fn (): array => $this->compiled(),
                'an amount band selects the longer chain' => fn (): array => $this->amountBands(),
                'steps sharing a sequence open together' => fn (): array => $this->parallel(),
                'a quorum needs that many different people' => fn (): array => $this->quorum(),
                'one person cannot clear a step twice' => fn (): array => $this->twiceIsRefused(),
                'the requester cannot approve their own document' => fn (): array => $this->ownDocument(),
                'the refusal names who can' => fn (): array => $this->refusalNamesWhoCan(),
                'a rejection needs a reason and ends the chain' => fn (): array => $this->rejection(),
                'an edited document returns to the start of the chain' => fn (): array => $this->editedReturnsToStart(),
                'a decision records the version it was about' => fn (): array => $this->fingerprintOnDecision(),
                'an unrelated change does not restart the chain' => fn (): array => $this->noteDoesNotRestart(),
                'a second request for one document is the same request' => fn (): array => $this->idempotent(),
                'the chain survives a rule being changed under it' => fn (): array => $this->chainIsARecord(),
                'an overdue step is reported for escalation' => fn (): array => $this->escalation(),
                'every decision reaches the audit trail' => fn (): array => $this->audited(),
                'a delegate acts and the record says so' => fn (): array => $this->delegation(),
                'a delegation to oneself is refused' => fn (): array => $this->selfDelegation(),
                'overlapping delegations are refused' => fn (): array => $this->overlappingDelegation(),
                'a delegation outside its window does not apply' => fn (): array => $this->delegationWindow(),
                'a revoked delegation does not apply' => fn (): array => $this->revokedDelegation(),
                'the delegations in force are reportable' => fn (): array => $this->standingReport(),
                'a limit is tested on the converted amount' => fn (): array => $this->limitOnConverted(),
                'a user\'s own limit beats a role\'s' => fn (): array => $this->ownLimitWins(),
                'the lower of a limit and a delegation\'s cap binds' => fn (): array => $this->delegationCap(),
                'a limit change is not retroactive' => fn (): array => $this->limitIsNotRetroactive(),
                'the rate used for the limit test is recorded' => fn (): array => $this->rateIsRecorded(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function noRule(): array
    {
        $bill = $this->enterBill();

        try {
            $this->request($bill);
        } catch (ApprovalRefused $e) {
            return str_contains($e->getMessage(), 'at least one rule')
                ? []
                : ['the refusal does not say what to configure: '.$e->getMessage()];
        }

        return ['a document with no matching rule was given an approval chain. A document with no chain '.
            'cannot be approved and must not be silently treated as approved'];
    }

    /** @return list<string> */
    private function compiled(): array
    {
        $violations = [];
        $this->rule('ALL', ['manager' => 1], priority: 10);
        $this->rule('BIG', ['director' => 1], priority: 20, from: '1000');

        $bill = $this->enterBill();
        $request = $this->request($bill);

        $steps = $request->steps()->get();

        if ($steps->count() !== 2) {
            $violations[] = sprintf('the chain has %d steps and two rules matched', $steps->count());
        }

        $roles = $steps->pluck('role')->all();

        if ($roles !== ['manager', 'director']) {
            $violations[] = sprintf(
                'the chain is %s and the rules are ordered manager then director. A matched set with '.
                'no order would give a different chain each time the rows came back differently',
                implode(', ', array_map('strval', $roles)),
            );
        }

        if ((string) $steps->first()->status !== 'open') {
            $violations[] = 'the first step is not open';
        }

        if ((string) $steps->last()->status !== 'pending') {
            $violations[] = 'the second step is open before the first was cleared';
        }

        return $violations;
    }

    /** @return list<string> */
    private function amountBands(): array
    {
        $this->rule('ALL', ['manager' => 1], priority: 10);
        $this->rule('BIG', ['director' => 1], priority: 20, from: '100000');

        $small = $this->request($this->enterBill());

        if ($small->steps()->count() !== 1) {
            return [sprintf(
                'a 1,150 bill got %d steps and the second rule starts at 100,000',
                $small->steps()->count(),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function parallel(): array
    {
        $violations = [];
        $this->rule('BOTH', ['manager' => 1, 'director' => 1], priority: 10, parallel: true);

        $request = $this->request($this->enterBill());
        $steps = $request->steps()->get();

        if ($steps->pluck('sequence')->unique()->count() !== 1) {
            $violations[] = 'the two steps do not share a sequence, so they are not parallel';
        }

        if ($steps->where('status', 'open')->count() !== 2) {
            $violations[] = sprintf(
                '%d of two parallel steps are open. Steps sharing a sequence open together, and the '.
                'chain moves on only when all of them are satisfied',
                $steps->where('status', 'open')->count(),
            );
        }

        $engine = app(ApprovalEngine::class);
        $bill = Bill::query()->findOrFail((string) $request->subject_id);

        $request = $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($bill), null, ['manager']);

        if ($request->isApproved()) {
            $violations[] = 'one of two parallel steps approved the whole document';
        }

        $request = $engine->decide($request, $this->director, 'approved', $engine->fieldsOf($bill), null, ['director']);

        if (! $request->isApproved()) {
            $violations[] = 'both parallel steps are satisfied and the request is '.(string) $request->status;
        }

        return $violations;
    }

    /** @return list<string> */
    private function quorum(): array
    {
        $violations = [];
        $this->rule('TWO', ['manager' => 2], priority: 10);

        $request = $this->request($this->enterBill());
        $engine = app(ApprovalEngine::class);
        $bill = Bill::query()->findOrFail((string) $request->subject_id);

        $request = $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($bill), null, ['manager']);

        if ($request->isApproved()) {
            $violations[] = 'one approval cleared a quorum of two. Two of three is a real policy, and a '.
                'chain that could only express "all" or "any" would make the business pick the wrong one';
        }

        $second = (string) Str::ulid();
        $request = $engine->decide($request, $second, 'approved', $engine->fieldsOf($bill), null, ['manager']);

        if (! $request->isApproved()) {
            $violations[] = 'two different approvals did not clear a quorum of two';
        }

        return $violations;
    }

    /** @return list<string> */
    private function twiceIsRefused(): array
    {
        $this->rule('TWO', ['manager' => 2], priority: 10);

        $request = $this->request($this->enterBill());
        $engine = app(ApprovalEngine::class);
        $bill = Bill::query()->findOrFail((string) $request->subject_id);

        $request = $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($bill), null, ['manager']);

        try {
            $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($bill), null, ['manager']);
        } catch (ApprovalRefused $e) {
            return str_contains($e->getMessage(), 'twice')
                ? []
                : ['the refusal does not say why: '.$e->getMessage()];
        }

        return ['one person cleared a quorum of two by pressing approve twice, which is the obvious way '.
            'to clear a step alone'];
    }

    /** @return list<string> */
    private function ownDocument(): array
    {
        $this->rule('ALL', ['manager' => 1], priority: 10);

        $request = $this->request($this->enterBill());
        $engine = app(ApprovalEngine::class);
        $bill = Bill::query()->findOrFail((string) $request->subject_id);

        try {
            $engine->decide($request, $this->requester, 'approved', $engine->fieldsOf($bill), null, ['manager']);
        } catch (ApprovalRefused $e) {
            return str_contains($e->getMessage(), 'you submitted')
                ? []
                : ['the refusal does not say it is their own document: '.$e->getMessage()];
        }

        return ['the requester approved their own document. Money leaving needs a second person in a '.
            'way money arriving does not, and a system that allowed this would turn the chain into '.
            'paperwork'];
    }

    /** @return list<string> */
    private function refusalNamesWhoCan(): array
    {
        $violations = [];
        $this->rule('ALL', ['manager' => 1], priority: 10);

        $request = $this->request($this->enterBill());
        $engine = app(ApprovalEngine::class);
        $bill = Bill::query()->findOrFail((string) $request->subject_id);

        try {
            $engine->decide($request, $this->director, 'approved', $engine->fieldsOf($bill), null, ['director']);
            $violations[] = 'somebody outside the open step approved it';
        } catch (ApprovalRefused $e) {
            if (! str_contains($e->getMessage(), 'manager')) {
                $violations[] = sprintf(
                    'the refusal does not name who can: "%s". "You cannot do this" sends somebody to '.
                    'ask around, and the person they ask is frequently the one who created it',
                    $e->getMessage(),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function rejection(): array
    {
        $violations = [];
        $this->rule('ALL', ['manager' => 1], priority: 10);

        $request = $this->request($this->enterBill());
        $engine = app(ApprovalEngine::class);
        $bill = Bill::query()->findOrFail((string) $request->subject_id);

        try {
            $engine->decide($request, $this->manager, 'rejected', $engine->fieldsOf($bill), 'no', ['manager']);
            $violations[] = '"no" was accepted as a rejection reason. A rejection that does not say why '.
                'returns the document to somebody with no idea what to change';
        } catch (ApprovalRefused) {
            // As intended.
        }

        $request = $engine->decide(
            $request->refresh(),
            $this->manager,
            'rejected',
            $engine->fieldsOf($bill),
            'The purchase order says 80 units at 10 and this bills 100 at 12.',
            ['manager'],
        );

        if ((string) $request->status !== 'rejected') {
            $violations[] = sprintf('the request is %s after a rejection', (string) $request->status);
        }

        if (! str_contains((string) $request->outcome_detail, '80 units')) {
            $violations[] = 'the reason was not kept on the request, so whoever has to fix the bill '.
                'cannot see it';
        }

        return $violations;
    }

    /**
     * The hole PF-136 exists to close.
     *
     * @return list<string>
     */
    private function editedReturnsToStart(): array
    {
        $violations = [];
        $this->rule('TWO', ['manager' => 1], priority: 10);
        $this->rule('DIR', ['director' => 1], priority: 20);

        $bill = $this->enterBill();
        $request = $this->request($bill);
        $engine = app(ApprovalEngine::class);

        // The manager clears the first step on a bill of 1,150.
        $request = $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($bill), null, ['manager']);

        if ($request->steps()->where('status', 'approved')->count() !== 1) {
            return ['the first step did not clear, so this check proves nothing'];
        }

        /*
         | And then the bill changes. Approved at 1,150, now 115,000.
         |
         | This is the failure an approval flag cannot see: the document
         | looks approved and is, and the amount somebody agreed to is not
         | the amount that will be paid.
         */
        DB::connection('tenant')->table('bills')
            ->where('id', (string) $bill->getKey())
            ->update([
                'net_amount' => '100000.0000',
                'tax_amount' => '15000.0000',
                // The splits move with the total: `bills_tax_splits_tie`
                // refuses a tax amount that does not add up from its
                // recoverable and non-recoverable halves, and it is right to.
                'recoverable_tax_amount' => '15000.0000',
                'non_recoverable_tax_amount' => '0.0000',
                'gross_amount' => '115000.0000',
                'payable_amount' => '115000.0000',
            ]);

        $changed = Bill::query()->findOrFail((string) $bill->getKey());

        if (! $engine->isStale($request, $engine->fieldsOf($changed))) {
            return ['the engine does not notice that the document changed'];
        }

        try {
            $engine->decide($request, $this->director, 'approved', $engine->fieldsOf($changed), null, ['director']);
            $violations[] = 'the second approver cleared a document that had changed since the first '.
                'approved it';
        } catch (ApprovalRefused $e) {
            if (! str_contains($e->getMessage(), 'starts again')) {
                $violations[] = 'the refusal does not say the chain starts again: '.$e->getMessage();
            }
        }

        $request = $request->refresh();

        if ((string) $request->status !== 'stale') {
            $violations[] = sprintf('the old request is %s and should be stale', (string) $request->status);
        }

        /*
         | A new chain, from the beginning.
         |
         | Not from the step it was on: the earlier approvers also approved
         | something else.
         */
        $fresh = $this->request($changed);

        if ((string) $fresh->getKey() === (string) $request->getKey()) {
            $violations[] = 'the stale request was reused';
        }

        if ($fresh->steps()->where('status', 'approved')->count() !== 0) {
            $violations[] = 'the new chain starts with a step already approved, so the earlier '.
                'approval was carried over to a document it was never about';
        }

        if ((string) $fresh->steps()->first()->role !== 'manager') {
            $violations[] = 'the new chain does not start at the first step';
        }

        return $violations;
    }

    /** @return list<string> */
    private function fingerprintOnDecision(): array
    {
        $this->rule('ALL', ['manager' => 1], priority: 10);

        $bill = $this->enterBill();
        $request = $this->request($bill);
        $engine = app(ApprovalEngine::class);

        $request = $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($bill), null, ['manager']);

        $decision = $request->decisions()->first();

        if ($decision === null) {
            return ['the decision was not recorded'];
        }

        return (string) $decision->fingerprint === (string) $request->document_fingerprint
            ? []
            : ['the decision does not record the version it was about, so nothing could say later '.
                'which document this approver saw'];
    }

    /** @return list<string> */
    private function noteDoesNotRestart(): array
    {
        $this->rule('ALL', ['manager' => 1], priority: 10);

        $bill = $this->enterBill();
        $request = $this->request($bill);
        $engine = app(ApprovalEngine::class);

        /*
         | An internal note is not a new document.
         |
         | A chain that restarted on one would make approval a thing people
         | learn to work around - and the way they work around it is by not
         | writing notes.
         */
        DB::connection('tenant')->table('bills')
            ->where('id', (string) $bill->getKey())
            ->update(['note' => 'Chased the supplier about the delivery date.']);

        $changed = Bill::query()->findOrFail((string) $bill->getKey());

        if ($engine->isStale($request, $engine->fieldsOf($changed))) {
            return ['adding an internal note restarted the chain. Approval becomes something people '.
                'work around, and what they stop doing is writing notes'];
        }

        $request = $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($changed), null, ['manager']);

        return $request->isApproved() ? [] : ['the document could not be approved after a note was added'];
    }

    /** @return list<string> */
    private function idempotent(): array
    {
        $this->rule('ALL', ['manager' => 1], priority: 10);

        $bill = $this->enterBill();
        $first = $this->request($bill);
        $second = $this->request($bill);

        if ((string) $first->getKey() !== (string) $second->getKey()) {
            return ['a form submitted twice produced two chains over one document, so two different '.
                'people could each approve a different request'];
        }

        $count = ApprovalRequest::query()
            ->where('subject_id', (string) $bill->getKey())
            ->where('status', 'pending')
            ->count();

        return $count === 1 ? [] : [sprintf('%d pending requests exist for one bill', $count)];
    }

    /** @return list<string> */
    private function chainIsARecord(): array
    {
        $rule = $this->rule('ALL', ['manager' => 1], priority: 10);

        $bill = $this->enterBill();
        $request = $this->request($bill);

        /*
         | The rule changes while the chain is open.
         |
         | The chain was compiled and written down, so it does not move. A
         | re-evaluated chain would shift the goalposts under the people
         | already in it, and nobody could answer "who was supposed to
         | approve this" about a document from last month.
         */
        ApprovalRuleStep::query()
            ->where('approval_rule_id', (string) $rule->getKey())
            ->update(['role' => 'somebody_else']);

        $engine = app(ApprovalEngine::class);
        $request = $engine->decide($request->refresh(), $this->manager, 'approved', $engine->fieldsOf($bill), null, ['manager']);

        return $request->isApproved()
            ? []
            : ['the manager could no longer clear a step they were assigned when the chain started: '.
                (string) $request->status];
    }

    /** @return list<string> */
    private function escalation(): array
    {
        $this->rule('ALL', ['manager' => 1], priority: 10, escalateAfterHours: 24);

        $request = $this->request($this->enterBill());
        $engine = app(ApprovalEngine::class);

        if ($engine->overdue($this->entityId) !== []) {
            return ['a step opened a moment ago is already overdue'];
        }

        $overdue = $engine->overdue($this->entityId, now()->addHours(25));

        return $overdue === []
            ? ['a step open for twenty-five hours with a twenty-four hour window is not reported. A '.
                'document waiting on somebody on holiday is one nobody is looking at']
            : [];
    }

    /** @return list<string> */
    private function audited(): array
    {
        $violations = [];
        $this->rule('ALL', ['manager' => 1], priority: 10);

        $bill = $this->enterBill();
        $request = $this->request($bill);
        $engine = app(ApprovalEngine::class);

        $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($bill), 'Checked against the order.', ['manager']);

        $actions = DB::connection('tenant')->table('audit_log')
            ->where('entity_id', $this->entityId)
            ->whereIn('action', ['approval.requested', 'approval.approved'])
            ->pluck('action')
            ->all();

        foreach (['approval.requested', 'approval.approved'] as $action) {
            if (! \in_array($action, $actions, true)) {
                $violations[] = sprintf('%s did not reach the audit trail', $action);
            }
        }

        $record = DB::connection('tenant')->table('audit_log')
            ->where('entity_id', $this->entityId)
            ->where('action', 'approval.approved')
            ->first();

        if ($record === null) {
            return $violations;
        }

        $after = json_decode((string) $record->after_state, true) ?: [];

        foreach (['rule', 'sequence', 'quorum', 'fingerprint'] as $key) {
            if (! isset($after[$key])) {
                $violations[] = sprintf(
                    'the audit record carries no "%s", so "who was supposed to approve this and what '.
                    'did they see" cannot be answered from it',
                    $key,
                );
            }
        }

        if (trim((string) $record->reason) === '') {
            $violations[] = 'the approver\'s comment is not recorded';
        }

        return $violations;
    }

    /** @return list<string> */
    private function delegation(): array
    {
        $violations = [];
        $this->rule('ALL', ['manager' => 1], priority: 10);

        /*
         | The step is the manager's, and the manager is away.
         |
         | A substitution would write the manager's id on the decision -
         | a forged approval, discoverable only by noticing they were on
         | holiday. The delegate acts and both are recorded.
         */
        $deputy = (string) Str::ulid();

        app(\App\Tenant\Purchasing\Services\Delegations::class)->delegate(
            entityId: $this->entityId,
            fromUserId: $this->manager,
            toUserId: $deputy,
            /*
             | Around the real clock, not the fixture's date.
             |
             | A delegation is about who may act TODAY, and the fixture
             | dates its documents in May while the clock is in November.
             | Measured: a May window was correctly reported as expired -
             | `permitting()` asks about today and the window had closed
             | months ago.
             */
            startsOn: \Illuminate\Support\Carbon::now()->subDay(),
            endsOn: \Illuminate\Support\Carbon::now()->addWeek(),
            reason: 'The approvals manager is on leave for the week of the 18th.',
            duty: 'bill.approve',
            createdBy: $this->director,
        );

        $bill = $this->enterBill();
        $request = $this->request($bill);
        $engine = app(ApprovalEngine::class);

        /*
         | Under the MANAGER's roles.
         |
         | A delegate borrows the authority of the person they cover for -
         | that is what a delegation is - and the engine checks the
         | delegation exists before it honours them. Measured: passing the
         | deputy's own (empty) roles failed with "this step is not yours to
         | clear", which is correct and is not what the check is about.
         */
        $request = $engine->decide(
            $request,
            $deputy,
            'approved',
            $engine->fieldsOf($bill),
            null,
            ['manager'],
            $this->manager,
        );

        if (! $request->isApproved()) {
            return [sprintf('the delegate could not act: %s', (string) $request->status)];
        }

        $decision = $request->decisions()->first();

        /*
         | `user_id` is who pressed the button; `on_behalf_of` is whose step
         | it was. Written the other way round, the record would say the
         | manager approved a document they never saw - and the only way to
         | discover that is to notice they were on holiday that week.
         */
        if ((string) $decision->user_id !== $deputy) {
            $violations[] = 'the decision does not name the person who actually acted';
        }

        if ((string) $decision->on_behalf_of !== $this->manager) {
            $violations[] = 'the decision does not record whose step it was, so a substitution would be '.
                'indistinguishable from the manager approving it themselves';
        }

        return $violations;
    }

    /** @return list<string> */
    private function selfDelegation(): array
    {
        try {
            app(\App\Tenant\Purchasing\Services\Delegations::class)->delegate(
                entityId: $this->entityId,
                fromUserId: $this->manager,
                toUserId: $this->manager,
                startsOn: $this->date->copy(),
                endsOn: $this->date->copy()->addWeek(),
                reason: 'Covering my own approvals while I am here.',
            );
        } catch (ApprovalRefused $e) {
            return str_contains($e->getMessage(), 'laundered')
                ? []
                : ['the refusal does not say why it matters: '.$e->getMessage()];
        }

        return ['somebody delegated to themselves. It looks harmless and it is the shape in which a '.
            'segregation-of-duties conflict is laundered'];
    }

    /** @return list<string> */
    private function overlappingDelegation(): array
    {
        $deputy = (string) Str::ulid();
        $delegations = app(\App\Tenant\Purchasing\Services\Delegations::class);

        $delegations->delegate(
            entityId: $this->entityId,
            fromUserId: $this->manager,
            toUserId: $deputy,
            startsOn: \Illuminate\Support\Carbon::now(),
            endsOn: \Illuminate\Support\Carbon::now()->addWeek(),
            reason: 'Away for the week of the 18th, the deputy covers.',
        );

        try {
            $delegations->delegate(
                entityId: $this->entityId,
                fromUserId: $this->manager,
                toUserId: $deputy,
                startsOn: \Illuminate\Support\Carbon::now()->addDays(3),
                endsOn: \Illuminate\Support\Carbon::now()->addDays(10),
                reason: 'Extending the cover by a few more days.',
            );
        } catch (ApprovalRefused $e) {
            return str_contains($e->getMessage(), 'two answers')
                ? []
                : ['the refusal does not say why overlapping is a problem: '.$e->getMessage()];
        }

        return ['two overlapping delegations between the same two people were accepted. They are two '.
            'answers to "may they act today", and the code would read whichever came back first'];
    }

    /** @return list<string> */
    private function delegationWindow(): array
    {
        $this->rule('ALL', ['manager' => 1], priority: 10);
        $deputy = (string) Str::ulid();

        app(\App\Tenant\Purchasing\Services\Delegations::class)->delegate(
            entityId: $this->entityId,
            fromUserId: $this->manager,
            toUserId: $deputy,
            // Last month, and over.
            startsOn: \Illuminate\Support\Carbon::now()->subMonth(),
            endsOn: \Illuminate\Support\Carbon::now()->subMonth()->addWeek(),
            reason: 'Covered the approvals during the April shutdown.',
        );

        $bill = $this->enterBill();
        $request = $this->request($bill);
        $engine = app(ApprovalEngine::class);

        /*
         | Under the manager's roles, so the only thing that can refuse is
         | the delegation.
         |
         | Measured: with empty roles this check passed for the wrong
         | reason - the step did not match - and a bite that removed the
         | delegation requirement entirely left it green.
         */
        try {
            $engine->decide($request, $deputy, 'approved', $engine->fieldsOf($bill), null, ['manager'], $this->manager);
        } catch (ApprovalRefused $e) {
            return str_contains($e->getMessage(), 'no delegation')
                ? []
                : ['the refusal is about something else: '.$e->getMessage()];
        }

        return ['a delegation that ended last month was still honoured. A delegation with no effective '.
            'window is a standing substitution nobody revisits'];
    }

    /** @return list<string> */
    private function revokedDelegation(): array
    {
        $this->rule('ALL', ['manager' => 1], priority: 10);
        $deputy = (string) Str::ulid();
        $delegations = app(\App\Tenant\Purchasing\Services\Delegations::class);

        $delegation = $delegations->delegate(
            entityId: $this->entityId,
            fromUserId: $this->manager,
            toUserId: $deputy,
            startsOn: \Illuminate\Support\Carbon::now()->subDay(),
            endsOn: \Illuminate\Support\Carbon::now()->addWeek(),
            reason: 'Away for the week, the deputy covers approvals.',
        );

        $delegations->revoke($delegation, $this->director, 'Returned early from leave.');

        $bill = $this->enterBill();
        $request = $this->request($bill);
        $engine = app(ApprovalEngine::class);

        try {
            $engine->decide($request, $deputy, 'approved', $engine->fieldsOf($bill), null, ['manager'], $this->manager);
        } catch (ApprovalRefused $e) {
            return str_contains($e->getMessage(), 'no delegation')
                ? []
                : ['the refusal is about something else: '.$e->getMessage()];
        }

        return ['a revoked delegation still let somebody act'];
    }

    /** @return list<string> */
    private function standingReport(): array
    {
        $violations = [];
        $delegations = app(\App\Tenant\Purchasing\Services\Delegations::class);
        $deputy = (string) Str::ulid();

        $delegations->delegate(
            entityId: $this->entityId,
            fromUserId: $this->manager,
            toUserId: $deputy,
            startsOn: \Illuminate\Support\Carbon::now()->subDay(),
            endsOn: \Illuminate\Support\Carbon::now()->addWeek(),
            reason: 'Away for the week of the 18th, the deputy covers.',
        );

        $inForce = $delegations->inForce($this->entityId, \Illuminate\Support\Carbon::now());

        if ($inForce === []) {
            return ['the standing report shows nothing while a delegation is in force. The compliance '.
                'pack needs the list, and a delegation nobody can see is one nobody revisits'];
        }

        if (trim((string) ($inForce[0]['reason'] ?? '')) === '') {
            $violations[] = 'the report does not carry the reason, which is the part an auditor reads';
        }

        $later = $delegations->inForce($this->entityId, \Illuminate\Support\Carbon::now()->addMonth());

        if ($later !== []) {
            $violations[] = 'a delegation that has expired still appears in force';
        }

        return $violations;
    }

    /** @return list<string> */
    private function limitOnConverted(): array
    {
        $violations = [];
        $this->rule('ALL', ['manager' => 1], priority: 10);

        app(\App\Tenant\Purchasing\Services\Delegations::class)->setLimit(
            entityId: $this->entityId,
            amount: '1000.0000',
            currency: 'SAR',
            effectiveFrom: $this->date->copy()->subYear(),
            role: 'manager',
            createdBy: $this->director,
        );

        /*
         | A bill of 10,000 in a weak currency, worth 1,150 converted.
         |
         | A limit tested on the document's own figure would be dodged by
         | invoicing in a currency with a small unit - which is the whole
         | reason PF-138 exists.
         */
        $bill = $this->enterBill();
        $engine = app(ApprovalEngine::class);

        $request = $engine->request(
            entityId: $this->entityId,
            subjectType: 'bill',
            subjectId: (string) $bill->getKey(),
            currency: 'JPY',
            amount: '150000.0000',
            fingerprintFields: $engine->fieldsOf($bill),
            requestedBy: $this->requester,
            functionalAmount: '4200.0000',
            fxRate: '0.028000000000',
            fxRateDate: $this->date->toDateString(),
        );

        try {
            $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($bill), null, ['manager']);
            $violations[] = 'a bill worth 4,200 converted was approved under a 1,000 limit. A limit '.
                'tested on the document\'s own figure is dodged by invoicing in a weak currency';
        } catch (ApprovalRefused $e) {
            if (! str_contains($e->getMessage(), 'converted')) {
                $violations[] = 'the refusal does not say the test is on the converted amount: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function ownLimitWins(): array
    {
        $delegations = app(\App\Tenant\Purchasing\Services\Delegations::class);

        $delegations->setLimit(
            entityId: $this->entityId,
            amount: '1000.0000',
            currency: 'SAR',
            effectiveFrom: $this->date->copy()->subYear(),
            role: 'manager',
        );

        $delegations->setLimit(
            entityId: $this->entityId,
            amount: '50000.0000',
            currency: 'SAR',
            effectiveFrom: $this->date->copy()->subMonth(),
            userId: $this->manager,
        );

        $limit = $delegations->limitFor($this->entityId, $this->manager, ['manager'], $this->date->copy());

        return $limit === '50000.0000'
            ? []
            : [sprintf(
                'the limit resolved to %s. An individual arrangement is deliberate and a role\'s is a '.
                'default, so the user\'s own beats the role\'s',
                (string) $limit,
            )];
    }

    /** @return list<string> */
    private function delegationCap(): array
    {
        $violations = [];
        $this->rule('ALL', ['manager' => 1], priority: 10);
        $delegations = app(\App\Tenant\Purchasing\Services\Delegations::class);
        $deputy = (string) Str::ulid();

        $delegations->setLimit(
            entityId: $this->entityId,
            amount: '100000.0000',
            currency: 'SAR',
            effectiveFrom: $this->date->copy()->subYear(),
            userId: $this->manager,
        );

        /*
         | "You may approve for me, up to 500."
         |
         | A delegation that carried the delegator's limit would make
         | delegation a way to borrow authority.
         */
        $delegations->delegate(
            entityId: $this->entityId,
            fromUserId: $this->manager,
            toUserId: $deputy,
            startsOn: \Illuminate\Support\Carbon::now()->subDay(),
            endsOn: \Illuminate\Support\Carbon::now()->addWeek(),
            reason: 'Away for the week; the deputy may clear small items only.',
            limitAmount: '500.0000',
        );

        $bill = $this->enterBill();
        $request = $this->request($bill);
        $engine = app(ApprovalEngine::class);

        try {
            $engine->decide($request, $deputy, 'approved', $engine->fieldsOf($bill), null, ['manager'], $this->manager);
            $violations[] = 'a 1,150 bill was cleared under a 500 delegation cap, so delegating became '.
                'a way to borrow the delegator\'s authority';
        } catch (ApprovalRefused $e) {
            if (! str_contains($e->getMessage(), '500')) {
                $violations[] = 'the refusal does not name the cap that bound: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function limitIsNotRetroactive(): array
    {
        $violations = [];
        $this->rule('ALL', ['manager' => 1], priority: 10);
        $delegations = app(\App\Tenant\Purchasing\Services\Delegations::class);

        $delegations->setLimit(
            entityId: $this->entityId,
            amount: '100000.0000',
            currency: 'SAR',
            effectiveFrom: $this->date->copy()->subYear(),
            role: 'manager',
        );

        $bill = $this->enterBill();
        $request = $this->request($bill);

        /*
         | The limit is cut to 10 AFTER the chain started.
         |
         | The limit that applied is the one in force when the chain
         | started: reading today's would change the answer to "was this
         | within their authority" for every approval already given.
         */
        /*
         | Effective from a month from NOW, not from the fixture's date.
         |
         | The fixture dates documents in May and the clock is in November,
         | so "the fixture date plus a day" is already in the past and the
         | new limit would legitimately be in force. The chain's own
         | `requested_at` is the real now, which is what the limit test reads.
         */
        $delegations->setLimit(
            entityId: $this->entityId,
            amount: '10.0000',
            currency: 'SAR',
            effectiveFrom: \Illuminate\Support\Carbon::now()->addMonth(),
            role: 'manager',
            reason: 'Tightened after a review.',
        );

        $engine = app(ApprovalEngine::class);

        try {
            $request = $engine->decide($request, $this->manager, 'approved', $engine->fieldsOf($bill), null, ['manager']);

            if (! $request->isApproved()) {
                $violations[] = 'the approval did not complete: '.(string) $request->status;
            }
        } catch (ApprovalRefused $e) {
            $violations[] = 'a limit cut after the chain started was applied to it retroactively: '.$e->getMessage();
        }

        $previous = DB::connection('tenant')->table('approval_limits')
            ->where('entity_id', $this->entityId)
            ->where('role', 'manager')
            ->whereNotNull('effective_to')
            ->count();

        if ($previous === 0) {
            $violations[] = 'the previous limit was replaced rather than closed, so "what was their '.
                'limit in March" has no answer - which is the question an auditor asks about a March '.
                'approval';
        }

        return $violations;
    }

    /** @return list<string> */
    private function rateIsRecorded(): array
    {
        $bill = $this->enterBill();
        $engine = app(ApprovalEngine::class);
        $this->rule('ALL', ['manager' => 1], priority: 10);

        $request = $engine->request(
            entityId: $this->entityId,
            subjectType: 'bill',
            subjectId: (string) $bill->getKey(),
            currency: 'EUR',
            amount: '1000.0000',
            fingerprintFields: $engine->fieldsOf($bill),
            requestedBy: $this->requester,
            functionalAmount: '4100.0000',
            fxRate: '4.100000000000',
            fxRateDate: $this->date->toDateString(),
        );

        $violations = [];

        if ((string) $request->fx_rate !== '4.100000000000') {
            $violations[] = sprintf(
                'the rate on the request is %s. "Why did this need a director" is answered by the rate '.
                'on the day, not by today\'s',
                (string) $request->fx_rate,
            );
        }

        if ($request->fx_rate_date?->toDateString() !== $this->date->toDateString()) {
            $violations[] = 'the rate date was not recorded';
        }

        if ((string) $request->functional_amount !== '4100.0000') {
            $violations[] = 'the converted amount was not recorded';
        }

        return $violations;
    }

    /**
     * A rule with steps.
     *
     * @param  array<string, int>  $roles  role => quorum
     */
    private function rule(
        string $code,
        array $roles,
        int $priority = 100,
        ?string $from = null,
        bool $parallel = false,
        ?int $escalateAfterHours = null,
    ): ApprovalRule {
        $rule = ApprovalRule::create([
            'entity_id' => $this->entityId,
            'code' => $code,
            'name_ar' => 'قاعدة اعتماد',
            'name_en' => 'An approval rule',
            'amount_from' => $from,
            'priority' => $priority,
            'active' => true,
        ]);

        $sequence = 1;

        foreach ($roles as $role => $quorum) {
            ApprovalRuleStep::create([
                'entity_id' => $this->entityId,
                'approval_rule_id' => (string) $rule->getKey(),
                'sequence' => $parallel ? 1 : $sequence,
                'quorum' => $quorum,
                'role' => $role,
                'escalate_after_hours' => $escalateAfterHours,
            ]);

            $sequence++;
        }

        return $rule;
    }

    private function request(Bill $bill): ApprovalRequest
    {
        $engine = app(ApprovalEngine::class);

        return $engine->request(
            entityId: $this->entityId,
            subjectType: 'bill',
            subjectId: (string) $bill->getKey(),
            currency: (string) $bill->currency,
            amount: (string) $bill->gross_amount,
            fingerprintFields: $engine->fieldsOf($bill),
            requestedBy: $this->requester,
            functionalAmount: (string) $bill->gross_amount,
            context: [
                'party_id' => (string) $bill->party_id,
                'account_ids' => $bill->lines->pluck('expense_account_id')->map(static fn ($a): string => (string) $a)->all(),
            ],
        );
    }
}
