<?php

declare(strict_types=1);

namespace App\Support\Http\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write that will not be executed as asked - PF-220.
 *
 * ## Three refusals, three different client fixes
 *
 * `keyRequired` is a client that has not implemented idempotency. The fix is
 * to generate a key per logical write and keep it across retries.
 *
 * `bodyDiffers` is a client that reused a key for a different write. The fix
 * is in the client's key generation, and the reason this is a 409 rather than
 * a replay of the first response is that a replay would hide it: the second
 * invoice would simply never be created, the client would record the first
 * one's id against it, and the discrepancy would surface as a missing
 * document weeks later.
 *
 * `stillRunning` is a correct client retrying too soon. The fix is to wait,
 * which is why this one - and only this one - carries `Retry-After`.
 */
final class IdempotencyRefused extends RuntimeException
{
    private function __construct(
        public readonly ProblemType $problemType,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly ?string $key = null,
    ) {
        parent::__construct($message);
    }

    public static function keyRequired(string $reason): self
    {
        return new self(ProblemType::IdempotencyKeyRequired, $reason);
    }

    public static function bodyDiffers(string $key): self
    {
        return new self(
            ProblemType::IdempotencyKeyConflict,
            sprintf(
                'The key "%s" was already used for a different request. Returning the first response would '.
                'hide a client bug: the second write would never happen, its id would be recorded against '.
                'the first document, and the discrepancy would surface weeks later as a document nobody '.
                'can find. Use a new key, or send the original request again unchanged.',
                $key,
            ),
            key: $key,
        );
    }

    public static function stillRunning(string $key, int $retryAfter): self
    {
        return new self(
            ProblemType::IdempotencyKeyInFlight,
            sprintf(
                'The first request with key "%s" is still running and has not committed. Executing this one '.
                'alongside it would post the same document twice, so it is refused rather than queued. '.
                'Retry after %d second(s); the answer will then be the first request\'s own response.',
                $key,
                $retryAfter,
            ),
            $retryAfter,
            $key,
        );
    }

    public function render(Request $request): ?Response
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        /*
         | The exception's own message is NOT the problem detail.
         |
         | It is written for the log, in English, at length, and it names the
         | reasoning. `detail` is written for the person who will read the
         | failure, in the language they asked for, and the registry already
         | holds that sentence for every type. Passing the English message
         | through was how this response stopped being localized while
         | remaining perfectly readable - to the one reader who asked for the
         | other language.
         |
         | The specifics go in extensions instead, where a client can read
         | them without parsing prose that is Arabic half the time.
         */
        $problem = Problem::of($this->problemType);

        if ($this->key !== null) {
            $problem->withExtensions(['idempotency_key' => $this->key]);
        }

        if ($this->retryAfter !== null) {
            $problem->withRetryAfter($this->retryAfter);
        }

        return $problem->toResponse($request);
    }
}
