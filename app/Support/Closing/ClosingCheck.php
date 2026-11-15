<?php

declare(strict_types=1);

namespace App\Support\Closing;

/**
 * Something that can refuse to let an accounting period close - PF-088.
 *
 * ## Why this interface exists in Support
 *
 * The same decision as `AuditTrail` and `RequiresStepUp`. Closing a period is
 * the ledger's act, and the ledger depends on nothing but `Tenancy` - that
 * rule is what keeps the posting rules for a jurisdiction changeable without
 * touching anything else.
 *
 * But the things that should stop a close belong to other modules. An
 * unexplained difference between the tax control accounts and the filed return
 * is a tax question; an unmatched bank line will be a banking question. Having
 * `Ledger` ask each of them directly would make the ledger depend on all of
 * them, and the first one to be added would carry the rest in with it.
 *
 * So the contract is infrastructure and lives here. A module registers a check
 * and the ledger consults the registry, knowing nothing about what is in it.
 *
 * ## Reasons, not a boolean
 *
 * A check that answers yes or no leaves the person closing to find out why by
 * elimination. Each reason is a sentence they can act on, and a check returning
 * several returns all of them - the same argument as the pre-filing checklist.
 */
interface ClosingCheck
{
    /** A short name for the finding, e.g. `tax reconciliation`. */
    public function name(): string;

    /**
     * Why this period must not close, or an empty list.
     *
     * @param  string  $entityId  the entity whose period it is
     * @param  string  $label  what a person calls the period: `2026-05`
     * @param  string  $from  the first day in the period, as `Y-m-d`
     * @param  string  $until  the first day NOT in the period, as `Y-m-d`
     * @return list<string>
     */
    public function reasonsToRefuse(string $entityId, string $label, string $from, string $until): array;
}
