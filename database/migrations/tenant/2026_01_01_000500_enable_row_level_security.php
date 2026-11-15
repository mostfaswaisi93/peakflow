<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-level security - PF-026.
 *
 * The SECOND of two independent mechanisms that scope rows to an entity. The
 * first is the application's own global scope, and this exists because one of
 * them will eventually have a hole: a raw query, a console command, a
 * `tinker` session during an incident, or a backfill script all bypass an
 * Eloquent scope.
 *
 * Cross-company leakage inside one tenant is harder to detect than
 * cross-tenant leakage, because the data looks plausible - a figure from the
 * wrong company is still a figure.
 *
 * ## What this migration provides
 *
 * The MECHANISM: a stable function that reads the per-request session variable,
 * and a helper that applies the standard policy to a table. Phase 02 applies
 * it to every financial table as those tables are created, which is also what
 * the `@financial` architecture rule checks for.
 *
 * ## What is deliberately NOT scoped this way
 *
 *   entities            the switcher has to list every entity a user may
 *                       reach, and the session variable holds exactly one.
 *                       Scoped by MEMBERSHIP in the application instead.
 *   user_entity_roles   same reason - it is the membership table itself.
 *   users               a tenant's users are tenant-wide, not per entity.
 *
 * Scoping those by current entity would not be stricter, it would be wrong.
 *
 * @online-safe Creates a function and policies; takes no long lock.
 */
return new class extends Migration
{
    /*
     | No getConnection() override, deliberately.
     |
     | Returning 'tenant' here would OVERRIDE the --database option and force
     | the application's own connection, which has no DDL privileges at all.
     | `tenants:migrate` runs with a migrator-credentialed clone of the tenant
     | connection and sets it as the default, so the plain facades below reach
     | the right database with the right privileges.
     |
     | That is the division PF-010 exists to create: the application can read
     | and write rows and can never change the schema.
     */
    public function up(): void
    {
        $variable = (string) config('tenancy.entity_scope.session_variable', 'peakflow.entity_id');

        DB::unprepared(sprintf(<<<'SQL'
            /*
             | The current entity, from the session variable the resolver sets.
             |
             | STABLE rather than VOLATILE so the planner can use it in an index
             | condition instead of re-evaluating it per row.
             |
             | `true` as the second argument to current_setting means "return
             | NULL if unset" rather than raising - which matters because the
             | migrator connects without it set, and a migration must not fail
             | merely because no entity has been selected.
             */
            CREATE OR REPLACE FUNCTION peakflow_current_entity()
            RETURNS char(26)
            LANGUAGE sql
            STABLE
            AS $fn$
                SELECT nullif(current_setting('%s', true), '')::char(26);
            $fn$;

            /*
             | Apply the standard entity policy to a table.
             |
             | A single definition, so every financial table is scoped the same
             | way and a new one cannot be scoped slightly differently by
             | accident.
             |
             | FORCE ROW LEVEL SECURITY is the part that is easy to miss: without
             | it, the table's OWNER bypasses its own policies, and the owner is
             | the migrator - which is exactly the role a backfill script runs
             | as. The migrator is then granted an explicit BYPASSRLS policy so
             | that legitimate cross-entity backfills remain possible, but
             | deliberately rather than by default.
             */
            CREATE OR REPLACE FUNCTION peakflow_apply_entity_rls(target regclass)
            RETURNS void
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
                partition regclass;
            BEGIN
                /*
                 | PARTITIONS FIRST, and this is not a detail.
                 |
                 | A policy on a partitioned parent governs queries addressed
                 | to the PARENT. A query naming a partition directly is
                 | governed by that partition's own policies - and the
                 | application role holds SELECT on every partition through
                 | default privileges.
                 |
                 | So a parent-only policy scopes `FROM journal_lines` and
                 | does nothing for `FROM journal_lines_2026_03`, which
                 | returns every entity's rows. That was measured, not
                 | assumed: two entities, four lines, two returned through the
                 | parent and four through the partition.
                 |
                 | Recursive because a partition may itself be partitioned.
                 */
                FOR partition IN
                    SELECT inhrelid::regclass FROM pg_inherits WHERE inhparent = target
                LOOP
                    PERFORM peakflow_apply_entity_rls(partition);
                END LOOP;

                EXECUTE format('ALTER TABLE %%s ENABLE ROW LEVEL SECURITY', target);
                EXECUTE format('ALTER TABLE %%s FORCE ROW LEVEL SECURITY', target);

                EXECUTE format(
                    'DROP POLICY IF EXISTS peakflow_entity_scope ON %%s', target
                );

                EXECUTE format(
                    'CREATE POLICY peakflow_entity_scope ON %%s
                     USING (entity_id = peakflow_current_entity())
                     WITH CHECK (entity_id = peakflow_current_entity())',
                    target
                );

                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'peakflow_migrator') THEN
                    EXECUTE format(
                        'DROP POLICY IF EXISTS peakflow_migrator_bypass ON %%s', target
                    );
                    EXECUTE format(
                        'CREATE POLICY peakflow_migrator_bypass ON %%s TO peakflow_migrator USING (true) WITH CHECK (true)',
                        target
                    );
                END IF;
            END;
            $fn$;
        SQL, $variable));

        // The entity-owned tables that exist so far. Phase 02 calls the same
        // helper for every financial table it creates.
        foreach (['tax_registrations', 'entity_settings', 'sod_overrides', 'sod_refusals'] as $table) {
            DB::unprepared(
                sprintf("SELECT peakflow_apply_entity_rls('%s');", $table),
            );
        }
    }

    public function down(): void
    {
        foreach (['tax_registrations', 'entity_settings', 'sod_overrides', 'sod_refusals'] as $table) {
            DB::unprepared(sprintf(
                'ALTER TABLE IF EXISTS %1$s DISABLE ROW LEVEL SECURITY; '.
                'DROP POLICY IF EXISTS peakflow_entity_scope ON %1$s; '.
                'DROP POLICY IF EXISTS peakflow_migrator_bypass ON %1$s;',
                $table,
            ));
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS peakflow_apply_entity_rls(regclass);
            DROP FUNCTION IF EXISTS peakflow_current_entity();
        SQL);
    }
};
