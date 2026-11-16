<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Exceptions;

/**
 * A match the engine or the ledger will not accept - PF-156 to PF-161.
 *
 * The refusals here are mostly about time passing: a proposal made this
 * morning against an invoice somebody settled this afternoon, a decision
 * made twice in two tabs. Each message says what changed rather than that
 * something did, because the next step differs - re-run the matching, or
 * look at who decided it first.
 */
final class MatchRefused extends \RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }
}
