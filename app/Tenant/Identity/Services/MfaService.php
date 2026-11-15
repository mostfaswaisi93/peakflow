<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Support\Mfa\Totp;
use App\Tenant\Identity\Exceptions\CannotRemoveLastFactor;
use App\Tenant\Identity\Models\Authenticator;
use App\Tenant\Identity\Models\RecoveryCode;
use App\Tenant\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Enrols and verifies second factors - PF-030.
 *
 * ## Enrolment is two steps, and the gap matters
 *
 * `beginTotpEnrolment()` creates an UNCONFIRMED authenticator and returns the
 * provisioning URI. `confirmTotpEnrolment()` requires a working code before
 * `confirmed_at` is set.
 *
 * Confirming on creation is the tempting shortcut and it locks people out: a
 * user who scans a QR code into an app whose clock is wrong, or who scans it
 * and then closes the app before saving, would have MFA enabled on an
 * authenticator that cannot produce an accepted code. The confirmation step
 * proves the loop works before anything depends on it.
 *
 * ## Replay is refused by a strictly increasing counter
 *
 * A TOTP code stays valid for its whole step, so a code read over someone's
 * shoulder - or out of a phishing proxy - can be used again seconds later.
 * The last accepted step is stored and anything at or below it is refused, so
 * each code works exactly once.
 */
