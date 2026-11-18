<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Audit\AuditTrail;
use App\Support\Money\TranslatesMoney;
use App\Tenant\Catalog\Services\ExchangeRates;
use App\Support\Config\ProductionConfiguration;
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
         | The virus scanner - PF-073.
         |
         | Bound by configuration, and there is deliberately no driver that
         | accepts everything: `unavailable` returns `failed`, which the read
         | path refuses, so a machine with no scanner can exercise the whole
         | upload path and still cannot serve a file nobody has looked at.
         */
        $this->app->bind(\App\Support\Attachments\VirusScanner::class, static function (): \App\Support\Attachments\VirusScanner {
            return match ((string) config('attachments.scanner', 'unavailable')) {
                'clamav' => new \App\Support\Attachments\ClamAvScanner(
                    (string) config('attachments.clamav.binary', 'clamdscan'),
                    (int) config('attachments.clamav.timeout_seconds', 60),
                ),
                default => new \App\Support\Attachments\UnavailableScanner(),
            };
        });

        /*
         | The optical reader - PF-142.
         |
         | The same shape as the scanner above, and for the same reason:
         | there is no driver that invents figures. `none` throws, the
         | pipeline records the proposal as `unavailable` with that reason,
         | and a document carrying embedded XML is still read exactly -
         | which is most of what matters, because a Factur-X PDF is a
         | structured document and running OCR over the picture of its
         | figures would be choosing a guess over a fact.
         */
        $this->app->bind(\App\Exchange\Extraction\OpticalReader::class, static function (): \App\Exchange\Extraction\OpticalReader {
            return match ((string) config('extraction.driver', 'none')) {
                default => new \App\Exchange\Extraction\UnavailableOpticalReader(),
            };
        });

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

        /*
         | Translating an amount for a caller that will not store the rate.
         |
         | `Parties` needs a credit exposure in one currency and may not
         | depend on `Catalog`; anything that POSTS a translation uses
         | `ExchangeRates` directly and records the rate with it. Same
         | decision and same reason as the audit trail above.
         */
        $this->app->bind(TranslatesMoney::class, ExchangeRates::class);

        /*
         | The Content Security Policy, SCOPED - PF-237.
         |
         | `scoped` rather than `singleton`, and the difference is the whole
         | security property. The object holds one per-request nonce so the
         | value in the header and the value on the `<script>` tag are the
         | same string; a singleton in a long-lived worker would hold the
         | same nonce for every response it ever served, and a nonce an
         | attacker can read off one page and reuse on the next is the same
         | as having no nonce at all.
         |
         | `bind` would be the other mistake: a fresh instance per resolution
         | means the view and the middleware generate different nonces, the
         | header blocks the script it was supposed to allow, and the symptom
         | is the first paint appearing in the wrong theme.
         */
        $this->app->scoped(\App\Support\Http\ContentSecurityPolicy::class);
    }

    public function boot(): void
    {
        $this->propagateTheCorrelationIdOutbound();

        $this->publishTheCspNonceToBlade();
        $this->enforceModelDiscipline();
        $this->guardAgainstFloatMoney();
        $this->refuseToStartOnUnsafeProductionSettings();
    }

    /**
     * Refuse to boot a production deployment whose settings are silently wrong - PF-012.
     *
     * Every rule in {@see ProductionConfiguration} is a value whose wrong
     * setting WORKS: `sslmode=prefer` connects, `EXCHANGE_DRIVER=log` reports
     * success, an unset `SESSION_SECURE_COOKIE` logs people in. The discovery
     * in each case is made by somebody other than the person who deployed it.
     *
     * So this throws rather than logs. The cost is accepted: a deployment that
     * has not read `docs/operations/configuration.md` does not start, and the
     * message names every wrong value and why it matters. A warning in a log
     * is read during the incident it was meant to prevent.
     *
     * Production only - every rule here would make local development
     * impossible, and `.env.example` is deliberately a configuration this
     * guard rejects.
     */
    /**
     * `@cspNonce` in a template - PF-237.
     *
     * A directive rather than a documented call to `app()`, because the one
     * thing that must not happen is a template resolving the policy a second
     * way and getting a second nonce. The directive is the only spelling, so
     * the inline script that needs one cannot be written with a nonce that
     * the header does not know about.
     *
     * It emits the whole attribute - `nonce="..."` - and not just the value,
     * so a template cannot produce `nonce=abc` unquoted.
     */
    private function publishTheCspNonceToBlade(): void
    {
        \Illuminate\Support\Facades\Blade::directive(
            'cspNonce',
            static fn (): string => "<?php echo app(\App\Support\Http\ContentSecurityPolicy::class)->nonceAttribute(); ?>",
        );
    }

    private function refuseToStartOnUnsafeProductionSettings(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        ProductionConfiguration::assert();
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
