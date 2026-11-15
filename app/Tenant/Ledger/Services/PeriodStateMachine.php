<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Events\PeriodClosed;
use App\Tenant\Ledger\Events\PeriodReopened;
use App\Tenant\Ledger\Exceptions\PeriodTransitionRefused;
use App\Tenant\Ledger\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moving a period between states, and recording why - PF-050.
 *
 * ## Every transition is audited with an actor, a reason and a time
 *
 * Which is the whole of this class's reason to exist. The states and their
 * rules were already in the enum and the terminal one is already enforced in
 * the database; what was missing is that a period's state could be changed by
 * an `update()` anywhere, leaving nothing to say who closed February or why
 * it was reopened.
 *
 * "Who reopened this period, when, and what did they say" is the first
 * question in any review of a restated figure, and a state column cannot
 * answer it.
 *
 * ## Reopening is the dangerous direction
 *
 * Closing a period stops postings; reopening one lets somebody change a
 * figure that has been reported. So reopening demands a reason, appears on
 * the compliance report, and - once PF-09x brings it - step-up
 * authentication.
 *
 * `locked` has no path back at all, by anyone, and that is enforced by a
 * database trigger rather than here. A state machine in the application is a
 * convenience; the trigger is the guarantee.
 *
 * ## What this does NOT do
 *
 * It does not post anything. Closing a year is a posting and belongs to
 * {@see YearEndClose}, which drives this class for the state change - so
 * "the period closed" and "the closing entry was posted" are two recorded
 * facts rather than one, and a close that posted and failed to change state
 * is visible as exactly that.
 */
