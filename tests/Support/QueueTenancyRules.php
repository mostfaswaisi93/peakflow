<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Events\SideEffect;
use App\Support\Tenancy\TenantContext;
use App\Tenant\Ledger\Listeners\CheckBalancesAfterARevaluation;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The tenant travels with the job - PF-048.
 *
 * ## The failure being guarded against
 *
 * A queue worker is a long-lived process that handles jobs for many tenants
 * in succession, and Laravel caches the opened PDO handle per connection
 * NAME. The name is `tenant` for every one of them.
 *
 * So a job that does not re-point the connection reads whichever tenant's
 * database the PREVIOUS job left open. It returns plausible rows from the
 * wrong customer and raises nothing. It is the worst bug this architecture
 * can have, and it is invisible in every test that runs one job at a time.
 *
 * Which is why the second check here queues TWO jobs and runs the hooks in
 * sequence: one tenant job, then a job with no tenant at all. The bare job
 * must be left with no usable connection. When the forgetting was removed
 * from the hooks, that bare job ran happily against the previous job's
 * database and wrote a row into it - measured, not supposed.
 *
 * ## And the mirror of it, which is easy to introduce while fixing the first
 *
 * Blanking the tenant after every job is the obvious implementation and is
 * wrong under the `sync` driver, where a job runs INSIDE a request that
 * already has a tenant. Blanking it leaves the rest of that request querying
 * a connection with no database, and the error names a missing password.
 *
 * So the hooks restore what was there rather than clearing it, and the third
 * check asserts exactly that.
 */
final class QueueTenancyRules
{
    /** A queue of this suite's own, so popping cannot swallow real work. */
    private const QUEUE = 'peakflow-verify';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $results = ['the side-effect listener is shaped for a queue' => $this->listenerIsShapedForAQueue()];

        $tenants = $this->tenants();

        if (count($tenants) < 1) {
            return $results + ['queue tenancy (no tenant provisioned - skipped)' => []];
        }

        if (! $this->redisIsReachable()) {
            return $results + ['queue tenancy (no redis - skipped)' => []];
        }

        $resolver = app(TenantConnectionResolver::class);
        $restoreTo = $resolver->current();

