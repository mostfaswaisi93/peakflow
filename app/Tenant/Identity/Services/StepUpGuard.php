<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Exceptions\StepUpRequired;
use App\Tenant\Identity\Models\Authenticator;
use App\Tenant\Identity\Models\StepUpAssertion;
use App\Tenant\Identity\Models\User;
use Illuminate\Support\Carbon;

/**
 * Enforces what a permission demands of the user's session - PF-031, PF-032.
 *
 * ## Three questions, deliberately separate
 *
 * 1. MAY this user do it? Roles and permissions. The Gate answers that.
 * 2. May the SAME user do it, given what they already did? The SoD engine.
 * 3. Has the user PROVED they are present? This.
 *
 * Collapsing any two of them produces a familiar hole. The most common is
 * treating a permission as sufficient: a stolen session cookie then carries
 * every money-moving capability its owner holds, and the password that
 * protected it was phished weeks ago.
 *
 * ## `requires_mfa` and `requires_step_up` are different demands
 *
 * `requires_mfa` means the user must HAVE a second factor and have used it to
 * establish this session. It is a property of the session.
 *
 * `requires_step_up` means they must prove themselves AGAIN, now, for this
 * specific act. It is a property of the act. Releasing a payment is the
 * canonical case: the session being legitimately MFA-authenticated an hour ago
 * says nothing about who is at the keyboard now.
 *
 * Call {@see assert()} on the write path, next to the SoD check, and never
 * only in the controller that renders the form.
 */
final class StepUpGuard
{
    /** How long an assertion remains usable. Short, because it authorises one act. */
    private const LIFETIME_SECONDS = 300;

