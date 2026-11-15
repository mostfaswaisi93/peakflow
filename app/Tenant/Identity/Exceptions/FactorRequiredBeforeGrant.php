<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refused: granting a money-moving role to a user with no second factor.
 *
 * Addressed to the administrator doing the granting, not to the user, because
 * they are the person reading it and the person who can act on it.
 */
final class FactorRequiredBeforeGrant extends DomainException
{
    /** @param list<string> $permissions */
    private function __construct(
        string $message,
        public readonly string $roleKey,
        public readonly array $permissions,
    ) {
        parent::__construct($message);
    }

    /** @param list<string> $permissions */
    public static function forRole(Role $role, User $user, array $permissions): self
    {
        return new self(
            sprintf(
                '%s has no second factor, so the "%s" role cannot be granted yet - it includes %s, which can '.
                'move money or conceal that money moved. Ask them to set up an authenticator app first, then '.
                'grant the role. Refusing now rather than at the moment they try to use it means they do not '.
                'discover this during a payment run.',
                $user->name,
                $role->name(),
                implode(', ', array_slice($permissions, 0, 4)).(count($permissions) > 4 ? ', …' : ''),
            ),
            $role->key,
            $permissions,
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::MfaEnrolmentRequired)
            ->withDetail($this->getMessage())
            ->withExtensions([
                'role' => $this->roleKey,
                'permissions_requiring_a_factor' => $this->permissions,
            ])
            ->toResponse($request);
    }
}
