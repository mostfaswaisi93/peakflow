<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * A profit and loss grouped by two dimensions over a year - PF-046.
 *
 * ## The query a dimensional ledger exists to answer, and the one that hurts
 *
 * "Revenue and cost by cost centre and project, by month, for the year." It
 * is the report that justifies having dimensions at all, and it is the one
 * that goes quadratic if the read model is wrong: twelve periods times the
 * P&L accounts times every dimension pair that occurred.
 *
 * ## The fixture
 *
 * 20 cost centres, 40 projects, and 300 of the 800 possible pairs - because a
 * real ledger is sparse. Not every project runs in every cost centre, and a
 * benchmark on the dense cross-product would be measuring a shape nothing
 * has while being four times harder than the truth.
 *
 * Over twelve periods and the generic chart's P&L accounts, that is tens of
 * thousands of balance rows: the read model of an entity with a year of
 * dimensional postings behind it.
 *
 * ## What is asserted
 *
 * That the grouped read uses the balance read model's own index and is
 * bounded by the periods asked for - a report that scans every period ever
 * posted is a report that gets slower every month, which is the failure mode
 * nobody notices until year three.
 */
final class DimensionBenchmark extends Benchmark
{
    private const COST_CENTRES = 20;

    private const PROJECTS = 40;

    private const PAIRS = 300;

    private string $entityId = '';

    /** @var list<string> */
    private array $periodIds = [];

    /** @var list<string> */
    private array $allPeriods = [];

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

            if ($this->seeded === 0) {
                return ['__skipped' => 'the entity has no periods, so no year could be built'];
            }

