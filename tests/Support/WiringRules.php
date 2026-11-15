<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Enums\PlanFeature;
use App\Platform\Exceptions\PlanDoesNotInclude;
use App\Platform\Models\Plan;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Http\Controllers\LoginController;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Session\Middleware\StartSession;
use ReflectionClass;

/**
 * Checks that the application is WIRED correctly, not just that the classes
 * exist.
 *
 * These need a booted application, which is what separates them from
 * {@see ArchitectureRules} - that one reads source files, this one inspects
 * the thing that was actually assembled.
 *
 * Run by bin/verify and by a PHPUnit wrapper, like the other rule sets.
 *
 * @return list<string> from each method: violations, empty means pass
 */
final class WiringRules
{
    public function __construct(
        private readonly Application $app,
    ) {}

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'tenant resolvers run before the auth guard' => $this->resolversBeforeAuth(),
            'tenant resolvers run after the session starts' => $this->resolversAfterSession(),
            'the entity scope runs after the auth guard' => $this->entityScopeAfterAuth(),
            'the tenant connection is a template with no credentials' => $this->tenantConnectionIsTemplate(),
            'the application does not connect as the migrator' => $this->applicationIsNotMigrator(),
            'problem types have unique URIs and sane statuses' => $this->problemTypesAreWellFormed(),
            'exchange and pdf default to their stubs' => $this->driversDefaultToStubs(),
            'the plan gate refuses with an upgrade path, not a 403' => $this->planGateOffersAnUpgrade(),
            'every reporting command actually runs' => $this->reportingCommandsRun(),
            'the login form is not an account oracle' => $this->loginIsNotAnOracle(),
            'every operator route is guarded' => $this->operatorRoutesAreGuarded(),
            'no operator route can reach a tenant connection' => $this->operatorRoutesAvoidTenants(),
        ];
    }

    /**
     * The one that matters most, and the one that was ALREADY WRONG ONCE.
     *
     * `prependToPriorityList(before: ...)` silently APPENDS when its target is
     * not in the list. Laravel's priority list holds the AuthenticatesRequests
     * CONTRACT, so naming the concrete Authenticate class matches nothing and
     * the resolvers end up after the guard - which is the exact failure the
     * pinning exists to prevent.
     *
     * So this asserts the resulting POSITIONS rather than asserting that the
     * pinning code was called. A test that checks the call and not the outcome
     * would have passed while the application was broken.
     *
     * @return list<string>
     */
    /**
     * The mirror of resolversBeforeAuth, and wrong in the opposite direction.
     *
     * Which entity a user may act in is read from their roles, so the guard
     * must have run first. Pinned with `appendToPriorityList`, which has the
     * same silent-fallback behaviour as its sibling if the anchor is not in
     * the list - so again this asserts the position, not the call.
     *
     * @return list<string>
     */
    public function entityScopeAfterAuth(): array
    {
        $priority = $this->priorityList();
        $authIndex = $this->indexOf($priority, AuthenticatesRequests::class);

        if ($authIndex === null) {
            return ['the auth guard is not in the middleware priority list at all'];
        }

        $index = $this->indexOf($priority, \App\Http\Middleware\ResolveEntity::class);

        if ($index === null) {
            return ['ResolveEntity is not in the middleware priority list; it would run in route order'];
        }

        if ($index < $authIndex) {
            return [sprintf(
                'ResolveEntity is at position %d, BEFORE the auth guard at %d. It reads the user\'s roles to '.
                'decide which entities they may act in, so there would be no user to read them from.',
                $index,
                $authIndex,
            )];
        }

        return [];
    }

    /**
     * A plan refusal must be answerable by the customer.
     *
     * "Forbidden" is what you say to someone attempting an intrusion. A
     * customer on the starter plan who reached a consolidation screen is a
     * person who would like to buy something and cannot work out how, so the
     * refusal is a 402 that names the feature and the cheapest plan including
     * it - computed from the catalogue, because a hardcoded "upgrade to
     * Business" goes stale the first time packaging changes and still reads
     * plausibly.
     *
     * @return list<string>
     */
    public function planGateOffersAnUpgrade(): array
    {
        $plans = Plan::query()->where('active', true)->orderBy('sort_order')->get();

        if ($plans->count() < 2) {
            return ['fewer than two active plans are seeded, so gating cannot be checked'];
        }

        $cheapest = $plans->first();
        $violations = [];

        // A feature the cheapest plan does not have, but some plan does.
        $gated = collect(PlanFeature::cases())
            ->first(fn (PlanFeature $f): bool => ! $cheapest->has($f)
                && $plans->contains(fn (Plan $p): bool => $p->has($f)));

        if ($gated === null) {
            return ['the cheapest plan includes every feature any plan does, so there is nothing to gate'];
        }

        $exception = PlanDoesNotInclude::feature($gated, $cheapest);
        $response = $exception->render(Request::create('/'));

        if ($response->getStatusCode() !== 402) {
            $violations[] = sprintf(
                'a plan refusal answered %d; it must be 402 Payment Required, because 403 tells a paying '.
                'customer they are suspected of something',
                $response->getStatusCode(),
            );
        }

        if (str_contains(strtolower($exception->getMessage()), 'forbidden')) {
            $violations[] = 'a plan refusal says "forbidden" to a customer who simply has not bought the feature';
        }

        if ($exception->cheapestPlanWithIt === null) {
            $violations[] = sprintf(
                'the refusal for "%s" names no plan that includes it, although one exists, so the customer is '.
                'told no and not told how',
                $gated->value,
            );
        }

        if ($exception->cheapestPlanWithIt === $cheapest->key) {
            $violations[] = 'the refusal points at the plan the customer is already on';
        }

        return $violations;
    }

    /**
     * Run each read-only command and assert it does not blow up.
     *
     * Added because a refactor removed a method that only the exceptions
     * report called, and every check still passed - nothing in the suite had
     * ever executed that command. A report that throws is worse than a report
     * that is wrong, because it is the thing somebody reaches for during an
     * incident.
     *
     * The exit code is NOT asserted: `mfa:status` returns 1 when somebody is
     * missing a factor and `audit:verify` returns 1 on a broken chain, and
     * both are correct answers. What is asserted is that the command reaches
     * an answer at all.
     *
     * @return list<string>
     */
    public function reportingCommandsRun(): array
    {
        $tenant = Tenant::query()->whereHas('connection')->orderBy('slug')->first();

        if ($tenant === null) {
            return [];
        }

        $commands = [
            'subscriptions:expire' => ['--pretend' => true],
            'audit:verify' => ['--all' => true],
            'sod:exceptions' => ['tenant' => $tenant->slug],
            'mfa:status' => ['tenant' => $tenant->slug],
        ];

        $violations = [];

        foreach ($commands as $command => $arguments) {
            try {
                Artisan::call($command, $arguments, new NullOutput());
            } catch (Throwable $e) {
                $violations[] = sprintf(
                    '`%s` threw %s: %s',
                    $command,
                    class_basename($e),
                    Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 140),
                );
            }
        }

        return $violations;
    }

    /**
     * Every way of failing to sign in must look the same from outside.
     *
     * Wrong password, no such user, unknown workspace, no workspace given:
     * one message, one exception type, therefore one status code.
     * Distinguishing them turns the form into an oracle answering "does this
     * company bank with you" to anyone who types a name - a disclosure about
     * the customer before it is a step towards an attack.
     *
     * The unknown-workspace path is the one this check exists for. It threw a
     * database error rather than a validation failure, because the refusal
     * was recorded in a tenant audit log that does not exist when no tenant
     * resolved. The message was identical and the STATUS CODE was not: 302
     * for a wrong password, 500 for a workspace that is not a customer.
     *
     * @return list<string>
     */
    public function loginIsNotAnOracle(): array
    {
        $tenant = Tenant::query()->whereHas('connection')->orderBy('slug')->first();

        if ($tenant === null) {
            return [];
        }

        $attempts = [
            'wrong password' => [$tenant->slug, 'definitely-not-a-user@invalid.test', 'wrong'],
            'unknown user' => [$tenant->slug, 'also-not-a-user@invalid.test', 'wrong'],
            'unknown workspace' => ['no-such-workspace-'.bin2hex(random_bytes(4)), 'someone@invalid.test', 'wrong'],
            'no workspace at all' => ['', 'someone@invalid.test', 'wrong'],
        ];

        $violations = [];
        $messages = [];

        /*
         | A fresh address from TEST-NET-1 (RFC 5737, reserved for
         | documentation and guaranteed never to be routed) on every run.
         |
         | Without this the check throttles ITSELF: four attempts per run
         | against a fixed address exhausts the per-address budget after a few
         | runs, and every refusal then reads "wait N seconds". That does not
         | just produce a false failure - it produced a false PASS first,
         | because four identical throttle messages satisfy a check looking
         | for four identical messages. A check whose own side effects change
         | what it measures is worse than no check.
         */
        $probeIp = sprintf('192.0.2.%d', random_int(1, 254));

        foreach ($attempts as $label => [$workspace, $email, $password]) {
            /*
             | Each attempt starts from NO tenant, because each sign-in is a
             | separate request with a fresh container.
             |
             | Resetting once before the loop is not enough and hid the bug
             | twice over: the first attempt uses a real workspace, which
             | re-binds `currentTenant` and re-points the connections, so
             | every later attempt saw a tenant and never reached the path
             | being tested. The check passed against code that answered 500
             | to an unknown workspace.
             |
             | The audit connection is cleared too. Left configured, a
             | tenant-less refusal writes into whichever tenant it still
             | points at and returns perfectly cleanly - which in a
             | long-running worker is the real shape of this failure: not an
             | error, but one tenant's audit log quietly receiving another
             | request's refusal.
             */
            app(TenantConnectionResolver::class)->forget();
            config(['database.connections.tenant_audit' => null]);
            DB::purge('tenant_audit');

            $request = Request::create(
                '/login',
                'POST',
                ['workspace' => $workspace, 'email' => $email, 'password' => $password],
                server: ['REMOTE_ADDR' => $probeIp],
            );
            $request->setLaravelSession(app('session')->driver('array'));

            try {
                app(LoginController::class)->store($request);

                $violations[] = sprintf('"%s" was ACCEPTED', $label);
            } catch (ValidationException $e) {
                $messages[$label] = implode(' ', $e->errors()['email'] ?? ['(no message)']);
            } catch (Throwable $e) {
                // The actual bug: a refusal that fails differently is a
                // refusal that tells the caller something.
                $violations[] = sprintf(
                    '"%s" threw %s instead of refusing cleanly, so it answers with a different status code '.
                    'than the other refusals: %s',
                    $label,
                    class_basename($e),
                    Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 120),
                );
            }
        }

        // Give back what the probe spent, so a run leaves the limiter as it
        // found it and a real user sharing the address is unaffected.
        foreach (array_keys($attempts) as $label) {
            [, $email] = $attempts[$label];
            RateLimiter::clear('login:account:'.hash('sha256', Str::lower(trim((string) $email))));
        }

        RateLimiter::clear('login:ip:'.hash('sha256', $probeIp));

        $distinct = array_unique($messages);

        if (count($distinct) > 1) {
            $violations[] = 'the refusals do not read alike: '.json_encode(
                $messages,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        }

        return $violations;
    }

    /**
     * Every route under `ops.` is behind `auth:ops` - PF-036.
     *
     * Checked by enumerating the router rather than by reading the routes
     * file, because the question is what the application will actually serve.
     * A route added outside the guarded group still appears in the file next
     * to guarded ones and looks right.
     *
     * The sign-in pages are the only exception, and they must carry
     * `guest:ops` so an authenticated operator cannot be walked back through
     * a login form.
     *
     * @return list<string>
     */
    public function operatorRoutesAreGuarded(): array
    {
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'ops.')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $isSignIn = in_array($name, ['ops.login', 'ops.login.store'], true);

            if ($isSignIn) {
                if (! in_array('guest:ops', $middleware, true)) {
                    $violations[] = sprintf('%s is not behind guest:ops', $name);
                }

                continue;
            }

            if (! in_array('auth:ops', $middleware, true)) {
                $violations[] = sprintf(
                    '%s (%s) is not behind auth:ops - an unguarded operator page is not a smaller version of '.
                    'this problem, it is the whole of it',
                    $name,
                    $route->uri(),
                );
            }

            // And never the tenant guard: an operator authenticated against a
            // customer's user table is the failure the two planes exist to
            // make impossible.
            if (in_array('auth', $middleware, true) || in_array('auth:web', $middleware, true)) {
                $violations[] = sprintf('%s uses the TENANT auth guard', $name);
            }
        }

        return $violations;
    }

    /**
     * No operator page may resolve a tenant connection.
     *
     * An operator who can read a customer's data from the console reads it
     * UNATTRIBUTABLY: nothing in that tenant's own audit log says they did,
     * because the tenant's audit log is only written by things running inside
     * the tenant. Reading requires impersonation, which requires the tenant's
     * recorded prior consent and writes an event in both planes.
     *
     * So the tenant resolvers must not appear on these routes at all. The
     * inconvenience is the control.
     *
     * @return list<string>
     */
    public function operatorRoutesAvoidTenants(): array
    {
        $violations = [];
        $tenantMiddleware = ['tenant.session', 'tenant.header', 'entity.scope'];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'ops.')) {
                continue;
            }

            foreach (array_intersect($tenantMiddleware, $route->gatherMiddleware()) as $found) {
                $violations[] = sprintf(
                    '%s carries %s, so an operator could read a customer\'s data from the console without '.
                    'impersonation - which means without it appearing in that customer\'s audit log',
                    (string) $route->getName(),
                    $found,
                );
            }
        }

        return $violations;
    }

    public function resolversBeforeAuth(): array
    {
        $priority = $this->priorityList();
        $authIndex = $this->indexOf($priority, AuthenticatesRequests::class);

        if ($authIndex === null) {
            return ['the auth guard is not in the middleware priority list at all'];
        }

        $violations = [];

        foreach ($this->resolverClasses() as $resolver) {
            $index = $this->indexOf($priority, $resolver);

            if ($index === null) {
                $violations[] = sprintf('%s is not in the middleware priority list', $resolver);

                continue;
            }

            if ($index > $authIndex) {
                $violations[] = sprintf(
                    '%s is at position %d, AFTER the auth guard at %d. The users table lives in the '.
                    'tenant database, so the guard would query the wrong connection.',
                    $resolver,
                    $index,
                    $authIndex,
                );
            }
        }

        return $violations;
    }

    /**
     * The session has to exist before the session resolver can read it.
     *
     * This is the other side of the same constraint, and the two together pin
     * the resolvers into exactly one valid window.
     *
     * @return list<string>
     */
    public function resolversAfterSession(): array
    {
        $priority = $this->priorityList();
        $sessionIndex = $this->indexOf($priority, StartSession::class);

        if ($sessionIndex === null) {
            return [];
        }

        $resolver = 'App\\Http\\Middleware\\ResolveTenantFromSession';
        $index = $this->indexOf($priority, $resolver);

        if ($index !== null && $index < $sessionIndex) {
            return [sprintf(
                '%s is at position %d, BEFORE StartSession at %d, so there is no session to read a tenant from.',
                $resolver,
                $index,
                $sessionIndex,
            )];
        }

        return [];
    }

    /**
     * The `tenant` connection in config is a TEMPLATE, rewritten per request.
     *
     * Real credentials sitting on it would mean a request that failed to
     * resolve a tenant still had a usable connection to somebody's database.
     *
     * @return list<string>
     */
    public function tenantConnectionIsTemplate(): array
    {
        $config = (array) config('database.connections.tenant', []);
        $violations = [];

        foreach (['database', 'username', 'password'] as $key) {
            if (($config[$key] ?? null) !== null) {
                $violations[] = sprintf(
                    'database.connections.tenant.%s is set in config. It must be null: the connection is '.
                    'rewritten per request by TenantConnectionResolver.',
                    $key,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function applicationIsNotMigrator(): array
    {
        $app = (string) (config('database.connections.platform.username') ?? '');
        $migrator = (string) (config('database.connections.migrator.username') ?? '');

        if ($app !== '' && $app === $migrator) {
            return [sprintf(
                'The application connects as "%s", which is also the migrator. The application must not be '.
                'able to ALTER the schema or DELETE posted history - see PF-010 and PF-233.',
                $app,
            )];
        }

        return [];
    }

    /** @return list<string> */
    public function problemTypesAreWellFormed(): array
    {
        $violations = [];
        $seen = [];

        foreach (\App\Support\Http\ProblemType::cases() as $type) {
            if (isset($seen[$type->uri()])) {
                $violations[] = sprintf('two problem types share the URI %s', $type->uri());
            }

            $seen[$type->uri()] = true;

            if ($type->status() < 400 || $type->status() > 599) {
                $violations[] = sprintf(
                    '%s has status %d; a problem detail must carry a 4xx or 5xx',
                    $type->value,
                    $type->status(),
                );
            }

            if (trim($type->title()) === '') {
                $violations[] = sprintf('%s has no title', $type->value);
            }
        }

        return $violations;
    }

    /**
     * A fresh clone must work with no third-party credentials.
     *
     * @return list<string>
     */
    public function driversDefaultToStubs(): array
    {
        $violations = [];

        if (config('exchange.driver') !== 'log') {
            $violations[] = 'exchange.driver is not "log". The committed default must need no partner.';
        }

        if (config('pdf.driver') !== 'stub') {
            $violations[] = 'pdf.driver is not "stub". The committed default must need no PDF service.';
        }

        return $violations;
    }

    /** @return list<string> */
    private function resolverClasses(): array
    {
        return [
            'App\\Http\\Middleware\\ResolveTenantFromSession',
            'App\\Http\\Middleware\\ResolveTenantFromHeader',
        ];
    }

    /** @return list<string> */
    private function priorityList(): array
    {
        $kernel = $this->app->make(HttpKernel::class);

        $property = (new ReflectionClass($kernel))->getProperty('middlewarePriority');
        $property->setAccessible(true);

        /** @var list<string> $list */
        $list = $property->getValue($kernel);

        return $list;
    }

    /** @param list<string> $list */
    private function indexOf(array $list, string $class): ?int
    {
        $index = array_search($class, $list, true);

        return $index === false ? null : (int) $index;
    }
}
