<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching\Passes;

use App\Tenant\Banking\Matching\Candidate;
use App\Tenant\Banking\Matching\MatchingContext;

/**
 * One way of deciding what a bank line is - PF-156 to PF-160.
 *
 * ## A pass proposes and never applies
 *
 * Every pass returns candidates. None of them writes anything, decides
 * whether its own score is good enough, or stops the ones after it. That
 * belongs to the engine, which is the only place that can see all of them
 * at once - and seeing all of them at once is what makes ambiguity
 * detectable.
 *
 * A pass that applied its own best answer would be a pass that could never
 * be told "two of you agree and that is the problem".
 */
interface MatchingPass
{
    /** The `banking.matching.passes` key this is. */
    public function key(): string;

    /**
     * Whether this pass is worth running at all for a transaction.
     *
     * Cheap and conservative: it exists so the expensive passes skip the
     * transactions they could not possibly match, not so a pass can decide
     * it is not needed.
     */
    public function appliesTo(MatchingContext $context): bool;

    /**
     * @return list<Candidate>
     */
    public function run(MatchingContext $context): array;
}
