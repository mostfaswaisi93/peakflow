<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Models\Authenticator;
use App\Tenant\Identity\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The second stage of a device sign-in - PF-260.
 *
 * The browser keeps the pending sign-in in its session ({@see SecondFactorChallenge}).
 * An app has no session, so the pending state travels with the client instead:
 * an encrypted, expiring challenge naming the person, the workspace and the
 * device, handed back with the code.
 *
 * ## Why encrypted and not merely signed
 *
 * It names a user id, and the password stage must not reveal which accounts
 * exist. A signed payload is readable; an encrypted one says nothing to
 * anybody without the application key.
 *
 * ## Spent once
 *
 * The challenge carries a nonce, and a successful completion spends it. One
 * accepted code opens one session - the same rule the browser's challenge
 * keeps by forgetting itself. A WRONG code does not spend it, so a person who
 * mistypes does not have to type their password again; the sign-in throttle,
 * keyed on the account the challenge names, is what bounds the guessing.
 */
final class ApiSignInChallenge
{
    private const PREFIX = 'pfch_';

    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array{challenge: string, expires_at: Carbon}
     */
    public function begin(User $user, string $tenantId, string $deviceName, ?string $entityId): array
    {
        $expiresAt = now()->addSeconds(Config::integer('api.sessions.challenge_seconds', 300));

        $payload = json_encode([
            'u' => $user->id,
            't' => $tenantId,
            'd' => $deviceName,
            'e' => $entityId,
            'x' => $expiresAt->getTimestamp(),
            'n' => Str::random(40),
        ], JSON_THROW_ON_ERROR);

        return [
            'challenge' => self::PREFIX.Crypt::encryptString($payload),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * The person a challenge names, if it is still usable - without spending it.
     *
     * For the throttle, which has to know whose budget a code attempt is
     * charged to before the code is checked.
     */
    public function pendingUser(string $challenge, string $tenantId): ?User
    {
        $payload = $this->open($challenge, $tenantId);

        return $payload === null ? null : $this->user($payload['u']);
    }

    /**
     * Accept a TOTP code or a recovery code against a challenge.
     *
     * Null for every failure - expired, tampered, spent, another workspace's,
     * a wrong code - and the reason goes to the audit log, for the same reason
     * the browser gives one sentence for all of them.
     *
     * @return array{user: User, factor: string, device_name: string, entity_id: ?string}|null
     */
    public function complete(string $challenge, string $tenantId, string $code): ?array
    {
        $payload = $this->open($challenge, $tenantId);
        $user = $payload === null ? null : $this->user($payload['u']);

        if ($payload === null || ! $user instanceof User) {
            $this->audit->refusal(
                category: 'identity',
                action: 'device_session.factor_refused',
                subjectLabel: 'second factor',
                reason: 'a code was submitted with a challenge that is expired, tampered with, already used, '.
                    'or from another workspace',
            );

            return null;
        }

        $factor = null;
        $authenticator = $this->mfa->verifyCode($user, $code);

        if ($authenticator instanceof Authenticator) {
            $factor = \is_string($authenticator->type) ? $authenticator->type : 'totp';
        } elseif ($this->mfa->consumeRecoveryCode($user, $code)) {
            // Second, and deliberately - see SecondFactorChallenge: trying the
            // recovery codes first would bcrypt every mistyped TOTP code ten
            // times before refusing it.
            $factor = 'recovery_code';
        }

        if ($factor === null) {
            $this->audit->refusal(
                category: 'identity',
                action: 'device_session.factor_refused',
                subjectType: User::class,
                subjectId: $user->id,
                subjectLabel: $user->name,
                reason: 'neither a code from a confirmed authenticator nor an unused recovery code matched; '.
                    'the challenge is still open until it expires',
                actor: $user,
            );

            return null;
        }

        /*
         | Spent now, and atomically. `add` succeeds for exactly one caller, so
         | two requests racing with two valid codes for the same challenge
         | open one session between them, not two.
         */
        $ttl = max(60, (int) $payload['x'] - now()->getTimestamp() + 60);

        if (! Cache::add('api-sign-in-challenge:'.$payload['n'], true, $ttl)) {
            $this->audit->refusal(
                category: 'identity',
                action: 'device_session.factor_refused',
                subjectType: User::class,
                subjectId: $user->id,
                subjectLabel: $user->name,
                reason: 'a challenge that had already opened a session was presented again',
                actor: $user,
            );

            return null;
        }

        return [
            'user' => $user,
            'factor' => $factor,
            'device_name' => (string) $payload['d'],
            'entity_id' => isset($payload['e']) && \is_string($payload['e']) ? $payload['e'] : null,
        ];
    }

    /**
     * @return array{u: string, t: string, d: string, e: ?string, x: int, n: string}|null
     */
    private function open(string $challenge, string $tenantId): ?array
    {
        if (! str_starts_with($challenge, self::PREFIX)) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString(substr($challenge, \strlen(self::PREFIX))), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! \is_array($decoded)
            || ! \is_string($decoded['u'] ?? null)
            || ! \is_string($decoded['t'] ?? null)
            || ! \is_string($decoded['d'] ?? null)
            || ! \is_int($decoded['x'] ?? null)
            || ! \is_string($decoded['n'] ?? null)) {
            return null;
        }

        if (! hash_equals($decoded['t'], $tenantId) || $decoded['x'] < now()->getTimestamp()) {
            return null;
        }

        if (Cache::has('api-sign-in-challenge:'.$decoded['n'])) {
            return null;
        }

        /** @var array{u: string, t: string, d: string, e: ?string, x: int, n: string} $decoded */
        return $decoded;
    }

    private function user(string $id): ?User
    {
        $user = User::query()->find($id);

        return $user instanceof User && $user->active ? $user : null;
    }
}
