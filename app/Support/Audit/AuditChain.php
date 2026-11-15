<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Re-checking the audit log's hash chain - PF-054.
 *
 * ## Why a Support contract
 *
 * The chain belongs to `Identity`, which the ledger may not depend on. But
 * the ledger's global proof has to include it: "no posted entry has been
 * mutated" is two claims, and the chain is the half that covers everything
 * the ledger itself cannot see.
 *
 * ## The two halves, and why neither is enough alone
 *
 * The entry fingerprint says the entry's substance is what it was when it was
 * posted. The chain says the record of who did what, when, has not been
 * edited.
 *
 * Without the chain, somebody who changed a figure and re-posted it could
 * delete the audit entry that shows they did. Without the fingerprint, an
 * intact chain proves only that nobody edited the LOG - the ledger could have
 * been changed underneath it by a statement that wrote no audit entry at all.
 *
 * Both are needed, and the global proof reports both.
 */
interface AuditChain
{
    /**
     * Walk the chain and report the first divergence.
     *
     * @return array{intact: bool, checked: int, detail: ?string}
     *   `detail` names the record at the first divergence - the actionable
     *   fact. Everything before it is intact, everything after is
     *   unverifiable, and reporting only "the chain is broken" on a log with
     *   forty thousand records in it is close to useless.
     */
    public function verdict(): array;
}
