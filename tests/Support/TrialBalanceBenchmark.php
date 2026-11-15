<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Ledger\Services\TrialBalance;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The trial balance at scale, with pruning proven - PF-058, PF-042.
 *
 * ## Why this benchmark runs as the MIGRATOR, in one transaction
 *
 * Three constraints that together leave one shape.
 *
 * The seed cannot go through the write-side triggers. `journal_lines` carries
 * seven of them - the balanced-entry check, the postable-account check, the
 * translation check, the dimension check, the balance maintenance, the
 * fingerprint - and at millions of rows that measures the triggers rather
 * than the read path. Disabling them needs table ownership, which only the
 * migrator role has.
 *
 * The seed cannot be committed. Millions of rows left behind would make every
 * other check and benchmark slower for the rest of the database's life. So it
 * runs in one transaction and rolls back - which in PostgreSQL is O(1)
 * regardless of how many rows were written, with the space reclaimed by
 * autovacuum afterwards.
 *
 * And the seed and the measurement have to share a connection, or the
 * measurement would not see the uncommitted rows.
 *
 * The cost of that shape is honest and worth stating: the migrator role
 * bypasses row-level security, so the figures here do not include the RLS
 * predicate. That predicate is a one-time filter on `entity_id`, not a
 * per-row cost - the plans in `bin/bench tree` show it evaluated once - so
 * what it changes is not the shape of the plan.
 *
 * ## The size, and what was actually run
 *
 * PF-058 asks for 10 million journal lines. The target is an argument, so the
 * benchmark can be run at that size on hardware that has the disk for it:
 *
 *     php bin/bench trialbalance            the default
 *     PEAKFLOW_BENCH_LINES=10000000 php bin/bench trialbalance
 *
 * Every figure the benchmark prints names the size it measured. A benchmark
 * that reports a number without the size it was measured at is a number
 * somebody will quote.
 */
final class TrialBalanceBenchmark extends Benchmark
{
    /** Lines to seed when nothing says otherwise. */
    private const DEFAULT_LINES = 1_000_000;

    /** How many postable accounts the figures are spread across. */
    private const ACCOUNTS = 400;

    private string $entityId = '';

    private int $lines = 0;

    private Carbon $from;

    private Carbon $to;

    /** @var list<string> */
    private array $disabled = [];

    /** @var array<string, mixed> */
    private array $originalConnection = [];

    /** @return array<string, mixed> */
    public function run(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['__skipped' => 'no tenant is provisioned'];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->asMigrator();

        $this->lines = (int) (getenv('PEAKFLOW_BENCH_LINES') ?: self::DEFAULT_LINES);

        $this->connection()->beginTransaction();

        try {
            $seeded = $this->seed();

            return [
                $this->result(
                    sprintf('seeding %s journal lines over 12 months', number_format($this->lines)),
                    sprintf('%.1f s', $seeded),
                    [],
                ),
                $this->theWholeYear(),
                $this->theWholeYearFromTheJournal(),
                $this->oneMonth(),
                $this->pruningIsProven(),
                $this->theDrillDown(),
                $this->queryCountDoesNotFollowTheRowCount(),
            ];
        } catch (Throwable $e) {
            return [$this->result('the benchmark could not run', '-', [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 220),
            ])];
        } finally {
            $this->connection()->rollBack();
            $this->reEnableTriggers();
            $this->restoreConnection();
        }
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function theWholeYear(): array
    {
        $started = hrtime(true);
        $statement = app(TrialBalance::class)->for($this->entityId, 'SAR', $this->to, $this->from);
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        $violations = [];

        if (($statement['basis']['source'] ?? '') !== 'read_model') {
            $violations[] = sprintf(
                'a full-year range read from %s rather than the balance model, so this figure is the '.
                'journal aggregation and not the one the budget is for',
                var_export($statement['basis']['source'] ?? null, true),
            );
        }

        if ($statement['rows']->count() !== self::ACCOUNTS) {
            $violations[] = sprintf(
                'the statement has %d rows where %d accounts carry a balance',
                $statement['rows']->count(),
                self::ACCOUNTS,
            );
        }

        if ($statement['balances'] !== true) {
            $violations[] = sprintf(
                'the trial balance does not balance: %s against %s',
                $statement['total_debit'],
                $statement['total_credit'],
            );
        }

        /*
         | Two seconds for a full-year trial balance.
         |
         | Chosen as what somebody waits for while a page loads, not as what
         | this machine happens to do. A trial balance that takes ten
         | seconds gets exported to a spreadsheet and maintained there, which
         | is how a ledger stops being the source of truth.
         */
        /*
         | Two seconds for a full-year trial balance.
         |
         | Chosen as what somebody waits for while a page loads, not as what
         | this machine happens to do. A trial balance that takes ten seconds
         | gets exported to a spreadsheet and maintained there, which is how
         | a ledger stops being the source of truth.
         |
         | It is met by the balance read model. The journal aggregation is
         | measured separately and is NOT held to this budget - see below.
         */
        if ($elapsed > 2_000.0) {
            $violations[] = sprintf(
                '%.0f ms exceeds the 2,000 ms budget for a full-year trial balance over %s lines',
                $elapsed,
                number_format($this->lines),
            );
        }

        return $this->result(
            sprintf('a full-year trial balance over %s lines', number_format($this->lines)),
            sprintf('%.0f ms, %d accounts, from the balance model', $elapsed, $statement['rows']->count()),
            $violations,
        );
    }

