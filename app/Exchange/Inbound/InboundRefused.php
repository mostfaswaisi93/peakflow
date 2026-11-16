<?php

declare(strict_types=1);

namespace App\Exchange\Inbound;

/**
 * A document, or an action on one, the inbound path will not accept - PF-140,
 * PF-141.
 *
 * Distinct from a *rejection*, which is a decision about a supplier's
 * document and is recorded on the row with a report and answered with the
 * standard's own response. This is a refusal to perform an operation - an
 * empty delivery, a supplier assigned with no reason, a document that is not
 * in a state the operation applies to - and nobody outside is told about it.
 */
final class InboundRefused extends \RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }
}
