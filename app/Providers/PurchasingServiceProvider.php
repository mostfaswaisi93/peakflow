<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Purchasing\Posting\Rules\BillPostedRule;
use App\Tenant\Purchasing\Posting\Rules\GoodsReceivedRule;
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
        $registry = $this->app->make(PostingRuleRegistry::class);

        $registry->register(new BillPostedRule());
        $registry->register(new GoodsReceivedRule());
    }
}
