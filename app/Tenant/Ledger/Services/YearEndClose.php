<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Exceptions\YearEndCloseRefused;
use App\Tenant\Ledger\Models\FiscalYear;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Posting\Rules\YearEndCloseRule;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Closing a fiscal year - PF-052, IAS 1.
 *
 * ## Re-runnable means it REFUSES, not that it recomputes
 *
 * A second run detects the existing close and refuses. The tempting
 * alternative - recompute and post the difference - looks idempotent and is
 * not: the first close moved the revenue accounts to zero, so a recomputation
 * sees nothing to close and posts nothing, while a recomputation that
 * deliberately excludes the close's own entries posts the same amount again.
 *
 * Both outcomes are wrong, one silently. Refusing is the only answer that
 * cannot be wrong, and the message says how to undo the first close.
 *
 * ## Reversible while the new year's first period is still open
 *
 * Which is the real requirement behind "reversible": once somebody has posted
 * into the new year on top of the carried-forward balances, un-closing the
 * old one changes figures those postings were made against. So the window is
 * bounded by something observable rather than by a time limit.
 *
 * ## The balances are read as at the year end, EXCLUDING the close itself
 *
 * Otherwise a reversal followed by a re-close would read the reversal as
 * ordinary activity and close it again. The exclusion is by journal, not by
 * date: the closing entry is dated the last day of the year, which is also
 * when plenty of real transactions are dated.
 */
final class YearEndClose
{
    /** The reserved journals. `CLS` accepts postings only from this rule. */
    private const CLOSING_JOURNAL = 'CLS';

    public function __construct(
        private readonly Poster $poster,
        private readonly Reverser $reverser,
        private readonly PeriodStateMachine $periods,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Close a year.
     *
     * @return array{
     *     entry: JournalEntry|null,
     *     accounts_closed: int,
     *     result: string,
     *     note: string,
     * }
     *
     * @throws YearEndCloseRefused
     */
    public function close(Entity $entity, FiscalYear $year): array
    {
        $entityId = (string) $entity->getKey();
        $functional = (string) $entity->functional_currency;

        $existing = $this->existingClose($entityId, (string) $year->getKey());

        if ($existing !== null) {
            throw YearEndCloseRefused::alreadyClosed(
                (string) $year->label,
                (string) $existing->number,
            );
        }

        /*
         | Every period of the year must be closed or locked first.
         |
         | Closing a year whose December is still open means December can
         | still take postings - which would land after the closing entry
         | and never be carried forward, so the year's revenue would be
         | understated and retained earnings would be short by exactly that
         | amount. Nothing would disagree with anything else.
         */
        $openPeriods = $this->openPeriodsIn($entityId, (string) $year->getKey());

        if ($openPeriods !== []) {
            throw YearEndCloseRefused::periodsStillOpen((string) $year->label, $openPeriods);
        }

        $balances = $this->closingBalances($entityId, $year);

        if ($balances === []) {
            // A year with no profit-or-loss movement at all. Recorded as a
            // close with no entry rather than refused: an entity formed in
            // December has a real year with nothing in it.
            $this->audit->record(
                category: 'ledger',
                action: 'year.closed',
                subjectType: FiscalYear::class,
                subjectId: (string) $year->getKey(),
                subjectLabel: (string) $year->label,
                after: ['accounts_closed' => 0],
                reason: 'the year had no revenue or expense movement, so there was nothing to carry to '.
                    'retained earnings and no entry was posted',
                entityId: $entityId,
            );

            return [
                'entry' => null,
                'accounts_closed' => 0,
                'result' => '0.0000',
                'note' => 'The year had no profit-or-loss movement, so no closing entry was needed.',
            ];
        }

        return DB::connection('tenant')->transaction(function () use (
            $entity,
            $entityId,
            $year,
            $balances,
            $functional
        ): array {
            /*
             | The last period is reopened to take the entry, then closed
             | again - through the state machine, so both moves are recorded
             | with their reason.
             |
             | The alternative is posting into a closed period, which the
             | database refuses and should: a close that can write into a
             | closed period is a mechanism anything else could borrow.
             */
            $last = $this->lastPeriodOf($entityId, (string) $year->getKey());

            if ($last === null) {
                throw YearEndCloseRefused::noPeriods((string) $year->label);
            }

            $wasState = $last->state;

            $this->periods->transition(
                $last,
                PeriodState::Open,
                sprintf('reopened to post the year-end closing entry for %s', (string) $year->label),
            );

            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: YearEndCloseRule::KEY,
                event: ['balances' => $balances],
                accountingDate: Carbon::parse((string) $year->ends_on),
                journalCode: self::CLOSING_JOURNAL,
                sourceType: 'year_end_close',
                sourceId: (string) $year->getKey(),
                narration: sprintf('Year-end close for %s (IAS 1)', (string) $year->label),
            );

            $this->periods->transition(
                $last->refresh(),
                $wasState,
                sprintf('closed again after posting the year-end entry for %s', (string) $year->label),
            );

            $result = (string) DB::connection('tenant')->table('journal_lines')
                ->where('journal_entry_id', $entry->getKey())
                ->where('account_id', $this->retainedEarningsAccount($entityId))
                ->value('functional_amount');

            $this->audit->record(
                category: 'ledger',
                action: 'year.closed',
                subjectType: FiscalYear::class,
                subjectId: (string) $year->getKey(),
                subjectLabel: (string) $year->label,
                after: [
                    'entry' => (string) $entry->number,
                    'accounts_closed' => count($balances),
                    'carried_to_retained_earnings' => $result,
                ],
                reason: sprintf(
                    'revenue and expense accounts zeroed into retained earnings; posted as an ordinary '.
                    'entry in the %s journal so it can be read, drilled into and reversed',
                    self::CLOSING_JOURNAL,
                ),
                entityId: $entityId,
            );

            return [
                'entry' => $entry,
                'accounts_closed' => count($balances),
                'result' => $result,
                'note' => sprintf(
                    '%d account(s) zeroed; %s %s carried to retained earnings. Reversible while the new '.
                    "year's first period is still open.",
                    count($balances),
                    $result,
                    $functional,
                ),
            ];
        });
    }

