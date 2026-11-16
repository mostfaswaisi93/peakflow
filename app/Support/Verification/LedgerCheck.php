<?php

declare(strict_types=1);

namespace App\Support\Verification;

/**
 * Something a module can assert about the ledger - PF-163.
 *
 * ## Why this is not just another method on the verifier
 *
 * The same decision as `ClosingCheck`, and for the same reason. The ledger
 * depends on nothing but `Tenancy`, which is what keeps a jurisdiction's
 * posting rules changeable without touching anything else. But the
 * assertions worth making about a ledger belong to the modules that know
 * what the numbers mean: that suspense holds only money traceable to a bank
 * line is a banking fact, and a verifier importing the banking service to
 * ask it would reverse the dependency the layering exists to protect.
 *
 * So the contract is infrastructure. A module registers a check and
 * `ledger:verify` runs it, knowing nothing about what it asserts.
 *
 * ## Plain arrays, not findings
 *
 * A finding is a ledger class and `Support` may not reach into the ledger -
 * the same wall, from the other side. A check returns the three parts of a
 * finding and the verifier builds it, which also keeps the severities in
 * one place rather than letting each module invent its own.
 */
interface LedgerCheck
{
    /** A short name for the check, e.g. `suspense`. */
    public function name(): string;

    /**
     * What is wrong, or an empty list.
     *
     * Each finding is `['severity' => 'critical'|'warning', 'kind' => ...,
     * 'detail' => ..., 'consequence' => ...]`. `detail` says what was
     * found; `consequence` says what it costs, because a finding nobody
     * can price is a finding nobody schedules.
     *
     * @return list<array{severity: string, kind: string, detail: string, consequence: string}>
     */
    public function findings(?string $entityId): array;
}
