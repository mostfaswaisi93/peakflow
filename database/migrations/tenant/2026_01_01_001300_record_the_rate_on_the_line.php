<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Which rate a line was booked at, not merely what the number was - PF-069.
 *
 * ## The value of a rate is not its identity
 *
 * `journal_lines.fx_rate` already stored `4.012500000000`. That is enough to
 * check the arithmetic and not enough to answer any of the questions actually
 * asked about a posting:
 *
 *   - was the rate current, or three weeks old?
 *   - was it the market rate or the statutory one?
 *   - which feed said so?
 *
 * The first is PF-069's requirement that a stale rate be **flagged on every
 * document that uses it**, and it cannot be answered from a number. Nor can
 * it be answered by looking the rate up again later: by then it may have been
 * superseded, and two feeds may have sent the same figure for different days.
 *
 * So the line records the rate's date, set and provider alongside its value.
 * Three narrow columns, and "which rate was this booked at" becomes a fact
 * about the line rather than a reconstruction.
 *
 * ## All four together, or none
 *
 * A rate value with no date is a rate nobody can place. The trigger extended
 * below requires the four columns to arrive together, which also closes a
 * smaller hole: a line could otherwise carry a rate from one set and a date
 * from another, and nothing would notice.
 *
 * ## This one is NOT online-safe, and the alternative does not exist
 *
 * ADD COLUMN on a partitioned parent takes ACCESS EXCLUSIVE on the parent and
 * on all 43 partitions at once, and CREATE INDEX on a partitioned table
 * cannot be CONCURRENTLY at all - PostgreSQL does not support it there. There
 * is no per-partition version of either: a column added to a partition is not
 * a column of the table.
 *
 * So the honest statement is that this needs a maintenance window on a tenant
 * with real volume, and that it is being run now, before any tenant has one.
 *
 * @unsafe-migration-reviewed: adds columns to the partitioned `journal_lines`,
 * which locks the parent and every partition. There is no concurrent form of
 * ADD COLUMN or of CREATE INDEX on a partitioned table, so this cannot be
 * split. It is being applied while the schema is pre-release and no tenant
 * holds posted lines; back-filling it into a tenant with history needs a
 * maintenance window, and the pre-flight will say so again.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | Raw SQL rather than the schema builder.
         |
         | `journal_lines` is partitioned, and ADD COLUMN on the parent
         | propagates to all 43 partitions in one catalogue operation - which
         | is what we want and what Blueprint would also do, but the builder
         | has no way to express the CHECK below in the same statement, and
         | two statements means two lock acquisitions.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE journal_lines
                ADD COLUMN fx_rate_set      varchar(32),
                ADD COLUMN fx_rate_as_of    date,
                ADD COLUMN fx_rate_provider varchar(32),

                /*
                 | All four, or none. A rate with no date cannot be placed,
                 | and a date with no rate describes nothing.
                 |
                 | NOT VALID, and that is the interesting part.
                 |
                 | PostgreSQL enforces a NOT VALID check on every INSERT and
                 | UPDATE from this moment on; what it skips is verifying
                 | rows that are already there. Which is exactly the right
                 | semantics here, because lines translated before this
                 | migration have a rate and no provenance, and there is no
                 | way to supply it:
                 |
                 |   - two feeds can have sent the same figure for different
                 |     days, so the value does not identify the row
                 |   - the rate may since have been superseded
                 |
                 | Filling the columns with a plausible guess would be
                 | inventing audit evidence, which is the one thing this
                 | system must never do. So the old lines keep saying "a rate
                 | was used and we cannot tell you which", every new line has
                 | to say which, and `ledger:verify` reports the old ones by
                 | name rather than leaving them to be discovered.
                 */
                ADD CONSTRAINT journal_lines_rate_is_complete CHECK (
                    (fx_rate IS NULL
                        AND fx_rate_set IS NULL
                        AND fx_rate_as_of IS NULL
                        AND fx_rate_provider IS NULL)
                    OR (fx_rate IS NOT NULL
                        AND fx_rate_set IS NOT NULL
                        AND fx_rate_as_of IS NOT NULL
                        AND fx_rate_provider IS NOT NULL)
                ) NOT VALID;
        SQL);

        /*
         | The staleness index.
         |
         | The question is "which postings used a rate older than N days",
         | asked per entity over a period. Leading with the entity, then the
         | accounting date, and INCLUDEing the rate's own date so the answer
         | comes from the index.
         */
        DB::unprepared(<<<'SQL'
            CREATE INDEX journal_lines_stale_rates
                ON journal_lines (entity_id, accounting_date)
                INCLUDE (fx_rate_as_of, fx_rate_set)
                WHERE fx_rate IS NOT NULL;
        SQL);

        $this->replaceTranslationInvariant();
    }

    /**
     * The same three claims as before, plus the rate's provenance.
     *
     * Replaced rather than added to, because two triggers refusing
     * overlapping things means the message a user sees depends on firing
     * order - and firing order between triggers of the same type is
     * alphabetical, which is not a sentence anybody should have to know.
     */
    private function replaceTranslationInvariant(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_line_translation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                functional char(3);
                is_enabled boolean;
                expected   numeric(21,4);
                set_exists boolean;
            BEGIN
                SELECT functional_currency INTO functional
                FROM entities
                WHERE id = NEW.entity_id;

                IF functional IS NULL THEN
                    RAISE EXCEPTION
                        'Journal line % names entity %, which does not exist.',
                        NEW.id, NEW.entity_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                SELECT enabled INTO is_enabled
                FROM entity_currencies
                WHERE entity_id = NEW.entity_id AND currency = NEW.currency;

                IF is_enabled IS NULL OR is_enabled = false THEN
                    RAISE EXCEPTION
                        'Entity % does not transact in %, so a posting in it was refused rather than converted at a rate nobody chose. Enable the currency first.',
                        NEW.entity_id, NEW.currency
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.currency = functional THEN
                    IF NEW.fx_rate IS NOT NULL THEN
                        RAISE EXCEPTION
                            'Journal line % is already in the functional currency (%) but carries a rate of %. A rate asserts that a translation happened; recording one here makes a translated line and an untranslated one indistinguishable afterwards.',
                            NEW.id, functional, NEW.fx_rate
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.functional_amount <> NEW.amount THEN
                        RAISE EXCEPTION
                            'Journal line % is in the functional currency (%) but its functional amount (%) differs from its amount (%).',
                            NEW.id, functional, NEW.functional_amount, NEW.amount
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.fx_rate IS NULL THEN
                    RAISE EXCEPTION
                        'Journal line % is in % and the entity''s books are in %, but no rate was supplied. A missing rate is not a rate of 1: accepting this would assert that % % equals % % and balance perfectly while doing it.',
                        NEW.id, NEW.currency, functional, NEW.amount, NEW.currency, NEW.amount, functional
                        USING ERRCODE = 'not_null_violation';
                END IF;

                expected := round(NEW.amount * NEW.fx_rate, 4);

                IF abs(NEW.functional_amount - expected) > 0.0001 THEN
                    RAISE EXCEPTION
                        'Journal line % does not follow from its own rate: % % at % is % %, but the line carries %. Either the rate or the translated amount is wrong, and the pair cannot both be recorded.',
                        NEW.id, NEW.amount, NEW.currency, NEW.fx_rate, expected, functional, NEW.functional_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 | The rate's provenance - PF-069.
                 |
                 | A number with no date cannot be judged stale, and
                 | staleness is a thing documents are required to show. The
                 | CHECK already demands the four columns together; this
                 | demands that the set is a real one, which a CHECK cannot
                 | do because it would need a subquery.
                 */
                /*
                 | Skipped when the provenance is absent.
                 |
                 | A line translated before the provenance columns existed
                 | has a rate and no date, and the NOT VALID constraint above
                 | is what refuses a NEW line in that shape. Raising here as
                 | well would mean an UPDATE to an unrelated column on one of
                 | those old lines - a narration correction, a dimension
                 | backfill - failed for a reason that has nothing to do with
                 | the change being made.
                 */
                IF NEW.fx_rate_as_of IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.fx_rate_as_of > NEW.accounting_date THEN
                    RAISE EXCEPTION
                        'Journal line % is dated % but was translated at a rate dated %. A rate from after the transaction did not exist when it happened, and booking at it restates the period.',
                        NEW.id, NEW.accounting_date, NEW.fx_rate_as_of
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT true INTO set_exists FROM fx_rate_sets WHERE code = NEW.fx_rate_set;

                IF set_exists IS NULL THEN
                    RAISE EXCEPTION
                        'Journal line % names rate set "%", which does not exist. The set is what says whether the rate was the market one, the monthly average or a statutory rate, and a name nobody can resolve answers none of those.',
                        NEW.id, NEW.fx_rate_set
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        /*
         | The function comes FIRST, and that order is the whole rollback.
         |
         | `assert_line_translation()` reads the three columns this migration
         | added. Dropping them while it still refers to them leaves a
         | trigger that raises `column does not exist` on every single
         | insert - a ledger that accepts nothing, which is a worse state
         | than the one being rolled back from.
         */
        $this->restorePreviousTranslationInvariant();

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS journal_lines_stale_rates;

            ALTER TABLE journal_lines
                DROP CONSTRAINT IF EXISTS journal_lines_rate_is_complete,
                DROP COLUMN IF EXISTS fx_rate_provider,
                DROP COLUMN IF EXISTS fx_rate_as_of,
                DROP COLUMN IF EXISTS fx_rate_set;
        SQL);
    }

    /** The 001200 body, without the provenance block. */
    private function restorePreviousTranslationInvariant(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_line_translation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                functional char(3);
                is_enabled boolean;
                expected   numeric(21,4);
            BEGIN
                SELECT functional_currency INTO functional
                FROM entities
                WHERE id = NEW.entity_id;

                IF functional IS NULL THEN
                    RAISE EXCEPTION
                        'Journal line % names entity %, which does not exist.',
                        NEW.id, NEW.entity_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                SELECT enabled INTO is_enabled
                FROM entity_currencies
                WHERE entity_id = NEW.entity_id AND currency = NEW.currency;

                IF is_enabled IS NULL OR is_enabled = false THEN
                    RAISE EXCEPTION
                        'Entity % does not transact in %, so a posting in it was refused rather than converted at a rate nobody chose. Enable the currency first.',
                        NEW.entity_id, NEW.currency
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.currency = functional THEN
                    IF NEW.fx_rate IS NOT NULL THEN
                        RAISE EXCEPTION
                            'Journal line % is already in the functional currency (%) but carries a rate of %. A rate asserts that a translation happened; recording one here makes a translated line and an untranslated one indistinguishable afterwards.',
                            NEW.id, functional, NEW.fx_rate
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.functional_amount <> NEW.amount THEN
                        RAISE EXCEPTION
                            'Journal line % is in the functional currency (%) but its functional amount (%) differs from its amount (%).',
                            NEW.id, functional, NEW.functional_amount, NEW.amount
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.fx_rate IS NULL THEN
                    RAISE EXCEPTION
                        'Journal line % is in % and the entity''s books are in %, but no rate was supplied. A missing rate is not a rate of 1: accepting this would assert that % % equals % % and balance perfectly while doing it.',
                        NEW.id, NEW.currency, functional, NEW.amount, NEW.currency, NEW.amount, functional
                        USING ERRCODE = 'not_null_violation';
                END IF;

                expected := round(NEW.amount * NEW.fx_rate, 4);

                IF abs(NEW.functional_amount - expected) > 0.0001 THEN
                    RAISE EXCEPTION
                        'Journal line % does not follow from its own rate: % % at % is % %, but the line carries %. Either the rate or the translated amount is wrong, and the pair cannot both be recorded.',
                        NEW.id, NEW.amount, NEW.currency, NEW.fx_rate, expected, functional, NEW.functional_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }
};
