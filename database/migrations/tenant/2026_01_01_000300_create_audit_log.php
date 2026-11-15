<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The tenant audit log - PF-035.
 *
 * Append-only, hash-chained, and partitioned by month.
 *
 * ## Append-only is enforced three ways, deliberately
 *
 * 1. A trigger refuses UPDATE and DELETE.
 * 2. Those privileges are REVOKED for the application role, so the guard does
 *    not depend on a trigger someone could drop. The table carries a
 *    `peakflow:append-only` comment and TenantProvisioner::harden() does the
 *    revoking, because the per-tenant role name is not knowable here.
 * 3. The model throws on `update()`, so the failure surfaces as a clear
 *    message during development rather than as a permission error in
 *    production.
 *
 * An audit record that can be edited is not evidence. One layer would be
 * enough if nothing ever went wrong; three is what makes it a property of the
 * system rather than a convention.
 *
 * ## Reads are audited, not only writes
 *
 * Who LOOKED at a bank account is an auditable event. That is unusual and it
 * is deliberate: in a financial system the question an investigation asks is
 * frequently "who knew", not "who changed".
 *
 * ## Partitioned by month
 *
 * It grows for the life of the account and is read almost exclusively in
 * `created_at DESC` order, bounded by a period. Partitions are created ahead
 * of time by a scheduled task, never on demand during a request.
 *
 * @online-safe Creates new tables only.
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
        $connection = DB::connection();

        $connection->unprepared(<<<'SQL'
            CREATE TABLE audit_log (
                id                char(26)     NOT NULL,
                entity_id         char(26),
                actor_user_id     char(26),
                actor_name        text,
                impersonated_by   text,

                category          varchar(48)  NOT NULL,
                action            varchar(64)  NOT NULL,

                subject_type      varchar(64),
                subject_id        char(26),
                subject_label     text,

                -- `before` and `after` are the projections that matter, not the
                -- whole row: an audit entry that copies every column becomes
                -- a second database with none of the first one's constraints.
                before_state      jsonb,
                after_state       jsonb,

                reason            text,

                -- Hash chain. Each entry is hashed over its own canonical form
                -- plus the previous entry's hash, which makes insertion,
                -- deletion and alteration all detectable.
                canonical_hash    char(64)     NOT NULL,
                previous_hash     char(64)     NOT NULL,
                chain_index       bigint       NOT NULL,

                correlation_id    varchar(64),
                ip_address        varchar(45),
                user_agent        text,

                created_at        timestamptz  NOT NULL DEFAULT now(),

                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at);

            -- Indexes LEAD with the filtered column and TRAIL with the sort
            -- column. PostgreSQL uses one index per table reference, so a lone
            -- `category` index would let it filter or sort but never both, and
            -- the remainder would be a sort of the whole matching set.
            CREATE INDEX audit_log_created_at_idx      ON audit_log (created_at DESC);
            CREATE INDEX audit_log_entity_created_idx  ON audit_log (entity_id, created_at DESC);
            CREATE INDEX audit_log_actor_created_idx   ON audit_log (actor_user_id, created_at DESC);
            CREATE INDEX audit_log_category_created_idx ON audit_log (category, created_at DESC);
            CREATE INDEX audit_log_action_created_idx  ON audit_log (action, created_at DESC);
            CREATE INDEX audit_log_subject_idx         ON audit_log (subject_type, subject_id, created_at DESC);
            CREATE UNIQUE INDEX audit_log_chain_idx    ON audit_log (chain_index, created_at);
        SQL);

        // Partitions for the current year and the next, created ahead of time.
        // A scheduled task extends this; a request never creates one, because
        // creating a partition takes a lock the hot path must not wait on.
        $start = new DateTimeImmutable('first day of january this year');

        for ($i = 0; $i < 24; $i++) {
            $from = $start->modify("+{$i} months");
            $to = $start->modify('+'.($i + 1).' months');

            $connection->unprepared(sprintf(
                'CREATE TABLE audit_log_%s PARTITION OF audit_log FOR VALUES FROM (%s) TO (%s);',
                $from->format('Y_m'),
                "'".$from->format('Y-m-d')."'",
                "'".$to->format('Y-m-d')."'",
            ));
        }

        // A default partition so a row with an unexpected date is never
        // REJECTED - losing an audit record because nobody extended the
        // partitions would be worse than storing it in the wrong place.
        $connection->unprepared('CREATE TABLE audit_log_default PARTITION OF audit_log DEFAULT;');

        $connection->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_audit_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'audit_log is append-only: an audit record that can be edited is not evidence. Record a new entry describing the correction instead.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER audit_log_append_only
                BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW
                EXECUTE FUNCTION refuse_audit_mutation();
        SQL);

        /*
         | The second layer is a privilege revoke, and this migration cannot
         | perform it: the role that connects to a tenant database is generated
         | per tenant (pf_t_<ulid>), so there is no name to revoke from here.
         |
         | An earlier version of this file revoked from a hardcoded
         | `peakflow_app`. That role exists in the cluster but is NOT the role
         | a tenant connects as, so the revoke was a no-op and the "three
         | layers" above were one trigger wearing a disguise.
         |
         | So the table DECLARES itself instead, and
         | TenantProvisioner::harden() - which does know the role name - reads
         | the marker after every migration and revokes. Any future append-only
         | table (the journal in Phase 02) gets the same treatment by carrying
         | the same comment, with nothing to remember.
         */
        $connection->unprepared(
            "COMMENT ON TABLE audit_log IS 'peakflow:append-only - UPDATE and DELETE are revoked from the application role by TenantProvisioner::harden(); record a correcting entry instead.';"
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_log_append_only ON audit_log;
            DROP FUNCTION IF EXISTS refuse_audit_mutation();
            DROP TABLE IF EXISTS audit_log;
        SQL);
    }
};
