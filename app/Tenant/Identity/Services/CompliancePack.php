<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Models\SodOverride;
use App\Tenant\Identity\Services\SodExceptionsReport;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The evidence pack an auditor is handed - PF-238.
 *
 * ## What makes it evidence rather than a report
 *
 * Every section is derived from something the system wrote at the time it
 * happened, not from something assembled now. The audit log is append-only
 * and hash-chained; the override rows carry who approved them and when; the
 * privilege matrix is read off the live catalog. Nothing here is a figure
 * somebody can adjust before printing.
 *
 * ## Reproducible for a date, where the data allows it
 *
 * An auditor asks for a point in time, and the honest answer differs by
 * section:
 *
 *   - **Event-derived** sections - period transitions, overrides granted,
 *     reopenings - are reconstructible for any past date, because the
 *     records are events with timestamps. These are marked `as_at`.
 *   - **State-derived** sections - who holds which role, which privileges
 *     the role has now - are only knowable as they are. `user_entity_roles`
 *     keeps no history: a grant is a row and a revocation is its deletion,
 *     so March cannot be reconstructed from it. These are marked `as_now`,
 *     and a pack printed for a past date says so rather than implying the
 *     access list is historical.
 *
 * Saying which is which is the whole point. A pack that silently mixed them
 * would answer "who could release payments in March" with today's list, and
 * the one person the question is about is the one who left in April.
 *
 * ## Two things the criterion asks for that have no source
 *
 * **Failed scheduled runs** need a durable record of scheduled-run outcomes
 * and there is no table for one. `routes/console.php` raises
 * `scheduled_job_failed` through the alert sink, so the failures reach a
 * log; what does not exist is a queryable history. A section built from the
 * log would be a section whose completeness depends on log retention, which
 * is not evidence.
 *
 * **Close-checklist overrides** have no source because there is no override.
 * `PeriodStateMachine` refuses a close whose checks have not passed; it
 * cannot be waived. The related act that IS recorded is a reopening, and
 * that is in the pack.
 */
final class CompliancePack
{
    public function __construct(
        private readonly AccessReview $review,
        private readonly AuditChainVerifier $chain,
        private readonly SodExceptionsReport $exceptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Carbon $asAt, int $dormantDays = 90): array
    {
        return [
            'as_at' => $asAt->toDateString(),
            'generated_at' => Carbon::now()->toIso8601ZuluString(),

            'access' => [
                'basis' => 'as_now',
                'why' => 'user_entity_roles keeps no history - a grant is a row and a revocation is '.
                    'its deletion - so this is who holds what today, not on the date above',
                'grants' => $this->review->grants(),
                'dormant' => $this->review->dormant($asAt->copy()->subDays($dormantDays)),
            ],

            /*
             | Two different questions about segregation of duties - PF-034.
             |
             | `in_force` is which overrides are live: who has been given
             | permission to break a rule. `exceptions` is what the control
             | actually did - where it refused, and what was done under an
             | override that was granted.
             |
             | An auditor asks both and they have different answers. A
             | standing override nobody ever used is a risk that did not
             | materialise; a refusal is the control working; an act under
             | an override is the control being set aside by somebody who
             | is named. A pack with only the first says what was permitted
             | and not what happened.
             */
            'sod_overrides' => [
                'basis' => 'as_at',
                'in_force' => $this->overridesInForce($asAt),
            ],

            'periods' => [
                'basis' => 'as_at',
                'transitions' => $this->events($asAt, ['period.close', 'period.transition', 'period.post_adjusting']),
                'reopenings' => $this->events($asAt, ['period.reopen', 'period.reopened', 'year.close.reversed']),
            ],

            'chain' => [
                'basis' => 'as_now',
                'why' => 'a chain is walked forward from its first record; verifying it as at a past '.
                    'date would mean verifying a prefix, which proves less rather than more',
                'verdict' => $this->chainVerdict(),
            ],

            /*
             | Scheduled runs, which this pack used to say did not exist.
             |
             | It said: "no durable record of scheduled-run outcomes
             | exists... a section whose completeness depends on log
             | retention is not evidence". That was true when it was
             | written and stopped being true with PF-241's
             | `platform.scheduled_runs`, which records every finished run
             | rather than only the failures - because a run missing from a
             | history of failures looks exactly like one that succeeded,
             | and exactly like one that never started.
             */
            'scheduled_runs' => [
                'basis' => 'as_at',
                'why' => 'the thirty days before the date above. "Did the nightly chain walk run every '.
                    'night" is the question this answers, and it is a question about a recent window '.
                    'rather than about all time',
                'runs' => $this->scheduledRuns($asAt),
            ],

            'not_included' => [
                'close_checklist_overrides' => 'there is no override. PeriodStateMachine refuses a '.
                    'close whose checks have not passed and it cannot be waived, so the related act '.
                    'that is recorded is a reopening - which is in this pack',
            ],
        ];
    }

