<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Enums\PlanFeature;
use App\Platform\Exceptions\PlanDoesNotInclude;
use App\Platform\Models\Plan;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
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
