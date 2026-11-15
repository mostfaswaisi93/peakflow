<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rebuilding the balance read model, and finding where it drifted - PF-053.
 *
 * ## The rebuild IS the specification
 *
 * `account_balances` is maintained by triggers applying deltas. This class
 * computes the same figures from the journal in one pass, which means the
 * rebuild is the definition of what the increments should have produced -
 * and the drift check is the two put side by side.
 *
 * That is the only arrangement in which a read model can be trusted. A cache
 * with no independent recomputation is a second source of truth, and the
 * first time it disagrees with the journal nobody can say which is right.
 *
 * ## Drift is REPORTED, never repaired
 *
 * The tempting behaviour is to fix it. It is wrong for a reason that only
 * shows up afterwards: silent repair hides the bug that caused the drift, and
 * the bug is what matters. A figure that was wrong for a week was on a report
 * for a week, in an email, possibly in a filed return - and a repair erases
 * the only evidence of how long.
 *
 * So `check()` records findings and raises an alert. `rebuild()` exists and is
 * a deliberate, explicit act with its own audit entry.
 *
 * ## Three kinds of drift, which are three different bugs
 *
 *   missing  the journal has movement the read model does not - an increment
 *            that never ran, usually a write path that bypassed the trigger
 *   extra    the read model has a row the journal does not justify - a delta
 *            applied twice, or applied and then the line removed
 *   wrong    both have the row and the figures differ - an arithmetic error
 *            in the increment, which is the most alarming of the three
 */
