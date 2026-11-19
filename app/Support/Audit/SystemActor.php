<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * What acted, when nobody did - PF-248.
 *
 * ## The sentence that was already in the code
 *
 * `AuditLog::record()` carries this, written long before this class:
 *
 *     Attributing such work to nobody would be worse than wrong - a
 *     segregation-of-duties rule asks "did THIS person already do that",
 *     and a duty recorded with no actor is a duty that conflicts with
 *     nothing and silently weakens every rule that would have matched it.
 *
 * It then resolved the actor from `auth()->guard('web')`, which outside a
 * browser request is null. So every audit entry written by a console
 * command - and every one written by a queued job - recorded no actor at
 * all. Measured: `auth()->guard('web')->id()` is NULL in console context.
 *
 * That was tolerable while nothing ran on a schedule. PF-243 put ten
 * commands on one, several of which write to the audit log every night:
 * subscriptions moving state, quotes closing, dunning notices going out to
 * customers. Each of those entries said that something happened and not
 * what did it.
 *
 * ## `actor_user_id` stays null, and that is right
 *
 * No user did it. Inventing a "system" user row would make a console run
 * indistinguishable from a person in every query that counts by actor, and
 * the segregation-of-duties indexes are keyed on `actor_user_id` - a system
 * actor appearing there would start conflicting with real duties.
 *
 * `actor_name` is free text and already holds the person's name. What goes
 * in it here is what ran, so the trail reads "quotes:expire" rather than
 * nothing - and the difference between "the scheduler did this" and "we do
 * not know who did this" is recoverable instead of lost.
 *
 * ## Set by events rather than read from argv
 *
 * `$_SERVER['argv']` would work and would be wrong the first time a command
 * is invoked through `Artisan::call()` from inside another one, which is
 * how `invariants:watch` works. The framework says which command is running
 * and which job is being processed; this holds the answer and nothing else.
 */
final class SystemActor
{
    private ?string $what = null;

    /**
     * Name what is acting now.
     *
     * The last one wins, deliberately: when a command invokes another, the
     * inner one is what is writing, and naming the outer would attribute an
     * entry to the thing that scheduled it rather than the thing that did
     * it. The caller restores the previous value if it cares, which is what
     * {@see self::during()} is for.
     */
    public function acting(?string $what): void
    {
        $this->what = $what === null || trim($what) === '' ? null : trim($what);
    }

    /** What is acting, or null when a person is. */
    public function describe(): ?string
    {
        return $this->what;
    }

    /**
     * Run something attributed to a named actor, and put back what was
     * there.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function during(string $what, callable $work): mixed
    {
        $previous = $this->what;

        $this->acting($what);

        try {
            return $work();
        } finally {
            $this->what = $previous;
        }
    }
}
