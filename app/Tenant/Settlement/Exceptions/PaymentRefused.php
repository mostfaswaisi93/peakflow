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

    /**
     * A run with duplicate warnings nobody has accepted - PF-180.
     *
     * Blocked rather than warned-and-released. The whole value of the
     * detection is that somebody looks before the money leaves: a
     * warning that did not block would be read after the supplier was
     * paid twice, which is the moment it stops being useful.
     *
     * The refusal lists what was found rather than a count. "Three
     * warnings" sends somebody to a screen; "entity ACME is paying the
     * same party 4,200.00 in run RUN-2026/00014, four days apart" is
     * the sentence they can act on.
     *
     * @param  list<string>  $details
     */
    public static function aRunWithUnacceptedDuplicates(string $number, array $details): self
    {
        return new self(sprintf(
            "Run %s has %d duplicate warning(s) nobody has accepted, so it cannot be released yet. "
            ."Each one is usually a duplicate and sometimes a retainer or two identical deliveries, "
            ."and the difference is knowledge nobody can put in a rule - so somebody says which, and "
            ."signs for it.\n\n%s",
            $number,
            \count($details),
            implode("\n", array_map(static fn (string $d): string => '- '.$d, $details)),
        ));
    }
}
