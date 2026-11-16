<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Purchasing\Exceptions\ApprovalRefused;
use App\Tenant\Purchasing\Models\ApprovalDelegation;
use App\Tenant\Purchasing\Models\ApprovalLimit;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Delegation, continuity and limits - PF-137, PF-138.
 *
 * ## A delegate acts, and the record says so
 *
 * The naive implementation of "approve on behalf of" writes the absent
 * person's id on the decision. That is a forged approval: the record says
 * somebody approved a document they never saw, and the only way to find out
 * is to notice they were on holiday that week.
 *
 * So the decision carries both - the actor and who they acted for - and the
 * delegation that permitted it has a window with an end on it. A delegation
 * with no end is a standing substitution nobody revisits: it outlives the
 * holiday, the role and sometimes the employment.
 *
 * ## A delegate cannot receive what SoD forbids them
 *
 * Which is the obvious way to launder a conflict: delegate to the person who
 * created the document. The engine asks the SoD engine about the **actor**,
 * so the conflict is caught where it arrives rather than where it was
 * arranged - and the database refuses a delegation to oneself, which is the
 * same trick from the other direction.
 *
 * ## A limit is tested on the converted amount
 *
 * A limit of 10,000 in the entity's own currency cannot be dodged by
 * invoicing in a weak one. The rate is recorded on the request, because "why
 * did this need a director" is answered by the rate on the day the chain
 * started and not by today's.
 *
 * And a limit change is never retroactive. The limit that applies is the one
 * in force when the chain started, which is why `effective_from` is required
 * and a change is a new row rather than an update.
 */
