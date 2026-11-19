<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Exceptions\DeviceSessionRefused;
use App\Tenant\Identity\Exceptions\TokenRefused;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\RefreshToken;
use App\Tenant\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person's device, signed in - PF-260.
 *
 * ## What a session is made of
 *
 * One FAMILY of refresh tokens, each spent to make the next, and one access
 * token alive at a time - an ordinary `api_tokens` row, issued through
 * {@see ApiTokenService} so that it is audited, scoped and checked exactly
 * like every other token. Nothing here is a second authorization model: an
 * access token from a phone is refused and permitted by the same middleware
 * and the same gate as a token from an integration.
 *
 * ## One entity per access token
 *
 * A token's scopes are checked in EVERY entity it names, and a person who
 * posts in the parent and only reads in a subsidiary would get a token that
 * can post in neither if the two were combined - or, taking the union, one
 * that can post in both. So an access token names one entity, carrying
 * exactly what the person holds there, and switching company is a refresh
 * that names another. The same rule the browser's switcher follows.
 *
 * ## What the scopes are
 *
 * Every permission the person holds in that entity, less those that need
 * somebody present at the moment of the act (releasing a payment run). Those
 * can never be token scopes - {@see ApiScopes::requiresStepUp()} - and a phone
 * is not an exception: the token on it outlives the moment the person was
 * looking at it.
 */