    /**
     * Scheduled runs in the thirty days before the date - PF-241, PF-13x.
     *
     * Successes as well as failures. A history of failures answers "what
     * went wrong" and not "what happened", and the second is the one an
     * auditor asks: a run missing from a list of failures looks exactly
     * like one that succeeded and exactly like one that never started.
     *
     * Read from the PLATFORM connection, because the schedule is the
     * platform's - a tenant does not have its own cron.
     *
     * @return array<string, mixed>
     */
    private function scheduledRuns(Carbon $asAt): array
    {
        // Day boundaries, for the reason above: a window measured from an
        // instant makes two packs for one date differ at the edges.
        $from = $asAt->copy()->startOfDay()->subDays(30);
        $until = $asAt->copy()->endOfDay();

        $rows = DB::connection('platform')->table('scheduled_runs')
            ->whereBetween('finished_at', [$from, $until])
            ->selectRaw(
                'command, count(*) as runs, '.
                "count(*) FILTER (WHERE exit_code = 0) as succeeded, ".
                "count(*) FILTER (WHERE exit_code IS NOT NULL AND exit_code <> 0) as failed, ".
                "count(*) FILTER (WHERE exit_code IS NULL) as unknown, ".
                'max(finished_at)::text as last_finished',
            )
            ->groupBy('command')
            ->orderBy('command')
            ->get();

        return [
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'by_command' => $rows->map(static fn (object $row): array => [
                'command' => (string) $row->command,
                'runs' => (int) $row->runs,
                'succeeded' => (int) $row->succeeded,
                'failed' => (int) $row->failed,
                'unknown' => (int) $row->unknown,
                'last_finished' => (string) $row->last_finished,
            ])->all(),
        ];
    }

    /**
     * Overrides that were in force on the date, not the ones granted on it.
     *
     * A different question with a different answer: an override granted in
     * January and still live in March is what an auditor asking about March
     * is looking for, and a list of grants made in March would not contain
     * it.
     *
     * @return list<array<string, mixed>>
     */
    private function overridesInForce(Carbon $asAt): array
    {
        $end = $asAt->copy()->endOfDay();

        return SodOverride::query()
            ->where('approved_at', '<=', $end)
            ->where('expires_at', '>=', $asAt->copy()->startOfDay())
            ->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('revoked_at', '>', $end))
            ->orderBy('approved_at')
            ->get()
            ->map(static fn (SodOverride $o): array => [
                'id' => (string) $o->getKey(),
                'rule' => (string) $o->sod_rule_id,
                'approved_at' => $o->approved_at?->toDateString(),
                'expires_at' => $o->expires_at?->toDateString(),
                'revoked_at' => $o->revoked_at?->toDateString(),
            ])
            ->all();
    }

    /**
     * Audit events of these actions, up to and including the date.
     *
     * @param  list<string>  $actions
     * @return list<array<string, mixed>>
     */
    private function events(Carbon $asAt, array $actions): array
    {
        return DB::connection('tenant')->table('audit_log')
            ->whereIn('action', $actions)
            ->where('created_at', '<=', $asAt->copy()->endOfDay())
            ->orderBy('created_at')
            ->get(['created_at', 'action', 'actor_name', 'subject_label', 'reason'])
            ->map(static fn (object $row): array => [
                'at' => Carbon::parse((string) $row->created_at)->toIso8601ZuluString(),
                'action' => (string) $row->action,
                'by' => $row->actor_name === null ? '(nobody named)' : (string) $row->actor_name,
                'subject' => (string) ($row->subject_label ?? ''),
                'reason' => (string) ($row->reason ?? ''),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function chainVerdict(): array
    {
        $verdict = $this->chain->verify();

        return [
            'intact' => $verdict->isIntact,
            'records_checked' => $verdict->recordsChecked,
            'first_divergence' => $verdict->firstDivergenceId,
            'explanation' => $verdict->explanation,
        ];
    }
}
