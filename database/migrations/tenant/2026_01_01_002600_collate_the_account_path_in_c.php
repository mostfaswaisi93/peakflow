<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The account path is byte-ordered, and says so - PF-037.
 *
 * ## A subtree is a RANGE, and a range needs byte ordering to be a prefix
 *
 * The materialized path makes "everything under 1.1" a question about string
 * order: the subtree is exactly the rows where `path >= '1.1.'` and
 * `path < '1.1/'`, because `/` is the byte immediately after `.`.
 *
 * That identity holds only under byte-wise comparison. Under a linguistic
 * collation the ordering is by collation element - punctuation can be ignored
 * at the primary level, and `1.1.2` and `1.12` can sort in either order - so
 * the range stops meaning "has this prefix" and starts meaning "sorts between
 * these two strings in this locale". The query would return the wrong
 * accounts, quietly, and differently on a server configured with a different
 * locale.
 *
 * So the column declares `C` rather than inheriting whatever the database
 * was created with. An account code is an identifier, not prose; there is no
 * language in which `1.1.2` belongs somewhere else.
 *
 * ## Measured, because the alternative looked fine
 *
 * At 5,055 accounts in one entity, on this schema:
 *
 *   path LIKE '1.1.%'                     cost 249.52   0.41 ms
 *                                         Index Cond: entity_id only
 *                                         Rows Removed by Filter: 4955
 *
 *   path >= '1.1.' AND path < '1.1/'      cost   7.21   0.06 ms
 *                                         Index Only Scan, both bounds in
 *                                         the index condition
 *
 * The LIKE is not a slower way to get the same plan - it is a different plan.
 * It reads every account in the entity and discards 98% of them, so its cost
 * grows with the size of the CHART rather than the size of the subtree. A
 * tenant with one large entity pays that on every roll-up.
 *
 * The prefix optimization does not kick in for the LIKE even with a literal
 * pattern, because `path` is `varchar` and the pattern operator takes `text`,
 * so the comparison is against a cast. Rather than add a second index with
 * `text_pattern_ops` to serve a query form that is strictly worse anyway, the
 * range is the only form used: see {@see \App\Tenant\Ledger\Services\AccountTree}.
 *
 * ## And the path is made UNIQUE at the same time
 *
 * A path identifies a node. Two accounts sharing one makes every subtree
 * query return both branches and every roll-up double-count one of them -
 * and it is reachable: the code is unique per entity and does not change when
 * an account moves, so moving `1.1` under `1.2` wants the path `1.2.1`, which
 * the sub-group `1.2.1` already has. That was found by a benchmark moving a
 * subtree, not by reading the schema.
 *
 * The index was already there and non-unique, so this costs one index rebuild
 * that the collation change was doing anyway.
 *
 * @online-safe No. ALTER COLUMN TYPE rewrites the table and rebuilds its
 *              indexes under an ACCESS EXCLUSIVE lock. `accounts` is a few
 *              thousand rows per tenant, so the window is milliseconds - but
 *              it IS a rewrite, and it is listed here rather than hidden.
 *
 * @unsafe-migration-reviewed: the ALTER COLUMN TYPE is deliberate and is the
 * whole migration. It is a collation change on a small table, it takes an
 * ACCESS EXCLUSIVE lock for the length of a rewrite of a few thousand rows,
 * and the alternative - leaving the column at the database's collation - is a
 * correctness bug that depends on how the server was installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE accounts
                ALTER COLUMN path TYPE varchar(512) COLLATE "C";

            DROP INDEX IF EXISTS accounts_entity_id_path_index;

            CREATE UNIQUE INDEX accounts_entity_id_path_index
                ON accounts (entity_id, path);

            COMMENT ON COLUMN accounts.path IS
                'The materialized path: the chain of ancestor segments joined by ".". Collated C, which '
                'is load-bearing rather than tidiness - a subtree is read as the half-open range '
                '[path || ''.'', path || ''/''] and that range only means "has this prefix" under '
                'byte-wise comparison. Under a linguistic collation it would return the wrong accounts, '
                'quietly, and differently per server locale.';
        SQL);

        /*
         | The index is recreated AFTER the collation change, deliberately.
         |
         | ALTER COLUMN TYPE rebuilds every index that mentions the column, so
         | recreating it first would build it twice. Built here, it is both
         | unique and C-collated in one pass.
         */
    }

    public function down(): void
    {
        /*
         | Back to the database's default collation.
         |
         | Which reintroduces the correctness problem, and that is what a
         | rollback of this migration means. The code that reads the range is
         | what rolls back with it.
         */
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS accounts_entity_id_path_index;

            ALTER TABLE accounts
                ALTER COLUMN path TYPE varchar(512);

            CREATE INDEX accounts_entity_id_path_index ON accounts (entity_id, path);
        SQL);
    }
};
