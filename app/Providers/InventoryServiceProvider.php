<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenant\Inventory\Posting\Rules\StockAdjustedRule;
use App\Tenant\Inventory\Posting\Rules\StockIssuedRule;
use App\Tenant\Inventory\Posting\Rules\StockReceivedRule;
use App\Tenant\Inventory\Posting\Rules\StockReturnedRule;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Where Inventory says how stock posts - PF-248.
 *
 * ## Why stock is its own module and not part of Catalog
 *
 * The first draft put it in `Catalog`, and the layer rules refused it:
 * Catalog may use `Support` and `Tenancy` and nothing else, so it cannot
 * post. That boundary is deliberate and right - Catalog is master data,
 * and a price list has no business writing journal entries.
 *
 * A stock movement is not master data. It is a document: it happens at a
 * moment, it posts, and it cannot be edited afterwards. So it belongs in
 * a transactional module alongside Sales and Purchasing, which depends on
 * `Catalog` for the item and on `Ledger` to post - exactly as they do.
 *
 * Found by the architecture check on the first run, which is the cheapest
 * possible moment: the alternative was giving Catalog the Ledger, and the
 * first thing to follow would have been a price list that posts.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(PostingRuleRegistry::class);

        /*
         | Four rules, because the credit differs and a reader needs to
         | see which happened.
         |
         | A receipt credits goods-received-not-invoiced, because the
         | goods are here and the supplier's invoice is not. An issue
         | credits inventory against cost of sales. An adjustment does
         | the same and is a different event: one has a creditor behind
         | it, the other has a reason somebody typed.
         |
         | A sales return posts the adjustment's shape and is a fourth
         | rule rather than a reuse of either - PF-252. Restoring it with
         | a receipt would credit a supplier gap that has no supplier,
         | and folding it into adjustments would put returns into the
         | shrinkage figure, where a rising returns rate looks like
         | theft.
         */
        $registry->register(new StockReceivedRule());
        $registry->register(new StockIssuedRule());
        $registry->register(new StockAdjustedRule());
        $registry->register(new StockReturnedRule());

        /*
         | And what Catalog asserts about the ledger - PF-248.
         |
         | The inventory control account equals what the movements say the
         | stock is worth. Through the registry in `Support`, so the
         | verifier never learns what a stock movement is.
         */
        $this->app->make(\App\Support\Verification\LedgerChecks::class)->register(
            $this->app->make(\App\Tenant\Inventory\Verification\InventoryIsItsMovements::class),
        );
    }
}