final class PeriodStateMachine
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Move a period to a new state.
     *
     * @throws PeriodTransitionRefused
     */
    public function transition(
        Period $period,
        PeriodState $to,
        string $reason,
        bool $isReopening = false,
    ): Period {
        $from = $period->state;

        if ($from === $to) {
            throw PeriodTransitionRefused::alreadyThere($period->label, $to);
        }

        if ($from->isTerminal()) {
            /*
             | Refused here as well as by the trigger.
             |
             | The trigger is the guarantee and this is the message: a
             | database error naming a constraint is not something to show
             | an accountant who has just been told the year is filed.
             */
            throw PeriodTransitionRefused::locked($period->label);
        }

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw PeriodTransitionRefused::notAllowed($period->label, $from, $to);
        }

        /*
         | Reopening demands a real reason.
         |
         | Twenty characters is not a high bar; it is enough to stop "fix"
         | and "reopen". The reason is read during a review of a restated
         | figure, when the only artefact is what somebody typed - and that
         | review happens because something went wrong.
         */
        $reopening = $isReopening || ($to === PeriodState::Open && $from !== PeriodState::Future);

        if ($reopening && mb_strlen(trim($reason)) < 20) {
            throw PeriodTransitionRefused::reopeningNeedsAReason($period->label);
        }

        if (trim($reason) === '') {
            throw PeriodTransitionRefused::transitionNeedsAReason($period->label, $to);
        }

        return DB::connection('tenant')->transaction(function () use (
            $period,
            $from,
            $to,
            $reason,
            $reopening,
        ): Period {
            $userId = auth()->guard('web')->id();
            $now = now();

            /*
             | The transition log, and the state column, together.
             |
             | Both in one transaction so a state that moved without a
             | record is not representable - which is the failure mode of
             | having only a column, and the one this class exists to close.
             */
            $transitionId = (string) Str::ulid();

            DB::connection('tenant')->table('period_transitions')->insert([
                'id' => $transitionId,
                'entity_id' => $period->entity_id,
                'period_id' => $period->getKey(),
                'from_state' => $from->value,
                'to_state' => $to->value,
                'is_reopening' => $reopening,
                'reason' => $reason,
                'by_user_id' => $userId,
                'at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /*
             | The period points at the transition that moved it.
             |
             | Which is how the row gets CONSUMED: the column is unique and
             | must change, and the trigger checks the row describes exactly
             | this move. A bare UPDATE has no id to offer, and one that
             | borrows the id the application just used is refused because
             | it is already on the period.
             */
            $attributes = [
                'state' => $to->value,
                'last_transition_id' => $transitionId,
                'updated_at' => $now,
            ];

            if ($to === PeriodState::Closed) {
                $attributes['closed_at'] = $now;
                $attributes['closed_by_user_id'] = $userId;
            }

            if ($to === PeriodState::Locked) {
                $attributes['locked_at'] = $now;
                $attributes['locked_by_user_id'] = $userId;
            }

            if ($to === PeriodState::Open) {
                // Reopening clears the close, because the period is not
                // closed any more and a stale closed_at is a date that
                // contradicts the state beside it.
                $attributes['closed_at'] = null;
                $attributes['closed_by_user_id'] = null;
            }

            DB::connection('tenant')->table('periods')
                ->where('id', $period->getKey())
                ->update($attributes);

            $this->audit->record(
                category: 'ledger',
                action: $reopening ? 'period.reopened' : 'period.'.$to->value,
                subjectType: Period::class,
                subjectId: (string) $period->getKey(),
                subjectLabel: (string) $period->label,
                before: ['state' => $from->value],
                after: ['state' => $to->value],
                reason: $reason,
                entityId: (string) $period->entity_id,
                periodId: (string) $period->getKey(),
            );

            /*
             | Dispatched INSIDE the transaction, deliberately.
             |
             | A posting listener must see the period in its new state and
             | must fail the transition if it fails - a reporting pack built
             | from a period that was still open when it was built is a pack
             | that will be rebuilt and will not match.
             |
             | Side-effect listeners are queued, and Laravel's queue
             | dispatcher defers the push until commit, so a rolled-back
             | transition sends no notification about a close that did not
             | happen.
             */
            event($reopening
                ? new PeriodReopened(
                    entityId: (string) $period->entity_id,
                    periodId: (string) $period->getKey(),
                    periodLabel: (string) $period->label,
                    fromState: $from->value,
                    reason: $reason,
                    byUserId: $userId === null ? null : (string) $userId,
                )
                : new PeriodClosed(
                    entityId: (string) $period->entity_id,
                    periodId: (string) $period->getKey(),
                    periodLabel: (string) $period->label,
                    state: $to->value,
                    reason: $reason,
                    byUserId: $userId === null ? null : (string) $userId,
                ));

            return $period->refresh();
        });
    }

    /**
     * Every transition a period has been through.
     *
     * @return list<array{from: string, to: string, reopening: bool, reason: string, at: string, by: ?string}>
     */
    public function history(string $periodId): array
    {
        return DB::connection('tenant')->table('period_transitions')
            ->where('period_id', $periodId)
            ->orderBy('at')
            ->get()
            ->map(static fn (object $row): array => [
                'from' => (string) $row->from_state,
                'to' => (string) $row->to_state,
                'reopening' => (bool) $row->is_reopening,
                'reason' => (string) $row->reason,
                'at' => (string) $row->at,
                'by' => $row->by_user_id === null ? null : (string) $row->by_user_id,
            ])
            ->all();
    }

    /**
     * Every reopening, for the compliance report - PF-050.
     *
     * A reopened period means a reported figure may have changed after it
     * was reported, which is a disclosure question under IAS 10 rather than
     * an operational one. It belongs on the compliance pack whether or not
     * anybody asks.
     *
     * @return list<array{period: string, reason: string, at: string, by: ?string, from: string}>
     */
    public function reopenings(?string $entityId = null): array
    {
        return DB::connection('tenant')->table('period_transitions as t')
            ->join('periods as p', 'p.id', '=', 't.period_id')
            ->where('t.is_reopening', true)
            ->when($entityId !== null, fn ($query) => $query->where('t.entity_id', $entityId))
            ->orderByDesc('t.at')
            ->get([
                'p.label',
                't.reason',
                't.at',
                't.by_user_id',
                't.from_state',
            ])
            ->map(static fn (object $row): array => [
                'period' => (string) $row->label,
                'reason' => (string) $row->reason,
                'at' => (string) $row->at,
                'by' => $row->by_user_id === null ? null : (string) $row->by_user_id,
                'from' => (string) $row->from_state,
            ])
            ->all();
    }
}
