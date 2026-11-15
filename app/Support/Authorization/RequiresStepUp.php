<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/**
 * Re-proving a factor before one dangerous act - PF-032.
 *
 * ## Why a Support contract
 *
 * The acts that need step-up are spread across the modules that own them:
 * reopening a closed period is `Ledger`, releasing a payment run is
 * `Settlement`, changing a supplier's bank account is `Parties`. The guard
 * that checks it belongs to `Identity`, which none of them may depend on.
 *
 * Same shape as {@see SegregatesDuties} and {@see \App\Support\Audit\AuditTrail}:
 * the capability is declared here and implemented there.
 *
 * ## It is asserted on the WRITE PATH, not only in a route
 *
 * A middleware on the route is the obvious place and is not enough. The same
 * service is called from a console command, a queued job and a future API
 * endpoint, and each of those is a route that does not exist yet. Asserting
 * inside the service means the control cannot be reached around.
 *
 * ## What "fresh" means, and what it does not
 *
 * A session being old is not the question - somebody who has been working all
 * day is still the person who logged in. The question is whether the factor
 * was re-proved recently enough to authorise THIS act, which is why the
 * window is minutes rather than hours and why it is per permission.
 *
 * A stolen session passes every check that looks at the session. It fails this
 * one.
 */
interface RequiresStepUp
{
    /**
     * Refuse unless the actor has re-proved a factor for this permission.
     *
     * Silent when the permission does not demand step-up, and silent when
     * there is no person to re-prove - a console command or a queued job has
     * no second factor, and refusing would make these permissions unusable
     * from a queue. The audit record names the actor as the system, which is
     * the honest description of what happened.
     *
     * @param  string|null  $subjectId  what is being authorised, when the
     *     permission is object-scoped: this period, this payment run. An
     *     assertion for one subject must not authorise another.
     * @param  string|null  $label  what a person would call the subject, for
     *     the audit entry and the prompt. "2026-03" rather than a ULID.
     *
     * @throws \RuntimeException when a fresh assertion is needed and absent
     */
    public function assert(string $permission, ?string $subjectId = null, ?string $label = null): void;

    /**
     * Whether the actor could act now, without throwing.
     *
     * For a screen deciding whether to show a prompt before somebody fills
     * in a form, rather than after.
     */
    public function isSatisfied(string $permission, ?string $subjectId = null): bool;
}
