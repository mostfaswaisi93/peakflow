<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Ledger\Exceptions\AdjustingEntryRefused;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Posting into the adjusting period - PF-051.
 *
 * ## Why period 13 needs a service of its own
 *
 * It carries no calendar days: it starts and ends on the fiscal year's last
 * day, sharing them with December. So a date lookup skips it on purpose -
 * otherwise every December posting would land in it by accident, and the
 * whole point is that adjustments sit AFTER the last month rather than being
 * re-dated into it.
 *
 * Which means nothing could reach it. Until this, the adjusting period was a
 * row the calendar generator could create and no posting path could use, and
 * the only way to record an auditor's adjustment was to re-date it into
 * December - the exact thing period 13 exists to prevent.
 *
 * ## Three things it does that the Poster must not
 *
 * The permission: `period.post_adjusting`, with step-up authentication.
 * Posting an adjustment is what happens after a year has been reported, and
 * the person doing it is changing a figure somebody has published.
 *
 * The session variable: `peakflow.posting_rule = 'adjusting'` is what the
 * period trigger reads to let a posting into a SOFT-CLOSED period. It is set
 * here, after the permission has been checked, and it is transaction-local -
 * so it cannot survive into the next statement on a pooled connection and
 * silently authorise something else.
 *
 * The audit entry: with the reason, because "an adjustment was posted in
 * April to the year that ended in December" is a disclosure question under
 * IAS 10, not an operational one.
 */
final class AdjustingEntries
{
    public function __construct(
        private readonly Poster $poster,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * The adjusting period of the fiscal year containing a date.
     *
     * By YEAR, not by date range: the period's own range is a single day, and
     * an adjustment is dated when the auditor asked for it - usually weeks
     * after the year ended.
     */
    public function periodFor(string $entityId, Carbon $withinYear): ?Period
    {
        return Period::query()
            ->where('periods.entity_id', $entityId)
            ->where('periods.is_adjusting', true)
            ->whereIn('periods.fiscal_year_id', function ($query) use ($entityId, $withinYear): void {
                $query->select('id')
                    ->from('fiscal_years')
                    ->where('entity_id', $entityId)
                    ->whereDate('starts_on', '<=', $withinYear->toDateString())
                    ->whereDate('ends_on', '>=', $withinYear->toDateString());
            })
            ->first();
    }

    /**
     * Post an adjustment into the adjusting period.
     *
     * @param  array<string, mixed>  $event
     *
     * @throws AdjustingEntryRefused
     */
    public function post(
        Entity $entity,
        string $ruleKey,
        array $event,
        Carbon $accountingDate,
        string $reason,
        string $journalCode = 'GEN',
    ): JournalEntry {
        $entityId = (string) $entity->getKey();

        if (Gate::denies('period.post_adjusting')) {
            throw AdjustingEntryRefused::notPermitted();
        }

        if (mb_strlen(trim($reason)) < 20) {
            /*
             | A reason, and a real one.
             |
             | An adjustment is what somebody asked for after the year was
             | reported. "Audit adjustment" is not a reason; which auditor,
             | which schedule, which finding is.
             */
            throw AdjustingEntryRefused::needsAReason();
        }

        $period = $this->periodFor($entityId, $accountingDate);

        if ($period === null) {
            throw AdjustingEntryRefused::noAdjustingPeriod($accountingDate->toDateString());
        }

        if (! $period->acceptsPostings()) {
            throw AdjustingEntryRefused::periodNotOpen((string) $period->label, $period->state->value);
        }

        return DB::connection('tenant')->transaction(function () use (
            $entity,
            $entityId,
            $ruleKey,
            $event,
            $accountingDate,
            $reason,
            $journalCode,
            $period,
        ): JournalEntry {
            /*
             | Transaction-local, and set before the Poster runs.
             |
             | The period trigger reads this to allow a posting into a
             | soft-closed period. `true` is the local flag on set_config,
             | so it is discarded at commit - a pooled connection cannot
             | carry it into somebody else's statement.
             */
            DB::connection('tenant')->statement(
                "SELECT set_config('peakflow.posting_rule', 'adjusting', true)",
            );

            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: $ruleKey,
                event: $event,
                accountingDate: $accountingDate,
                journalCode: $journalCode,
                sourceType: 'adjusting_entry',
                narration: sprintf('Adjusting entry: %s', $reason),
                into: $period,
            );

            $this->audit->record(
                category: 'ledger',
                action: 'period.post_adjusting',
                subjectType: JournalEntry::class,
                subjectId: (string) $entry->getKey(),
                subjectLabel: (string) $entry->number,
                after: [
                    'period' => (string) $period->label,
                    'accounting_date' => $accountingDate->toDateString(),
                    'rule' => $ruleKey,
                ],
                reason: $reason,
                entityId: $entityId,
                periodId: (string) $period->getKey(),
            );

            return $entry;
        });
    }

    /**
     * How many adjustments a year has, and what they came to.
     *
     * For the compliance pack. An adjusting period with forty entries in it
     * is a different conversation from one with two, and both are a different
     * conversation from an empty one.
     *
     * @return array{period: ?string, entries: int, debits: string, state: ?string}
     */
    public function summaryFor(string $entityId, Carbon $withinYear): array
    {
        $period = $this->periodFor($entityId, $withinYear);

        if ($period === null) {
            return ['period' => null, 'entries' => 0, 'debits' => '0.0000', 'state' => null];
        }

        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT
                count(DISTINCT e.id) AS entries,
                coalesce(sum(l.functional_amount) FILTER (WHERE l.functional_amount > 0), 0) AS debits
            FROM journal_entries e
            LEFT JOIN journal_lines l ON l.journal_entry_id = e.id
            WHERE e.period_id = cast(? AS char(26))
              AND e.status IN ('posted', 'reversed')
        SQL, [(string) $period->getKey()]);

        return [
            'period' => (string) $period->label,
            'entries' => (int) ($row->entries ?? 0),
            'debits' => (string) ($row->debits ?? '0.0000'),
            'state' => $period->state->value,
        ];
    }
}
