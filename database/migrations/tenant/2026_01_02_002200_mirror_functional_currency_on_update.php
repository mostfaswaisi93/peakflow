<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let an entity actually change its functional currency - PF-087.
 *
 * ## The hole
 *
 * Two correct guards, and no path between them.
 *
 * `entities_functional_currency_immutable` permits the change while the
 * entity has no posted entries, which is right: before anything is measured
 * there is nothing to re-present. `entity_currencies_one_functional` permits
 * exactly one functional currency per entity, which is also right.
 *
 * But `mirror_functional_currency` fired `AFTER INSERT` only. So the entity
 * row updated, the old `entity_currencies` row kept `is_functional`, and the
 * application's next attempt to record the new one hit the unique
 * constraint. The legal change was refused by a constraint protecting an
 * invariant the change had not broken - the mirror had simply never been
 * finished.
 *
 * Found by moving the development workspace from the riyal to the shekel,
 * which is the first time in this repository that anything tried.
 *
 * ## The old currency stays ENABLED
 *
 * Demoted, not disabled. An entity that kept its books in riyals for two
 * years still has riyal documents, still receives riyal payments, and the
 * history is not wrong - it was measured correctly at the time. Disabling it
 * here would stop new documents in a currency the business still trades in,
 * to tidy a flag.
 *
 * @unsafe-migration-reviewed: it replaces a trigger function in place.
 * `CREATE OR REPLACE` takes a brief lock on the function, not on `entities`,
 * and the new body is a superset of the old one's behaviour - an INSERT
 * still mirrors exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mirror_functional_currency()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                /*
                 | Demote first, promote second.
                 |
                 | `entity_currencies_one_functional` is a plain unique
                 | index, so it is checked per statement. Promoting before
                 | demoting would mean two functional rows existing between
                 | the two statements, and the insert would be refused -
                 | which is the exact failure this migration exists to fix,
                 | reproduced inside the fix.
                 */
                UPDATE entity_currencies
                   SET is_functional = false, updated_at = now()
                 WHERE entity_id = NEW.id
                   AND currency <> NEW.functional_currency
                   AND is_functional;

                INSERT INTO entity_currencies (
                    id, entity_id, currency, is_functional, enabled,
                    default_rate_set, created_at, updated_at
                )
                VALUES (
                    peakflow_ulid(), NEW.id, NEW.functional_currency, true, true,
                    'market', now(), now()
                )
                ON CONFLICT (entity_id, currency) DO UPDATE
                    SET is_functional = true, enabled = true, updated_at = now();

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS entities_mirror_functional_currency_on_update ON entities;

            /*
             | A second trigger rather than widening the first.
             |
             | `WHEN` is what keeps this off every unrelated update to an
             | entity - a rename, a profile change, an activation. Without it
             | the mirror would run on each of them, taking a write lock on
             | entity_currencies for no reason.
             */
            CREATE TRIGGER entities_mirror_functional_currency_on_update
                AFTER UPDATE OF functional_currency ON entities
                FOR EACH ROW
                WHEN (OLD.functional_currency IS DISTINCT FROM NEW.functional_currency)
                EXECUTE FUNCTION mirror_functional_currency();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS entities_mirror_functional_currency_on_update ON entities;
        SQL);
    }
};
