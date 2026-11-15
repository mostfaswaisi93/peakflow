<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Audit\AuditTrail;
use App\Support\Telemetry\CorrelationId;
use App\Tenant\Identity\Services\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         | The audit trail, resolved by its Support contract.
         |
         | Every module writes to the audit log, and the implementation lives
         | in `Identity` because that is where users and the hash chain are.
         | Binding the interface here is what keeps `Ledger`, `Parties` and
         | `Catalog` from each acquiring a dependency on `Identity` for a
         | reason that has nothing to do with identity.
         |
         | Not a singleton: it reads the current entity from the request-
         | scoped EntityContext, and a singleton holding a stale one would
         | attribute a record to the wrong company.
         */
        $this->app->bind(AuditTrail::class, AuditLog::class);
    }

    public function boot(): void
    {
        $this->propagateTheCorrelationIdOutbound();

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

    /**
     * Send the correlation id on every outbound HTTP request - PF-014.
     *
     * ## Why this matters more than it sounds
     *
     * The outbound calls in a financial system are the ones that go wrong:
     * an e-invoicing clearance, a bank payment initiation, a rate feed. When
     * one of them fails, the question is what WE sent and what they say they
     * received - and a shared id is what lets their support team find it.
     *
     * ZATCA and most payment gateways log request headers. Sending the id
     * means a disputed clearance can be looked up on both sides by the same
     * value, rather than by a timestamp and a hope.
     *
     * ## A global middleware, so no call site can forget
     *
     * `Http::globalRequestMiddleware()` runs for every request the client
     * makes, including inside a package. Adding the header per call site is a
     * convention, and the call that matters is the one somebody adds in a
     * hurry.
     *
     * The header is NOT overwritten when a caller set one deliberately -
     * passing a specific id through is a legitimate thing to do when
     * forwarding somebody else's trace.
     */
    private function propagateTheCorrelationIdOutbound(): void
    {
        Http::globalRequestMiddleware(static function (RequestInterface $request): RequestInterface {
            if ($request->hasHeader(CorrelationId::HEADER)) {
                return $request;
            }

            return $request->withHeader(CorrelationId::HEADER, CorrelationId::current());
        });
    }
}
