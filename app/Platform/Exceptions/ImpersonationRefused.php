<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use App\Platform\Models\Tenant;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refused: an operator tried to enter a tenant that has not consented.
 *
 * Addressed to the operator, who is staff, so it says plainly what is missing
 * and who can provide it - there is no reason to be vague with someone on the
 * inside.
 */
final class ImpersonationRefused extends DomainException
{
    public static function withoutConsent(Tenant $tenant): self
    {
        return new self(sprintf(
            'No usable impersonation consent exists for %s. A user of that workspace has to grant it, with a '.
            'reason and an expiry, before anyone here can enter. This is not a permission that can be raised '.
            'from this side - that is the point of it.',
            $tenant->name,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::Forbidden)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
