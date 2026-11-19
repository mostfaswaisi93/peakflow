<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenant\Ledger\Charts\ChartTemplateLibrary;
use App\Tenant\Ledger\Events\RevaluationRun;
use App\Tenant\Ledger\Listeners\CheckBalancesAfterARevaluation;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Ledger\Posting\Rules\ForeignCurrencyRevaluationRule;
use App\Tenant\Ledger\Posting\Rules\InterEntityDueFromRule;
use App\Tenant\Ledger\Posting\Rules\InterEntityDueToRule;
use App\Tenant\Ledger\Posting\Rules\OpeningBalanceRule;
use App\Tenant\Ledger\Posting\Rules\YearEndCloseRule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the posting rules the ledger itself owns - PF-047.
 *
 * The registry is a singleton because registration happens at boot and
 * resolution happens per posting; a scoped instance would lose every rule
 * between requests in a long-running worker and the first posting after a
 * restart would fail with "no rules are registered at all".
 *
 * Other modules register their own rules in their own providers. This one
 * holds only what has no other owner - opening balances belong to the ledger
 * because no document produces them.
 */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PostingRuleRegistry::class);

        /*
         | The registry of reasons a period might not close - PF-088.
         |
         | Owned by the ledger because closing is its act; filled by other
         | modules through the interface in `Support`.
         */
        $this->app->singleton(\App\Support\Closing\ClosingChecks::class);
        $this->app->singleton(\App\Support\Verification\LedgerChecks::class);

        /*
         | The chart templates, read from disk once per process.
         |
         | A singleton because parsing and checking three JSON files on every
         | resolution is work with one answer, and because the check is the
         | expensive half: every template is validated at load, and a
         | provisioning run that resolves the library per entity would
         | revalidate the same files for each one.
         */
        $this->app->singleton(ChartTemplateLibrary::class, static fn (): ChartTemplateLibrary =>
            ChartTemplateLibrary::shipped());
    }

    public function boot(): void
    {

        /*
         | The invariants this module watches - PF-242.
         |
         | Registered through the registry in `Support`, the same door the
         | closing checks and the ledger checks come through. Every condition
         | here was already provable and none of it was WATCHED: nothing in
         | the repository ran the check that finds it.
         */
        $watch = $this->app->make(\App\Support\Telemetry\Alerts\InvariantWatch::class);

        $watch->register($this->app->make(\App\Tenant\Ledger\Alerts\LedgerInvariantWatcher::class));

        $watch->register($this->app->make(\App\Tenant\Ledger\Alerts\ReadModelDriftWatcher::class));

        $registry = $this->app->make(PostingRuleRegistry::class);

        $registry->register(new OpeningBalanceRule());

        /*
         | Revaluation belongs to the ledger for the same reason opening
         | balances do: no document produces it. It is a consequence of a
         | period ending and a rate having moved, and the rate arrives in the
         | event rather than being looked up here - which is what keeps the
         | ledger from needing to know what the catalog is.
         */
        $registry->register(new ForeignCurrencyRevaluationRule());

        /*
         | The year-end close, for the same reason as the other two: no
         | document produces it. It is a consequence of a year ending, and
         | it posts as an ordinary entry in a reserved journal so it can be
         | read, drilled into and reversed.
         */
        $registry->register(new YearEndCloseRule());

        /*
         | Both sides of an inter-entity transaction - PF-057.
         |
         | Two rules rather than one parameterised by direction, because the
         | sides post in different entities, at different rates, against
         | different charts - and a reserved journal names ONE rule, so two
         | sides sharing a key could not be told apart by where they landed.
         */
        $registry->register(new InterEntityDueFromRule());
        $registry->register(new InterEntityDueToRule());

        /*
         | The one queued listener in the ledger, and it verifies rather
         | than posts.
         |
         | Queued because it is a side effect per PF-048: it compares the
         | balance read model against the journal, and the journal is the
         | ledger. An architecture rule enforces the pairing both ways - a
         | listener on a financial event that is queued without being marked
         | SideEffect fails the build, and so does a SideEffect that is not
         | queued.
         */
        Event::listen(RevaluationRun::class, CheckBalancesAfterARevaluation::class);

        /*
         | Two things that must stop a period closing - PF-196.
         |
         | They are the ledger's OWN blockers, which is why they register
         | here rather than being consulted directly: the registry is what
         | keeps the ledger from learning what a tax return or a bank
         | reconciliation is, and the ledger putting its own checks in
         | through the same door is what stops that door becoming a
         | special case.
         |
         | Neither can be signed off, unlike suspense. Unexplained money in
         | suspense is a fact about the world the close cannot always
         | resolve; a drifted balance and an unfinished draft are facts
         | about this system, each with a one-command fix.
         */
        $closing = $this->app->make(\App\Support\Closing\ClosingChecks::class);

        $closing->register($this->app->make(\App\Tenant\Ledger\Closing\ReadModelDriftCheck::class));
        $closing->register(new \App\Tenant\Ledger\Closing\UnpostedEntriesCheck());
    }
}
