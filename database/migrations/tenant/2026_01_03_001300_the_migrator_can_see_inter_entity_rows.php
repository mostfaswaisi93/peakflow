<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `inter_entity_transactions` hid every row from the migrator - PF-244.
 *
 * ## The table that got missed, and why
 *
 * `peakflow_apply_entity_rls()` does two things: it writes the
 * `entity_id = peakflow_current_entity()` policy, and it grants the
 * migrator an unconditional bypass. This table could not use the helper,
 * because an inter-entity transaction has `from_entity_id` and
 * `to_entity_id` and no `entity_id` at all, so 003000 hand-wrote a
 * `peakflow_either_side` policy instead.
 *
 * It hand-wrote the first half and not the second. The table carries
 * `FORCE ROW LEVEL SECURITY`, so the owner is bound by its own policy, and
 * with no bypass the migrator - which owns every table here - could see
 * **no row in this table at all** unless a current entity happened to be
 * set.
 *
 * ## What that costs, which is more than a dump
 *
 * It was found by `TenantDump`'s preflight: `pg_dump` has to run with row
 * security ON, so a table without a bypass does not fail, it comes out
 * EMPTY. 291 tables carried the bypass and this one did not.
 *
 * The dump is the cheap half. The expensive half is that a data migration
 * reading this table sees zero rows and reports success - a backfill that
 * corrects inter-entity balances would say "0 rows updated" and be ticked
 * off. Nothing errors, and the table is the one that records money moving
 * between two companies in the same group.
 *
 * ## The bypass is not a weakening
 *
 * The migrator already owns this table and can `ALTER TABLE ... NO FORCE
 * ROW LEVEL SECURITY` whenever it likes, so a permissive policy grants it
 * nothing it could not already take. The application connects as the
 * tenant role, which this policy does not name, so `peakflow_either_side`
 * remains the only thing scoping a request.
 *
 * @online-safe Creates one policy on one table. A catalog write under a
 * brief ACCESS EXCLUSIVE lock on `inter_entity_transactions`; no rows are
 * read or rewritten and nothing is scanned.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'peakflow_migrator') THEN
                    DROP POLICY IF EXISTS peakflow_migrator_bypass ON inter_entity_transactions;

                    CREATE POLICY peakflow_migrator_bypass ON inter_entity_transactions
                        TO peakflow_migrator
                        USING (true)
                        WITH CHECK (true);
                END IF;
            END
            $$;

            COMMENT ON TABLE inter_entity_transactions IS
                'Money moving between two entities in one tenant. Scoped by peakflow_either_side on from_entity_id or to_entity_id, because the row belongs to both. The migrator holds an unconditional bypass: FORCE ROW LEVEL SECURITY binds the owner too, and without it a dump or a backfill reads this table as empty and says so cheerfully.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP POLICY IF EXISTS peakflow_migrator_bypass ON inter_entity_transactions');
    }
};
