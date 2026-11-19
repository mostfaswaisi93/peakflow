<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bill entered in error was permanent - PF-245.
 *
 * ## Measured
 *
 * `bills_status_is_known` permits five states:
 *
 *     draft, awaiting_approval, approved, posted, cancelled
 *
 * `App\Tenant\Purchasing\Services\Bills` is the only thing that writes the
 * column, and it writes two of them: `draft` on entry and `posted` on
 * posting. Its public surface is `enter()`, `post()` and `event()`. There is
 * no third exit. A bill keyed twice, or keyed against the wrong supplier,
 * stays a draft for ever.
 *
 * ## What that costs, which is not what it looks like
 *
 * The obvious cost is an untidy list. The real one is that the erroneous
 * bill goes on acting as a bill.
 *
 * `DuplicateBillDetector::examine()` searches candidates whose status is in
 * `('draft', 'awaiting_approval', 'approved', 'posted')` - already written
 * to exclude a cancelled bill, for a state that did not exist - and
 * `Bills::post()` refuses a bill with any unacknowledged duplicate warning.
 * So the sequence is: a bill is keyed twice, somebody notices, and the
 * CORRECT bill cannot be posted until a human acknowledges a warning about a
 * mistake everybody already agrees was a mistake. There is no way to say
 * "that one is not a bill"; the only available answer is to acknowledge the
 * suspicion, which records in the audit trail that the duplicate was
 * reviewed and accepted.
 *
 * ## The columns, and why a reason is not nullable in practice
 *
 * `approved_at`/`approved_by` already exist, with
 * `bills_approved_is_attributable` tying them together. Cancellation gets
 * the same shape plus a reason, because the question asked about a
 * cancelled bill six months later is never "when" - it is "why is there a
 * gap in this supplier's document numbers", and the answer has to be on the
 * row.
 *
 * The column is nullable at the schema level and required by the service,
 * deliberately: making it `NOT NULL` would mean a default for the rows that
 * exist, and a default reason is a sentence nobody wrote appearing in an
 * audit trail as though somebody had.
 *
 * ## And a cancelled bill holds no journal entry
 *
 * `bills_posted_carries_its_entry` says a posted bill has one. The mirror
 * was missing: nothing said a cancelled bill has none. It matters because
 * cancellation and posting are the two terminal states and the ledger is
 * what makes them different - a cancelled bill that kept its
 * `journal_entry_id` would be a payable in the ledger with no payable
 * document behind it, which the subledger-to-control check would report as
 * a mismatch nobody could explain from the bills table.
 *
 * @online-safe Three nullable columns and two CHECK constraints. PostgreSQL
 *              adds a nullable column without a rewrite; the constraints are
 *              added NOT VALID and validated separately, so the ACCESS
 *              EXCLUSIVE lock is held only for the catalog update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->ulid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
        });

        /*
         | NOT VALID, then VALIDATE.
         |
         | `ADD CONSTRAINT ... CHECK` scans the whole table under ACCESS
         | EXCLUSIVE, which on a tenant with years of bills is a lock held
         | for the length of a full scan - during business hours, because
         | that is when deployments happen. NOT VALID takes the lock for the
         | catalog write only and applies to every row written afterwards;
         | VALIDATE CONSTRAINT then scans under SHARE UPDATE EXCLUSIVE, which
         | does not block reads or writes.
         |
         | Both existing rows satisfy them trivially - no row is cancelled -
         | so the validation is a formality here. It is written this way
         | because the next one will not be.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_cancelled_is_attributable
                    CHECK (
                        (cancelled_at IS NULL AND cancelled_by IS NULL)
                        OR (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL)
                    ) NOT VALID,

                ADD CONSTRAINT bills_cancelled_holds_no_entry
                    CHECK (status <> 'cancelled' OR journal_entry_id IS NULL) NOT VALID;

            ALTER TABLE bills VALIDATE CONSTRAINT bills_cancelled_is_attributable;
            ALTER TABLE bills VALIDATE CONSTRAINT bills_cancelled_holds_no_entry;

            COMMENT ON COLUMN bills.cancellation_reason IS
                'Why this bill is not a bill. Required by Bills::cancel() and nullable here, because a NOT NULL would need a default for the rows that already exist - and a default reason is a sentence nobody wrote, appearing in an audit trail as though somebody had.';
        SQL);
    }

    /**
     * The constraints go. The columns stay, and that is the whole of it.
     *
     * `cancellation_reason` holds the only record of why a bill is not a
     * bill. Dropping it would be irreversible, and a rollback is run in a
     * hurry by somebody who has decided the deployment was wrong - which is
     * the worst moment to discover that the undo also deleted the
     * explanations.
     *
     * Three unused nullable columns on a table cost nothing. The constraints
     * are dropped because they are the part that can REFUSE a write, and a
     * rolled-back deployment running older code should not meet a rule it
     * does not know about.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE bills
                DROP CONSTRAINT IF EXISTS bills_cancelled_is_attributable,
                DROP CONSTRAINT IF EXISTS bills_cancelled_holds_no_entry;
        SQL);
    }
};
