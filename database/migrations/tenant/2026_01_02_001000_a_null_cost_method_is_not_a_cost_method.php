<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A check constraint passes on NULL, and this one did - PF-247.
 *
 * ## The trap
 *
 * `CHECK (NOT stocked OR cost_method IN ('weighted_average', 'fifo'))`
 * reads like "a stocked item declares a cost method". For a stocked item
 * with a null method it evaluates:
 *
 *   NOT true OR (NULL IN (...))  ->  false OR NULL  ->  NULL
 *
 * and PostgreSQL accepts a check that evaluates to NULL. So the one row
 * the constraint existed to refuse was the one row it let through.
 *
 * ## Why this is worth its own migration rather than a quiet amendment
 *
 * Because the shape is general. Every `OR x IN (...)` in a check
 * constraint has it, and the failure is silent in exactly the direction
 * that matters: the constraint looks present, reads correctly, and
 * refuses nothing.
 *
 * Found by a check that created a stocked item with no cost method and
 * expected a refusal. Without that check the first defensible cost would
 * have been whichever one the code happened to pick, for ever.
 *
 * @financial-not-applicable: corrects a check constraint's null handling; no amount, balance or entity scope is involved
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE items
                DROP CONSTRAINT IF EXISTS items_stocked_declares_a_cost_method;

            ALTER TABLE items
                ADD CONSTRAINT items_stocked_declares_a_cost_method
                    CHECK (
                        NOT stocked
                        OR (cost_method IS NOT NULL
                            AND cost_method IN ('weighted_average', 'fifo'))
                    );

            /*
             | And the same shape, two constraints down.
             |
             | `items_only_goods_carry_stock` compares `type`, which is NOT
             | NULL, so it is safe - but the negative-basis one tests a
             | nullable text column through `coalesce`, which is why it was
             | written that way and is correct. Recorded here so the next
             | reader does not have to work out which of the three were at
             | risk.
             */
            COMMENT ON CONSTRAINT items_stocked_declares_a_cost_method ON items IS
                'A stocked item declares its cost method. Written with an explicit NOT NULL test because `OR cost_method IN (...)` evaluates to NULL for a null method, and a check that evaluates to NULL passes - PF-247.';
        SQL);
    }

    public function down(): void
    {
        /*
         | Restoring the broken form would reinstate a constraint that
         | refuses nothing, which is worse than not having it. Left undone.
         */
    }
};
