<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Models\SodOverride;
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

            'not_included' => [
                'failed_scheduled_runs' => 'no durable record of scheduled-run outcomes exists. The '.
                    'failures raise scheduled_job_failed through the alert sink, so they reach a log; '.
                    'a section whose completeness depends on log retention is not evidence',
                'close_checklist_overrides' => 'there is no override. PeriodStateMachine refuses a '.
                    'close whose checks have not passed and it cannot be waived, so the related act '.
                    'that is recorded is a reopening - which is in this pack',
            ],
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
