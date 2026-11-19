<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Alerts;

/**
 * Something that watches an invariant the product already has - PF-242.
 *
 * ## The point of this interface is that it adds no invariants
 *
 * The product already knows how to find every one of these: `ledger:verify`
 * proves the balanced-entry rule, the control accounts and the audit chain,
 * `ledger:rebuild-balances --check` finds read-model drift,
 * `privileges:verify` finds a manual grant, `archive:verify` re-checksums a
 * sealed period, and the suspense service knows what is in suspense. All of
 * them are RUNNABLE and none of them was WATCHED: nothing in the repository
 * invoked any of them on a schedule, so the first time a breach was noticed
 * was the first time somebody thought to ask.
 *
 * So a watcher is a thin thing. It calls the check that already exists and
 * turns its finding into an `Alert` that names the entity, the period and the
 * first divergent record. A watcher that re-implemented the check would be a
 * second answer to the same question, and the two would disagree first on the
 * day one of them was right.
 *
 * ## `keys()` and not `key()`
 *
 * Because one existing check answers several of the declared conditions at
 * once: `LedgerVerifier::verify()` finds an unbalanced entry, a subledger
 * that no longer equals its control account and a broken hash chain in one
 * pass. Splitting that into three watchers would mean three full passes over
 * the ledger every hour to produce the findings of one.
 *
 * The registry checks every key against `config/telemetry.php`, so a watcher
 * cannot raise something nobody has written a runbook for.
 */
interface InvariantWatcher
{
    /**
     * The declared alert keys this watcher may raise.
     *
     * @return list<string>
     */
    public function keys(): array;

    public function scope(): WatchScope;

    /**
     * What is wrong, or nothing.
     *
     * @param  string  $tenant  the tenant the finding belongs to
     * @return list<Alert>
     */
    public function watch(string $tenant, ?string $entityId): array;
}
