<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Numbering\NumberFormat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The gap report - PF-071.
 *
 * ## The question this answers
 *
 * "Show me that invoice numbers 1 to 4,312 are all accounted for."
 *
 * It is the first thing asked in a tax audit in most of the jurisdictions
 * this product targets, and it is asked because a missing document number is
 * indistinguishable from a deleted document. The answer cannot be "we use a
 * gapless allocator"; it has to be a list.
 *
 * ## Two independent counts, compared
 *
 * The series row says how many numbers it issued. The documents say how many
 * exist. Comparing them is the whole check, and comparing them is only
 * meaningful because they are written by different things:
 *
 *   - `issued_count` is incremented under the series lock by the allocator
 *   - the document count comes from the table the documents are in
 *
 * A single source would make this a tautology. Two sources make it a test.
 *
 * ## Why a gap is reported and not repaired
 *
 * There is no safe repair. Renumbering issued documents changes what is on a
 * customer's invoice and in a tax return; inserting a filler document invents
 * a transaction. The only correct response is to explain the gap, which is a
 * human act - and usually the explanation is a crash between the allocation
 * and the insert, which is exactly what the surrounding transaction is meant
 * to prevent and worth knowing about.
 */
final class NumberSeriesReport
{
    /**
     * Every series for an entity, with its gap state.
     *
     * @return list<array{
     *     scope: string, key: string, branch: ?string, year: ?int,
     *     format: string, first: int, last: ?int, issued: int,
     *     documents: int, tracked: bool, expected: int, status: string,
     *     detail: ?string, ceiling: ?int, headroom: ?int,
     * }>
     */
    public function forEntity(string $entityId): array
    {
        $series = DB::connection('tenant')->table('number_series')
            ->where('entity_id', $entityId)
            ->orderBy('scope')
            ->orderBy('key')
            ->orderBy('year')
            ->get();

        $report = [];

        foreach ($series as $row) {
            $first = (int) $row->first_number;
            $last = $row->last_issued_number === null ? null : (int) $row->last_issued_number;
            $issued = (int) $row->issued_count;

            /*
             | How many numbers the range covers.
             |
             | Inclusive of both ends: a series that issued only number 1 has
             | a range of one, not zero. Getting this wrong by one reports a
             | gap on every clean series, and a report that always says
             | "gapped" is a report nobody reads.
             */
            $expected = $last === null ? 0 : ($last - $first + 1);

            /*
             | Null means the documents for this scope are not countable
             | yet, which is different from counting zero of them.
             |
             | Treating the two the same reported every series in an
             | untracked scope as gapped. On real data that was every series
             | but the journal's - a report of pure false positives, which
             | is how a report stops being run.
             */
            $documents = $this->documentsFor($entityId, (string) $row->scope, $row->year);

            $status = match (true) {
                $last === null => 'unchecked',
                $documents === null => 'untracked',
                $expected === $issued && $issued === $documents => 'clean',
                default => 'gapped',
            };

            $format = NumberFormat::of((string) $row->format);
            $ceiling = $format->ceiling();

            $report[] = [
                'scope' => (string) $row->scope,
                'key' => (string) $row->key,
                'branch' => $row->branch === null ? null : (string) $row->branch,
                'year' => $row->year === null ? null : (int) $row->year,
                'format' => (string) $row->format,
                'first' => $first,
                'last' => $last,
                'issued' => $issued,
                'documents' => $documents ?? 0,
                'tracked' => $documents !== null,
                'expected' => $expected,
                'status' => $status,
                'detail' => $this->explain($status, $expected, $issued, $documents ?? 0, $first, $last, (string) $row->scope),
                'ceiling' => $ceiling,
                /*
                 | How many numbers are left before the format runs out.
                 |
                 | Worth reporting because the format is FROZEN once the
                 | series has issued: a `{seq:3}` series that reaches 999 in
                 | October cannot be widened, and the only remedy is a new
                 | series - which is a decision somebody wants weeks of
                 | warning about, not an error at the till.
                 */
                'headroom' => $ceiling === null || $last === null ? null : $ceiling - $last,
            ];
        }

        return $report;
    }

