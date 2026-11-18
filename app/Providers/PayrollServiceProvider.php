<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Payroll\Posting\Rules\PayrollPostedRule;
use Illuminate\Support\ServiceProvider;

/**
 * Where payroll says how it posts - PF-256.
 *
 * One rule, deliberately. A payroll run is one event in the books: the
 * gross and the employer's contributions as costs, the net and the
 * deductions as liabilities, in a single entry. Splitting it into a rule
 * per component would make the month's payroll a dozen entries that have
 * to be read together to see that they balance - and the first one
 * somebody posts alone is a journal nobody can explain.
 */
final class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(PostingRuleRegistry::class)->register(new PayrollPostedRule());
    }
}
