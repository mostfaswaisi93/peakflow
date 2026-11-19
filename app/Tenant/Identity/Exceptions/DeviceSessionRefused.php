<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A device sign-in, second factor or refresh that did not succeed - PF-260.
 *
 * The message is the precise reason, for the log. The client is told the
 * registry's sentence for the TYPE and nothing finer: a sign-in that says
 * "no such user" where it could have said "wrong password" has answered a
 * question nobody should be able to ask it.
 */
final class DeviceSessionRefused extends RuntimeException
{
    private function __construct(
        public readonly ProblemType $problemType,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /** Any password-stage or code-stage failure. One answer for all of them. */
    public static function signIn(string $because): self
    {
        return new self(ProblemType::SignInRefused, $because);
    }

    /** A refresh token that cannot be exchanged - unknown, spent, revoked or expired. */
    public static function refresh(string $because): self
    {
        return new self(ProblemType::Unauthenticated, $because);
    }

    /** Too many attempts, per account or per address. */
    public static function throttled(int $retryAfter): self
    {
        return new self(
            ProblemType::RateLimited,
            sprintf('Too many sign-in attempts; retry in %d seconds.', $retryAfter),
            max(1, $retryAfter),
        );
    }

    /**
     * The person was authenticated and holds a role in no company they could
     * act in - or asked for one they hold nothing in.
     */
    public static function noEntity(string $because): self
    {
        return new self(ProblemType::EntityNotYours, $because);
    }

    public function render(Request $request): Response
    {
        $problem = Problem::of($this->problemType);

        if ($this->retryAfter !== null) {
            $problem->withRetryAfter($this->retryAfter);
        }

        /*
         | `no-store` on every answer from these endpoints, refusals included.
         |
         | A refusal carries no credential, but a cache that learned to store
         | this path's responses would store the next successful one too, and
         | that one does.
         */
        return $problem->withHeaders(['Cache-Control' => 'no-store'])->toResponse($request);
    }
}
