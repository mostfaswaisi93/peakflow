<?php

declare(strict_types=1);

namespace App\Support\Http\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carries a 5xx out of an idempotent transaction so the claim rolls back with
 * it - PF-220.
 *
 * A 500 is not an answer, it is the absence of one. Storing it would replay it
 * for 24 hours: the client could never succeed with that key, and the key is
 * the only thing it is permitted to retry with.
 *
 * The only way to leave nothing behind after a server failure is to throw, so
 * this exists to carry the response the handler already produced past the
 * rollback. A distinct class and not a generic exception, because catching a
 * RuntimeException there would also catch a real failure from inside the
 * handler and return it as a response the handler never produced.
 */
final class ServerFailureNotStored extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('The handler failed on our side, so nothing was stored for replay.');
    }
}
