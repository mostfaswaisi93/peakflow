<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Purchasing\Exceptions\ApprovalRefused;
use App\Tenant\Purchasing\Exceptions\OrderRefused;
use App\Tenant\Purchasing\Models\ApprovalRequest;
use App\Tenant\Purchasing\Models\ApprovalRule;
use App\Tenant\Purchasing\Models\ApprovalRuleStep;
use App\Tenant\Purchasing\Models\PurchaseRequisition;
use App\Tenant\Purchasing\Services\ApprovalEngine;
use App\Tenant\Purchasing\Services\PurchaseOrders;
use App\Tenant\Purchasing\Services\Requisitions;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Purchase requisitions - PF-130.
 *
 * ## The step only earns its keep if three things hold
 *
 * The justification is real, the approval goes through the **same** engine as
 * a bill's, and the dimensions reach the order. Take away the first and a
 * requisition is a purchase order with extra clicks; the second and there are
 * two sets of limits to keep in step, of which the unwatched one drifts; the
 * third and a department carries a cost another department asked for.
 *
 * ## And the step is optional
 *
 * A three-person business forced through it approves its own requests to
 * itself all day and learns to click past them - and an approval somebody has
 * learned to click past is worse than none, because in an audit it looks like
 * a control.
 */
final class RequisitionRules
{
    use \Tests\Support\Concerns\EntersBills;

    private string $requester = '';

