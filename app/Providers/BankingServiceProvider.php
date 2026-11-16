<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Closing\ClosingChecks;
use App\Tenant\Banking\Closing\SuspenseCheck;
use App\Tenant\Banking\Posting\Rules\SuspenseClearedRule;
use App\Tenant\Banking\Posting\Rules\UnmatchedToSuspenseRule;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the banking posting rules and its closing check - PF-163.
 *
 * ## Why a provider of its own
 *
 * The same reason as `TaxServiceProvider`. The layering ruleset lets Banking
 * depend on Ledger and not the reverse, and registering a banking rule from
 * `LedgerServiceProvider` would make the ledger's own wiring import from the
 * banking module. Deptrac does not inspect providers, so the violation would
 * be invisible to the check that exists to catch exactly this.
 *
 * ## The closing check the ledger was already expecting
 *
 * `PeriodStateMachine` has said since PF-088 that "an unmatched bank line
 * will be another" reason a period cannot close. This is it, and the ledger
 * still knows nothing about what a bank line is: it consults a registry in
 * `Support` and this provider puts something into it.
 */
final class BankingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(PostingRuleRegistry::class);

        $registry->register(new UnmatchedToSuspenseRule());
        $registry->register(new SuspenseClearedRule());

        /*
         | One rule per kind of difference - PF-164.
         |
         | Not one rule with a type parameter. A posting rule is what
         | somebody reads to answer "what does a short payment do to my
         | accounts", and that question has six different answers; a
         | single registry entry called "difference" answers none of them.
         */
        $registry->register(new \App\Tenant\Banking\Posting\Rules\BankChargeDifferenceRule());
        $registry->register(new \App\Tenant\Banking\Posting\Rules\FxDifferenceRule());
        $registry->register(new \App\Tenant\Banking\Posting\Rules\ShortPaymentDifferenceRule());
        $registry->register(new \App\Tenant\Banking\Posting\Rules\OverpaymentDifferenceRule());
        $registry->register(new \App\Tenant\Banking\Posting\Rules\RoundingDifferenceRule());
        $registry->register(new \App\Tenant\Banking\Posting\Rules\WithholdingDifferenceRule());

        /*
         | And the reason a period might not close.
         |
         | Resolved from the container rather than constructed here: the
         | check needs the suspense service, which needs the poster, and a
         | provider that wires a dependency graph by hand is one that stops
         | matching the container the first time something gains an
         | argument.
         */
        $this->app->make(ClosingChecks::class)->register(
            $this->app->make(SuspenseCheck::class),
        );

        /*
         | And the conclusion the close rests on - PF-165.
         |
         | Every other control in this module protects one fact; this one
         | protects the conclusion, that the cash figure in the accounts
         | is the cash the business has. Cash is the one balance an
         | auditor can confirm independently, which is why it is the
         | first one they ask about.
         */
        $this->app->make(ClosingChecks::class)->register(
            $this->app->make(\App\Tenant\Banking\Closing\ReconciliationCheck::class),
        );

        /*
         | And what `ledger:verify` asserts about suspense - PF-163.
         |
         | That the control account's balance is accounted for by parked
         | bank items. The same registry argument: the verifier asks a list
         | and never learns what a bank line is.
         */
        $this->app->make(\App\Support\Verification\LedgerChecks::class)->register(
            $this->app->make(\App\Tenant\Banking\Verification\SuspenseIsTraceable::class),
        );
    }
}
