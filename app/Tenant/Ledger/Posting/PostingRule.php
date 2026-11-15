<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Posting;

/**
 * Turns something that happened into a balanced set of lines - PF-047.
 *
 * ## This is the seam that keeps the ledger ignorant
 *
 * `Ledger` must not know what an invoice is. If it did, every new document
 * type would mean changing the ledger, and the ledger is the one module where
 * a change is expensive - it is where the invariants live and where a mistake
 * is discovered a quarter later by an auditor.
 *
 * So `Sales` registers a rule saying how an issued invoice posts, and the
 * ledger resolves it by key without understanding it. Adding credit notes,
 * payroll or depreciation adds rules; it does not touch this module.
 *
 * ## Rules are inspectable BEFORE they run
 *
 * `describe()` returns what the rule will hit, without an event and without
 * posting anything. That is what lets an operator see "an issued invoice
 * debits receivables and credits revenue and output VAT" on a screen, during
 * configuration, rather than discovering it from a trial balance in March.
 *
 * A rule that can only be understood by running it is a rule nobody will
 * review.
 */
interface PostingRule
{
    /**
     * The key modules register under, e.g. `sales.invoice.issued`.
     *
     * It appears on every entry this rule produces, which is how a balance is
     * traced back not just to a document but to the reason that document
     * posted the way it did.
     */
    public function key(): string;

    /** A short description for the inspection screen. */
    public function describe(): RuleDescription;

    /**
     * The lines, in the entity's functional currency unless stated.
     *
     * Returning an unbalanced set is a programming error and is caught by the
     * poster before anything is written - with this rule's name attached, so
     * the failure says which rule is wrong rather than which constraint fired.
     *
     * @param  array<string, mixed>  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array;
}
