<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A posted entry carries a fingerprint of its own substance - PF-054.
 *
 * ## What the immutability triggers prove, and what they do not
 *
 * `journal_entries_posted_immutable` and `journal_lines_posted_immutable`
 * refuse an UPDATE to a posted entry. That is the right control and it proves
 * one thing: no statement went through those triggers and changed a posted
 * figure.
 *
 * It proves nothing about a statement that did not go through them. A role
 * that can `ALTER TABLE ... DISABLE TRIGGER`, a restore from a doctored dump,
 * a superuser session, a bug in a future migration: each leaves a ledger in
 * which every trigger is present and correct and a figure has moved. Nothing
 * in the schema would notice, and the trial balance would still tie - because
 * a tamper that balances is the only kind worth doing.
 *
 * So the substance of each entry is hashed, and `ledger:verify` recomputes it.
 * A changed amount, a moved account, an added or removed line, a re-dated
 * entry: each changes the hash, and the report names the entry.
 *
 * ## A separate table, which the first attempt got wrong
 *
 * The obvious design is a `content_hash` column on `journal_entries`, written
 * by a trigger. It cannot work: the entry is inserted with `status =
 * 'posted'`, so the trigger's own UPDATE is refused by the immutability
 * trigger. Carving an exception into that refusal to let the fingerprint
 * through would widen the one control that has no business being widened.
 *
 * So the fingerprint lives in its own table. `journal_entries` is never
 * updated, the immutability trigger keeps its single meaning, and the
 * fingerprint gets a guarantee of its own that a column could not have: it
 * may be refined only inside the transaction that created it, and never
 * afterwards by anyone.
 *
 * ## Computed in the DATABASE, at COMMIT
 *
 * Not in PHP at posting time. The application computing its own fingerprint
 * proves only that the application wrote what it meant to write - an import
 * that bypasses the Poster would get no fingerprint at all, and a missing one
 * is indistinguishable from a legitimate gap.
 *
 * At commit rather than per statement, because an entry is transiently
 * incomplete while its lines are being inserted - the same reason the balance
 * trigger is deferred. A deferred constraint trigger is per-row, so the first
 * line of a fifty-line entry computes the hash and the other forty-nine see a
 * line count that already matches and return: one hash per entry, not fifty.
 *
 * ## What is in the hash, and the one field deliberately left out
 *
 * In: the entity, journal, period, number, accounting date, posting rule,
 * source, narration, what it reverses, and every line's account, amounts,
 * currency, rate provenance, party, tax code, dimensions and narration - in
 * line order.
 *
 * Out: `status`. An entry moves from `posted` to `reversed` legitimately when
 * something reverses it, and that is not a change to its substance. Including
 * it would make every reversal look like a tamper, and a check that cries
 * wolf is a check nobody runs.
 *
 * @online-safe Creates one table, one function and two deferred triggers. No
 *              rewrite of an existing table, and entries posted before this
 *              migration simply have no fingerprint row - they cannot be
 *              given a trustworthy one after the fact, and a retroactive hash
 *              would certify whatever the table happens to say today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_fingerprints', function (Blueprint $table): void {
            $table->char('journal_entry_id', 26)->primary();
            $table->char('entity_id', 26);
            $table->char('content_hash', 64);
            $table->unsignedInteger('line_count');
            $table->timestampTz('fingerprinted_at');

            /*
             | The transaction that wrote it, not the time it was written.
             |
             | The write-once guard has to distinguish "this transaction is
             | still settling its own fingerprint" from "somebody is
             | rewriting a fingerprint taken earlier". The first version
             | compared `fingerprinted_at` against `now()` and always
             | refused: `timestampTz` stores whole seconds and `now()` has
             | microseconds, so the two were never equal and every
             | multi-statement entry in the check suites failed.
             |
             | A transaction id is what the test actually means, and it has
             | no precision to get wrong.
             */
            $table->unsignedBigInteger('written_in_xact');
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE journal_entry_fingerprints IS
                'peakflow:tenant-scoped peakflow:no-delete - SHA-256 of each entry''s substance and its '
                'lines, computed by a deferred trigger at commit. Recomputed by ledger:verify, which is '
                'what makes a figure changed OUTSIDE the immutability triggers detectable rather than '
                'merely forbidden. A separate table because journal_entries cannot be updated after '
                'posting - by design, and that design is not being widened for this.';

            COMMENT ON COLUMN journal_entry_fingerprints.line_count IS
                'How many lines the hash covers. Not redundant: it is how the second and subsequent rows '
                'of a multi-line insert know the fingerprint is already current and skip recomputing it, '
                'which is the difference between one hash per entry and one per line.';

            COMMENT ON COLUMN journal_entry_fingerprints.written_in_xact IS
                'The transaction that wrote this fingerprint. The write-once guard compares it against '
                'pg_current_xact_id() to tell "this transaction is still settling its own fingerprint" '
                'from "somebody is rewriting one taken earlier" - a transaction id rather than a '
                'timestamp, because fingerprinted_at stores whole seconds and now() does not, so an '
                'equality on it is never true.';

            /*
             | The canonical form, in one function.
             |
             | `jsonb_build_object` with keys in a fixed order, and the lines
             | ordered by their id - a ULID, so insertion order. A hash over
             | an unordered set is a hash that changes when the planner feels
             | like it.
             |
             | Numerics are rendered to text at their declared scale rather
             | than left to jsonb's numeric formatting, because '100.00' and
             | '100.0000' are the same number and different strings, and the
             | column's scale is the one that has to win.
             */
            CREATE OR REPLACE FUNCTION peakflow_entry_fingerprint(entry_id char(26))
            RETURNS text
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT encode(sha256(convert_to(
                    jsonb_build_object(
                        'entity',     e.entity_id,
                        'journal',    e.journal_id,
                        'period',     e.period_id,
                        'number',     e.number,
                        'date',       to_char(e.accounting_date, 'YYYY-MM-DD'),
                        'rule',       coalesce(e.posting_rule, ''),
                        'source',     coalesce(e.source_type, '') || ':' || coalesce(e.source_id, ''),
                        'narration',  coalesce(e.narration, ''),
                        'reverses',   coalesce(e.reverses_entry_id, ''),
                        'lines',      coalesce((
                            SELECT jsonb_agg(
                                jsonb_build_object(
                                    'account',    l.account_id,
                                    'amount',     trim(to_char(l.amount, '9999999999999999990.0000')),
                                    'currency',   l.currency,
                                    'functional', trim(to_char(l.functional_amount, '9999999999999999990.0000')),
                                    'rate',       coalesce(trim(to_char(l.fx_rate, '990.000000000000')), ''),
                                    'rate_set',   coalesce(l.fx_rate_set, ''),
                                    'rate_as_of', coalesce(to_char(l.fx_rate_as_of, 'YYYY-MM-DD'), ''),
                                    'provider',   coalesce(l.fx_rate_provider, ''),
                                    'party',      coalesce(l.party_id, ''),
                                    'tax_code',   coalesce(l.tax_code_id, ''),
                                    'dimensions', concat_ws('|',
                                        coalesce(l.dimension_1, '-'), coalesce(l.dimension_2, '-'),
                                        coalesce(l.dimension_3, '-'), coalesce(l.dimension_4, '-'),
                                        coalesce(l.dimension_5, '-'), coalesce(l.dimension_6, '-'),
                                        coalesce(l.dimension_7, '-'), coalesce(l.dimension_8, '-')),
                                    'narration',  coalesce(l.narration, '')
                                )
                                ORDER BY l.id
                            )
                            FROM journal_lines l
                            WHERE l.journal_entry_id = e.id
                        ), '[]'::jsonb)
                    )::text, 'UTF8')), 'hex')
                FROM journal_entries e
                WHERE e.id = entry_id;
            $$;

            COMMENT ON FUNCTION peakflow_entry_fingerprint(char) IS
                'The canonical fingerprint of an entry and its lines. SECURITY DEFINER so it can read the '
                'rows under row-level security while running on behalf of whoever is writing. `status` is '
                'deliberately absent: an entry moves from posted to reversed legitimately, and including '
                'it would make every reversal look like a tamper.';

            CREATE OR REPLACE FUNCTION peakflow_record_entry_fingerprint()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                target  char(26);
                owner   char(26);
                lines   integer;
                current integer;
            BEGIN
                /*
                 | An IF, not a CASE expression.
                 |
                 | plpgsql plans the whole expression before evaluating it,
                 | so a CASE mentioning NEW.journal_entry_id fails on the
                 | journal_entries trigger - where NEW has no such field -
                 | even though that arm would never be taken. The error names
                 | the field and not the reason.
                 */
                IF TG_TABLE_NAME = 'journal_entries' THEN
                    target := NEW.id;
                ELSE
                    target := NEW.journal_entry_id;
                END IF;

                SELECT e.entity_id INTO owner FROM journal_entries e WHERE e.id = target;

                /*
                 | The entry is gone. Which happens: a DRAFT can be deleted,
                 | and deleting it takes its lines with it, so this fires for
                 | lines whose entry no longer exists. Nothing to fingerprint.
                 */
                IF owner IS NULL THEN
                    RETURN NULL;
                END IF;

                SELECT count(*) INTO lines FROM journal_lines l WHERE l.journal_entry_id = target;

                SELECT f.line_count INTO current
                FROM journal_entry_fingerprints f
                WHERE f.journal_entry_id = target;

                /*
                 | The line-count shortcut, and why it is only for LINES.
                 |
                 | On a multi-line insert the first row computes the hash and
                 | the rest see a count that already matches and return: one
                 | hash per entry rather than one per line.
                 |
                 | It must not apply to an entry-level change. Editing a
                 | draft's narration changes the substance and not the line
                 | count, so taking the shortcut there would leave the stored
                 | hash describing the version before the edit - and the
                 | entry would read as tampered the moment it was posted.
                 */
                IF TG_TABLE_NAME <> 'journal_entries'
                   AND current IS NOT NULL AND current = lines THEN
                    RETURN NULL;
                END IF;

                INSERT INTO journal_entry_fingerprints
                    (journal_entry_id, entity_id, content_hash, line_count,
                     fingerprinted_at, written_in_xact)
                VALUES
                    (target, owner, peakflow_entry_fingerprint(target), lines,
                     now(), pg_current_xact_id()::text::bigint)
                ON CONFLICT (journal_entry_id) DO UPDATE
                    SET content_hash = excluded.content_hash,
                        line_count = excluded.line_count,
                        fingerprinted_at = excluded.fingerprinted_at,
                        written_in_xact = excluded.written_in_xact;

                RETURN NULL;
            END;
            $$;

            /*
             | Deferred, for the same reason the balance trigger is.
             |
             | An entry is transiently incomplete while its lines are being
             | inserted. A fingerprint taken then would cover a partial entry
             | and would then be "corrected" on the next line, which is the
             | behaviour a write-once fingerprint must not have.
             */
            /*
             | INSERT and UPDATE on the entry, not INSERT alone.
             |
             | A DRAFT entry is mutable - that is what a draft is - and its
             | number, date and narration are all in the fingerprint. With
             | INSERT only, editing a draft's narration left the stored hash
             | describing the version before the edit, and the entry read as
             | tampered the moment it was posted. A posted entry cannot be
             | updated at all, so this adds nothing there.
             */
            CREATE CONSTRAINT TRIGGER journal_entries_fingerprint
                AFTER INSERT OR UPDATE ON journal_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_record_entry_fingerprint();

            CREATE CONSTRAINT TRIGGER journal_lines_fingerprint
                AFTER INSERT OR UPDATE ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_record_entry_fingerprint();

            /*
             | A fingerprint may be refined only inside the transaction that
             | created it.
             |
             | The row records the transaction that wrote it, so the rule is
             | exact: the deferred triggers all fire at commit and may settle
             | on a final hash between them; once the transaction ends, the
             | fingerprint is a fact about the past and nothing may touch it.
             |
             | Not a timestamp comparison. `timestampTz` stores whole seconds
             | and `now()` has microseconds, so `fingerprinted_at = now()`
             | was never true and the first version of this guard refused
             | every legitimate refinement - which showed up as four
             | unrelated ledger checks failing with a message about
             | fingerprints.
             |
             | Which is the guarantee a column on `journal_entries` could not
             | have given, and the reason this table is worth its own file.
             */
            CREATE OR REPLACE FUNCTION peakflow_refuse_refingerprinting()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.written_in_xact = pg_current_xact_id()::text::bigint THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION
                    'The fingerprint of journal entry % was taken at % and cannot be rewritten. It is the '
                    'only record of what that entry contained when it was posted; a fingerprint that can '
                    'be refreshed certifies whatever the table says today, which is the opposite of what '
                    'it is for.',
                    OLD.journal_entry_id, OLD.fingerprinted_at
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER journal_entry_fingerprints_write_once
                BEFORE UPDATE ON journal_entry_fingerprints
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_refuse_refingerprinting();

            SELECT peakflow_apply_entity_rls('journal_entry_fingerprints');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_fingerprint ON journal_lines;
            DROP TRIGGER IF EXISTS journal_entries_fingerprint ON journal_entries;
            DROP TRIGGER IF EXISTS journal_entry_fingerprints_write_once ON journal_entry_fingerprints;
            DROP FUNCTION IF EXISTS peakflow_refuse_refingerprinting();
            DROP FUNCTION IF EXISTS peakflow_record_entry_fingerprint();
            DROP FUNCTION IF EXISTS peakflow_entry_fingerprint(char);
        SQL);

        Schema::dropIfExists('journal_entry_fingerprints');
    }
};