    private string $manager = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['requisitions (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();

            $this->requester = (string) Str::ulid();
            $this->manager = (string) Str::ulid();

            return $this->runEachInASavepoint([
                'a requisition is raised with its estimate' => fn (): array => $this->raising(),
                'a requisition with no justification is refused' => fn (): array => $this->justification(),
                'and the database insists on one too' => fn (): array => $this->justificationInTheDatabase(),
                'a requisition posts nothing' => fn (): array => $this->postsNothing(),
                'it routes through the bill approval engine' => fn (): array => $this->oneEngine(),
                'the requester cannot approve their own request' => fn (): array => $this->ownRequest(),
                'editing the justification restarts the chain' => fn (): array => $this->editedJustification(),
                'approval is read from the chain, not asserted' => fn (): array => $this->approvalIsRead(),
                'an unapproved requisition cannot become an order' => fn (): array => $this->unapprovedConversion(),
                'conversion carries the dimensions forward' => fn (): array => $this->dimensionsCarryForward(),
                'conversion takes the quoted price, not the estimate' => fn (): array => $this->quotedPrice(),
                'and says so when it keeps an estimate' => fn (): array => $this->estimateKept(),
                'one requisition converts once' => fn (): array => $this->convertsOnce(),
                'a rejection needs a reason' => fn (): array => $this->rejection(),
                'the step is optional by default' => fn (): array => $this->optional(),
                'and required where the entity asks for it' => fn (): array => $this->required(),
                'another entity cannot see these requisitions' => fn (): array => $this->entityScope(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function raising(): array
    {
        $violations = [];
        $requisition = $this->raise();

        if ($requisition->number === null) {
            $violations[] = 'the requisition has no number, so nothing can reference it';
        }

        // 10 at 25 plus 4 at 150 = 850.
        if (! BigDecimal::of((string) $requisition->estimated_amount)->isEqualTo('850.0000')) {
            $violations[] = sprintf(
                'the estimate is %s and 10 at 25 plus 4 at 150 is 850',
                (string) $requisition->estimated_amount,
            );
        }

        if ($requisition->lines->count() !== 2) {
            $violations[] = 'the requisition did not keep its lines';
        }

        return $violations;
    }

    /** @return list<string> */
    private function justification(): array
    {
        try {
            $this->raise(justification: 'needed');

            return [
                'a requisition was raised with "needed" as its justification. Strip the justification '.
                'out and a requisition is a purchase order with extra clicks: what makes the step worth '.
                'having is that somebody wrote down why and somebody else read it',
            ];
        } catch (OrderRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function justificationInTheDatabase(): array
    {
        $requisition = $this->raise();

        try {
            DB::connection('tenant')->table('purchase_requisitions')
                ->where('id', (string) $requisition->getKey())
                ->update(['justification' => 'needed']);

            return [
                'the database accepted "needed" as a justification, so an import can produce the '.
                'document the service refuses',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'justification_says_something')) {
                return ['the justification was refused by something other than its constraint: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function postsNothing(): array
    {
        $before = DB::connection('tenant')->table('journal_entries')->count();
        $this->raise();
        $after = DB::connection('tenant')->table('journal_entries')->count();

        if ($after !== $before) {
            return [sprintf(
                'raising a requisition posted %d journal entries. A requisition is one step further '.
                'back than an order: the business has not even chosen who to buy from, so there is '.
                'nothing that has happened for the ledger to record',
                $after - $before,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function oneEngine(): array
    {
        $violations = [];
        $rule = $this->rule('REQ-ONE', ['purchasing.manager' => 1]);

        /*
         | One rule, two kinds of document.
         |
         | Which is what "one approval mechanism and not two" means
         | observably: the rule a business writes for its spending applies to
         | the request and to the bill, and both chains are rows in the same
         | tables compiled by the same engine.
         |
         | Measured: asserting the request's `subject_type` against the
         | model's own constant was circular - a bite that changed the
         | constant changed both sides and walked past the check. This
         | compares two documents against one configured rule, which a
         | workflow of its own could not satisfy.
         */
        $requisition = $this->raise();
        $request = app(Requisitions::class)->submit($requisition, $this->requester);

        $bill = $this->enterBill();
        $billRequest = app(ApprovalEngine::class)->request(
            entityId: $this->entityId,
            subjectType: 'bill',
            subjectId: (string) $bill->getKey(),
            currency: (string) $bill->currency,
            amount: (string) $bill->gross_amount,
            fingerprintFields: app(ApprovalEngine::class)->fieldsOf($bill),
            requestedBy: $this->requester,
        );

        $ruleOf = static fn (ApprovalRequest $r): array => $r->steps()
            ->pluck('approval_rule_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->all();

        $forRequisition = $ruleOf($request);
        $forBill = $ruleOf($billRequest);

        if ($forRequisition === [] || $forBill === []) {
            $violations[] = sprintf(
                'the chains compiled %d step rule(s) for the requisition and %d for the bill. A '.
                'document with no chain cannot be approved',
                \count($forRequisition),
                \count($forBill),
            );
        }

        if ($forRequisition !== $forBill || $forRequisition !== [(string) $rule->getKey()]) {
            $violations[] = sprintf(
                'the requisition compiled from rule(s) %s and the bill from %s. One approval mechanism '.
                'and not two means the rule a business writes for its spending applies to both: a '.
                'second workflow is a second set of limits to keep in step, and the one that drifts is '.
                'the one nobody is looking at',
                implode(', ', $forRequisition) ?: 'none',
                implode(', ', $forBill) ?: 'none',
            );
        }

        if ((string) $requisition->refresh()->status !== 'awaiting_approval') {
            $violations[] = sprintf(
                'a submitted requisition is %s',
                (string) $requisition->refresh()->status,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function ownRequest(): array
    {
        $this->rule('REQ-OWN', ['purchasing.manager' => 1]);

        $requisition = $this->raise();
        $request = app(Requisitions::class)->submit($requisition, $this->requester);

        try {
            app(ApprovalEngine::class)->decide(
                request: $request,
                userId: $this->requester,
                decision: 'approved',
                currentFields: app(Requisitions::class)->fieldsOf($requisition->refresh()->load('lines')),
                userRoles: ['purchasing.manager'],
            );

            return [
                'the requester approved their own requisition. The engine asks the same '.
                'segregation-of-duties question it asks about a bill, which is the reason a requisition '.
                'goes through it rather than through a workflow of its own',
            ];
        } catch (ApprovalRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function editedJustification(): array
    {
        $this->rule('REQ-EDIT', ['purchasing.manager' => 1]);

        $requisition = $this->raise();
        $request = app(Requisitions::class)->submit($requisition, $this->requester);

        /*
         | The reason changes after somebody was asked to approve it.
         |
         | The budget holder agreed to the reason as much as to the figure,
         | so changing why is a different request - and the fingerprint has
         | to cover the justification for that to hold.
         */
        $requisition->update([
            'justification' => 'actually it is for the other project, which has a different budget',
        ]);

        try {
            app(ApprovalEngine::class)->decide(
                request: $request,
                userId: $this->manager,
                decision: 'approved',
                currentFields: app(Requisitions::class)->fieldsOf($requisition->refresh()->load('lines')),
                userRoles: ['purchasing.manager'],
            );

            return [
                'a requisition whose justification changed after submission was approved against the '.
                'new text. The budget holder agreed to the reason as much as to the figure, so this is '.
                'an approval of something nobody was asked about',
            ];
        } catch (ApprovalRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function approvalIsRead(): array
    {
        $violations = [];
        $this->rule('REQ-READ', ['purchasing.manager' => 1]);

        $requisition = $this->raise();
        $request = app(Requisitions::class)->submit($requisition, $this->requester);

        /*
         | Marking it approved from a chain that has not approved it.
         |
         | Which would bypass the limits, the delegation record and the duty
         | check in one call, so the service reads the chain rather than
         | being told.
         */
        try {
            app(Requisitions::class)->recordApproval($requisition, $request);

            $violations[] = 'a requisition was marked approved while its chain was still pending';
        } catch (OrderRefused) {
            // Expected.
        }

        $approved = $this->approve($requisition, $request);

        if (! $approved->isApproved()) {
            $violations[] = 'a requisition whose chain cleared is not approved';
        }

        if ($approved->approved_by === null) {
            $violations[] = 'an approved requisition does not record who approved it, so "who agreed to '.
                'this" is answered by reading the chain backwards';
        }

        return $violations;
    }

    /** @return list<string> */
    private function unapprovedConversion(): array
    {
        $requisition = $this->raise();

        try {
            app(Requisitions::class)->convert(
                $requisition,
                $this->entity(),
                (string) $this->supplier?->getKey(),
                $this->date->copy(),
                userId: $this->manager,
            );

            return [
                'a draft requisition became a purchase order. An order raised from an unapproved '.
                'request commits the business to money nobody agreed to spend, which is the one thing '.
                'the step exists to prevent',
            ];
        } catch (OrderRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function dimensionsCarryForward(): array
    {
        $violations = [];
        $this->rule('REQ-DIM', ['purchasing.manager' => 1]);

        $costCentre = (string) Str::ulid();
        $requisition = $this->raise(dimensions: [$costCentre]);
        $request = app(Requisitions::class)->submit($requisition, $this->requester);
        $requisition = $this->approve($requisition, $request);

        $order = app(Requisitions::class)->convert(
            $requisition,
            $this->entity(),
            (string) $this->supplier?->getKey(),
            $this->date->copy(),
            userId: $this->manager,
        );

        foreach ($order->lines as $line) {
            if ((string) $line->dimension_1 !== $costCentre) {
                $violations[] = sprintf(
                    'order line %d carries dimension_1 %s and the requisition\'s cost centre is %s. The '.
                    'cost centre that approved the spend is the one the expense lands against; '.
                    're-entering it on the order is how a department ends up carrying a cost another '.
                    'department asked for',
                    (int) $line->line_no,
                    (string) ($line->dimension_1 ?? 'nothing'),
                    $costCentre,
                );

                break;
            }
        }

        if ($order->purchase_requisition_id === null) {
            $violations[] = 'the order does not name the requisition it came from, so "what came of my '.
                'request" is answered by searching the orders for something that looks similar';
        }

        if ((string) $requisition->refresh()->purchase_order_id !== (string) $order->getKey()) {
            $violations[] = 'the requisition does not name the order it became';
        }

        return $violations;
    }

    /** @return list<string> */
    private function quotedPrice(): array
    {
        $violations = [];
        $this->rule('REQ-PRICE', ['purchasing.manager' => 1]);

        $requisition = $this->raise();
        $request = app(Requisitions::class)->submit($requisition, $this->requester);
        $requisition = $this->approve($requisition, $request);

        $first = $requisition->lines->first();

        $order = app(Requisitions::class)->convert(
            $requisition,
            $this->entity(),
            (string) $this->supplier?->getKey(),
            $this->date->copy(),
            prices: array_merge(
                [(string) $first->getKey() => '30'],
                $requisition->lines->skip(1)->mapWithKeys(
                    static fn ($line): array => [(string) $line->getKey() => (string) $line->estimated_unit_price],
                )->all(),
            ),
            userId: $this->manager,
        );

        $orderLine = $order->lines->firstWhere('line_no', (int) $first->line_no);

        if (! BigDecimal::of((string) $orderLine->unit_price)->isEqualTo('30')) {
            $violations[] = sprintf(
                'the order line is priced %s and the supplier quoted 30. An estimate copied silently '.
                'into a purchase order is a price somebody will later claim was agreed',
                (string) $orderLine->unit_price,
            );
        }

        if (str_contains((string) $order->note, 'estimated price')) {
            $violations[] = 'the order warns about an estimated price although every line was quoted';
        }

        return $violations;
    }

    /** @return list<string> */
    private function estimateKept(): array
    {
        $this->rule('REQ-EST', ['purchasing.manager' => 1]);

        $requisition = $this->raise();
        $request = app(Requisitions::class)->submit($requisition, $this->requester);
        $requisition = $this->approve($requisition, $request);

        $order = app(Requisitions::class)->convert(
            $requisition,
            $this->entity(),
            (string) $this->supplier?->getKey(),
            $this->date->copy(),
            userId: $this->manager,
        );

        if (! str_contains((string) $order->note, 'estimated')) {
            return [
                'an order converted with no quoted prices says nothing about it. Refusing would be '.
                'wrong - stationery at a catalogue price genuinely needs no re-quoting - but the order '.
                'has to say which lines carry an estimate, or somebody will read it as an agreed price',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function convertsOnce(): array
    {
        $this->rule('REQ-ONCE', ['purchasing.manager' => 1]);

        $requisition = $this->raise();
        $request = app(Requisitions::class)->submit($requisition, $this->requester);
        $requisition = $this->approve($requisition, $request);

        app(Requisitions::class)->convert(
            $requisition,
            $this->entity(),
            (string) $this->supplier?->getKey(),
            $this->date->copy(),
            userId: $this->manager,
        );

        try {
            app(Requisitions::class)->convert(
                $requisition->refresh(),
                $this->entity(),
                (string) $this->supplier?->getKey(),
                $this->date->copy(),
                userId: $this->manager,
            );

            return [
                'one requisition produced two orders. Both look authorised, and the business buys the '.
                'same thing twice',
            ];
        } catch (OrderRefused $e) {
            /*
             | And the refusal says which order already exists.
             |
             | Measured: the status moves approved -> converted, so the
             | approval check catches this too and says "this request is not
             | approved" - both true and useless to somebody who raised an
             | order ten minutes ago. Without this assertion the check
             | passed with the specific guard removed.
             */
            if (! str_contains($e->getMessage(), 'already converted into order')) {
                return [sprintf(
                    'the refusal does not say which order already exists: "%s"',
                    $e->getMessage(),
                )];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function rejection(): array
    {
        $violations = [];
        $requisition = $this->raise();

        try {
            app(Requisitions::class)->reject($requisition, $this->manager, 'no');

            $violations[] = 'a requisition was rejected with "no". The requester is going to ask, and a '.
                'rejection with nothing behind it is what makes them re-submit the same request with a '.
                'different title';
        } catch (OrderRefused) {
            // Expected.
        }

        $rejected = app(Requisitions::class)->reject(
            $requisition,
            $this->manager,
            'the budget for this cost centre is committed until the next quarter',
        );

        if ((string) $rejected->status !== 'rejected' || $rejected->rejection_reason === null) {
            $violations[] = 'a rejected requisition does not keep its reason';
        }

        return $violations;
    }

    /** @return list<string> */
    private function optional(): array
    {
        /*
         | By default, an order needs no requisition at all.
         |
         | A three-person business forced through the step approves its own
         | requests to itself all day and learns to click past them - and an
         | approval somebody has learned to click past is worse than none,
         | because in an audit it looks like a control.
         */
        try {
            app(PurchaseOrders::class)->raise(
                entity: $this->entity(),
                partyId: (string) $this->supplier?->getKey(),
                orderedOn: $this->date->copy(),
                currency: 'SAR',
                lines: [$this->orderLine()],
                createdBy: $this->manager,
            );

            return [];
        } catch (OrderRefused $e) {
            return ['an order with no requisition was refused although the entity does not require one: '
                .$e->getMessage()];
        }
    }

    /** @return list<string> */
    private function required(): array
    {
        $violations = [];
        $settings = app(Settings::class);

        $settings->set('purchasing.require_requisition', true, $this->entityId);
        $settings->flush();

        try {
            app(PurchaseOrders::class)->raise(
                entity: $this->entity(),
                partyId: (string) $this->supplier?->getKey(),
                orderedOn: $this->date->copy(),
                currency: 'SAR',
                lines: [$this->orderLine()],
                createdBy: $this->manager,
            );

            $violations[] = 'with the requisition step required, an order was raised with no request '.
                'behind it. The check has to be here rather than in the requisition service, because '.
                'the requisition is the document that would otherwise simply not exist';
        } catch (OrderRefused) {
            // Expected.
        }

        // And an approved requisition still gets through.
        $this->rule('REQ-REQD', ['purchasing.manager' => 1]);
        $requisition = $this->raise();
        $request = app(Requisitions::class)->submit($requisition, $this->requester);
        $requisition = $this->approve($requisition, $request);

        try {
            app(Requisitions::class)->convert(
                $requisition,
                $this->entity(),
                (string) $this->supplier?->getKey(),
                $this->date->copy(),
                userId: $this->manager,
            );
        } catch (OrderRefused $e) {
            $violations[] = 'an approved requisition could not be converted with the step required: '
                .$e->getMessage();
        }

        $settings->set('purchasing.require_requisition', false, $this->entityId);
        $settings->flush();

        return $violations;
    }

    /** @return list<string> */
    private function entityScope(): array
    {
        $requisition = $this->raise();

        $other = \App\Tenant\Tenancy\Models\Entity::create([
            'code' => 'RQ'.Str::upper(Str::random(6)),
            'legal_name' => 'A second entity in the same tenant',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $context = app(\App\Tenant\Tenancy\Services\EntityContext::class);

        try {
            $context->use($other);

            $visible = PurchaseRequisition::query()->whereKey((string) $requisition->getKey())->count();

            return $visible === 0
                ? []
                : ['a requisition belonging to another entity is visible from this one'];
        } finally {
            $context->use($this->entity());
        }
    }

    // ---- Helpers -------------------------------------------------------

    /** @param list<string>|null $dimensions */
    private function raise(
        string $justification = 'The calibration jigs on line two are out of tolerance and the batch is due in June.',
        ?array $dimensions = null,
    ): PurchaseRequisition {
        return app(Requisitions::class)->raise(
            entity: $this->entity(),
            requestedBy: $this->requester,
            requestedOn: $this->date->copy(),
            currency: 'SAR',
            lines: [
                [
                    'description' => 'Calibration jigs',
                    'quantity' => '10',
                    'estimated_unit_price' => '25',
                    'expense_account_id' => $this->expenseAccountId,
                ],
                [
                    'description' => 'Replacement sensor heads',
                    'quantity' => '4',
                    'estimated_unit_price' => '150',
                    'expense_account_id' => $this->expenseAccountId,
                ],
            ],
            justification: $justification,
            neededBy: $this->date->copy()->addDays(21),
            dimensions: $dimensions,
        );
    }

    /** @return array<string, mixed> */
    private function orderLine(): array
    {
        return [
            'description' => 'Calibration jigs',
            'quantity_ordered' => '10',
            'unit_price' => '25',
            'expense_account_id' => $this->expenseAccountId,
            'tax_rate' => '15.000000',
        ];
    }

    /** @param array<string, int> $roles */
    private function rule(string $code, array $roles): ApprovalRule
    {
        $rule = ApprovalRule::create([
            'entity_id' => $this->entityId,
            'code' => $code,
            'name_ar' => 'قاعدة اعتماد طلبات الشراء',
            'name_en' => 'A requisition approval rule',
            'priority' => 100,
            'active' => true,
        ]);

        $sequence = 1;

        foreach ($roles as $role => $quorum) {
            ApprovalRuleStep::create([
                'entity_id' => $this->entityId,
                'approval_rule_id' => (string) $rule->getKey(),
                'sequence' => $sequence++,
                'quorum' => $quorum,
                'role' => $role,
            ]);
        }

        return $rule;
    }

    private function approve(PurchaseRequisition $requisition, ApprovalRequest $request): PurchaseRequisition
    {
        app(ApprovalEngine::class)->decide(
            request: $request,
            userId: $this->manager,
            decision: 'approved',
            currentFields: app(Requisitions::class)->fieldsOf($requisition->refresh()->load('lines')),
            userRoles: ['purchasing.manager'],
        );

        return app(Requisitions::class)->recordApproval($requisition, $request->refresh());
    }
}
