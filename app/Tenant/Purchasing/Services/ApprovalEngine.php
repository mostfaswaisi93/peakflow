<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Authorization\SegregatesDuties;
use App\Support\Hashing\Canonicalizer;
use App\Tenant\Purchasing\Exceptions\ApprovalRefused;
use App\Tenant\Purchasing\Models\ApprovalDecision;
use App\Tenant\Purchasing\Models\ApprovalRequest;
use App\Tenant\Purchasing\Models\ApprovalRule;
use App\Tenant\Purchasing\Models\ApprovalStep;
use App\Tenant\Purchasing\Models\Bill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One approval mechanism, for every document that needs one - PF-136.
 *
 * ## The hole this exists to close
 *
 * An approval is not a flag. It is a decision about a **specific version**
 * of a document, and the failure that matters is the edit afterwards:
 * approved at 1,000, changed to 100,000, paid. Every system that stores
 * approval as a boolean has this hole, and it is invisible because the
 * document looks approved and is.
 *
 * So the request carries a fingerprint of the document as it was when the
 * chain started, each decision records what its approver saw, and
 * {@see isStale()} is asked before anything is treated as approved. A
 * document whose fingerprint has moved **returns to the start of the
 * chain** - not to the step it was on, because the earlier approvers also
 * approved something else.
 *
 * ## The chain is compiled once and written down
 *
 * Not re-evaluated per decision. A rule changed halfway through would
 * otherwise move the goalposts under the people already in it, and nobody
 * could answer "who was supposed to approve this" about a document from last
 * month.
 *
 * ## Segregation of duties, with a refusal that names who can
 *
 * A user cannot approve a document they created. The refusal says who can,
 * because "you cannot do this" sends somebody to ask around - and the person
 * they ask is frequently the one who created it.
 */