final class DeviceSessions
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ApiScopes $scopes,
        private readonly AuditLog $audit,
        private readonly MfaService $mfa,
    ) {}

    /**
     * The person behind a token, as an app needs to show them - GET /me.
     *
     * The scopes are the TOKEN's, not the person's: an app deciding whether to
     * show an "Approve" button must ask what this credential may do, and a
     * person's grants can be wider than the token they are holding.
     *
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    public function describe(User $user, string $entityId, array $scopes): array
    {
        return [
            'resource' => 'me',
            'id' => (string) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'locale' => $user->locale === null ? null : (string) $user->locale,
            'second_factor_enrolled' => $this->mfa->hasConfirmedFactor($user),
            'entity_id' => $entityId,
            'scopes' => array_values($scopes),
            'entities' => $this->entitiesFor($user),
        ];
    }

    /**
     * The companies a person may act in, with what the app needs to show them.
     *
     * @return list<array{id: string, code: string, legal_name: string, functional_currency: string}>
     */
    public function entitiesFor(User $user): array
    {
        return DB::connection('tenant')->table('entities')
            ->whereIn('id', $user->entityIds())
            ->where('active', true)
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['id', 'code', 'legal_name', 'functional_currency'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'legal_name' => (string) $row->legal_name,
                'functional_currency' => (string) $row->functional_currency,
            ])
            ->values()
            ->all();
    }

    /**
     * What an access token for this person in this entity carries.
     *
     * @return list<string>
     */
    public function scopesFor(User $user, string $entityId): array
    {
        if (! $user->active) {
            return [];
        }

        $user->forgetResolvedPermissions();

        return $user->permissionsIn($entityId)
            ->map(static fn (mixed $key): string => (string) $key)
            ->filter(fn (string $key): bool => $this->scopes->exists($key) && ! $this->scopes->requiresStepUp($key))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The entity to open or continue a session in.
     *
     * The one asked for, if the person may act there; otherwise the first, by
     * code, in which they hold anything at all. Never one they hold nothing in:
     * an access token with no scopes cannot be issued, and a session that
     * opened anyway would answer every request with a 403 nobody could
     * explain.
     *
     * @throws DeviceSessionRefused
     */
    public function chooseEntity(User $user, ?string $requested): string
    {
        $entities = array_column($this->entitiesFor($user), 'id');

        if ($requested !== null && $requested !== '') {
            if (! \in_array($requested, $entities, true) || $this->scopesFor($user, $requested) === []) {
                throw DeviceSessionRefused::noEntity(sprintf(
                    '%s asked for entity %s and holds no role there.',
                    $user->email,
                    $requested,
                ));
            }

            return $requested;
        }

        foreach ($entities as $entityId) {
            if ($this->scopesFor($user, $entityId) !== []) {
                return $entityId;
            }
        }

        throw DeviceSessionRefused::noEntity(sprintf(
            '%s holds no permission in any active company, so there is nothing a session could do.',
            $user->email,
        ));
    }

    /**
     * Open a session: the first refresh token of a new family, and its access token.
     *
     * @param  ?string  $secondFactor  what the person presented just now, or null
     */
    public function open(User $user, string $deviceName, ?string $secondFactor, string $entityId, Request $request): IssuedSession
    {
        return DB::connection('tenant')->transaction(function () use ($user, $deviceName, $secondFactor, $entityId, $request): IssuedSession {
            $startedAt = now();
            $familyId = strtolower((string) Str::ulid());

            $issued = $this->issue(
                user: $user,
                familyId: $familyId,
                id: $familyId,
                deviceName: $deviceName,
                entityId: $entityId,
                secondFactor: $secondFactor,
                startedAt: $startedAt,
                request: $request,
            );

            $this->audit->record(
                category: 'identity',
                action: 'device_session.opened',
                subjectType: 'api_refresh_tokens',
                subjectId: $familyId,
                subjectLabel: $deviceName,
                after: ['entity_id' => $entityId, 'second_factor' => $secondFactor, 'scopes' => $issued->scopes],
                reason: sprintf('%s signed in on a device called "%s".', $user->name, $deviceName),
                entityId: $entityId,
                actor: $user,
            );

            return $issued;
        });
    }

    /**
     * Spend a refresh token for the next pair - in this entity or another.
     *
     * A token presented AFTER it was spent closes its whole family before the
     * refusal is returned. The device was handed the successor and stored it,
     * so whoever is presenting the old one holds a copy; both now hold a
     * credential and the server cannot tell which is the person.
     *
     * @throws DeviceSessionRefused
     */
    public function refresh(string $plaintext, ?string $entityId, Request $request): IssuedSession
    {
        $hash = hash('sha256', $plaintext);

        /*
         | The verdict is decided inside the transaction and ACTED on outside
         | it, for the one case that writes and then refuses: closing the
         | family after a reuse has to commit, and an exception thrown inside
         | the transaction would roll the closure back with it.
         */
        $outcome = DB::connection('tenant')->transaction(function () use ($hash, $entityId, $request): IssuedSession|RefreshToken|string {
            $current = RefreshToken::query()->where('token', $hash)->lockForUpdate()->first();

            if (! $current instanceof RefreshToken) {
                return 'no refresh token has that hash';
            }

            if ($current->used_at !== null) {
                return $current;
            }

            if ($current->revoked_at !== null) {
                return 'the refresh token was revoked: '.(string) $current->revoked_reason;
            }

            if (! $current->expires_at->isFuture()) {
                return 'the refresh token expired at '.$current->expires_at->toIso8601ZuluString();
            }

            $user = User::query()->find($current->user_id);

            if (! $user instanceof User || ! $user->active) {
                return 'the person this session belongs to is deactivated or gone';
            }

            /*
             | Continue where the device was, unless it asked to move.
             |
             | If the person lost every role in the entity the session was in,
             | an UNSPECIFIED refresh moves them to the first company they can
             | still act in rather than refusing: the app asked for "the next
             | token", not for that company, and refusing would sign them out
             | of every company over a change to one. An entity the device
             | NAMED is held to it, because then it did ask.
             */
            $target = $entityId !== null && $entityId !== ''
                ? $this->chooseEntity($user, $entityId)
                : ($this->scopesFor($user, $current->entity_id) !== []
                    ? $current->entity_id
                    : $this->chooseEntity($user, null));

            $successorId = strtolower((string) Str::ulid());

            $issued = $this->issue(
                user: $user,
                familyId: $current->family_id,
                id: $successorId,
                deviceName: $current->device_name,
                entityId: $target,
                secondFactor: $current->second_factor,
                startedAt: $current->session_started_at,
                request: $request,
            );

            $current->forceFill(['used_at' => now(), 'replaced_by_id' => $successorId])->save();

            $this->retireAccessToken($current->access_token_id, 'Replaced by the next access token of this device session.');

            return $issued;
        });

        if ($outcome instanceof IssuedSession) {
            return $outcome;
        }

        if ($outcome instanceof RefreshToken) {
            $this->close(
                $outcome->family_id,
                'A spent refresh token was presented again, so a copy of it is held by somebody else.',
            );

            $this->audit->refusal(
                category: 'identity',
                action: 'device_session.reuse_detected',
                subjectType: 'api_refresh_tokens',
                subjectId: $outcome->family_id,
                subjectLabel: $outcome->device_name,
                after: ['ip' => (string) $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 300, '')],
                reason: 'a refresh token was presented after it had been spent; the device holds its successor, '.
                    'so this was a copy - the whole session was closed',
                entityId: $outcome->entity_id,
            );

            throw DeviceSessionRefused::refresh('a spent refresh token was presented again; the session was closed');
        }

        throw DeviceSessionRefused::refresh($outcome);
    }

    /**
     * End a session: every refresh token in the family, and the access token now live.
     */
    public function close(string $familyId, string $reason): void
    {
        DB::connection('tenant')->transaction(function () use ($familyId, $reason): void {
            $rows = RefreshToken::query()->where('family_id', $familyId)->lockForUpdate()->get();

            foreach ($rows as $row) {
                if ($row->revoked_at === null) {
                    $row->forceFill(['revoked_at' => now(), 'revoked_reason' => Str::limit($reason, 200, '')])->save();
                }

                $this->retireAccessToken($row->access_token_id, $reason);
            }
        });

        $head = RefreshToken::query()->where('family_id', $familyId)->orderByDesc('created_at')->first();

        $this->audit->record(
            category: 'identity',
            action: 'device_session.closed',
            subjectType: 'api_refresh_tokens',
            subjectId: $familyId,
            subjectLabel: $head?->device_name,
            reason: $reason,
            entityId: $head?->entity_id,
        );
    }

    /** The session an access token was issued for, if it came from one. */
    public function sessionOf(ApiToken $token): ?RefreshToken
    {
        return RefreshToken::query()
            ->where('access_token_id', (string) $token->getKey())
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * A person's live sessions: the unspent head of every open family.
     *
     * @return Collection<int, RefreshToken>
     */
    public function liveSessionsOf(User $user): Collection
    {
        return RefreshToken::query()
            ->where('user_id', (string) $user->getKey())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('session_started_at')
            ->get();
    }

    /**
     * One link in a family: a refresh token row and the access token beside it.
     */
    private function issue(
        User $user,
        string $familyId,
        string $id,
        string $deviceName,
        string $entityId,
        ?string $secondFactor,
        Carbon $startedAt,
        Request $request,
    ): IssuedSession {
        $scopes = $this->scopesFor($user, $entityId);

        $accessExpiresAt = now()->addMinutes((int) config('api.sessions.access_token_minutes', 60));

        try {
            $access = $this->tokens->issue(
                owner: $user,
                name: Str::limit('Device: '.$deviceName, 64, ''),
                scopes: $scopes,
                entityIds: [$entityId],
                expiresAt: $accessExpiresAt,
            );
        } catch (TokenRefused $e) {
            // The person's grants changed between choosing the entity and
            // issuing - refused as having no company there, which is true.
            throw DeviceSessionRefused::noEntity($e->getMessage());
        }

        /*
         | The refresh token ends at whichever comes first: its own lifetime,
         | or the end of the session it belongs to.
         */
        $refreshExpiresAt = now()->addDays((int) config('api.sessions.refresh_token_days', 30));
        $sessionEndsAt = $startedAt->copy()->addDays((int) config('api.sessions.session_max_days', 90));

        if ($sessionEndsAt->lessThan($refreshExpiresAt)) {
            $refreshExpiresAt = $sessionEndsAt;
        }

        $secret = RefreshToken::PREFIX.Str::random(64);

        $row = new RefreshToken();
        $row->forceFill([
            'id' => $id,
            'family_id' => $familyId,
            'user_id' => (string) $user->getKey(),
            'token' => hash('sha256', $secret),
            'device_name' => $deviceName,
            'entity_id' => $entityId,
            'access_token_id' => (string) $access['token']->getKey(),
            'second_factor' => $secondFactor,
            'session_started_at' => $startedAt,
            'expires_at' => $refreshExpiresAt,
            'created_ip' => Str::limit((string) $request->ip(), 45, ''),
            'user_agent' => Str::limit((string) $request->userAgent(), 300, ''),
        ])->save();

        return new IssuedSession(
            sessionId: $familyId,
            user: $user,
            entityId: $entityId,
            scopes: $access['token']->scopes(),
            accessToken: $access['plaintext'],
            accessTokenExpiresAt: $accessExpiresAt,
            refreshToken: $secret,
            refreshTokenExpiresAt: $refreshExpiresAt,
            sessionStartedAt: $startedAt,
            secondFactor: $secondFactor,
        );
    }

    private function retireAccessToken(?string $tokenId, string $reason): void
    {
        if ($tokenId === null) {
            return;
        }

        $token = ApiToken::query()->find($tokenId);

        if ($token instanceof ApiToken && $token->revoked_at === null) {
            $this->tokens->revoke($token, Str::limit($reason, 200, ''));
        }
    }
}
