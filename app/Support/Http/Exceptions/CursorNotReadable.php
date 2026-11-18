<?php

declare(strict_types=1);

namespace App\Support\Http\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A pagination cursor could not be read - PF-221.
 *
 * ## Why both reasons answer the same way
 *
 * A malformed cursor and one whose signature does not verify are different
 * mistakes - a truncated query string against a hand-constructed page - and
 * the response says the same thing to both. Confirming "the shape was right,
 * the signature was wrong" tells somebody probing exactly how close they got,
 * and tells an honest integrator nothing they can act on: either way the
 * answer is to send back a cursor this API returned, unaltered.
 *
 * The distinction is kept in the message, which reaches the log and not the
 * client.
 */
final class CursorNotReadable extends RuntimeException
{
    public static function malformed(): self
    {
        return new self(
            'The cursor is not a cursor this API issued: it does not have the shape of one. A cursor is '.
            'opaque and must be sent back exactly as it was received.',
        );
    }

    public static function signatureDoesNotVerify(): self
    {
        return new self(
            'The cursor\'s signature does not verify for this listing. Either it was altered, or it came '.
            'from a different listing - a cursor carries the sort it belongs to, because the same opaque '.
            'string against a different sort would compare an account code to a date.',
        );
    }

    public function render(Request $request): ?Response
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return Problem::of(ProblemType::CursorNotReadable)->toResponse($request);
    }
}
