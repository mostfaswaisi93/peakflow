<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Purchasing\Exceptions\ApprovalRefused;
use App\Tenant\Purchasing\Models\ApprovalRequest;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * A supplier bill's way through approval, to the ledger - PF-265.
 *
 * ## What was missing
 *
 * `bills.status` has allowed `awaiting_approval` and `approved` since the table
 * was created, and {@see ApprovalEngine} has been able to run a chain about a
 * bill for as long - but nothing joined them: a bill went from draft straight
 * to posted, approved by nobody (see the note on {@see Bills::post()}). This
 * is the join, and it adds nothing to either side: the engine decides who may
 * approve, at what amount, in what order, and whether the bill changed under
 * them; {@see Bills::post()} books it.
 *
 * ## draft -> awaiting_approval -> posted, or back to draft
 *
 * - {@see submit()} opens the chain on a draft. The chain is compiled from
 *   the entity's rules once and written down, and a bill no rule covers is
 *   refused rather than waved through.
 * - {@see decide()} records one approver's decision. While steps remain the
 *   bill waits. When the LAST step clears, the bill is approved and posted in
 *   the same transaction - so "approved" never means "approved, and somebody
 *   still has to remember to book it", and a bill the ledger refuses (a closed
 *   period, an unacknowledged duplicate) is not left approved-but-unbooked:
 *   the decision is refused with the ledger's reason and can be made again
 *   once that is fixed.
 * - A rejection, or a bill that changed under its chain, returns it to draft,
 *   to be corrected and submitted again - from the beginning, because the
 *   earlier approvers approved a different document.
 */
final class BillApprovals
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly Bills $bills,
        private readonly AuditTrail $audit,
    ) {}

    /** @throws ApprovalRefused */
    public function submit(Bill $bill, Entity $entity, string $userId): ApprovalRequest
    {
        return DB::connection('tenant')->transaction(function () use ($bill, $entity, $userId): ApprovalRequest {
            /** @var Bill $locked */
            $locked = Bill::query()->lockForUpdate()->findOrFail($bill->id);

            $status = self::text($locked->status);

            if ($status !== 'draft') {
                throw ApprovalRefused::notADraft($status);
            }

            $currency = self::text($locked->currency);
            $gross = self::text($locked->gross_amount);
            $functional = self::text($entity->functional_currency);
            $fxRate = self::text($locked->getAttribute('fx_rate'));

            /*
             | The amount a limit is tested on is the FUNCTIONAL one, so a
             | limit cannot be dodged by entering the bill in a weak currency.
             | Unknown for a foreign bill with no rate yet - the engine then
             | tests the document amount, and says so.
             */
            $functionalAmount = match (true) {
                $currency === $functional => $gross,
                $fxRate !== '' => (string) BigDecimal::of($gross)->multipliedBy($fxRate)->toScale(4, RoundingMode::HalfUp),
                default => null,
            };

            $request = $this->engine->request(
                entityId: $entity->id,
                subjectType: 'bill',
                subjectId: $locked->id,
                currency: $currency,
                amount: $gross,
                fingerprintFields: $this->engine->fieldsOf($locked),
                requestedBy: $userId,
                functionalAmount: $functionalAmount,
                fxRate: $fxRate === '' ? null : $fxRate,
                fxRateDate: self::text($locked->getAttribute('fx_rate_date')) ?: null,
                context: [
                    'party_id' => self::text($locked->party_id),
                    'account_ids' => array_values(array_unique(array_map(
                        static fn (mixed $id): string => self::text($id),
                        $locked->lines()->pluck('expense_account_id')->all(),
                    ))),
                ],
            );

            $locked->forceFill(['status' => 'awaiting_approval'])->save();

            $this->audit->record(
                category: 'purchasing',
                action: 'bill.submitted_for_approval',
                subjectType: 'bill',
                subjectId: $locked->id,
                subjectLabel: self::text($locked->getAttribute('supplier_document_number')),
                after: ['approval_request_id' => $request->id, 'amount' => $gross, 'currency' => $currency],
                reason: 'submitted for approval; the chain was compiled from the entity\'s rules and written down',
            );

            return $request;
        });
    }

    /**
     * One approver's decision, and what it does to the bill.
     *
     * @param  'approved'|'rejected'  $decision
     * @return array{request: ApprovalRequest, bill: Bill}
     *
     * @throws ApprovalRefused
     */
    public function decide(Bill $bill, Entity $entity, string $userId, string $decision, ?string $comment): array
    {
        return DB::connection('tenant')->transaction(function () use ($bill, $entity, $userId, $decision, $comment): array {
            /** @var Bill $locked */
            $locked = Bill::query()->lockForUpdate()->findOrFail($bill->id);

            $status = self::text($locked->status);

            if ($status !== 'awaiting_approval') {
                throw ApprovalRefused::notAwaitingApproval($status);
            }

            $request = $this->pending($locked);

            if (! $request instanceof ApprovalRequest) {
                throw ApprovalRefused::notAwaitingApproval($status);
            }

            $decided = $this->engine->decide(
                request: $request,
                userId: $userId,
                decision: $decision,
                currentFields: $this->engine->fieldsOf($locked),
                comment: $comment,
                userRoles: $this->rolesOf($userId, $entity->id),
            );

            $outcome = self::text($decided->status);

            if ($outcome === 'approved') {
                $locked->forceFill([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => $userId,
                ])->save();

                // Booked in the same transaction: a bill the ledger refuses is
                // not left approved-and-unbooked - see the class note.
                $locked = $this->bills->post($locked, $entity, $userId);
            } elseif ($outcome === 'rejected' || $outcome === 'stale') {
                $locked->forceFill(['status' => 'draft'])->save();
            }

            $this->audit->record(
                category: 'purchasing',
                action: 'bill.approval_decided',
                subjectType: 'bill',
                subjectId: $locked->id,
                subjectLabel: self::text($locked->getAttribute('supplier_document_number')),
                after: ['decision' => $decision, 'chain' => $outcome, 'bill_status' => self::text($locked->status)],
                reason: $comment,
            );

            return ['request' => $decided, 'bill' => $locked];
        });
    }

    /** The chain the bill is in now, if it is in one. */
    public function pending(Bill $bill): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('subject_type', 'bill')
            ->where('subject_id', $bill->id)
            ->where('status', 'pending')
            ->orderByDesc('requested_at')
            ->first();
    }

    /** The most recent chain about the bill, whatever became of it. */
    public function latest(Bill $bill): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('subject_type', 'bill')
            ->where('subject_id', $bill->id)
            ->orderByDesc('requested_at')
            ->first();
    }

    /** "Who can clear the open step" - the engine's own sentence. */
    public function whoCanDecide(ApprovalRequest $request): string
    {
        return $this->engine->whoCanDecide($request);
    }

    /**
     * The role keys a person holds in an entity - what a step's `role` is
     * matched against.
     *
     * @return list<string>
     */
    private function rolesOf(string $userId, string $entityId): array
    {
        return array_values(array_map(
            static fn (mixed $key): string => self::text($key),
            DB::connection('tenant')->table('user_entity_roles')
                ->join('roles', 'roles.id', '=', 'user_entity_roles.role_id')
                ->where('user_entity_roles.user_id', $userId)
                ->where('user_entity_roles.entity_id', $entityId)
                ->pluck('roles.key')
                ->all(),
        ));
    }

    private static function text(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return \is_string($value) ? $value : (\is_int($value) || \is_float($value) ? (string) $value : '');
    }
}