final class Delegations
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Record a delegation.
     *
     * @throws ApprovalRefused
     */
    public function delegate(
        string $entityId,
        string $fromUserId,
        string $toUserId,
        Carbon $startsOn,
        Carbon $endsOn,
        string $reason,
        ?string $duty = null,
        ?string $limitAmount = null,
        ?string $createdBy = null,
    ): ApprovalDelegation {
        if ($fromUserId === $toUserId) {
            throw ApprovalRefused::because(
                'Nobody delegates to themselves. It looks harmless and it is the shape in which a '.
                'segregation-of-duties conflict is laundered: delegate to yourself from the person '.
                'whose approval you need.',
            );
        }

        if ($endsOn->lt($startsOn)) {
            throw ApprovalRefused::because(sprintf(
                'A delegation ending %s cannot start %s.',
                $endsOn->toDateString(),
                $startsOn->toDateString(),
            ));
        }

        if (\strlen(trim($reason)) <= 10) {
            throw ApprovalRefused::because(
                'A delegation needs a reason. "Out of office" with no more is what a standing '.
                'substitution looks like on the day somebody audits it, and the compliance pack has to '.
                'say why each one exists.',
            );
        }

        try {
            $delegation = ApprovalDelegation::create([
                'entity_id' => $entityId,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'duty' => $duty,
                'limit_amount' => $limitAmount,
                'reason' => trim($reason),
                'created_by' => $createdBy,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'approval_delegations_do_not_overlap')) {
                throw ApprovalRefused::because(
                    'A delegation between these two people already covers part of that window. Two '.
                    'overlapping ones are two answers to "may they act today", and the code would read '.
                    'whichever came back first.',
                );
            }

            throw $e;
        }

        $this->audit->record(
            category: 'purchasing',
            action: 'approval.delegated',
            subjectType: 'user',
            subjectId: $fromUserId,
            subjectLabel: sprintf('to %s', $toUserId),
            after: [
                'to' => $toUserId,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'duty' => $duty,
                'limit' => $limitAmount,
            ],
            reason: trim($reason),
            entityId: $entityId,
        );

        return $delegation;
    }

    public function revoke(ApprovalDelegation $delegation, string $userId, string $reason): ApprovalDelegation
    {
        $delegation->update(['revoked_at' => now(), 'revoked_by' => $userId]);

        $this->audit->record(
            category: 'purchasing',
            action: 'approval.delegation_revoked',
            subjectType: 'user',
            subjectId: (string) $delegation->from_user_id,
            subjectLabel: sprintf('to %s', (string) $delegation->to_user_id),
            after: ['revoked_by' => $userId],
            reason: $reason,
            entityId: (string) $delegation->entity_id,
        );

        return $delegation->refresh();
    }

    /**
     * Whom this user may act for today.
     *
     * @return list<ApprovalDelegation>
     */
    public function heldBy(string $entityId, string $userId, ?Carbon $asOf = null, ?string $duty = null): array
    {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();

        return ApprovalDelegation::query()
            ->where('entity_id', $entityId)
            ->where('to_user_id', $userId)
            ->whereNull('revoked_at')
            ->whereDate('starts_on', '<=', $day->toDateString())
            ->whereDate('ends_on', '>=', $day->toDateString())
            ->get()
            ->filter(static fn (ApprovalDelegation $d): bool => $duty === null
                || $d->duty === null
                || (string) $d->duty === $duty)
            ->values()
            ->all();
    }

    /**
     * Whether this user may act for that one, and under what limit.
     *
     * Returns the delegation rather than a boolean, because the limit on it
     * is part of the answer: "you may approve for me, up to 5,000" is a real
     * arrangement and a yes/no cannot express it.
     */
    public function permitting(
        string $entityId,
        string $actorId,
        string $forUserId,
        ?Carbon $asOf = null,
        ?string $duty = null,
    ): ?ApprovalDelegation {
        foreach ($this->heldBy($entityId, $actorId, $asOf, $duty) as $delegation) {
            if ((string) $delegation->from_user_id === $forUserId) {
                return $delegation;
            }
        }

        return null;
    }

    /**
     * The delegations in force on a date - the compliance pack's standing
     * report.
     *
     * @return list<array<string, mixed>>
     */
    public function inForce(string $entityId, ?Carbon $asOf = null): array
    {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();

        return DB::connection('tenant')->table('approval_delegations')
            ->where('entity_id', $entityId)
            ->whereNull('revoked_at')
            ->whereDate('starts_on', '<=', $day->toDateString())
            ->whereDate('ends_on', '>=', $day->toDateString())
            ->orderBy('ends_on')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all()
        ;
    }

    /**
     * Set a limit. A change is a new row with its own window.
     *
     * Never an update, because a limit change is not retroactive: the limit
     * that applied to a chain is the one in force when it started, and
     * rewriting the row would change the answer to "was this within their
     * authority" for every approval already given under it.
     */
    public function setLimit(
        string $entityId,
        string $amount,
        string $currency,
        Carbon $effectiveFrom,
        ?string $role = null,
        ?string $userId = null,
        ?string $createdBy = null,
        ?string $reason = null,
    ): ApprovalLimit {
        if (($role === null) === ($userId === null)) {
            throw ApprovalRefused::because(
                'A limit belongs to a role or to a user, and exactly one. Both would be two limits in '.
                'one row with no answer to which applies; neither would be a limit on everybody, which '.
                'is the entity\'s own and is a different thing.',
            );
        }

        /*
         | The previous limit is closed, not replaced.
         |
         | So "what was their limit in March" has an answer, which is the
         | question an auditor asks about an approval from March.
         */
        $previous = ApprovalLimit::query()
            ->where('entity_id', $entityId)
            ->where('role', $role)
            ->where('user_id', $userId)
            ->whereNull('effective_to')
            ->get();

        foreach ($previous as $row) {
            $row->update(['effective_to' => $effectiveFrom->copy()->subDay()->toDateString()]);
        }

        $limit = ApprovalLimit::create([
            'entity_id' => $entityId,
            'role' => $role,
            'user_id' => $userId,
            'limit_amount' => $amount,
            'currency' => $currency,
            'effective_from' => $effectiveFrom->toDateString(),
            'created_by' => $createdBy,
            'reason' => $reason,
        ]);

        $this->audit->record(
            category: 'purchasing',
            action: 'approval.limit_set',
            subjectType: $role === null ? 'user' : 'role',
            subjectId: (string) ($userId ?? $entityId),
            subjectLabel: (string) ($role ?? $userId),
            before: $previous->isEmpty() ? null : ['limit' => (string) $previous->first()->limit_amount],
            after: [
                'limit' => $amount,
                'currency' => $currency,
                'effective_from' => $effectiveFrom->toDateString(),
            ],
            reason: $reason,
            entityId: $entityId,
        );

        return $limit;
    }

    /**
     * The limit that applies to this actor, in the entity's own currency.
     *
     * A user's own limit beats a role's: an individual arrangement is
     * deliberate and a role's is a default. Where several roles apply, the
     * **highest** wins - a person who holds two roles has the authority of
     * both, and taking the lower would make granting somebody an extra role
     * reduce what they can do.
     */
    public function limitFor(
        string $entityId,
        string $userId,
        array $roles,
        ?Carbon $asOf = null,
    ): ?string {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();

        $own = ApprovalLimit::query()
            ->where('entity_id', $entityId)
            ->where('user_id', $userId)
            ->get()
            ->filter(static fn (ApprovalLimit $l): bool => $l->appliesOn($day));

        if ($own->isNotEmpty()) {
            return (string) $own->sortByDesc(
                static fn (ApprovalLimit $l): string => (string) $l->limit_amount,
            )->first()->limit_amount;
        }

        if ($roles === []) {
            return null;
        }

        $byRole = ApprovalLimit::query()
            ->where('entity_id', $entityId)
            ->whereIn('role', $roles)
            ->get()
            ->filter(static fn (ApprovalLimit $l): bool => $l->appliesOn($day));

        if ($byRole->isEmpty()) {
            return null;
        }

        $highest = BigDecimal::zero();

        foreach ($byRole as $limit) {
            $value = BigDecimal::of((string) $limit->limit_amount);

            if ($value->isGreaterThan($highest)) {
                $highest = $value;
            }
        }

        return (string) $highest;
    }

    /**
     * Whether an amount is within a limit, with the delegation's own cap
     * applied.
     *
     * A delegation may carry a lower cap than the delegator's limit - "you
     * may approve for me, up to 5,000" - and the lower of the two is what
     * binds. Taking the delegator's would make delegation a way to borrow
     * authority.
     */
    public function isWithin(string $functionalAmount, ?string $limit, ?string $delegationCap = null): bool
    {
        $effective = null;

        foreach ([$limit, $delegationCap] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $value = BigDecimal::of($candidate);
            $effective = $effective === null || $value->isLessThan($effective) ? $value : $effective;
        }

        if ($effective === null) {
            return true;
        }

        return BigDecimal::of($functionalAmount)->abs()->isLessThanOrEqualTo($effective);
    }
}
