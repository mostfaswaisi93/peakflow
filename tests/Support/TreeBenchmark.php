<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Services\AccountTree;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * The chart as a tree, at 5,000 accounts - PF-037.
 *
 * ## The shape built here, and why it is this shape
 *
 * Five statements, ten groups each, ten sub-groups each, nine postable
 * accounts each: 5,055 accounts, four levels. It is bigger than any real
 * chart and deliberately so - the point of a benchmark is the size nobody has
 * yet, because the size everybody has is fast under every implementation.
 *
 * Four levels rather than ten because a chart IS shallow. A benchmark on a
 * pathological depth would be measuring something nothing does.
 *
 * ## What is asserted
 *
 * The plan, first: a subtree read has to be an index scan whose bounds are
 * both in the index condition, and it has to examine roughly what it returns.
 * That last one is the assertion that matters. The `LIKE` form this replaced
 * ran in 0.4 ms here and read 5,055 rows to return 100 - fast, on this data,
 * and linear in the size of the chart rather than the subtree.
 *
 * Then the budget. Generous, because a runner is a shared machine; tight
 * enough to catch an order of magnitude.
 */
final class TreeBenchmark extends Benchmark
{
    private const ACCOUNTS = 5055;

    private string $entityId = '';

    private int $seeded = 0;

