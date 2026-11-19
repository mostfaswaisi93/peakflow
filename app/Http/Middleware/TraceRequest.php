<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Telemetry\SpanKind;
use App\Support\Telemetry\Tracer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The first of the three spans a trace is made of - PF-240.
 *
 * ## Why the span is named `http.server` and the route is an attribute
 *
 * A span name has to be low cardinality - it is what a viewer groups by, and
 * a name holding a document id is a group of one, which makes "which request
 * is slow" unanswerable.
 *
 * The route TEMPLATE would be the ideal name and is not available when this
 * middleware runs: it is global, so it runs before routing, and the only
 * thing known at that point is the raw path - which is the high-cardinality
 * value the rule exists to keep out. The template is known on the way back,
 * so it goes on as `route`, which is the field a log line already uses for
 * the same thing.
 *
 * ## Global, and inside the correlation id
 *
 * Global because a 404 from an unmatched URL and a 500 from the exception
 * handler are requests too, and they are the ones somebody is looking for.
 * Inside `AssignCorrelationId` because the trace id IS the correlation id: a
 * span opened outside it would adopt whatever the previous request left, and
 * two requests sharing a trace id is worse than neither having one, because
 * it reads as causation.
 *
 * ## A 5xx is an error and a 4xx is not
 *
 * A validation failure is the application working. Recording it as an error
 * would mean the sampler kept every rejected form submission, and an error
 * rate that counts them is a number nobody can use.
 */
final class TraceRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $tracer = app(Tracer::class);

        $span = $tracer->start('http.server', SpanKind::Server, [
            'method' => $request->getMethod(),
        ]);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $tracer->finish($span, 'error', [
                'exception_class' => $e::class,
                'route' => $this->route($request),
            ]);

            throw $e;
        }

        $status = $response->getStatusCode();

        $tracer->finish($span, $status >= 500 ? 'error' : 'ok', [
            'status' => $status,
            'route' => $this->route($request),
        ]);

        return $response;
    }

    /**
     * The route's own template, never the path that was requested.
     *
     * `invoices/{invoice}` and not `invoices/01M7FW…`. An unmatched URL has
     * no template and reports `(unrouted)` rather than its path: a 404 from
     * a scanner probing `/wp-login.php` would otherwise put every URL on the
     * internet into this field.
     */
    private function route(Request $request): string
    {
        $route = $request->route();

        if ($route === null || ! method_exists($route, 'uri')) {
            return '(unrouted)';
        }

        return $request->getMethod().' /'.ltrim((string) $route->uri(), '/');
    }
}
