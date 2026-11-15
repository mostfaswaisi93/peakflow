<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Sales\Posting\Rules\CreditNoteIssuedRule;
use App\Tenant\Sales\Posting\Rules\CorrectedInvoiceIssuedRule;
use App\Tenant\Sales\Posting\Rules\FinalInvoiceIssuedRule;
use App\Tenant\Sales\Posting\Rules\InvoiceIssuedRule;
use App\Tenant\Sales\Posting\Rules\PrepaymentInvoiceIssuedRule;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the sales posting rules - PF-095.
 *
 * A provider of its own for the same reason the tax one has one: the layering
 * ruleset lets Sales depend on Ledger and not the reverse, and registering a
 * sales rule from `LedgerServiceProvider` would make the ledger's own wiring
 * import from the sales module. Deptrac does not inspect providers, so the
 * violation would be invisible to the check that exists to catch it.
 */
final class SalesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(PostingRuleRegistry::class);

        $registry->register(new InvoiceIssuedRule());
        $registry->register(new CreditNoteIssuedRule());
        $registry->register(new CorrectedInvoiceIssuedRule());
        $registry->register(new PrepaymentInvoiceIssuedRule());
        $registry->register(new FinalInvoiceIssuedRule());
    }
}
