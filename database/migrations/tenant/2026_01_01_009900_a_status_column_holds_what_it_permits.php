<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A status column holds every status its constraint permits.
 *
 * ## Found by trying to put a bill in the state the workflow names
 *
 * `bills.status` was `varchar(16)` and `bills_status_is_known` permitted
 * `'awaiting_approval'` - seventeen characters. So the one state between
 * entering a bill and approving it could never be stored: the constraint
 * said it was legal and the column refused it, with the error arriving as
 * "value too long for type character varying(16)" rather than as anything
 * about approval.
 *
 * `purchase_orders.status` had the same pair of facts, and the same
 * unreachable state.
 *
 * ## Why nothing had noticed
 *
 * Nothing wrote it. `Bills::enter()` creates a draft and the approval
 * engine moves it to `approved`, so the intermediate state exists in the
 * constraint, in `DuplicateBillDetector`'s filter and in the status lists
 * on screen, and in no row anywhere.
 *
 * That is the shape of defect that waits: the day somebody implements
 * submit-for-approval on a bill - or a customer's import sets the status
 * it read in the documentation - it fails on a string length. And
 * `DuplicateBillDetector` is already filtering on a value no row can
 * hold, which means it has been looking for duplicates among four states
 * and finding them among three.
 *
 * ## 24, not 17
 *
 * `expense_claims` and `purchase_requisitions` were already 24 and hold
 * the same vocabulary, so this makes the four agree rather than fixing
 * two to the width of today's longest word. A column sized to the exact
 * longest permitted value is a column the next state name breaks.
 *
 * @financial-not-applicable: widens two existing status columns; no amount, balance or entity scope is involved
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | ALTER TYPE to a WIDER varchar of the same type is a catalog-only
         | change in PostgreSQL - no table rewrite, no verification scan.
         | The lock is held for the time the catalog row is updated.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE bills
                ALTER COLUMN status TYPE varchar(24);

            ALTER TABLE purchase_orders
                ALTER COLUMN status TYPE varchar(24);

            COMMENT ON COLUMN bills.status IS
                'Where the bill is in its workflow. 24 characters because the constraint permits awaiting_approval, which a 16-character column refused - so the state existed in the constraint and in no row.';
        SQL);
    }

    public function down(): void
    {
        /*
         | Narrowing back is the unsafe direction, and is left undone.
         |
         | A row holding `awaiting_approval` would fail the conversion, so
         | a rollback would either lose data or refuse at a moment nobody
         | chose. A wider column is harmless; reverting it is not.
         */
    }
};
