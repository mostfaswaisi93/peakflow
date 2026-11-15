<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\DatabaseConnection;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Take CONNECT away from PUBLIC on every database - PF-010.
 *
 * ## Why this exists as a command and not only in the provisioner
 *
 * The provisioner now seals each tenant database at creation, and the Docker
 * init script seals the platform database. Neither helps a cluster that was
 * provisioned before those lines existed - which is every cluster that already
 * has customers on it.
 *
 * ## What is wrong with an unsealed database
 *
 * A PostgreSQL database with an empty ACL is not one nobody may connect to. An
 * empty ACL means the built-in default applies, and the built-in default grants
 * CONNECT and TEMPORARY to PUBLIC. So every login role on the cluster can open
 * a session in every database, and a `GRANT CONNECT TO <role>` does not change
 * that, because there was never anything to revoke.
 *
 * Measured on a real local cluster: the platform role opened a session in a
 * tenant's database and read 129 table names out of `pg_class` with their row
 * estimates - `accounts (~336 rows)`, `account_roles (~120 rows)`. The schema
 * privileges held, so no business row came back, and that is not much comfort:
 * how many accounts a customer has, which modules they use and whether they
 * have posted anything are facts about that customer. `pg_class` is
 * world-readable and cannot be revoked, so the boundary has to be the
 * connection.
 *
 * ## Idempotent, and safe to run while serving
 *
 * `REVOKE` and `GRANT` on a database take a brief lock on the catalog row and
 * do not touch existing sessions - a connection already open stays open. So
 * this can run on a live cluster, and the sessions it was meant to prevent are
 * gone at their next reconnect rather than mid-transaction.
 */
final class DatabasesSeal extends Command
{
    protected $signature = 'databases:seal
        {--pretend : Show the statements, change nothing}
        {--tenant= : Seal one tenant database by name}';

    protected $description = 'Revoke PUBLIC\'s default CONNECT on the platform and tenant databases';

    public function handle(): int
    {
        $migrator = DB::connection('migrator');
        $pretend = (bool) $this->option('pretend');
        $failures = [];
        $sealed = 0;

        foreach ($this->targets() as $database => $owners) {
            if (\is_string($this->option('tenant')) && $this->option('tenant') !== $database) {
                continue;
            }

            $before = $this->whoCanConnect($migrator, $database);

            try {
                $sealed += $this->seal($migrator, $database, $owners, $pretend) ? 1 : 0;
            } catch (Throwable $e) {
                $failures[$database] = $e->getMessage();

                $this->line(sprintf('  <fg=red>FAIL</> %s - %s', $database, $e->getMessage()));

                continue;
            }

            $after = $pretend ? $before : $this->whoCanConnect($migrator, $database);

            $this->line(sprintf(
                '  <fg=green>%s</> %s',
                $pretend ? 'would' : 'ok   ',
                $database,
            ));

            /*
             | The roles that LOSE access are named, not just counted.
             |
             | This command is run on a cluster somebody else set up, and the
             | one outcome nobody wants is it quietly revoking the access an
             | undocumented reporting job depends on. Printing the difference
             | means that job's role is named on screen before anybody
             | notices it stopped.
             */
            $lost = array_diff($before, $after);

            if ($lost !== []) {
                $this->line(sprintf(
                    '         no longer able to connect: %s',
                    implode(', ', $lost),
                ));
            }
        }

        $this->newLine();

        if ($failures !== []) {
            $this->error(sprintf(
                '%d database(s) could not be sealed. Until they are, any login role on the cluster can '.
                'open a session in them and read their table names and row counts out of pg_class.',
                \count($failures),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d database(s) %s.',
            $sealed,
            $pretend ? 'would be sealed' : 'sealed',
        ));

        return self::SUCCESS;
    }

    /**
     * Every database this command is responsible for, with the roles that keep access.
     *
     * @return array<string, list<string>>
     */
    private function targets(): array
    {
        $migratorRole = (string) config('database.connections.migrator.username');
        $appRole = (string) config('database.connections.platform.username');
        $platformDatabase = (string) config('database.connections.platform.database');

        /*
         | `peakflow_readonly` keeps the PLATFORM database and nothing else.
         |
         | It exists for reporting replicas and analytics, and the registry is
         | what analytics reads: plans, subscriptions, tenant counts. Reporting
         | on a customer's own ledger goes through that tenant's connection,
         | so a blanket CONNECT on every tenant database would be access
         | nothing uses - and the one credential most likely to be pasted into
         | a BI tool.
         */
        $targets = [$platformDatabase => [$appRole, 'peakflow_readonly', $migratorRole]];

        foreach (DatabaseConnection::all() as $connection) {
            /*
             | Each tenant database keeps ITS OWN role and the migrator.
             |
             | Not the platform role. That is the point: a leaked platform
             | credential should reach the tenant registry and nothing else,
             | and a leaked tenant credential should reach one customer.
             */
            $targets[$connection->database] = [$connection->username, $migratorRole];
        }

        /*
         | And anything else on the cluster that is ours.
         |
         | The test databases were the ones this missed first time: they are
         | created by the Docker init script, are not in the registry, and
         | hold whatever a developer last seeded - which in practice is a copy
         | of production's shape. Taking the list from the catalog rather than
         | from the registry means a database somebody created by hand is
         | included too, and that is the one most likely to be unsealed.
         */
        foreach (DB::connection('migrator')->select(
            "SELECT datname FROM pg_database WHERE datname LIKE 'peakflow%' ORDER BY datname",
        ) as $row) {
            $name = (string) $row->datname;

            if (! isset($targets[$name])) {
                $targets[$name] = [$appRole, $migratorRole];
            }
        }

        return $targets;
    }

    /** @param  list<string>  $owners */
    private function seal(Connection $migrator, string $database, array $owners, bool $pretend): bool
    {
        $grammar = $migrator->getQueryGrammar();

        $statements = [
            sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $grammar->wrap($database)),
            sprintf(
                'GRANT CONNECT, TEMPORARY ON DATABASE %s TO %s',
                $grammar->wrap($database),
                implode(', ', array_map($grammar->wrap(...), $owners)),
            ),
        ];

        /*
         | Explicit grants to anybody else are revoked too.
         |
         | `REVOKE ALL ON DATABASE ... FROM PUBLIC` removes only the built-in
         | default. A grant somebody made by hand survives it, and the first
         | run of the matrix check found two: `peakflow_readonly` had CONNECT
         | on both test databases, from a line nobody could point to.
         |
         | So "sealed" means the connect list is EXACTLY the roles named here.
         | That is a decision with a cost - it can revoke the access an
         | undocumented job depends on - which is why the command prints every
         | role that loses access, by name, as it goes.
         */
        foreach ($this->otherLoginRoles($migrator, $owners) as $role) {
            if (! $this->hasConnect($migrator, $role, $database)) {
                continue;
            }

            $statements[] = sprintf(
                'REVOKE ALL ON DATABASE %s FROM %s',
                $grammar->wrap($database),
                $grammar->wrap($role),
            );
        }

        foreach ($statements as $statement) {
            if ($pretend) {
                $this->line('         '.$statement);

                continue;
            }

            $migrator->statement($statement);
        }

        return true;
    }

