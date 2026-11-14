<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->enforceModelDiscipline();
        $this->guardAgainstFloatMoney();
    }

    /**
     * Fail loudly in development rather than silently in production.
     *
     * `shouldBeStrict` turns an N+1 into an exception. A query-budget test
     * catches those in CI, but an exception while the code is being written
     * catches them before the test is even written - and an N+1 is invisible
     * to every other kind of test, because the page renders and the data is
     * right.
     */
    private function enforceModelDiscipline(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * A last line of defence for the money path.
     *
     * The architecture rules forbid a float in application code, and
     * PostgreSQL `numeric` refuses to become one. This covers the gap between
     * them: a binding arriving as a PHP float on its way to the database means
     * something upstream coerced it, and the coercion has already lost
     * precision by the time it gets here.
     *
     * Development and testing only. In production the cost of inspecting every
     * binding is not worth paying, and by then the guards above have had every
     * opportunity to catch it.
     */
    private function guardAgainstFloatMoney(): void
    {
        if ($this->app->isProduction()) {
            return;
        }

        DB::listen(static function (QueryExecuted $query): void {
            foreach ($query->bindings as $binding) {
                if (\is_float($binding)) {
                    throw new RuntimeException(sprintf(
                        'A float binding (%s) reached the database from: %s. Money is exact decimal end to end - '.
                        'see docs/architecture/money-and-precision.md. Whatever produced this value has already lost precision.',
                        var_export($binding, true),
                        $query->sql,
                    ));
                }
            }
        });
    }
}
