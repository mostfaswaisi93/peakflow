<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Closing\ClosingChecks;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Tax\Closing\TaxReconciliationCheck;
use App\Tenant\Tax\Posting\Rules\PurchaseTaxRule;
use App\Tenant\Tax\Posting\Rules\ReverseChargeSelfAssessmentRule;
use App\Tenant\Tax\Posting\Rules\SalesTaxRule;
use App\Tenant\Tax\Posting\Rules\WithholdingOnPaymentRule;
use App\Tenant\Tax\Posting\Rules\WithholdingOnReceiptRule;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the tax posting rules - PF-084.
 *
 * ## Why a provider of its own rather than a few more lines in the ledger's
 *
 * Because the layering ruleset lets Tax depend on Ledger and not the reverse,
 * and registering a tax rule from `LedgerServiceProvider` would make the
 * ledger's own wiring import from the tax module. Deptrac does not inspect
 * providers, so the violation would be invisible to the check that exists to
 * catch exactly this - and the first person to follow the pattern would put
 * something heavier through the same door.
 *
 * The registry is a singleton owned by the ledger. Registering into it from
 * here is a one-directional call that the ruleset already permits.
 */
final class TaxServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(PostingRuleRegistry::class);

        $registry->register(new SalesTaxRule());
        $registry->register(new PurchaseTaxRule());

        /*
         | Both sides of a reverse charge, in one rule.
         |
         | One rule rather than two because the two legs are not independently
         | correct: posting the input without the output reports a claim with
         | no liability, which is the shape of a refund request an authority
         | investigates. Separate rules could be called separately, and the
         | half that gets forgotten is always the output.
         */
        $registry->register(new ReverseChargeSelfAssessmentRule());

        $registry->register(new WithholdingOnPaymentRule());
        $registry->register(new WithholdingOnReceiptRule());

        /*
         | And the reason a period might not close - PF-088.
         |
         | Registered into a registry in `Support` that the ledger consults,
         | so the ledger never learns what a tax return is. An unexplained
         | difference between the control accounts and the return stops the
         | close, because the close is the last moment the period can still be
         | posted into.
         */
        $this->app->make(ClosingChecks::class)->register(
            $this->app->make(TaxReconciliationCheck::class),
        );
    }
}
