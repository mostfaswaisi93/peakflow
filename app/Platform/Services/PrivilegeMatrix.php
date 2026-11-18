<?php

declare(strict_types=1);

namespace App\Platform\Services;

use Illuminate\Database\Connection;

/**
 * What the grants are supposed to be, asked of the server - PF-233.
 *
 * ## Not "the migration ran", but "the grants are right now"
 *
 * Which is the criterion, and the distinction is the whole task. A
 * provisioner that granted correctly in March proves nothing about April: a
 * `GRANT` typed into psql during an incident at 3am and never reverted is the
 * normal way this regresses, and it leaves no trace anywhere except in the
 * catalog. So every answer here comes from `has_table_privilege()` and
 * `pg_class.relacl` rather than from the provisioning code, and
 * `privileges:verify` runs it on every deploy.
 *
 * ## Why the catalog and not a connection attempt
 *
 * Both, in different places. {@see \Tests\Support\DatabaseRoleRules} already
 * attempts the two crossings that matter with a live PDO connection, because
 * a check that only interrogates the catalog is asserting that the catalog
 * agrees with itself. What the catalog is better at is COVERAGE: 348 tables
 * times seven privileges is 2,436 answers, and attempting each as a statement
 * would mean writing 2,436 statements that each have to be undone.
 *
 * So this is the matrix, and the suite that uses it attempts the handful of
 * crossings whose refusal has to be a fact rather than a catalog entry - a
 * DELETE of a posted journal entry among them.
 *
 * ## The declared intent, written out
 *
 * In a tenant database, the application role may:
 *
 * | | SELECT | INSERT | UPDATE | DELETE | TRUNCATE | REFERENCES | TRIGGER |
 * |-|-|-|-|-|-|-|-|
 * | an ordinary table | yes | yes | yes | yes | **no** | **no** | **no** |
 * | `peakflow:no-delete` | yes | yes | yes | **no** | **no** | **no** | **no** |
 * | `peakflow:append-only` | yes | yes | **no** | **no** | **no** | **no** | **no** |
 *
 * TRUNCATE is refused because it is a DELETE that no row trigger sees and no
 * row-level security policy filters: `TRUNCATE journal_entries` empties the
 * ledger of every entity in the tenant at once, past the policy that makes
 * one entity invisible to another. REFERENCES and TRIGGER are refused because
 * both are schema changes wearing a data privilege's name - a foreign key
 * takes a lock and a trigger runs arbitrary code as the table's owner.
 *
 * None of the three is ever granted by {@see TenantProvisioner}. They are
 * checked anyway, because what this file is for is the grant nobody meant to
 * make.
 */
final class PrivilegeMatrix
{
    /** Privileges no application role may hold on any table, ever. */
    public const NEVER = ['TRUNCATE', 'REFERENCES', 'TRIGGER'];

