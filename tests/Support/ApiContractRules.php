<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Http\ProblemType;
use App\Tenant\Identity\Exceptions\TokenRefused;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\Permission;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\ApiScopes;
use App\Tenant\Identity\Services\ApiTokenService;
use App\Tenant\Identity\Services\PermissionCatalogue;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What the API promises, and what it refuses - PF-217 to PF-222.
 *
 * ## The one failure this file exists for
 *
 * A money amount leaving this system as a JSON number.
 *
 * It is not a hypothetical. `JOD 1.234` serialised as `1.234` arrives in
 * JavaScript as the nearest double - 1.2339999999999999857891452847979962825775146484375 -
 * and every client that formats it with two decimals, which is every client,
 * writes 1.23 on an invoice for a three-decimal currency. The lines then no
 * longer sum to the total, nothing errors, and the difference is a third of a
 * fils per line: too small to investigate and too consistent to be noise.
 *
 * So the last check in this file walks every byte of every response the suite
 * has made and refuses a decimal number anywhere in any of them. It is the
 * check that justifies the rest: the others exist so that this one has real
 * responses to walk.
 *
 * ## Why the contract is read rather than generated
 *
 * `docs/api/openapi.yaml` is hand-authored and committed, and the agreement is
 * asserted in BOTH directions: an operation in the document with no route
 * fails, and a route with no operation fails. An API whose documentation is
 * generated from its implementation documents whatever the implementation
 * happens to do, including its mistakes - and can never disagree with the
 * code, which is the only way a document is useful.
 *
 * There is no YAML library in this project, for the same reason `bin/verify`
 * exists, so this file carries a reader of its own. It REFUSES syntax it does
 * not understand rather than skipping it: a lenient reader would turn a
 * document the check cannot parse into a document the check silently approves.
 *
 * ## Why it commits its fixture and tears it down afterwards
 *
 * It drives real HTTP requests through the kernel, and the first thing the
 * tenant middleware does is purge the tenant connection - which abandons any
 * transaction this suite was holding and takes its fixture with it. The same
 * mechanical reason `PublicQuoteRules` and `ConcurrencyRules` commit.
 *
 * ## And why it saves the default guard
 *
 * `auth:sanctum` calls `shouldUse('sanctum')` when it authenticates, which
 * changes the application's DEFAULT guard for the rest of the process. In a
 * real deployment that is one request in one process; in `bin/verify` it is
 * every suite that runs after this one, reading `auth()->user()` and getting a
 * machine. Saved and restored in the `finally`, for the same reason
 * `ErrorResponseRules` forgets every guard's user before it asks what an
 * unauthenticated request gets: a check whose result depends on which checks
 * ran before it is not a check.
 */
final class ApiContractRules
{
    use Concerns\ProvesTheLedger;

    /** The parsed contract. */
    private array $spec = [];

    private string $tenantId = '';

    private string $entityId = '';

    private string $otherEntityId = '';

    private ?Entity $entity = null;

    private ?User $user = null;

    /**
     * A second user holding a role that grants nothing.
     *
     * For the one state the token service correctly refuses to create: a
     * live token whose owner's permission has since been revoked. It happens
     * to every long-lived credential the first time somebody changes teams,
     * and the token is still a valid credential at that moment - so the
     * middleware that answers it needs exercising.
     */
    private ?User $stranger = null;

    private string $roleId = '';

    private string $emptyRoleId = '';

    /** Bearer strings, keyed by the role each token plays in the checks. */
    private array $tokens = [];

    private string $postingDate = '';

    /** @var array<string, string> account code => id */
    private array $accounts = [];

    private string $controlAccountCode = '';

    /**
     * Every response this suite has produced: [method, uri, status, body].
     *
     * Collected so that the money and date checks can walk ALL of them rather
     * than a sample somebody remembered to include. A check that inspects the
     * one response its author was thinking about is how a float reaches
     * production through the endpoint nobody listed.
     *
     * @var list<array{method: string, uri: string, status: int, body: string, operation: string}>
     */
    private array $seen = [];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->with('connection')->whereNotNull('provisioned_at')
            ->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the API contract (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->tenantId = (string) $tenant->getKey();

        $guard = (string) config('auth.defaults.guard');

        /*
         | And the locale, for the same reason as the guard.
         |
         | `NegotiateApiLanguage` sets the application locale from each
         | request's `Accept-Language`, and nothing puts it back - so the last
         | request this suite makes decides what language every suite after it
         | renders in. A check whose result depends on which checks ran before
         | it is not a check, and this one would hand that problem to somebody
         | else's.
         */
        $locale = app()->getLocale();

        try {
            $this->spec = MinimalYaml::parse((string) file_get_contents($this->specPath()));
        } catch (Throwable $e) {
            // Returned as a failure rather than thrown: a document this
            // reader cannot parse is the one state in which every other
            // check here would report nothing and look green.
            return ['the contract could not be read' => [$e->getMessage()]];
        }

        try {
            $this->seed();

            $checks = $this->runEach([
                // PF-217 - the contract.
                'the contract parses with nothing skipped, and every reference resolves' => fn (): array => $this->theContractIsWhole(),
                'every documented operation is a route on the API surface' => fn (): array => $this->everyOperationIsARoute(),
                'no undocumented route exists on the API surface' => fn (): array => $this->noUndocumentedRoute(),
                'every operation describes its error shapes as problem documents' => fn (): array => $this->everyErrorShapeIsDescribed(),
                'every operation carries an example, and every example matches its own schema' => fn (): array => $this->examplesMatchTheirSchemas(),
                'every schema in the contract is used by something' => fn (): array => $this->noDeadSchemas(),
                'the contract is published at the path it documents' => fn (): array => $this->theContractIsPublished(),

                // PF-218 - authentication and scopes.
                'no operation answers without a token' => fn (): array => $this->nothingWorksWithoutAToken(),
                'every scope named on a route is a permission key' => fn (): array => $this->scopesAreePermissions(),
                'a token can carry neither an unknown scope nor one its owner lacks' => fn (): array => $this->aTokenCannotExceedItsOwner(),
                'a token may not mint a token wider than itself' => fn (): array => $this->delegationOnlyNarrows(),
                'a missing scope and a missing permission are different refusals' => fn (): array => $this->twoRefusalsNotOne(),
                'a token is refused in an entity it does not name' => fn (): array => $this->entityScopeIsEnforced(),
                'a token from another plane, and a revoked one, authenticate nothing' => fn (): array => $this->theWrongPlaneAndTheDead(),
                'a live token is never widened in place' => fn (): array => $this->aTokenIsNeverWidened(),
                'issuing, rotating and revoking are audited and visible' => fn (): array => $this->theLifecycleIsVisible(),
                'the gate narrows a machine to its token, not to its user' => fn (): array => $this->theGateNarrows(),

                // PF-219 - problem details.
                'every refusal is a problem document with all five members' => fn (): array => $this->everyRefusalIsAProblem(),
                'the registry documents every problem type with its status' => fn (): array => $this->theRegistryIsComplete(),
                'detail follows Accept-Language, and type and title never do' => fn (): array => $this->onlyTheHumanHalfIsLocalized(),
                'a line problem is addressed by pointer, with its own message' => fn (): array => $this->errorsAreAddressed(),

                // PF-220 - idempotency.
                'a ledger write without an Idempotency-Key is refused' => fn (): array => $this->theKeyIsRequired(),
                'a replay returns the stored response, and a different body is a conflict' => fn (): array => $this->replayAndConflict(),
                'two concurrent posts make one entry and two identical responses' => fn (): array => $this->twoAtOnce(),
                'every route that posts to the ledger requires a key' => fn (): array => $this->everyPostingRouteIsIdempotent(),

                // PF-221 - pagination.
                'a page is cursor-based, seeks, and carries RFC 8288 links' => fn (): array => $this->theCursorSeeks(),
                'a cursor that was altered, or came from another listing, is refused' => fn (): array => $this->aTamperedCursor(),
                'a page size outside the allowlist is refused, naming the allowlist' => fn (): array => $this->thePageSizeAllowlist(),
                'offset pagination is available, capped, and says which mode answered' => fn (): array => $this->offsetIsTheSlowerPath(),

                // PF-222 - money and dates.
                'a live response matches the schema the contract documents for it' => fn (): array => $this->liveResponsesMatchTheContract(),
                'no response anywhere carries a bare numeric money value' => fn (): array => $this->noBareNumbers(),
            ]);

            /*
             | The global proof needs an entity established.
             |
             | Every request above established one and then cleared it in a
             | `finally`, which is right - a pooled connection must not carry
             | one request's entity into the next. The proof is not a request,
             | so it sets the scope itself; without it the verifier's own
             | queries are refused for having no entity, and the suite
             | reports that as a broken ledger.
             */
            app(EntityContext::class)->use($this->entityId);

            return $this->withTheLedgerProof($checks, $this->entityId);
        } catch (Throwable $e) {
            return ['the API contract could not be checked' => [
                $e::class.': '.Str::limit((string) preg_replace('/\s+/', ' ', $e->getMessage()), 300),
            ]];
        } finally {
            config(['auth.defaults.guard' => $guard]);
            app()->setLocale($locale);
            $this->forgetEverySignedInUser();
            $this->teardown();
        }
    }

    /**
     * Run each check, keeping an unexpected throw to that check.
     *
     * A single try around everything turns one unexpected throw into one
     * opaque failure and reports nothing about the other thirty - which is
     * exactly what happened while proving these bite, when a deliberately
     * broken cursor signature took the whole suite with it.
     *
     * No savepoints here, unlike the suites that run inside a transaction:
     * this one commits, so a failed statement aborts nothing but itself.
     *
     * @param  array<string, callable(): list<string>>  $checks
     * @return array<string, list<string>>
     */
    private function runEach(array $checks): array
    {
        $results = [];

        foreach ($checks as $name => $check) {
            try {
                $results[$name] = $check();
            } catch (Throwable $e) {
                $results[$name] = [sprintf(
                    'the check could not run: %s: %s',
                    $e::class,
                    Str::limit((string) preg_replace('/\s+/', ' ', $e->getMessage()), 300),
                )];
            }
        }

        return $results;
    }

    // =========================================================================
    //  PF-217 - the contract
    // =========================================================================

