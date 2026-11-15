<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The shared half of a benchmark: measure, and read the plan.
 *
 * ## A plan assertion and a stopwatch answer different questions
 *
 * The stopwatch says "this was fast on this machine on this run". The plan
 * says "this query reads the rows it needs and no others", which is the
 * property that still holds on a hundred times the data.
 *
 * So both are recorded, and a plan regression fails the benchmark even when
 * the stopwatch is happy - because on the data the benchmark has, a full scan
 * of a small table IS fast, and that is exactly how a scan reaches
 * production.
 */
abstract class Benchmark
{
    /** How many times a query is run; the MEDIAN is reported. */
    protected const RUNS = 7;

    protected function connection(): Connection
    {
        return DB::connection('tenant');
    }

    /**
     * Run a query a few times and report the median.
     *
     * The median rather than the mean, and certainly rather than the best:
     * the mean follows one unlucky run on a shared machine, and the best
     * reports a cache state nothing in production has.
     *
     * @param  list<mixed>  $bindings
     * @return array{median_ms: float, rows: int}
     */
    protected function time(string $sql, array $bindings = []): array
    {
        $timings = [];
        $rows = 0;

        // Warmed once, so the figure is not a measure of first-touch I/O.
        $this->connection()->select($sql, $bindings);

        for ($run = 0; $run < static::RUNS; $run++) {
            $started = hrtime(true);
            $result = $this->connection()->select($sql, $bindings);
            $timings[] = (hrtime(true) - $started) / 1_000_000;
            $rows = count($result);
        }

        sort($timings);

        return [
            'median_ms' => $timings[intdiv(count($timings), 2)],
            'rows' => $rows,
        ];
    }

    /**
     * The plan, flattened, with what the scan actually touched.
     *
     * `rows_examined` is the sum of what the scans returned before filtering,
     * which is the number that grows when a query stops using an index.
     * `rows_removed` is what was then thrown away - the direct measure of
     * work done for nothing.
     *
     * @param  list<mixed>  $bindings
     * @return array{nodes: list<string>, indexes: list<string>, rows_returned: int, rows_removed: int, sequential: bool}
     */
    protected function plan(string $sql, array $bindings = []): array
    {
        $lines = array_map(
            static fn (object $row): string => (string) $row->{'QUERY PLAN'},
            $this->connection()->select('EXPLAIN (ANALYZE) '.$sql, $bindings),
        );

        $nodes = [];
        $indexes = [];
        $returned = 0;
        $removed = 0;
        $sequential = false;

        foreach ($lines as $line) {
            if (preg_match('/->\s+([A-Z][A-Za-z ]+?)\s+(?:on|using|\()/', $line, $match) === 1) {
                $nodes[] = trim($match[1]);
            }

            /*
             | Read off the line, not off the captured node name.
             |
             | The capture is for display and depends on a regex matching the
             | rest of the line; this flag decides whether a benchmark
             | passes, so it reads the one string that is always there. The
             | first version used the capture and reported a sequential scan
             | on some runs and not others, on identical data.
             */
            if (str_contains($line, 'Seq Scan')) {
                $sequential = true;
            }

            /*
             | Both spellings. PostgreSQL writes "Index Scan using <name>"
             | and "Bitmap Index Scan on <name>", and a parser that knows
             | only the first reports "no index" for a perfectly good bitmap
             | plan - which it did, on a query that was using the index the
             | benchmark was asserting.
             */
            if (preg_match('/using ([\w.]+)/', $line, $match) === 1) {
                $indexes[] = $match[1];
            } elseif (preg_match('/Bitmap Index Scan on ([\w.]+)/', $line, $match) === 1) {
                $indexes[] = $match[1];
            }

            if (preg_match('/Rows Removed by Filter: (\d+)/', $line, $match) === 1) {
                $removed += (int) $match[1];
            }

            if (preg_match('/actual time=[\d.]+\.\.[\d.]+ rows=(\d+) loops=(\d+)/', $line, $match) === 1
                && $returned === 0) {
                $returned = (int) $match[1] * (int) $match[2];
            }
        }

        return [
            'nodes' => $nodes,
            'indexes' => array_values(array_unique($indexes)),
            'rows_returned' => $returned,
            'rows_removed' => $removed,
            'sequential' => $sequential,
        ];
    }

    /**
     * @param  list<string>  $violations
     * @return array{label: string, measured: string, violations: list<string>}
     */
    protected function result(string $label, string $measured, array $violations): array
    {
        return ['label' => $label, 'measured' => $measured, 'violations' => $violations];
    }
}