    /**
     * Drift in one tenant database, read through a connection to it.
     *
     * The connection may be the application's own - `current_user` is then the
     * role under test, which is the cheapest way to ask - and the ACL columns
     * it reads are world-readable, so nothing here needs the migrator.
     *
     * ## Why the role is a parameter and not always `current_user`
     *
     * Because a check that wants to prove this code can FAIL has to grant the
     * application role a privilege it should not have, and the application
     * role cannot grant itself anything - that is the point of the
     * arrangement. So the grant is made on the migrator's connection, inside
     * a transaction, and the question has to be asked about the application
     * role from that same connection or the uncommitted grant is invisible.
     *
     * Getting this wrong is not a check that fails; it is a check that passes.
     * Asked about `current_user` on the migrator's connection, every answer
     * is "yes, it holds everything" - because the migrator owns the tables -
     * so a deliberately planted TRUNCATE is duly reported and the report is
     * about the wrong role. The first version of that check passed for
     * exactly that reason.
     *
     * @param  string|null  $role  whose privileges to read; `current_user` when null
     * @return list<string>
     */
    public function tenantDrift(Connection $connection, ?string $role = null): array
    {
        $drift = [];
        $role ??= (string) $connection->selectOne('SELECT current_user AS role')->role;

        $tables = $connection->select(<<<'SQL'
            SELECT c.relname,
                   c.relkind,
                   coalesce(obj_description(c.oid, 'pg_class'), '') AS comment,
                   coalesce((
                       SELECT obj_description(parent.oid, 'pg_class')
                       FROM pg_inherits i
                       JOIN pg_class parent ON parent.oid = i.inhparent
                       WHERE i.inhrelid = c.oid
                   ), '') AS parent_comment,
                   has_table_privilege(?, c.oid, 'SELECT') AS may_select,
                   has_table_privilege(?, c.oid, 'INSERT') AS may_insert,
                   has_table_privilege(?, c.oid, 'UPDATE') AS may_update,
                   has_table_privilege(?, c.oid, 'DELETE') AS may_delete,
                   has_table_privilege(?, c.oid, 'TRUNCATE') AS may_truncate,
                   has_table_privilege(?, c.oid, 'REFERENCES') AS may_reference,
                   has_table_privilege(?, c.oid, 'TRIGGER') AS may_trigger,
                   pg_get_userbyid(c.relowner) AS owner
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind IN ('r', 'p')
            ORDER BY c.relname
        SQL, array_fill(0, 7, $role));

        if ($tables === []) {
            return ['no tables were found in the tenant schema at all, so this check checked nothing'];
        }

        foreach ($tables as $table) {
            $name = (string) $table->relname;

            /*
             | A partition INHERITS its parent's marker.
             |
             | A privilege granted on a partitioned table does not describe
             | its partitions, so the audit log's 25 monthly partitions each
             | have their own ACL - and each is reachable by name. Reading
             | only the partition's own comment would report 25 tables as
             | unmarked and writable, which is how a real finding gets lost
             | in noise.
             */
            $markers = (string) $table->comment.' '.(string) $table->parent_comment;
            $appendOnly = str_contains($markers, 'peakflow:append-only');
            $noDelete = str_contains($markers, 'peakflow:no-delete');

            foreach (self::NEVER as $privilege) {
                $column = match ($privilege) {
                    'TRUNCATE' => 'may_truncate',
                    'REFERENCES' => 'may_reference',
                    default => 'may_trigger',
                };

                if ((bool) $table->{$column}) {
                    $drift[] = sprintf(
                        '%s holds %s on %s. %s',
                        $role,
                        $privilege,
                        $name,
                        match ($privilege) {
                            'TRUNCATE' => 'TRUNCATE is a DELETE that no trigger sees and no row-level '.
                                'security policy filters, so it empties the table for every entity in '.
                                'the tenant at once.',
                            'REFERENCES' => 'A foreign key is a schema change wearing a data '.
                                'privilege\'s name, and it takes a lock on the table it points at.',
                            default => 'A trigger runs arbitrary code as the table\'s owner, which is '.
                                'the migrator, which has DDL.',
                        },
                    );
                }
            }

            if ($appendOnly && (bool) $table->may_update) {
                $drift[] = sprintf(
                    '%s holds UPDATE on %s, which is marked peakflow:append-only. An audit record that '.
                    'can be edited is not evidence.',
                    $role,
                    $name,
                );
            }

            if (($appendOnly || $noDelete) && (bool) $table->may_delete) {
                $drift[] = sprintf(
                    '%s holds DELETE on %s, which is marked %s. %s',
                    $role,
                    $name,
                    $appendOnly ? 'peakflow:append-only' : 'peakflow:no-delete',
                    $appendOnly
                        ? 'An entry that can be removed is not a log.'
                        : 'A posted row is corrected by reversal and never removed, so a role that can '.
                          'delete one can make a correction look like it never happened.',
                );
            }

            /*
             | A table the APPLICATION role cannot read is also drift.
             |
             | The matrix has two directions and only one of them is a
             | security hole; the other is an outage, and an outage found by
             | a deploy check is better than one found by a user. A migration
             | that creates a table while connected as the wrong role leaves
             | it owned by that role with no grant to the tenant, and the
             | first request that touches it gets "permission denied for
             | table" - which reads as a bug in the query.
             */
            if (! (bool) $table->may_select) {
                $drift[] = sprintf(
                    '%s cannot SELECT from %s, which is owned by %s. The default privileges only apply '.
                    'to tables the migrator creates, so this one was created by somebody else.',
                    $role,
                    $name,
                    (string) $table->owner,
                );
            }

            if (! $appendOnly && ! (bool) $table->may_insert) {
                $drift[] = sprintf('%s cannot INSERT into %s.', $role, $name);
            }

            if ((string) $table->owner === $role) {
                $drift[] = sprintf(
                    '%s OWNS %s. An owner can ALTER and DROP its own table whatever the grants say, and '.
                    'a table owner is exempt from row-level security unless the policy is FORCED - so '.
                    'ownership hands back everything the grants took away.',
                    $role,
                    $name,
                );
            }
        }

        return [...$drift, ...$this->schemaDrift($connection, $role), ...$this->strangersInTheAcl($connection, $role)];
    }

