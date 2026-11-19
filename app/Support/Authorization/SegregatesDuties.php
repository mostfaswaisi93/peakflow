<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/**
 * Refusing two incompatible duties to one person.
 *
 * ## Why this interface is in Support
 *
 * The implementation lives in `Identity`, which is right - segregation of
 * duties is built on users, roles and the audit log. But the duties
 * themselves belong to other modules: changing a supplier's bank account is
 * `Parties`, releasing a payment is `Settlement`, approving a bill is
 * `Purchasing`. Every one of them has to assert.
 *
 * Depending on `Identity\Services\SodEngine` directly from those modules
 * makes them all depend on `Identity`, and the headline rule of this
 * architecture is that `Ledger` depends on nothing but `Tenancy`. So the
 * contract is infrastructure and lives here - the same decision, and for
 * the same reason, as {@see \App\Support\Audit\AuditTrail} and
 * {@see \App\Support\Database\TenantModel}.
 *
 * ## What is deliberately missing
 *
 * The `?User` parameter the implementation accepts. A `User` is an
 * `Identity` model, and taking one here would make `Support` depend on
 * `Identity` - the exact thing being avoided, moved one level down.
 *
 * What a domain module CAN name is a user ID, which is a string and not a
 * model - added for PF-249. `PaymentRuns::release()` is handed the id of
 * whoever is releasing and the engine was resolving its own actor from the
 * web guard instead, so on the console and queue paths this method's own
 * comment says it is reached from, there was no actor at all and the two
 * non-overridable rules on `payment.release` were skipped.
 *
 * So the id is passed, the implementation resolves it, and `Settlement`
 * still imports no `Identity` type - which is the wall that caught the
 * first attempt at this fix.
 */
interface SegregatesDuties
{
    /**
     * Refuse if this person has already performed the conflicting duty.
     *
     * `$partyId` and `$periodId` are what a rule's scope compares on: a
     * party-scoped rule bites when the same COUNTERPARTY is involved, which
     * is what makes "changed this supplier's bank account, then released a
     * payment to this supplier" the conflict rather than "changed any bank
     * account, then released any payment".
     *
     * Throws when the duties conflict. The exception type is the
     * implementation's, and callers in domain modules do not catch it -
     * letting it reach the HTTP layer is what keeps them from importing
     * `Identity` for a type they only pass through.
     */
    public function assert(
        string $duty,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $partyId = null,
        ?string $periodId = null,
        ?string $subjectLabel = null,
        ?string $userId = null,
    ): void;

    /**
     * Which rule would refuse this duty, if any - without recording a refusal.
     *
     * For disabling a button, which is a courtesy and not a control: the
     * answer is re-asked by {@see assert()} at the moment of the write,
     * because anything decided while rendering a page is already stale.
     *
     * Returns the rule's KEY rather than the rule, because a `SodRule` is an
     * `Identity` model and a caller only needs to know which rule and
     * whether it can be excused.
     */
    public function blockingRule(
        string $duty,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $partyId = null,
        ?string $periodId = null,
    ): ?string;
}
