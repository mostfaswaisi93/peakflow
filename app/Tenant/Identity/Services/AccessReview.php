<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who can do what, and who has stopped using it - PF-238.
 *
 * ## What an access review is for
 *
 * Not "is the permission model correct" - that is a design question and the
 * SoD engine answers it. This answers a different one, which only a human
 * can: **should this person still have this?** Somebody moved teams in March,
 * somebody left in June, an integration was switched off and its token was
 * not. None of that is a bug; all of it is access that outlived its reason,
 * and the only way it comes back is that a person looks at a list.
 *
 * So the output is built to be read by somebody who will mark rows, not to be
 * asserted against. It is ordered by the thing that makes a row suspicious -
 * money-moving permissions first, then how long it has been since the holder
 * did anything.
 *
 * ## Dormancy is derived from the audit trail, not from a column
 *
 * There is no `last_login_at`. That is deliberate: a column has to be written
 * by every path that signs somebody in, and the day one of them forgets, the
 * review quietly reports an active account as dormant - or worse, the other
 * way. The audit log already records `session.started` because it is
 * evidence, and evidence is what a review is made of.
 *
 * A token is different: `last_used_at` is written by the middleware that
 * authenticates it, which is the only path, so there is one writer and no
 * drift to worry about.
 *
 * ## What it cannot do, said plainly
 *
 * It is a review **as at now**, not as at a date. `user_entity_roles` holds
 * no history - a grant is a row and a revocation is its deletion - so there
 * is nothing to reconstruct March from. The audit log records both acts, so
 * the history exists as a narrative and not as a state, and answering "who
 * held this in March" means replaying it. That is its own task and this does
 * not pretend otherwise.
 */
final class AccessReview
{
    public function __construct(
        private readonly MfaService $mfa,
    ) {}

    /**
     * Every grant: who, which role, in which entity, with what it carries.
     *
     * One row per user per entity rather than per permission. A review with
     * one line per permission runs to thousands and is read by nobody; the
     * decision being made is "should this person have this role here", and
     * the permissions are what makes that decision informed.
     *
     * @return list<array<string, mixed>>
     */
    public function grants(): array
    {
        /*
         | Read as rows, not as `Tenancy\Models\Entity`.
         |
         | `Identity` may not depend on `Tenancy` - the layer rule caught the
         | first version - and what is needed here is one string per id for
         | the report to be readable. Importing a model to call `->code` on
         | it would be reaching across a wall for a label.
         */
        $entities = DB::connection('tenant')->table('entities')->pluck('code', 'id');
        $rows = [];

        foreach (User::query()->orderBy('name')->cursor() as $user) {
            $guarded = $this->mfa->moneyMovingPermissionsHeldBy($user);
            $hasFactor = $this->mfa->hasConfirmedFactor($user);

            $grants = DB::connection('tenant')->table('user_entity_roles as uer')
                ->join('roles as r', 'r.id', '=', 'uer.role_id')
                ->where('uer.user_id', (string) $user->getKey())
                ->orderBy('uer.entity_id')
                ->orderBy('r.key')
                ->get(['uer.entity_id', 'r.key as role_key', 'uer.granted_by_user_id', 'uer.created_at']);

            foreach ($grants->groupBy('entity_id') as $entityId => $inEntity) {
                $rows[] = [
                    'user_id' => (string) $user->getKey(),
                    'user' => (string) $user->name,
                    'email' => (string) $user->email,
                    'active' => (bool) $user->active,
                    'entity_id' => (string) $entityId,
                    'entity' => (string) ($entities[(string) $entityId] ?? '(gone)'),
                    'roles' => $inEntity->pluck('role_key')->map(static fn ($k): string => (string) $k)->all(),
                    'granted_at' => $inEntity->min('created_at'),
                    'money_moving' => $guarded->all(),
                    'has_factor' => $hasFactor,

                    /*
                     | The one row shape that is always wrong.
                     |
                     | `RoleGrantService` refuses the grant when the user has
                     | no factor, so this should be impossible - and a review
                     | that only reported what the code permits would never
                     | find the grant that was written before the rule
                     | existed, or by an import, or straight at the table.
                     */
                    'needs_attention' => $guarded->isNotEmpty() && ! $hasFactor,
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return [$b['needs_attention'], \count($b['money_moving']), $a['user']]
                <=> [$a['needs_attention'], \count($a['money_moving']), $b['user']];
        });

        return $rows;
    }

    /**
     * Accounts that have done nothing for a while, and tokens unused.
     *
     * `$since` is a date rather than a number of days so the caller decides
     * what "a while" is. Ninety days is the usual answer and it is not this
     * service's to assume: a seasonal business has staff who sign in twice a
     * year, and a review that called them dormant would be training its
     * reader to ignore it.
     *
     * A user who has NEVER appeared in the trail is reported with a null
     * last-seen rather than being left out. An account created and never
     * used is the most reviewable row there is.
     *
     * @return array{users: list<array<string, mixed>>, tokens: list<array<string, mixed>>}
     */
    public function dormant(Carbon $since): array
    {
        $lastSeen = DB::connection('tenant')->table('audit_log')
            ->whereNotNull('actor_user_id')
            ->groupBy('actor_user_id')
            ->selectRaw('actor_user_id, max(created_at) as last_seen')
            ->pluck('last_seen', 'actor_user_id');

        $users = [];

        foreach (User::query()->where('active', true)->orderBy('name')->cursor() as $user) {
            $seen = $lastSeen[(string) $user->getKey()] ?? null;

            if ($seen !== null && Carbon::parse((string) $seen)->greaterThanOrEqualTo($since)) {
                continue;
            }

            $users[] = [
                'user_id' => (string) $user->getKey(),
                'user' => (string) $user->name,
                'email' => (string) $user->email,
                'last_seen' => $seen === null ? null : Carbon::parse((string) $seen)->toDateString(),
                'money_moving' => $this->mfa->moneyMovingPermissionsHeldBy($user)->count(),
            ];
        }

        $tokens = [];

        foreach (ApiToken::query()->whereNull('revoked_at')->orderBy('name')->cursor() as $token) {
            $used = $token->last_used_at;

            if ($used !== null && $used->greaterThanOrEqualTo($since)) {
                continue;
            }

            $tokens[] = [
                'token_id' => (string) $token->getKey(),
                'name' => (string) $token->name,
                'last_used' => $used?->toDateString(),
                'created' => $token->created_at?->toDateString(),
            ];
        }

        return ['users' => $users, 'tokens' => $tokens];
    }
}