    /**
     * Reverse a close, while the new year has not been posted into.
     *
     * @throws YearEndCloseRefused
     */
    public function reopen(Entity $entity, FiscalYear $year, string $reason): JournalEntry
    {
        $entityId = (string) $entity->getKey();
        $existing = $this->existingClose($entityId, (string) $year->getKey());

        if ($existing === null) {
            throw YearEndCloseRefused::notClosed((string) $year->label);
        }

        if (mb_strlen(trim($reason)) < 20) {
            throw YearEndCloseRefused::reopeningNeedsAReason((string) $year->label);
        }

        /*
         | The window: the new year's first period must still be open.
         |
         | Once somebody has posted into the new year on top of the carried
         | forward balances, un-closing the old one changes figures those
         | postings were made against - and the new year's own trial balance
         | stops tying to the old year's closing one.
         */
        $nextYear = FiscalYear::query()
            ->where('entity_id', $entityId)
            ->where('starts_on', '>', $year->ends_on)
            ->orderBy('starts_on')
            ->first();

        if ($nextYear !== null && ! $this->firstPeriodIsStillOpen($entityId, (string) $nextYear->getKey())) {
            throw YearEndCloseRefused::newYearHasMovedOn(
                (string) $year->label,
                (string) $nextYear->label,
            );
        }

        /** @var JournalEntry $entry */
        $entry = JournalEntry::query()->findOrFail($existing->id);

        return DB::connection('tenant')->transaction(function () use (
            $entry,
            $entityId,
            $year,
            $reason
        ): JournalEntry {
            $period = $this->lastPeriodOf($entityId, (string) $year->getKey());

            if ($period === null) {
                throw YearEndCloseRefused::noPeriods((string) $year->label);
            }

            $wasState = $period->state;

            $this->periods->transition(
                $period,
                PeriodState::Open,
                sprintf('reopened to reverse the year-end close for %s: %s', (string) $year->label, $reason),
            );

            $reversal = $this->reverser->reverse(
                $entry,
                Carbon::parse((string) $year->ends_on),
                sprintf('year-end close reversed: %s', $reason),
            );

            $this->periods->transition(
                $period->refresh(),
                $wasState,
                sprintf('closed again after reversing the year-end entry for %s', (string) $year->label),
            );

            $this->audit->record(
                category: 'ledger',
                action: 'year.close.reversed',
                subjectType: FiscalYear::class,
                subjectId: (string) $year->getKey(),
                subjectLabel: (string) $year->label,
                before: ['entry' => (string) $entry->number],
                after: ['reversal' => (string) $reversal->number],
                reason: $reason,
                entityId: $entityId,
            );

            return $reversal;
        });
    }

