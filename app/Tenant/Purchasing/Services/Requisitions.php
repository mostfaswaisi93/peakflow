<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Purchasing\Exceptions\OrderRefused;
use App\Tenant\Purchasing\Models\ApprovalRequest;
use App\Tenant\Purchasing\Models\PurchaseOrder;
use App\Tenant\Purchasing\Models\PurchaseRequisition;
use App\Tenant\Purchasing\Models\PurchaseRequisitionLine;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The request before the commitment - PF-130.
 *
 * ## One approval mechanism, not two
 *
 * A requisition routes through {@see ApprovalEngine} as a subject of type
 * `requisition`. The engine is already generic over the subject, so this
 * costs nothing and buys everything: the same limits, the same delegation,
 * the same fingerprint check that returns an edited document to the start of
 * the chain, and the same segregation-of-duties refusal when the requester
 * tries to approve their own request.
 *
 * A requisition workflow of its own would mean a second set of limits to keep
 * in step with the first, and the one that drifts is the one nobody is
 * looking at.
 *
 * ## It is optional, and the option is enforced where orders are raised
 *
 * `purchasing.require_requisition` defaults to off, because a three-person
 * business forced through a requisition step approves its own requests to
 * itself all day and learns to click past them. Where it is on,
 * {@see PurchaseOrders::raise()} refuses an order that names no approved
 * requisition - which is the only place the rule can be enforced, since the
 * requisition is the document that would otherwise simply not exist.
 *
 * ## Conversion carries the dimensions forward
 *
 * The cost centre that approved the spend is the cost centre the expense
 * lands against. Re-entering it on the order is how a department ends up
 * carrying a cost another department asked for - and the requisition is the
 * only document that records who asked.
 *
 * ## The estimate never becomes the price
 *
 * The requisition's figures are estimates and are named so. Conversion takes
 * the quantity and the accounts; the price comes from the order, because by
 * then the supplier has quoted. An estimate copied silently into a purchase
 * order is a price somebody will later claim was agreed.
 */
