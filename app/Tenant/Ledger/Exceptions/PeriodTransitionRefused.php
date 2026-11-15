<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use App\Tenant\Ledger\Enums\PeriodState;
use RuntimeException;

/**
 * A period state change that will not happen - PF-050.
 *
 * ## The messages are read by an accountant mid-close
 *
 * Which shapes all of them. A database error naming a constraint is not
 * something to show somebody who has just been told the year is filed, and
 * "invalid transition" tells them nothing about what to do instead.
 */
final class PeriodTransitionRefused extends RuntimeException
{
    public static function alreadyThere(string $label, PeriodState $state): self
    {
        return new self(sprintf(
            '%s is already %s. Nothing was changed, and nothing was recorded - a transition to the state a '.
            'period is already in would put a line in the history saying something happened when it did not.',
            $label,
            $state->value,
        ));
    }

    /**
     * The terminal state.
     *
     * Refused here as well as by the database trigger: the trigger is the
     * guarantee, this is the explanation. A locked period has had a return
     * filed against it, and the correction for a filed return is an
     * amendment rather than an edit.
     */
    public static function locked(string $label): self
    {
        return new self(sprintf(
            '%s is locked and cannot be changed by anyone, including an operator. It was locked after the '.
            'statutory filing, and a figure in a filed return is corrected by an amendment in a later '.
            'period - not by editing the period it was filed from.',
            $label,
        ));
    }

    public static function notAllowed(string $label, PeriodState $from, PeriodState $to): self
    {
        return new self(sprintf(
            '%s is %s and cannot go straight to %s. From %s the only moves are: %s.',
            $label,
            $from->value,
            $to->value,
            $from->value,
            $from->allowedTransitions() === []
                ? 'none'
                : implode(', ', array_map(
                    static fn (PeriodState $state): string => $state->value,
                    $from->allowedTransitions(),
                )),
        ));
    }

    /**
     * Reopening with no stated reason.
     *
     * The reason is read during a review of a restated figure - and that
     * review happens because something went wrong, when the only artefact
     * is what somebody typed at the time. "Fix" is not an artefact.
     */
    /**
     * Something outside the ledger says this period must not close - PF-088.
     *
     * @param  list<string>  $reasons
     */
    public static function closingIsBlocked(string $label, array $reasons): self
    {
        return new self(sprintf(
            'The %s period will not close while %d thing%s outstanding: %s. Closing it would freeze '.
            'figures that nobody has reconciled, and the reconciliation is much harder afterwards - the '.
            'period cannot be posted into, so every correction becomes a restatement.',
            $label,
            \count($reasons),
            \count($reasons) === 1 ? ' is' : 's are',
            implode(' | ', $reasons),
        ));
    }

    public static function reopeningNeedsAReason(string $label): self
    {
        return new self(sprintf(
            'Reopening %s needs a reason somebody can read later. A reopened period means a reported figure '.
            'may have changed after it was reported, which is a disclosure question rather than an '.
            'operational one - and the review of it happens when something has gone wrong, with nothing to '.
            'go on but what you write here.',
            $label,
        ));
    }

    public static function transitionNeedsAReason(string $label, PeriodState $to): self
    {
        return new self(sprintf(
            'Moving %s to %s needs a reason. Every transition is recorded with one, because a close with no '.
            'stated reason is indistinguishable from a close somebody made by accident.',
            $label,
            $to->value,
        ));
    }
}
