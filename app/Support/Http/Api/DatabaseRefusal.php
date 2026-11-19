<?php

declare(strict_types=1);

namespace App\Support\Http\Api;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Database\QueryException;

/**
 * A rule the DATABASE refused, said to the client in the database's words.
 *
 * The ledger's invariants are held twice: by the services, and again by
 * triggers that raise `check_violation` with a sentence written for a person -
 * "this document has 4829.0000 left on it and this would allocate 999999.0000".
 * When the service did not catch the case first, the trigger's refusal
 * reaches the API as a QueryException, and without this it answers 500: a
 * failure on OUR side, for a request that was simply not allowed.
 *
 * Only `23514` (check_violation) and `P0001` (a bare RAISE) are translated,
 * and only their first line - the sentence - is passed on. The SQL, the
 * connection details and the CONTEXT trace stay in the log, where the
 * correlation id finds them.
 */
final class DatabaseRefusal
{
    private const SPEAKING_STATES = ['23514', 'P0001'];

    /** The refusal as a 422, or null when this is a real failure and should stay a 500. */
    public static function problem(QueryException $e): ?Problem
    {
        $state = $e->errorInfo[0] ?? $e->getCode();
        $state = \is_string($state) ? $state : (\is_int($state) ? (string) $state : '');

        if (! \in_array($state, self::SPEAKING_STATES, true)) {
            return null;
        }

        $message = $e->errorInfo[2] ?? $e->getMessage();
        $message = \is_string($message) ? $message : '';

        // "ERROR:  <sentence>\nCONTEXT: …" - the sentence, and nothing after it.
        if (preg_match('/ERROR:\s+(.+?)(?:\n|\\\\n|$)/s', $message, $m) === 1) {
            $message = $m[1];
        }

        $message = trim(strtok($message, "\n") ?: $message);

        return Problem::of(ProblemType::ValidationFailed)->withDetail($message);
    }
}
