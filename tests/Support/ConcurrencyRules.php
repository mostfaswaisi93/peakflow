<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\PartyTaxRegistration;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tax\Services\TaxCodes;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The gapless proof, under real contention - PF-094.
 *
 * ## Why this suite exists at all
 *
 * Every other claim about the allocator can be made in one process: it
 * counts, it returns the number on a rollback, it holds one series at a time.
 * The claim that matters cannot. "No gaps and no duplicates when twenty
 * people issue at once" is a statement about two hundred transactions
 * contending for one row, and a loop in one process proves only that the
 * allocator can count - which nobody doubted.
 *
 * So the workers are real OS processes against real PostgreSQL, and the
 * assertion is on the set of numbers they produced.
 *
 * ## Why it is NOT part of `bin/verify all`
 *
 * It writes committed data - the workers are separate processes and cannot
 * see a transaction this one holds open - and it provisions nothing, so it
 * cleans up after itself through the database owner. That is slow and it
 * touches the cluster, the same reasons `rollback` is its own target.
 *
 * ## Why the teardown needs the owner role
 *
 * Because the application role cannot delete an issued invoice: the trigger
 * refuses and the role may not disable it. Which is PF-010 and PF-091
 * working - and it means a probe that creates real issued documents has to
 * clean up as the role that owns the tables, or not at all.
 */
final class ConcurrencyRules
{
    use \Tests\Support\Concerns\ProvesTheLedger;

    private const WORKERS = 20;

    private const PER_WORKER = 10;

    private string $entityId = '';

    private string $tenantId = '';

    private ?Entity $entity = null;

    private Carbon $date;

