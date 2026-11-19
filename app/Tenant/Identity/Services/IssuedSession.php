<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Models\User;
use Illuminate\Support\Carbon;

/**
 * A device session's two credentials, with their only plaintext - PF-260.
 *
 * Returned once, by {@see DeviceSessions::open()} and
 * {@see DeviceSessions::refresh()}, and never reconstructable afterwards: the
 * database holds hashes of both.
 */
final readonly class IssuedSession
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $sessionId,
        public User $user,
        public string $entityId,
        public array $scopes,
        public string $accessToken,
        public Carbon $accessTokenExpiresAt,
        public string $refreshToken,
        public Carbon $refreshTokenExpiresAt,
        public Carbon $sessionStartedAt,
        public ?string $secondFactor,
    ) {}
}