    /**
     * The schema itself: USAGE yes, CREATE no.
     *
     * CREATE on `public` is how a role with no DDL gets DDL. It cannot alter
     * the migrator's tables, but it can create its own - including one that
     * shadows nothing and holds a copy of what row-level security would not
     * let it keep.
     *
     * @return list<string>
     */
    private function schemaDrift(Connection $connection, string $role): array
    {
        $drift = [];

        $schema = $connection->selectOne(
            "SELECT has_schema_privilege(?, 'public', 'USAGE') AS may_use,
                    has_schema_privilege(?, 'public', 'CREATE') AS may_create",
            [$role, $role],
        );

        if (! (bool) $schema->may_use) {
            $drift[] = sprintf('%s has no USAGE on schema public, so it can reach nothing at all.', $role);
        }

        if ((bool) $schema->may_create) {
            $drift[] = sprintf(
                '%s has CREATE on schema public. That is DDL under another name: it cannot alter the '.
                'migrator\'s tables, and it can create its own - which is where a role that row-level '.
                'security keeps out of one entity\'s rows puts a copy of them.',
                $role,
            );
        }

        return $drift;
    }

    /**
     * Any other login role holding a privilege in this database.
     *
     * Read from `relacl`, which lists the grants that were made explicitly.
     * The expected contents are exactly two entries - the owning migrator and
     * this tenant's own role - and a third is a grant somebody made by hand.
     *
     * `peakflow_readonly` is the one that turns up: it exists for reporting
     * replicas over the PLATFORM database, it is the credential most likely
     * to be pasted into a BI tool, and a grant of it on a tenant's tables
     * means that tool can read one customer's ledger.
     *
     * @return list<string>
     */
    private function strangersInTheAcl(Connection $connection, string $role): array
    {
        $expected = [$role, (string) config('database.connections.migrator.username')];

        $rows = $connection->select(<<<'SQL'
            SELECT c.relname, a.grantee, string_agg(DISTINCT a.privilege_type, ', ' ORDER BY a.privilege_type) AS privileges
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN information_schema.table_privileges a
              ON a.table_schema = n.nspname AND a.table_name = c.relname
            JOIN pg_roles r ON r.rolname = a.grantee
            WHERE n.nspname = 'public'
              AND c.relkind IN ('r', 'p')
              AND r.rolcanlogin
              AND NOT r.rolsuper
              AND a.grantee <> ALL (?)
            GROUP BY c.relname, a.grantee
            ORDER BY a.grantee, c.relname
        SQL, ['{'.implode(',', array_map(static fn (string $r): string => '"'.$r.'"', $expected)).'}']);

        $byRole = [];

        foreach ($rows as $row) {
            $byRole[(string) $row->grantee][] = sprintf('%s (%s)', (string) $row->relname, (string) $row->privileges);
        }

        $drift = [];

        foreach ($byRole as $grantee => $tables) {
            $drift[] = sprintf(
                '%s holds privileges on %d table(s) in this tenant\'s database, starting with %s. A '.
                'tenant database is supposed to be reachable by exactly two roles: its own and the '.
                'migrator that owns the schema.',
                $grantee,
                \count($tables),
                $tables[0],
            );
        }

        return $drift;
    }

