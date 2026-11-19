<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Platform\Models\Tenant;
use App\Support\Http\Api\Wire;
use App\Tenant\Identity\Services\DeviceSessions;
use App\Tenant\Identity\Services\IssuedSession;

/**
 * A device session on the wire - PF-260.
 *
 * In the application layer because it names the TENANT, which is a platform
 * record: the app needs the tenant id to send as `X-Tenant` on every later
 * request, and the person typed a slug, not an id.
 */
final class DeviceSessionPresenter
{
    /** @return array<string, mixed> */
    public static function session(Tenant $tenant, IssuedSession $issued, DeviceSessions $sessions): array
    {
        return [
            'resource' => 'device_session',
            'id' => $issued->sessionId,
            'token_type' => 'Bearer',
            'access_token' => $issued->accessToken,
            'access_token_expires_at' => Wire::timestamp($issued->accessTokenExpiresAt),
            'refresh_token' => $issued->refreshToken,
            'refresh_token_expires_at' => Wire::timestamp($issued->refreshTokenExpiresAt),
            'session_started_at' => Wire::timestamp($issued->sessionStartedAt),
            'second_factor' => $issued->secondFactor,
            'tenant' => [
                'id' => (string) $tenant->getKey(),
                'slug' => (string) $tenant->slug,
                'name' => (string) $tenant->name,
            ],
            'entity_id' => $issued->entityId,
            'me' => $sessions->describe($issued->user, $issued->entityId, $issued->scopes),
        ];
    }
}
