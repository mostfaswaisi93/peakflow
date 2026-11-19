<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A cancelled order keeps the fact that it was confirmed - PF-090.
 *
 * ## The constraint this replaces, and what it refused
 *
 * `(status IN ('confirmed', 'completed')) = (confirmed_at IS NOT NULL)` reads
 * as "a confirmation is dated", and it also says the reverse: that a dated
 * confirmation means the order is currently confirmed. So cancelling a
 * confirmed order was refused by the database - measured, as a check
 * violation - because the cancellation left `confirmed_at` where it was.
 *
 * Which it should. The order WAS confirmed; that is a fact about what
 * happened, and clearing it to satisfy a constraint would erase the date
 * somebody committed to supplying the work. What the constraint actually
 * wants to say is two things:
 *
 *   - a confirmed or completed order has a confirmation date;
 *   - a draft does not, because nobody has confirmed it yet.
 *
 * A cancelled order may have one or not, depending on whether it was
 * cancelled before or after it was confirmed - and both happen.
  *
 * @online-unsafe Adds CHECK constraints to `sales_orders`, which already exists.
 *                `ADD CONSTRAINT` without `NOT VALID` scans every row under
 *                ACCESS EXCLUSIVE, so on a populated table it blocks reads and
 *                writes for the length of a full scan. It is declared rather
 *                than rewritten because what the marker describes is the
 *                STATEMENT, not the install where the table happened to be
 *                empty - a deployment replaying it onto years of rows would
 *                get the scan. The pattern to copy is
 *                `2026_01_03_000800_a_bill_can_be_cancelled`: NOT VALID, then
 *                VALIDATE CONSTRAINT, which takes only SHARE UPDATE EXCLUSIVE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sales_orders
                DROP CONSTRAINT IF EXISTS sales_orders_confirmed_is_attributable,

                ADD CONSTRAINT sales_orders_confirmation_is_dated
                    CHECK (status NOT IN ('confirmed', 'completed') OR confirmed_at IS NOT NULL),

                ADD CONSTRAINT sales_orders_a_draft_is_unconfirmed
                    CHECK (status <> 'draft' OR confirmed_at IS NULL);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sales_orders
                DROP CONSTRAINT IF EXISTS sales_orders_confirmation_is_dated,
                DROP CONSTRAINT IF EXISTS sales_orders_a_draft_is_unconfirmed,

                ADD CONSTRAINT sales_orders_confirmed_is_attributable
                    CHECK ((status IN ('confirmed', 'completed')) = (confirmed_at IS NOT NULL));
        SQL);
    }
};
