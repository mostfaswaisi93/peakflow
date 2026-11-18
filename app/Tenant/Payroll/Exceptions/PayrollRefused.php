<?php

declare(strict_types=1);

namespace App\Tenant\Payroll\Exceptions;

use RuntimeException;

/**
 * Payroll that cannot be read, run or posted - PF-256.
 */
final class PayrollRefused extends RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }

    public static function notPermittedToSee(): self
    {
        return new self(
            'Seeing a salary needs `payroll.view`. The figures are not hidden on a screen - the rows '.
            'themselves are invisible to a session without it, so a report, an export and a query all '.
            'see the same empty set.',
        );
    }

    public static function aReadWithNoReason(): self
    {
        return new self(
            'Reading payroll is recorded with the reason it was read FOR - a run, a query from an '.
            'employee, an audit request. The question an investigation asks is not "was it read" but '.
            '"why", and a reason nobody typed is a question nobody can answer.',
        );
    }

    /**
     * A claim outside a transaction would outlive the work it was for.
     *
     * `set_config(..., true)` is transaction-local, and a claim made
     * without one would be held by whatever ran next on the same
     * connection - the following request under a pooled connection, the
     * next job in a worker. That is how a permission checked once
     * becomes a permission held forever.
     */
    public static function aClaimOutsideATransaction(): self
    {
        return new self(
            'Payroll visibility is claimed inside a transaction, because it is released with it. '.
            'Claimed outside one it would be held by whatever runs next on this connection - the next '.
            'request, the next job - and nothing about that looks wrong from the outside.',
        );
    }

    public static function aScheduleThatIsNotActive(string $reference, string $state): self
    {
        return new self(sprintf(
            'Salary schedule %s is %s. Only an approved, active schedule pays anybody - a draft is a '.
            'figure somebody is still typing.',
            $reference,
            $state,
        ));
    }

    public static function anEmployeeWhoIsNotOne(string $partyId): self
    {
        return new self(sprintf(
            'Party %s is not marked as an employee. A salary paid to a party nobody marked as one is '.
            'how a supplier ends up on the payroll, and the figure is invisible to everybody who is '.
            'not permitted to see salaries.',
            $partyId,
        ));
    }
}
