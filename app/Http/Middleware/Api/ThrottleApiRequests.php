<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Platform\Models\Tenant;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests per minute, per tenant and per token - PF-221, conventions.md.
 *
 * ## Two buckets, and writes are the tighter one
 *
 * A read that runs away costs us a query; a write that runs away posts the
 * same document again and again, and every copy is a real row somebody has
 * to find and reverse. So a token has a bucket for each, and the write bucket
 * is the smaller.
 *
 * ## Keyed on the token, inside the tenant
 *
 * Not on the address: an integration behind a NAT shares an address with
 * every other one in the building, and a phone changes address every time it
 * leaves the house. The token is the thing that is one client.
 *
 * ## The headers are on every response, not only the refusal
 *
 * `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` on every
 * answer, so a well-behaved client slows down BEFORE it is refused. A limit
 * that is only visible in the 429 teaches clients to find it by hitting it.
 *
 * Runs after `api.actor`, which is what makes the token known.
 */
final class ThrottleApiRequests
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $write = ! \in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
        $limit = max(1, Config::integer($write ? 'api.rate_limits.writes_per_minute' : 'api.rate_limits.reads_per_minute', 60));
        $key = $this->bucket($request, $write);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            return Problem::of(ProblemType::RateLimited)
                ->withRetryAfter($retryAfter)
                ->withHeaders($this->headers($limit, 0, $retryAfter))
                ->toResponse($request);
        }

        RateLimiter::hit($key, 60);

        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers($limit, RateLimiter::remaining($key, $limit), max(0, RateLimiter::availableIn($key))) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function bucket(Request $request, bool $write): string
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        $who = $token instanceof ApiToken
            ? 'token:'.$token->id
            : 'address:'.hash('sha256', (string) $request->ip());

        $current = app()->bound('currentTenant') ? app('currentTenant') : null;
        $tenant = $current instanceof Tenant ? $current->id : 'none';

        return sprintf('api-rate:%s:%s:%s', $write ? 'write' : 'read', $tenant, $who);
    }

    /** @return array<string, string> */
    private function headers(int $limit, int $remaining, int $reset): array
    {
        return [
            'RateLimit-Limit' => (string) $limit,
            'RateLimit-Remaining' => (string) max(0, $remaining),
            'RateLimit-Reset' => (string) $reset,
        ];
    }
}
