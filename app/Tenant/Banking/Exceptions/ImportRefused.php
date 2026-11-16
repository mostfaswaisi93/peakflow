<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Exceptions;

/**
 * A statement import the system will not accept - PF-147, PF-149.
 *
 * ## The messages are long on purpose
 *
 * A refused import is somebody holding a file they just downloaded from
 * their bank, being told no. The question they have is not "what rule did I
 * break" but "what do I do with this file now" - and a message that answers
 * only the first sends them to turn the rule off.
 *
 * So each message names the gap in figures, says what usually causes it, and
 * says what to do instead. The continuity refusal in particular has to
 * mention that a genuine bank-side gap is recorded deliberately rather than
 * by disabling the check, because the person facing it has a real gap and
 * the only route they can see is the configuration flag.
 */
final class ImportRefused extends \RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }
}
