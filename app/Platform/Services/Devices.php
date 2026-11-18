<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\Exceptions\DeviceRefused;
use App\Platform\Models\Device;
use App\Platform\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Registering a device, and taking it away again - PF-255.
 *
 * ## The whole risk is a till that keeps working after it is stolen
 *
 * Everything here follows from that sentence.
 *
 * The secret is returned ONCE, by {@see register()}, and never stored -
 * only `sha256` of it. A dump of the `devices` table does not let
 * anybody sell anything. There is deliberately no "show me the token
 * again": a product that could show it has stored it, and a product
 * that has stored it has it in its backups.
 *
 * {@see resolve()} is the hot path - every request a till makes - and it
 * checks the revocation BEFORE the tenant connection is opened. A
 * revoked device gets a refusal that names the revocation and its
 * reason, rather than a 500 or a silence, because the till is held by a
 * person who has to decide whether to telephone somebody.
 *
 * ## Why sha256 and not bcrypt
 *
 * This is verified on every request a shop makes. A work factor in the
 * tens of milliseconds is correct for a password a human chose and
 * wrong here: the token has no user-chosen part, it is 32 bytes of
 * randomness, and there is nothing to brute force. What bcrypt buys is
 * protection against a weak secret, and there is no weak secret to
 * protect.
 */
final class Devices
{
    /**
     * Register a device and hand back its token, once.
     *
     * @return array{device: Device, token: string, why: string}
     *
     * @throws DeviceRefused
     */
    public function register(
        Tenant $tenant,
        string $name,
        string $kind,
        string $operatorId,
    ): array {
        $name = trim($name);

        if (mb_strlen($name) < 3) {
            throw DeviceRefused::because(
                'A device needs a name somebody will recognise - "Till 2, front counter" is what is '.
                'read when deciding whether to revoke it at nine on a Saturday evening, and a list of '.
                'ids is a list nobody can act on.',
            );
        }

        if (! \in_array($kind, ['till', 'handheld', 'phone', 'tablet', 'integration'], true)) {
            throw DeviceRefused::anUnknownKind($kind);
        }

        if ($tenant->provisioned_at === null) {
            throw DeviceRefused::because(sprintf(
                'Tenant %s is not provisioned yet, so a token for it would authenticate against a '.
                'database that is not finished.',
                (string) $tenant->slug,
            ));
        }

        /*
         | 32 bytes of randomness, shown once.
         |
         | Prefixed so that a token found in a log or a support ticket is
         | recognisable as one - which is what lets it be revoked rather
         | than wondered about.
         */
        $secret = 'pfd_'.Str::random(43);

        $device = Device::create([
            'tenant_id' => (string) $tenant->getKey(),
            'name' => $name,
            'kind' => $kind,
            'token_hash' => hash('sha256', $secret),
            'token_hint' => mb_substr($secret, -8),
            'registered_at' => Carbon::now(),
            'registered_by' => $operatorId,
        ]);

        return [
            'device' => $device,
            'token' => $secret,
            'why' => sprintf(
                'Registered "%s" for %s. The token is shown now and never again - it is stored as a '.
                'hash, so a dump of the table cannot sell anything. If it is lost, revoke this device '.
                'and register another.',
                $name,
                (string) $tenant->slug,
            ),
        ];
    }

    /**
     * Which tenant this token belongs to, if it still does.
     *
     * The hot path. Checked before any tenant connection is opened,
     * because the point of scoping a token to one tenant is that it
     * cannot reach a second one even if a route is wrong.
     *
     * @throws DeviceRefused
     */
    public function resolve(string $token, ?string $ip = null, ?string $version = null): Device
    {
        $device = Device::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($device === null) {
            /*
             | One message for "no such token" and for a malformed one.
             |
             | A refusal that distinguished them would say whether a
             | guessed token exists, which is the only thing an attacker
             | with a list wants to know.
             */
            throw DeviceRefused::noSuchToken();
        }

        if ($device->isRevoked()) {
            throw DeviceRefused::revoked(
                (string) $device->name,
                (string) $device->revoked_at,
                (string) $device->revoked_reason,
            );
        }

        /*
         | Last seen, recorded without a transaction and without failing
         | the request if it cannot be written.
         |
         | It is diagnostic: "when did till 2 last reach us" is asked
         | when a shop says the totals are wrong. A failure to record it
         | must not stop a sale, which is why it is not in the same
         | breath as the resolution.
         */
        $device->forceFill([
            'last_seen_at' => Carbon::now(),
            'last_seen_ip' => $ip,
            'last_seen_version' => $version,
        ])->saveQuietly();

        return $device;
    }

    /** @throws DeviceRefused */
    public function revoke(Device $device, string $reason, string $operatorId): void
    {
        if ($device->isRevoked()) {
            throw DeviceRefused::because(sprintf(
                'Device "%s" was already revoked on %s.',
                (string) $device->name,
                (string) $device->revoked_at,
            ));
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw DeviceRefused::because(
                'A revocation needs its reason. "Sold the van" and "the till was stolen" are read a '.
                'month later by somebody deciding whether to re-register the device, and the '.
                'difference between them is the whole decision.',
            );
        }

        $device->forceFill([
            'revoked_at' => Carbon::now(),
            'revoked_by' => $operatorId,
            'revoked_reason' => trim($reason),
        ])->save();
    }

    /**
     * Every device a tenant has, with the state a person needs to act.
     *
     * @return list<array<string, mixed>>
     */
    public function forTenant(Tenant $tenant): array
    {
        return Device::query()
            ->where('tenant_id', (string) $tenant->getKey())
            ->orderByDesc('registered_at')
            ->get()
            ->map(static fn (Device $device): array => [
                'id' => (string) $device->getKey(),
                'name' => (string) $device->name,
                'kind' => (string) $device->kind,
                'token_ends' => (string) $device->token_hint,
                'last_seen_at' => $device->last_seen_at?->toDateTimeString(),
                'last_seen_version' => $device->last_seen_version,
                'revoked_at' => $device->revoked_at?->toDateTimeString(),
                'revoked_reason' => $device->revoked_reason,
            ])
            ->all();
    }
}
