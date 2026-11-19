<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A bank line cannot be applied to more than it is worth - PF-162.
 *
 * ## The one invariant splitting makes breakable
 *
 * `matches` was built for splits in both directions, and the document side
 * was already guarded: a service refuses a match larger than what the
 * document still has outstanding. The transaction side had nothing. A
 * 10,000 receipt could be split 6,000 / 6,000 across two invoices, each
 * allocation individually fitting its own document, and the line would have
 * settled 12,000 of a 10,000 payment.
 *
 * What makes that expensive is where it surfaces. Nothing refuses it,
 * nothing reports it, and both invoices read as paid. It appears as a
 * reconciliation that will not balance - the bank balance and the ledger
 * balance differ by 2,000 and every individual match looks right.
 *
 * ## Why a trigger rather than a service check
 *
 * The service checks it too, with a sentence a person can act on. This is
 * here because a split is several rows and a service is one code path: an
 * import, a correction script, a future bulk tool or a half-finished
 * transaction each reach this table without passing that path, and the
 * invariant is not "the service is careful" - it is "the sum of what a line
 * settles is the line".
 *
 * ## Reversed matches do not count
 *
 * A reversal keeps the original row and sets `reversed_at`. Counting it
 * would make a line un-rematchable after a correction, which is the one
 * moment somebody needs to rematch it.
 *
 * @financial-not-applicable: this migration creates no table. It adds a trigger to
 * `matches`, which carries `entity_id` and has row-level security applied where it
 * is created - and applying RLS again from here would be a second policy on one table.
  *
 * @online-safe Replaces one function body, which is a catalog-only change. The trigger
 *              that calls it is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_transaction_is_not_over_applied()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                line_amount numeric(21,4);
                line_currency char(3);
                applied numeric(21,4);
            BEGIN
                /*
                 | A reversal only ever removes from the total.
                 |
                 | Skipping it is not an optimisation: re-checking on the
                 | update that reverses a row would read the row as still
                 | live under some isolation levels and refuse a correction
                 | for exceeding a total the correction is reducing.
                 */
                IF TG_OP = 'UPDATE' AND NEW.reversed_at IS NOT NULL THEN
                    RETURN NEW;
                END IF;

                SELECT abs(t.amount), t.currency
                INTO line_amount, line_currency
                FROM bank_transactions t
                WHERE t.id = NEW.bank_transaction_id
                  AND t.booking_date = NEW.booking_date;

                IF line_amount IS NULL THEN
                    RAISE EXCEPTION
                        'This match names bank transaction % on %, and there is no such line. A '
                        'match to a transaction that does not exist settles nothing and makes the '
                        'reconciliation unexplainable.',
                        NEW.bank_transaction_id, NEW.booking_date
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF NEW.currency <> line_currency THEN
                    RAISE EXCEPTION
                        'This match is in % and the bank line is in %. A match in another currency '
                        'is a figure that cannot be added to the line it claims to settle, so the '
                        'remainder would be arithmetic between two different units.',
                        NEW.currency, line_currency
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT coalesce(sum(abs(m.amount)), 0)
                INTO applied
                FROM matches m
                WHERE m.bank_transaction_id = NEW.bank_transaction_id
                  AND m.reversed_at IS NULL
                  AND m.id <> NEW.id;

                IF applied + abs(NEW.amount) > line_amount THEN
                    RAISE EXCEPTION
                        'This bank line is %, % of it is already applied, and this match would add '
                        'another % - which settles more than the line is worth. Reverse what is '
                        'applied or match this to the remainder of %.',
                        line_amount, applied, abs(NEW.amount), line_amount - applied
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER matches_do_not_over_apply_a_line
                BEFORE INSERT OR UPDATE ON matches
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_transaction_is_not_over_applied();

            COMMENT ON FUNCTION peakflow_a_transaction_is_not_over_applied() IS
                'The sum of what a bank line settles is the line - PF-162. Over-application surfaces as a reconciliation that will not balance while every individual match looks right.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS matches_do_not_over_apply_a_line ON matches;
            DROP FUNCTION IF EXISTS peakflow_a_transaction_is_not_over_applied();
        SQL);
    }
};
