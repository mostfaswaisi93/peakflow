<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tenant could not be resolved, or may not be served.
 *
 * Each reason renders differently, because each has a different remedy:
 *
 *   missing      no tenant was identified at all        -> 400
 *   unknown      identified, and does not exist         -> 404, see below
 *   inactive     exists, not entitled to be served      -> 402
 *   migrating    exists, temporarily unavailable        -> 503 + Retry-After
 *
 * An unknown tenant is reported as NOT FOUND rather than as "no such tenant",
 * for the same reason a hidden record is: distinguishing them tells an
 * attacker which tenant identifiers are real.
 */
final class TenantNotResolvable extends RuntimeException
{
    private function __construct(
        public readonly ProblemType $problemType,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self(
            ProblemType::TenantRequired,
            'No tenant was identified on this request.',
        );
    }

    public static function unknown(): self
    {
        return new self(
            ProblemType::NotFound,
            'The requested workspace could not be found.',
        );
    }

    public static function inactive(string $reason): self
    {
        return new self(
            ProblemType::SubscriptionInactive,
            $reason,
        );
    }

    public static function migrating(int $retryAfter): self
    {
        return new self(
            ProblemType::TenantMigrating,
            'This workspace is being upgraded and will be available shortly.',
            $retryAfter,
        );
    }

    public function render(Request $request): ?Response
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        $problem = Problem::of($this->problemType)->withDetail($this->getMessage());

        if ($this->retryAfter !== null) {
            $problem->withExtensions(['retry_after' => $this->retryAfter]);
        }

        $response = $problem->toResponse($request);

        if ($this->retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $this->retryAfter);
        }

        return $response;
    }
}