    public function __construct()
    {
        $this->date = Carbon::parse('2026-05-18');
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the gapless proof (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->tenantId = (string) $tenant->getKey();

        try {
            $this->seed();

            return $this->withTheLedgerProof([
                /*
                 | First, while every series this entity has is still
                 | uncreated: once the burst has run there is no first use
                 | left to contend over.
                 */
                'the first use of a series is not a race' => $this->firstUseIsNotARace(),
                '200 documents from 20 workers are exactly 1..200' => $this->theBurst(),
                'a failure past the lock point leaves the rest gapless' => $this->failuresPastTheLock(),
                'a crashed worker consumes no number' => $this->aCrashedWorker(),
                'one series under lock does not block another' => $this->twoSeriesDoNotBlock(),
            ], $this->entityId);
        } catch (Throwable $e) {
            return ['the gapless proof could not run' => [$e::class.': '.$e->getMessage()]];
        } finally {
            $this->teardown();
        }
    }

    /**
     * Two transactions creating one series row at the same moment.
     *
     * ## Why this is forced rather than raced
     *
     * The burst found this bug by accident - two workers created the journal
     * series at the same instant, one lost on the unique index, and that
     * worker's whole document failed. Re-running the burst did not reproduce
     * it: whether the two inserts land in the same window is a matter of
     * microseconds, and a check that finds a bug one run in three is a check
     * that gets re-run until it passes.
     *
     * So the interleaving is constructed. This process inserts the series row
     * and holds the transaction open; the worker cannot see it, tries to
     * insert its own, and **blocks on the unique index** - PostgreSQL makes a
     * conflicting insert wait for the other transaction to end. Once a
     * backend is actually waiting on a lock, this process commits, and the
     * worker's insert collides for certain.
     *
     * `INSERT ... ON CONFLICT DO NOTHING` followed by a re-read under the
     * lock survives it. A plain `INSERT` does not.
     *
     * @return list<string>
     */
    public function firstUseIsNotARace(): array
    {
        $connection = DB::connection('tenant');
        $connection->beginTransaction();

        try {
            /*
             | The JOURNAL series, not the invoice series.
             |
             | Because that is the one the burst caught: the sales journal's
             | number series is created by the first posting, so the race is
             | reached through the posting rule rather than through the
             | document's own series.
             */
            $connection->table('number_series')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'entity_id' => $this->entityId,
                'scope' => 'journal',
                'key' => 'SAL',
                'prefix' => 'SAL-',
                'padding' => 5,
                'format' => 'SAL-{YYYY}/{seq:5}',
                'branch' => null,
                'next_number' => 1,
                'first_number' => 1,
                'issued_count' => 0,
                'resets_annually' => true,
                'year' => $this->date->year,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $worker = $this->spawn('INV', 1, 'ok');
            $waited = $this->waitForABlockedBackend();

            $connection->commit();

            $lines = $this->collect([$worker], 120);
            $issued = array_values(array_filter($lines, static fn (array $l): bool => ($l['ok'] ?? false) === true));

            if (! $waited) {
                return ['no backend ever blocked on the series row, so the two inserts never collided '.
                    'and this check proved nothing. The worker may have failed to boot'];
            }

            if ($issued !== []) {
                return [];
            }

            return [sprintf(
                'a document could not be issued while another transaction was creating the same '.
                'numbering series: %s. First use of a series is a race - on the morning a new year\'s '.
                'series opens, every worker in the entity tries to create it at once - and losing it '.
                'must cost a wait, not a document',
                (string) ($lines[0]['error'] ?? 'the worker produced nothing'),
            )];
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    /**
     * Wait until some backend in this database is waiting on a lock.
     *
     * Returns false if none does within the timeout, which means the
     * interleaving this check depends on did not happen - reported rather
     * than passed, because a probe that cannot reach the thing it probes must
     * not report the absence of its own effect as success.
     */
    private function waitForABlockedBackend(float $seconds = 30.0): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            /*
             | `pg_locks`, not `pg_stat_activity`.
             |
             | Measured: polling `wait_event_type` saw nothing for the full
             | thirty seconds while the worker went on to issue its document
             | - the wait columns are not reliably visible to an unprivileged
             | role. An ungranted lock in `pg_locks` is readable by anybody
             | and is the same fact: some backend is waiting.
             */
            $waiting = DB::connection('tenant')->selectOne(
                'SELECT count(*) AS n FROM pg_locks WHERE NOT granted',
            );

            if ((int) ($waiting->n ?? 0) > 0) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    /** @return list<string> */
    public function theBurst(): array
    {
        $violations = [];
        $expected = self::WORKERS * self::PER_WORKER;
        $before = \count($this->sequenceNumbers('INV'));

        $started = microtime(true);
        $lines = $this->run(array_fill(0, self::WORKERS, ['INV', self::PER_WORKER, 'ok']));
        $elapsed = microtime(true) - $started;

        $issued = array_values(array_filter($lines, static fn (array $l): bool => ($l['ok'] ?? false) === true));
        $failed = array_values(array_filter($lines, static fn (array $l): bool => ($l['ok'] ?? false) !== true));

        if ($failed !== []) {
            $violations[] = sprintf(
                '%d of %d attempts failed. The first: %s',
                \count($failed), $expected, (string) ($failed[0]['error'] ?? 'no message'),
            );
        }

        $numbers = $this->sequenceNumbers('INV');

        if (\count($numbers) !== $before + $expected) {
            $violations[] = sprintf(
                'the series holds %d documents and should hold %d (%d before the burst, %d issued by it)',
                \count($numbers), $before + $expected, $before, $expected,
            );
        }

        $duplicates = array_keys(array_filter(array_count_values($numbers), static fn (int $n): bool => $n > 1));

        if ($duplicates !== []) {
            $violations[] = sprintf(
                'duplicate numbers: %s. Two documents with one legal number is the failure a sequence '.
                'cannot produce and a lock-free counter can',
                implode(', ', array_map('strval', \array_slice($duplicates, 0, 5))),
            );
        }

        $missing = array_values(array_diff(range(1, $before + $expected), $numbers));

        if ($missing !== []) {
            $violations[] = sprintf(
                'missing numbers: %s. A gap in an invoice series is a finding in a tax audit, and the '.
                'auditor\'s question is not why %d is missing but what you did with invoice %d',
                implode(', ', array_map('strval', \array_slice($missing, 0, 10))),
                $missing[0], $missing[0],
            );
        }

        if ($numbers !== range(1, \count($numbers))) {
            $violations[] = 'the numbers issued are not 1..n in order, so the series is not a sequence';
        }

        /*
         | The chain, too.
         |
         | Twenty workers each read "the previous document in this series"
         | while holding the series lock. If that read were not inside the
         | lock, two documents would claim the same previous hash - a chain
         | that forks, which verifies from either branch and is not a chain.
         |
         | Measured, and worth knowing: moving the chain link to BEFORE the
         | allocation does not break this. Two other things catch it first.
         | The posting step takes the sales journal's own number series lock,
         | which serialises every document in the entity anyway, and
         | `invoices (entity_id, series_key, chain_index)` is unique, so a
         | second document claiming one position is refused by the database.
         | The reordering is still a defect - it relies on another lock for
         | correctness - and it is caught by the source-order assertion in
         | `InvoiceIssuingRules`, which is where it belongs: a property that
         | only one of three overlapping guarantees provides should be
         | asserted on the code, not on an outcome that stays right for
         | reasons nobody chose.
         */
        $indices = Invoice::query()
            ->where('entity_id', $this->entityId)
            ->where('series_key', 'INV')
            ->orderBy('chain_index')
            ->pluck('chain_index')
            ->map(static fn (mixed $i): int => (int) $i)
            ->all();

        if ($indices !== range(1, \count($indices))) {
            $violations[] = 'the chain indices are not 1..n after the burst, so two workers claimed one '.
                'position in the chain';
        }

        $walk = app(\App\Tenant\Sales\Services\ChainVerifier::class)->verify($this->entityId, 'INV');

        if (! $walk['verified']) {
            $violations[] = sprintf(
                'the chain does not verify after the burst: %s',
                (string) ($walk['divergence']['reason'] ?? 'no reason given'),
            );
        }

        if ($violations === []) {
            fprintf(
                \STDERR,
                "        (%d documents from %d workers in %.1fs, %.0f/s)\n",
                $expected, self::WORKERS, $elapsed, $expected / max($elapsed, 0.001),
            );
        }

        return $violations;
    }

    /**
     * A third of the transactions fail AFTER the allocation.
     *
     * @return list<string>
     */
    public function failuresPastTheLock(): array
    {
        $violations = [];
        $before = \count($this->sequenceNumbers('INV'));

        /*
         | Six workers: four issue two documents each, two roll back two
         | each. The rolled-back ones allocate a number and then lose it,
         | which is the case `nextval()` cannot survive - it would leave four
         | holes in the series, and the holes would be permanent.
         */
        $lines = $this->run([
            ['INV', 2, 'ok'], ['INV', 2, 'ok'], ['INV', 2, 'ok'], ['INV', 2, 'ok'],
            ['INV', 2, 'rollback-after-lock'], ['INV', 2, 'rollback-after-lock'],
        ]);

        $rolledBack = array_values(array_filter(
            $lines,
            static fn (array $l): bool => ($l['rolled_back'] ?? false) === true,
        ));

        if (\count($rolledBack) !== 4) {
            $violations[] = sprintf(
                '%d transactions rolled back after the lock point and 4 were asked for, so this check '.
                'is not exercising what it claims',
                \count($rolledBack),
            );
        }

        foreach ($rolledBack as $line) {
            if (($line['allocated'] ?? '') === '') {
                $violations[] = 'a rolled-back worker never reached the allocation, so nothing was '.
                    'returned and nothing was proved';
            }
        }

        $numbers = $this->sequenceNumbers('INV');
        $added = \count($numbers) - $before;

        if ($added !== 8) {
            $violations[] = sprintf('%d documents were issued and 8 were expected', $added);
        }

        $missing = array_values(array_diff(range(1, \count($numbers)), $numbers));

        if ($missing !== []) {
            $violations[] = sprintf(
                'the series has gaps at %s after four transactions failed past the lock point. This is '.
                'the exact failure a PostgreSQL sequence produces, every time, and it is why the '.
                'counter is a row',
                implode(', ', array_map('strval', \array_slice($missing, 0, 10))),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function aCrashedWorker(): array
    {
        $violations = [];
        $before = $this->nextNumber('INV');
        $documents = \count($this->sequenceNumbers('INV'));
        $draftsBefore = $this->drafts();

        $lines = $this->run([['INV', 1, 'crash-after-lock']]);
        $crashed = array_values(array_filter(
            $lines,
            static fn (array $l): bool => ($l['crashed'] ?? false) === true,
        ));

        if ($crashed === []) {
            return ['the worker did not report reaching the allocation before it was killed, so this '.
                'check proved nothing'];
        }

        if ($this->nextNumber('INV') !== $before) {
            $violations[] = sprintf(
                'a worker killed with SIGKILL after allocating left the series at %s instead of %s. '.
                'The number it took is gone, and the gap is permanent',
                (string) $this->nextNumber('INV'), (string) $before,
            );
        }

        if (\count($this->sequenceNumbers('INV')) !== $documents) {
            $violations[] = 'a killed worker left a document behind';
        }

        if ($this->drafts() !== $draftsBefore) {
            $violations[] = sprintf(
                'a killed worker left a draft behind (%d -> %d), so the document it was writing is '.
                'half-written rather than absent',
                $draftsBefore, $this->drafts(),
            );
        }

        return $violations;
    }

    /**
     * Holding one series' lock must not stop another series issuing.
     *
     * @return list<string>
     */
    public function twoSeriesDoNotBlock(): array
    {
        $connection = DB::connection('tenant');
        $connection->beginTransaction();

        try {
            /*
             | This process takes the INV series lock and keeps it.
             |
             | Not a timing comparison: a check that asserts "B was faster
             | than A" fails on a loaded machine and teaches people to re-run
             | it. Holding A's lock for certain and asking B to finish is
             | either true or it hangs, and a hang is a failure with an
             | obvious cause.
             */
            app(NumberAllocator::class)->next($this->entityId, 'sales_document', 'INV', $this->date);

            $lines = $this->run([['CRN', 1, 'ok']], timeout: 60);
            $issued = array_values(array_filter($lines, static fn (array $l): bool => ($l['ok'] ?? false) === true));

            if ($issued === []) {
                return [sprintf(
                    'a credit note could not be issued while the invoice series was locked: %s. Two '.
                    'series sharing a lock means one document type stops the other, and the lock scope '.
                    'is meant to be one series',
                    (string) ($lines[0]['error'] ?? 'the worker produced nothing, which is what a block '.
                        'looks like from here'),
                )];
            }

            return [];
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Spawn the workers, wait, and collect what they said.
     *
     * @param  list<array{0: string, 1: int, 2: string}>  $workers  series, count, mode
     * @return list<array<string, mixed>>
     */
    private function run(array $workers, int $timeout = 300): array
    {
        $spawned = [];

        foreach ($workers as [$series, $count, $mode]) {
            $spawned[] = $this->spawn($series, $count, $mode);
        }

        return $this->collect(array_values(array_filter($spawned)), $timeout);
    }

    /**
     * One worker process, started and not waited for.
     *
     * @return array{process: resource, pipes: array<int, resource>}|null
     */
    private function spawn(string $series, int $count, string $mode): ?array
    {
        $base = \dirname(__DIR__, 2);

        $command = sprintf(
            'exec php %s %s %s %s %d %s',
            escapeshellarg($base.'/tests/Support/Concurrency/issue-worker.php'),
            escapeshellarg($this->tenantId),
            escapeshellarg($this->entityId),
            escapeshellarg($series),
            $count,
            escapeshellarg($mode),
        );

        $process = proc_open(
            ['bash', '-c', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $base,
        );

        if (! \is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * Wait for every worker and parse what it printed.
     *
     * @param  list<array{process: resource, pipes: array<int, resource>}|null>  $workers
     * @return list<array<string, mixed>>
     */
    private function collect(array $workers, int $timeout = 300): array
    {
        $running = [];

        foreach ($workers as $i => $worker) {
            if ($worker !== null) {
                $running[$i] = $worker;
            }
        }

        $output = array_fill_keys(array_keys($running), '');
        $errors = array_fill_keys(array_keys($running), '');
        $deadline = microtime(true) + $timeout;

        while ($running !== [] && microtime(true) < $deadline) {
            foreach ($running as $i => $worker) {
                $output[$i] .= (string) stream_get_contents($worker['pipes'][1]);
                $errors[$i] .= (string) stream_get_contents($worker['pipes'][2]);

                if (proc_get_status($worker['process'])['running'] === false) {
                    $output[$i] .= (string) stream_get_contents($worker['pipes'][1]);
                    $errors[$i] .= (string) stream_get_contents($worker['pipes'][2]);

                    fclose($worker['pipes'][1]);
                    fclose($worker['pipes'][2]);
                    proc_close($worker['process']);

                    unset($running[$i]);
                }
            }

            if ($running !== []) {
                usleep(20_000);
            }
        }

        foreach ($running as $i => $worker) {
            proc_terminate($worker['process'], 9);
            proc_close($worker['process']);
            $errors[$i] .= sprintf('(worker %d did not finish within %ds)', $i, $timeout);
        }

        $lines = [];

        foreach ($output as $i => $text) {
            foreach (explode("\n", $text) as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                $lines[] = \is_array($decoded)
                    ? $decoded
                    : ['ok' => false, 'error' => 'unparseable worker output: '.$line];
            }
        }

        /*
         | A worker that printed nothing still has to be heard.
         |
         | Its stderr is the only record of why - a boot failure, a fatal
         | error, a kill - and swallowing it turns "the series is short by
         | ten" into a mystery.
         */
        foreach ($errors as $i => $text) {
            $text = trim($text);

            if ($text !== '' && trim((string) $output[$i]) === '') {
                $lines[] = ['ok' => false, 'error' => sprintf('worker %d said: %s', $i, $text)];
            }
        }

        return $lines;
    }

    /**
     * The numeric part of every number issued in a series, in order.
     *
     * @return list<int>
     */
    private function sequenceNumbers(string $series): array
    {
        return Invoice::query()
            ->where('entity_id', $this->entityId)
            ->where('series_key', $series)
            ->whereNotNull('number')
            ->orderBy('number')
            ->pluck('number')
            ->map(static function (mixed $number): int {
                $parts = explode('/', (string) $number);

                return (int) end($parts);
            })
            ->all();
    }

    private function drafts(): int
    {
        return Invoice::query()
            ->where('entity_id', $this->entityId)
            ->where('status', 'draft')
            ->count();
    }

    private function nextNumber(string $series): ?string
    {
        $value = DB::connection('tenant')->table('number_series')
            ->where('entity_id', $this->entityId)
            ->where('scope', 'sales_document')
            ->where('key', $series)
            ->value('next_number');

        return $value === null ? null : (string) $value;
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'CC'.random_int(1000, 9999),
            'legal_name' => 'Peakflow Contention Co.',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->entity = $entity;
        $context->use($entity);

        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '310000000000003',
            'effective_from' => '2020-01-01',
            'is_primary' => true,
            'filing_frequency' => 'monthly',
        ]);

        app(ReturnPeriods::class)->generateYear($entity, 'SA', 2026);

        $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, $this->date);
        app(PeriodStateMachine::class)->transition($period, PeriodState::Open, 'opened for the gapless proof');

        $party = Party::create([
            'code' => 'CC-C-'.random_int(1000, 9999),
            'legal_name' => 'Acme Industrial LLC',
            'is_customer' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        PartyTaxRegistration::create([
            'party_id' => $party->getKey(),
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '300000000000003',
            'is_primary' => true,
            'validity' => PartyTaxRegistration::range(CarbonImmutable::parse('2019-01-01')),
        ]);

        app(TaxCodes::class)->introduce(
            entityId: $this->entityId,
            code: 'SA-S-15',
            category: TaxCategory::Standard,
            jurisdiction: 'SA',
            rate: '15.000000',
            from: CarbonImmutable::parse('2020-07-01'),
            labelAr: 'النسبة الأساسية',
            labelEn: 'Standard rate',
            source: 'the gapless proof',
            reportingBoxes: ['sa' => ['output' => 'standard_sales']],
        );
    }

    /**
     * Remove the probe's entity, as the role that owns the tables.
     *
     * The application role cannot: the trigger refuses to delete an issued
     * invoice and the role may not disable it, which is the guarantee doing
     * its job. The owner's credentials are the platform migrator's, already
     * in this process's configuration - no new secret, and no test-only
     * privilege granted to anybody.
     */
    private function teardown(): void
    {
        if ($this->entityId === '') {
            return;
        }

        try {
            config(['database.connections.probe_owner' => array_merge(
                (array) config('database.connections.tenant'),
                [
                    'username' => config('database.connections.migrator.username'),
                    'password' => config('database.connections.migrator.password'),
                ],
            )]);

            DB::purge('probe_owner');
            $owner = DB::connection('probe_owner');

            $immutable = ['invoices', 'invoice_lines', 'invoice_tax_breakdown', 'journal_entries', 'journal_lines'];

            /*
             | `ALTER TABLE ... DISABLE TRIGGER USER`, not
             | `session_replication_role`.
             |
             | Measured: the owner role is not a superuser - "permission
             | denied to set parameter session_replication_role" - which is
             | correct and is why the parameter is not how this is done. A
             | table's owner may disable its own triggers, and only for the
             | tables named here.
             */
            foreach ($immutable as $table) {
                if ($owner->getSchemaBuilder()->hasTable($table)) {
                    $owner->statement(sprintf('ALTER TABLE %s DISABLE TRIGGER USER', $table));
                }
            }

            foreach ([
                'invoice_tax_breakdown', 'invoice_lines', 'invoice_allowances_charges',
                'invoice_references', 'invoices',
                'journal_lines', 'journal_entries', 'account_balances',
                'number_series', 'return_periods', 'tax_codes', 'tax_rates',
                'account_roles', 'accounts', 'periods', 'fiscal_years',
                'entity_currencies', 'tax_registrations',

                /*
                 | `audit_log` is deliberately NOT in this list.
                 |
                 | It is append-only by trigger, and the chain head holds the
                 | hash of the last record written - so deleting this probe's
                 | records would leave the tenant's audit chain pointing at a
                 | record that no longer exists, and `audit:verify` would
                 | report a broken chain forever after. The records stay: they
                 | are an honest account of two hundred documents that were
                 | genuinely issued, and a teardown that mutilates an audit
                 | trail to tidy up is the exact lesson this system exists to
                 | refuse.
                 */
            ] as $table) {
                if ($owner->getSchemaBuilder()->hasTable($table)
                    && $owner->getSchemaBuilder()->hasColumn($table, 'entity_id')) {
                    $owner->table($table)->where('entity_id', $this->entityId)->delete();
                }
            }

            $owner->table('entities')->where('id', $this->entityId)->delete();

            foreach ($immutable as $table) {
                if ($owner->getSchemaBuilder()->hasTable($table)) {
                    $owner->statement(sprintf('ALTER TABLE %s ENABLE TRIGGER USER', $table));
                }
            }

            DB::purge('probe_owner');
        } catch (Throwable $e) {
            fprintf(
                \STDERR,
                "        (teardown left entity %s behind: %s)\n",
                $this->entityId,
                $e->getMessage(),
            );
        }
    }
}
