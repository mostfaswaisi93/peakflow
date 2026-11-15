<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Listeners;

use App\Support\Events\SideEffect;
use App\Tenant\Ledger\Events\RevaluationRun;
use App\Tenant\Ledger\Services\BalanceRebuilder;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Verifies the balance read model after a revaluation - PF-048, PF-053.
 *
 * ## Why a revaluation in particular
 *
 * It is the heaviest thing the ledger does on its own: a reversal of the
 * previous run and a fresh multi-line entry, every line of it maintaining
 * `account_balances` through a trigger. If the increments and the journal are
 * ever going to disagree, this is where it shows.
 *
 * ## Why it is a side effect, and the test it passes
 *
 * The test in {@see SideEffect} is: if this failed and nobody noticed for an
 * hour, would the books be wrong? No. The books are the journal. This compares
 * a CACHE against the journal and reports a difference - so an hour late it
 * reports the same difference, and the journal was right the whole time.
 *
 * Which is also why it must not be synchronous. A full recomputation of every
 * balance inside the revaluation's transaction would hold its locks for the
 * length of the comparison, and a failure in the comparison - a timeout, a
 * memory limit on a large tenant - would roll back a posting that was correct.
 * Reporting a drift is not worth undoing a revaluation.
 *
 * ## The tenant comes from the payload; the entity comes from the event
 *
 * Two different kinds of context and they arrive by different routes on
 * purpose. Which DATABASE this runs against is infrastructure, stamped onto
 * every payload by {@see \App\Providers\QueueTenancyServiceProvider}. Which
 * ENTITY it concerns is domain, and is on the event.
 *
 * Establishing the entity is not optional: `account_balances` and
 * `journal_lines` are both under row-level security, and with no entity set
 * the policy matches nothing. Both sides of the comparison would come back
 * empty and agree perfectly - a drift check that reports "clean" because it
 * read nothing is worse than no drift check at all.
 */
final class CheckBalancesAfterARevaluation implements ShouldQueue, SideEffect
{
    use InteractsWithQueue;

    /**
     * Its own queue.
     *
     * A comparison over every balance is slow, and sharing a queue with the
     * notifications means a large tenant's drift check delays every small
     * tenant's mail behind it.
     */
    public string $queue = 'verification';

    /**
     * Retried, but not many times.
     *
     * A drift finding is recorded by the check itself, so a retry re-reads
     * and re-reports; three attempts covers a lost connection without
     * writing the same finding ten times.
     */
    public int $tries = 3;

    public function __construct(
        private readonly BalanceRebuilder $balances,
        private readonly EntityContext $entities,
    ) {}

    public function whyDeferrable(): string
    {
        return 'It compares a cache against the journal. The journal is the ledger, so a drift that is '.
            'reported an hour late was a drift for that hour either way - and recomputing every balance '.
            'inside the posting transaction would hold its locks for the length of the comparison and let '.
            'a timeout in a verification roll back a revaluation that was correct.';
    }

    public function handle(RevaluationRun $event): void
    {
        /*
         | `during()` rather than `use()`.
         |
         | The worker's container is reused across jobs. An entity left
         | established by this job is an entity the next job inherits, and
         | the next job may be for another tenant entirely - at which point
         | the id matches nothing and its queries return empty rather than
         | wrong, which is the better of two bad outcomes but is still a bug
         | that presents as missing data.
         */
        $result = $this->entities->during($event->entityId(), fn (): array => $this->balances->check(
            $event->entityId(),
        ));

        if ($result['clean']) {
            return;
        }

        /*
         | Logged here as well as recorded by `check()`.
         |
         | `check()` writes the findings and the audit entry, which is what
         | somebody reads in March. This line says which revaluation was
         | running when the drift appeared, which is what somebody needs in
         | the ten minutes after the alert - and the run id is not something
         | the rebuilder knows.
         */
        Log::error('Balance drift found after a revaluation', [
            'run_id' => $event->runId,
            'entity_id' => $event->entityId(),
            'period_id' => $event->periodId,
            'accounts_restated' => $event->accountsRestated,
            'findings' => count($result['findings']),
            'checked' => $result['checked'],
        ]);
    }
}