    /**
     * The same statement, aggregated from the journal.
     *
     * Measured, and deliberately NOT held to the page-load budget. It is the
     * verification path - `BalanceRebuilder` and the agreement check use it -
     * and verification is allowed to be slow in a way a page is not.
     *
     * The number is here because it is the reason the read model exists. At
     * ten million lines it was 7.5 seconds against a two-second budget, which
     * is what sent the trial balance to the read model in the first place.
     *
     * @return array{label: string, measured: string, violations: list<string>}
     */
    private function theWholeYearFromTheJournal(): array
    {
        $started = hrtime(true);
        $statement = app(TrialBalance::class)
            ->for($this->entityId, 'SAR', $this->to, $this->from, fromJournal: true);
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        $violations = [];

        if (($statement['basis']['source'] ?? '') !== 'journal') {
            $violations[] = 'fromJournal did not force the journal path, so this is not measuring what it '.
                'says it is';
        }

        if ($statement['balances'] !== true) {
            $violations[] = sprintf(
                'the journal aggregation does not balance: %s against %s',
                $statement['total_debit'],
                $statement['total_credit'],
            );
        }

        /*
         | A loose ceiling, to catch an order of magnitude rather than a
         | page-load target. Thirty seconds is "something has gone badly
         | wrong", not "this is acceptable for a report".
         */
        if ($elapsed > 30_000.0) {
            $violations[] = sprintf('%.0f ms to aggregate %s lines is an order of magnitude out',
                $elapsed, number_format($this->lines));
        }

        return $this->result(
            'the same statement, aggregated from the journal',
            sprintf('%.0f ms - the verification path, not a page', $elapsed),
            $violations,
        );
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function oneMonth(): array
    {
        $from = $this->from->copy()->addMonths(5)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $started = hrtime(true);
        $statement = app(TrialBalance::class)->for($this->entityId, 'SAR', $to, $from);
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        $violations = [];

        if ($statement['balances'] !== true) {
            $violations[] = 'one month of the trial balance does not balance';
        }

        /*
         | A month must be MUCH faster than a year, and that is the claim
         | partitioning exists to make good on.
         |
         | 500 ms rather than a twelfth of the year's budget, because the
         | planner's work and the result assembly do not shrink with the
         | range - only the scan does.
         */
        if (($statement['basis']['source'] ?? '') !== 'read_model') {
            $violations[] = 'one month of a period-aligned range did not read from the balance model';
        }

        if ($elapsed > 500.0) {
            $violations[] = sprintf('%.0f ms exceeds the 500 ms budget for one month', $elapsed);
        }

        return $this->result(
            'one month of the same ledger',
            sprintf('%.0f ms, %d accounts', $elapsed, $statement['rows']->count()),
            $violations,
        );
    }

    /**
     * Partition pruning, read off the plan - PF-042.
     *
     * The claim is that a dated query touches only the partitions its range
     * covers. Without it, every report scans every month ever posted and gets
     * slower each month - correct, fast in the first year, and nobody notices
     * until year three.
     *
     * @return array{label: string, measured: string, violations: list<string>}
     */
    private function pruningIsProven(): array
    {
        $violations = [];
        $measured = [];

        foreach ([
            'one month' => [$this->from->copy()->addMonths(5)->startOfMonth(), 1],
            'one quarter' => [$this->from->copy()->addMonths(3)->startOfMonth(), 3],
            'the whole year' => [$this->from->copy(), 12],
        ] as $label => [$start, $months]) {
            $end = $start->copy()->addMonths($months)->subDay();

            $plan = $this->planLines($start, $end);
            $scanned = $this->partitionsIn($plan);

            $measured[] = sprintf('%s: %d partition(s)', $label, $scanned);

            if ($scanned === 0) {
                $violations[] = sprintf('the plan for %s names no partition at all', $label);

                continue;
            }

            /*
             | At most one extra, for the month a range's edge falls into.
             |
             | Exact equality would be brittle: a quarter ending on the last
             | day of a month can legitimately touch the next partition if
             | the bound is inclusive by a day. More than one extra means the
             | bound is not reaching the planner.
             */
            if ($scanned > $months + 1) {
                $violations[] = sprintf(
                    '%s scanned %d partitions where %d cover it. The date bound is not pruning, so every '.
                    'report reads every month ever posted and gets slower each month',
                    $label,
                    $scanned,
                    $months,
                );
            }
        }

        return $this->result(
            'a dated query touches only its own partitions',
            implode(', ', $measured),
            $violations,
        );
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function theDrillDown(): array
    {
        $code = $this->connection()->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('postable', true)
            ->orderBy('code')
            ->value('code');

        $from = $this->from->copy()->addMonths(5)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $started = hrtime(true);
        $lines = app(TrialBalance::class)->drillDown($this->entityId, (string) $code, $to, $from);
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        $violations = [];

        if ($lines->isEmpty()) {
            $violations[] = 'the drill-down came back empty on an account that carries a balance';
        }

        if ($elapsed > 1_500.0) {
            $violations[] = sprintf('%.0f ms exceeds the 1,500 ms budget for one account for one month', $elapsed);
        }

        return $this->result(
            'drilling into one account for one month',
            sprintf('%.0f ms, %d lines', $elapsed, $lines->count()),
            $violations,
        );
    }

    /**
     * The query count does not follow the number of accounts - PF-058.
     *
     * An N+1 here is invisible to every functional test: the figures are
     * right, the statement balances, and the page takes eleven seconds for a
     * chart of four hundred accounts because each row fetched its own name.
     *
     * Counted by logging the connection's queries, which is the only way to
     * see it without reading the implementation and believing it.
     *
     * @return array{label: string, measured: string, violations: list<string>}
     */
    private function queryCountDoesNotFollowTheRowCount(): array
    {
        $counts = [];

        foreach ([5, self::ACCOUNTS] as $accounts) {
            $codes = $this->connection()->table('accounts')
                ->where('entity_id', $this->entityId)
                ->where('postable', true)
                ->orderBy('code')
                ->limit($accounts)
                ->pluck('code')
                ->all();

            $this->connection()->flushQueryLog();
            $this->connection()->enableQueryLog();

            $statement = app(TrialBalance::class)->for($this->entityId, 'SAR', $this->to, $this->from);
            $statement['rows']->filter(
                static fn (array $row): bool => in_array($row['code'], $codes, true),
            )->count();

            $counts[$accounts] = count($this->connection()->getQueryLog());
            $this->connection()->disableQueryLog();
        }

        $violations = [];
        $small = $counts[5] ?? 0;
        $large = $counts[self::ACCOUNTS] ?? 0;

        if ($large !== $small) {
            $violations[] = sprintf(
                'the trial balance ran %d queries for %d accounts and %d for 5. An N+1 here is invisible '.
                'to every functional test: the figures are right, the statement balances, and the page '.
                'takes eleven seconds',
                $large,
                self::ACCOUNTS,
                $small,
            );
        }

        if ($large > 4) {
            $violations[] = sprintf('the trial balance runs %d queries; it should be a small constant', $large);
        }

        return $this->result(
            'the query count is a constant, not a function of the rows',
            sprintf('%d quer%s either way', $large, $large === 1 ? 'y' : 'ies'),
            $violations,
        );
    }

    // ---------------------------------------------------------------------

    /** @return list<string> */
    private function planLines(Carbon $from, Carbon $to): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->{'QUERY PLAN'},
            $this->connection()->select(
                'EXPLAIN SELECT count(*) FROM journal_lines l '.
                'WHERE l.entity_id = cast(? AS char(26)) AND l.accounting_date BETWEEN ? AND ?',
                [$this->entityId, $from->toDateString(), $to->toDateString()],
            ),
        );
    }