    /** @return array<string, mixed> */
    public function run(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['__skipped' => 'no tenant is provisioned'];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection()->beginTransaction();

        try {
            $built = $this->build();

            return [
                $this->result(
                    sprintf('building %d accounts', self::ACCOUNTS),
                    sprintf('%.2f s', $built),
                    $built > 20.0 ? [sprintf('took %.1f s to insert %d accounts', $built, self::ACCOUNTS)] : [],
                ),
                $this->subtreeOfAGroup(),
                $this->subtreeOfAStatement(),
                $this->directChildren(),
                $this->ancestors(),
                $this->rollUpAWholeStatement(),
                $this->movingASubtree(),
            ];
        } catch (Throwable $e) {
            return [$this->result('the benchmark could not run', '-', [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
                implode(' <- ', array_map(static fn (array $f): string => basename($f['file'] ?? '?').':'.($f['line'] ?? '?'), array_slice(array_values(array_filter($e->getTrace(), static fn (array $f): bool => isset($f['file']) && str_contains($f['file'], '/peakflow/'))), 0, 4))),
            ])];
        } finally {
            $this->connection()->rollBack();
        }
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function subtreeOfAGroup(): array
    {
        /*
         | The SQL comes from the SERVICE, not from this file.
         |
         | Written out by hand first, which made the benchmark a measurement
         | of a query nothing runs: changing `AccountTree` to use `LIKE`
         | again left every figure here unmoved. Taking the builder's own SQL
         | means the benchmark fails when the service regresses, which is the
         | only reason to have it.
         */
        [$sql, $bindings] = $this->queryOf((new AccountTree())->descendantsOf($this->entityId, '1.1'));

        $timing = $this->time($sql, $bindings);
        $plan = $this->plan($sql, $bindings);

        $violations = [];

        if ($timing['rows'] !== 100) {
            $violations[] = sprintf('returned %d rows where 100 belong', $timing['rows']);
        }

        if ($timing['rows'] === 0) {
            return $this->result('subtree of a group (100 of 5,055)', 'nothing returned', $violations);
        }

        if ($plan['sequential']) {
            $violations[] = 'the subtree read is a sequential scan, so its cost grows with the chart '.
                'rather than with the subtree. At 50,000 accounts it reads 50,000 to return 100';
        }

        /*
         | The ratio, which is the assertion that matters.
         |
         | A scan that examines 50 times what it returns is doing the work of
         | a full scan with the appearance of an index one. 1.5x allows for
         | the bound rows a range scan touches at each end.
         */
        if ($plan['rows_removed'] > (int) ($timing['rows'] * 0.5)) {
            $violations[] = sprintf(
                'the scan discarded %d rows to return %d. A range scan touches the subtree and its two '.
                'bounds; discarding more than that means the bounds are not in the index condition',
                $plan['rows_removed'],
                $timing['rows'],
            );
        }

        if ($timing['median_ms'] > 15.0) {
            $violations[] = sprintf('%.2f ms exceeds the 15 ms budget', $timing['median_ms']);
        }

        return $this->result(
            'subtree of a group (100 of 5,055)',
            sprintf('%.3f ms, %s, %d discarded',
                $timing['median_ms'],
                implode('+', $plan['indexes']) ?: 'no index',
                $plan['rows_removed']),
            $violations,
        );
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function subtreeOfAStatement(): array
    {
        [$sql, $bindings] = $this->queryOf((new AccountTree())->subtreeOf($this->entityId, '1'));

        $timing = $this->time($sql, $bindings);
        $plan = $this->plan($sql, $bindings);
        $violations = [];

        /*
         | No index assertion here, and that is the point of having both
         | cases.
         |
         | A whole statement is 1,011 of 5,055 rows - a fifth of the table -
         | and PostgreSQL reads a fifth of a small table sequentially because
         | that genuinely beats random index access. Asserting an index scan
         | here would be asserting the planner make a worse choice, and the
         | first version of this check did exactly that: it failed on the run
         | where the planner was right.
         |
         | What is asserted is the budget, and that nothing is discarded -
         | a scan that reads the table and throws away rows OUTSIDE the
         | subtree would mean the bounds are not being applied at all.
         */
        if ($plan['rows_removed'] > 5055 - 1011) {
            $violations[] = sprintf(
                'the scan discarded %d rows, which is more than the chart holds outside this statement',
                $plan['rows_removed'],
            );
        }

        if ($timing['median_ms'] > 30.0) {
            $violations[] = sprintf('%.2f ms exceeds the 30 ms budget', $timing['median_ms']);
        }

        return $this->result(
            'subtree of a statement (1,011 of 5,055)',
            sprintf('%.3f ms, %s',
                $timing['median_ms'],
                $plan['sequential']
                    ? 'seq scan - a fifth of the table, so this is the right plan'
                    : (implode('+', $plan['indexes']) ?: 'no index')),
            $violations,
        );
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function directChildren(): array
    {
        [$sql, $bindings] = $this->queryOf((new AccountTree())->childrenOf($this->entityId, '1.1', 1));

        $timing = $this->time($sql, $bindings);
        $violations = [];

        if ($timing['rows'] !== 10) {
            $violations[] = sprintf('returned %d children where 10 belong', $timing['rows']);
        }

        if ($timing['median_ms'] > 10.0) {
            $violations[] = sprintf('%.2f ms exceeds the 10 ms budget', $timing['median_ms']);
        }

        return $this->result(
            'the direct children of a group',
            sprintf('%.3f ms, %d rows', $timing['median_ms'], $timing['rows']),
            $violations,
        );
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function ancestors(): array
    {
        $tree = new AccountTree();

        $started = hrtime(true);
        $ancestors = $tree->ancestorsOf($this->entityId, '1.1.1.1')->get(['code', 'depth']);
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        $violations = [];
        $codes = $ancestors->pluck('code')->all();

        if ($codes !== ['1', '1.1', '1.1.1']) {
            $violations[] = sprintf(
                'the ancestors of 1.1.1.1 came back as [%s] rather than [1, 1.1, 1.1.1]',
                implode(', ', array_map('strval', $codes)),
            );
        }

        if ($elapsed > 10.0) {
            $violations[] = sprintf('%.2f ms exceeds the 10 ms budget', $elapsed);
        }

        return $this->result(
            'the ancestors of a leaf, outermost first',
            sprintf('%.3f ms, %d levels', $elapsed, count($codes)),
            $violations,
        );
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function rollUpAWholeStatement(): array
    {
        $tree = new AccountTree();

        $started = hrtime(true);
        $rolled = $tree->rollUp($this->entityId, '1');
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        $violations = [];

        if ($elapsed > 40.0) {
            $violations[] = sprintf(
                '%.2f ms exceeds the 40 ms budget. A statement is twenty of these, so the budget is the '.
                'per-line share of a report that has to render while somebody waits',
                $elapsed,
            );
        }

        if ($rolled['accounts'] === 0) {
            $violations[] = 'the roll-up summed nothing, so the figure above measures an empty join '.
                'rather than a roll-up';
        }

        return $this->result(
            'rolling up a statement from the read model',
            sprintf('%.3f ms, %d accounts over %d balance rows',
                $elapsed, $rolled['accounts'], $this->seeded),
            $violations,
        );
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function movingASubtree(): array
    {
        $tree = new AccountTree();

        $moving = Account::query()
            ->where('entity_id', $this->entityId)
            ->where('code', '1.1')
            ->firstOrFail();

        /*
         | A fresh root, because every existing group is already called X.1.
         |
         | Moving `1.1` under `1.2` wants the path `1.2.1`, which the
         | sub-group `1.2.1` already holds - so the move is refused, and
         | correctly. The benchmark is measuring the rewrite, not the guard,
         | so it moves into somewhere free.
         */
        $into = Account::query()->create([
            'entity_id' => $this->entityId,
            'code' => '9',
            'name_ar' => '9',
            'name_en' => 'Reorganised',
            'parent_id' => null,
            'path' => '9',
            'depth' => 0,
            'type' => 'asset',
            'statement_line' => 'assets',
            'postable' => false,
            'monetary' => false,
        ]);

        $started = hrtime(true);
        $moved = $tree->move($this->entityId, $moving, $into);
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        $violations = [];

        if ($moved !== 101) {
            $violations[] = sprintf('%d accounts moved where 101 belong', $moved);
        }

        /*
         | The cost of a materialized path, measured.
         |
         | One UPDATE over the range, so this is proportional to the subtree.
         | A leaf under the moved group has to come out with the new path and
         | the right depth - the arithmetic is a shift, and a shift that is
         | wrong by one puts every descendant at the wrong level in every
         | report that groups.
         */
        $leaf = Account::query()
            ->where('entity_id', $this->entityId)
            ->where('code', '1.1.1.1')
            ->first(['path', 'depth']);

        if ($leaf === null || (string) $leaf->path !== '9.1.1.1') {
            $violations[] = sprintf(
                'after moving 1.1 under 9, the leaf 1.1.1.1 has the path %s where 9.1.1.1 belongs',
                $leaf === null ? '(gone)' : '"'.(string) $leaf->path.'"',
            );
        }

        if ($leaf !== null && (int) $leaf->depth !== 3) {
            $violations[] = sprintf('the moved leaf is at depth %d rather than 3', (int) $leaf->depth);
        }

        if ($elapsed > 60.0) {
            $violations[] = sprintf('%.2f ms exceeds the 60 ms budget for moving 101 accounts', $elapsed);
        }

        return $this->result(
            'moving a 101-account subtree, paths rewritten',
            sprintf('%.3f ms, %d accounts', $elapsed, $moved),
            $violations,
        );
    }

    /**
     * An Eloquent builder's own SQL and bindings.
     *
     * So that the thing measured is the thing the application runs. The
     * builder is never executed here - `EXPLAIN` and the stopwatch take the
     * SQL directly, which also means a benchmark cannot accidentally measure
     * Eloquent's hydration instead of the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Tenant\Ledger\Models\Account>  $query
     * @return array{0: string, 1: list<mixed>}
     */
    private function queryOf(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return [$query->toSql(), array_values($query->getBindings())];
    }

    private function build(): float
    {
        app(EntityContext::class)->forget();

        $entity = Entity::create([
            'code' => 'BCH'.random_int(100, 999),
            'legal_name' => 'Tree benchmark',
            'country' => 'ZZ',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        app(EntityContext::class)->use($entity);
        $this->entityId = (string) $entity->getKey();

        // The provisioned chart is cleared so the shape is the benchmark's own.
        $this->connection()->table('account_roles')->where('entity_id', $this->entityId)->delete();
        $this->connection()->table('journals')->where('entity_id', $this->entityId)->delete();

        foreach ($this->connection()->table('accounts')
            ->where('entity_id', $this->entityId)
            ->orderByDesc('depth')->pluck('id') as $id) {
            $this->connection()->table('accounts')->where('id', $id)->delete();
        }

        $started = hrtime(true);
        $rows = [];

        $add = function (string $code, ?string $parentId, int $depth, string $type, bool $postable)
            use (&$rows): string {
            $id = (string) Str::ulid();

            $rows[] = [
                'id' => $id,
                'entity_id' => $this->entityId,
                'code' => $code,
                'name_ar' => $code,
                'name_en' => 'Account '.$code,
                'parent_id' => $parentId,
                'path' => $code,
                'depth' => $depth,
                'type' => $type,
                'statement_line' => $type === 'asset' ? 'assets' : 'other',
                'postable' => $postable,
                'monetary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            return $id;
        };

        foreach (['asset', 'liability', 'equity', 'revenue', 'expense'] as $index => $type) {
            $statement = (string) ($index + 1);
            $statementId = $add($statement, null, 0, $type, false);

            for ($group = 1; $group <= 10; $group++) {
                $groupCode = $statement.'.'.$group;
                $groupId = $add($groupCode, $statementId, 1, $type, false);

                for ($sub = 1; $sub <= 10; $sub++) {
                    $subCode = $groupCode.'.'.$sub;
                    $subId = $add($subCode, $groupId, 2, $type, false);

                    for ($leaf = 1; $leaf <= 9; $leaf++) {
                        $add($subCode.'.'.$leaf, $subId, 3, $type, true);
                    }
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->connection()->table('accounts')->insert($chunk);
        }

        /*
         | ANALYZE, deliberately.
         |
         | Without it the planner has no statistics for a table that was
         | created inside this transaction and will choose a plan from
         | defaults - so the benchmark would measure the planner's ignorance
         | rather than the index.
         */
        $this->seedBalances();

        $this->connection()->statement('ANALYZE accounts');
        $this->connection()->statement('ANALYZE account_balances');

        return (hrtime(true) - $started) / 1_000_000_000;
    }

    /**
     * A balance per leaf account per period, for statement 1.
     *
     * Because a roll-up over an EMPTY read model measures a join that finds
     * nothing - which the first version of this benchmark did, reporting
     * "0 accounts with balances" in 0.9 ms and asserting nothing at all.
     *
     * 900 leaves across the entity's periods is a read model the size a
     * mid-sized entity really has after a year.
     */
    private function seedBalances(): void
    {
        $periods = $this->connection()->table('periods')
            ->where('entity_id', $this->entityId)
            ->orderBy('starts_on')
            ->limit(12)
            ->pluck('id')
            ->all();

        if ($periods === []) {
            return;
        }

        $leaves = $this->connection()->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('depth', 3)
            ->where('path', '>=', '1.')
            ->where('path', '<', AccountTree::subtreeEnd('1'))
            ->pluck('id')
            ->all();

        $rows = [];
        $now = now();

        foreach ($periods as $period) {
            foreach ($leaves as $index => $account) {
                $amount = (string) (($index % 97) + 1).'.0000';

                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'entity_id' => $this->entityId,
                    'period_id' => $period,
                    'account_id' => $account,
                    'dimension_key' => '-|-|-|-|-|-|-|-',
                    'net' => $amount,
                    'debits' => $amount,
                    'credits' => '0.0000',
                    'line_count' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->seeded = count($rows);

        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->connection()->table('account_balances')->insert($chunk);
        }
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
