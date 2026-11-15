<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Numbering\Exceptions\InvalidNumberFormat;
use App\Support\Numbering\NumberFormat;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Ledger\Services\NumberSeriesReport;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What a numbering series guarantees - PF-071, PF-093.
 *
 * ## Why this is its own suite
 *
 * A missing document number is indistinguishable from a deleted document.
 * That is the whole reason numbering is not a sequence: `nextval()` does not
 * roll back, so a failed insert burns its number, and the resulting gap is a
 * question from an auditor that nobody can answer.
 *
 * Three claims get checked, in rough order of how expensive they are to get
 * wrong:
 *
 *   1. the numbers are consecutive, and a gap is FOUND rather than assumed
 *      away
 *   2. the shape of a series cannot change once it has issued
 *   3. the counter cannot be wound back
 *
 * The third is the one that reissues a number already printed on a
 * customer's invoice - two documents with one number, which is worse than a
 * gap because nothing about either document looks wrong.
 */
final class NumberingRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private Carbon $date;

    public function __construct()
    {
        $this->date = Carbon::now()->startOfYear()->addMonths(2);
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        /*
         | The format rules need no database at all, so they run even on a
         | machine with no tenant provisioned - which is most CI jobs, and
         | the reason a suite that skips wholesale when there is no tenant
         | ends up never running.
         */
        $results = ['a number format is validated before it can be used' => $this->formatsAreValidated()];

        $tenant = $this->tenant();

        if ($tenant === null) {
            return $results + ['numbering series (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $results + [
                'allocation records what it issued, not just where it is going' => $this->allocationIsRecorded(),
                'the shape of a series is frozen once it has issued' => $this->shapeIsFrozen(),
                'the counter cannot be wound back' => $this->counterOnlyMovesForward(),
                'a branch gets its own series rather than contending on one lock' => $this->branchesDoNotContend(),
                'a gap is found rather than assumed away' => $this->gapsAreFound(),
            ];
        } catch (Throwable $e) {
            return $results + ['numbering series could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function formatsAreValidated(): array
    {
        $violations = [];

        $rendered = NumberFormat::of('INV-{entity}-{YYYY}-{seq:6}')
            ->render(42, Carbon::parse('2026-03-10'), ['entity' => 'ACME']);

        if ($rendered !== 'INV-ACME-2026-000042') {
            $violations[] = sprintf('a format rendered as "%s" rather than INV-ACME-2026-000042', $rendered);
        }

        /*
         | The year comes from the DOCUMENT date.
         |
         | A document dated 31 December and entered on 2 January belongs to
         | December's series. Taking the year from the clock puts it in next
         | year's sequence and breaks that sequence's gaplessness at both
         | ends - the one property the mechanism exists to provide.
         */
        $newYear = NumberFormat::of('INV-{YYYY}-{seq:4}')->render(1, Carbon::parse('2025-12-31'));

        if ($newYear !== 'INV-2025-0001') {
            $violations[] = 'the year in a document number does not come from the document date, so a '.
                'document entered in January is numbered into the wrong year\'s series';
        }

        $refusals = [
            'INV-{YYYY}' => 'a format with no sequence',
            '{seq}-{seq:4}' => 'a format with two sequences',
            'INV-{YEAR}-{seq:4}' => 'a format with an unknown placeholder, which would print literally',
            '{seq:20}' => 'a sequence wider than the counter',
            '' => 'an empty format',
        ];

        foreach ($refusals as $template => $what) {
            try {
                NumberFormat::of($template);

                $violations[] = sprintf('%s was accepted ("%s")', $what, $template);
            } catch (InvalidNumberFormat) {
                // Correct.
            }
        }

        /*
         | A yearly reset with no year in the format.
         |
         | Not a malformed template - nothing about it looks wrong on its own
         | - and it issues `INV-00001` again every January. It can only be
         | caught where both facts are known, which is why there is a second
         | constructor that takes the reset policy.
         */
        try {
            NumberFormat::forSeries('INV-{seq:5}', resetsAnnually: true);

            $violations[] = 'a series that resets every year was allowed a format with no year in it, so it '.
                'will issue the same numbers again next January - and the duplicates are on documents';
        } catch (InvalidNumberFormat) {
            // Correct.
        }

        if (NumberFormat::of('X-{seq:3}')->ceiling() !== 999) {
            $violations[] = 'a format does not know how high it can count, so nothing can warn that a '.
                'three-digit series is about to run out - and the format cannot be widened once it has issued';
        }

        return $violations;
    }

    /** @return list<string> */
    public function allocationIsRecorded(): array
    {
        $allocator = app(NumberAllocator::class);
        $violations = [];

        $first = $allocator->next($this->entityId, 'chk', 'INV', $this->date);
        $allocator->next($this->entityId, 'chk', 'INV', $this->date);
        $third = $allocator->next($this->entityId, 'chk', 'INV', $this->date);

        if ($first !== 'INV-'.$this->date->year.'/00001' || $third !== 'INV-'.$this->date->year.'/00003') {
            $violations[] = sprintf('the numbers are not consecutive: got "%s" then "%s"', $first, $third);
        }

        $series = $this->series('INV');

        /*
         | All three facts, written under the same lock.
         |
         | `next_number` says where the series will go next; it cannot say
         | where it started or whether anything was skipped. The gap check
         | compares the count against the range, so a count that lags by one
         | reports a gap that is not there - which is a fortnight of
         | somebody's time.
         */
        if ((int) $series->last_issued_number !== 3) {
            $violations[] = sprintf(
                'after three allocations the series records %s as its last issued number',
                var_export($series->last_issued_number, true),
            );
        }

        if ((int) $series->issued_count !== 3) {
            $violations[] = sprintf('the series counted %d issues out of three', (int) $series->issued_count);
        }

        if ((int) $series->next_number !== 4) {
            $violations[] = 'the counter is not one past the last number issued, so the next document either '.
                'reuses a number or skips one';
        }

        return $violations;
    }

    /** @return list<string> */
    public function shapeIsFrozen(): array
    {
        $allocator = app(NumberAllocator::class);
        $allocator->next($this->entityId, 'chk', 'FRZ', $this->date);
        $series = $this->series('FRZ');
        $violations = [];

        $reshaped = $this->refusal(fn () => $this->connection->table('number_series')
            ->where('id', $series->id)
            ->update(['format' => 'FRZ-{YYYY}-{seq:7}']));

        if ($reshaped === null) {
            $violations[] = 'the format of a series that has already issued was changed. The numbers stay '.
                'consecutive in the counter and stop looking consecutive on the documents - FRZ-00002 '.
                'followed by FRZ-2026-0000003 - and nobody reading the file can tell whether documents are '.
                'missing or none are';
        }

        $reset = $this->refusal(fn () => $this->connection->table('number_series')
            ->where('id', $series->id)
            ->update(['resets_annually' => false]));

        if ($reset === null) {
            $violations[] = 'the reset policy of an issued series was changed. Turning an annual reset on '.
                'duplicates numbers already issued; turning it off leaves next year continuing a sequence a '.
                'jurisdiction requires to restart';
        }

        $start = $this->refusal(fn () => $this->connection->table('number_series')
            ->where('id', $series->id)
            ->update(['first_number' => 500]));

        if ($start === null) {
            $violations[] = 'the number an issued series started from was edited, and the gap check compares '.
                'against it - so a gap can be made to disappear by moving the goalposts';
        }

        /*
         | And a series that has issued NOTHING must still be configurable.
         |
         | A freeze that applies before the first document makes the format
         | unchangeable from the moment the row exists, which means it can
         | never be corrected at all.
         */
        $fresh = (string) Str::ulid();

        $this->connection->table('number_series')->insert([
            'id' => $fresh,
            'entity_id' => $this->entityId,
            'scope' => 'chk',
            'key' => 'NEW',
            'prefix' => 'NEW-',
            'padding' => 5,
            'format' => 'NEW-{YYYY}/{seq:5}',
            'next_number' => 1,
            'first_number' => 1,
            'issued_count' => 0,
            'resets_annually' => true,
            'year' => $this->date->year,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeIssuing = $this->refusal(fn () => $this->connection->table('number_series')
            ->where('id', $fresh)
            ->update(['format' => 'NEW-{YYYY}-{seq:6}']));

        if ($beforeIssuing !== null) {
            $violations[] = 'a series that has issued nothing could not be reshaped, so a format can never '.
                'be corrected: '.$beforeIssuing;
        }

        return $violations;
    }

    /** @return list<string> */
    public function counterOnlyMovesForward(): array
    {
        $allocator = app(NumberAllocator::class);

        $allocator->next($this->entityId, 'chk', 'BACK', $this->date);
        $allocator->next($this->entityId, 'chk', 'BACK', $this->date);
        $series = $this->series('BACK');

        $wound = $this->refusal(fn () => $this->connection->table('number_series')
            ->where('id', $series->id)
            ->update(['next_number' => 1]));

        /*
         | Two independent layers refuse this, and that was measured:
         | disabling the freeze trigger alone left the check passing,
         | because the `number_series_counter_is_ahead` CHECK also forbids
         | a counter behind the last number issued. Both had to be removed
         | before this check would report anything.
         |
         | Worth recording, because the obvious reading of a check that
         | survives a guard being disabled is that the check is weak.
         */
        if ($wound === null) {
            return ['the counter was wound back. The numbers in between are on documents that have been '.
                'issued, and reissuing one puts two documents in the file with the same number - which is '.
                'worse than a gap, because nothing about either document looks wrong'];
        }

        return [];
    }

    /** @return list<string> */
    public function branchesDoNotContend(): array
    {
        $allocator = app(NumberAllocator::class);
        $violations = [];

        $riyadh = $allocator->next($this->entityId, 'chk', 'RCT', $this->date, branch: 'RUH');
        $jeddah = $allocator->next($this->entityId, 'chk', 'RCT', $this->date, branch: 'JED');

        if ($riyadh !== $jeddah) {
            $violations[] = sprintf(
                'two branches shared one series: %s and %s. Where numbering is per branch, one series means '.
                'every invoice in the company waits behind every other',
                $riyadh,
                $jeddah,
            );
        }

        $count = $this->connection->table('number_series')
            ->where('entity_id', $this->entityId)
            ->where('key', 'RCT')
            ->count();

        if ($count !== 2) {
            $violations[] = sprintf('two branches produced %d series rather than two', $count);
        }

        /*
         | A null branch must be its own series, not a wildcard.
         |
         | NULL is not equal to NULL in a unique index, so without
         | `coalesce` two null-branch series would both be permitted - the
         | duplicate the index exists to prevent.
         */
        $allocator->next($this->entityId, 'chk', 'RCT', $this->date);

        $withNull = $this->connection->table('number_series')
            ->where('entity_id', $this->entityId)
            ->where('key', 'RCT')
            ->whereNull('branch')
            ->count();

        if ($withNull !== 1) {
            $violations[] = sprintf(
                'an unbranched series exists %d times, so the unique key treats a null branch as a wildcard '.
                'and two series can issue the same numbers',
                $withNull,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function gapsAreFound(): array
    {
        $allocator = app(NumberAllocator::class);
        $report = app(NumberSeriesReport::class);
        $violations = [];

        $clean = collect($report->forEntity($this->entityId))->firstWhere('scope', 'journal');

        if ($clean !== null && $clean['status'] === 'gapped') {
            $violations[] = 'the journal series is reported as gapped before anything has gone wrong: '.
                (string) $clean['detail'];
        }

        /*
         | Allocate a number and write no document.
         |
         | This IS the failure: a crash between the allocation and the insert.
         | The transaction normally prevents it, which is exactly why the
         | report has to be able to find it when something bypasses the
         | transaction - a restore, a bulk import, a killed process.
         */
        $allocator->next($this->entityId, 'journal', 'GEN', $this->date);

        $after = collect($report->check($this->entityId))->firstWhere('scope', 'journal');

        if ($after === null) {
            return ['the journal series disappeared from the report'];
        }

        if ($after['status'] !== 'gapped') {
            $violations[] = 'a number was allocated with no document written and the report says the series '.
                'is '.$after['status'].'. A missing number is indistinguishable from a deleted document, and '.
                'this is the report an auditor asks for';
        }

        if (! str_contains((string) $after['detail'], 'crash between allocating a number')) {
            $violations[] = 'the gap is reported without saying what it usually means, so whoever reads it '.
                'has to guess whether to investigate';
        }

        $recorded = $this->connection->table('number_series')
            ->where('entity_id', $this->entityId)
            ->where('scope', 'journal')
            ->value('gap_status');

        if ($recorded !== 'gapped') {
            $violations[] = 'the gap check did not record its result on the series, so "when was this last '.
                'checked" has to be answered by running it again in front of the auditor';
        }

        /*
         | A scope whose documents cannot be counted is NOT a gap.
         |
         | This is the check that was missing, and the absence cost a report
         | that was 100% false positives on real data: everything but the
         | journal was reported as gapped, because an uncountable scope
         | counted as zero documents. The earlier checks all used the
         | journal scope and so all passed.
         |
         | A report that cries wolf is a report nobody runs, which makes it
         | worse than no report - the auditor's question still gets asked
         | and now there is a tool everyone has learnt to ignore.
         */
        $untracked = collect($report->forEntity($this->entityId))
            ->filter(static fn (array $line): bool => $line['scope'] === 'chk');

        if ($untracked->isEmpty()) {
            $violations[] = 'the report lost the series in an untracked scope entirely, so a series issuing '.
                'numbers is invisible rather than merely uncheckable';
        }

        $wronglyGapped = $untracked->filter(
            static fn (array $line): bool => $line['status'] === 'gapped',
        );

        if ($wronglyGapped->isNotEmpty()) {
            $violations[] = sprintf(
                '%d series in a scope whose documents cannot be counted were reported as GAPPED. An '.
                'uncountable scope is not zero documents, and a report of false positives is one nobody runs',
                $wronglyGapped->count(),
            );
        }

        if ($untracked->contains(static fn (array $line): bool => $line['tracked'] === true)) {
            $violations[] = 'a series in an untracked scope claims its documents are tracked';
        }

        return $violations;
    }

    private function series(string $key): object
    {
        /** @var object $row */
        $row = $this->connection->table('number_series')
            ->where('entity_id', $this->entityId)
            ->where('key', $key)
            ->first();

        return $row;
    }

    /**
     * Run a write expected to be refused, in its own savepoint.
     *
     * A refused statement aborts the whole transaction, not just itself, so
     * without a savepoint the first successful refusal makes every later
     * check report "current transaction is aborted".
     */
    private function refusal(callable $write): ?string
    {
        try {
            $this->connection->transaction(function () use ($write): void {
                $write();

                throw new NumberingWriteAccepted();
            });
        } catch (NumberingWriteAccepted) {
            return null;
        } catch (Throwable $e) {
            return (string) preg_replace('/\s+/', ' ', $e->getMessage());
        }

        return null;
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'NUM'.random_int(100, 999),
            'legal_name' => 'Numbering check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $context->use($entity);
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

/** Thrown to roll back a write the database ACCEPTED. */
final class NumberingWriteAccepted extends \RuntimeException {}
