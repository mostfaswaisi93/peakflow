<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use RuntimeException;

/**
 * Why a budget was not written - PF-193.
 *
 * A budget is what a department is held to, so a line nobody can compare
 * to an actual is worse than a missing one: it reads on the variance
 * report as money that was allocated and never spent, for ever, and
 * nothing on the page says the account or the cost centre it names does
 * not exist.
 */
final class BudgetRefused extends RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }
}
