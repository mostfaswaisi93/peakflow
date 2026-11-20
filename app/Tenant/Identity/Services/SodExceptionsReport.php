<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Models\SodOverride;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Support\Facades\DB;

/**
 * The standing exceptions report - PF-034.
 *
 * ## Why refusals and overrides are both on it
 *
 * An auditor asks two questions about segregation of duties, and they are not
 * the same question.
 *
 * "Where was the control EXERCISED" - the refusals. A long list is not a
 * finding; it is the control working. A list that is empty in a company with
 * four staff is the finding, because it means either nobody ever attempts a
 * conflicting duty or the control is not running.
 *
 * "Where was the control SUSPENDED" - the overrides. Each one must have a
 * reason, a named approver who is not the beneficiary, and an expiry. An
 * override that has been renewed eleven times is not an exception; it is the
 * control environment, and it should be reported as such.
 *
 * ## Expired is not the same as revoked
 *
 * An expired override lapsed on its own, as designed. A revoked one was taken
 * away deliberately, and somebody decided to. The report distinguishes them
 * because "why was this revoked" is a question worth asking and "why did this
 * expire" is not.
 *
 * ## The entity is required, and that is not a limitation
 *
 * `sod_refusals` and `sod_overrides` are entity-scoped by row-level security,
 * so a report run with no entity established reads NOTHING - correctly, and
 * silently. The first version of this class took an optional entity and
 * therefore produced a confidently empty report for a tenant with five
 * refusals in it, which is the worst possible output for a compliance
 * document.
 *
 * So the entity is required here, and a tenant-wide report is an aggregation
 * of per-entity reports, each read under that entity's own scope. That also
 * means the report cannot accidentally show an entity the reader has no role
 * in.
 */
final class SodExceptionsReport
{
    /**
     * @return array{
     *     entity_id: string, from: string, to: string,
     *     refusals: Collection<int, object>,
     *     refusals_by_rule: Collection<string, int>,
     *     overrides: Collection<int, SodOverride>,
     *     active_overrides: int,
     *     expired_overrides: int,
     *     revoked_overrides: int,
     *     repeatedly_renewed: Collection<int, object>,
     * }
     */
    public function for(string $entityId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now()->endOfDay();

        // Establish the scope these tables are read under. Without this the
        // RLS policy compares against NULL and the report is empty.
        app(EntityContext::class)->use($entityId);

        $refusals = DB::connection('tenant')->table('sod_refusals as r')
            ->leftJoin('sod_rules as rule', 'rule.id', '=', 'r.sod_rule_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.entity_id', $entityId)
            ->whereBetween('r.created_at', [$from, $to])
            /*
             | The id breaks the tie, and the tie is not rare.
             |
             | Rows written in one transaction share a `created_at` to the
             | microsecond, so ordering on it alone leaves their order to
             | the planner - and this report goes into the evidence pack,
             | which is diffed against last quarter's. A pack whose row
             | order moves on its own makes every section look changed.
             */
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->get([
                'r.id', 'r.created_at', 'r.attempted_duty', 'r.conflicting_duty',
                'r.subject_type', 'r.subject_id', 'r.conflicting_audit_id',
                'rule.key as rule_key', 'rule.scope', 'rule.overridable',
                'u.name as user_name',
            ]);

        $overrides = SodOverride::query()
            ->with(['rule', 'user'])
            ->where('entity_id', $entityId)
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->get();

        /*
         | An override granted repeatedly for the same user and rule is the
         | signal worth surfacing. One exception is an exception; four in a row
         | is a staffing decision that nobody wrote down, and reporting it as
         | four separate exceptions hides that.
         */
        $repeated = $overrides
            ->groupBy(fn (SodOverride $o): string => $o->sod_rule_id.'|'.$o->user_id)
            ->filter(fn (Collection $group): bool => $group->count() >= 3)
            ->map(fn (Collection $group): object => (object) [
                'rule_key' => $group->first()?->rule?->key,
                'user_name' => $group->first()?->user?->name,
                'times_granted' => $group->count(),
                'first_granted_at' => $group->min('approved_at'),
                'latest_expires_at' => $group->max('expires_at'),
            ])
            ->values();

        return [
            'entity_id' => $entityId,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'refusals' => $refusals,
            'refusals_by_rule' => $refusals
                ->groupBy(fn (object $r): string => (string) ($r->rule_key ?? 'unknown'))
                ->map(fn (Collection $g): int => $g->count()),
            'overrides' => $overrides,
            'active_overrides' => $overrides->filter(fn (SodOverride $o): bool => $o->isUsable())->count(),
            'expired_overrides' => $overrides
                ->filter(fn (SodOverride $o): bool => $o->revoked_at === null && ! $o->expires_at->greaterThan(now()))
                ->count(),
            'revoked_overrides' => $overrides->filter(fn (SodOverride $o): bool => $o->revoked_at !== null)->count(),
            'repeatedly_renewed' => $repeated,
        ];
    }
}
