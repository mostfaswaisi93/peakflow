<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveEntity;
use App\Http\Middleware\ResolveTenantFromHeader;
use App\Http\Middleware\ResolveTenantFromSession;
use App\Http\Middleware\SetLocale;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | SetLocale runs TWICE, and that is deliberate.
        |
        | Globally it runs before routing, so a request that never matches a
        | route - every 404, and every failure thrown during routing - still
        | answers in the right language. At that point there is no session, so
        | it resolves from Accept-Language alone.
        |
        | Then in the web group it runs again, with the session available, and
        | refines the choice using the user's own preference and the tenant
        | default. It is idempotent, so running it twice costs one extra
        | header parse and buys correct language on error responses.
        */
        $middleware->prepend(SetLocale::class);

        $middleware->web(append: [
            SetLocale::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Middleware ordering is LOAD-BEARING
        |----------------------------------------------------------------------
        |
        | The tenant resolver MUST run before any auth middleware, because the
        | `users` table lives in the tenant's own database. A guard that ran
        | first would query whichever connection happened to be configured - in
        | development the platform database, in production the PREVIOUS
        | request's tenant.
        |
        | The symptom is intermittent authentication against the wrong
        | database, which is one of the worst failures to diagnose, and this is
        | exactly the kind of ordering a framework upgrade reorders silently.
        | So it is pinned explicitly AND asserted by
        | tests/Feature/MiddlewarePriorityTest.php.
        |
        | The entity scope (PF-026) is the mirror image and is pinned AFTER
        | the guard, not before: which entity a user may act in is a property
        | of their roles, so the user has to exist first.
        */
        /*
        | Pin before the AuthenticatesRequests CONTRACT, not the Authenticate
        | class.
        |
        | Laravel's priority list holds the interface. Naming the concrete
        | class here matches nothing, and `prependToPriorityList` then silently
        | APPENDS - which puts the resolvers after the guard and produces
        | exactly the failure this pinning exists to prevent. That is not a
        | hypothetical: it is what happened on the first attempt, and it is why
        | MiddlewarePriorityTest asserts the resulting positions rather than
        | asserting that these lines were called.
        |
        | One call per middleware: $prepend is used as an array key, so it
        | takes a single class and not a list.
        */
        foreach ([ResolveTenantFromSession::class, ResolveTenantFromHeader::class] as $resolver) {
            $middleware->prependToPriorityList(
                before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
                prepend: $resolver,
            );
        }

        /*
        | And the entity scope immediately AFTER the guard.
        |
        | `appendToPriorityList` with the same contract as the anchor puts it
        | directly after authentication: the user is known, so their roles can
        | be read, and every middleware that runs later - authorization, the
        | SoD engine - can rely on an entity being established.
        */
        $middleware->appendToPriorityList(
            after: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            append: ResolveEntity::class,
        );

        $middleware->alias([
            'tenant.session' => ResolveTenantFromSession::class,
            'tenant.header' => ResolveTenantFromHeader::class,
            'entity.scope' => ResolveEntity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        |----------------------------------------------------------------------
        | Errors are RFC 9457 on the API, and guard-aware everywhere else
        |----------------------------------------------------------------------
        |
        | An API client gets `application/problem+json` with a type it can
        | switch on. A browser gets a redirect to the right login for the plane
        | it was in - the operator console and the application have different
        | ones, and sending an operator to the tenant login is a dead end.
        */

        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(static function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return Problem::of(ProblemType::ValidationFailed)
                ->withDetail(__('فشل التحقق من صحة الطلب'))
                ->withValidationErrors($e->errors())
                ->toResponse($request);
        });

        $exceptions->render(static function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return Problem::of(ProblemType::Unauthenticated)->toResponse($request);
            }

            // Guard-aware: the operator console and the application have
            // separate logins, and a 302 to the wrong one is a dead end.
            return redirect()->guest(
                $request->is('ops/*') ? '/ops/login' : '/login'
            );
        });

        $exceptions->render(static function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return Problem::of(ProblemType::Forbidden)
                ->withDetail($e->getMessage())
                ->toResponse($request);
        });

        $exceptions->render(static function (TooManyRequestsHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $problem = Problem::of(ProblemType::RateLimited);
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            if ($retryAfter !== null) {
                $problem->withExtensions(['retry_after' => (int) $retryAfter]);
            }

            return $problem->toResponse($request);
        });

        /*
        | A record that exists but is not visible to this token is reported the
        | same way as one that does not exist. Distinguishing them tells an
        | attacker which tenant ids and which document numbers are real.
        */
        $exceptions->render(static function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return Problem::of(ProblemType::NotFound)
                ->withDetail(__('لم يُعثَر على السجل المطلوب'))
                ->toResponse($request);
        });

        /*
        | Reports stay in the allowlist. A log, a trace and an error report all
        | leak the same way, so none of them carries a bound value, an IBAN or
        | a token - see config/telemetry.php and PF-014.
        */
        $exceptions->render(static fn (
            \App\Platform\Exceptions\TenantNotResolvable $e,
            Request $request,
        ) => $e->render($request));

        $exceptions->context(static fn (): array => array_filter([
            'tenant_id' => app()->bound('currentTenant') ? app('currentTenant')->getKey() : null,
            'entity_id' => app()->bound('currentEntity') ? app('currentEntity')->getKey() : null,
        ]));
    })
    ->create();
