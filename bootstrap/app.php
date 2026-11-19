<?php

declare(strict_types=1);

use App\Http\Middleware\Api\EstablishApiActor;
use App\Http\Middleware\Api\NegotiateApiLanguage;
use App\Http\Middleware\Api\RequireIdempotency;
use App\Http\Middleware\Api\RequireTokenScope;
use App\Http\Middleware\Api\ResolveApiEntity;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsurePlanAllows;
use App\Http\Middleware\RequireStepUp;
use App\Http\Middleware\ResolveEntity;
use App\Http\Middleware\SayWhyThePageIsUnstyled;
use App\Http\Middleware\ResolveTenantFromHeader;
use App\Http\Middleware\ResolveTenantFromPath;
use App\Http\Middleware\ResolveTenantFromSession;
use App\Http\Middleware\ApplyDisplayDigits;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TraceRequest;
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
        /*
        | The REST API, at `/api/v1` - PF-217, PF-230.
        |
        | The version is in the PREFIX and not negotiated by a header. A
        | version an integrator can see in a log line, in a bug report and in
        | the URL they pasted into a support ticket is worth more than one
        | negotiated invisibly - and a header default means a client that
        | forgets to send it silently gets whichever version we decided was
        | current, which is the upgrade nobody tested.
        |
        | `api/v1` and not `api` with the version inside the route file,
        | because `$request->is('api/*')` is what the exception handler below
        | switches on to answer in problem+json. One prefix, both facts.
        */
        apiPrefix: 'api/v1',
        api: __DIR__.'/../routes/api.php',
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
        /*
         | THE GLOBAL ORDER, written once, because four separate `prepend()`
         | calls each reasoned about in isolation produced the wrong one.
         |
         | ## What it was, and why that was not what anybody wrote
         |
         | `prepend()` puts a middleware in FRONT of whatever is already
         | there, so the LAST prepend runs FIRST. Three of these four
         | comments claimed the outermost position for their own middleware -
         | the correlation id said "FIRST, before anything that might log",
         | the security headers said "prepended, so it is the LAST middleware
         | the response passes on the way out" - and the one that happened to
         | be written last won. Measured off the kernel:
         |
         |     SecurityHeaders, SetLocale, AssignCorrelationId, TraceRequest
         |
         | So the correlation id ran THIRD. Two consequences, both real. A
         | failure inside `SecurityHeaders` or `SetLocale` answered with no
         | `X-Correlation-Id` header at all - and `docs/operations/
         | observability.md` promises it "including on an error response -
         | the error page is exactly when somebody needs it". And
         | `AssignCorrelationId`'s `finally` never ran for such a request, so
         | under Octane or a long-lived worker the id leaked into the next
         | one: two unrelated requests joined in the logs, which is worse
         | than no id, because it reads as causation.
         |
         | ## The order, outermost first, and why each is where it is
         |
         | 1. `AssignCorrelationId`. Outermost because every other entry on
         |    this list can fail and every failure is logged. It is also the
         |    cheapest thing here - adopt a header or generate an id - so
         |    there is least to go wrong ahead of the ones that can.
         |
         | 2. `SecurityHeaders`. Second, which still makes it the last thing
         |    a response passes on the way out of everything that can
         |    produce one: the exception handler renders inside the unwinding
         |    stack, so a 404, a 500 and `/workspace-unavailable` all pass
         |    back through it. It gives up only the headers on a response
         |    produced by a failure in step 1.
         |
         | 3. `TraceRequest`. Inside the correlation id, because the trace id
         |    IS the correlation id: a span opened outside it would take
         |    whatever the previous request left bound.
         |
         | 4. `SetLocale`. Innermost of the four, and globally it runs before
         |    routing so a 404 still answers in the right language. It runs
         |    again in the web group with the session available.
         |
         | The calls below are therefore in REVERSE of that list. An
         | architecture check asserts the resolved order rather than trusting
         | this comment, because the comment is what was wrong before.
         */
        $middleware->prepend(SetLocale::class);

        $middleware->prepend(TraceRequest::class);

        $middleware->prepend(SecurityHeaders::class);

        $middleware->prepend(AssignCorrelationId::class);

        $middleware->web(append: [
            SetLocale::class,
            /*
             | The tenant's digit shape - PF-202, and the WEB group is the
             | whole point of where this line is.
             |
             | It pushes the tenant's `display.digits` setting into the one
             | place that knows how to ask ICU for a numbering system, and the
             | group it is registered in is what keeps the shaping to display:
             | the api group never runs it, a queued job never runs it, so a
             | serializer, an export and an API response stay Western whatever
             | a tenant chose. Registering it globally would undo that in one
             | line and the symptom would be an XML payload a government
             | endpoint rejects.
             */
            ApplyDisplayDigits::class,
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

        /*
        | The session policy runs in the web group, after the guard has had a
        | chance to resolve a user - it has nothing to enforce against an
        | anonymous request, and running it earlier would mean checking an
        | absolute timeout on a session with no authentication in it.
        */
        $middleware->web(append: [EnforceSessionPolicy::class]);

        /*
        | And the one that explains a naked page - PF-244.
        |
        | Last in the group, because it reads the response rather than the
        | request: it appends a banner to an html page that was rendered
        | without a stylesheet, naming which of the three causes applies -
        | a left-behind `public/hot`, no build, or an APP_URL that is not the
        | address the browser is at.
        |
        | Registered unconditionally and inert in production, where the same
        | three are refused at boot by `ProductionConfiguration` instead. One
        | middleware list rather than a list that differs by environment,
        | which is the kind of difference that makes a production-only bug
        | impossible to reproduce.
        */
        $middleware->web(append: [SayWhyThePageIsUnstyled::class]);

        /*
        | The API group: language first, and nothing else globally.
        |
        | `NegotiateApiLanguage` is appended to the group rather than named on
        | every route, because it has to apply to the FAILURES as well - a 401
        | from the guard and a 400 from the tenant resolver are both rendered
        | before any route middleware runs, and a problem document in the
        | wrong language is exactly what PF-219 asks us to avoid.
        |
        | It is in the group and not global because the inversion it performs
        | - Accept-Language outranking the tenant default - is right for a
        | machine and wrong for a browser. SetLocale explains the browser
        | ordering; this one explains why the API differs.
        */
        $middleware->api(prepend: [
            NegotiateApiLanguage::class,
        ]);

        $middleware->alias([
            'tenant.session' => ResolveTenantFromSession::class,
            'tenant.header' => ResolveTenantFromHeader::class,
            'tenant.path' => ResolveTenantFromPath::class,
            'entity.scope' => ResolveEntity::class,
            'plan' => EnsurePlanAllows::class,
            'step-up' => RequireStepUp::class,

            /*
            | The API's own four. Each one is named on the routes that need
            | it rather than applied to the group, because which routes need
            | which is a decision worth seeing in a route listing - and
            | `api.idempotent` on everything would train clients to generate
            | a key per call, which is the same as not having one.
            */
            'api.actor' => EstablishApiActor::class,
            'api.entity' => ResolveApiEntity::class,
            'api.scope' => RequireTokenScope::class,
            'api.idempotent' => RequireIdempotency::class,
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

        /*
        | LAST, and it catches everything else - PF-219.
        |
        | Registered after every specific renderer above, because
        | `renderViaCallbacks` takes the first that answers and this one
        | answers for any Throwable at all.
        |
        | Without it, an unhandled exception on the API answers with Laravel's
        | own JSON - `{"message": …, "exception": …, "trace": […]}` - which is
        | not `application/problem+json`, carries no `type` a client can
        | switch on, and in production is a bare `{"message": "Server Error"}`.
        | So the one response an integrator most needs to recognise would be
        | the only one not in the contract.
        |
        | An exception that renders itself is already handled: Laravel calls
        | `$e->render()` before any callback, so everything reaching here is
        | something nobody anticipated - which is exactly what a 500 means.
        |
        | In debug mode the class and message ride along as an extension. Not
        | in production: a problem document goes to the client, and an
        | exception message in this product can carry a bound value or an
        | account number - see config/telemetry.php and PF-014.
        */
        $exceptions->render(static function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $problem = Problem::of(ProblemType::ServerError);

            if (config('app.debug') === true) {
                $problem->withExtensions(['debug' => $e::class.': '.$e->getMessage()]);
            }

            return $problem->toResponse($request);
        });

        $exceptions->context(static fn (): array => array_filter([
            'tenant_id' => app()->bound('currentTenant') ? app('currentTenant')->getKey() : null,
            'entity_id' => app()->bound('currentEntity') ? app('currentEntity')->getKey() : null,
        ]));
    })
    ->create();