    /**
     * The revenue and expense balances to zero.
     *
     * Excluding the closing journal, so a reversal followed by a re-close
     * does not read the reversal as ordinary activity and close it again.
     * By JOURNAL rather than by date: the closing entry is dated the last
     * day of the year, which is also when plenty of real transactions are.
     *
     * @return list<array{account_id: string, balance: string}>
     */
    public function closingBalances(string $entityId, FiscalYear $year): array
    {
        $dimensionColumns = implode(', ', array_map(
            static fn (int $axis): string => 'l.dimension_'.$axis,
            range(1, 8),
        ));

        $rows = DB::connection('tenant')->select(<<<SQL
            SELECT l.account_id, {$dimensionColumns}, sum(l.functional_amount) AS balance
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounts a ON a.id = l.account_id
            JOIN journals j ON j.id = e.journal_id
            WHERE l.entity_id = cast(? AS char(26))
              AND l.accounting_date BETWEEN ? AND ?
              AND e.status IN ('posted', 'reversed')
              AND a.type IN ('revenue', 'expense')
              AND j.code <> ?
            GROUP BY l.account_id, {$dimensionColumns}
            HAVING sum(l.functional_amount) <> 0
            ORDER BY l.account_id
        SQL, [
            $entityId,
            (string) $year->starts_on,
            (string) $year->ends_on,
            self::CLOSING_JOURNAL,
        ]);

        return $this->collapseToRequiredDimensions($entityId, $rows);
    }

    /**
     * Keep the dimensions each account REQUIRES, and collapse the rest.
     *
     * ## Why the close carries dimensions at all
     *
     * An account that requires a cost centre refuses a line without one -
     * including the closing line. Which is not an obstacle to work around:
     * it is the right answer. An expense account closed without its cost
     * centre leaves that cost centre's profit-or-loss never zeroed, so the
     * following year's dimensional P&L opens with last year's figures still
     * in it.
     *
     * ## And why only the required ones
     *
     * Grouping by all eight axes is also correct and multiplies the entry:
     * fifty cost centres by twenty projects is a thousand lines for one
     * account. The axes an account does not require are ones the business
     * said it does not need broken out, so they collapse - and the entry
     * stays proportional to what somebody declared they cared about.
     *
     * @param  list<object>  $rows
     * @return list<array{account_id: string, balance: string, dimensions: array<string, string|null>}>
     */
    private function collapseToRequiredDimensions(string $entityId, array $rows): array
    {
        /** @var array<string, list<int>> account id => required axis numbers */
        $required = [];

        foreach (
            DB::connection('tenant')->table('account_dimension_requirements as r')
                ->join('dimension_axes as a', 'a.id', '=', 'r.dimension_axis_id')
                ->where('r.entity_id', $entityId)
                ->get(['r.account_id', 'a.axis']) as $requirement
        ) {
            $required[(string) $requirement->account_id][] = (int) $requirement->axis;
        }

        $collapsed = [];

        foreach ($rows as $row) {
            $accountId = (string) $row->account_id;
            $dimensions = [];

            foreach ($required[$accountId] ?? [] as $axis) {
                $value = $row->{'dimension_'.$axis};
                $dimensions['dimension_'.$axis] = $value === null ? null : (string) $value;
            }

            // The group key: the account plus the dimensions that survive.
            $key = $accountId.'|'.implode('|', array_map(
                static fn (?string $value): string => $value ?? '-',
                $dimensions,
            ));

            if (! isset($collapsed[$key])) {
                $collapsed[$key] = [
                    'account_id' => $accountId,
                    'balance' => '0',
                    'dimensions' => $dimensions,
                ];
            }

            $collapsed[$key]['balance'] = (string) BigDecimal::of($collapsed[$key]['balance'])
                ->plus(BigDecimal::of((string) $row->balance));
        }

        return array_values(array_filter(
            $collapsed,
            static fn (array $row): bool => ! BigDecimal::of($row['balance'])->isZero(),
        ));
    }