    /**
     * Record the result on the series rows.
     *
     * Stored as well as returned, so "when was this last checked" is
     * answerable without re-running it - and so an auditor's question can be
     * answered from the row rather than from a report that has to be
     * regenerated in front of them.
     *
     * @return list<array<string, mixed>>
     */
    public function check(string $entityId): array
    {
        $report = $this->forEntity($entityId);

        foreach ($report as $line) {
            DB::connection('tenant')->table('number_series')
                ->where('entity_id', $entityId)
                ->where('scope', $line['scope'])
                ->where('key', $line['key'])
                ->where(fn ($query) => $line['year'] === null
                    ? $query->whereNull('year')
                    : $query->where('year', $line['year']))
                ->where(fn ($query) => $line['branch'] === null
                    ? $query->whereNull('branch')
                    : $query->where('branch', $line['branch']))
                ->update([
                    'gap_checked_at' => now(),
                    'gap_status' => $line['status'],
                    'gap_detail' => $line['detail'],
                    'updated_at' => now(),
                ]);
        }

        return $report;
    }

    /**
     * Which numbers in a series have no document.
     *
     * The list, not the count - because "there is a gap of three" is not an
     * answer anybody can act on, and the usual next question is which three.
     *
     * @return list<int>
     */
    public function missingNumbers(string $entityId, string $scope, string $key, ?int $year = null): array
    {
        $series = DB::connection('tenant')->table('number_series')
            ->where('entity_id', $entityId)
            ->where('scope', $scope)
            ->where('key', $key)
            ->where(fn ($query) => $year === null ? $query->whereNull('year') : $query->where('year', $year))
            ->first();

        if ($series === null || $series->last_issued_number === null) {
            return [];
        }

        $format = NumberFormat::of((string) $series->format);
        $entityCode = (string) DB::connection('tenant')->table('entities')
            ->where('id', $entityId)
            ->value('code');

        /*
         | Rendered, then compared with what the documents actually say.
         |
         | Comparing SEQUENCE numbers would need the number parsed back out
         | of each document, and a format with a branch or a month in it has
         | no single position to parse from. Rendering forward is
         | unambiguous: this is the string the allocator would have produced
         | for that sequence, and either a document carries it or none does.
         */
        $date = $series->year === null
            ? Carbon::now()
            : Carbon::create((int) $series->year, 1, 1);

        $issued = DB::connection('tenant')->table($this->tableFor($scope))
            ->where('entity_id', $entityId)
            ->pluck('number')
            ->map(static fn (mixed $n): string => (string) $n)
            ->all();

        $present = array_flip($issued);
        $missing = [];

        for ($n = (int) $series->first_number; $n <= (int) $series->last_issued_number; $n++) {
            $rendered = $format->render($n, $date, [
                'entity' => $entityCode,
                'branch' => (string) ($series->branch ?? ''),
                'key' => strtoupper($key),
            ]);

            if (! isset($present[$rendered])) {
                $missing[] = $n;
            }
        }

        return $missing;
    }

    /** Null when this scope's documents cannot be counted yet. */
    private function documentsFor(string $entityId, string $scope, ?int $year): ?int
    {
        $table = $this->tableFor($scope);

        if ($table === null) {
            return null;
        }

        return DB::connection('tenant')->table($table)
            ->where('entity_id', $entityId)
            ->when(
                $year !== null,
                fn ($query) => $query->whereRaw('extract(year from accounting_date) = ?', [$year]),
            )
            ->count();
    }

    /**
     * Which table a scope's documents live in.
     *
     * Only `journal` so far; invoices, credit notes and bills arrive with
     * their own phases. Returning null rather than zero, because a report
     * that counts zero documents for a scope it does not understand reports
     * every series in that scope as gapped - which it did, on real data,
     * for every series but the journal's.
     */
    private function tableFor(string $scope): ?string
    {
        return match ($scope) {
            'journal' => 'journal_entries',
            default => null,
        };
    }

    private function explain(
        string $status,
        int $expected,
        int $issued,
        int $documents,
        int $first,
        ?int $last,
        string $scope,
    ): ?string {
        if ($status === 'unchecked') {
            return 'The series has not issued anything yet.';
        }

        if ($status === 'untracked') {
            return sprintf(
                'Issued %d through %d. The documents for the "%s" scope cannot be counted by this report yet, '.
                'so a gap can neither be confirmed nor ruled out - which is reported rather than guessed at, '.
                'because a report that cries wolf is a report nobody runs.',
                $first,
                (int) $last,
                $scope,
            );
        }

        if ($status === 'clean') {
            return sprintf('%d through %d, all %d accounted for.', $first, (int) $last, $documents);
        }

        return sprintf(
            'The series covers %d through %d, which is %d numbers. It recorded issuing %d and %d document(s) '.
            'carry one of them. A gap is usually a crash between allocating a number and writing the row it '.
            'belonged to; it is reported rather than repaired, because renumbering issued documents changes '.
            'what is on a customer\'s invoice and inserting a filler invents a transaction.',
            $first,
            (int) $last,
            $expected,
            $issued,
            $documents,
        );
    }
}
