<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The operator's own log claimed append-only and nothing enforced it - PF-239.
 *
 * ## Measured
 *
 *     role=peakflow_app  UPDATE=true  DELETE=true
 *     comment: (none)
 *
 * `ActivityLog::update()` throws, so the model refuses. But
 * `DB::table('activity_logs')->update(...)` does not go through the model,
 * and nor does any raw statement - and the application role held UPDATE. So
 * the record of what support did inside a customer's workspace could be
 * edited by the application itself, and the only thing standing in the way
 * was a method on one class.
 *
 * The tenant's own `audit_log` has two layers: a trigger that refuses UPDATE
 * and DELETE, and a revoked privilege. The operator's log - the one that says
 * who impersonated whom - had one, in PHP.
 *
 * `platformDrift()` already reports a platform table marked
 * `peakflow:append-only` that the application role can still write to. It
 * never fired here because the table carried **no comment at all**, so the
 * mechanism that would have caught this was waiting on a marker nobody had
 * applied.
 *
 * ## Why a third marker rather than `append-only`
 *
 * `append-only` means no UPDATE **and** no DELETE, and DELETE has a
 * legitimate caller: `TenantsDiscardAbandoned` force-deletes the activity
 * logs of a tenant whose provisioning never finished, because that tenant
 * has no customer, no books and no audit significance - it is a half-created
 * row being cleaned up. Marking this table `append-only` would either break
 * that command or require an exception to the marker, and an exception to a
 * marker is how a marker stops meaning anything.
 *
 * So the vocabulary gains `peakflow:no-update`: SELECT yes, INSERT yes,
 * UPDATE **no**, DELETE yes. It says exactly what is true of this table and
 * nothing more.
 *
 * ## What each of the three layers is for
 *
 * The REVOKE stops the application. The TRIGGER stops anything connecting as
 * a role that was granted UPDATE anyway - which is what drift looks like, and
 * the reason `PrivilegeMatrix` exists. `ActivityLog::update()`'s exception
 * stays because it is the only one of the three that produces a sentence a
 * developer reads at the point of the mistake rather than a database error.
 *
 * Unlike the tenant `audit_log`, the role here CAN be named: `peakflow_app`
 * is the platform connection's role and it is one role for the whole
 * cluster, not one per tenant. That is why the revoke is in this migration
 * and the audit log's had to be left to the provisioner.
 *
 * @online-safe A REVOKE on one table, a comment, and a trigger that fires
 *              only on UPDATE. No rows are read or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | The migration's OWN connection, not `platform`.
         |
         | `php artisan migrate --database=migrator` is how this schema is
         | migrated (see `bin/setup` and `bin/dev-up`), because the platform
         | connection runs as the application role and that role has no DDL -
         | which is the separation `config/database.php` spells out. Naming
         | `platform` here produced `permission denied for schema public`,
         | and would have done so on every deployment.
         */
        $connection = $this->getConnection() === null
            ? DB::connection('migrator')
            : DB::connection($this->getConnection());

        $connection->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_activity_amendment()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'activity_logs cannot be amended: it is the record of what support did inside a customer workspace, and a record support can edit answers nothing. Write a new entry describing the correction.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            DROP TRIGGER IF EXISTS activity_logs_no_update ON activity_logs;

            CREATE TRIGGER activity_logs_no_update
                BEFORE UPDATE ON activity_logs
                FOR EACH ROW
                EXECUTE FUNCTION refuse_activity_amendment();

            COMMENT ON TABLE activity_logs IS 'peakflow:no-update - what an operator did inside a customer workspace. UPDATE is revoked and refused by a trigger; DELETE stays, because tenants:discard-abandoned removes the logs of a tenant whose provisioning never finished and which therefore has no customer and no audit significance.';
        SQL);

        /*
         | The revoke, by name, which the tenant audit log could not do.
         |
         | `peakflow_app` is the platform connection's role and there is one
         | of it for the whole cluster. A tenant's role is generated per
         | tenant, which is why `audit_log`'s revoke had to be left to the
         | provisioner and this one does not.
         |
         | Read from the configuration rather than written as a literal, so a
         | deployment that renamed the role does not silently revoke nothing -
         | the earlier version of the audit log's migration revoked from a
         | hardcoded name that was not the role in use, and it was a
         | well-formed statement that protected nothing.
         */
        $role = (string) config('database.connections.platform.username');

        if ($role !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $role) === 1) {
            $connection->unprepared(sprintf(
                'REVOKE UPDATE ON activity_logs FROM %s;',
                $role,
            ));
        }
    }

    public function down(): void
    {
        $connection = $this->getConnection() === null
            ? DB::connection('migrator')
            : DB::connection($this->getConnection());

        $role = (string) config('database.connections.platform.username');

        $connection->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS activity_logs_no_update ON activity_logs;
            DROP FUNCTION IF EXISTS refuse_activity_amendment();
            COMMENT ON TABLE activity_logs IS NULL;
        SQL);

        if ($role !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $role) === 1) {
            $connection->unprepared(sprintf('GRANT UPDATE ON activity_logs TO %s;', $role));
        }
    }
};
