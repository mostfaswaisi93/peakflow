<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Enums\TenantStatus;
use App\Platform\Models\ActivityLog;
use App\Platform\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Discard a tenant whose provisioning was abandoned - PF-019.
 *
 * ## The gap this closes
 *
 * `TenantProvisioner::create()` undoes its own work when a step throws: the
 * role, the database and the registry row all go. What it cannot undo is a
 * provisioning run that never reached its `catch` - the process was killed,
 * the container was reclaimed, the operator closed the tab. The rollback
 * closures lived in that process's memory, so nothing runs and the attempt
 * stops half-made: a registry row in `provisioning`, a live database on the
 * cluster, a login role with a password only that dead process knew.
 *
 * It was found the way these things are found. A migration with a broken
 * heredoc failed one provisioning run; the run was killed before it could
 * tidy up; and from then on nine checks in `bin/verify tenant` failed on
 * every run, each one naming that abandoned database and each one telling
 * the truth - the application role really does still hold UPDATE on its
 * `audit_log`, because `harden()` never got that far.
 *
 * ## Why it is a command and not a sweep on boot
 *
 * This drops databases. An abandoned attempt and a provisioning run that is
 * still going look identical from the registry - both are a row in
 * `provisioning` with no `provisioned_at` - and the only thing that tells
 * them apart is how long ago it started. A sweep that guessed wrong would
 * drop the database out from under a provisioning run in progress, which is
 * a worse failure than the orphan it was cleaning up. So the operator names
 * the age they are willing to call abandoned, and the default is an hour:
 * longer than any provisioning run this product has ever taken.
 *
 * ## What it will not touch
 *
 * A tenant with `provisioned_at` set, whatever its status. A suspended or
 * closed tenant still has a customer's ledger in it, and "closed" is a
 * billing state, not permission to drop the database. Discarding one of
 * those is the archive path, which exports before it deletes.
 */
final class TenantsDiscardAbandoned extends Command
{
    protected $signature = 'tenants:discard-abandoned
        {--older-than=60 : Only attempts that started this many minutes ago}
        {--slug= : One attempt, by slug}
        {--pretend : List what would go, change nothing}
        {--force : Do not ask}';

    protected $description = 'Drop the database, role and registry row left by a provisioning run that never finished';

    public function handle(): int
    {
        $minutes = max(0, (int) $this->option('older-than'));
        $pretend = (bool) $this->option('pretend');

        $abandoned = $this->abandoned($minutes);

        if ($abandoned === []) {
            $this->info('No abandoned provisioning attempts.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Abandoned provisioning attempts:');

        foreach ($abandoned as $tenant) {
            $this->line(sprintf(
                '  %-24s started %s  database %s',
                $tenant->slug,
                $tenant->created_at?->diffForHumans() ?? 'at an unknown time',
                $tenant->connection?->database ?? '(none recorded)',
            ));
        }

        $this->newLine();

        if ($pretend) {
            $this->info(sprintf('%d attempt(s) would be discarded.', \count($abandoned)));

            return self::SUCCESS;
        }

        /*
         | The confirmation names the databases, not the count.
         |
         | "Discard 3 tenants?" is a question anybody answers yes to. The
         | failure mode worth preventing is an operator running this with
         | `--older-than=0` while a colleague is provisioning a real
         | customer, and the only thing that stops it is seeing that
         | customer's database name in the prompt.
         */
        if (! $this->option('force') && ! $this->confirm(sprintf(
            'Drop %s and the role behind each? This cannot be undone.',
            implode(', ', array_map(
                static fn (Tenant $t): string => $t->connection?->database ?? $t->slug,
                $abandoned,
            )),
        ), false)) {
            $this->warn('Nothing was discarded.');

            return self::SUCCESS;
        }

        $migrator = DB::connection('migrator');
        $failures = [];

        foreach ($abandoned as $tenant) {
            try {
                $this->discard($migrator, $tenant);

                $this->line(sprintf('  <fg=green>ok   </> %s', $tenant->slug));
            } catch (Throwable $e) {
                $failures[$tenant->slug] = $e->getMessage();

                $this->line(sprintf('  <fg=red>FAIL</> %s - %s', $tenant->slug, $e->getMessage()));
            }
        }

        $this->newLine();

        if ($failures !== []) {
            $this->error(sprintf(
                '%d attempt(s) could not be discarded. Each one leaves a live database and a login role '.
                'on the cluster, and every tenant-enforcement check will keep failing on it.',
                \count($failures),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('%d attempt(s) discarded.', \count($abandoned)));

        return self::SUCCESS;
    }

    /**
     * The attempts old enough to call abandoned.
     *
     * @return list<Tenant>
     */
    private function abandoned(int $minutes): array
    {
        $query = Tenant::query()
            ->with('connection')
            ->whereNull('provisioned_at')
            ->where('status', TenantStatus::Provisioning)
            ->where('created_at', '<=', now()->subMinutes($minutes));

        if (\is_string($this->option('slug'))) {
            $query->where('slug', $this->option('slug'));
        }

        return $query->orderBy('created_at')->get()->all();
    }

    private function discard(Connection $migrator, Tenant $tenant): void
    {
        /*
         | Refused again here, not only in the query.
         |
         | The query already excludes a provisioned tenant, and this is the
         | line that matters if somebody later adds a `--slug` shortcut that
         | skips it: a customer's ledger must not be reachable from a
         | cleanup command by typing its slug.
         */
        if ($tenant->provisioned_at !== null) {
            throw new \RuntimeException(sprintf(
                '%s finished provisioning, so it holds a customer ledger. Use the archive path.',
                $tenant->slug,
            ));
        }

        $grammar = $migrator->getQueryGrammar();
        $connection = $tenant->connection;

        if ($connection !== null) {
            /*
             | Sessions are terminated first.
             |
             | `DROP DATABASE` refuses while anything is connected, and the
             | thing most likely to be connected is the dead run's own
             | pooled session, which PostgreSQL will hold until its TCP
             | timeout - long after the process that owned it is gone.
             */
            $migrator->statement(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ?',
                [$connection->database],
            );

            $migrator->statement(sprintf(
                'DROP DATABASE IF EXISTS %s',
                $grammar->wrap($connection->database),
            ));

            $migrator->statement(sprintf(
                'DROP ROLE IF EXISTS %s',
                $grammar->wrap($connection->username),
            ));

            $connection->forceDelete();
        }

        ActivityLog::query()->where('tenant_id', $tenant->getKey())->forceDelete();
        $tenant->subscriptions()->forceDelete();
        $tenant->forceDelete();
    }
}
