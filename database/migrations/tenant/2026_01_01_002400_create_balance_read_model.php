<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized balances, maintained incrementally - PF-053.
 *
 * @financial
 *
 * ## A read model is a cache, and a cache is a second source of truth
 *
 * Which is the whole risk. The journal is the ledger; this table is a
 * convenience that makes a trial balance over three years of lines return in
 * milliseconds instead of seconds. The moment anybody treats it as the
 * answer rather than as a cache of the answer, a drift of 0.01 becomes a
 * figure in a filed return.
 *
 * So three things are true of it by construction:
 *
 *   - it is **rebuildable from the journal by definition**, and the rebuild
 *     is the specification of what the increments should have produced
 *   - a drift check **reports** and never repairs. Silent repair hides the
 *     bug that caused the drift, and the bug is the thing that matters -
 *     a figure that was wrong for a week was on a report for a week
 *   - the increments are maintained by a **database trigger**, not by the
 *     posting service, because an import, a correction script and a restore
 *     all write lines without going near the service
 *
 * ## The grain: entity, period, account, dimension set
 *
 * Per period and not per date, because every report is bounded by periods
 * and a per-date grain is a row per account per day that nobody queries.
 *
 * Per dimension SET and not per axis, because a P&L grouped by cost centre
 * and project needs the combination - summing two per-axis tables gives the
 * right totals and the wrong breakdown, which is worse than no breakdown
 * because it looks like one.
 *
 * ## `dimension_key` exists so the unique index is usable
 *
 * Eleven columns with NULLs in eight of them cannot be a unique key: NULL is
 * not equal to NULL, so every dimensionless line would insert a new row
 * rather than adding to the existing one, and the table would grow one row
 * per line - a read model with more rows than the thing it summarises.
 *
 * @online-safe Creates one table and triggers; no rewrite of journal_lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_balances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->ulid('period_id');
            $table->ulid('account_id');

            /*
             | The dimension set, as the eight columns AND as a key.
             |
             | The columns are what a report groups by; the key is what makes
             | the unique index work, because NULL is not equal to NULL and
             | eight nullable columns in a unique index means every
             | dimensionless line inserts its own row.
             */
            for ($axis = 1; $axis <= 8; $axis++) {
                $table->ulid('dimension_'.$axis)->nullable();
            }

            $table->string('dimension_key', 220);

            /*
             | Three figures, not one.
             |
             | The net is what a balance sheet reads. The gross debit and
             | credit are what a trial balance's movement columns read, and
             | they cannot be derived from the net - an account with 42,000
             | of debits and 1,000 of credits has the same net as one with
             | 41,000 of debits and none.
             */
            $table->decimal('net', 21, 4)->default('0');
            $table->decimal('debits', 21, 4)->default('0');
            $table->decimal('credits', 21, 4)->default('0');
            $table->integer('line_count')->default(0);

            $table->timestamps();

            $table->unique(
                ['entity_id', 'period_id', 'account_id', 'dimension_key'],
                'account_balances_grain',
            );

            // The trial-balance query: bounded by entity and period, grouped
            // by account.
            $table->index(['entity_id', 'period_id', 'account_id'], 'account_balances_trial');
        });

        /*
         | The drift log.
         |
         | A check that only exits non-zero tells an operator that something
         | was wrong at some point. This says which account, in which
         | period, by how much, and when it was noticed - which is what
         | somebody needs to work out what was on a report and for how long.
         */
        Schema::create('balance_drift_findings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->ulid('period_id')->nullable();
            $table->ulid('account_id')->nullable();
            $table->string('dimension_key', 220)->nullable();

            $table->decimal('stored_net', 21, 4)->nullable();
            $table->decimal('rebuilt_net', 21, 4)->nullable();
            $table->decimal('difference', 21, 4)->nullable();

            // 'missing', 'extra' or 'wrong' - three different bugs.
            $table->string('kind', 16);

            $table->timestamp('found_at');
            $table->timestamps();

            $table->index(['entity_id', 'found_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE balance_drift_findings
                ADD CONSTRAINT balance_drift_findings_kind_known
                    CHECK (kind IN ('missing', 'extra', 'wrong'));

            /*
             | A balance row of all zeroes is a row that says nothing.
             |
             | Not forbidden - a period's activity can net to zero while
             | having had movement, and the gross figures say so. What is
             | forbidden is a row with no movement at all, which is what a
             | bug in the increments produces.
             */
            ALTER TABLE account_balances
                ADD CONSTRAINT account_balances_has_movement
                    CHECK (line_count > 0);
        SQL);

        $this->installIncrementalMaintenance();

        foreach (['account_balances', 'balance_drift_findings'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE account_balances IS
                'A CACHE of the journal, not a source of truth. Rebuildable by definition; a drift check '
                'reports and never repairs, because silent repair hides the bug that caused the drift - and '
                'a figure that was wrong for a week was on a report for a week.';
        SQL);
    }

    /**
     * The increments, in the database - PF-053.
     *
     * ## Why not in the posting service
     *
     * Because an import, a correction script and a restore all write lines
     * without going near it. A read model maintained by one write path is a
     * read model that drifts the first time somebody uses another, and the
     * drift is found by whoever compares a report to the journal.
     *
     * ## Only posted lines count
     *
     * A draft entry has not happened. So the line trigger applies a delta
     * only when the parent entry is posted or reversed, and a second trigger
     * on `journal_entries` applies the whole entry when a draft becomes
     * posted - which is the manual-entry path.
     *
     * `reversed` still counts, for the same reason the trial balance counts
     * it: the original posting happened and the reversal is a separate entry
     * whose own lines cancel it. Counting only `posted` drops the original
     * and keeps the reversal, so every reversed transaction appears as its
     * own negative.
     */
    private function installIncrementalMaintenance(): void
    {
        DB::unprepared(<<<'SQL'
            /*
             | The dimension set as one comparable value.
             |
             | IMMUTABLE so it can be used in an index and so the planner
             | can fold it. A dash for a null axis rather than an empty
             | string, because an empty string is also a value a ULID column
             | could in principle hold and a dash is not.
             */
            CREATE OR REPLACE FUNCTION peakflow_dimension_key(
                d1 char(26), d2 char(26), d3 char(26), d4 char(26),
                d5 char(26), d6 char(26), d7 char(26), d8 char(26)
            )
            RETURNS varchar(220)
            LANGUAGE sql
            IMMUTABLE
            AS $$
                SELECT concat_ws('|',
                    coalesce(trim(d1), '-'), coalesce(trim(d2), '-'),
                    coalesce(trim(d3), '-'), coalesce(trim(d4), '-'),
                    coalesce(trim(d5), '-'), coalesce(trim(d6), '-'),
                    coalesce(trim(d7), '-'), coalesce(trim(d8), '-')
                )::varchar(220);
            $$;

            /*
             | Apply one line's movement to the read model.
             |
             | A single function so the line trigger and the entry trigger
             | cannot disagree about what a delta is - which they would, and
             | the disagreement would be a drift nobody could explain.
             */
            CREATE OR REPLACE FUNCTION peakflow_apply_balance_delta(
                p_entity_id char(26),
                p_period_id char(26),
                p_account_id char(26),
                p_d1 char(26), p_d2 char(26), p_d3 char(26), p_d4 char(26),
                p_d5 char(26), p_d6 char(26), p_d7 char(26), p_d8 char(26),
                p_amount numeric(21,4),
                p_lines integer
            )
            RETURNS void
            LANGUAGE plpgsql
            AS $$
            DECLARE
                dim_key varchar(220);
            BEGIN
                dim_key := peakflow_dimension_key(p_d1, p_d2, p_d3, p_d4, p_d5, p_d6, p_d7, p_d8);

                INSERT INTO account_balances (
                    id, entity_id, period_id, account_id,
                    dimension_1, dimension_2, dimension_3, dimension_4,
                    dimension_5, dimension_6, dimension_7, dimension_8,
                    dimension_key, net, debits, credits, line_count,
                    created_at, updated_at
                )
                VALUES (
                    peakflow_ulid(), p_entity_id, p_period_id, p_account_id,
                    p_d1, p_d2, p_d3, p_d4, p_d5, p_d6, p_d7, p_d8,
                    dim_key, p_amount,
                    CASE WHEN p_amount > 0 THEN p_amount ELSE 0 END,
                    CASE WHEN p_amount < 0 THEN -p_amount ELSE 0 END,
                    p_lines, now(), now()
                )
                ON CONFLICT (entity_id, period_id, account_id, dimension_key) DO UPDATE
                    SET net = account_balances.net + p_amount,
                        debits = account_balances.debits
                                 + CASE WHEN p_amount > 0 THEN p_amount ELSE 0 END,
                        credits = account_balances.credits
                                  + CASE WHEN p_amount < 0 THEN -p_amount ELSE 0 END,
                        line_count = account_balances.line_count + p_lines,
                        updated_at = now();
            END;
            $$;

            /*
             | SECURITY DEFINER, and the reason is the same as the
             | functional-currency mirror's.
             |
             | `account_balances` is entity-scoped. A posting carries the
             | session's current entity, so the policy would normally
             | permit the write - except during a rebuild, which walks every
             | entity, and during provisioning, when no entity is selected.
             | The function takes the entity from the LINE, so there is no
             | input through which it can be pointed elsewhere.
             */
            CREATE OR REPLACE FUNCTION maintain_account_balances()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                entry_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    /*
                     | A deleted line.
                     |
                     | `journal_lines` is marked no-delete and the
                     | application role has no DELETE privilege, so this is
                     | the migrator's path - a correction during an
                     | incident. The read model follows rather than drifting
                     | silently, which is the one thing it must not do.
                     */
                    SELECT status INTO entry_status FROM journal_entries WHERE id = OLD.journal_entry_id;

                    IF entry_status IN ('posted', 'reversed') THEN
                        PERFORM peakflow_apply_balance_delta(
                            OLD.entity_id, OLD.period_id, OLD.account_id,
                            OLD.dimension_1, OLD.dimension_2, OLD.dimension_3, OLD.dimension_4,
                            OLD.dimension_5, OLD.dimension_6, OLD.dimension_7, OLD.dimension_8,
                            -OLD.functional_amount, -1
                        );
                    END IF;

                    RETURN OLD;
                END IF;

                SELECT status INTO entry_status FROM journal_entries WHERE id = NEW.journal_entry_id;

                -- A draft entry has not happened.
                IF entry_status NOT IN ('posted', 'reversed') THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    PERFORM peakflow_apply_balance_delta(
                        OLD.entity_id, OLD.period_id, OLD.account_id,
                        OLD.dimension_1, OLD.dimension_2, OLD.dimension_3, OLD.dimension_4,
                        OLD.dimension_5, OLD.dimension_6, OLD.dimension_7, OLD.dimension_8,
                        -OLD.functional_amount, -1
                    );
                END IF;

                PERFORM peakflow_apply_balance_delta(
                    NEW.entity_id, NEW.period_id, NEW.account_id,
                    NEW.dimension_1, NEW.dimension_2, NEW.dimension_3, NEW.dimension_4,
                    NEW.dimension_5, NEW.dimension_6, NEW.dimension_7, NEW.dimension_8,
                    NEW.functional_amount, 1
                );

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER journal_lines_maintain_balances
                AFTER INSERT OR UPDATE OR DELETE ON journal_lines
                FOR EACH ROW
                EXECUTE FUNCTION maintain_account_balances();

            /*
             | A draft becoming posted.
             |
             | The manual-entry path writes a draft and its lines, then
             | posts - so the lines were written while the entry had not
             | happened and the line trigger skipped them. This applies the
             | whole entry at the moment it becomes real.
             */
            CREATE OR REPLACE FUNCTION maintain_balances_on_posting()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                line record;
            BEGIN
                IF OLD.status <> 'draft' OR NEW.status NOT IN ('posted', 'reversed') THEN
                    RETURN NEW;
                END IF;

                FOR line IN
                    SELECT * FROM journal_lines WHERE journal_entry_id = NEW.id
                LOOP
                    PERFORM peakflow_apply_balance_delta(
                        line.entity_id, line.period_id, line.account_id,
                        line.dimension_1, line.dimension_2, line.dimension_3, line.dimension_4,
                        line.dimension_5, line.dimension_6, line.dimension_7, line.dimension_8,
                        line.functional_amount, 1
                    );
                END LOOP;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER journal_entries_maintain_balances
                AFTER UPDATE ON journal_entries
                FOR EACH ROW
                EXECUTE FUNCTION maintain_balances_on_posting();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_entries_maintain_balances ON journal_entries;
            DROP FUNCTION IF EXISTS maintain_balances_on_posting();

            DROP TRIGGER IF EXISTS journal_lines_maintain_balances ON journal_lines;
            DROP FUNCTION IF EXISTS maintain_account_balances();

            DROP FUNCTION IF EXISTS peakflow_apply_balance_delta(
                char(26), char(26), char(26),
                char(26), char(26), char(26), char(26),
                char(26), char(26), char(26), char(26),
                numeric, integer
            );

            DROP FUNCTION IF EXISTS peakflow_dimension_key(
                char(26), char(26), char(26), char(26),
                char(26), char(26), char(26), char(26)
            );
        SQL);

        Schema::dropIfExists('balance_drift_findings');
        Schema::dropIfExists('account_balances');
    }
};
