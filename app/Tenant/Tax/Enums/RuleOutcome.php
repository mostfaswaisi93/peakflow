<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Enums;

/**
 * What happened when a place-of-supply rule was evaluated - PF-078.
 *
 * ## Three outcomes, because "could not tell" is not "no"
 *
 * A rule that did not match and a rule that could not be evaluated must not
 * be the same value. If they were, a rule blocked for want of a delivery
 * location would fall through to a looser rule, and the looser rule would
 * answer - which is the engine guessing while appearing to follow its own
 * precedence.
 *
 * So evaluation returns one of three, and the engine stops at the first rule
 * that is not `NoMatch`.
 */
enum RuleOutcome
{
    /** Every condition holds. This rule decides. */
    case Matched;

    /** At least one condition does not hold. Try the next rule. */
    case NoMatch;

    /**
     * Every other condition holds and the delivery location is not known.
     *
     * The engine refuses here rather than continuing, because this rule has
     * higher precedence than anything that follows and might have won.
     */
    case NeedsTheDeliveryLocation;
}
