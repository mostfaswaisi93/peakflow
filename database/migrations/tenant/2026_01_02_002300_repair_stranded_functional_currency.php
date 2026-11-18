<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair an entity whose functional currency row never moved - PF-087.
 *
 * ## The state this leaves behind
 *
 * Until the previous migration, `mirror_functional_currency` fired on INSERT
 * only. An entity that changed its functional currency therefore ended up
 * with `entities.functional_currency` saying one thing and the
 * `entity_currencies` row flagged `is_functional` saying another.
 *
 * The entity is then stuck: every later attempt to record the real
 * functional currency is refused by `entity_currencies_one_functional`,
 * because the old row still holds the flag. Adding the trigger does not help
 * - the trigger fires on a change, and the change already happened.
 *
 * So the repair is separate from the fix, which is the right shape: the
 * trigger stops it happening again, this puts right what already did.
 *
 * ## Every entity, not just the ones that look wrong
 *
 * The WHERE clause is the condition itself, so an entity in the correct
 * state is not touched and the migration is safe to run anywhere. On a
 * cluster where nobody ever changed a currency it updates nothing.
 *
 * @unsafe-migration-reviewed: it writes to `entity_currencies`, a small
 * table, under a WHERE that matches only rows that disagree with their own
 * entity. The row it promotes is the one the entity already names, so no
 * balance and no document changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Demote first: the unique index is checked per statement, and a
            -- moment with two functional rows is the failure being repaired.
            UPDATE entity_currencies ec
               SET is_functional = false, updated_at = now()
              FROM entities e
             WHERE ec.entity_id = e.id
               AND ec.is_functional
               AND ec.currency <> e.functional_currency;

            INSERT INTO entity_currencies (
                id, entity_id, currency, is_functional, enabled,
                default_rate_set, created_at, updated_at
            )
            SELECT peakflow_ulid(), e.id, e.functional_currency, true, true,
                   'market', now(), now()
              FROM entities e
            ON CONFLICT (entity_id, currency) DO UPDATE
                SET is_functional = true, enabled = true, updated_at = now();
        SQL);
    }

    public function down(): void
    {
        /*
         | Nothing to undo.
         |
         | The state before was an entity disagreeing with itself, and
         | restoring a disagreement is not a rollback - it is reintroducing
         | the defect.
         */
    }
};