        try {
            return $results + [
                'every payload carries the tenant it was dispatched in' => $this->payloadCarriesTheTenant($tenants),
                'a job with no tenant is left with no connection' => $this->aBareJobInheritsNothing($tenants),
                'the hooks restore the caller rather than blanking it' => $this->theCallerIsRestored($tenants),
                'a failed job does not leave its tenant pointed at' => $this->aFailureRestoresToo($tenants),
            ];
        } catch (Throwable $e) {
            return $results + ['queue tenancy could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 220),
            ]];
        } finally {
            $this->drain();

            $restoreTo === null ? $resolver->forget() : $resolver->point($restoreTo);
        }
    }

    /**
     * The one queued listener's own shape, read rather than assumed.
     *
     * The architecture rule asserts the SideEffect/ShouldQueue pairing across
     * every listener. This asserts the things that pairing does not cover and
     * that a reviewer would otherwise have to remember: that it answers why
     * it may be late, and that it is not sharing a queue with the mail.
     *
     * @return list<string>
     */
    public function listenerIsShapedForAQueue(): array
    {
        $violations = [];
        $listener = new \ReflectionClass(CheckBalancesAfterARevaluation::class);

        if (! $listener->implementsInterface(SideEffect::class)) {
            $violations[] = 'the balance check is not marked SideEffect';
        }

        $defaults = $listener->getDefaultProperties();
        $queue = $defaults['queue'] ?? null;

        if (! is_string($queue) || $queue === '' || $queue === 'default') {
            $violations[] = sprintf(
                'the balance check runs on the %s queue. A comparison over every balance is slow, and '.
                'sharing a queue with the notifications means a large tenant\'s verification delays every '.
                'small tenant\'s mail behind it',
                $queue === null ? 'default' : '"'.(string) $queue.'"',
            );
        }

        $why = $listener->newInstanceWithoutConstructor()->whyDeferrable();

        if (mb_strlen(trim($why)) < 60) {
            $violations[] = 'the balance check does not say in words why it may be late. The sentence is '.
                'the decision: whoever cannot write it has a posting, not a side effect';
        }

        return $violations;
    }

    /**
     * @param  list<Tenant>  $tenants
     * @return list<string>
     */
    public function payloadCarriesTheTenant(array $tenants): array
    {
        $violations = [];
        $resolver = app(TenantConnectionResolver::class);

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            $payload = $this->dispatchAndRead();

            if (! array_key_exists('peakflow_tenant', $payload)) {
                $violations[] = sprintf(
                    'a job dispatched in %s carries no tenant key at all, so a worker has nothing to '.
                    'point the connection at and will use whatever the job before it left open',
                    (string) $tenant->slug,
                );

                continue;
            }

            if ($payload['peakflow_tenant'] !== (string) $tenant->slug) {
                $violations[] = sprintf(
                    'a job dispatched in %s is stamped %s',
                    (string) $tenant->slug,
                    var_export($payload['peakflow_tenant'], true),
                );
            }
        }

        /*
         | And a dispatch with NO tenant is stamped null, not omitted.
         |
         | The distinction matters at the other end: a missing key and a null
         | one are the same to the worker, but a stamp that is sometimes
         | absent means the hook cannot tell "this is a platform job" from
         | "something went wrong while stamping".
         */
        $resolver->forget();

        $platform = $this->dispatchAndRead();

        if (! array_key_exists('peakflow_tenant', $platform)) {
            $violations[] = 'a platform job carries no tenant key, where it should carry an explicit null';
        } elseif ($platform['peakflow_tenant'] !== null) {
            $violations[] = sprintf(
                'a job dispatched outside any tenant is stamped %s. It would run against that tenant\'s '.
                'database, which nobody asked it to touch',
                var_export($platform['peakflow_tenant'], true),
            );
        }

        return $violations;
    }

    /**
     * Two jobs in sequence: a tenant's, then one with no tenant.
     *
     * The bare job must be left with nothing. This is the cross-tenant read,
     * and running one job at a time cannot see it.
     *
     * @param  list<Tenant>  $tenants
     * @return list<string>
     */
    public function aBareJobInheritsNothing(array $tenants): array
    {
        $resolver = app(TenantConnectionResolver::class);
        $tenant = $tenants[0];

        $resolver->point($tenant);
        $stamped = $this->dispatchAndPop();

        $resolver->forget();
        $bare = $this->dispatchAndPop();

        // The first job runs and completes, exactly as a worker would run it.
        event(new JobProcessing('redis', $stamped));
        $during = app(TenantContext::class)->currentKey();
        event(new JobProcessed('redis', $stamped));

        // And now the bare one, on the same process, with that connection warm.
        event(new JobProcessing('redis', $bare));
        $inherited = app(TenantContext::class)->currentKey();
        $database = (string) (config('database.connections.tenant.database') ?? '');
        event(new JobProcessed('redis', $bare));

        $violations = [];

        if ($during !== (string) $tenant->slug) {
            $violations[] = sprintf(
                'a stamped job for %s ran with %s established, so the stamp is not being read',
                (string) $tenant->slug,
                var_export($during, true),
            );
        }

        if ($inherited !== null) {
            $violations[] = sprintf(
                'a job carrying no tenant ran with %s established. It inherited the previous job\'s '.
                'connection, which is a cross-tenant read: it returns plausible rows from the wrong '.
                'customer\'s database and raises nothing',
                var_export($inherited, true),
            );
        }

        if ($database !== '') {
            $violations[] = sprintf(
                'a job carrying no tenant was left pointed at the database "%s". Failing is the only '.
                'acceptable outcome here; reading the wrong books is not',
                $database,
            );
        }

        return $violations;
    }

    /**
     * A job inside a request must leave that request as it found it.
     *
     * @param  list<Tenant>  $tenants
     * @return list<string>
     */
    public function theCallerIsRestored(array $tenants): array
    {
        $resolver = app(TenantConnectionResolver::class);
        $caller = $tenants[0];
        $other = $tenants[1] ?? $tenants[0];

        $resolver->point($caller);

        // A job for another tenant, dispatched from inside this one - which is
        // what an operator action and a sync driver both look like.
        $resolver->point($other);
        $job = $this->dispatchAndPop();
        $resolver->point($caller);

        event(new JobProcessing('redis', $job));
        event(new JobProcessed('redis', $job));

        $violations = [];

        if (app(TenantContext::class)->currentKey() !== (string) $caller->slug) {
            $violations[] = sprintf(
                'a job for %s left %s established instead of putting %s back. Under the sync driver that '.
                'is the rest of a request querying the wrong database, or none',
                (string) $other->slug,
                var_export(app(TenantContext::class)->currentKey(), true),
                (string) $caller->slug,
            );

            return $violations;
        }

        /*
         | And the connection has to WORK, not merely be named.
         |
         | `forget()` nulls the database and leaves the binding in place, so
         | a check that only reads the container passes while every query in
         | the rest of the request fails. One real query settles it.
         */
        try {
            DB::connection('tenant')->selectOne('SELECT 1 AS ok');
        } catch (Throwable $e) {
            $violations[] = sprintf(
                'the caller\'s tenant is named again but its connection does not work: %s',
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 120),
            );
        }

        return $violations;
    }

    /**
     * A job that threw must not leave its tenant behind either.
     *
     * @param  list<Tenant>  $tenants
     * @return list<string>
     */
    public function aFailureRestoresToo(array $tenants): array
    {
        $resolver = app(TenantConnectionResolver::class);
        $tenant = $tenants[0];

        $resolver->point($tenant);
        $job = $this->dispatchAndPop();
        $resolver->forget();

        event(new JobProcessing('redis', $job));
        event(new JobExceptionOccurred('redis', $job, new RuntimeException('the job threw')));

        if (app(TenantContext::class)->currentKey() !== null) {
            return [sprintf(
                'a job that threw left %s established. The next job on the worker inherits it, and a '.
                'failure is a more likely moment for that than a success',
                var_export(app(TenantContext::class)->currentKey(), true),
            )];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function dispatchAndRead(): array
    {
        /*
         | Drained first, every time.
         |
         | Redis is FIFO and this suite dispatches repeatedly, so without
         | this a pop returns an EARLIER check's job. It did: the bare-job
         | check read a payload stamped by the check before it and reported
         | a cross-tenant read that was not happening. A probe that lies in
         | the alarming direction is still a probe that lies.
         */
        $this->drain();

        dispatch(static function (): void {})->onQueue(self::QUEUE);

        $raw = Redis::connection()->lindex('queues:'.self::QUEUE, -1);

        return json_decode((string) $raw, true) ?: [];
    }

    private function dispatchAndPop(): Job
    {
        $this->drain();

        dispatch(static function (): void {})->onQueue(self::QUEUE);

        $job = Queue::connection('redis')->pop(self::QUEUE);

        if (! $job instanceof Job) {
            throw new RuntimeException('the job just dispatched could not be popped back');
        }

        return $job;
    }

    private function drain(): void
    {
        try {
            Redis::connection()->del(
                'queues:'.self::QUEUE,
                'queues:'.self::QUEUE.':reserved',
                'queues:'.self::QUEUE.':delayed',
            );
        } catch (Throwable) {
            // Nothing to drain, or no Redis. Either way there is nothing to do.
        }
    }

    private function redisIsReachable(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<Tenant> */
    private function tenants(): array
    {
        try {
            return Tenant::query()->with('connection')->whereHas('connection')
                ->orderBy('slug')->get()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
