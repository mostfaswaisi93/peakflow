<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Tenant\Ledger\Services\LedgerFinding;
use App\Tenant\Ledger\Services\LedgerVerifier;
use Throwable;

/**
 * The global proof, as the last act of any suite that posts - PF-054.
 *
 * ## Why every posting suite ends with this
 *
 * An individual assertion can be written to pass. A check that posts an entry
 * and then asserts the cash account moved by the right amount will pass even
 * if the rule posted twice, posted to the wrong receivable, posted into a
 * closed period, or left an entry whose fingerprint no longer matches -
 * because the check only looks at what its author thought to look at.
 *
 * `ledger:verify` asks the questions no individual check can fake, over the
 * whole ledger. Running it last means a rule that breaks an invariant fails
 * the check that exercised it, named and dated, rather than a check written
 * months later by somebody who happened to guess the right question.
 *
 * ## Why it is a trait AND an architecture rule
 *
 * The trait makes it one line. The rule is what makes it wiring rather than a
 * convention: a suite in `tests/Support` that writes to `journal_lines` and
 * does not end with this proof fails `bin/verify arch`. Forgetting it is a
 * build failure, which is the only form of "wired in" that survives the
 * person who wrote the convention leaving.
 *
 * ## It runs INSIDE the suite's transaction
 *
 * Which is why it cannot live in `bin/verify`. Every suite rolls back in a
 * `finally`, so by the time a runner saw the results there would be nothing
 * left to verify. The proof has to be the last thing the suite does while its
 * data still exists.
 */
trait ProvesTheLedger
{
    /**
     * Append the global proof to a suite's results.
     *
     * @param  array<string, list<string>>  $checks
     * @return array<string, list<string>>
     */
    final protected function withTheLedgerProof(array $checks, ?string $entityId = null): array
    {
        return $checks + [
            'and the ledger still proves its own invariants' => $this->theLedgerProof($entityId),
        ];
    }

    /** @return list<string> */
    final protected function theLedgerProof(?string $entityId = null): array
    {
        try {
            $findings = app(LedgerVerifier::class)->verify($entityId);
        } catch (Throwable $e) {
            return [sprintf('the global proof could not run: %s', $e->getMessage())];
        }

        /*
         | Critical findings only.
         |
         | The warnings are real and are not this suite's business: "entries
         | posted before the fingerprint existed" is a true statement about
         | a reprovisioned database and would fail every suite forever,
         | which is how a check stops being read.
         */
        return $findings
            ->filter(static fn (LedgerFinding $finding): bool => $finding->isCritical())
            ->map(static fn (LedgerFinding $finding): string => sprintf(
                '%s: %s',
                $finding->kind,
                $finding->detail,
            ))
            ->values()
            ->all();
    }
}
