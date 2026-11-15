<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Support\Limits\Ceilings;
use App\Tenant\Identity\Enums\DutyScope;
use App\Tenant\Identity\Exceptions\SegregationOfDutiesConflict;
use App\Tenant\Identity\Models\SodOverride;
use App\Tenant\Identity\Models\SodRule;
use App\Tenant\Identity\Models\User;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Decides whether this user may perform this duty, given what they have
 * already done - PF-033.
 *
 * ## It asks the audit trail, not the roles
 *
 * By the time this runs, the permission check has already passed: the user IS
 * allowed to perform the duty in general. The question here is different and
 * cannot be answered from roles, because both duties are legitimately inside
 * the user's role - which is exactly why the control exists.
 *
 * So the engine asks the audit log: has this person already performed the
 * conflicting duty, on the same document, counterparty or period? The audit
 * log is the only thing that knows, and it is append-only and hash-chained,
 * which is what makes the answer trustworthy.
 *
 * ## A refusal is recorded even though it rolls the transaction back
 *
 * Refusing aborts the caller's transaction, so the refusal is written through
 * {@see AuditLog::refusal()} on its own connection. A refusal nobody can see
 * is indistinguishable from an attempt nobody made, and the exceptions report
 * in PF-034 is built from these.
 */
final class SodEngine
{
    /** @var ?Collection<int, SodRule> */
    private ?Collection $rules = null;

    public function __construct(
        private readonly EntityContext $entities,
        private readonly AuditLog $audit,
        private readonly Ceilings $ceilings,
    ) {}

    /**
     * Refuse if performing this duty would conflict with something the user
     * has already done.
     *
     * Call it BEFORE doing the work, and inside the same transaction, so that
     * nothing is half-done when it refuses.
     *
     * @param  string  $duty  a permission key, e.g. `payment.release`
     * @param  ?string  $subjectType  the document's class or type name
     * @param  ?string  $subjectId  the document's id
     * @param  ?string  $partyId  the counterparty, for party-scoped rules
     * @param  ?string  $periodId  the accounting period, for period-scoped rules
     *
     * @throws SegregationOfDutiesConflict
     */
    public function assert(
        string $duty,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $partyId = null,
        ?string $periodId = null,
        ?string $subjectLabel = null,
        ?User $user = null,
    ): void {
        $user ??= $this->currentUser();

        if ($user === null) {
            // No actor: a console command or a system job. There is no person
            // whose duties could conflict, so there is nothing to assert. The
            // audit record will say the actor was the system, which is the
            // honest description.
            return;
        }

        $entityId = $this->entities->current();

        foreach ($this->rulesFor($duty) as $rule) {
            $conflicting = $rule->conflictingDuty($duty);

            if ($conflicting === null) {
                continue;
            }

            $prior = $this->priorDuty($rule, $user, $conflicting, $subjectType, $subjectId, $partyId, $periodId);

            if ($prior === null) {
                continue;
            }

            // A conflict exists. An override may excuse it - but only if the
            // rule permits one at all.
            if ($rule->overridable && $this->usableOverride($rule, $user, $entityId) !== null) {
                $this->audit->record(
                    category: 'sod',
                    action: 'sod.override.used',
                    subjectType: $subjectType,
                    subjectId: $subjectId,
                    subjectLabel: $subjectLabel,
                    after: [
                        'rule' => $rule->key,
                        'attempted_duty' => $duty,
                        'conflicting_duty' => $conflicting,
                        'conflicting_audit_id' => $prior->id ?? null,
                    ],
                    reason: 'an approved, unexpired exception permitted a duty that would otherwise be refused',
                    partyId: $partyId,
                    periodId: $periodId,
                );

                continue;
            }

            $this->recordRefusal($rule, $user, $entityId, $duty, $conflicting, $prior, $subjectType, $subjectId);

            throw SegregationOfDutiesConflict::between(
                $rule,
                $duty,
                $conflicting,
                isset($prior->created_at) ? Carbon::parse((string) $prior->created_at) : null,
                $prior->subject_label ?? $subjectLabel,
                $prior->id ?? null,
            );
        }
    }