    /**
     * Row-level security on every table that requires one.
     *
     * Separate from the grants because it answers a different question: the
     * grants decide whether the application may touch a table at all, and the
     * policy decides which rows it sees once it may. Both have to hold, and a
     * table with perfect grants and no policy returns every entity's rows to
     * every entity.
     *
     * `FORCE` is asked about separately. Plain `ENABLE` exempts the table's
     * owner from its own policies, and the owner here is the migrator - so a
     * merely-enabled table is scoped for the application and wide open to any
     * console command that runs as the migrator.
     *
     * @return list<string>
     */
    public function rowSecurityDrift(Connection $connection): array
    {
        $rows = $connection->select(<<<'SQL'
            SELECT c.relname,
                   c.relrowsecurity AS enabled,
                   c.relforcerowsecurity AS forced,
                   (SELECT count(*) FROM pg_policies p
                      WHERE p.schemaname = 'public' AND p.tablename = c.relname) AS policies
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN information_schema.columns col
              ON col.table_schema = 'public'
             AND col.table_name = c.relname
             AND col.column_name = 'entity_id'
            WHERE n.nspname = 'public'
              AND c.relkind IN ('r', 'p')
              AND coalesce(obj_description(c.oid, 'pg_class'), '') NOT LIKE '%peakflow:tenant-scoped%'
              AND coalesce((
                    SELECT obj_description(parent.oid, 'pg_class')
                    FROM pg_inherits i
                    JOIN pg_class parent ON parent.oid = i.inhparent
                    WHERE i.inhrelid = c.oid
                  ), '') NOT LIKE '%peakflow:tenant-scoped%'
            ORDER BY c.relname
        SQL);

        if ($rows === []) {
            return ['no entity-scoped tables were found, which means this check is not checking anything'];
        }

        $drift = [];

        foreach ($rows as $row) {
            $name = (string) $row->relname;

            if (! (bool) $row->enabled) {
                $drift[] = sprintf('%s has an entity_id column and row-level security is not enabled.', $name);

                continue;
            }

            if (! (bool) $row->forced) {
                $drift[] = sprintf(
                    '%s has row-level security enabled but not FORCED, so the table owner bypasses its '.
                    'own policy.',
                    $name,
                );
            }

            if ((int) $row->policies === 0) {
                $drift[] = sprintf('%s has row-level security enabled and no policy, so it returns nothing.', $name);
            }
        }

        return $drift;
    }

    /**
     * The platform database's own matrix.
     *
     * Shorter, because the platform database has no row-level security - its
     * tenant boundary is the database boundary - and because the application
     * role legitimately needs DML on nearly all of it. What it must not have
     * is the three privileges nobody needs, and UPDATE or DELETE on the
     * operator's own activity log.
     *
     * @return list<string>
     */
    public function platformDrift(Connection $connection): array
    {
        $drift = [];
        $role = (string) $connection->selectOne('SELECT current_user AS role')->role;

        $tables = $connection->select(<<<'SQL'
            SELECT c.relname,
                   coalesce(obj_description(c.oid, 'pg_class'), '') AS comment,
                   has_table_privilege(current_user, c.oid, 'UPDATE') AS may_update,
                   has_table_privilege(current_user, c.oid, 'DELETE') AS may_delete,
                   has_table_privilege(current_user, c.oid, 'TRUNCATE') AS may_truncate,
                   has_table_privilege(current_user, c.oid, 'REFERENCES') AS may_reference,
                   has_table_privilege(current_user, c.oid, 'TRIGGER') AS may_trigger
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind IN ('r', 'p')
            ORDER BY c.relname
        SQL);

        foreach ($tables as $table) {
            $name = (string) $table->relname;

            foreach (['TRUNCATE' => 'may_truncate', 'REFERENCES' => 'may_reference', 'TRIGGER' => 'may_trigger'] as $privilege => $column) {
                if ((bool) $table->{$column}) {
                    $drift[] = sprintf('%s holds %s on the platform table %s.', $role, $privilege, $name);
                }
            }

            if (! str_contains((string) $table->comment, 'peakflow:append-only')) {
                continue;
            }

            foreach (['UPDATE' => 'may_update', 'DELETE' => 'may_delete'] as $privilege => $column) {
                if ((bool) $table->{$column}) {
                    $drift[] = sprintf(
                        '%s holds %s on %s, which is marked peakflow:append-only. The operator\'s own '.
                        'activity log is the record of what support did inside a customer\'s workspace, '.
                        'and a record support can edit answers nothing.',
                        $role,
                        $privilege,
                        $name,
                    );
                }
            }
        }

        return $drift;
    }
}
