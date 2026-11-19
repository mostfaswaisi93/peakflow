<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Verification\LedgerChecks;
use App\Tenant\Reporting\Verification\SubledgersTieToTheirControls;
use Illuminate\Support\ServiceProvider;

/**
 * What `ledger:verify` asserts about the subledgers - PF-163.
 *
 * ## Why `Reporting` has a provider at all
 *
 * It did not need one: its services are resolved from the container and
 * have nothing to register. This exists for one reason - the receivables
 * and payables ties, which were asserted by nothing.
 *
 * The check belongs in `Reporting` and not in `Sales` or `Purchasing`
 * because the one definition of a document's open balance is used by the
 * ageing, `Sales` may not depend on `Reporting`, and a tie computed in
 * `Sales` from invoices plus a control balance read out of `journal_lines`
 * would be a second answer to "does receivables tie". The module that can
 * reach both sides without inverting any dependency is this one.
 *
 * ## Registered through the registry, like the other three
 *
 * `Banking`, `Settlement` and `Inventory` each register a `LedgerCheck` the
 * same way. The verifier asks a list and never learns what an invoice is,
 * which is what keeps `Ledger` dependent on nothing but `Tenancy`.
 *
 * ## Last, and the order is load-bearing
 *
 * In `bootstrap/providers.php` this comes after the modules whose services
 * the ageing resolves. A `register()` that resolved `AgingReport` eagerly
 * would need them bound already; it does not - the check takes it as a
 * constructor argument and the container resolves it when `ledger:verify`
 * runs - but the ordering is the one that stays correct if that ever
 * changes.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(LedgerChecks::class)->register(
            $this->app->make(SubledgersTieToTheirControls::class),
        );
    }
}
