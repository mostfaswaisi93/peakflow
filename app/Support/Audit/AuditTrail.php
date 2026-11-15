<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Recording who did what, from any module.
 *
 * ## Why this interface exists in Support
 *
 * The implementation lives in `Identity`, which is right - the audit log is
 * built on users, sessions and a hash chain, and that is identity's business.
 *
 * But every module needs to write to it. A bank detail changing, a rate
 * falling back to a stale one, a period being reopened: recording who did
 * that is not a feature of the module doing it, it is a property of every
 * write in the system.
 *
 * Depending on `Identity\Services\AuditLog` directly from `Ledger`, `Parties`
 * and `Catalog` makes all three depend on `Identity`, and the headline rule
 * of this architecture is that `Ledger` depends on nothing but `Tenancy`.
 * That rule is worth keeping for a real reason: it is what makes the posting
 * rules for a jurisdiction changeable without touching anything else.
 *
 * So the contract is infrastructure and lives here, the same decision and for
 * the same reason as {@see \App\Support\Database\TenantModel}.
 *
 * ## What is deliberately missing
 *
 * `$actor`. The concrete implementation accepts one; this interface does not,
 * because a `User` is an `Identity` model and taking it here would make
 * `Support` depend on `Identity` - the exact thing being avoided, moved one
 * level down.
 *
 * Nothing in a domain module needs it: the actor comes from the authenticated
 * session. The two places that do pass one explicitly - the login path, and a
 * refusal recorded for a user who could not be authenticated - are inside
 * `Identity` and use the class directly.
 */
interface AuditTrail
{
    /**
     * Append a record, inside the caller's transaction.
     *
     * Joining the caller's transaction is the right default: an action that
     * was rolled back did not happen, and logging that it did would make the
     * log wrong in the one direction that matters.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return string the id of the record
     */
    public function record(
        string $category,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $subjectLabel = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $entityId = null,
        ?string $partyId = null,
        ?string $periodId = null,
    ): string;

    /**
     * Record something that was PREVENTED.
     *
     * Written out of band, on its own connection, because refusing usually
     * means rolling back - and a refusal that disappears with the rollback it
     * caused is the one record an investigation actually wants.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return string the id of the record
     */
    public function refusal(
        string $category,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $subjectLabel = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $entityId = null,
        ?string $partyId = null,
        ?string $periodId = null,
    ): string;
}
