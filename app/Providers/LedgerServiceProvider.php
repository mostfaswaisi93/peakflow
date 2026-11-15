<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Ledger\Posting\Rules\ForeignCurrencyRevaluationRule;
use App\Tenant\Ledger\Posting\Rules\OpeningBalanceRule;
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
    }

    public function boot(): void
    {
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
    }
}