    /**
     * The close entry for a year, if one is standing.
     *
     * ## Excluding reversals, which carry the same source
     *
     * The reverser copies `source_type` and `source_id` onto the reversal -
     * correctly, because a reversal is about the same thing as the entry it
     * reverses. Which meant the first version of this method found the
     * REVERSAL, reported the year as still closed, and made a re-close
     * impossible: "already closed by CLS-2026/00002", where 00002 was the
     * reversal of 00001.
     *
     * So the filter is on the posting rule as well. A reversal's rule is
     * `ledger.reversal`; only an entry from the close rule itself counts as
     * a close.
     */
    private function existingClose(string $entityId, string $yearId): ?object
    {
        return DB::connection('tenant')->table('journal_entries')
            ->where('entity_id', $entityId)
            ->where('source_type', 'year_end_close')
            ->where('source_id', $yearId)
            ->where('posting_rule', YearEndCloseRule::KEY)
            ->whereNull('reverses_entry_id')
            ->where('status', 'posted')
            ->first();
    }

    /** @return list<string> */
    private function openPeriodsIn(string $entityId, string $yearId): array
    {
        return DB::connection('tenant')->table('periods')
            ->where('entity_id', $entityId)
            ->where('fiscal_year_id', $yearId)
            ->whereIn('state', ['future', 'open', 'soft_closed'])
            ->orderBy('ordinal')
            ->pluck('label')
            ->map(static fn (mixed $label): string => (string) $label)
            ->all();
    }

    /**
     * The period the closing entry will land in.
     *
     * ## The one covering the year's last DAY, not period 13
     *
     * The first version returned the adjusting period, on the reasoning
     * that the close belongs after the last month. It does - and the entry
     * is dated the last day of the year, which is a calendar date, and the
     * poster resolves the period from the date. So it reopened period 13
     * and the poster wrote into December, which was closed.
     *
     * Reopening the period that actually takes the entry is the fix, and
     * the accounting is unaffected: period 13 carries no calendar days, so
     * its own entries are dated the year end too and are already inside the
     * balances this close reads. The close is simply the last thing posted
     * on that date.
     */
    private function lastPeriodOf(string $entityId, string $yearId): ?\App\Tenant\Ledger\Models\Period
    {
        $year = FiscalYear::query()->find($yearId);

        if ($year === null) {
            return null;
        }

        return \App\Tenant\Ledger\Models\Period::query()
            ->where('entity_id', $entityId)
            ->where('fiscal_year_id', $yearId)
            ->where('is_adjusting', false)
            ->where('starts_on', '<=', (string) $year->ends_on)
            ->where('ends_on', '>=', (string) $year->ends_on)
            ->first();
    }

    private function firstPeriodIsStillOpen(string $entityId, string $yearId): bool
    {
        $first = DB::connection('tenant')->table('periods')
            ->where('entity_id', $entityId)
            ->where('fiscal_year_id', $yearId)
            ->orderBy('ordinal')
            ->first();

        if ($first === null) {
            return true;
        }

        if (! in_array((string) $first->state, ['future', 'open'], true)) {
            return false;
        }

        /*
         | Open AND unposted.
         |
         | A period that is open and already carries entries has been
         | traded in, and un-closing the prior year changes the balances
         | those entries were made against.
         */
        return ! DB::connection('tenant')->table('journal_entries')
            ->where('entity_id', $entityId)
            ->where('period_id', $first->id)
            ->where('status', '<>', 'draft')
            ->exists();
    }

    private function retainedEarningsAccount(string $entityId): string
    {
        return (string) DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $entityId)
            ->where('role', 'retained_earnings')
            ->value('account_id');
    }
}
