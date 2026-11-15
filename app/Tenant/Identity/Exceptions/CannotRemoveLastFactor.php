<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refused: removing the last second factor of a user who needs one - PF-030.
 *
 * Without this refusal, the route around every MFA requirement in the system
 * is self-service: remove your own authenticator, and the permission that
 * demanded one has nothing left to demand.
 */
final class CannotRemoveLastFactor extends DomainException
{
    /** @param list<string> $permissions */
    private function __construct(string $message, public readonly array $permissions)
    {
        parent::__construct($message);
    }

    /** @param list<string> $permissions */
    public static function whileHolding(array $permissions): self
    {
        return new self(
            sprintf(
                'This is your only second factor, and you hold %d permission(s) that require one (%s). '.
                'Add another factor before removing this one. If you have lost the device, use a recovery code '.
                'to enrol a replacement, or ask an administrator to remove those permissions first - either way '.
                'the change is recorded, which simply deleting the factor would not be.',
                count($permissions),
                implode(', ', array_slice($permissions, 0, 5)).(count($permissions) > 5 ? ', …' : ''),
            ),
            $permissions,
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::MfaEnrolmentRequired)
            ->withDetail($this->getMessage())
            ->withExtensions(['permissions_requiring_a_factor' => $this->permissions])
            ->toResponse($request);
    }
}
