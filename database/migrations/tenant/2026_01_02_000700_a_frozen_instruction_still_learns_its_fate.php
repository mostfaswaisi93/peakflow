<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A released instruction is frozen, and still learns what happened to it.
 *
 * ## The distinction PF-174 did not need and PF-178 does
 *
 * PF-174 froze a released run's lines against any update, which was right
 * with the information it had: everything on an instruction at that point
 * defined the payment, and a released payment cannot be edited.
 *
 * PF-178 and PF-179 add columns that are **facts about** the instruction
 * rather than parts of it - what the bank said, when it cleared, which
 * entry posted it. Those arrive after release by definition: a status
 * report comes days later and a statement line later still.
 *
 * Freezing them would mean a released instruction could never record its
 * own outcome, so the in-transit position could never close and the
 * three-way tie could never be made. Not freezing anything would mean the
 * beneficiary and the amount could be rewritten after the file had gone.
 *
 * So the trigger names the columns that may move and freezes the rest.
 * The same decision `payment_files` records: the download columns and the
 * supersession pointer may change because they are facts about the file,
 * and its bytes may not.
 *
 * ## Why a list and not a flag
 *
 * A `frozen` boolean on the row would be a flag somebody clears. The
 * columns that may change are a property of the schema rather than of the
 * row, and naming them here means adding a column is a decision about
 * which side it falls on - which is a question worth being asked.
 *
 * @financial-not-applicable: replaces a trigger function; no amount, balance or entity scope is involved
  *
 * @online-safe Replaces a function body, which is a catalog-only change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_released_run_is_frozen()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                run_state text;
                run_number text;
            BEGIN
                SELECT r.state, r.number INTO run_state, run_number
                  FROM payment_runs r
                 WHERE r.id = coalesce(NEW.run_id, OLD.run_id);

                IF run_state IS DISTINCT FROM 'released' THEN
                    RETURN coalesce(NEW, OLD);
                END IF;

                /*
                 | An update that changes only what the bank told us.
                 |
                 | These columns are facts ABOUT the instruction and
                 | arrive after release by definition: a status report
                 | comes days later and a statement line later still.
                 | Freezing them would mean a released instruction could
                 | never record its own outcome, so the in-transit
                 | position could never close.
                 */
                IF TG_OP = 'UPDATE' AND TG_TABLE_NAME = 'payment_run_instructions' THEN
                    IF NEW.id = OLD.id
                       AND NEW.run_id = OLD.run_id
                       AND NEW.entity_id = OLD.entity_id
                       AND NEW.party_id = OLD.party_id
                       AND NEW.party_bank_account_id IS NOT DISTINCT FROM OLD.party_bank_account_id
                       AND NEW.amount = OLD.amount
                       AND NEW.currency = OLD.currency
                       AND NEW.sequence = OLD.sequence
                       AND NEW."of" = OLD."of"
                       AND NEW.split_reason IS NOT DISTINCT FROM OLD.split_reason
                       AND NEW.account_fingerprint IS NOT DISTINCT FROM OLD.account_fingerprint
                    THEN
                        RETURN NEW;
                    END IF;
                END IF;

                RAISE EXCEPTION
                    'Payment run % has been released, so its lines cannot be changed. The run would say one figure and its lines another, and the file has already gone. What the bank reported, when it cleared and which entry posted it are facts about the instruction and may still be recorded.',
                    run_number
                    USING ERRCODE = 'check_violation';
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        /*
         | Narrowing back would stop a released instruction recording its
         | own outcome, so a rollback would break the in-transit position
         | rather than restore a safer state. Left undone.
         */
    }
};
