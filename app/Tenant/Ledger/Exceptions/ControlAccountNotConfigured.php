<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Ledger\Enums\ControlRole;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A rule asked for a control account this entity has not assigned.
 *
 * Refused rather than skipped. A rule that silently omits a receivable line
 * produces an entry that still balances - and from then on the subledger and
 * the ledger disagree by exactly the documents that went through while the
 * role was missing, with nothing recording that it happened.
 */
final class ControlAccountNotConfigured extends DomainException
{
    private function __construct(string $message, public readonly string $role)
    {
        parent::__construct($message);
    }

    /** A VAT role asked for without naming an authority - PF-084. */
    public static function needsAJurisdiction(ControlRole $role): self
    {
        return new self(
            sprintf(
                'The "%s" role is per jurisdiction, and this posting did not say which. Not defaulted to the '.
                'entity\'s country: an entity registered in two jurisdictions would post one authority\'s VAT '.
                'to the other\'s account, and the figure would look plausible in both returns while neither '.
                'reconciled.',
                $role->value,
            ),
            $role->value,
        );
    }

    /** A role that has no jurisdiction asked for with one - PF-084. */
    public static function hasNoJurisdiction(ControlRole $role, string $jurisdiction): self
    {
        return new self(
            sprintf(
                'The "%s" role was asked for in %s, and there is one of these per entity rather than per '.
                'jurisdiction. A bank account is a bank account wherever the entity is registered, and a '.
                'jurisdiction here is a value the next reader has to work out the meaning of.',
                $role->value,
                $jurisdiction,
            ),
            $role->value,
        );
    }

    /** The role exists, but not for that authority - PF-084. */
    public static function forRoleIn(ControlRole $role, string $jurisdiction): self
    {
        return new self(
            sprintf(
                'This entity has no "%s" account for %s. It may well have one for another jurisdiction, and '.
                'posting to that would put one authority\'s VAT in another\'s return. Assign the account for '.
                '%s before posting anything taxed there.',
                $role->value,
                $jurisdiction,
                $jurisdiction,
            ),
            $role->value,
        );
    }

    public static function forRole(ControlRole $role): self
    {
        return new self(
            sprintf(
                'This entity has no account assigned to the "%s" role, so nothing can be posted that needs one. '.
                'Assign it in the chart of accounts before posting; skipping the line instead would leave an '.
                'entry that balances without recording the %s, and the subledger would disagree with the ledger '.
                'from that moment on.',
                $role->value,
                str_replace('_', ' ', $role->value),
            ),
            $role->value,
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::ControlAccountNotPostable)
            ->withDetail($this->getMessage())
            ->withExtensions(['role' => $this->role])
            ->toResponse($request);
    }
}