final class Requisitions
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly ApprovalEngine $approvals,
        private readonly PurchaseOrders $orders,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Raise a requisition.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws OrderRefused
     */
    public function raise(
        Entity $entity,
        string $requestedBy,
        Carbon $requestedOn,
        string $currency,
        array $lines,
        string $justification,
        ?Carbon $neededBy = null,
        ?string $preferredPartyId = null,
        ?array $dimensions = null,
        ?string $note = null,
    ): PurchaseRequisition {
        if ($lines === []) {
            throw OrderRefused::because('A requisition with no lines requests nothing.');
        }

        if (\strlen(trim($justification)) <= 10) {
            throw OrderRefused::because(
                'A requisition needs a justification. "Needed" is what somebody types to get past a '.
                'required field, and what a budget holder reads three months later when asked why the '.
                'department spent what it did.',
            );
        }

        $entityId = (string) $entity->getKey();
        $dimensions ??= [];

        return DB::connection('tenant')->transaction(function () use (
            $entityId, $requestedBy, $requestedOn, $currency, $lines, $justification,
            $neededBy, $preferredPartyId, $dimensions, $note,
        ): PurchaseRequisition {
            $estimate = $this->estimate($lines, $currency);

            $requisition = PurchaseRequisition::create([
                'entity_id' => $entityId,
                'number' => $this->numbers->next($entityId, 'purchase_requisition', 'REQ', $requestedOn),
                'status' => 'draft',
                'requested_by' => $requestedBy,
                'requested_on' => $requestedOn->toDateString(),
                'needed_by' => $neededBy?->toDateString(),
                'justification' => trim($justification),
                'currency' => $currency,
                'estimated_amount' => (string) $estimate->amount,
                'dimension_1' => $dimensions[0] ?? null,
                'dimension_2' => $dimensions[1] ?? null,
                'dimension_3' => $dimensions[2] ?? null,
                'dimension_4' => $dimensions[3] ?? null,
                'preferred_party_id' => $preferredPartyId,
                'note' => $note,
            ]);

            foreach ($lines as $index => $line) {
                $this->writeLine($requisition, $line, $index, $currency);
            }

            $this->audit->record(
                category: 'purchasing',
                action: 'requisition.raised',
                subjectType: 'requisition',
                subjectId: (string) $requisition->getKey(),
                subjectLabel: $requisition->label(),
                after: [
                    'requested_by' => $requestedBy,
                    'estimate' => (string) $estimate->amount,
                    'currency' => $currency,
                    'lines' => \count($lines),
                    'needed_by' => $neededBy?->toDateString(),
                ],
                reason: trim($justification),
                entityId: $entityId,
            );

            return $requisition->refresh();
        });
    }

    /**
     * Submit it for approval, through the bill engine.
     *
     * @throws OrderRefused
     */
    public function submit(PurchaseRequisition $requisition, ?string $userId = null): ApprovalRequest
    {
        $requisition = $requisition->refresh()->load('lines');

        if (! $requisition->isDraft()) {
            throw OrderRefused::because(sprintf(
                'Requisition %s is %s, so it is not waiting to be submitted. Re-submitting an approved '.
                'request would open a second chain over a document the first chain already decided.',
                $requisition->label(),
                (string) $requisition->status,
            ));
        }

        $request = $this->approvals->request(
            entityId: (string) $requisition->entity_id,
            subjectType: PurchaseRequisition::SUBJECT_TYPE,
            subjectId: (string) $requisition->getKey(),
            currency: (string) $requisition->currency,
            amount: (string) $requisition->estimated_amount,
            fingerprintFields: $this->fieldsOf($requisition),
            requestedBy: $userId ?? (string) $requisition->requested_by,
        );

        $requisition->update(['status' => 'awaiting_approval', 'submitted_at' => now()]);

        return $request;
    }

    /**
     * Record that the chain approved it.
     *
     * Read from the request rather than told, so a requisition cannot be
     * marked approved by a caller who did not go through the engine - which
     * would bypass the limits, the delegation record and the duty check in
     * one call.
     *
     * @throws OrderRefused
     */
    public function recordApproval(PurchaseRequisition $requisition, ApprovalRequest $request): PurchaseRequisition
    {
        $requisition = $requisition->refresh();
        $request = $request->refresh();

        if ((string) $request->subject_id !== (string) $requisition->getKey()) {
            throw OrderRefused::because(
                'That approval belongs to another document. Marking a requisition approved from '.
                "somebody else's chain is an approval nobody gave.",
            );
        }

        if (! $request->isApproved()) {
            throw OrderRefused::because(sprintf(
                'The approval chain for %s is %s, not approved.',
                $requisition->label(),
                (string) $request->status,
            ));
        }

        $decider = DB::connection('tenant')->table('approval_decisions as d')
            ->join('approval_steps as s', 's.id', '=', 'd.approval_step_id')
            ->where('s.approval_request_id', (string) $request->getKey())
            ->where('d.decision', 'approved')
            ->orderByDesc('d.decided_at')
            ->value('d.user_id');

        $requisition->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $decider === null ? null : (string) $decider,
        ]);

        $this->audit->record(
            category: 'purchasing',
            action: 'requisition.approved',
            subjectType: 'requisition',
            subjectId: (string) $requisition->getKey(),
            subjectLabel: $requisition->label(),
            after: [
                'approval_request_id' => (string) $request->getKey(),
                'estimate' => (string) $requisition->estimated_amount,
            ],
            entityId: (string) $requisition->entity_id,
        );

        return $requisition->refresh();
    }

    /**
     * Reject it, with a reason the requester can act on.
     *
     * @throws OrderRefused
     */
    public function reject(PurchaseRequisition $requisition, string $userId, string $reason): PurchaseRequisition
    {
        if (\strlen(trim($reason)) <= 5) {
            throw OrderRefused::because(
                'A rejection needs a reason. The requester is going to ask, and "rejected" with '.
                'nothing behind it is what makes them re-submit the same request with a different '.
                'title.',
            );
        }

        $requisition->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejected_by' => $userId,
            'rejection_reason' => trim($reason),
        ]);

        $this->audit->record(
            category: 'purchasing',
            action: 'requisition.rejected',
            subjectType: 'requisition',
            subjectId: (string) $requisition->getKey(),
            subjectLabel: $requisition->label(),
            after: ['rejected_by' => $userId],
            reason: trim($reason),
            entityId: (string) $requisition->entity_id,
        );

        return $requisition->refresh();
    }

    /**
     * Convert an approved requisition into an order.
     *
     * The prices come from `$prices`, keyed by requisition line id, because
     * by now the supplier has quoted. Where one is missing the estimate is
     * used and the order says so in its note - which is honest, and better
     * than refusing: a requisition for stationery at a catalogue price
     * genuinely needs no re-quoting.
     *
     * @param  array<string, string>  $prices  requisition line id => agreed unit price
     *
     * @throws OrderRefused
     */
    public function convert(
        PurchaseRequisition $requisition,
        Entity $entity,
        string $partyId,
        Carbon $orderedOn,
        array $prices = [],
        ?string $userId = null,
        ?Carbon $expectedOn = null,
        ?string $paymentTermId = null,
    ): PurchaseOrder {
        $requisition = $requisition->refresh()->load('lines');

        /*
         | Already converted, checked BEFORE the approval state.
         |
         | The status moves approved -> converted, so the approval check
         | below would catch this too - and it would say "this request is
         | not approved", which is both true and useless. The person
         | reading it raised an order ten minutes ago and needs to be told
         | which one.
         */
        if ($requisition->isConverted()) {
            throw OrderRefused::because(sprintf(
                'Requisition %s was already converted into order %s. A second order from one request '.
                'buys the same thing twice, and both orders look authorised.',
                $requisition->label(),
                (string) (PurchaseOrder::query()
                    ->whereKey((string) $requisition->purchase_order_id)
                    ->value('number') ?? $requisition->purchase_order_id),
            ));
        }

        if (! $requisition->isApproved()) {
            throw OrderRefused::because(sprintf(
                'Requisition %s is %s. An order raised from an unapproved request commits the business '.
                'to money nobody agreed to spend, which is the one thing the requisition step exists '.
                'to prevent.',
                $requisition->label(),
                (string) $requisition->status,
            ));
        }

        $lines = [];
        $estimated = [];

        foreach ($requisition->lines as $line) {
            $lineId = (string) $line->getKey();
            $price = $prices[$lineId] ?? (string) $line->estimated_unit_price;

            if (! isset($prices[$lineId])) {
                $estimated[] = (int) $line->line_no;
            }

            /*
             | The dimensions come from the line, then the header.
             |
             | The cost centre that approved the spend is the one the
             | expense lands against. Re-entering it on the order is how a
             | department ends up carrying a cost another department asked
             | for.
             */
            $dimensions = $line->dimensions();
            $header = $requisition->dimensions();

            $lines[] = [
                'line_no' => (int) $line->line_no,
                'description' => (string) $line->description,
                'line_type' => (string) $line->line_type,
                'item_id' => $line->item_id === null ? null : (string) $line->item_id,
                'unit_code' => (string) $line->unit_code,
                'quantity_ordered' => (string) $line->quantity,
                'unit_price' => $price,
                'expense_account_id' => $line->expense_account_id === null ? null : (string) $line->expense_account_id,
                'expected_on' => $line->needed_by?->toDateString(),
                'dimension_1' => $dimensions[0] ?? $header[0] ?? null,
                'dimension_2' => $dimensions[1] ?? $header[1] ?? null,
                'dimension_3' => $dimensions[2] ?? $header[2] ?? null,
                'dimension_4' => $dimensions[3] ?? $header[3] ?? null,
            ];
        }

        return DB::connection('tenant')->transaction(function () use (
            $requisition, $entity, $partyId, $orderedOn, $lines, $estimated,
            $userId, $expectedOn, $paymentTermId,
        ): PurchaseOrder {
            $order = $this->orders->raise(
                entity: $entity,
                partyId: $partyId,
                orderedOn: $orderedOn,
                currency: (string) $requisition->currency,
                lines: $lines,
                expectedOn: $expectedOn ?? ($requisition->needed_by === null
                    ? null
                    : Carbon::parse($requisition->needed_by->toDateString())),
                createdBy: $userId,
                paymentTermId: $paymentTermId,
                note: $estimated === []
                    ? sprintf('Raised from requisition %s.', $requisition->label())
                    : sprintf(
                        'Raised from requisition %s. Line(s) %s carry the requisition\'s estimated '.
                        'price, not a quoted one.',
                        $requisition->label(),
                        implode(', ', array_map('strval', $estimated)),
                    ),
                requisitionId: (string) $requisition->getKey(),
            );

            $requisition->update([
                'status' => 'converted',
                'converted_at' => now(),
                'purchase_order_id' => (string) $order->getKey(),
            ]);

            $this->audit->record(
                category: 'purchasing',
                action: 'requisition.converted',
                subjectType: 'requisition',
                subjectId: (string) $requisition->getKey(),
                subjectLabel: $requisition->label(),
                after: [
                    'purchase_order_id' => (string) $order->getKey(),
                    'order' => $order->label(),
                    'supplier' => $partyId,
                    'estimated_prices_kept' => $estimated,
                ],
                entityId: (string) $requisition->entity_id,
                partyId: $partyId,
            );

            return $order;
        });
    }

    /**
     * The fields the approval fingerprint covers.
     *
     * The justification is in it deliberately: changing *why* after somebody
     * approved it is a different request, and the budget holder agreed to the
     * reason as much as to the figure.
     *
     * The note is not, because it is correspondence rather than substance.
     *
     * @return array<string, mixed>
     */
    public function fieldsOf(PurchaseRequisition $requisition): array
    {
        return [
            'currency' => (string) $requisition->currency,
            'estimated_amount' => (string) $requisition->estimated_amount,
            'justification' => (string) $requisition->justification,
            'needed_by' => $requisition->needed_by?->toDateString(),
            'dimensions' => $requisition->dimensions(),
            'lines' => $requisition->lines->map(static fn (PurchaseRequisitionLine $line): array => [
                'line_no' => (int) $line->line_no,
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'estimated_unit_price' => (string) $line->estimated_unit_price,
                'expense_account_id' => $line->expense_account_id === null ? null : (string) $line->expense_account_id,
            ])->all(),
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function estimate(array $lines, string $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($lines as $line) {
            $total = $total->plus($this->lineEstimate($line, $currency));
        }

        return $total;
    }

    /** @param array<string, mixed> $line */
    private function lineEstimate(array $line, string $currency): Money
    {
        if (isset($line['estimated_amount'])) {
            return Money::of((string) $line['estimated_amount'], $currency);
        }

        return Money::ofRounded(
            (string) BigDecimal::of((string) ($line['quantity'] ?? '1'))
                ->multipliedBy((string) ($line['estimated_unit_price'] ?? '0')),
            $currency,
            RoundingPolicy::halfUp(Scale::Amount),
        );
    }

    /** @param array<string, mixed> $line */
    private function writeLine(
        PurchaseRequisition $requisition,
        array $line,
        int $index,
        string $currency,
    ): PurchaseRequisitionLine {
        return PurchaseRequisitionLine::create([
            'entity_id' => (string) $requisition->entity_id,
            'purchase_requisition_id' => (string) $requisition->getKey(),
            'line_no' => (int) ($line['line_no'] ?? $index + 1),
            'item_id' => $line['item_id'] ?? null,
            'description' => (string) $line['description'],
            'line_type' => (string) ($line['line_type'] ?? 'goods'),
            'unit_code' => (string) ($line['unit_code'] ?? 'C62'),
            'quantity' => (string) ($line['quantity'] ?? '1'),
            'estimated_unit_price' => (string) ($line['estimated_unit_price'] ?? '0'),
            'estimated_amount' => (string) $this->lineEstimate($line, $currency)->amount,
            'expense_account_id' => $line['expense_account_id'] ?? null,
            'needed_by' => isset($line['needed_by'])
                ? Carbon::parse((string) $line['needed_by'])->toDateString()
                : null,
            'dimension_1' => $line['dimension_1'] ?? null,
            'dimension_2' => $line['dimension_2'] ?? null,
            'dimension_3' => $line['dimension_3'] ?? null,
            'dimension_4' => $line['dimension_4'] ?? null,
        ]);
    }
}