    /** @return list<string> */
    private function theContractIsWhole(): array
    {
        $violations = [];

        foreach (['openapi', 'info', 'paths', 'components'] as $member) {
            if (! isset($this->spec[$member])) {
                $violations[] = sprintf('the contract has no `%s`', $member);
            }
        }

        if (($this->spec['openapi'] ?? null) !== '3.1.0') {
            $violations[] = sprintf(
                'the contract declares OpenAPI %s. 3.1 is what the task names, and 3.0 differs in how it '.
                'expresses nullability and examples - which is exactly what a client generator needs to '.
                'know before it starts.',
                var_export($this->spec['openapi'] ?? null, true),
            );
        }

        /*
         | Every $ref resolves, anywhere in the document.
         |
         | A dangling reference is not a documentation blemish: a generator
         | reading it produces a client with a missing type, and the symptom
         | is a compile error in somebody else's build with our file's name on
         | it.
         */
        foreach ($this->refsIn($this->spec) as $ref) {
            if ($this->resolve($ref) === null) {
                $violations[] = sprintf('`%s` points at nothing', $ref);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function everyOperationIsARoute(): array
    {
        $violations = [];
        $routes = $this->apiRoutes();

        foreach ($this->operations() as $operation) {
            $key = $operation['method'].' '.$operation['path'];

            if (! isset($routes[$key])) {
                $violations[] = sprintf(
                    '%s (%s) is in the contract and is not a route. A client writes code against this '.
                    'document and would get a 404 - which is worse than an endpoint that is not documented '.
                    'at all, because the 404 looks like an outage.',
                    $key,
                    $operation['id'],
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function noUndocumentedRoute(): array
    {
        $violations = [];
        $documented = [];

        foreach ($this->operations() as $operation) {
            $documented[$operation['method'].' '.$operation['path']] = true;
        }

        foreach (array_keys($this->apiRoutes()) as $route) {
            if (! isset($documented[$route])) {
                $violations[] = sprintf(
                    '%s is a route on the API surface and is in no operation. An endpoint nobody can '.
                    'discover is an endpoint nobody reviewed, and it is still reachable by anybody who '.
                    'guesses it.',
                    $route,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function everyErrorShapeIsDescribed(): array
    {
        $violations = [];

        foreach ($this->operations() as $operation) {
            $responses = $operation['responses'];

            foreach ($responses as $status => $response) {
                if ((int) $status < 400) {
                    continue;
                }

                $resolved = $this->dereference($response);
                $content = $resolved['content'] ?? [];

                if (! isset($content['application/problem+json'])) {
                    $violations[] = sprintf(
                        '%s answers %s with %s rather than application/problem+json. RFC 9457 names its '.
                        'own media type, and a client switching on it cannot tell a problem document from a '.
                        'payload that happens to have a `title`.',
                        $operation['id'],
                        $status,
                        implode(', ', array_keys((array) $content)) ?: '(no content)',
                    );

                    continue;
                }

                $schema = $content['application/problem+json']['schema'] ?? [];

                if (($schema['$ref'] ?? null) !== '#/components/schemas/Problem') {
                    $violations[] = sprintf(
                        '%s describes its %s body with a schema of its own rather than the shared Problem. '.
                        'An error type is a contract; a second shape for the same thing is how a client '.
                        'ends up with two parsers.',
                        $operation['id'],
                        $status,
                    );
                }
            }

            // And every operation says what happens when we fail.
            if (! isset($responses['500'])) {
                $violations[] = sprintf(
                    '%s does not describe a 500. An error that is not in the spec is not an error a client '.
                    'can handle - and this is the one every operation can produce.',
                    $operation['id'],
                );
            }

            $needsAuth = ($operation['security'] ?? null) !== [];

            if ($needsAuth && ! isset($responses['401'])) {
                $violations[] = sprintf(
                    '%s requires a token and does not describe a 401.',
                    $operation['id'],
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function examplesMatchTheirSchemas(): array
    {
        $violations = [];

        foreach ($this->operations() as $operation) {
            /*
             | Counted on the SUCCESS response, not across the operation.
             |
             | The first version of this counted every example anywhere in the
             | operation - and the error responses are `$ref`s to shared ones
             | that carry examples of their own, so deleting an operation's
             | own 200 example left the count at four and the check passed.
             | Found by deleting exactly that, which is what the bite sweep
             | is for.
             */
            $successExamples = 0;

            foreach ($operation['responses'] as $status => $response) {
                $resolved = $this->dereference($response);

                foreach ((array) ($resolved['content'] ?? []) as $type => $media) {
                    /*
                     | A representation that is not JSON is its own example.
                     |
                     | One operation returns the contract document itself, and
                     | an "example" of it would be a second copy of a
                     | thousand-line file that nothing keeps in step. The
                     | suite fetches that one and byte-compares it with the
                     | committed file, which is a stronger fixture than an
                     | example could be.
                     */
                    if ((int) $status < 400 && $type !== 'application/json') {
                        $successExamples++;
                    }

                    foreach ((array) ($media['examples'] ?? []) as $name => $example) {
                        if ((int) $status < 400) {
                            $successExamples++;
                        }

                        if (! \array_key_exists('value', (array) $example)) {
                            $violations[] = sprintf('%s %s example `%s` has no value', $operation['id'], $status, $name);

                            continue;
                        }

                        $violations = [...$violations, ...array_map(
                            static fn (string $problem): string => sprintf(
                                '%s %s example `%s`: %s',
                                $operation['id'],
                                $status,
                                $name,
                                $problem,
                            ),
                            $this->validate($example['value'], (array) ($media['schema'] ?? []), ''),
                        )];
                    }
                }
            }

            if ($successExamples === 0) {
                $violations[] = sprintf(
                    '%s has no example of what it RETURNS. PF-217 asks for examples used as test fixtures '.
                    'precisely so they cannot go stale - an example nothing validates is a paragraph of '.
                    'prose that was true once.',
                    $operation['id'],
                );
            }

            foreach ((array) ($operation['requestBody']['content'] ?? []) as $media) {
                if (($media['examples'] ?? []) === []) {
                    $violations[] = sprintf('%s has a request body and no example of one', $operation['id']);

                    continue;
                }

                foreach ((array) $media['examples'] as $name => $example) {
                    $violations = [...$violations, ...array_map(
                        static fn (string $problem): string => sprintf(
                            '%s request example `%s`: %s',
                            $operation['id'],
                            $name,
                            $problem,
                        ),
                        $this->validate($example['value'] ?? null, (array) ($media['schema'] ?? []), ''),
                    )];
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function noDeadSchemas(): array
    {
        $used = [];

        foreach ($this->refsIn($this->spec) as $ref) {
            $used[$ref] = true;
        }

        $violations = [];

        foreach (array_keys((array) ($this->spec['components']['schemas'] ?? [])) as $name) {
            if (! isset($used['#/components/schemas/'.$name])) {
                $violations[] = sprintf(
                    '`%s` is defined and referenced by nothing. A schema no operation reaches describes a '.
                    'resource this API does not serve, and an integrator who builds against it builds '.
                    'against a plan.',
                    $name,
                );
            }
        }

        foreach (['parameters', 'responses', 'headers'] as $section) {
            foreach (array_keys((array) ($this->spec['components'][$section] ?? [])) as $name) {
                if (! isset($used['#/components/'.$section.'/'.$name])) {
                    $violations[] = sprintf('`components.%s.%s` is referenced by nothing', $section, $name);
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function theContractIsPublished(): array
    {
        $response = $this->request('GET', '/api/v1/openapi.yaml', operation: 'getOpenApiDocument');

        if ($response->getStatusCode() !== 200) {
            return [sprintf(
                'GET /api/v1/openapi.yaml answered %d. PF-217 asks for the spec to be published to tenants '.
                'as the integration reference, and a contract an integrator has to be emailed is the '.
                'version they build against for two years.',
                $response->getStatusCode(),
            )];
        }

        $violations = [];
        $served = (string) $response->getContent();

        if ($served !== (string) file_get_contents($this->specPath())) {
            $violations[] = 'the published document is not the committed one, byte for byte';
        }

        $type = (string) $response->headers->get('content-type');

        if (! str_contains($type, 'application/yaml')) {
            $violations[] = sprintf('the contract is served as %s rather than application/yaml', $type);
        }

        if ($response->headers->get('etag') === null) {
            $violations[] = 'the contract carries no ETag, so a client polling for changes re-downloads it '.
                'every time and learns nothing from having polled';
        }

        return $violations;
    }

    // =========================================================================
    //  PF-218 - authentication and scopes
    // =========================================================================

    /** @return list<string> */
    private function nothingWorksWithoutAToken(): array
    {
        $violations = [];

        foreach ($this->operations() as $operation) {
            if (($operation['security'] ?? null) === []) {
                // The contract itself, documented as unauthenticated.
                continue;
            }

            $response = $this->request(
                $operation['method'],
                $this->concreteUri($operation['path']),
                headers: ['X-Tenant' => $this->tenantId, 'X-Entity' => $this->entityId],
                body: $operation['method'] === 'GET' ? null : [],
                operation: $operation['id'],
            );

            if ($response->getStatusCode() !== 401) {
                $violations[] = sprintf(
                    '%s %s answered %d with no Authorization header and should answer 401.',
                    $operation['method'],
                    $operation['path'],
                    $response->getStatusCode(),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function scopesAreePermissions(): array
    {
        $violations = [];
        $catalogue = app(PermissionCatalogue::class);

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $entry) {
                if (! \is_string($entry) || ! str_starts_with($entry, 'api.scope:')) {
                    continue;
                }

                foreach (explode(',', substr($entry, \strlen('api.scope:'))) as $scope) {
                    if (! $catalogue->has($scope)) {
                        $violations[] = sprintf(
                            '%s requires the scope `%s`, which is not a permission key. A scope that is not '.
                            'a permission is a second authorization model, and this one cannot be satisfied '.
                            'by any token at all - the route is unreachable.',
                            $route->uri(),
                            $scope,
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function aTokenCannotExceedItsOwner(): array
    {
        $violations = [];
        $service = app(ApiTokenService::class);
        \assert($this->user !== null);

        /*
         | Each of these asserts WHICH refusal, not merely that there was one.
         |
         | The first version asked only whether issuing threw - and a token
         | carrying `invoices:read` is refused twice over: once for not being
         | a permission, and once for not being held by its owner. So
         | disabling the unknown-scope check entirely left this passing on
         | the second refusal. Found by disabling exactly that.
         */
        $refusal = $this->refusalCode(fn () => $service->issue(
            owner: $this->user,
            name: 'invented scope',
            scopes: ['invoices:read'],
            entityIds: [$this->entityId],
        ));

        if ($refusal !== 'scope_unknown') {
            $violations[] = sprintf(
                'a token carrying `invoices:read` was refused as `%s` and should be refused as '.
                '`scope_unknown`: it is not a permission at all. A second vocabulary for machines is a '.
                'second authorization model, and the two drift the moment one of them gains an entry.',
                $refusal ?? 'not refused at all',
            );
        }

        /*
         | A scope the owner does not hold anywhere.
         |
         | `item.manage` is seeded, is not in the role this fixture grants,
         | and needs no step-up - so the only thing that can refuse it is the
         | owner's missing grant, which is the case that matters. A scope that
         | existed nowhere would be refused by the check above, and a
         | step-up permission by the one below; either would prove nothing
         | about delegation. (`payroll.view` was the first choice and is
         | marked `requires_step_up`, so it was refused for that instead.)
         */
        $refusal = $this->refusalCode(fn () => $service->issue(
            owner: $this->user,
            name: 'borrowed permission',
            scopes: ['item.manage'],
            entityIds: [$this->entityId],
        ));

        if ($refusal !== 'scope_not_held') {
            $violations[] = sprintf(
                'a token carrying `item.manage` - a real permission its owner does not hold, and one that '.
                'needs no step-up - was refused as `%s`. Issuing a token would otherwise be a way to grant '.
                'yourself a permission.',
                $refusal ?? 'not refused at all',
            );
        }

        // And a step-up permission can never be a scope, whoever holds it.
        $refusal = $this->refusalCode(fn () => $service->issue(
            owner: $this->user,
            name: 'a credential that releases payments',
            scopes: ['payment.release'],
            entityIds: [$this->entityId],
        ));

        if ($refusal !== 'scope_needs_step_up') {
            $violations[] = sprintf(
                'a token carrying `payment.release` was refused as `%s` and should be refused as '.
                '`scope_needs_step_up`. A step-up assertion proves somebody is at the keyboard now; a token '.
                'in a configuration file proves the opposite.',
                $refusal ?? 'not refused at all',
            );
        }

        // And the scope list must mirror the catalogue in the other direction.
        $scopes = app(ApiScopes::class)->all();
        $permissions = Permission::query()->orderBy('key')->pluck('key')->all();

        if ($scopes !== $permissions) {
            $violations[] = sprintf(
                'the scope list and the permission catalogue disagree: %s. They are meant to be the same '.
                'list, so that there is one authorization model.',
                implode(', ', array_merge(
                    array_diff($scopes, $permissions),
                    array_diff($permissions, $scopes),
                )),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function delegationOnlyNarrows(): array
    {
        $violations = [];

        /*
         | A read-only token that may manage users, asking for the right to
         | post.
         |
         | This is the escalation the rule exists for, and it is not
         | contrived: a token needs `user.manage` to rotate itself, and its
         | owner is a person who may post. Without the ceiling, the narrow
         | credential mints a wide one in a single call.
         */
        $response = $this->request('POST', '/api/v1/api-tokens', [
            'name' => 'a wider token',
            'scopes' => ['journal.post'],
            'entity_ids' => [$this->entityId],
        ], $this->headers('rotator'), operation: 'issueApiToken');

        if ($response->getStatusCode() !== 403) {
            $violations[] = sprintf(
                'a token carrying report.view and user.manage minted one carrying journal.post, and the '.
                'request answered %d. Delegation has to narrow, or this endpoint IS the privilege '.
                'escalation.',
                $response->getStatusCode(),
            );
        }

        $response = $this->request('POST', '/api/v1/api-tokens', [
            'name' => 'a token for the other company',
            'scopes' => ['report.view'],
            'entity_ids' => [$this->otherEntityId],
        ], $this->headers('rotator'), operation: 'issueApiToken');

        if ($response->getStatusCode() !== 403) {
            $violations[] = sprintf(
                'a token scoped to one entity minted one for another, and the request answered %d.',
                $response->getStatusCode(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function twoRefusalsNotOne(): array
    {
        $violations = [];

        // The token lacks the scope; its owner holds the permission.
        $response = $this->request(
            'POST',
            '/api/v1/journal-entries',
            $this->anEntry(),
            $this->headers('reader') + ['Idempotency-Key' => $this->key()],
            operation: 'postJournalEntry',
        );

        $problem = $this->problem($response);

        if ($response->getStatusCode() !== 403
            || ! str_contains((string) ($problem['type'] ?? ''), 'token-scope-missing')) {
            $violations[] = sprintf(
                'a token without the journal.post scope answered %d/%s. It should be 403 '.
                '`token-scope-missing`: the fix is to issue a new token, and a generic Forbidden sends '.
                'somebody to ask for a permission they already have.',
                $response->getStatusCode(),
                (string) ($problem['type'] ?? '(no type)'),
            );
        }

        /*
         | And the mirror image: the token carries the scope, and the user
         | behind it does not hold the permission.
         |
         | The token row is written directly rather than issued, because the
         | service refuses to issue it - correctly. The state being tested is
         | the one that exists after a ROLE IS REVOKED, which happens to a
         | live token every time somebody leaves a team, and the token is
         | still a valid credential at that moment.
         */
        $response = $this->request(
            'GET',
            '/api/v1/accounts',
            headers: $this->headers('stale'),
            operation: 'listAccounts',
        );

        $problem = $this->problem($response);

        if ($response->getStatusCode() !== 403
            || str_contains((string) ($problem['type'] ?? ''), 'token-scope-missing')) {
            $violations[] = sprintf(
                'a token whose owner lost the permission answered %d/%s. It should be 403 `forbidden`: '.
                're-issuing the token cannot help, so telling the holder that the token is at fault sends '.
                'them in the wrong direction.',
                $response->getStatusCode(),
                (string) ($problem['type'] ?? '(no type)'),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function entityScopeIsEnforced(): array
    {
        $violations = [];

        /*
         | An entity the TOKEN does not name, where the user does hold a role.
         |
         | The user's role in the other entity is what makes this check about
         | the token: without it, refusing the request proves only that
         | somebody refused it, and the first version of this passed with the
         | token's entity list ignored entirely - the grant check caught the
         | same request. Found by making `mayActIn()` return true.
         |
         | The two branches are told apart by `token_entity_ids`, which only
         | the token's own refusal carries.
         */
        $response = $this->request('GET', '/api/v1/accounts', headers: [
            'Authorization' => 'Bearer '.$this->tokens['full'],
            'X-Tenant' => $this->tenantId,
            'X-Entity' => $this->otherEntityId,
        ], operation: 'listAccounts');

        $problem = $this->problem($response);

        if ($response->getStatusCode() !== 403
            || ! str_contains((string) ($problem['type'] ?? ''), 'entity-not-permitted')
            || ! isset($problem['token_entity_ids'])) {
            $violations[] = sprintf(
                'a token acting in an entity it does not name - while its owner holds a role there - '.
                'answered %d/%s%s. A token is scoped to specific entities and never to the tenant, so '.
                'adding a company to the group must not widen a token issued before it.',
                $response->getStatusCode(),
                (string) ($problem['type'] ?? '(no type)'),
                isset($problem['token_entity_ids']) ? '' : ', without naming the token\'s own entities',
            );
        }

        /*
         | And the mirror: the token names the entity, its owner holds no role
         | there. Refused for the other reason, and it must not claim to be
         | the token's fault.
         */
        $response = $this->request('GET', '/api/v1/accounts', headers: [
            'Authorization' => 'Bearer '.$this->tokens['stranger-elsewhere'],
            'X-Tenant' => $this->tenantId,
            'X-Entity' => $this->otherEntityId,
        ], operation: 'listAccounts');

        $problem = $this->problem($response);

        if ($response->getStatusCode() !== 403
            || ! str_contains((string) ($problem['type'] ?? ''), 'entity-not-permitted')
            || isset($problem['token_entity_ids'])) {
            $violations[] = sprintf(
                'a token whose owner holds no role in the entity it names answered %d/%s. It must be '.
                'refused, and it must not be reported as the token naming the wrong entity - the token '.
                'names the right one, and the grant is what is missing.',
                $response->getStatusCode(),
                (string) ($problem['type'] ?? '(no type)'),
            );
        }

        // No X-Entity at all.
        $response = $this->request('GET', '/api/v1/accounts', headers: [
            'Authorization' => 'Bearer '.$this->tokens['full'],
            'X-Tenant' => $this->tenantId,
        ], operation: 'listAccounts');

        if ($response->getStatusCode() !== 400) {
            $violations[] = sprintf(
                'a request with no X-Entity answered %d and should be 400. The browser can guess because a '.
                'person is watching and will notice the wrong company\'s figures; an integration will not, '.
                'and the failure is silent and cumulative.',
                $response->getStatusCode(),
            );
        }

        // No X-Tenant at all.
        $response = $this->request('GET', '/api/v1/accounts', headers: [
            'Authorization' => 'Bearer '.$this->tokens['full'],
            'X-Entity' => $this->entityId,
        ], operation: 'listAccounts');

        if ($response->getStatusCode() !== 400) {
            $violations[] = sprintf(
                'a request with no X-Tenant answered %d and should be 400.',
                $response->getStatusCode(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function theWrongPlaneAndTheDead(): array
    {
        $violations = [];

        foreach (['platform' => 'a platform-plane token', 'revoked' => 'a revoked token'] as $which => $what) {
            $response = $this->request(
                'GET',
                '/api/v1/accounts',
                headers: $this->headers($which),
                operation: 'listAccounts',
            );

            if ($response->getStatusCode() !== 401) {
                $violations[] = sprintf(
                    '%s reached a tenant route and answered %d. Both must be 401 - and 401 rather than '.
                    '403, because confirming that a token is real and simply not usable here is the one '.
                    'fact worth having.',
                    $what,
                    $response->getStatusCode(),
                );
            }
        }

        /*
         | And the refusal is recorded against THAT token.
         |
         | A cross-plane token presentation is either a serious bug or
         | somebody probing, and both are worth a line an investigation can
         | find. It is written out of band, so it survives the refusal.
         |
         | Scoped to the token's own id rather than to the last five minutes:
         | this suite deliberately does not delete audit records - the chain
         | head holds the hash of the last one written - so a time window
         | matches the PREVIOUS run's records and the check passes with the
         | audit call removed entirely. Found by removing it.
         */
        $recorded = DB::connection('tenant')->table('audit_log')
            ->where('action', 'api_token.refused')
            ->where('subject_id', explode('|', $this->tokens['platform'])[0])
            ->count();

        if ($recorded === 0) {
            $violations[] = 'a token from another plane was refused and nothing was recorded against it. A '.
                'refusal nobody can see is indistinguishable from an attempt nobody made.';
        }

        return $violations;
    }

    /** @return list<string> */
    private function aTokenIsNeverWidened(): array
    {
        $violations = [];

        $token = ApiToken::query()->findOrFail(explode('|', $this->tokens['reader'])[0]);

        $refusal = $this->refusal(static fn () => DB::connection('tenant')->table('api_tokens')
            ->where('id', $token->getKey())
            ->update(['abilities' => json_encode(['report.view', 'journal.post'])]));

        if ($refusal === null) {
            $violations[] = 'a live token\'s scopes were widened by a direct UPDATE. "What could this token '.
                'do on the 14th" then has no answer, because the only row says it could always do this.';
        }

        $revokedId = explode('|', $this->tokens['revoked'])[0];

        $refusal = $this->refusal(static fn () => DB::connection('tenant')->table('api_tokens')
            ->where('id', $revokedId)
            ->update(['revoked_at' => null]));

        if ($refusal === null) {
            $violations[] = 'a revoked token was brought back to life by clearing revoked_at. A credential '.
                'that was compromised is then live again, with one row saying both things.';
        }

        /*
         | Not a `static` closure, and that is not a style preference.
         |
         | It was one, and it read `$this->entityId` - which throws "Using
         | $this when not in object context" before it reaches the database.
         | `refusal()` catches any Throwable, so the closure's own error was
         | reported as the database refusing the row, and this assertion
         | passed for as long as it existed while proving nothing. Found by
         | dropping the constraint and watching the check still pass.
         */
        $entityId = $this->entityId;

        $refusal = $this->refusal(static fn () => DB::connection('tenant')->table('api_tokens')->insert([
            'id' => Str::lower((string) Str::ulid()),
            'plane' => 'tenant',
            'tokenable_type' => (new User())->getMorphClass(),
            'tokenable_id' => Str::lower((string) Str::ulid()),
            'name' => 'a wildcard',
            'token' => hash('sha256', (string) Str::ulid()),
            'abilities' => json_encode(['*']),
            'entity_ids' => json_encode([$entityId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($refusal === null) {
            $violations[] = 'a token carrying `*` was accepted by the database. A wildcard names no '.
                'permission and widens itself with every deployment that adds one.';
        }

        return $violations;
    }

    /** @return list<string> */
    private function theLifecycleIsVisible(): array
    {
        $violations = [];

        $response = $this->request('POST', '/api/v1/api-tokens', [
            'name' => 'a token the suite rotates',
            'scopes' => ['report.view'],
            'entity_ids' => [$this->entityId],
        ], $this->headers('full'), operation: 'issueApiToken');

        $issued = $this->problem($response);

        if ($response->getStatusCode() !== 201 || ! \is_string($issued['token'] ?? null)) {
            return [sprintf(
                'issuing a token answered %d and returned %s',
                $response->getStatusCode(),
                $issued === [] ? 'nothing' : 'no plaintext',
            )];
        }

        $id = (string) $issued['id'];

        /*
         | Used once, so `last_used_at` has something to say.
         |
         | That field is the only one that answers the question actually asked
         | about a token found in a configuration file: is anything still
         | using this. A name and a creation date cannot.
         */
        $this->request('GET', '/api/v1/journal-entries', headers: [
            'Authorization' => 'Bearer '.$issued['token'],
            'X-Tenant' => $this->tenantId,
            'X-Entity' => $this->entityId,
        ], operation: 'listJournalEntries');

        $rotated = $this->request('POST', '/api/v1/api-tokens/'.$id.'/rotate', [
            'reason' => 'The suite proves a rotation is two rows and an arrow.',
        ], $this->headers('full'), operation: 'rotateApiToken');

        $successor = $this->problem($rotated);

        if ($rotated->getStatusCode() !== 201) {
            $violations[] = sprintf('rotating answered %d', $rotated->getStatusCode());
        }

        $listing = $this->problem($this->request(
            'GET',
            '/api/v1/api-tokens',
            headers: $this->headers('full'),
            operation: 'listApiTokens',
        ));

        $rows = [];

        foreach ((array) ($listing['data'] ?? []) as $row) {
            $rows[(string) $row['id']] = $row;
        }

        $predecessor = $rows[$id] ?? null;

        if ($predecessor === null) {
            $violations[] = 'a rotated token vanished from the listing. The rotation chain is only legible '.
                'when both ends of it are in the list, and "did we already rotate this" is the question '.
                'being asked.';
        } else {
            if ($predecessor['last_used_at'] === null) {
                $violations[] = 'last_used_at is null on a token that was just used, so the listing cannot '.
                    'answer whether anything is still using a credential somebody found';
            }

            if ($predecessor['revoked_at'] === null) {
                $violations[] = 'the predecessor is not revoked, so a rotation doubled the number of live '.
                    'credentials rather than replacing one';
            }

            if (($predecessor['rotated_to_token_id'] ?? null) !== ($successor['id'] ?? null)) {
                $violations[] = 'the predecessor does not name its successor, so the rotation is two '.
                    'unrelated rows';
            }
        }

        /*
         | Scoped to this token's id, for the reason above: a time window
         | matches an earlier run, and this suite leaves its audit records
         | behind on purpose.
         */
        foreach ([
            'api_token.issued' => $id,
            'api_token.rotated' => $id,
        ] as $action => $subject) {
            $audited = DB::connection('tenant')->table('audit_log')
                ->where('action', (string) $action)
                ->where('subject_id', $subject)
                ->count();

            if ($audited === 0) {
                $violations[] = sprintf('`%s` was not audited against the token it happened to', (string) $action);
            }
        }

        $revoked = $this->request(
            'DELETE',
            '/api/v1/api-tokens/'.(string) $successor['id'],
            ['reason' => 'The suite proves a revocation is recorded with its reason.'],
            $this->headers('full'),
            operation: 'revokeApiToken',
        );

        if ($revoked->getStatusCode() !== 200) {
            $violations[] = sprintf('revoking answered %d', $revoked->getStatusCode());
        }

        if (DB::connection('tenant')->table('audit_log')
            ->where('action', 'api_token.revoked')
            ->where('subject_id', (string) ($successor['id'] ?? 'none'))
            ->count() === 0) {
            $violations[] = '`api_token.revoked` was not audited against the token it happened to';
        }

        // A reason is required, and short is not a reason.
        $refused = $this->request(
            'DELETE',
            '/api/v1/api-tokens/'.$id,
            ['reason' => 'no'],
            $this->headers('full'),
            operation: 'revokeApiToken',
        );

        if ($refused->getStatusCode() !== 422) {
            $violations[] = sprintf(
                'revoking with a two-character reason answered %d. "Revoked" with no reason cannot '.
                'distinguish a routine rotation from a response to a leak.',
                $refused->getStatusCode(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function theGateNarrows(): array
    {
        \assert($this->user !== null);

        $context = app(EntityContext::class);
        $context->use($this->entityId);

        $reader = ApiToken::query()->findOrFail(explode('|', $this->tokens['reader'])[0]);

        try {
            $withToken = Gate::forUser($this->user->withAccessToken($reader))->allows('journal.post');
            $withoutToken = Gate::forUser($this->freshUser())->allows('journal.post');
        } finally {
            $context->forget();
            $this->user->withAccessToken(null);
        }

        $violations = [];

        if ($withToken) {
            $violations[] = 'the gate allowed journal.post to a token that does not carry it. The scope '.
                'check on the route is not the only place authorization is asked: a service calling '.
                'Gate::denies() three layers down would answer from the user\'s full permissions, which is '.
                'the second and weaker model for machines that PF-218 forbids.';
        }

        if (! $withoutToken) {
            $violations[] = 'the gate refused journal.post to the user in a browser session, so the '.
                'narrowing is not narrowing - it is a denial';
        }

        return $violations;
    }

    // =========================================================================
    //  PF-219 - problem details
    // =========================================================================

    /** @return list<string> */
    private function everyRefusalIsAProblem(): array
    {
        $violations = [];
        $checked = 0;

        foreach ($this->seen as $seen) {
            if ($seen['status'] < 400) {
                continue;
            }

            $checked++;
            $body = json_decode($seen['body'], true);

            if (! \is_array($body)) {
                $violations[] = sprintf('%s %s (%d) did not answer with JSON', $seen['method'], $seen['uri'], $seen['status']);

                continue;
            }

            foreach (['type', 'title', 'status', 'detail', 'instance'] as $member) {
                if (! isset($body[$member])) {
                    $violations[] = sprintf(
                        '%s %s (%d) has no `%s`. RFC 9457 names all five, and `detail` is the only one a '.
                        'person can act on.',
                        $seen['method'],
                        $seen['uri'],
                        $seen['status'],
                        $member,
                    );
                }
            }

            if (($body['status'] ?? null) !== $seen['status']) {
                $violations[] = sprintf(
                    '%s %s answered %d and the document says %s. A client that trusts the body retries the '.
                    'wrong thing.',
                    $seen['method'],
                    $seen['uri'],
                    $seen['status'],
                    var_export($body['status'] ?? null, true),
                );
            }

            $type = (string) ($body['type'] ?? '');

            if (! str_starts_with($type, 'https://peakflow.app/problems/')) {
                $violations[] = sprintf('%s %s has the type `%s`, which is not in the registry', $seen['method'], $seen['uri'], $type);

                continue;
            }

            $value = substr($type, \strlen('https://peakflow.app/problems/'));

            if (ProblemType::tryFrom($value) === null) {
                $violations[] = sprintf(
                    '`%s` is not a declared problem type. An error type invented at the call site is not a '.
                    'contract a client can switch on.',
                    $value,
                );
            }
        }

        if ($checked === 0) {
            $violations[] = 'no refusal was observed at all, so this check proved nothing';
        }

        return $violations;
    }

    /** @return list<string> */
    private function theRegistryIsComplete(): array
    {
        $path = \dirname(__DIR__, 2).'/docs/api/problems.md';

        if (! is_file($path)) {
            return ['docs/api/problems.md is missing, so PF-219\'s registry of documented type URIs is nowhere'];
        }

        $document = (string) file_get_contents($path);
        $violations = [];

        foreach (ProblemType::cases() as $case) {
            if (! str_contains($document, '`'.$case->value.'`')) {
                $violations[] = sprintf(
                    '`%s` is a problem type and is not in the registry document. A type a client can '.
                    'receive and cannot look up is not a documented contract.',
                    $case->value,
                );

                continue;
            }

            /*
             | And the status beside it.
             |
             | A registry that named the types without their statuses would
             | leave a client guessing which ones are retryable - and the
             | guess people make is "4xx is my fault", which is wrong for
             | exactly the two that matter: a 409 in flight is retryable and
             | a 409 conflict is not.
             */
            $row = $this->rowFor($document, $case->value);

            if ($row !== null && ! str_contains($row, '| '.$case->status().' |')) {
                $violations[] = sprintf(
                    'the registry row for `%s` does not carry its status (%d)',
                    $case->value,
                    $case->status(),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function onlyTheHumanHalfIsLocalized(): array
    {
        $violations = [];
        $bodies = [];

        foreach (['ar', 'en'] as $locale) {
            $response = $this->request('GET', '/api/v1/accounts', headers: [
                'Authorization' => 'Bearer '.$this->tokens['reader'],
                'X-Tenant' => $this->tenantId,
                'X-Entity' => $this->entityId,
                'Accept-Language' => $locale,
            ], operation: 'listAccounts');

            $bodies[$locale] = $this->problem($response);

            if ($response->headers->get('content-language') !== $locale) {
                $violations[] = sprintf(
                    'a request asking for %s answered Content-Language %s. A client that asked for English '.
                    'and received Arabic cannot otherwise tell a wrong header from a deployment that '.
                    'ignores it.',
                    $locale,
                    (string) $response->headers->get('content-language'),
                );
            }
        }

        if (($bodies['ar']['type'] ?? null) !== ($bodies['en']['type'] ?? null)) {
            $violations[] = 'the problem `type` changed with the language. It is the identity of the '.
                'condition: a localized URI is a different URI, and a client switching on it takes a '.
                'different branch per language.';
        }

        if (($bodies['ar']['title'] ?? null) !== ($bodies['en']['title'] ?? null)) {
            $violations[] = 'the problem `title` changed with the language. It is the machine-readable '.
                'summary, and a client switching on it would break by taking the wrong branch rather than '.
                'by failing.';
        }

        if (($bodies['ar']['detail'] ?? null) === ($bodies['en']['detail'] ?? null)) {
            $violations[] = 'the problem `detail` is the same in both languages, so nothing is localized at '.
                'all - and the person reading the failure reads it in whichever language we happened to '.
                'write it in';
        }

        if (preg_match('/\p{Arabic}/u', (string) ($bodies['ar']['detail'] ?? '')) !== 1) {
            $violations[] = 'the Arabic `detail` has no Arabic in it';
        }

        if (preg_match('/\p{Arabic}/u', (string) ($bodies['en']['detail'] ?? '')) === 1) {
            $violations[] = 'the English `detail` still has Arabic in it, which renders perfectly and is '.
                'absent from the language the reader asked for';
        }

        foreach (ProblemType::cases() as $case) {
            if ($case->detail('ar') === $case->detail('en')) {
                $violations[] = sprintf('`%s` has the same detail in both languages', $case->value);
            }

            if (preg_match('/[^\x00-\x7F]/', $case->title()) === 1) {
                $violations[] = sprintf('`%s` has a non-ASCII title, which is the machine-readable half', $case->value);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function errorsAreAddressed(): array
    {
        $violations = [];

        // A control account, which the validator refuses with its reason.
        $response = $this->request('POST', '/api/v1/journal-entries', [
            'accounting_date' => $this->postingDate,
            'narration' => 'A posting to a control account',
            'lines' => [
                ['account_code' => $this->controlAccountCode, 'amount' => '100.0000'],
                ['account_code' => $this->accounts['credit'] ?? '', 'amount' => '-100.0000'],
            ],
        ], $this->headers('full') + ['Idempotency-Key' => $this->key()], operation: 'postJournalEntry');

        $problem = $this->problem($response);

        if ($response->getStatusCode() !== 422) {
            $violations[] = sprintf(
                'posting to a control account answered %d rather than 422. Its balance is the total of a '.
                'subledger: once the two disagree neither can be shown to be right without re-deriving '.
                'the whole history.',
                $response->getStatusCode(),
            );
        }

        $pointers = array_column((array) ($problem['errors'] ?? []), 'pointer');

        if (! \in_array('/lines/0/account_code', $pointers, true)) {
            $violations[] = sprintf(
                'the refusal addressed %s rather than /lines/0/account_code. A client cannot put the '.
                'message next to the field without a pointer, and a pointer that is off by one highlights '.
                'the wrong row with complete confidence.',
                implode(', ', $pointers) ?: '(nothing)',
            );
        }

        $codes = array_column((array) ($problem['errors'] ?? []), 'code');

        if (! \in_array('account_is_a_control', $codes, true)) {
            $violations[] = sprintf(
                'the refusal carries the codes %s and not `account_is_a_control`. The code is what a client '.
                'switches on; the sentence is for the person.',
                implode(', ', $codes) ?: '(none)',
            );
        }

        // An entry that does not balance reports the figure, as money.
        $response = $this->request('POST', '/api/v1/journal-entries', [
            'accounting_date' => $this->postingDate,
            'lines' => [
                ['account_code' => $this->accounts['debit'], 'amount' => '100.0000'],
                ['account_code' => $this->accounts['credit'], 'amount' => '-90.0000'],
            ],
        ], $this->headers('full') + ['Idempotency-Key' => $this->key()], operation: 'postJournalEntry');

        $problem = $this->problem($response);

        if ($response->getStatusCode() !== 422) {
            $violations[] = sprintf('an unbalanced entry answered %d', $response->getStatusCode());
        }

        if (($problem['imbalance']['amount'] ?? null) !== '10.0000') {
            $violations[] = sprintf(
                'the imbalance was reported as %s and not the object {"amount":"10.0000",…}. The amount an '.
                'entry is out BY is what says what went wrong - 90 is a transposition, twice a line is a '.
                'sign, a round figure is a missing line - and it is the figure a client is most likely to '.
                'do arithmetic on.',
                json_encode($problem['imbalance'] ?? null),
            );
        }

        return $violations;
    }

    // =========================================================================
    //  PF-220 - idempotency
    // =========================================================================

    /** @return list<string> */
    private function theKeyIsRequired(): array
    {
        $violations = [];

        $response = $this->request(
            'POST',
            '/api/v1/journal-entries',
            $this->anEntry(),
            $this->headers('full'),
            operation: 'postJournalEntry',
        );

        $problem = $this->problem($response);

        if ($response->getStatusCode() !== 422
            || ! str_contains((string) ($problem['type'] ?? ''), 'idempotency-key-required')) {
            $violations[] = sprintf(
                'a ledger write with no Idempotency-Key answered %d/%s and was not refused. A client that '.
                'retries without one posts the entry twice, and the second is indistinguishable from a '.
                'real transaction.',
                $response->getStatusCode(),
                (string) ($problem['type'] ?? '(no type)'),
            );
        }

        // And a key too short to be unique is not a key.
        $response = $this->request(
            'POST',
            '/api/v1/journal-entries',
            $this->anEntry(),
            $this->headers('full') + ['Idempotency-Key' => '1'],
            operation: 'postJournalEntry',
        );

        if ($response->getStatusCode() !== 422) {
            $violations[] = sprintf(
                'a one-character Idempotency-Key was accepted (%d). It collides with this same token\'s '.
                'next request, and the collision presents as a write that silently did nothing.',
                $response->getStatusCode(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function replayAndConflict(): array
    {
        $violations = [];
        $key = $this->key();
        $payload = $this->anEntry($key);

        $first = $this->request('POST', '/api/v1/journal-entries', $payload, $this->headers('full') + [
            'Idempotency-Key' => $key,
        ], operation: 'postJournalEntry');

        if ($first->getStatusCode() !== 201) {
            return [sprintf(
                'the first post answered %d: %s',
                $first->getStatusCode(),
                Str::limit((string) $first->getContent(), 300),
            )];
        }

        $second = $this->request('POST', '/api/v1/journal-entries', $payload, $this->headers('full') + [
            'Idempotency-Key' => $key,
        ], operation: 'postJournalEntry');

        if ($second->getStatusCode() !== 201 || $second->getContent() !== $first->getContent()) {
            $violations[] = sprintf(
                'a replay with the same body answered %d and a %s body. A network that drops a response is '.
                'indistinguishable from one that drops a request, so the retry has to be answered with the '.
                'SAME answer.',
                $second->getStatusCode(),
                $second->getContent() === $first->getContent() ? 'matching' : 'different',
            );
        }

        if ($second->headers->get('idempotent-replay') !== 'true') {
            $violations[] = 'a replayed response does not say so. A client cannot otherwise tell whether '.
                'its retry executed.';
        }

        /*
         | And the entry reads back the way it was written.
         |
         | Fetched here rather than in a check of its own, because this is the
         | only place in the suite that HAS a posted entry - and the money
         | and date checks walk every response, so the detail representation
         | with its lines has to appear in one of them.
         */
        $posted = $this->problem($first);
        $read = $this->request(
            'GET',
            '/api/v1/journal-entries/'.(string) ($posted['id'] ?? 'missing'),
            headers: $this->headers('full'),
            operation: 'getJournalEntry',
        );

        if ($read->getStatusCode() !== 200) {
            $violations[] = sprintf('reading back the posted entry answered %d', $read->getStatusCode());
        }

        $detail = $this->problem($read);

        if (($detail['number'] ?? null) !== ($posted['number'] ?? null)) {
            $violations[] = 'the entry read back is not the entry that was posted';
        }

        if (\count((array) ($detail['lines'] ?? [])) !== 2) {
            $violations[] = 'the entry read back does not carry its two lines';
        }

        // An id from another company is a 404, not a 403.
        $hidden = $this->request(
            'GET',
            '/api/v1/journal-entries/'.Str::lower((string) Str::ulid()),
            headers: $this->headers('full'),
            operation: 'getJournalEntry',
        );

        if ($hidden->getStatusCode() !== 404) {
            $violations[] = sprintf(
                'an entry id that does not exist answered %d rather than 404. A record that exists and is '.
                'not visible has to be reported identically to one that does not, or a numbering series can '.
                'be walked from outside.',
                $hidden->getStatusCode(),
            );
        }

        $entries = $this->countEntriesNarrated($key);

        if ($entries !== 1) {
            $violations[] = sprintf('the same key produced %d entries rather than exactly one', $entries);
        }

        // The same key, a different body.
        $different = $payload;
        $different['narration'] = 'A different request entirely';

        $third = $this->request('POST', '/api/v1/journal-entries', $different, $this->headers('full') + [
            'Idempotency-Key' => $key,
        ], operation: 'postJournalEntry');

        $problem = $this->problem($third);

        if ($third->getStatusCode() !== 409
            || ! str_contains((string) ($problem['type'] ?? ''), 'idempotency-key-conflict')) {
            $violations[] = sprintf(
                'the same key with a different body answered %d/%s rather than a 409. Returning the first '.
                'response would hide a client bug: the second write never happens, its id is recorded '.
                'against the first document, and the discrepancy surfaces weeks later as a document '.
                'nobody can find.',
                $third->getStatusCode(),
                (string) ($problem['type'] ?? '(no type)'),
            );
        }

        /*
         | The SAME token and key against a different ENTITY is a conflict.
         |
         | The record is keyed on the token and the key, so a client that
         | repointed its integration at a second company would otherwise be
         | REPLAYED - told its entry exists in a company where it does not.
         | The entity is inside the fingerprint for exactly this, and the
         | conflict is reported before the handler runs, which is why the
         | second company needs no open period for this to be answerable.
         */
        $crossKey = $this->key();
        $crossPayload = $this->anEntry($crossKey);

        $here = $this->request('POST', '/api/v1/journal-entries', $crossPayload, [
            'Authorization' => 'Bearer '.$this->tokens['both'],
            'X-Tenant' => $this->tenantId,
            'X-Entity' => $this->entityId,
            'Idempotency-Key' => $crossKey,
        ], operation: 'postJournalEntry');

        if ($here->getStatusCode() !== 201) {
            $violations[] = sprintf('the two-entity token could not post at all (%d)', $here->getStatusCode());
        }

        $elsewhere = $this->request('POST', '/api/v1/journal-entries', $crossPayload, [
            'Authorization' => 'Bearer '.$this->tokens['both'],
            'X-Tenant' => $this->tenantId,
            'X-Entity' => $this->otherEntityId,
            'Idempotency-Key' => $crossKey,
        ], operation: 'postJournalEntry');

        $problem = $this->problem($elsewhere);

        if ($elsewhere->getStatusCode() !== 409
            || ! str_contains((string) ($problem['type'] ?? ''), 'idempotency-key-conflict')) {
            $violations[] = sprintf(
                'the same token and key against another entity answered %d/%s rather than a 409. Replaying '.
                'it would tell a client repointed at a second company that its entry exists where it does '.
                'not; executing it would post the same document into two companies.',
                $elsewhere->getStatusCode(),
                (string) ($problem['type'] ?? '(no type)'),
            );
        }

        // An in-flight claim is a 409 with Retry-After, not a second execution.
        $inFlight = $this->whileAClaimIsHeld($key.'-inflight');

        if ($inFlight === null) {
            $violations[] = 'the in-flight case could not be exercised';
        } else {
            $problem = json_decode((string) $inFlight->getContent(), true);

            if ($inFlight->getStatusCode() !== 409
                || ! str_contains((string) ($problem['type'] ?? ''), 'idempotency-key-in-flight')) {
                $violations[] = sprintf(
                    'a request arriving while the first was still running answered %d/%s rather than a 409 '.
                    'in-flight. Executing it alongside would post the document twice.',
                    $inFlight->getStatusCode(),
                    (string) ($problem['type'] ?? '(no type)'),
                );
            }

            if ($inFlight->headers->get('retry-after') === null) {
                $violations[] = 'the in-flight refusal carries no Retry-After header, so every '.
                    'off-the-shelf retry policy ignores the pause it asked for';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function twoAtOnce(): array
    {
        $key = $this->key();
        $payload = $this->anEntry($key);

        $responses = $this->twoProcesses($key, $payload);

        if (\count($responses) !== 2) {
            return [sprintf(
                'only %d of the two workers reported back, so the race was not run. %s',
                \count($responses),
                implode(' | ', array_map(
                    static fn (array $r): string => Str::limit((string) ($r['error'] ?? ''), 200),
                    $responses,
                )),
            )];
        }

        $violations = [];

        $entries = $this->countEntriesNarrated($key);

        if ($entries !== 1) {
            $violations[] = sprintf(
                'two concurrent posts with one key produced %d entries. This is the failure the whole '.
                'mechanism exists for: two documents, identical in every field a person looks at, and a '.
                'different number.',
                $entries,
            );
        }

        $statuses = [(int) $responses[0]['status'], (int) $responses[1]['status']];
        sort($statuses);

        if ($statuses === [201, 201]) {
            /*
             | Both waited and both were answered. The loser blocked on the
             | unique index, the winner committed, and the loser replayed the
             | winner's response byte for byte.
             */
            if ($responses[0]['body'] !== $responses[1]['body']) {
                $violations[] = 'both workers got a 201 and the two bodies differ, so one of them posted a '.
                    'second document or answered from somewhere else';
            }

            return $violations;
        }

        if ($statuses !== [201, 409]) {
            return [...$violations, sprintf(
                'the two workers answered %d and %d. One of them posts; the other either waits for it and '.
                'replays its answer, or is told to come back - there is no third outcome. %s',
                $statuses[0],
                $statuses[1],
                Str::limit((string) $responses[0]['body'], 200),
            )];
        }

        /*
         | The winner took longer to commit than the loser was willing to
         | wait, so the loser was told to come back. That is the documented
         | contract and not a second answer: the retry it was told to make
         | gets the winner's own response.
         |
         | Asserted by MAKING that retry here. A check that stopped at the
         | 409 would be accepting a refusal as proof of a replay - and the
         | criterion is two identical responses, which this client gets on
         | its second call exactly as the contract says it will.
         */
        $refused = $responses[(int) $responses[0]['status'] === 409 ? 0 : 1];
        $winner = $responses[(int) $responses[0]['status'] === 409 ? 1 : 0];
        /** @var array<string, mixed> $problem */
        $problem = json_decode((string) $refused['body'], true) ?: [];

        if (! str_contains((string) ($problem['type'] ?? ''), 'idempotency-key-in-flight')) {
            $violations[] = sprintf(
                'the second worker was refused as `%s` rather than as a request in flight. A conflict here '.
                'would mean the two requests were not recognised as the same one.',
                (string) ($problem['type'] ?? '(no type)'),
            );

            return $violations;
        }

        $wait = (int) ($problem['retry_after'] ?? 0);

        if ($wait <= 0) {
            $violations[] = 'the in-flight refusal named no interval to wait, so a client has nothing to '.
                'retry on but a guess';

            return $violations;
        }

        sleep($wait);

        $retried = $this->request(
            'POST',
            '/api/v1/journal-entries',
            $payload,
            $this->headers('full') + ['Idempotency-Key' => $key],
            operation: 'postJournalEntry',
        );

        if ($retried->getStatusCode() !== 201 || $retried->getContent() !== $winner['body']) {
            $violations[] = sprintf(
                'the retry a client was told to make answered %d and a %s body. The whole point of the '.
                'Retry-After is that the answer is waiting - otherwise the client has been sent round a '.
                'loop with no end.',
                $retried->getStatusCode(),
                $retried->getContent() === $winner['body'] ? 'matching' : 'different',
            );
        }

        if ($this->countEntriesNarrated($key) !== 1) {
            $violations[] = 'the retry produced a second document';
        }

        return $violations;
    }

    /** @return list<string> */
    private function everyPostingRouteIsIdempotent(): array
    {
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! \in_array('POST', $route->methods(), true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $posts = array_filter(
                $middleware,
                static fn (mixed $entry): bool => \is_string($entry)
                    && (str_contains($entry, 'journal.post')
                        || str_contains($entry, 'invoice.issue')
                        || str_contains($entry, 'payment.record')
                        || str_contains($entry, 'payment.release')),
            );

            $guarded = array_filter(
                $middleware,
                static fn (mixed $entry): bool => $entry === 'api.idempotent'
                    || $entry === \App\Http\Middleware\Api\RequireIdempotency::class,
            );

            if ($posts === [] || $guarded !== []) {
                continue;
            }

            $violations[] = sprintf(
                'POST %s requires a scope that posts to the ledger and does not require an '.
                'Idempotency-Key. Forgetting it on the next ledger endpoint has to be a failed build '.
                'rather than a duplicate somebody finds in March.',
                $route->uri(),
            );
        }

        return $violations;
    }

    // =========================================================================
    //  PF-221 - pagination
    // =========================================================================

    /** @return list<string> */
    private function theCursorSeeks(): array
    {
        $violations = [];

        $first = $this->problem($this->request(
            'GET',
            '/api/v1/accounts?limit=10',
            headers: $this->headers('full'),
            operation: 'listAccounts',
        ));

        if (\count((array) ($first['data'] ?? [])) !== 10) {
            return [sprintf(
                'the first page returned %d rows of a requested 10, so the fixture has too few accounts to '.
                'prove anything about paging',
                \count((array) ($first['data'] ?? [])),
            )];
        }

        if (($first['meta']['pagination'] ?? null) !== 'cursor') {
            $violations[] = 'a page with no `page` parameter was not served by the cursor. Cursor is the '.
                'default because offset over a growing journal both drifts and degrades.';
        }

        $next = (string) ($first['links']['next'] ?? '');

        if ($next === '') {
            return [...$violations, 'the first page of a longer list offers no `next` link'];
        }

        $response = $this->request('GET', $this->pathOf($next), headers: $this->headers('full'), operation: 'listAccounts');
        $second = $this->problem($response);

        $firstCodes = array_column((array) $first['data'], 'code');
        $secondCodes = array_column((array) ($second['data'] ?? []), 'code');

        if (array_intersect($firstCodes, $secondCodes) !== []) {
            $violations[] = sprintf(
                'the second page repeats %s from the first. A keyset seek names the exact row it continues '.
                'from; an overlap means it is counting instead.',
                implode(', ', array_intersect($firstCodes, $secondCodes)),
            );
        }

        if ($secondCodes !== [] && min($secondCodes) < max($firstCodes)) {
            $violations[] = 'the second page is not after the first in sort order';
        }

        // RFC 8288, in the header and not only in the body.
        $link = (string) $response->headers->get('link');

        if (! str_contains($link, 'rel="next"')) {
            $violations[] = sprintf(
                'the Link header is `%s` and carries no rel="next". A library that already knows how to '.
                'follow one needs no code written for this API; a body-only link needs code.',
                $link,
            );
        }

        if (! str_contains($link, 'rel="prev"')) {
            $violations[] = 'a page reached by a cursor offers no rel="prev", so a client that has paged '.
                'forward cannot page back without starting again';
        }

        // And prev returns to where we were.
        $back = $this->problem($this->request(
            'GET',
            $this->pathOf((string) ($second['links']['prev'] ?? '')),
            headers: $this->headers('full'),
            operation: 'listAccounts',
        ));

        if (array_column((array) ($back['data'] ?? []), 'code') !== $firstCodes) {
            $violations[] = 'paging back did not return the page we came from';
        }

        return $violations;
    }

    /** @return list<string> */
    private function aTamperedCursor(): array
    {
        $violations = [];

        $first = $this->problem($this->request(
            'GET',
            '/api/v1/accounts?limit=10',
            headers: $this->headers('full'),
            operation: 'listAccounts',
        ));

        $next = $this->pathOf((string) ($first['links']['next'] ?? ''));
        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
        $cursor = (string) ($query['cursor'] ?? '');

        if ($cursor === '') {
            return ['no cursor was issued, so tampering with one cannot be checked'];
        }

        // A payload somebody edited: the signature no longer covers it.
        $forged = base64_encode('{"k":"0.0.0","i":"00000000000000000000000000","d":"f"}');
        $forged = rtrim(strtr($forged, '+/', '-_'), '=').'.'.explode('.', $cursor)[1];

        $response = $this->request(
            'GET',
            '/api/v1/accounts?limit=10&cursor='.urlencode($forged),
            headers: $this->headers('full'),
            operation: 'listAccounts',
        );

        $problem = $this->problem($response);

        if ($response->getStatusCode() !== 400
            || ! str_contains((string) ($problem['type'] ?? ''), 'cursor-not-readable')) {
            $violations[] = sprintf(
                'a hand-edited cursor answered %d/%s. An unsigned cursor is a hand-editable WHERE clause, '.
                'and the interesting version is not an attack but an integration that constructs cursors '.
                'itself and breaks when the sort key changes.',
                $response->getStatusCode(),
                (string) ($problem['type'] ?? '(no type)'),
            );
        }

        /*
         | And a cursor from another listing.
         |
         | Without the listing inside the signed material, an accounts cursor
         | verifies perfectly against the journal - and the keyset predicate
         | then compares an account code to a date.
         */
        $response = $this->request(
            'GET',
            '/api/v1/journal-entries?limit=10&cursor='.urlencode($cursor),
            headers: $this->headers('full'),
            operation: 'listJournalEntries',
        );

        if ($response->getStatusCode() !== 400) {
            $violations[] = sprintf(
                'an accounts cursor was accepted by the journal listing (%d). The same opaque string '.
                'against a different sort compares an account code to a date.',
                $response->getStatusCode(),
            );
        }

        // And a cursor against a different SORT of the same listing.
        $response = $this->request(
            'GET',
            '/api/v1/accounts?limit=10&sort=-code&cursor='.urlencode($cursor),
            headers: $this->headers('full'),
            operation: 'listAccounts',
        );

        if ($response->getStatusCode() !== 400) {
            $violations[] = sprintf(
                'a cursor issued for one sort was accepted for the reverse sort (%d), which silently '.
                'returns a page from the wrong place in the table',
                $response->getStatusCode(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function thePageSizeAllowlist(): array
    {
        $violations = [];
        /** @var list<int> $permitted */
        $permitted = (array) config('api.pagination.page_sizes');

        foreach (['99999', '11', '0', '-1', 'all'] as $asked) {
            $response = $this->request(
                'GET',
                '/api/v1/accounts?limit='.urlencode($asked),
                headers: $this->headers('full'),
                operation: 'listAccounts',
            );

            $problem = $this->problem($response);

            if ($response->getStatusCode() !== 400) {
                $violations[] = sprintf(
                    'limit=%s answered %d. An allowlist rather than a ceiling, because a ceiling answers '.
                    'limit=99999 with a page of %d and says nothing - so the client believes it has every '.
                    'row and iterates no further.',
                    $asked,
                    $response->getStatusCode(),
                    (int) end($permitted),
                );

                continue;
            }

            if (($problem['permitted_page_sizes'] ?? null) !== $permitted) {
                $violations[] = sprintf(
                    'the refusal of limit=%s does not name the permitted sizes. "Invalid limit" is a '.
                    'refusal somebody answers by guessing.',
                    $asked,
                );
            }
        }

        foreach ($permitted as $size) {
            $response = $this->request(
                'GET',
                '/api/v1/accounts?limit='.$size,
                headers: $this->headers('full'),
                operation: 'listAccounts',
            );

            if ($response->getStatusCode() !== 200) {
                $violations[] = sprintf('limit=%d is on the allowlist and answered %d', $size, $response->getStatusCode());
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function offsetIsTheSlowerPath(): array
    {
        $violations = [];

        $page = $this->problem($this->request(
            'GET',
            '/api/v1/accounts?limit=10&page=2',
            headers: $this->headers('full'),
            operation: 'listAccounts',
        ));

        if (($page['meta']['pagination'] ?? null) !== 'offset') {
            $violations[] = 'a request with `page` was not served by offset, and the response does not say '.
                'which mode answered it - so a client paging by number would page through the wrong thing '.
                'while everything looked fine';
        }

        if (($page['meta']['page'] ?? null) !== 2) {
            $violations[] = 'an offset page does not echo its page number';
        }

        $first = $this->problem($this->request(
            'GET',
            '/api/v1/accounts?limit=10&page=1',
            headers: $this->headers('full'),
            operation: 'listAccounts',
        ));

        if (array_intersect(
            array_column((array) ($page['data'] ?? []), 'code'),
            array_column((array) ($first['data'] ?? []), 'code'),
        ) !== []) {
            $violations[] = 'offset pages 1 and 2 overlap';
        }

        $cap = (int) config('api.pagination.maximum_offset_page');

        $response = $this->request(
            'GET',
            '/api/v1/accounts?limit=10&page='.($cap + 1),
            headers: $this->headers('full'),
            operation: 'listAccounts',
        );

        $problem = $this->problem($response);

        if ($response->getStatusCode() !== 400
            || ! str_contains((string) ($problem['type'] ?? ''), 'offset-too-deep')) {
            $violations[] = sprintf(
                'page=%d answered %d and the cap is %d. Reaching it means reading every row before it in '.
                'order to discard them.',
                $cap + 1,
                $response->getStatusCode(),
                $cap,
            );
        }

        foreach (['latest', '0', '-3'] as $asked) {
            $response = $this->request(
                'GET',
                '/api/v1/accounts?page='.urlencode($asked),
                headers: $this->headers('full'),
                operation: 'listAccounts',
            );

            if ($response->getStatusCode() !== 422 && $response->getStatusCode() !== 400) {
                $violations[] = sprintf(
                    'page=%s answered %d. Coerced, it becomes page one - so a client paging by a value it '.
                    'computed wrongly reads the first page for ever and appears to work.',
                    $asked,
                    $response->getStatusCode(),
                );
            }
        }

        return $violations;
    }

    // =========================================================================
    //  PF-222 - money and dates
    // =========================================================================

    /** @return list<string> */
    private function liveResponsesMatchTheContract(): array
    {
        $violations = [];
        $validated = 0;

        foreach ($this->seen as $seen) {
            $operation = $this->operation($seen['operation']);

            if ($operation === null) {
                continue;
            }

            $response = $operation['responses'][(string) $seen['status']] ?? null;

            if ($response === null) {
                $violations[] = sprintf(
                    '%s answered %d, which its operation `%s` does not describe. An error that is not in '.
                    'the spec is not an error a client can handle.',
                    $seen['uri'],
                    $seen['status'],
                    $seen['operation'],
                );

                continue;
            }

            $resolved = $this->dereference($response);
            $schema = $resolved['content']['application/json']['schema']
                ?? $resolved['content']['application/problem+json']['schema']
                ?? null;

            if ($schema === null) {
                continue;
            }

            /** @var mixed $body */
            $body = json_decode($seen['body'], true);

            if (! \is_array($body)) {
                continue;
            }

            $validated++;

            $violations = [...$violations, ...array_map(
                static fn (string $problem): string => sprintf(
                    '%s %s (%d): %s',
                    $seen['method'],
                    $seen['uri'],
                    $seen['status'],
                    $problem,
                ),
                $this->validate($body, (array) $schema, ''),
            )];
        }

        if ($validated === 0) {
            $violations[] = 'no live response was validated against the contract, so this check proved '.
                'nothing';
        }

        return $violations;
    }

    /** @return list<string> */
    private function noBareNumbers(): array
    {
        $violations = [];
        $walked = 0;

        /*
         | The names a decimal hides behind.
         |
         | Not an exhaustive list of money fields - it is the list of names
         | that must never carry a JSON number, whatever else they carry.
         */
        $monetary = [
            'amount', 'net', 'tax', 'gross', 'total', 'debit', 'credit', 'balance',
            'imbalance', 'unallocated', 'allocated', 'rate', 'unit_price', 'quantity',
            'functional_amount', 'taxable_amount', 'tax_amount', 'amount_due',
        ];

        foreach ($this->seen as $seen) {
            /** @var mixed $body */
            $body = json_decode($seen['body'], true);

            if (! \is_array($body)) {
                continue;
            }

            $walked++;

            foreach ($this->flatten($body) as $path => $value) {
                $leaf = (string) (array_slice(explode('/', $path), -1)[0] ?? '');

                /*
                 | A decimal number ANYWHERE is a violation.
                 |
                 | Not only under a money key. Every decimal quantity in this
                 | product - an amount, a rate, a quantity, a unit price - is
                 | an exact decimal held as a string, so a float in a response
                 | is either money that escaped or a field that is about to
                 | become money. The one legitimate non-integer would be a
                 | ratio nobody computes arithmetic on, and there is none.
                 */
                if (\is_float($value)) {
                    $violations[] = sprintf(
                        '%s %s carries the JSON number %s at %s. A client parsing it as a float is the bug '.
                        'this prevents: 1.234 becomes 1.2339999999999999857891452847979962825775146484375, '.
                        'and every client that formats it with two decimals writes 1.23 on an invoice for a '.
                        'three-decimal currency.',
                        $seen['method'],
                        $seen['uri'],
                        var_export($value, true),
                        $path,
                    );

                    continue;
                }

                if (\is_int($value) && \in_array($leaf, $monetary, true)) {
                    $violations[] = sprintf(
                        '%s %s carries the integer %d at %s, where money belongs. An integer is the same '.
                        'mistake that has not been noticed yet: the first amount with a fraction turns it '.
                        'into a float.',
                        $seen['method'],
                        $seen['uri'],
                        $value,
                        $path,
                    );
                }

                // A money object is an object, with a string amount.
                if ($leaf === 'currency' && \is_string($value)) {
                    $parent = implode('/', array_slice(explode('/', $path), 0, -1));
                    $sibling = $parent === '' ? 'amount' : $parent.'/amount';
                    $amounts = $this->flatten($body);

                    if (isset($amounts[$sibling]) && ! \is_string($amounts[$sibling])) {
                        $violations[] = sprintf(
                            '%s has a `currency` at %s whose `amount` is not a string',
                            $seen['uri'],
                            $parent,
                        );
                    }
                }

                // An accounting date is a date; a timestamp is RFC 3339 UTC.
                if (\is_string($value) && $this->isAccountingDateField($leaf)) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                        $violations[] = sprintf(
                            '%s carries `%s` as %s at %s. An accounting date that can be timezone-shifted '.
                            'is a period-allocation bug: 2026-11-30T21:00:00Z read in Riyadh is December, '.
                            'which is another month, another quarter and another tax return.',
                            $seen['uri'],
                            $leaf,
                            var_export($value, true),
                            $path,
                        );
                    }
                }

                if (\is_string($value) && $this->isTimestampField($leaf)) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
                        $violations[] = sprintf(
                            '%s carries the timestamp `%s` as %s at %s rather than RFC 3339 in UTC',
                            $seen['uri'],
                            $leaf,
                            var_export($value, true),
                            $path,
                        );
                    }
                }
            }
        }

        if ($walked === 0) {
            $violations[] = 'no response body was walked, so this check proved nothing';
        }

        /*
         | And the three-decimal currency, directly.
         |
         | The responses above are in a two-decimal currency, which is the
         | case that works by accident. JOD is the case that does not: the
         | amount keeps its four stored places and the exponent says 3, so a
         | client formats it correctly - and a server that had pre-formatted
         | it to two places would have hidden the evidence.
         */
        $jod = \App\Support\Http\Api\Wire::amount('1.2340', 'JOD');

        if ($jod['amount'] !== '1.2340' || $jod['presentation_exponent'] !== 3) {
            $violations[] = sprintf(
                'a JOD amount reached the wire as %s. The amount is a string at the storage scale and the '.
                'exponent is a separate field, so the client formats and the server does not pre-format.',
                json_encode($jod),
            );
        }

        return $violations;
    }

    // =========================================================================
    //  Reading the contract
    // =========================================================================

    private function specPath(): string
    {
        return \dirname(__DIR__, 2).'/docs/api/openapi.yaml';
    }

    /**
     * Every operation in the contract, flattened.
     *
     * @return list<array{id: string, method: string, path: string, responses: array<string, mixed>, requestBody: array<string, mixed>, security: mixed}>
     */
    private function operations(): array
    {
        $operations = [];

        foreach ((array) ($this->spec['paths'] ?? []) as $path => $methods) {
            foreach ((array) $methods as $method => $operation) {
                if (! \in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $operations[] = [
                    'id' => (string) ($operation['operationId'] ?? $method.' '.$path),
                    'method' => strtoupper((string) $method),
                    'path' => (string) $path,
                    'responses' => (array) ($operation['responses'] ?? []),
                    'requestBody' => (array) ($operation['requestBody'] ?? []),
                    'security' => $operation['security'] ?? null,
                ];
            }
        }

        return $operations;
    }

    /** @return array<string, mixed>|null */
    private function operation(string $id): ?array
    {
        foreach ($this->operations() as $operation) {
            if ($operation['id'] === $id) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * The API routes, as `METHOD /path` with the version prefix removed.
     *
     * @return array<string, true>
     */
    private function apiRoutes(): array
    {
        $prefix = (string) config('api.prefix');
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix.'/') && $route->uri() !== $prefix) {
                continue;
            }

            $path = '/'.ltrim(substr($route->uri(), \strlen($prefix)), '/');

            foreach ($route->methods() as $method) {
                if (\in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $routes[$method.' '.$path] = true;
            }
        }

        return $routes;
    }

    /** @param array<array-key, mixed>|mixed $node */
    private function refsIn(mixed $node, string $path = ''): array
    {
        $refs = [];

        if (! \is_array($node)) {
            return $refs;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && \is_string($value)) {
                $refs[] = $value;

                continue;
            }

            $refs = [...$refs, ...$this->refsIn($value, $path.'/'.$key)];
        }

        return $refs;
    }

    /** @return array<string, mixed>|null */
    private function resolve(string $ref): ?array
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        /** @var mixed $node */
        $node = $this->spec;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            if (! \is_array($node) || ! \array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return \is_array($node) ? $node : null;
    }

    /**
     * One level of `$ref` resolution.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function dereference(array $node): array
    {
        if (\is_string($node['$ref'] ?? null)) {
            return $this->resolve((string) $node['$ref']) ?? [];
        }

        return $node;
    }

    /**
     * Validate a value against the subset of JSON Schema this contract uses.
     *
     * Deliberately a SUBSET, and it refuses nothing silently: a keyword the
     * contract uses and this does not understand would make every example
     * pass, which is the shape of a check that cannot fail. The supported
     * keywords are the ones the document uses, and `theContractIsWhole`
     * asserts that no other keyword appears.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validate(mixed $value, array $schema, string $path): array
    {
        $schema = $this->dereference($schema);

        if ($schema === []) {
            return [];
        }

        $problems = [];
        $at = $path === '' ? '(root)' : $path;

        foreach ((array) ($schema['allOf'] ?? []) as $branch) {
            $problems = [...$problems, ...$this->validate($value, (array) $branch, $path)];
        }

        if (isset($schema['oneOf'])) {
            $matched = false;

            foreach ((array) $schema['oneOf'] as $branch) {
                if ($this->validate($value, (array) $branch, $path) === []) {
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                $problems[] = sprintf('%s matches none of the alternatives', $at);
            }
        }

        if (isset($schema['const']) && $value !== $schema['const']) {
            $problems[] = sprintf('%s is %s and must be %s', $at, var_export($value, true), var_export($schema['const'], true));
        }

        if (isset($schema['enum']) && ! \in_array($value, (array) $schema['enum'], true)) {
            $problems[] = sprintf('%s is %s, which is not one of %s', $at, var_export($value, true), json_encode($schema['enum']));
        }

        $types = isset($schema['type']) ? (array) $schema['type'] : [];

        if ($types !== [] && ! $this->isOfType($value, $types)) {
            return [...$problems, sprintf('%s is %s and should be %s', $at, get_debug_type($value), implode('|', $types))];
        }

        if (\is_string($value)) {
            if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\/', (string) $schema['pattern']).'/u', $value) !== 1) {
                $problems[] = sprintf('%s (%s) does not match %s', $at, $value, (string) $schema['pattern']);
            }

            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                $problems[] = sprintf('%s is shorter than %d', $at, (int) $schema['minLength']);
            }

            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                $problems[] = sprintf('%s is longer than %d', $at, (int) $schema['maxLength']);
            }
        }

        if (\is_array($value) && array_is_list($value)) {
            if (isset($schema['minItems']) && \count($value) < (int) $schema['minItems']) {
                $problems[] = sprintf('%s has %d items and needs %d', $at, \count($value), (int) $schema['minItems']);
            }

            foreach ($value as $index => $item) {
                if (isset($schema['items'])) {
                    $problems = [...$problems, ...$this->validate($item, (array) $schema['items'], $path.'/'.$index)];
                }
            }

            return $problems;
        }

        if (\is_array($value)) {
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! \array_key_exists((string) $required, $value)) {
                    $problems[] = sprintf('%s is missing the required member `%s`', $at, (string) $required);
                }
            }

            $properties = (array) ($schema['properties'] ?? []);

            foreach ($value as $key => $member) {
                if (isset($properties[$key])) {
                    $problems = [...$problems, ...$this->validate($member, (array) $properties[$key], $path.'/'.$key)];

                    continue;
                }

                if (($schema['additionalProperties'] ?? null) === false) {
                    $problems[] = sprintf('%s has the undocumented member `%s`', $at, (string) $key);

                    continue;
                }

                if (\is_array($schema['additionalProperties'] ?? null)) {
                    $problems = [...$problems, ...$this->validate($member, (array) $schema['additionalProperties'], $path.'/'.$key)];
                }
            }
        }

        return $problems;
    }

    /** @param list<string> $types */
    private function isOfType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => \is_string($value),
                'integer' => \is_int($value),
                'number' => \is_int($value) || \is_float($value),
                'boolean' => \is_bool($value),
                'null' => $value === null,
                'array' => \is_array($value) && array_is_list($value),
                'object' => \is_array($value) && ! array_is_list($value),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    //  Driving the API
    // =========================================================================

    /**
     * One request through the real kernel, recorded.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    private function request(
        string $method,
        string $uri,
        ?array $body = null,
        array $headers = [],
        string $operation = '',
    ): Response {
        /*
         | Every guard forgotten first.
         |
         | `RequestGuard` caches its user after the first resolution, so the
         | second request in this process would authenticate as whoever the
         | first one was - and a check that passes because of the request
         | before it is not a check. Found while the entity-scope check
         | reported that a token could act anywhere.
         */
        $this->forgetEverySignedInUser();

        $request = $body === null
            ? Request::create($uri, $method)
            : Request::create(
                $uri,
                $method,
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                (string) json_encode($body),
            );

        $request->headers->set('Accept', 'application/json');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $response = app(Kernel::class)->handle($request);

        $this->seen[] = [
            'method' => $method,
            'uri' => $uri,
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getContent(),
            'operation' => $operation,
        ];

        return $response;
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.($this->tokens[$token] ?? 'none'),
            'X-Tenant' => $this->tenantId,
            'X-Entity' => $this->entityId,
        ];
    }

    /** @return array<string, mixed> */
    private function problem(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * A balanced two-line entry.
     *
     * The narration is the handle the checks count by. The client's
     * idempotency key is deliberately NOT written onto the entry - the column
     * behind `source_id` is a ULID - so "how many entries did this key
     * produce" is answered by looking for the entry the request described.
     *
     * @return array<string, mixed>
     */
    private function anEntry(?string $narration = null): array
    {
        return [
            'accounting_date' => $this->postingDate,
            'narration' => $narration ?? 'Accrued electricity, by the contract suite',
            'lines' => [
                ['account_code' => $this->accounts['debit'], 'amount' => '150.0000'],
                ['account_code' => $this->accounts['credit'], 'amount' => '-150.0000'],
            ],
        ];
    }

    /**
     * How many entries the suite's own narration marker produced.
     *
     * The entity scope is re-established first, and that is not a detail:
     * every request clears it in a `finally` - a pooled connection must not
     * carry one request's entity into the next - and `journal_entries` is
     * behind row-level security. Without it this counted zero after a
     * successful post and reported that the write had not happened, which is
     * the most misleading possible failure for an idempotency check.
     */
    private function countEntriesNarrated(string $narration): int
    {
        app(EntityContext::class)->use($this->entityId);

        try {
            return DB::connection('tenant')->table('journal_entries')
                ->where('entity_id', $this->entityId)
                ->where('narration', $narration)
                ->count();
        } finally {
            app(EntityContext::class)->forget();
        }
    }

    private function key(): string
    {
        return 'suite-'.Str::lower((string) Str::ulid());
    }

    private function pathOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $query === '' ? $path : $path.'?'.$query;
    }

    /**
     * Hold an idempotency claim open on another connection, then ask again.
     *
     * The claim lives inside the first request's transaction, so the only way
     * to observe "still in flight" from this process is to BE the first
     * request - holding the row uncommitted while a second request tries to
     * claim it. A second connection does exactly that, and the request under
     * test then blocks on the unique index until its lock timeout and answers
     * 409.
     */
    private function whileAClaimIsHeld(string $key): ?Response
    {
        config(['database.connections.claim_holder' => (array) config('database.connections.tenant')]);
        DB::purge('claim_holder');

        $holder = DB::connection('claim_holder');

        /*
         | The entity, written onto THIS connection.
         |
         | `api_idempotency_records` is behind forced row-level security, and
         | the session variable the policy reads lives on the connection - so
         | a second connection starts with no entity and the insert below is
         | refused by the policy rather than by anything this check is about.
         */
        $context = app(EntityContext::class);
        $context->use($this->entityId);
        $context->applyTo($holder);

        /*
         | The token id out of the bearer string, not a lookup by name.
         |
         | It was `where('name', 'the suite - full')->value('id')`, which on a
         | tenant carrying rows from an earlier interrupted run returned the
         | OLD token - so the claim was held against a token the request was
         | not using, nothing conflicted, and the check reported that an
         | in-flight request had been executed twice when it had not. The
         | bearer is `<id>|<secret>`, so the id is already in hand.
         */
        $tokenId = explode('|', $this->tokens['full'])[0];

        $holder->beginTransaction();

        try {
            $holder->table('api_idempotency_records')->insert([
                'id' => (string) Str::ulid(),
                'token_id' => $tokenId,
                'idempotency_key' => $key,
                'entity_id' => $this->entityId,
                'request_fingerprint' => str_repeat('0', 64),
                'state' => 'in_flight',
                'expires_at' => now()->addHours(24),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->request(
                'POST',
                '/api/v1/journal-entries',
                $this->anEntry(),
                $this->headers('full') + ['Idempotency-Key' => $key],
                operation: 'postJournalEntry',
            );
        } catch (Throwable) {
            return null;
        } finally {
            $holder->rollBack();
            DB::purge('claim_holder');
            $context->forget();
        }
    }

    /**
     * Two real OS processes posting the same key at the same instant.
     *
     * A loop in one process proves that the code can count, which nobody
     * doubted. The failure PF-220 exists for needs two transactions
     * contending for one unique index at the same moment, and the only way to
     * get that is two processes - the same reasoning as `ConcurrencyRules`.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function twoProcesses(string $key, array $payload): array
    {
        $base = \dirname(__DIR__, 2);
        $startAt = microtime(true) + 1.5;
        $workers = [];

        for ($i = 0; $i < 2; $i++) {
            $command = sprintf(
                'exec php %s %s %s %s %s %s %s',
                escapeshellarg($base.'/tests/Support/Concurrency/api-worker.php'),
                escapeshellarg($this->tenantId),
                escapeshellarg($this->tokens['full']),
                escapeshellarg($this->entityId),
                escapeshellarg($key),
                escapeshellarg((string) $startAt),
                escapeshellarg((string) json_encode($payload)),
            );

            $process = proc_open(
                ['bash', '-c', $command],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $base,
            );

            if (\is_resource($process)) {
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }
        }

        $output = array_fill(0, \count($workers), '');
        $errors = array_fill(0, \count($workers), '');
        $deadline = microtime(true) + 90;
        $running = $workers;

        while ($running !== [] && microtime(true) < $deadline) {
            foreach ($running as $i => $worker) {
                $output[$i] .= (string) stream_get_contents($worker['pipes'][1]);
                $errors[$i] .= (string) stream_get_contents($worker['pipes'][2]);

                if (proc_get_status($worker['process'])['running'] === false) {
                    $output[$i] .= (string) stream_get_contents($worker['pipes'][1]);
                    $errors[$i] .= (string) stream_get_contents($worker['pipes'][2]);

                    fclose($worker['pipes'][1]);
                    fclose($worker['pipes'][2]);
                    proc_close($worker['process']);

                    unset($running[$i]);
                }
            }

            if ($running !== []) {
                usleep(20_000);
            }
        }

        $results = [];

        foreach ($output as $i => $line) {
            /** @var mixed $decoded */
            $decoded = json_decode(trim($line), true);

            if (\is_array($decoded)) {
                $results[] = $decoded;

                continue;
            }

            $results[] = ['status' => 0, 'body' => '', 'error' => trim($errors[$i] ?: $line)];
        }

        return array_values(array_filter(
            $results,
            static fn (array $result): bool => (int) ($result['status'] ?? 0) !== 0,
        ));
    }

    // =========================================================================
    //  Small helpers
    // =========================================================================

    /**
     * Flatten a decoded body to `path => leaf value`.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<string, mixed>
     */
    private function flatten(array $node, string $prefix = ''): array
    {
        $flat = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'/'.$key;

            if (\is_array($value)) {
                $flat = [...$flat, ...$this->flatten($value, $path)];

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    private function isAccountingDateField(string $name): bool
    {
        return \in_array($name, [
            'accounting_date', 'issue_date', 'due_date', 'value_date', 'tax_point',
            'tax_point_date', 'delivery_date', 'as_of', 'expires_on', 'from', 'until',
        ], true);
    }

    private function isTimestampField(string $name): bool
    {
        return \in_array($name, [
            'posted_at', 'created_at', 'updated_at', 'last_used_at', 'revoked_at',
            'expires_at', 'issued_at', 'completed_at', 'entered_at',
        ], true);
    }

    private function rowFor(string $document, string $type): ?string
    {
        foreach (explode("\n", $document) as $line) {
            if (str_contains($line, '`'.$type.'`') && str_starts_with(trim($line), '|')) {
                return $line;
            }
        }

        return null;
    }

    /** A URI for an operation's path, with `{id}` filled in with something real. */
    private function concreteUri(string $path): string
    {
        return '/api/v1'.str_replace('{id}', (string) Str::ulid(), $path);
    }

    /**
     * The CODE of the first addressed error in a refusal, or null.
     *
     * The code and not the message, because the message is prose and the
     * point of asking is which of several refusals fired. A check that only
     * asks whether something was refused passes while the specific control it
     * was written for is switched off, as long as a second control catches
     * the same input.
     */
    private function refusalCode(callable $act): ?string
    {
        try {
            $act();
        } catch (TokenRefused $e) {
            return (string) ($e->errors[0]['code'] ?? 'refused with no addressed error');
        } catch (Throwable $e) {
            return $e::class;
        }

        return null;
    }

    /** Run something expected to be refused, and return the refusal. */
    private function refusal(callable $act): ?string
    {
        try {
            $act();
        } catch (TokenRefused|Throwable $e) {
            return (string) preg_replace('/\s+/', ' ', $e->getMessage());
        }

        return null;
    }

    private function freshUser(): User
    {
        /** @var User $user */
        $user = User::query()->findOrFail((string) $this->user?->getKey());

        return $user;
    }

    private function forgetEverySignedInUser(): void
    {
        try {
            auth()->forgetGuards();
        } catch (Throwable) {
            // Nothing resolved, which is the state this wants anyway.
        }
    }

    // =========================================================================
    //  The fixture
    // =========================================================================

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $suffix = Str::upper(Str::random(6));

        $entity = Entity::create([
            'code' => 'API'.$suffix,
            'legal_name' => 'Peakflow API Co.',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entity = $entity;
        $this->entityId = (string) $entity->getKey();

        $other = Entity::create([
            'code' => 'OTH'.$suffix,
            'legal_name' => 'Peakflow API Subsidiary',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->otherEntityId = (string) $other->getKey();

        $context->use($entity);

        $this->user = User::create([
            'name' => 'The contract suite',
            'email' => 'api-suite-'.Str::lower($suffix).'@peakflow.test',
            'password' => Str::random(40),
            'active' => true,
        ]);

        /*
         | The role is granted with a direct insert, not through
         | RoleGrantService.
         |
         | `journal.post` is marked `requires_mfa`, and the grant service
         | refuses it to a user with no confirmed second factor - correctly.
         | Enrolling one here would mean this suite tested the enrolment
         | flow, which belongs to PF-030 and has its own checks. What is under
         | test here is what the API does with a user who HAS the permission.
         */
        $role = Role::create([
            'key' => 'api-suite-'.Str::lower($suffix),
            'name_ar' => 'دور فحص الواجهة',
            'name_en' => 'The contract suite role',
            'system' => false,
        ]);

        $this->roleId = (string) $role->getKey();

        $permissions = Permission::query()
            ->whereIn('key', ['account.manage', 'report.view', 'journal.post', 'user.manage'])
            ->pluck('id', 'key');

        foreach ($permissions as $permissionId) {
            DB::connection('tenant')->table('role_permissions')->insert([
                'id' => (string) Str::ulid(),
                'role_id' => $this->roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         | The same role in BOTH entities.
         |
         | In the second one deliberately: the entity-scope check needs a
         | request that only the TOKEN's own entity list can refuse. With no
         | grant there, the grant check refuses it first and the check would
         | pass with the token's list ignored entirely.
         */
        foreach ([$this->entityId, $this->otherEntityId] as $entityId) {
            DB::connection('tenant')->table('user_entity_roles')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => (string) $this->user->getKey(),
                'entity_id' => $entityId,
                'role_id' => $this->roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->user->forgetResolvedPermissions();

        $this->stranger = User::create([
            'name' => 'The contract suite, without grants',
            'email' => 'api-suite-stranger-'.Str::lower($suffix).'@peakflow.test',
            'password' => Str::random(40),
            'active' => true,
        ]);

        $empty = Role::create([
            'key' => 'api-suite-empty-'.Str::lower($suffix),
            'name_ar' => 'دور بلا صلاحيات',
            'name_en' => 'A role that grants nothing',
            'system' => false,
        ]);

        $this->emptyRoleId = (string) $empty->getKey();

        DB::connection('tenant')->table('user_entity_roles')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => (string) $this->stranger->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => $this->emptyRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A period that accepts postings, on a date inside it.
        $date = Carbon::now()->startOfMonth()->addDays(9);
        $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, $date);

        if ($period === null) {
            throw new \RuntimeException('a newly created entity has no period covering '.$date->toDateString());
        }

        if ($period->state !== PeriodState::Open) {
            app(PeriodStateMachine::class)->transition($period, PeriodState::Open, 'opened for the API contract checks');
        }

        $this->postingDate = $date->toDateString();

        // Two postable, non-control accounts, and one control account.
        $postable = DB::connection('tenant')->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('postable', true)
            ->whereNotIn('id', fn ($q) => $q->select('account_id')->from('account_roles'))
            ->orderBy('code')
            ->pluck('code');

        $this->accounts = [
            'debit' => (string) $postable->first(),
            'credit' => (string) $postable->get(1),
        ];

        $this->controlAccountCode = (string) DB::connection('tenant')->table('accounts')
            ->join('account_roles', 'accounts.id', '=', 'account_roles.account_id')
            ->where('accounts.entity_id', $this->entityId)
            ->orderBy('accounts.code')
            ->value('accounts.code');

        $this->mintTokens();
    }

    private function mintTokens(): void
    {
        \assert($this->user !== null);

        $service = app(ApiTokenService::class);

        $this->tokens['full'] = $service->issue(
            owner: $this->user,
            name: 'the suite - full',
            scopes: ['account.manage', 'report.view', 'journal.post', 'user.manage'],
            entityIds: [$this->entityId],
        )['plaintext'];

        $this->tokens['reader'] = $service->issue(
            owner: $this->user,
            name: 'the suite - reader',
            scopes: ['report.view'],
            entityIds: [$this->entityId],
        )['plaintext'];

        $this->tokens['rotator'] = $service->issue(
            owner: $this->user,
            name: 'the suite - rotator',
            scopes: ['report.view', 'user.manage'],
            entityIds: [$this->entityId],
        )['plaintext'];

        $revoked = $service->issue(
            owner: $this->user,
            name: 'the suite - revoked',
            scopes: ['report.view'],
            entityIds: [$this->entityId],
        );

        $this->tokens['revoked'] = $revoked['plaintext'];
        $service->revoke($revoked['token'], 'Revoked so the suite can prove a dead token opens nothing.');

        /*
         | The rows the service will not write, written directly.
         |
         | Each is a state that EXISTS in production and that the service
         | correctly refuses to create: a platform-plane row in a tenant's
         | table (a restore into the wrong database), a token whose owner's
         | role was revoked after it was issued, and a token for an entity
         | its owner no longer reaches. Refusing to create them is right;
         | refusing to TEST them would leave the middleware that handles them
         | unexercised.
         */
        $this->tokens['platform'] = $this->plantToken('the suite - platform', [
            'plane' => ApiToken::PLANE_PLATFORM,
            'abilities' => ['report.view'],
            'entity_ids' => [$this->entityId],
        ]);

        /*
         | A token carrying a scope its owner no longer holds.
         |
         | Owned by the stranger, whose role grants nothing. The token is
         | perfectly formed and carries exactly the scope the route asks for,
         | so the only thing that can refuse it is the user's own permission -
         | which is the distinction `twoRefusalsNotOne` is about.
         */
        $this->tokens['stale'] = $this->plantToken('the suite - stale grant', [
            'plane' => ApiToken::PLANE_TENANT,
            'abilities' => ['account.manage'],
            'entity_ids' => [$this->entityId],
        ], $this->stranger);

        /*
         | The stranger's token, naming an entity the stranger cannot reach.
         |
         | Their only role is in the first entity, so this names the second -
         | which makes the owner's missing grant the one thing that can refuse
         | it.
         */
        $this->tokens['stranger-elsewhere'] = $this->plantToken('the suite - stranger elsewhere', [
            'plane' => ApiToken::PLANE_TENANT,
            'abilities' => ['account.manage'],
            'entity_ids' => [$this->otherEntityId],
        ], $this->stranger);

        $this->tokens['both'] = $this->plantToken('the suite - two entities', [
            'plane' => ApiToken::PLANE_TENANT,
            'abilities' => ['journal.post', 'report.view'],
            'entity_ids' => [$this->entityId, $this->otherEntityId],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function plantToken(string $name, array $attributes, ?User $owner = null): string
    {
        $owner ??= $this->user;

        \assert($owner !== null);

        $id = Str::lower((string) Str::ulid());
        $plaintext = Str::random(40);

        DB::connection('tenant')->table('api_tokens')->insert([
            'id' => $id,
            'plane' => (string) $attributes['plane'],
            'tokenable_type' => $owner->getMorphClass(),
            'tokenable_id' => (string) $owner->getKey(),
            'name' => $name,
            'token' => hash('sha256', $plaintext),
            'abilities' => (string) json_encode($attributes['abilities']),
            'entity_ids' => (string) json_encode($attributes['entity_ids']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id.'|'.$plaintext;
    }

    /**
     * Remove the committed fixture, as the role that owns the tables.
     *
     * The application role cannot delete a posted journal entry - which is
     * correct, and is why this reconnects as the migrator. Leaving the rows
     * behind would make the development database a pile of probe entities and
     * would also make the next run of this suite measure the last one's data.
     */
    private function teardown(): void
    {
        if ($this->entityId === '') {
            return;
        }

        try {
            config(['database.connections.probe_owner' => array_merge(
                (array) config('database.connections.tenant'),
                [
                    'username' => config('database.connections.migrator.username'),
                    'password' => config('database.connections.migrator.password'),
                ],
            )]);

            DB::purge('probe_owner');
            $owner = DB::connection('probe_owner');

            $owner->table('api_idempotency_records')
                ->whereIn('entity_id', [$this->entityId, $this->otherEntityId])->delete();

            $owner->table('api_tokens')
                ->whereIn('tokenable_id', array_filter([
                    (string) $this->user?->getKey(),
                    (string) $this->stranger?->getKey(),
                ]))
                /*
                 | And the wildcard probe, which belongs to nobody.
                 |
                 | The row the `*` assertion tries to write names a random
                 | owner on purpose - it is testing a database constraint and
                 | not a user - so it would survive an owner-scoped cleanup.
                 | It never exists on a healthy run, because the constraint
                 | refuses it; it exists when somebody has dropped the
                 | constraint, which is exactly when the next run should not
                 | find it already there.
                 */
                ->orWhere('abilities', '["*"]')
                ->delete();

            /*
             | A posted entry is undeletable, by anybody, and that is correct.
             |
             | `ALTER TABLE ... DISABLE TRIGGER USER` rather than
             | `session_replication_role`: the owner role is deliberately not
             | a superuser (PF-010), so the parameter is refused - but a
             | table's owner may disable its own triggers, and only for the
             | two tables named here. The same decision `ConcurrencyRules`
             | took, for the same reason: this suite posts real entries and
             | leaving them behind would make the development database a pile
             | of probe companies and would make the next run measure this
             | one's data.
             |
             | `audit_log` is deliberately absent from both lists. It is
             | append-only, and the chain head holds the hash of the last
             | record written - so deleting this suite's records would leave
             | the tenant's chain pointing at a record that no longer exists
             | and `audit:verify` would report a tampered log for ever.
             */
            foreach (['journal_entries', 'journal_lines'] as $immutable) {
                if ($owner->getSchemaBuilder()->hasTable($immutable)) {
                    $owner->statement(sprintf('ALTER TABLE %s DISABLE TRIGGER USER', $immutable));
                }
            }

            /*
             | In foreign-key order, and the first two are the ones that were
             | missing.
             |
             | `journal_entry_fingerprints` references an entry and
             | `period_transitions` references a period, so deleting entries
             | or periods first fails - and because this whole block is inside
             | a `try` that reports to STDERR, the failure was invisible to a
             | runner reading stdout. Four fixture companies had accumulated
             | in the development database before anybody counted.
             |
             | Disabling the triggers does not help with these: a foreign key
             | is a system trigger, and `DISABLE TRIGGER USER` leaves it on -
             | correctly, since the alternative is a dangling reference.
             */
            foreach ([
                'journal_entry_fingerprints', 'journal_lines', 'journal_entries',
                'account_balances', 'period_transitions', 'account_roles', 'accounts',
                'periods', 'fiscal_years', 'entity_currencies', 'entity_settings', 'number_series',
                'journal_template_lines', 'journal_templates', 'journals',
            ] as $table) {
                if ($owner->getSchemaBuilder()->hasTable($table)
                    && $owner->getSchemaBuilder()->hasColumn($table, 'entity_id')) {
                    $owner->table($table)->whereIn('entity_id', [$this->entityId, $this->otherEntityId])->delete();
                }
            }

            $owner->table('user_entity_roles')
                ->whereIn('role_id', [$this->roleId, $this->emptyRoleId])->delete();
            $owner->table('role_permissions')
                ->whereIn('role_id', [$this->roleId, $this->emptyRoleId])->delete();
            $owner->table('roles')->whereIn('id', [$this->roleId, $this->emptyRoleId])->delete();
            $owner->table('users')->whereIn('id', array_filter([
                (string) $this->user?->getKey(),
                (string) $this->stranger?->getKey(),
            ]))->delete();
            $owner->table('entities')->whereIn('id', [$this->entityId, $this->otherEntityId])->delete();

            foreach (['journal_entries', 'journal_lines'] as $immutable) {
                if ($owner->getSchemaBuilder()->hasTable($immutable)) {
                    $owner->statement(sprintf('ALTER TABLE %s ENABLE TRIGGER USER', $immutable));
                }
            }

            DB::purge('probe_owner');
        } catch (Throwable $e) {
            fprintf(\STDERR, "        (teardown left entity %s behind: %s)\n", $this->entityId, $e->getMessage());
        }
    }
}

/**
 * A YAML reader for one document, and only the syntax that document uses.
 *
 * ## Why this exists at all
 *
 * There is no YAML library in this project. None could be installed in the
 * environment PeakFlow was first assembled in, which is the same reason
 * `bin/verify` exists - and "we could not check the contract" is not an
 * acceptable state for the document a customer's integration is written
 * against.
 *
 * ## Why it refuses rather than skips
 *
 * A lenient reader is the dangerous one. A document that drifted into syntax
 * this did not understand would be a document the check silently approved,
 * and a check that cannot fail is worse than no check - it is a check
 * somebody trusts.
 *
 * So anything outside the authoring convention stated at the top of
 * `openapi.yaml` throws, with the line number. The convention is narrow on
 * purpose: block mappings, block sequences, `|` block scalars, and JSON for
 * anything inline.
 */
final class MinimalYaml
{
    /** @return array<string, mixed> */
    public static function parse(string $text): array
    {
        $lines = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $number => $raw) {
            $trimmed = ltrim($raw, ' ');

            if (str_contains($raw, "\t")) {
                throw new \RuntimeException(sprintf('line %d is indented with a tab', $number + 1));
            }

            if ($trimmed === '') {
                // Kept, at an indent no structure can reach, so that a blank
                // line inside a block scalar survives and a blank line
                // between mappings ends nothing.
                $lines[] = ['indent' => PHP_INT_MAX, 'text' => '', 'line' => $number + 1];

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            $indent = \strlen($raw) - \strlen($trimmed);

            if ($indent % 2 !== 0) {
                throw new \RuntimeException(sprintf('line %d is indented by %d, which is not a multiple of two', $number + 1, $indent));
            }

            // `- key: value` is a sequence item whose value is a mapping. It
            // is split into a marker and the mapping's first line so that the
            // block parser sees one shape and not two.
            if (str_starts_with($trimmed, '- ') && preg_match('/^- ("[^"]*"|\'[^\']*\'|[^:{\[]+):(\s|$)/', $trimmed) === 1) {
                $lines[] = ['indent' => $indent, 'text' => '-', 'line' => $number + 1];
                $lines[] = ['indent' => $indent + 2, 'text' => substr($trimmed, 2), 'line' => $number + 1];

                continue;
            }

            $lines[] = ['indent' => $indent, 'text' => $trimmed, 'line' => $number + 1];
        }

        $index = 0;
        $parsed = self::block($lines, $index, 0);

        if (! \is_array($parsed)) {
            throw new \RuntimeException('the document is not a mapping');
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param  list<array{indent: int, text: string, line: int}>  $lines
     */
    private static function block(array $lines, int &$index, int $indent): mixed
    {
        $map = [];
        $list = [];

        while ($index < \count($lines)) {
            $line = $lines[$index];

            if ($line['indent'] === PHP_INT_MAX) {
                $index++;

                continue;
            }

            if ($line['indent'] < $indent) {
                break;
            }

            if ($line['indent'] > $indent) {
                throw new \RuntimeException(sprintf(
                    'line %d is indented deeper than its parent with nothing to belong to',
                    $line['line'],
                ));
            }

            $text = $line['text'];

            if ($text === '-' || str_starts_with($text, '- ')) {
                if ($map !== []) {
                    throw new \RuntimeException(sprintf('line %d mixes a sequence into a mapping', $line['line']));
                }

                $index++;

                if ($text === '-') {
                    $list[] = self::block($lines, $index, $indent + 2);

                    continue;
                }

                $list[] = self::scalar(substr($text, 2), $lines, $index, $indent, $line['line']);

                continue;
            }

            if ($list !== []) {
                throw new \RuntimeException(sprintf('line %d mixes a mapping into a sequence', $line['line']));
            }

            if (preg_match('/^("[^"]*"|\'[^\']*\'|[^:]+):(?:[ ](.*))?$/', $text, $matches) !== 1) {
                throw new \RuntimeException(sprintf(
                    'line %d is neither a mapping entry nor a sequence item: %s',
                    $line['line'],
                    $text,
                ));
            }

            $key = trim($matches[1], '\'"');
            $rest = $matches[2] ?? '';
            $index++;

            $map[$key] = $rest === ''
                ? self::nested($lines, $index, $indent)
                : self::scalar($rest, $lines, $index, $indent, $line['line']);
        }

        return $list !== [] ? $list : $map;
    }

    /**
     * The block belonging to a key with no inline value.
     *
     * @param  list<array{indent: int, text: string, line: int}>  $lines
     */
    private static function nested(array $lines, int &$index, int $indent): mixed
    {
        $next = $index;

        while ($next < \count($lines) && $lines[$next]['indent'] === PHP_INT_MAX) {
            $next++;
        }

        if ($next >= \count($lines) || $lines[$next]['indent'] <= $indent) {
            return null;
        }

        return self::block($lines, $index, $lines[$next]['indent']);
    }

    /**
     * A value written on the same line as its key, or after it.
     *
     * @param  list<array{indent: int, text: string, line: int}>  $lines
     */
    private static function scalar(string $value, array $lines, int &$index, int $indent, int $line): mixed
    {
        $value = rtrim($value);

        if ($value === '|' || $value === '|-' || $value === '>' || $value === '>-') {
            $collected = [];

            while ($index < \count($lines)) {
                if ($lines[$index]['indent'] === PHP_INT_MAX) {
                    $collected[] = '';
                    $index++;

                    continue;
                }

                if ($lines[$index]['indent'] <= $indent) {
                    break;
                }

                $collected[] = str_repeat(' ', $lines[$index]['indent'] - $indent - 2).$lines[$index]['text'];
                $index++;
            }

            // Trailing blanks belong to the next entry, not to this scalar.
            while ($collected !== [] && end($collected) === '') {
                array_pop($collected);
            }

            return implode("\n", $collected);
        }

        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            $json = $value;

            while (! self::balanced($json)) {
                if ($index >= \count($lines)) {
                    throw new \RuntimeException(sprintf('the inline value starting on line %d never closes', $line));
                }

                $json .= ' '.$lines[$index]['text'];
                $index++;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException(sprintf(
                    'line %d has an inline value that is not JSON (%s): %s',
                    $line,
                    json_last_error_msg(),
                    Str::limit($json, 120),
                ));
            }

            return $decoded;
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            /** @var mixed $decoded */
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException(sprintf('line %d has an unreadable quoted string', $line));
            }

            return $decoded;
        }

        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        if (str_contains($value, ' #')) {
            throw new \RuntimeException(sprintf(
                'line %d has a trailing comment on a plain scalar, which this reader will not guess at',
                $line,
            ));
        }

        return match (true) {
            $value === 'true' => true,
            $value === 'false' => false,
            $value === 'null' || $value === '~' => null,
            preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            preg_match('/^-?\d+\.\d+$/', $value) === 1 => (float) $value,
            default => $value,
        };
    }

    /** Are the braces and brackets of an inline value balanced, outside strings? */
    private static function balanced(string $json): bool
    {
        $depth = 0;
        $inString = false;
        $escaped = false;

        foreach (str_split($json) as $character) {
            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $escaped = true;

                continue;
            }

            if ($character === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($character === '{' || $character === '[') {
                $depth++;
            }

            if ($character === '}' || $character === ']') {
                $depth--;
            }
        }

        return ! $inString && $depth === 0;
    }
}