            return [
                $this->result(
                    sprintf('building %d balance rows', $this->seeded),
                    sprintf('%.2f s', $built),
                    $built > 30.0 ? [sprintf('took %.1f s', $built)] : [],
                ),
                $this->plAndLByTwoDimensions(),
                $this->oneCostCentreAcrossTheYear(),
                $this->theYearIsBounded(),
            ];
        } catch (Throwable $e) {
            return [$this->result('the benchmark could not run', '-', [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ])];
        } finally {
            $this->connection()->rollBack();
        }
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function plAndLByTwoDimensions(): array
    {
        $placeholders = implode(',', array_fill(0, count($this->periodIds), '?'));

        $sql = <<<SQL
            SELECT
                b.period_id,
                b.account_id,
                b.dimension_1,
                b.dimension_2,
                sum(b.net)        AS net,
                sum(b.line_count) AS lines
            FROM account_balances b
            JOIN accounts a ON a.id = b.account_id
            WHERE b.entity_id = cast(? AS char(26))
              AND b.period_id IN ({$placeholders})
              AND a.type IN ('revenue', 'expense')
            GROUP BY b.period_id, b.account_id, b.dimension_1, b.dimension_2
            ORDER BY b.period_id, b.account_id
        SQL;

        $bindings = array_merge([$this->entityId], $this->periodIds);

        $timing = $this->time($sql, $bindings);
        $plan = $this->plan($sql, $bindings);
        $violations = [];

        if ($timing['rows'] === 0) {
            $violations[] = 'the report returned nothing, so the figure measures an empty scan';
        }

        /*
         | The budget: a quarter of a second for a year of a dimensional
         | P&L.
         |
         | Chosen as what a person waits for without reaching for the mouse
         | again, not as what this machine happens to do. A report that
         | takes two seconds gets exported to a spreadsheet and maintained
         | there instead, which is how a ledger stops being the source of
         | truth.
         */
        if ($timing['median_ms'] > 250.0) {
            $violations[] = sprintf(
                '%.1f ms exceeds the 250 ms budget for a year of a two-dimensional P&L',
                $timing['median_ms'],
            );
        }

        return $this->result(
            'P&L by month, cost centre and project (a year)',
            sprintf('%.1f ms, %d groups from %d rows', $timing['median_ms'], $timing['rows'], $this->seeded),
            $violations,
        );
    }

    /** @return array{label: string, measured: string, violations: list<string>} */
    private function oneCostCentreAcrossTheYear(): array
    {
        $placeholders = implode(',', array_fill(0, count($this->periodIds), '?'));

        $sql = <<<SQL
            SELECT b.period_id, sum(b.net) AS net
            FROM account_balances b
            WHERE b.entity_id = cast(? AS char(26))
              AND b.period_id IN ({$placeholders})
              AND b.dimension_1 = cast(? AS char(26))
            GROUP BY b.period_id
            ORDER BY b.period_id
        SQL;

        $centre = (string) $this->connection()->table('dimension_values')
            ->where('entity_id', $this->entityId)
            ->where('code', 'CC01')
            ->value('id');

        $bindings = array_merge([$this->entityId], $this->periodIds, [$centre]);

        $timing = $this->time($sql, $bindings);
        $violations = [];

        if ($timing['rows'] === 0) {
            $violations[] = 'no month had a balance for the first cost centre';
        }

        if ($timing['median_ms'] > 150.0) {
            $violations[] = sprintf('%.1f ms exceeds the 150 ms budget', $timing['median_ms']);
        }

        return $this->result(
            'one cost centre, month by month',
            sprintf('%.1f ms, %d months', $timing['median_ms'], $timing['rows']),
            $violations,
        );
    }

    /**
     * The report reads the year asked for, and an index exists that serves it.
     *
     * ## Two claims, and the second needs the planner held still
     *
     * The first is that the period bound narrows the read at all: the scan
     * must not examine the periods outside the year. That is measured against
     * a read model holding every period the entity has, of which the year is
     * a part.
     *
     * The second is that an index can serve it. On this fixture the planner
     * reasonably chooses a sequential scan - the year is a large fraction of
     * a small table, and reading it sequentially genuinely wins. Asserting an
     * index scan on the natural plan would be asserting the planner make a
     * worse choice, which is the mistake the tree benchmark made first.
     *
     * So the second claim is measured with `enable_seqscan` off, which asks a
     * different question: not "which plan is chosen today" but "is there an
     * index that serves this query at all". That is the property that decides
     * what happens in year three, when the table is large and the planner
     * does want an index.
     *
     * @return array{label: string, measured: string, violations: list<string>}
     */
    private function theYearIsBounded(): array
    {
        $placeholders = implode(',', array_fill(0, count($this->periodIds), '?'));

        $sql = <<<SQL
            SELECT count(*) AS n
            FROM account_balances b
            WHERE b.entity_id = cast(? AS char(26))
              AND b.period_id IN ({$placeholders})
        SQL;

        $bindings = array_merge([$this->entityId], $this->periodIds);
        $violations = [];

        /*
         | The expected count comes from somewhere ELSE.
         |
         | The first version computed it from the query under test, which
         | made the check self-referential: removing the period bound
         | entirely moved both numbers together and the check passed. This
         | counts through the query builder instead - a different
         | construction path - so the two can disagree.
         */
        $expected = $this->connection()->table('account_balances')
            ->where('entity_id', $this->entityId)
            ->whereIn('period_id', $this->periodIds)
            ->count();

        $inTheYear = (int) ($this->connection()->selectOne($sql, $bindings)->n ?? 0);

        if ($inTheYear !== $expected) {
            $violations[] = sprintf(
                'the year read returned %d rows where the year holds %d. A report that is not bounded by '.
                'the periods asked for is a report that grows every month',
                $inTheYear,
                $expected,
            );
        }

        $natural = $this->plan($sql, $bindings);

        /*
         | Allowed to discard what lies outside the year, and no more.
         |
         | A sequential scan discards exactly the rows of the other periods,
         | which is correct on this data. What this catches is a plan that
         | reads something else entirely - a join blown up, or a bound lost.
         */
        $outsideTheYear = $this->seeded - $inTheYear;

        if ($natural['rows_removed'] > $outsideTheYear) {
            $violations[] = sprintf(
                'the scan discarded %d rows while only %d lie outside the year, so it is reading more '.
                'than the table holds for this entity',
                $natural['rows_removed'],
                $outsideTheYear,
            );
        }

        $this->connection()->statement('SET LOCAL enable_seqscan = off');

        try {
            $forced = $this->plan($sql, $bindings);
        } finally {
            $this->connection()->statement('SET LOCAL enable_seqscan = on');
        }

        if ($forced['indexes'] === []) {
            $violations[] = 'with sequential scans disabled the query still uses no index, so no index '.
                'serves a period-bounded read of the balance model. It is fast on a year of data and '.
                'gets slower every month, which nobody notices until year three';
        }

        return $this->result(
            'a year is bounded, and an index serves it',
            sprintf('%d of %d rows in the year; forced plan uses %s',
                $inTheYear,
                $this->seeded,
                implode('+', $forced['indexes']) ?: 'no index'),
            $violations,
        );
    }

    private function build(): float
    {
        app(EntityContext::class)->forget();

        $entity = Entity::create([
            'code' => 'DIM'.random_int(100, 999),
            'legal_name' => 'Dimension benchmark',
            'country' => 'ZZ',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        app(EntityContext::class)->use($entity);
        $this->entityId = (string) $entity->getKey();

        $started = hrtime(true);

        /*
         | Every period is seeded; only a YEAR is reported on.
         |
         | Which is what makes "bounded by the year" a measurable claim. The
         | first version seeded exactly the periods it then queried, so a
         | query that ignored the bound entirely would have read the same
         | rows and passed.
         */
        $allPeriods = $this->connection()->table('periods')
            ->where('entity_id', $this->entityId)
            ->orderBy('starts_on')
            ->pluck('id')
            ->all();

        $this->allPeriods = array_map('strval', $allPeriods);
        $this->periodIds = array_slice($this->allPeriods, 0, 12);

        if ($this->periodIds === []) {
            return 0.0;
        }

        $centres = $this->axis(1, 'cc', 'CC', self::COST_CENTRES);
        $projects = $this->axis(2, 'pr', 'PR', self::PROJECTS);

        /*
         | A sparse sample of pairs, deterministically chosen.
         |
         | `$index * 7 % count` rather than a random pick, so two runs of the
         | benchmark measure the same shape. A benchmark whose fixture moves
         | cannot tell a regression from a reshuffle.
         */
        $pairs = [];

        foreach ($centres as $centreIndex => $centre) {
            for ($offset = 0; $offset < intdiv(self::PAIRS, count($centres)); $offset++) {
                $pairs[] = [$centre, $projects[($centreIndex * 3 + $offset) % count($projects)]];
            }
        }

        $accounts = $this->connection()->table('accounts')
            ->where('entity_id', $this->entityId)
            ->whereIn('type', ['revenue', 'expense'])
            ->where('postable', true)
            ->pluck('id')
            ->all();

        $rows = [];
        $now = now();
        $counter = 0;

        foreach ($this->allPeriods as $period) {
            foreach ($accounts as $account) {
                foreach ($pairs as [$centre, $project]) {
                    $counter++;

                    // Two of every three pairs touch a given account, which is
                    // what keeps the read model sparse rather than a dense
                    // cross-product nothing ever has.
                    if ($counter % 3 === 0) {
                        continue;
                    }

                    $amount = (string) (($counter % 977) + 1).'.0000';

                    $rows[] = [
                        'id' => (string) Str::ulid(),
                        'entity_id' => $this->entityId,
                        'period_id' => $period,
                        'account_id' => $account,
                        'dimension_1' => $centre,
                        'dimension_2' => $project,
                        'dimension_key' => $centre.'|'.$project.'|-|-|-|-|-|-',
                        'net' => $amount,
                        'debits' => $amount,
                        'credits' => '0.0000',
                        'line_count' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        /*
         | De-duplicated on the grain before inserting.
         |
         | `account_balances` is unique on (entity, period, account,
         | dimension_key) because that IS its grain, and the deterministic
         | pair sample repeats a pair - so the fixture has to respect the
         | grain the same way the trigger does.
         */
        $unique = [];

        foreach ($rows as $row) {
            $unique[$row['period_id'].'|'.$row['account_id'].'|'.$row['dimension_key']] = $row;
        }

        $rows = array_values($unique);
        $this->seeded = count($rows);

        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->connection()->table('account_balances')->insert($chunk);
        }

        $this->connection()->statement('ANALYZE account_balances');
        $this->connection()->statement('ANALYZE accounts');

        return (hrtime(true) - $started) / 1_000_000_000;
    }

    /** @return list<string> */
    private function axis(int $number, string $key, string $prefix, int $values): array
    {
        $axisId = (string) Str::ulid();

        $this->connection()->table('dimension_axes')->insert([
            'id' => $axisId,
            'entity_id' => $this->entityId,
            'axis' => $number,
            'key' => $key,
            'name_ar' => $key,
            'name_en' => $prefix,
            'leaf_only' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [];
        $ids = [];

        for ($index = 1; $index <= $values; $index++) {
            $id = (string) Str::ulid();
            $code = sprintf('%s%02d', $prefix, $index);
            $ids[] = $id;

            $rows[] = [
                'id' => $id,
                'entity_id' => $this->entityId,
                'dimension_axis_id' => $axisId,
                'code' => $code,
                'name_ar' => $code,
                'name_en' => $code,
                'path' => $code,
                'depth' => 0,
                'postable' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->connection()->table('dimension_values')->insert($rows);

        return $ids;
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
