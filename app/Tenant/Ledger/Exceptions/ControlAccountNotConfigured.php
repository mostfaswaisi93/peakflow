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