    /**
     * Would this duty be refused? Asked without performing it, and without
     * recording a refusal.
     *
     * For disabling a button, which is a courtesy and not a control: the
     * answer is re-asked by {@see assert()} at the moment of the write,
     * because anything decided while rendering a page is already stale.
     */
    public function wouldRefuse(
        string $duty,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $partyId = null,
        ?string $periodId = null,
        ?User $user = null,
    ): ?SodRule {
        $user ??= $this->currentUser();

        if ($user === null) {
            return null;
        }

        $entityId = $this->entities->current();

        foreach ($this->rulesFor($duty) as $rule) {
            $conflicting = $rule->conflictingDuty($duty);

            if ($conflicting === null) {
                continue;
            }

            if ($this->priorDuty($rule, $user, $conflicting, $subjectType, $subjectId, $partyId, $periodId) === null) {
                continue;
            }

            if ($rule->overridable && $this->usableOverride($rule, $user, $entityId) !== null) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    /**
     * Grant an exception - PF-034.
     *
     * Three refusals are built in. A rule marked non-overridable cannot be
     * excused by anyone. The approver cannot be the beneficiary, because a
     * control a user can switch off for themselves is not a control. And the
     * exception expires, because one that does not is a permanent change to
     * the control environment dressed as an exception.
     *
     * @throws SegregationOfDutiesConflict
     */
    public function grantOverride(
        SodRule $rule,
        User $beneficiary,
        User $approver,
        string $reason,
        Carbon $expiresAt,
    ): SodOverride {
        if (! $rule->overridable) {
            $this->audit->refusal(
                category: 'sod',
                action: 'sod.override.refused',
                subjectType: SodRule::class,
                subjectId: (string) $rule->getKey(),
                subjectLabel: $rule->key,
                after: ['rule' => $rule->key, 'beneficiary' => (string) $beneficiary->getKey()],
                reason: 'the rule is not overridable',
            );

            throw SegregationOfDutiesConflict::notOverridable($rule);
        }

        /*
         | The platform's ceiling on how long an exception may run.
         |
         | A customer who sets their override expiry to ten years has not
         | configured a control, they have removed one - and they will do it at
         | 5pm on the day it blocks something urgent. The ceiling is refused
         | rather than silently clamped, because an approver who asked for a
         | year and got thirty days without being told believes the exception
         | lasts a year.
         */
        $maximumDays = $this->ceilings->int('ceiling.sod_override_days');

        if ($expiresAt->greaterThan(now()->addDays($maximumDays))) {
            $this->audit->refusal(
                category: 'sod',
                action: 'sod.override.refused',
                subjectType: SodRule::class,
                subjectId: (string) $rule->getKey(),
                subjectLabel: $rule->key,
                after: ['requested_until' => $expiresAt->toIso8601String(), 'ceiling_days' => $maximumDays],
                reason: 'the requested expiry exceeds the platform ceiling',
            );

            throw SegregationOfDutiesConflict::overrideTooLong($rule, $maximumDays);
        }

        if ((string) $approver->getKey() === (string) $beneficiary->getKey()) {
            $this->audit->refusal(
                category: 'sod',
                action: 'sod.override.refused',
                subjectType: SodRule::class,
                subjectId: (string) $rule->getKey(),
                subjectLabel: $rule->key,
                after: ['rule' => $rule->key, 'beneficiary' => (string) $beneficiary->getKey()],
                reason: 'the approver and the beneficiary are the same person',
            );

            throw SegregationOfDutiesConflict::approverIsBeneficiary($rule);
        }

        $override = SodOverride::create([
            'sod_rule_id' => $rule->getKey(),
            'entity_id' => $this->entities->current(),
            'user_id' => $beneficiary->getKey(),
            'reason' => $reason,
            'approved_by_user_id' => $approver->getKey(),
            'approved_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        $this->audit->record(
            category: 'sod',
            action: 'sod.override.granted',
            subjectType: SodOverride::class,
            subjectId: (string) $override->getKey(),
            subjectLabel: $rule->key,
            after: [
                'rule' => $rule->key,
                'beneficiary' => (string) $beneficiary->getKey(),
                'approved_by' => (string) $approver->getKey(),
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            reason: $reason,
        );

        return $override;
    }

    /**
     * The most recent audit record of this user performing the conflicting
     * duty within the rule's scope.
     *
     * Most recent rather than first because that is what the refusal message
     * should name: "you did this an hour ago" is actionable, "you did this in
     * March" invites the user to believe it is a stale false positive.
     */
    private function priorDuty(
        SodRule $rule,
        User $user,
        string $conflictingDuty,
        ?string $subjectType,
        ?string $subjectId,
        ?string $partyId,
        ?string $periodId,
    ): ?object {
        $query = DB::connection('tenant')->table('audit_log')
            ->where('actor_user_id', $user->getKey())
            ->where('action', $conflictingDuty)
            // A refusal is not a performance of the duty. Without this, being
            // refused once would itself become the conflicting history.
            ->where('category', '!=', 'sod');

        $scoped = match ($rule->scope) {
            DutyScope::Object => $subjectId === null
                ? null
                : $query->where('subject_type', $subjectType)->where('subject_id', $subjectId),
            DutyScope::Party => $partyId === null ? null : $query->where('party_id', $partyId),
            DutyScope::Period => $periodId === null ? null : $query->where('period_id', $periodId),
        };

        if ($scoped === null) {
            // The caller did not supply the key this rule needs. Refusing
            // would block legitimate work on a technicality; passing silently
            // would disable the control. So neither: it is a programming
            // error and it says so.
            throw new \LogicException(sprintf(
                'The SoD rule "%s" is scoped to %s, but no %s was supplied when asserting "%s". '.
                'A %s-scoped rule cannot be evaluated without it, and skipping the check would silently '.
                'disable the control.',
                $rule->key,
                $rule->scope->value,
                $rule->scope->value === 'object' ? 'subject id' : $rule->scope->value.' id',
                $conflictingDuty,
                $rule->scope->value,
            ));
        }

        return $scoped->orderByDesc('created_at')->first(['id', 'created_at', 'subject_label']);
    }

    private function usableOverride(SodRule $rule, User $user, ?string $entityId): ?SodOverride
    {
        // A plain query: SodOverride carries no Eloquent entity scope to
        // remove, because Identity sits below Tenancy and must not use its
        // trait. The entity is filtered explicitly here - as it already was -
        // and PostgreSQL enforces the boundary with a forced RLS policy.
        return SodOverride::query()
            ->where('sod_rule_id', $rule->getKey())
            ->where('user_id', $user->getKey())
            ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId))
            ->whereNotNull('approved_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('expires_at')
            ->first();
    }

    private function recordRefusal(
        SodRule $rule,
        User $user,
        ?string $entityId,
        string $duty,
        string $conflicting,
        object $prior,
        ?string $subjectType,
        ?string $subjectId,
    ): void {
        // The refusals table is the one the exceptions report reads; the audit
        // record is the evidence. Both, because the report must stay fast and
        // the evidence must stay in the chain.
        DB::connection('tenant')->table('sod_refusals')->insert([
            'id' => (string) Str::ulid(),
            'sod_rule_id' => $rule->getKey(),
            'entity_id' => $entityId,
            'user_id' => $user->getKey(),
            'attempted_duty' => $duty,
            'conflicting_duty' => $conflicting,
            'conflicting_audit_id' => $prior->id ?? null,
            'subject_id' => $subjectId,
            'subject_type' => $subjectType,
            'created_at' => now(),
        ]);

        $this->audit->refusal(
            category: 'sod',
            action: 'sod.refused',
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $prior->subject_label ?? null,
            after: [
                'rule' => $rule->key,
                'attempted_duty' => $duty,
                'conflicting_duty' => $conflicting,
                'conflicting_audit_id' => $prior->id ?? null,
                'overridable' => $rule->overridable,
            ],
            reason: sprintf('the same user performed "%s" within the %s scope', $conflicting, $rule->scope->value),
            entityId: $entityId,
        );
    }

    /** @return Collection<int, SodRule> */
    private function rulesFor(string $duty): Collection
    {
        $this->rules ??= SodRule::query()->where('active', true)->get();

        return $this->rules->filter(
            fn (SodRule $rule): bool => $rule->duty_a === $duty || $rule->duty_b === $duty,
        );
    }

    private function currentUser(): ?User
    {
        $user = auth()->guard('web')->user();

        return $user instanceof User ? $user : null;
    }
}