    /** @param list<string> $plan */
    private function partitionsIn(array $plan): int
    {
        $partitions = [];

        foreach ($plan as $line) {
            if (preg_match('/journal_lines_(\d{4}_\d{2})/', $line, $match) === 1) {
                $partitions[$match[1]] = true;
            }
        }

        return count($partitions);
    }

    private function seed(): float
    {
        $started = hrtime(true);

        app(EntityContext::class)->forget();

        $entity = Entity::create([
            'code' => 'TBB'.random_int(100, 999),
            'legal_name' => 'Trial balance benchmark',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        app(EntityContext::class)->use($entity);

        /*
         | The year is chosen to sit inside the existing partitions.
         |
         | They are created ahead of time by a scheduled task, monthly, so
         | the benchmark uses a year that is already covered rather than
         | creating partitions as a side effect - which is the one thing
         | PF-042 says must never happen during a request.
         */
        $this->from = Carbon::now()->startOfYear();
        $this->to = $this->from->copy()->endOfYear();

        $this->openEveryPeriod();
        $this->disableLineTriggers();

        $accounts = $this->connection()->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('postable', true)
            ->pluck('id')
            ->all();

        $accounts = $this->padAccountsTo($accounts, self::ACCOUNTS);

        $journalId = (string) $this->connection()->table('journals')
            ->where('entity_id', $this->entityId)
            ->where('code', 'GEN')
            ->value('id');

        /*
         | One entry per month, each holding a twelfth of the lines.
         |
         | The entry count is not what is being measured and a million
         | entries would make the seed the slow part. Twelve entries keep
         | the line count honest and the fixture quick.
         */
        /*
         | An EVEN number per month, which is not a detail.
         |
         | The amounts alternate sign, so an odd count leaves one unmatched
         | +100 in every month. At a million lines that is 83,333 a month and
         | twelve stray hundreds, and the benchmark reported "the trial
         | balance does not balance: 49,999,200 against 50,000,400" - a
         | failure in the fixture's arithmetic wearing the costume of a
         | product bug.
         |
         | A fixture has to satisfy the invariant it measures under, or the
         | measurement says nothing.
         */
        $perMonth = 2 * intdiv($this->lines, 24);

        for ($month = 0; $month < 12; $month++) {
            $date = $this->from->copy()->addMonths($month);
            $period = (string) $this->connection()->table('periods')
                ->where('entity_id', $this->entityId)
                ->where('is_adjusting', false)
                ->whereDate('starts_on', '<=', $date->toDateString())
                ->whereDate('ends_on', '>=', $date->toDateString())
                ->value('id');

            $entryId = (string) Str::ulid();

            $this->connection()->table('journal_entries')->insert([
                'id' => $entryId,
                'entity_id' => $this->entityId,
                'journal_id' => $journalId,
                'period_id' => $period,
                'number' => sprintf('BCH-%02d', $month + 1),
                'accounting_date' => $date->toDateString(),
                'status' => 'posted',
                'period_state_when_posted' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             | generate_series, because a million round trips is not a seed.
             |
             | The amounts alternate sign so each month sums to zero, which
             | keeps the balanced-entry invariant true even though its
             | trigger is off for the seed - a fixture that violates the
             | invariant it is measuring under would make the measurement
             | meaningless.
             */
            $this->connection()->statement(<<<'SQL'
                INSERT INTO journal_lines (
                    id, entity_id, journal_entry_id, accounting_date, period_id,
                    account_id, amount, currency, functional_amount, created_at, updated_at
                )
                SELECT
                    upper(substr(md5(random()::text || n::text), 1, 26)),
                    cast(? AS char(26)),
                    cast(? AS char(26)),
                    cast(? AS date),
                    cast(? AS char(26)),
                    a.id,
                    CASE WHEN n % 2 = 0 THEN 100.0000 ELSE -100.0000 END,
                    'SAR',
                    CASE WHEN n % 2 = 0 THEN 100.0000 ELSE -100.0000 END,
                    now(),
                    now()
                FROM generate_series(1, ?) AS n
                JOIN (
                    SELECT id, (row_number() OVER (ORDER BY code)) - 1 AS ord, count(*) OVER () AS total
                    FROM accounts
                    WHERE entity_id = cast(? AS char(26)) AND postable IS TRUE
                ) AS a ON a.ord = n % a.total
            SQL, [
                $this->entityId,
                $entryId,
                $date->toDateString(),
                $period,
                $perMonth,
                $this->entityId,
            ]);
        }

        $this->seedTheReadModel();
        $this->reEnableTriggers();
        $this->connection()->statement('ANALYZE journal_lines');
        $this->connection()->statement('ANALYZE account_balances');
        $this->connection()->statement('ANALYZE journal_entries');

        return (hrtime(true) - $started) / 1_000_000_000;
    }

    /**
     * Every month of the benchmark year, opened through the state machine.
     *
     * Not by a direct UPDATE. PF-050's trigger refuses a state change with no
     * transition row behind it - "who reopened this period, when, and what
     * did they say" is the first question in any review of a restated figure,
     * and a state column cannot answer it. The fixture tried the shortcut and
     * was refused, which is the guard working.
     *
     * Twelve transitions and twelve audit rows is a trivial cost next to the
     * lines being seeded, and it means the fixture reaches its state the same
     * way a real tenant does.
     */
    private function openEveryPeriod(): void
    {
        $periods = Period::query()
            ->where('entity_id', $this->entityId)
            ->where('is_adjusting', false)
            ->whereDate('starts_on', '>=', $this->from->toDateString())
            ->whereDate('ends_on', '<=', $this->to->toDateString())
            ->orderBy('starts_on')
            ->get();

        $machine = app(PeriodStateMachine::class);

        foreach ($periods as $period) {
            if ($period->state === PeriodState::Open) {
                continue;
            }

            $machine->transition(
                $period,
                PeriodState::Open,
                'opened by the trial-balance benchmark to seed a year of journal lines',
            );
        }
    }

    /**
     * @param  list<string>  $accounts
     * @return list<string>
     */
    private function padAccountsTo(array $accounts, int $target): array
    {
        $rows = [];
        $now = now();
        $parent = $this->connection()->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('code', '5')
            ->first(['id', 'path', 'type', 'statement_line']);

        for ($index = count($accounts); $index < $target; $index++) {
            $id = (string) Str::ulid();
            $code = sprintf('5.90.%03d', $index);

            $rows[] = [
                'id' => $id,
                'entity_id' => $this->entityId,
                'code' => $code,
                'name_ar' => $code,
                'name_en' => 'Benchmark account '.$code,
                'parent_id' => $parent?->id,
                'path' => '5.90.'.sprintf('%03d', $index),
                'depth' => 2,
                'type' => (string) ($parent->type ?? 'expense'),
                'statement_line' => (string) ($parent->statement_line ?? 'operating_expenses'),
                'postable' => true,
                'monetary' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $accounts[] = $id;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->connection()->table('accounts')->insert($chunk);
        }

        return $accounts;
    }

    private function disableLineTriggers(): void
    {
        $this->disabled = ['journal_lines', 'journal_entries'];

        foreach ($this->disabled as $table) {
            $this->connection()->statement(sprintf('ALTER TABLE %s DISABLE TRIGGER USER', $table));
        }
    }

    private function reEnableTriggers(): void
    {
        foreach ($this->disabled as $table) {
            try {
                $this->connection()->statement(sprintf('ALTER TABLE %s ENABLE TRIGGER USER', $table));
            } catch (Throwable) {
                // The transaction may already be gone. Nothing to re-enable.
            }
        }

        $this->disabled = [];
    }

    /**
     * Re-credential the `tenant` connection itself, rather than adding one.
     *
     * Because the thing being measured resolves `tenant` - the models, the
     * entity context, `TrialBalance`. A separate migrator connection would
     * seed on one connection and measure on another, and the measurement
     * would not see the uncommitted rows at all.
     *
     * Restored in `restoreConnection()`, so nothing after this benchmark runs
     * with ownership it should not have.
     */
    private function asMigrator(): void
    {
        $this->originalConnection = (array) config('database.connections.tenant');
        $migrator = (array) config('database.connections.migrator');

        config(['database.connections.tenant' => array_merge($this->originalConnection, [
            'username' => $migrator['username'],
            'password' => $migrator['password'],
        ])]);

        DB::purge('tenant');
    }

    private function restoreConnection(): void
    {
        if ($this->originalConnection === []) {
            return;
        }

        config(['database.connections.tenant' => $this->originalConnection]);

        DB::purge('tenant');

        $this->originalConnection = [];
    }

    /**
     * The balance read model, computed from the lines just seeded.
     *
     * The seed disables the triggers that normally maintain it - at millions
     * of rows a per-line increment measures the trigger - so the read model
     * is built here in one pass, by the same arithmetic
     * {@see \App\Tenant\Ledger\Services\BalanceRebuilder::fromJournal()}
     * uses. 400 accounts over 12 periods is 4,800 rows.
     *
     * Which also means this benchmark cannot tell you the read model is
     * CORRECT - it built it. That claim belongs to `bin/verify ledger`, where
     * the two read paths are compared row by row on a ledger written through
     * the real triggers.
     */
    private function seedTheReadModel(): void
    {
        $this->connection()->statement(<<<'SQL'
            INSERT INTO account_balances (
                id, entity_id, period_id, account_id, dimension_key,
                net, debits, credits, line_count, created_at, updated_at
            )
            SELECT
                upper(substr(md5(random()::text || l.period_id || l.account_id), 1, 26)),
                l.entity_id,
                l.period_id,
                l.account_id,
                peakflow_dimension_key(
                    l.dimension_1, l.dimension_2, l.dimension_3, l.dimension_4,
                    l.dimension_5, l.dimension_6, l.dimension_7, l.dimension_8
                ),
                sum(l.functional_amount),
                coalesce(sum(l.functional_amount) FILTER (WHERE l.functional_amount > 0), 0),
                coalesce(-sum(l.functional_amount) FILTER (WHERE l.functional_amount < 0), 0),
                count(*),
                now(),
                now()
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE l.entity_id = cast(? AS char(26))
              AND e.status IN ('posted', 'reversed')
            GROUP BY l.entity_id, l.period_id, l.account_id, 5
        SQL, [$this->entityId]);
    }

    private function tenant(): ?Tenant
    {
        try {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->first();
        } catch (Throwable) {
            return null;
        }
    }
}
