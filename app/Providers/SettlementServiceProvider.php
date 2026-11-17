<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Settlement\Posting\Rules\AllocationPostedRule;
use App\Tenant\Settlement\Posting\Rules\PaymentRecordedRule;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the settlement posting rules - PF-166, PF-168.
 *
 * ## Two rules, because they are two facts
 *
 * Recording a payment and allocating it are separate acts with separate
 * postings, and that separation is the whole design of this module: "they
 * sent 10,000" and "10,000 of their invoices are settled" become one fact
 * only when somebody says which invoices.
 *
 * A single rule doing both would be a rule that guesses, and the cost is
 * not a wrong total - it is a customer balance nobody can take apart.
 *
 * ## Why a provider of its own
 *
 * The same reason as the tax and banking providers. The layering ruleset
 * lets Settlement depend on Ledger and not the reverse, and registering a
 * settlement rule from `LedgerServiceProvider` would make the ledger's own
 * wiring import from this module - a violation Deptrac cannot see, because
 * it does not inspect providers.
 */
final class SettlementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(PostingRuleRegistry::class);

        $registry->register(new PaymentRecordedRule());
        $registry->register(new AllocationPostedRule());

        /*
         | And the two halves of a write-off - PF-172.
         |
         | Two rules, because a recovery is not an un-write-off. The
         | conclusion that a debt would not be collected was right on the
         | day it was made; crediting bad debt back would say otherwise
         | and would net the two figures into one that describes neither.
         */
        /*
         | The in-transit trio - PF-178, PF-179.
         |
         | Released, cleared, refused. Three rules rather than one with a
         | sign, because they answer different questions and a reader of
         | the ledger needs to see which happened: an instruction that
         | cleared and one the bank refused both close the in-transit
         | position, and only one of them means the supplier was paid.
         */
        $registry->register(new \App\Tenant\Settlement\Posting\Rules\RunReleasedRule());
        $registry->register(new \App\Tenant\Settlement\Posting\Rules\TransitClearedRule());
        $registry->register(new \App\Tenant\Settlement\Posting\Rules\InstructionRejectedRule());

        $registry->register(new \App\Tenant\Settlement\Posting\Rules\WriteOffPostedRule());

        /*
         | And what Settlement asserts about the ledger - PF-179.
         |
         | Through the registry in `Support`, so the verifier never learns
         | what a payment run is. In transit equals released-and-
         | unreconciled: the figure in the middle of the three-way tie
         | between AP, the bank and the GL.
         */
        $this->app->make(\App\Support\Verification\LedgerChecks::class)->register(
            $this->app->make(\App\Tenant\Settlement\Verification\TransitIsReleasedAndUnreconciled::class),
        );
        $registry->register(new \App\Tenant\Settlement\Posting\Rules\RecoveryPostedRule());

        /*
         | And the two halves of the estimate - PF-173.
         |
         | Charged when a provision is computed, released when a write-off
         | uses one up. Separate from the write-off rules because a
         | provision is an estimate and a write-off is a conclusion, and
         | sharing an account would destroy the figure that calibrates the
         | matrix: how much of last year's estimate turned out to be real.
         */
        $registry->register(new \App\Tenant\Settlement\Posting\Rules\EclProvisionChargedRule());
        $registry->register(new \App\Tenant\Settlement\Posting\Rules\EclProvisionReleasedRule());
    }
}
