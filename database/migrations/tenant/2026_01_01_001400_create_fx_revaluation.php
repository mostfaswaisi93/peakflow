<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Period-end revaluation of monetary items - PF-070.
 *
 * @financial
 *
 * ## The distinction IAS 21 turns on
 *
 * Monetary items are restated at the closing rate. Non-monetary items are
 * not. Getting that backwards does not produce an error; it produces a
 * balance sheet that is wrong in a direction nobody notices, because both
 * answers look like numbers.
 *
 * A monetary item is "units of currency held, and assets and liabilities to
 * be received or paid in a fixed or determinable number of currency units".
 * The test is whether it settles in CURRENCY:
 *
 *   monetary      cash, bank, receivables, payables, VAT recoverable or
 *                 payable, inter-entity balances, loans
 *   non-monetary  property, inventory, prepayments, share capital,
 *                 retained earnings, and - the one that catches people -
 *                 DEFERRED REVENUE
 *
 * Deferred revenue is settled by delivering goods or services, not by paying
 * money. It is non-monetary, it is not revalued, and treating it as monetary
 * puts an exchange difference through profit or loss for an obligation whose
 * cost is not denominated in currency at all.
 *
 * Suspense is non-monetary here too, for a different reason: it holds items
 * nobody has identified yet, and revaluing an unidentified item asserts a
 * classification that has not been made.
 *
 * ## Why a flag rather than a derivation from the account type
 *
 * "Asset" does not imply monetary - property and receivables are both
 * assets. "Current" does not either: a prepayment is current and
 * non-monetary. There is no reliable derivation, which is why every
 * accounting system that tried one has an account somewhere being revalued
 * that should not be.
 *
 * So it is a column, seeded by the chart template with the classification
 * stated for each account, and changeable by a controller who knows their own
 * chart better than a template does.
 *
 * ## Reversed and recomputed, never accumulated
 *
 * An unrealized difference is an opinion about today's rate. Next period the
 * rate is different and so is the opinion, and adding this period's opinion
 * to last period's produces a figure that is neither.
 *
 * So each run reverses the previous one in full and posts a fresh
 * computation. That is also what makes it re-runnable inside an open period:
 * running it three times on three different days leaves exactly one
 * adjustment standing, the last one.
 *
 * ## What the migration pre-flight flags here, and why it is accepted
 *
 * Three UPDATEs with no batching, and a `dropColumn`.
 *
 * @unsafe-migration-reviewed: the UPDATEs backfill the IAS 21 classification
 * onto `accounts`, bounded by account code. A chart of accounts is hundreds
 * of rows - the whole table is smaller than one day of journal lines - so
 * there is nothing to batch and nothing to hold. Batching it would add a
 * mechanism whose failure modes are more interesting than the statement's.
 * The `dropColumn` is in `down()`, which runs only on a deliberate rollback,
 * and a rollback that leaves the column in place leaves `fx:revalue` reading
 * a classification the code it rolled back to does not write.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | The monetary flag.
         |
         | Nullable, meaning "not classified", which is deliberately NOT the
         | same as non-monetary. An unclassified account is reported by
         | `fx:revalue` rather than quietly skipped, because an account that
         | holds a foreign balance and has never been classified is the exact
         | case where a silent default is wrong in whichever direction it
         | happens to point.
         */
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('monetary')->nullable();
        });

        /*
         | One run per entity per period, with the entry it posted.
         |
         | `is_current` rather than deleting the superseded rows: the history
         | of what was posted and reversed is the explanation for why a
         | period's figures moved between two readings of the same report.
         */
        Schema::create('fx_revaluation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->ulid('period_id');

            $table->string('rate_set', 32);
            $table->date('revalued_at');

            // The entry this run posted, and the reversal of the run it
            // replaced. Either can be null: a run that found no difference
            // posts nothing, and the first run has nothing to reverse.
            $table->ulid('journal_entry_id')->nullable();
            $table->ulid('reversal_entry_id')->nullable();
            $table->ulid('supersedes_run_id')->nullable();

            $table->boolean('is_current')->default(true);

            $table->integer('accounts_examined')->default(0);
            $table->integer('accounts_restated')->default(0);

            /*
             | What the run decided, per account and currency: the exposure,
             | the rate, the booked functional amount and the difference.
             |
             | Kept because "why is there a 4,120 revaluation in March" is
             | asked at every close, and reconstructing it from the journal
             | means re-deriving the balances as they were at the time.
             */
            $table->jsonb('workings')->nullable();

            $table->text('unclassified_accounts')->nullable();

            $table->ulid('run_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'period_id']);
        });

        DB::unprepared(<<<'SQL'
            -- One CURRENT run per entity per period. Earlier ones stay as
            -- history with is_current false.
            CREATE UNIQUE INDEX fx_revaluation_runs_one_current
                ON fx_revaluation_runs (entity_id, period_id)
                WHERE is_current;

            ALTER TABLE fx_revaluation_runs
                ADD CONSTRAINT fx_revaluation_runs_supersession_is_not_circular
                    CHECK (supersedes_run_id IS NULL OR supersedes_run_id <> id);

        SQL);

        $this->installSupersessionInvariant();

        $this->classifyTemplateAccounts();

        DB::unprepared("SELECT peakflow_apply_entity_rls('fx_revaluation_runs');");

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE fx_revaluation_runs IS
                'peakflow:no-delete - the record of what was posted and reversed at each close is the '
                'explanation for why a period''s figures moved between two readings of the same report.';

            COMMENT ON COLUMN accounts.monetary IS
                'IAS 21: settles in a fixed or determinable number of currency units, so it is restated at '
                'the closing rate. NULL means not classified, which is reported rather than assumed - '
                'deferred revenue is the account most often wrongly marked monetary, because it settles in '
                'goods and services rather than in money.';
        SQL);
    }

    /**
     * A superseded run must have had its entry reversed - PF-070.
     *
     * ## The invariant
     *
     * "Reversed and recomputed rather than accumulated" has to be true in
     * the data, not merely in whichever version of the code is running. A
     * run that superseded another without reversing its entry leaves BOTH
     * adjustments standing - which is the accumulation this is meant to
     * prevent, and the resulting figure is neither period's answer.
     *
     * ## Why a trigger and not a CHECK
     *
     * The obvious form, `supersedes_run_id IS NULL OR reversal_entry_id IS
     * NOT NULL`, is wrong, and it took writing the service to see why: a run
     * that found no difference posts no entry at all. The next run supersedes
     * it and has nothing to reverse, so the CHECK refuses a correct run -
     * and refuses it at the end of a revaluation that has already posted,
     * which rolls back work that was right.
     *
     * The condition is really "the superseded run's entry was reversed, if it
     * had one", and that needs a lookup. Which is a trigger.
     */
    private function installSupersessionInvariant(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_superseded_run_was_reversed()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                superseded_entry char(26);
            BEGIN
                IF NEW.supersedes_run_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT journal_entry_id INTO superseded_entry
                FROM fx_revaluation_runs
                WHERE id = NEW.supersedes_run_id;

                -- A run that posted nothing has nothing to reverse.
                IF superseded_entry IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.reversal_entry_id IS NULL THEN
                    RAISE EXCEPTION
                        'Revaluation run % supersedes run %, which posted entry %, without reversing it. Both adjustments would stand, and an unrealized difference that accumulates across periods is neither period''s answer.',
                        NEW.id, NEW.supersedes_run_id, superseded_entry
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fx_revaluation_runs_supersession_reverses
                BEFORE INSERT OR UPDATE ON fx_revaluation_runs
                FOR EACH ROW
                EXECUTE FUNCTION assert_superseded_run_was_reversed();
        SQL);
    }

    /**
     * Classify the accounts the chart template creates.
     *
     * By CODE, because the template's codes are fixed and its control roles
     * are not present on every account that needs classifying - petty cash
     * and PPE have no role at all.
     *
     * An entity whose chart was customised keeps whatever it set; this only
     * fills in what is still null.
     */
    private function classifyTemplateAccounts(): void
    {
        $monetary = [
            '1.1.1.1',  // Petty cash - units of currency held.
            '1.1.1.2',  // Bank.
            '1.1.1.3',  // Payments in transit.
            '1.1.2.1',  // Trade receivables.
            '1.1.2.2',  // ECL provision - a valuation allowance against a
                        // monetary asset, so it follows the underlying item.
            '1.1.3',    // Input VAT - recoverable from the authority in cash.
            '1.1.5',    // Inter-entity - settled between the companies in cash.
            '2.1.1',    // Trade payables.
            '2.1.2',    // Output VAT - payable to the authority in cash.
        ];

        $nonMonetary = [
            '1.1.4',    // Suspense - unidentified. Revaluing it would assert
                        // a classification nobody has made.
            '1.2.1',    // Property, plant and equipment.
            '2.1.3',    // Deferred revenue - settled in goods and services.
            '3.1',      // Share capital.
            '3.2',      // Retained earnings.
            '4.1', '4.2',
            '5.1', '5.2', '5.3', '5.4', '5.5', '5.6',
        ];

        DB::table('accounts')->whereIn('code', $monetary)->whereNull('monetary')
            ->update(['monetary' => true, 'updated_at' => now()]);

        DB::table('accounts')->whereIn('code', $nonMonetary)->whereNull('monetary')
            ->update(['monetary' => false, 'updated_at' => now()]);

        /*
         | Header accounts are never postable, so they hold no balance and
         | the classification does not apply. Marked false rather than left
         | null so that `fx:revalue` does not report thirty header accounts
         | as unclassified every time it runs - a report that is mostly noise
         | is a report nobody reads.
         */
        DB::table('accounts')->where('postable', false)->whereNull('monetary')
            ->update(['monetary' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fx_revaluation_runs_supersession_reverses ON fx_revaluation_runs;
            DROP FUNCTION IF EXISTS assert_superseded_run_was_reversed();
        SQL);

        Schema::dropIfExists('fx_revaluation_runs');

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('monetary');
        });
    }
};