final class MfaService
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @return array{authenticator: Authenticator, secret: string, uri: string}
     */
    public function beginTotpEnrolment(User $user, string $name = 'Authenticator app'): array
    {
        $secret = Totp::generateSecret();

        $authenticator = new Authenticator([
            'user_id' => $user->getKey(),
            'type' => Authenticator::TYPE_TOTP,
            'name' => $name,
        ]);

        $authenticator->setSecret($secret);
        $authenticator->save();

        $this->audit->record(
            category: 'identity',
            action: 'mfa.enrolment.started',
            subjectType: Authenticator::class,
            subjectId: (string) $authenticator->getKey(),
            subjectLabel: $name,
            after: ['type' => Authenticator::TYPE_TOTP],
            reason: 'the authenticator is not usable until a code from it is accepted',
            actor: $user,
        );

        return [
            'authenticator' => $authenticator,
            // Returned once, here, and never readable from the model again
            // without an explicit secret() call. The user has to save it now.
            'secret' => $secret,
            'uri' => Totp::provisioningUri(
                $secret,
                $user->email,
                (string) config('app.name', 'PeakFlow'),
            ),
        ];
    }

    public function confirmTotpEnrolment(Authenticator $authenticator, string $code): bool
    {
        if ($authenticator->isConfirmed()) {
            return true;
        }

        $secret = $authenticator->secret();

        if ($secret === null) {
            return false;
        }

        $step = Totp::verify($secret, $code);

        if ($step === null) {
            return false;
        }

        $authenticator->forceFill([
            'confirmed_at' => now(),
            'sign_count' => $step,
            'last_used_at' => now(),
        ])->save();

        $this->audit->record(
            category: 'identity',
            action: 'mfa.enrolment.confirmed',
            subjectType: Authenticator::class,
            subjectId: (string) $authenticator->getKey(),
            subjectLabel: (string) $authenticator->name,
            reason: 'a code from the authenticator was accepted, so it is now usable',
            actor: $authenticator->user,
        );

        return true;
    }

    /**
     * Verify a code from any of the user's confirmed authenticators.
     *
     * Returns the authenticator that accepted it, so the caller can record
     * WHICH factor was asserted - "a second factor was used" is weaker
     * evidence than "the passkey named 'work laptop' was used".
     */
    public function verifyCode(User $user, string $code): ?Authenticator
    {
        $authenticators = Authenticator::query()
            ->where('user_id', $user->getKey())
            ->where('type', Authenticator::TYPE_TOTP)
            ->whereNotNull('confirmed_at')
            ->get();

        foreach ($authenticators as $authenticator) {
            $secret = $authenticator->secret();

            if ($secret === null) {
                continue;
            }

            $step = Totp::verify($secret, $code);

            if ($step === null) {
                continue;
            }

            /*
             | Strictly greater. A code at or below the last accepted step is
             | a replay - the same code presented twice inside its 30-second
             | window, which is exactly what a phishing proxy relays.
             */
            if ($authenticator->sign_count !== null && $step <= $authenticator->sign_count) {
                $this->audit->refusal(
                    category: 'identity',
                    action: 'mfa.replay.refused',
                    subjectType: Authenticator::class,
                    subjectId: (string) $authenticator->getKey(),
                    subjectLabel: (string) $authenticator->name,
                    after: ['presented_step' => $step, 'last_accepted_step' => $authenticator->sign_count],
                    reason: 'the code was correct but had already been used; a correct code works exactly once',
                    actor: $user,
                );

                return null;
            }

            $authenticator->forceFill([
                'sign_count' => $step,
                'last_used_at' => now(),
            ])->save();

            return $authenticator;
        }

        return null;
    }

    /**
     * Replace the user's recovery codes and return the plaintext, once.
     *
     * The whole set is replaced rather than topped up. A user who has spent
     * three of ten has a problem that adding three more does not address, and
     * partial regeneration makes "how many are left" unanswerable.
     *
     * @return Collection<int, string>
     */
    public function regenerateRecoveryCodes(User $user, int $count = 10): Collection
    {
        $codes = collect(range(1, $count))->map(
            // Grouped for transcription. Someone reads these off a screen and
            // types them into a phone a month later.
            fn (): string => strtoupper(Str::random(5).'-'.Str::random(5)),
        );

        DB::connection('tenant')->transaction(function () use ($user, $codes): void {
            RecoveryCode::query()->where('user_id', $user->getKey())->delete();

            foreach ($codes as $code) {
                RecoveryCode::create([
                    'user_id' => $user->getKey(),
                    'code_hash' => Hash::make($code),
                ]);
            }
        });

        $this->audit->record(
            category: 'identity',
            action: 'mfa.recovery_codes.regenerated',
            subjectType: User::class,
            subjectId: (string) $user->getKey(),
            subjectLabel: $user->name,
            after: ['count' => $count],
            reason: 'any previously issued recovery codes stopped working',
            actor: $user,
        );

        return $codes;
    }

    /**
     * Spend a recovery code.
     *
     * Every unused code is hashed against, rather than looking one up: a
     * recovery code is a password and the hash is not reversible, so there is
     * nothing to look up by. The loop is bounded by the number of codes a user
     * has, which is ten.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $candidates = RecoveryCode::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->get();

        foreach ($candidates as $candidate) {
            if (! Hash::check($code, (string) $candidate->getAttributes()['code_hash'])) {
                continue;
            }

            $candidate->forceFill(['used_at' => now()])->save();

            $remaining = $candidates->count() - 1;

            $this->audit->record(
                category: 'identity',
                action: 'mfa.recovery_code.used',
                subjectType: User::class,
                subjectId: (string) $user->getKey(),
                subjectLabel: $user->name,
                after: ['remaining' => $remaining],
                // Worth saying out loud in the log: a recovery code being used
                // means the user's normal second factor was unavailable, and a
                // run of them is either a lost phone or an attacker who has
                // the list.
                reason: $remaining === 0
                    ? 'the last recovery code was used; the user has no fallback left'
                    : sprintf('%d recovery code(s) remain', $remaining),
                actor: $user,
            );

            return true;
        }

        $this->audit->refusal(
            category: 'identity',
            action: 'mfa.recovery_code.refused',
            subjectType: User::class,
            subjectId: (string) $user->getKey(),
            subjectLabel: $user->name,
            reason: 'no unused recovery code matched',
            actor: $user,
        );

        return false;
    }

    /**
     * Remove a factor, unless it is the last one protecting a money-moving
     * permission - PF-030.
     *
     * The refusal is the point. Without it, the way around every MFA
     * requirement in the system is: remove your own authenticator, and the
     * permission that demanded one now has nothing to demand. A control a
     * user can delete is not a control, and self-service factor removal is
     * the most convenient possible way to delete it.
     *
     * A user who genuinely lost their device uses a recovery code to enrol a
     * replacement, or an administrator revokes their money-moving permissions
     * first. Both leave a trail; deleting the factor silently would not.
     *
     * @throws CannotRemoveLastFactor
     */
    public function removeAuthenticator(Authenticator $authenticator): void
    {
        $user = $authenticator->user;

        if ($user === null) {
            $authenticator->delete();

            return;
        }

        $remaining = Authenticator::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('confirmed_at')
            ->whereKeyNot($authenticator->getKey())
            ->count();

        if ($remaining === 0) {
            $guarded = $this->moneyMovingPermissionsHeldBy($user);

            if ($guarded->isNotEmpty()) {
                $this->audit->refusal(
                    category: 'identity',
                    action: 'mfa.removal.refused',
                    subjectType: Authenticator::class,
                    subjectId: (string) $authenticator->getKey(),
                    subjectLabel: (string) $authenticator->name,
                    after: ['permissions' => $guarded->all()],
                    reason: 'it is the last factor of a user who holds permissions that require one',
                    actor: $user,
                );

                throw CannotRemoveLastFactor::whileHolding($guarded->all());
            }
        }

        $this->audit->record(
            category: 'identity',
            action: 'mfa.removed',
            subjectType: Authenticator::class,
            subjectId: (string) $authenticator->getKey(),
            subjectLabel: (string) $authenticator->name,
            before: ['type' => $authenticator->type, 'name' => $authenticator->name],
            reason: $remaining === 0
                ? 'the user now has no second factor at all'
                : sprintf('%d other confirmed factor(s) remain', $remaining),
            actor: $user,
        );

        $authenticator->delete();
    }

    /**
     * The MFA-requiring permissions this user holds, in any entity.
     *
     * Across entities deliberately: a factor is a property of the person, not
     * of one company, so holding `payment.release` in any entity is reason
     * enough to keep one.
     *
     * @return Collection<int, string>
     */
    public function moneyMovingPermissionsHeldBy(User $user): Collection
    {
        return DB::connection('tenant')->table('permissions as p')
            ->join('role_permissions as rp', 'rp.permission_id', '=', 'p.id')
            ->join('user_entity_roles as uer', 'uer.role_id', '=', 'rp.role_id')
            ->where('uer.user_id', $user->getKey())
            ->where('p.requires_mfa', true)
            ->distinct()
            ->orderBy('p.key')
            ->pluck('p.key');
    }

    public function hasConfirmedFactor(User $user): bool
    {
        return Authenticator::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('confirmed_at')
            ->exists();
    }
}
