<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Exceptions;

use RuntimeException;

/**
 * A payment this system will not record - PF-166.
 *
 * Every message says what was wrong and what to do instead. A refusal a
 * person cannot act on is a refusal they route around, and the ways
 * around this one all end with money recorded somewhere it is not.
 */
final class PaymentRefused extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
