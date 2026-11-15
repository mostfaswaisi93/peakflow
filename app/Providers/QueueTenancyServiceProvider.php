<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Services\QueueTenantContext;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Telemetry\CorrelationId;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Carrying the tenant onto the queue, and restoring it in the worker - PF-048.
 *
 * ## Why this is infrastructure and not each job's business
 *
 * Every queued job in a database-per-tenant system has to run against the
 * right database, and a worker is long-lived: Laravel caches the opened PDO
 * handle per connection NAME, and the name is `tenant` for all of them. A job
 * that does not re-point the connection reads whichever tenant the previous
 * job left pointed at.
 *
 * That bug returns plausible data from the wrong customer's database and
 * raises no error. "Each job remembers to set its tenant" is not a defence
 * against it - the one that forgets is the one that leaks - so the tenant is
 * stamped onto every payload at dispatch and restored before every job runs.
 *
 * ## Put back afterwards, not blanked
 *
 * The first version of this blanked the tenant after every job, which is
 * right in a worker and wrong everywhere else: under the `sync` driver a job
 * runs INSIDE a request that already has a tenant established, and blanking
 * it left the rest of that request querying a connection with no database.
 * The error names a missing password, which is a long way from the cause.
 *
 * So what was established before the job is remembered and restored - on
 * success, on failure and on an exception alike. Restoring only on success
 * would leave a failed job's tenant pointed at for whatever ran next, which
 * is the same leak arriving by a different route.
 *
 * ## A job with no tenant is a platform job
 *
 * Stamped as null and left alone - provisioning, billing, a platform report.
 * The connection is forgotten first either way, so a platform job cannot
 * inherit a tenant connection and read through it. A job that needed tenant
 * data and arrived without a tenant therefore FAILS rather than reading the
 * wrong database, which is the only acceptable of the two outcomes.
 */
final class QueueTenancyServiceProvider extends ServiceProvider
{
    /** The payload key. Prefixed, because the payload is Laravel's. */
    private const KEY = 'peakflow_tenant';

    /**
     * The correlation id's payload key - PF-014.
     *
     * Carried on the same payload as the tenant, for the same reason: an
     * invoice that posts a journal entry and queues a PDF render is one
     * causal chain, and the question afterwards is "what happened to invoice
     * 4471". A job that generates its own id answers that with a second id
     * nobody can join to the first.
     */
    private const CORRELATION = 'peakflow_correlation_id';

    /**
     * What was established before each job in flight, innermost last.
     *
     * A stack because the `sync` driver runs a job inside its caller, and a
     * job may dispatch another synchronously from inside itself.
     *
     * @var list<\App\Platform\Models\Tenant|null>
     */
    private array $previous = [];

    public function register(): void
    {
        /*
         | Not scoped and not a singleton.
         |
         | It holds no state of its own - the resolver does - and binding it
         | per-resolution means a worker that has forgotten a tenant cannot
         | be handed a stale instance that still believes in one.
         */
        $this->app->bind(TenantContext::class, QueueTenantContext::class);
    }

    public function boot(): void
    {
        $this->stampTheTenantOnEveryPayload();
        $this->restoreTheTenantBeforeEveryJob();
        $this->forgetTheTenantAfterEveryJob();
    }

    /**
     * Write the current tenant onto the payload at dispatch.
     *
     * At dispatch time the tenant is established - that is what makes this
     * reliable where asking the job is not. The closure runs for every
     * queued thing: jobs, queued listeners, mail, notifications, chains.
     */
    private function stampTheTenantOnEveryPayload(): void
    {
        Queue::createPayloadUsing(function (): array {
            try {
                $key = $this->app->make(TenantContext::class)->currentKey();
            } catch (Throwable) {
                /*
                 | Swallowed, and only here.
                 |
                 | This closure runs inside every dispatch, including ones
                 | made from a console command booting before the platform
                 | connection is usable. A dispatch that explodes because it
                 | could not ASK about the tenant would be a worse failure
                 | than a job stamped with none, which is refused later by
                 | the job itself when it needs tenant data.
                 */
                $key = null;
            }

            return [
                self::KEY => $key,
                self::CORRELATION => CorrelationId::current(),
            ];
        });
    }

    /**
     * Establish the payload's tenant, remembering what was there before.
     *
     * The remembering is what makes this safe under the `sync` driver, where
     * a job runs INSIDE a request that already has a tenant established.
     * Blanking it afterwards - which is the obvious implementation - leaves
     * the rest of that request with a connection that has no database, and
     * the error it produces names a password rather than a tenant.
     */
    private function restoreTheTenantBeforeEveryJob(): void
    {
        Queue::before(function (JobProcessing $event): void {
            $resolver = $this->app->make(TenantConnectionResolver::class);

            /*
             | Pushed, not assigned.
             |
             | A job dispatched synchronously from inside another job nests,
             | and a single field would let the inner job's completion
             | restore the outer job's caller instead of the outer job.
             */
            $this->previous[] = $resolver->current();

            /*
             | Forgotten FIRST, unconditionally.
             |
             | Including for a job with no tenant, which is the case this
             | ordering exists for: a platform job must not run on whatever
             | connection the job before it left open. `after` also forgets,
             | so in a healthy worker this is the second of two defences -
             | and it is the one that still holds when a worker was killed
             | mid-job and resumed, or when a job was processed by something
             | that never fired the completion events.
             */
            $resolver->forget();

            /*
             | The id is ADOPTED from the payload, not generated.
             |
             | So the job's log lines carry the id of the request that queued
             | it. A job generating its own would be correct and useless: two
             | ids for one causal chain cannot be joined, which is the one
             | thing a correlation id exists for.
             */
            CorrelationId::adopt($event->job->payload()[self::CORRELATION] ?? null);

            $key = $event->job->payload()[self::KEY] ?? null;

            if (! is_string($key) || $key === '') {
                return;
            }

            /*
             | Resolved, with its servability checks.
             |
             | A job for a suspended or migrating tenant throws here and
             | lands in the failed table, where somebody sees it. The
             | alternative - running it against a database mid-migration -
             | is how a job half-succeeds on a column that is being dropped.
             */
            $resolver->resolve($key);
        });
    }

    /**
     * Put back whatever was established before the job, always.
     *
     * `after`, `failing` and `exceptionOccurred` all run it. Restoring only
     * on success would leave a failed job's tenant pointed at for whatever
     * ran next, which is the same cross-tenant read arriving by a different
     * route.
     */
    private function forgetTheTenantAfterEveryJob(): void
    {
        $restore = function (): void {
            $resolver = $this->app->make(TenantConnectionResolver::class);

            $resolver->forget();

            CorrelationId::forget();

            $previous = array_pop($this->previous);

            /*
             | `point()` rather than `resolve()`.
             |
             | The caller's tenant was servable a moment ago; re-running the
             | checks would mean a tenant suspended during a long job throws
             | from the restore and replaces whatever the job was really
             | failing for.
             */
            if ($previous !== null) {
                $resolver->point($previous);
            }
        };

        Queue::after($restore);
        Queue::failing($restore);
        Queue::exceptionOccurred($restore);
    }
}