    /**
     * Every login role that is not supposed to keep access to this database.
     *
     * Superusers are left alone: revoking from one does nothing, because a
     * superuser bypasses privilege checks entirely, and issuing the statement
     * would only make the output claim a tightening that did not happen.
     *
     * @param  list<string>  $owners
     * @return list<string>
     */
    private function otherLoginRoles(Connection $migrator, array $owners): array
    {
        $roles = [];

        foreach ($migrator->select(
            'SELECT rolname FROM pg_roles WHERE rolcanlogin AND NOT rolsuper ORDER BY rolname',
        ) as $row) {
            $name = (string) $row->rolname;

            if (! \in_array($name, $owners, true)) {
                $roles[] = $name;
            }
        }

        return $roles;
    }

    private function hasConnect(Connection $migrator, string $role, string $database): bool
    {
        $answer = $migrator->selectOne(
            'SELECT has_database_privilege(?, ?, ?) AS yes',
            [$role, $database, 'CONNECT'],
        );

        return $answer !== null && (bool) $answer->yes;
    }

    /**
     * Which login roles can currently connect, asked of the server.
     *
     * `has_database_privilege` rather than parsing `datacl`, because an empty
     * `datacl` is exactly the case that matters and parsing it would read as
     * "nobody" - which is the misreading this whole command exists to correct.
     *
     * @return list<string>
     */
    private function whoCanConnect(Connection $migrator, string $database): array
    {
        $roles = $migrator->select(
            'SELECT rolname FROM pg_roles WHERE rolcanlogin AND NOT rolsuper ORDER BY rolname',
        );

        $able = [];

        foreach ($roles as $role) {
            $name = (string) $role->rolname;

            $answer = $migrator->selectOne(
                'SELECT has_database_privilege(?, ?, ?) AS yes',
                [$name, $database, 'CONNECT'],
            );

            if ($answer !== null && (bool) $answer->yes) {
                $able[] = $name;
            }
        }

        return $able;
    }
}
