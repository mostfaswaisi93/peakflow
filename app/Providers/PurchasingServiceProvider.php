<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Purchasing\Posting\Rules\BillPostedRule;
use App\Tenant\Purchasing\Posting\Rules\ExpenseClaimPostedRule;
use App\Tenant\Purchasing\Posting\Rules\GoodsReceivedRule;
use App\Tenant\Purchasing\Posting\Rules\SupplierCreditNotePostedRule;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the purchasing posting rules - PF-133.
 *
 * A provider of its own, for the same reason `Sales` and `Tax` have theirs:
 * the layering ruleset lets `Purchasing` depend on `Ledger` and not the
 * reverse, and registering a purchasing rule from `LedgerServiceProvider`
 * would make the ledger's own wiring import from the purchasing module.
 * Deptrac does not inspect providers, so the violation would be invisible to
 * the check that exists to catch it.
 */
final class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {

        /*
         | The business metrics this module owns - PF-241.
         |
         | Registered through the registry in `Support` for the same reason
         | the closing checks and the ledger checks are: a metrics service
         | that asked each module directly would depend on all of them, and
         | the dashboards are derived from what is registered rather than
         | written out somewhere that drifts.
         */
        $metrics = $this->app->make(\App\Support\Telemetry\Metrics\Metrics::class);

        $metrics->register($this->app->make(\App\Tenant\Purchasing\Metrics\DocumentsAwaitingApproval::class));

        $registry = $this->app->make(PostingRuleRegistry::class);

        $registry->register(new BillPostedRule());
        $registry->register(new GoodsReceivedRule());
        $registry->register(new SupplierCreditNotePostedRule());
        $registry->register(new ExpenseClaimPostedRule());
    }
}
