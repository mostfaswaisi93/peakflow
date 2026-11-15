<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Telemetry\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One id per request, returned to the caller - PF-014.
 *
 * ## It goes out on the response as well as into the logs
 *
 * Which is the half that gets left out. A customer reporting "the invoice
 * screen errored at 14:32" is describing a time zone and a minute; a customer
 * quoting the correlation id from the error page is describing one request.
 *
 * So the header is set on the way out, including on an error response - the
 * error page is exactly when somebody needs the id.
 *
 * ## And forgotten afterwards
 *
 * Octane and `queue:work` keep the container between requests, so an id left
 * bound is an id the NEXT request adopts - and two requests sharing a
 * correlation id is worse than neither having one, because it reads as
 * causation.
 */
final class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = CorrelationId::current();

        /*
         | Put back on the REQUEST too.
         |
         | Anything reading the header later - the audit log does - then sees
         | the sanitised, generated id rather than nothing, so the audit
         | trail and the log lines carry the same value.
         */
        $request->headers->set(CorrelationId::HEADER, $id);

        try {
            $response = $next($request);

            $response->headers->set(CorrelationId::HEADER, $id);

            return $response;
        } finally {
            CorrelationId::forget();
        }
    }
}