    public function __construct(
        private readonly PermissionCatalogue $catalogue,
        private readonly MfaService $mfa,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Refuse unless the session satisfies what this permission demands.
     *
     * @throws StepUpRequired
     */
    public function assert(
        string $permission,
        ?string $subjectId = null,
        ?User $user = null,
        ?string $label = null,
    ): void {
        $permissionModel = $this->catalogue->get($permission);

        if ($permissionModel === null) {
            return;
        }

        if (! $permissionModel->requires_mfa && ! $permissionModel->requires_step_up) {
            return;
        }

        $user ??= $this->currentUser();

        if ($user === null) {
            // A console command or system job. There is no person to re-prove,
            // and refusing would make these permissions unusable from a queue.
            // The audit record names the actor as the system, which is the
            // honest description of what happened.
            return;
        }

        $label ??= $this->describe($permission);

        if ($permissionModel->requires_mfa && ! $this->mfa->hasConfirmedFactor($user)) {
            $this->refuse($permission, $subjectId, $user, 'the user has no confirmed second factor');

            throw StepUpRequired::notEnrolled($permission, $label);
        }

        if ($permissionModel->requires_mfa && ! $this->sessionAssertedAFactor()) {
            $this->refuse($permission, $subjectId, $user, 'this session was not established with a second factor');

            throw StepUpRequired::notEnrolled($permission, $label);
        }

        if (! $permissionModel->requires_step_up) {
            return;
        }

        $assertion = $this->usableAssertion($permission, $subjectId, $user);

        if ($assertion === null) {
            $this->refuse($permission, $subjectId, $user, 'no unconsumed step-up assertion covers this action');

            throw StepUpRequired::forAction($permission, $subjectId, $label);
        }

        // Spend it. One proof, one act.
        $assertion->forceFill(['consumed_at' => now()])->save();

        $this->audit->record(
            category: 'identity',
            action: 'step_up.consumed',
            subjectType: StepUpAssertion::class,
            subjectId: (string) $assertion->getKey(),
            subjectLabel: $permission,
            after: [
                'permission' => $permission,
                'for_subject' => $subjectId,
                'factor_type' => $assertion->factor_type,
            ],
            reason: 'the assertion authorised exactly this act and cannot authorise another',
            actor: $user,
        );
    }

    /**
     * Record that the user has just re-proved themselves for one act.
     *
     * The code is verified here rather than trusted from the caller, so there
     * is no path that records an assertion without one.
     */
    public function recordAssertion(
        User $user,
        string $permission,
        ?string $subjectId,
        string $code,
    ): ?StepUpAssertion {
        $authenticator = $this->mfa->verifyCode($user, $code);

        if ($authenticator === null) {
            $this->audit->refusal(
                category: 'identity',
                action: 'step_up.refused',
                subjectType: User::class,
                subjectId: (string) $user->getKey(),
                subjectLabel: $user->name,
                after: ['permission' => $permission, 'for_subject' => $subjectId],
                reason: 'the second-factor code was not accepted',
                actor: $user,
            );

            return null;
        }

        $assertion = StepUpAssertion::create([
            'user_id' => $user->getKey(),
            'session_id' => $this->sessionId(),
            'authorized_for' => $permission,
            'subject_id' => $subjectId,
            'factor_type' => $authenticator->type,
            'asserted_at' => now(),
            'expires_at' => Carbon::now()->addSeconds(self::LIFETIME_SECONDS),
        ]);

        $this->audit->record(
            category: 'identity',
            action: 'step_up.asserted',
            subjectType: StepUpAssertion::class,
            subjectId: (string) $assertion->getKey(),
            subjectLabel: $permission,
            after: [
                'permission' => $permission,
                'for_subject' => $subjectId,
                'factor_type' => $authenticator->type,
                'expires_at' => $assertion->expires_at->toIso8601String(),
            ],
            actor: $user,
        );

        return $assertion;
    }

    /**
     * Does the session already satisfy this permission? Asked without
     * refusing, for deciding whether to prompt.
     */
    public function isSatisfied(string $permission, ?string $subjectId = null, ?User $user = null): bool
    {
        $permissionModel = $this->catalogue->get($permission);

        if ($permissionModel === null) {
            return true;
        }

        $user ??= $this->currentUser();

        if ($user === null) {
            return true;
        }

        if ($permissionModel->requires_mfa
            && (! $this->mfa->hasConfirmedFactor($user) || ! $this->sessionAssertedAFactor())) {
            return false;
        }

        if (! $permissionModel->requires_step_up) {
            return true;
        }

        return $this->usableAssertion($permission, $subjectId, $user) !== null;
    }

    /**
     * An assertion for this permission, and for this subject.
     *
     * A null `subject_id` on the assertion matches only a null subject. An
     * assertion recorded without a subject must not authorise an act on a
     * specific one, or "confirm to release a payment" would authorise
     * releasing any of them.
     */
    private function usableAssertion(string $permission, ?string $subjectId, User $user): ?StepUpAssertion
    {
        return StepUpAssertion::query()
            ->where('user_id', $user->getKey())
            ->where('authorized_for', $permission)
            ->when($subjectId === null,
                fn ($q) => $q->whereNull('subject_id'),
                fn ($q) => $q->where('subject_id', $subjectId),
            )
            ->when($this->sessionId() !== null, fn ($q) => $q->where('session_id', $this->sessionId()))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('asserted_at')
            ->first();
    }

    /**
     * Was a second factor used to establish THIS session?
     *
     * Read from the session rather than from the authenticator's
     * `last_used_at`: a factor used an hour ago in a different browser says
     * nothing about this session.
     */
    private function sessionAssertedAFactor(): bool
    {
        if (! app()->bound('session')) {
            return false;
        }

        $asserted = session('factor_asserted_at');

        return is_string($asserted) || is_int($asserted);
    }

    private function sessionId(): ?string
    {
        return app()->bound('session') ? session()->getId() : null;
    }

    private function refuse(string $permission, ?string $subjectId, User $user, string $because): void
    {
        $this->audit->refusal(
            category: 'identity',
            action: 'step_up.required',
            subjectType: Authenticator::class,
            subjectId: $subjectId,
            subjectLabel: $permission,
            after: ['permission' => $permission, 'for_subject' => $subjectId],
            reason: $because,
            actor: $user,
        );
    }

    /** A human phrase for the permission key, for the refusal message. */
    private function describe(string $permission): string
    {
        $model = $this->catalogue->get($permission);

        if ($model !== null) {
            return mb_strtolower($model->label());
        }

        return str_replace(['.', '_'], [' ', ' '], $permission);
    }

    private function currentUser(): ?User
    {
        $user = auth()->guard('web')->user();

        return $user instanceof User ? $user : null;
    }
}