final class BalanceRebuilder
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * The balances as the journal defines them.
     *
     * One query, grouped at the read model's own grain. The `status IN
     * ('posted', 'reversed')` bound is the same as the trial balance's, for
     * the same reason: `reversed` records that a reversing entry exists, not
     * that these lines were withdrawn.
     *
     * @return array<string, array{net: string, debits: string, credits: string, lines: int}>
     *     keyed by entity|period|account|dimension_key
     */
    public function fromJournal(?string $entityId = null): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                l.entity_id,
                l.period_id,
                l.account_id,
                peakflow_dimension_key(
                    l.dimension_1, l.dimension_2, l.dimension_3, l.dimension_4,
                    l.dimension_5, l.dimension_6, l.dimension_7, l.dimension_8
                ) AS dimension_key,
                sum(l.functional_amount) AS net,
                coalesce(sum(l.functional_amount) FILTER (WHERE l.functional_amount > 0), 0) AS debits,
                coalesce(-sum(l.functional_amount) FILTER (WHERE l.functional_amount < 0), 0) AS credits,
                count(*) AS lines
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE e.status IN ('posted', 'reversed')
              AND (cast(? AS char(26)) IS NULL OR l.entity_id = cast(? AS char(26)))
            GROUP BY l.entity_id, l.period_id, l.account_id, 4
        SQL, [$entityId, $entityId]);

        $balances = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                (string) $row->entity_id,
                (string) $row->period_id,
                (string) $row->account_id,
                (string) $row->dimension_key,
            ]);

            $balances[$key] = [
                'net' => (string) $row->net,
                'debits' => (string) $row->debits,
                'credits' => (string) $row->credits,
                'lines' => (int) $row->lines,
            ];
        }

        return $balances;
    }

    /** @return array<string, array{net: string, debits: string, credits: string, lines: int}> */
    public function stored(?string $entityId = null): array
    {
        $rows = DB::connection('tenant')->table('account_balances')
            ->when($entityId !== null, fn ($query) => $query->where('entity_id', $entityId))
            ->get(['entity_id', 'period_id', 'account_id', 'dimension_key', 'net', 'debits', 'credits', 'line_count']);

        $balances = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                (string) $row->entity_id,
                (string) $row->period_id,
                (string) $row->account_id,
                (string) $row->dimension_key,
            ]);

            $balances[$key] = [
                'net' => (string) $row->net,
                'debits' => (string) $row->debits,
                'credits' => (string) $row->credits,
                'lines' => (int) $row->line_count,
            ];
        }

        return $balances;
    }

    /**
     * Compare the two, record the differences, raise an alert.
     *
     * Repairs nothing. See the class note.
     *
     * @return array{
     *     checked: int,
     *     findings: list<array{kind: string, key: string, stored: ?string, rebuilt: ?string, difference: ?string}>,
     *     clean: bool,
     * }
     */
    public function check(?string $entityId = null): array
    {
        $rebuilt = $this->fromJournal($entityId);
        $stored = $this->stored($entityId);
        $findings = [];

        foreach ($rebuilt as $key => $expected) {
            $actual = $stored[$key] ?? null;

            if ($actual === null) {
                $findings[] = [
                    'kind' => 'missing',
                    'key' => $key,
                    'stored' => null,
                    'rebuilt' => $expected['net'],
                    'difference' => $expected['net'],
                ];

                continue;
            }

            /*
             | Compared as decimals, never as floats.
             |
             | `numeric` read into a float and compared against another
             | float is a comparison that reports drift on figures that
             | agree - and a drift check that cries wolf is a drift check
             | nobody runs, which is worse than not having one.
             */
            if ($this->differs($actual, $expected)) {
                $findings[] = [
                    'kind' => 'wrong',
                    'key' => $key,
                    'stored' => $actual['net'],
                    'rebuilt' => $expected['net'],
                    'difference' => (string) \Brick\Math\BigDecimal::of($actual['net'])
                        ->minus(\Brick\Math\BigDecimal::of($expected['net'])),
                ];
            }
        }

        foreach ($stored as $key => $actual) {
            if (! isset($rebuilt[$key])) {
                $findings[] = [
                    'kind' => 'extra',
                    'key' => $key,
                    'stored' => $actual['net'],
                    'rebuilt' => null,
                    'difference' => $actual['net'],
                ];
            }
        }

        if ($findings !== []) {
            $this->recordFindings($findings);
        }

        return [
            'checked' => count($rebuilt),
            'findings' => $findings,
            'clean' => $findings === [],
        ];
    }

    /**
     * Recompute from scratch.
     *
     * A deliberate act with its own audit entry, not something a check does
     * on the way past. The entry records how many rows were replaced,
     * because "the balances were rebuilt on the 14th" is the context for
     * every figure that changed that day.
     *
     * @return array{rows: int, replaced: int}
     */
    public function rebuild(?string $entityId = null): array
    {
        return DB::connection('tenant')->transaction(function () use ($entityId): array {
            $before = DB::connection('tenant')->table('account_balances')
                ->when($entityId !== null, fn ($query) => $query->where('entity_id', $entityId))
                ->count();

            /*
             | Deleted and reinserted rather than upserted.
             |
             | An upsert leaves behind any row the journal no longer
             | justifies - which is the `extra` drift, and a rebuild that
             | cannot clear it is a rebuild that cannot restore the
             | invariant it exists to restore.
             */
            DB::connection('tenant')->table('account_balances')
                ->when($entityId !== null, fn ($query) => $query->where('entity_id', $entityId))
                ->delete();

            $rebuilt = $this->fromJournal($entityId);
            $rows = [];

            foreach ($rebuilt as $key => $balance) {
                [$entity, $period, $account, $dimensionKey] = explode('|', $key, 4);

                $dimensions = explode('|', $dimensionKey);

                $row = [
                    'id' => (string) \Illuminate\Support\Str::ulid(),
                    'entity_id' => $entity,
                    'period_id' => $period,
                    'account_id' => $account,
                    'dimension_key' => $dimensionKey,
                    'net' => $balance['net'],
                    'debits' => $balance['debits'],
                    'credits' => $balance['credits'],
                    'line_count' => $balance['lines'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                foreach ($dimensions as $index => $value) {
                    $row['dimension_'.($index + 1)] = $value === '-' ? null : $value;
                }

                $rows[] = $row;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::connection('tenant')->table('account_balances')->insert($chunk);
            }

            $this->audit->record(
                category: 'ledger',
                action: 'balances.rebuilt',
                subjectType: 'account_balances',
                subjectLabel: $entityId === null ? 'every entity' : $entityId,
                before: ['rows' => $before],
                after: ['rows' => count($rows)],
                reason: 'the balance read model was recomputed from the journal; it is a cache, and the '.
                    'journal is the ledger',
                entityId: $entityId,
            );

            return ['rows' => count($rows), 'replaced' => $before];
        });
    }

    /**
     * @param  list<array{kind: string, key: string, stored: ?string, rebuilt: ?string, difference: ?string}>  $findings
     */
    private function recordFindings(array $findings): void
    {
        $rows = [];
        $now = now();

        foreach ($findings as $finding) {
            $parts = explode('|', $finding['key'], 4);

            $rows[] = [
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'entity_id' => $parts[0] ?? '',
                'period_id' => $parts[1] ?? null,
                'account_id' => $parts[2] ?? null,
                'dimension_key' => $parts[3] ?? null,
                'stored_net' => $finding['stored'],
                'rebuilt_net' => $finding['rebuilt'],
                'difference' => $finding['difference'],
                'kind' => $finding['kind'],
                'found_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('tenant')->table('balance_drift_findings')->insert($chunk);
        }

        /*
         | The alert.
         |
         | Audit log AND application log. The audit entry is append-only and
         | sits beside the figures it explains; the log line is what a
         | monitor watches. Neither is a substitute for the other, and the
         | one that matters in March is the first.
         */
        $this->audit->record(
            category: 'ledger',
            action: 'balances.drift_detected',
            subjectType: 'account_balances',
            subjectLabel: sprintf('%d drifted balance(s)', count($findings)),
            after: ['findings' => array_slice($findings, 0, 20)],
            reason: 'the balance read model disagrees with the journal. It was NOT repaired: a silent repair '.
                'hides the bug that caused the drift, and a figure that was wrong for a week was on a '.
                'report for a week. Run ledger:rebuild-balances deliberately once the cause is known.',
        );

        Log::error('Balance read model drift detected', [
            'findings' => count($findings),
            'kinds' => array_count_values(array_column($findings, 'kind')),
            'sample' => array_slice($findings, 0, 5),
        ]);
    }

    /**
     * @param  array{net: string, debits: string, credits: string, lines: int}  $actual
     * @param  array{net: string, debits: string, credits: string, lines: int}  $expected
     */
    private function differs(array $actual, array $expected): bool
    {
        foreach (['net', 'debits', 'credits'] as $figure) {
            if (! \Brick\Math\BigDecimal::of($actual[$figure])->isEqualTo(\Brick\Math\BigDecimal::of($expected[$figure]))) {
                return true;
            }
        }

        return $actual['lines'] !== $expected['lines'];
    }
}