final class ApprovalEngine
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly SegregatesDuties $duties,
        private readonly Delegations $delegations,
    ) {}

    /**
     * Start a chain over a document.
     *
     * Idempotent: a pending request for the same subject whose fingerprint
     * still matches is returned rather than duplicated, because a form
     * submitted twice must not produce two chains over one document.
     *
     * @throws ApprovalRefused if no rule matches
     */
    public function request(
        string $entityId,
        string $subjectType,
        string $subjectId,
        string $currency,
        string $amount,
        array $fingerprintFields,
        ?string $requestedBy = null,
        ?string $functionalAmount = null,
        ?string $fxRate = null,
        ?string $fxRateDate = null,
        array $context = [],
    ): ApprovalRequest {
        $fingerprint = $this->fingerprint($fingerprintFields);

        $existing = ApprovalRequest::query()
            ->where('entity_id', $entityId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', 'pending')
            ->first();

        if ($existing !== null) {
            if ((string) $existing->document_fingerprint === $fingerprint) {
                return $existing;
            }

            /*
             | The document changed while a chain was open.
             |
             | The open request is marked stale rather than updated: the
             | decisions already in it were about the earlier version, and
             | rewriting the fingerprint would make them look like decisions
             | about this one.
             */
            $this->markStale($existing, 'the document changed while the chain was open');
        }

        $steps = $this->compile($entityId, $subjectType, $functionalAmount ?? $amount, $context);

        if ($steps === []) {
            throw ApprovalRefused::noRuleMatches($subjectType, $amount, $currency);
        }

        return DB::connection('tenant')->transaction(function () use (
            $entityId, $subjectType, $subjectId, $currency, $amount, $fingerprint,
            $requestedBy, $functionalAmount, $fxRate, $fxRateDate, $steps,
        ): ApprovalRequest {
            $request = ApprovalRequest::create([
                'entity_id' => $entityId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'status' => 'pending',
                'currency' => $currency,
                'amount' => $amount,
                'functional_amount' => $functionalAmount,
                'fx_rate' => $fxRate,
                'fx_rate_date' => $fxRateDate,
                'document_fingerprint' => $fingerprint,
                'requested_by' => $requestedBy,
                'requested_at' => now(),
            ]);

            foreach ($steps as $step) {
                ApprovalStep::create([
                    'entity_id' => $entityId,
                    'approval_request_id' => (string) $request->getKey(),
                    'sequence' => (int) $step['sequence'],
                    'quorum' => (int) $step['quorum'],
                    'status' => 'pending',
                    'approval_rule_id' => $step['approval_rule_id'],
                    'rule_code' => $step['rule_code'],
                    'role' => $step['role'],
                    'user_id' => $step['user_id'],
                    'limit_amount' => $step['limit_amount'],
                    'escalate_after_hours' => $step['escalate_after_hours'],
                ]);
            }

            $this->openNextSequence($request->refresh());

            $this->audit->record(
                category: 'purchasing',
                action: 'approval.requested',
                subjectType: $subjectType,
                subjectId: $subjectId,
                subjectLabel: sprintf('%s %s', $currency, $amount),
                after: [
                    'steps' => \count($steps),
                    'fingerprint' => $fingerprint,
                    'functional_amount' => $functionalAmount,
                    'rules' => array_values(array_unique(array_map(
                        static fn (array $s): string => (string) $s['rule_code'],
                        $steps,
                    ))),
                ],
                entityId: $entityId,
            );

            return $request->refresh();
        });
    }

    /**
     * Record a decision.
     *
     * `$userRoles` is the roles the action is taken **under**. For a
     * delegated act those are the delegator's: a delegate borrows the
     * authority of the person they are covering for, and the delegation is
     * what permits the borrowing. The engine checks the delegation exists
     * before it honours them, and the decision records both people.
     *
     * @param  list<string>|null  $userRoles
     *
     * @throws ApprovalRefused
     */
    public function decide(
        ApprovalRequest $request,
        string $userId,
        string $decision,
        array $currentFields,
        ?string $comment = null,
        ?array $userRoles = null,
        ?string $onBehalfOf = null,
    ): ApprovalRequest {
        $request = $request->refresh();

        if (! $request->isPending()) {
            throw ApprovalRefused::notPending((string) $request->status);
        }

        /*
         | The document, now, against the document that was submitted.
         |
         | Checked before anything else: approving a version nobody is
         | looking at any more is the defect, and the answer is to start
         | again rather than to record a decision about the wrong thing.
         */
        $now = $this->fingerprint($currentFields);

        if ($now !== (string) $request->document_fingerprint) {
            $this->markStale($request, 'the document changed after the chain started');

            throw ApprovalRefused::documentChanged();
        }

        if ($decision === 'rejected' && \strlen(trim((string) $comment)) <= 5) {
            throw ApprovalRefused::rejectionNeedsAReason();
        }

        /*
         | Two people, named separately - PF-137.
         |
         | `$userId` pressed the button. `$onBehalfOf` is whose step it is.
         | The decision records both: writing only the absent person's id
         | would be a forged approval - the record would say somebody
         | approved a document they never saw, and the only way to discover
         | it is to notice they were on holiday that week.
         */
        $delegation = null;

        if ($onBehalfOf !== null && $onBehalfOf !== $userId) {
            $delegation = $this->delegations->permitting(
                (string) $request->entity_id,
                $userId,
                $onBehalfOf,
                null,
                'bill.approve',
            );

            if ($delegation === null) {
                throw ApprovalRefused::noDelegation($onBehalfOf);
            }
        }

        /*
         | Segregation of duties is asked about the person ACTING.
         |
         | The obvious way to launder a conflict is to delegate to whoever
         | created the document, so the question is about the actor rather
         | than about the delegator - the conflict is caught where it
         | arrives rather than where it was arranged.
         */
        $this->assertTheyMayDecide($request, $userId, $userRoles);

        $whoseStep = $onBehalfOf ?? $userId;
        $step = $this->stepFor($request, $whoseStep, $userRoles);

        if ($step === null) {
            throw ApprovalRefused::notTheirStep($this->whoCanDecide($request));
        }

        /*
         | The limit, on the CONVERTED amount - PF-138.
         |
         | A limit of 10,000 in the entity's own currency cannot be dodged
         | by invoicing in a weak one. The rate is on the request, recorded
         | when the chain started, because "why did this need a director" is
         | answered by the rate on the day.
         */
        if ($decision === 'approved') {
            $this->assertWithinTheirLimit($request, $whoseStep, $userRoles, $step, $delegation);
        }

        return DB::connection('tenant')->transaction(function () use (
            $request, $step, $userId, $decision, $comment, $now, $onBehalfOf,
        ): ApprovalRequest {
            try {
                ApprovalDecision::create([
                    'entity_id' => (string) $request->entity_id,
                    'approval_request_id' => (string) $request->getKey(),
                    'approval_step_id' => (string) $step->getKey(),
                    'user_id' => $userId,
                    'decision' => $decision,
                    'comment' => $comment,
                    'fingerprint' => $now,
                    'on_behalf_of' => $onBehalfOf === $userId ? null : $onBehalfOf,
                    'decided_at' => now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                /*
                 | One decision per person per step.
                 |
                 | A quorum of two cannot be met by one person pressing
                 | approve twice, which is the obvious way to clear a step
                 | alone. The index is the guarantee; this is the message.
                 */
                throw ApprovalRefused::alreadyDecided();
            }

            if ($decision === 'rejected') {
                $step->update(['status' => 'rejected', 'closed_at' => now()]);

                $request->update([
                    'status' => 'rejected',
                    'decided_at' => now(),
                    'outcome_detail' => $comment,
                ]);

                /*
                 | The action as a literal, at the call site.
                 |
                 | `SodCoverageRules` greps for `action: '...'` to prove that
                 | a duty named by an SoD rule is one some service actually
                 | writes - a typo there disables a control silently, which
                 | is the one failure mode a control must not have. Passing
                 | the action through a shared helper made it ungreppable
                 | and the check said so.
                 */
                $this->audit->record(
                    category: 'purchasing',
                    action: 'approval.rejected',
                    subjectType: (string) $request->subject_type,
                    subjectId: (string) $request->subject_id,
                    subjectLabel: sprintf('step %d', (int) $step->sequence),
                    after: $this->decisionPayload($request, $step, $userId, $onBehalfOf),
                    reason: $comment,
                    entityId: (string) $request->entity_id,
                );

                return $request->refresh();
            }

            $step->increment('approvals');
            $step->refresh();

            if ($step->needsMore() === 0) {
                $step->update(['status' => 'approved', 'closed_at' => now()]);
            }

            $this->audit->record(
                category: 'purchasing',
                action: 'approval.approved',
                subjectType: (string) $request->subject_type,
                subjectId: (string) $request->subject_id,
                subjectLabel: sprintf('step %d', (int) $step->sequence),
                after: $this->decisionPayload($request, $step, $userId, $onBehalfOf),
                reason: $comment,
                entityId: (string) $request->entity_id,
            );

            $this->openNextSequence($request->refresh());

            return $request->refresh();
        });
    }

    /**
     * Whether a request is about a document that has since changed.
     *
     * Asked before anything is treated as approved. A caller that trusted
     * the status alone would be trusting a decision about a different
     * document.
     */
    public function isStale(ApprovalRequest $request, array $currentFields): bool
    {
        return $this->fingerprint($currentFields) !== (string) $request->document_fingerprint;
    }

    /**
     * The fingerprint of a bill: the fields that make it what it is.
     *
     * Not every column. The supplier, the amounts, the dates and the lines -
     * the things an approver was deciding about. A note or an internal
     * reference changing is not a new document, and a chain that restarted
     * on those would make approval a thing people learn to work around.
     *
     * @return array<string, mixed>
     */
    public function fieldsOf(Bill $bill): array
    {
        $lines = $bill->lines->map(static fn ($line): array => [
            'line_no' => (int) $line->line_no,
            'description' => (string) $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'net_amount' => (string) $line->net_amount,
            'tax_amount' => (string) $line->tax_amount,
            'account' => (string) $line->expense_account_id,
        ])->all();

        return [
            'party_id' => (string) $bill->party_id,
            'supplier_document_number' => (string) $bill->supplier_document_number,
            'supplier_document_date' => $bill->supplier_document_date->toDateString(),
            'tax_point' => $bill->tax_point->toDateString(),
            'posting_date' => $bill->posting_date->toDateString(),
            'currency' => (string) $bill->currency,
            'net_amount' => (string) $bill->net_amount,
            'tax_amount' => (string) $bill->tax_amount,
            'gross_amount' => (string) $bill->gross_amount,
            'lines' => $lines,
        ];
    }

    /**
     * The fingerprint of a set of fields - RFC 8785, then SHA-256.
     *
     * Through the canonicalizer rather than `json_encode`, so a document
     * whose keys come back from the database in a different order hashes the
     * same. Without that, a chain would go stale on a document nobody
     * touched - which is worse than not checking at all, because people
     * learn to ignore it.
     *
     * @param  array<string, mixed>  $fields
     */
    public function fingerprint(array $fields): string
    {
        return Canonicalizer::hash($fields);
    }

    /**
     * Who may act on the open step, in words.
     *
     * The refusal names them because "you cannot do this" sends somebody to
     * ask around, and the person they ask is frequently the one who created
     * the document.
     */
    public function whoCanDecide(ApprovalRequest $request): string
    {
        $open = $request->steps()->where('status', 'open')->get();

        if ($open->isEmpty()) {
            return 'nobody: no step is open';
        }

        $who = $open->map(static function (ApprovalStep $step): string {
            if ($step->user_id !== null) {
                return sprintf('user %s', (string) $step->user_id);
            }

            return sprintf(
                'anyone with the %s role%s',
                (string) $step->role,
                $step->quorum > 1 ? sprintf(' (%d of them)', $step->quorum) : '',
            );
        })->unique()->values()->all();

        return implode(', or ', $who);
    }

    /**
     * Steps whose escalation window has passed - PF-137's escalation path.
     *
     * @return list<ApprovalStep>
     */
    public function overdue(string $entityId, ?\DateTimeInterface $asOf = null): array
    {
        $now = $asOf === null ? now() : \Illuminate\Support\Carbon::parse($asOf);

        return ApprovalStep::query()
            ->where('entity_id', $entityId)
            ->where('status', 'open')
            ->whereNotNull('escalate_after_hours')
            ->whereNotNull('opened_at')
            ->get()
            ->filter(static fn (ApprovalStep $step): bool => $step->opened_at
                ->addHours((int) $step->escalate_after_hours)
                ->lt($now))
            ->values()
            ->all();
    }

    /**
     * Compile the matching rules into an ordered chain.
     *
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function compile(string $entityId, string $subjectType, string $amount, array $context): array
    {
        $rules = ApprovalRule::query()
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('code')
            ->get();

        $steps = [];
        $sequence = 0;

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $subjectType, $amount, $context)) {
                continue;
            }

            $ruleSteps = $rule->steps()->get();

            if ($ruleSteps->isEmpty()) {
                continue;
            }

            /*
             | Each rule's steps are appended after the previous rule's.
             |
             | A rule's own sequences are relative to itself - "my second
             | step" - and concatenating preserves that while keeping two
             | rules' steps from colliding. Steps sharing a sequence within a
             | rule stay parallel.
             */
            $offset = $sequence;
            $highest = $offset;

            foreach ($ruleSteps as $step) {
                $resolved = $offset + (int) $step->sequence;
                $highest = max($highest, $resolved);

                $steps[] = [
                    'sequence' => $resolved,
                    'quorum' => (int) $step->quorum,
                    'approval_rule_id' => (string) $rule->getKey(),
                    'rule_code' => (string) $rule->code,
                    'role' => $step->role === null ? null : (string) $step->role,
                    'user_id' => $step->user_id === null ? null : (string) $step->user_id,
                    'limit_amount' => $step->limit_amount === null ? null : (string) $step->limit_amount,
                    'escalate_after_hours' => $step->escalate_after_hours === null
                        ? null
                        : (int) $step->escalate_after_hours,
                ];
            }

            $sequence = $highest;

            if ($rule->stops_here) {
                break;
            }
        }

        usort($steps, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return $steps;
    }

    /** @param array<string, mixed> $context */
    private function matches(ApprovalRule $rule, string $subjectType, string $amount, array $context): bool
    {
        if ($rule->document_type !== null && (string) $rule->document_type !== $subjectType) {
            return false;
        }

        $value = \Brick\Math\BigDecimal::of($amount)->abs();

        if ($rule->amount_from !== null && $value->isLessThan((string) $rule->amount_from)) {
            return false;
        }

        if ($rule->amount_to !== null && $value->isGreaterThan((string) $rule->amount_to)) {
            return false;
        }

        if ($rule->party_id !== null && (string) $rule->party_id !== (string) ($context['party_id'] ?? '')) {
            return false;
        }

        if ($rule->account_id !== null && ! \in_array(
            (string) $rule->account_id,
            array_map('strval', (array) ($context['account_ids'] ?? [])),
            true,
        )) {
            return false;
        }

        if ($rule->dimension_axis !== null) {
            $dimensions = (array) ($context['dimensions'] ?? []);

            if (! \in_array((string) $rule->dimension_value, array_map('strval', $dimensions), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Open every step at the next unfinished sequence.
     *
     * Parallel by construction: steps sharing a sequence open together, and
     * the chain moves on only when all of them are satisfied.
     */
    private function openNextSequence(ApprovalRequest $request): void
    {
        $steps = $request->steps()->get();

        $outstanding = $steps->filter(
            static fn (ApprovalStep $step): bool => ! \in_array($step->status, ['approved', 'skipped'], true),
        );

        if ($outstanding->isEmpty()) {
            $request->update([
                'status' => 'approved',
                'decided_at' => now(),
                'outcome_detail' => sprintf('%d steps cleared', $steps->count()),
            ]);

            return;
        }

        $next = (int) $outstanding->min('sequence');

        foreach ($outstanding->where('sequence', $next) as $step) {
            if ($step->status === 'pending') {
                $step->update(['status' => 'open', 'opened_at' => now()]);
            }
        }
    }

    /** @param list<string>|null $userRoles */
    private function stepFor(ApprovalRequest $request, string $userId, ?array $userRoles): ?ApprovalStep
    {
        foreach ($request->steps()->where('status', 'open')->get() as $step) {
            if ($step->user_id !== null && (string) $step->user_id === $userId) {
                return $step;
            }

            if ($step->role !== null && \in_array((string) $step->role, $userRoles ?? [], true)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param  list<string>|null  $userRoles
     *
     * @throws ApprovalRefused
     */
    private function assertTheyMayDecide(ApprovalRequest $request, string $userId, ?array $userRoles): void
    {
        if ((string) $request->requested_by === $userId) {
            throw ApprovalRefused::ownDocument($this->whoCanDecide($request));
        }

        /*
         | And the segregation-of-duties engine, which knows about the
         | document rather than about this chain.
         |
         | The check above catches the requester; this one catches the
         | person who created the bill through another route, which is the
         | same conflict arriving by a different door.
         */
        $this->duties->assert(
            duty: 'bill.approve',
            subjectType: (string) $request->subject_type,
            subjectId: (string) $request->subject_id,
            subjectLabel: sprintf('%s %s', (string) $request->currency, (string) $request->amount),
        );
    }

    /**
     * The approver's own limit, the step's cap, and the delegation's.
     *
     * The lowest of the three binds. A delegation that carried the
     * delegator's limit would make delegation a way to borrow authority.
     *
     * @param  list<string>|null  $userRoles
     *
     * @throws ApprovalRefused
     */
    private function assertWithinTheirLimit(
        ApprovalRequest $request,
        string $userId,
        ?array $userRoles,
        ApprovalStep $step,
        ?\App\Tenant\Purchasing\Models\ApprovalDelegation $delegation,
    ): void {
        $amount = (string) ($request->functional_amount ?? $request->amount);

        $limit = $this->delegations->limitFor(
            (string) $request->entity_id,
            $userId,
            $userRoles ?? [],
            /*
             | As of the date the CHAIN started, not today.
             |
             | A limit change is not retroactive: the limit that applied is
             | the one in force when the chain started, and reading today's
             | would change the answer to "was this within their authority"
             | for every approval already given.
             */
            \Illuminate\Support\Carbon::parse($request->requested_at->toDateTimeString()),
        );

        $caps = array_values(array_filter([
            $limit,
            $step->limit_amount === null ? null : (string) $step->limit_amount,
            $delegation?->limit_amount === null ? null : (string) $delegation->limit_amount,
        ]));

        if ($caps === []) {
            return;
        }

        $lowest = null;

        foreach ($caps as $cap) {
            $value = \Brick\Math\BigDecimal::of($cap);
            $lowest = $lowest === null || $value->isLessThan($lowest) ? $value : $lowest;
        }

        if (\Brick\Math\BigDecimal::of($amount)->abs()->isGreaterThan($lowest)) {
            throw ApprovalRefused::overTheirLimit(
                $amount,
                (string) $lowest,
                (string) ($request->functional_amount === null ? $request->currency : 'the functional currency'),
            );
        }
    }

    private function markStale(ApprovalRequest $request, string $why): void
    {
        $request->update([
            'status' => 'stale',
            'decided_at' => now(),
            'outcome_detail' => $why.', so every decision in it was about a different document',
        ]);

        $this->audit->record(
            category: 'purchasing',
            action: 'approval.stale',
            subjectType: (string) $request->subject_type,
            subjectId: (string) $request->subject_id,
            subjectLabel: (string) $request->document_fingerprint,
            after: ['why' => $why],
            reason: $why,
            entityId: (string) $request->entity_id,
        );
    }

    /**
     * What an approval record carries.
     *
     * @return array<string, mixed>
     */
    private function decisionPayload(
        ApprovalRequest $request,
        ApprovalStep $step,
        string $userId,
        ?string $onBehalfOf,
    ): array {
        return [
            'rule' => (string) $step->rule_code,
            'sequence' => (int) $step->sequence,
            'quorum' => (int) $step->quorum,
            'approvals' => (int) $step->approvals,
            'fingerprint' => (string) $request->document_fingerprint,
            'by' => $userId,
            'on_behalf_of' => $onBehalfOf,
        ];
    }
}
