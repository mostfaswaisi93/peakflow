<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies an entity transacts in, and the rates between them - PF-067, PF-068.
 *
 * @financial
 *
 * ## Direction is stored, never inferred
 *
 * `USD/EUR` and `EUR/USD` are separate rows. Both, if both are used.
 *
 * This is the one rule in this file worth stating twice, because the saving
 * from breaking it is one row and the cost is permanent:
 *
 *     stored      USD -> EUR  0.920000000000
 *     inverted    EUR -> USD  1 / 0.92 = 1.086956521739...
 *     round trip  1,000 USD -> 920.00 EUR -> 1,000.0000000004 USD
 *
 * Four ten-thousandths of a cent, in the same direction, on every conversion.
 * It accumulates into a consolidation difference that is small enough to be
 * dismissed and large enough to be noticed, which is the worst size a
 * difference can be: nobody can justify a week finding it and nobody can
 * sign the accounts while it is there.
 *
 * So there is no inverse anywhere - not in SQL, not in the `Rate` class,
 * which deliberately has no `inverse()` method - and `from_currency <>
 * to_currency` is a CHECK rather than a convention.
 *
 * ## A missing rate is a refusal, never a 1
 *
 * The failure this schema is shaped against is a foreign-currency line that
 * reaches the ledger with no rate and a functional amount equal to its
 * original amount. It balances. It passes every total. And it silently
 * asserts that 4,000 EUR is 4,000 SAR.
 *
 * Two triggers make it unrepresentable rather than discouraged:
 *
 *   - a line in a currency other than the entity's functional currency must
 *     carry a rate, and its functional amount must FOLLOW from that rate
 *   - a line already in the functional currency must carry no rate, because
 *     a rate of 1 claims a translation that did not happen
 *
 * A PHP guard would be enough for the posting path. It is not enough for an
 * import, a correction script, or a console session, and those are where this
 * actually goes wrong.
 *
 * ## Three rate sets, not one
 *
 * A daily market rate for transactions, a monthly average for translating
 * profit or loss, and a statutory rate because tax authorities mandate their
 * own and it is never the one the treasury uses. They coexist; a conversion
 * names the set it used, and that lands on the line.
 *
 * @see docs/architecture/money-and-precision.md
 * @see IAS 21
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | Which currencies an entity transacts in - PF-067.
         |
         | Entity-scoped, because a group's holding company and its
         | free-zone subsidiary genuinely deal in different currencies, and
         | a dropdown offering every ISO 4217 code is how a posting ends up
         | in XAU.
         */
        Schema::create('entity_currencies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->char('currency', 3);

            /*
             | Exactly one per entity, and it is a copy of
             | `entities.functional_currency` rather than a second source of
             | truth - kept in step by a trigger, so the two can never say
             | different things.
             */
            $table->boolean('is_functional')->default(false);
            $table->boolean('enabled')->default(true);

            /*
             | Display policy - PF-067.
             |
             | None of this touches a stored amount. It is presentation, and
             | it is per entity per currency because the same tenant shows
             | SAR with the riyal symbol to a Saudi entity and as "SAR" on a
             | statement sent abroad.
             */
            $table->string('symbol_display', 8)->default('symbol');
            $table->string('symbol_position', 8)->default('before');
            $table->string('digit_shape', 16)->default('latin');

            /*
             | The exponent used for PRESENTATION, which is not always the
             | stored scale and not always the ISO one.
             |
             | Null means "the ISO 4217 exponent for this currency", which is
             | the right answer almost always. It is overridable because a
             | few jurisdictions present a 3-decimal currency at 2 on a
             | customer-facing document while still settling at 3, and
             | hard-coding the ISO value would make that unrepresentable.
             */
            $table->smallInteger('presentation_exponent')->nullable();

            // Which rate set this currency is translated at by default.
            $table->string('default_rate_set', 32)->default('market');

            $table->timestamps();

            $table->unique(['entity_id', 'currency']);
            $table->index(['entity_id', 'enabled']);
        });

        /*
         | The rate sets - PF-068.
         |
         | Tenant-wide rather than per entity: a monthly average for October
         | is a fact about October, and two entities in the same group using
         | different numbers for it is a consolidation that cannot be
         | explained.
         */
        Schema::create('fx_rate_sets', function (Blueprint $table): void {
            $table->string('code', 32)->primary();
            $table->string('name');

            // Prose, because "which set should I use" is a question an
            // accountant answers from the purpose and never from the code.
            $table->text('purpose');

            /*
             | How old a rate from this set may be before every document
             | using it is flagged - PF-069.
             |
             | Per set, because the thresholds genuinely differ: a market
             | rate three days old is a problem, and a monthly average three
             | days after month end is simply the current one.
             */
            $table->smallInteger('staleness_threshold_days')->default(3);

            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        /*
         | Rate providers and their precedence - PF-069.
         |
         | Precedence rather than a single configured provider, because a
         | feed outage must not stop a business trading. The fetch walks them
         | in order, falls back to the last known rate, and raises an alert;
         | what it never does is block an invoice.
         */
        Schema::create('fx_providers', function (Blueprint $table): void {
            $table->string('code', 32)->primary();
            $table->string('name');
            $table->smallInteger('precedence');
            $table->boolean('active')->default(true);

            /** @var list<string> which rate sets this provider can supply */
            $table->json('supplies_rate_sets')->nullable();

            // Operational history, so "why is this rate from the fallback"
            // is answerable without reading logs.
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_failure_reason')->nullable();
            $table->integer('consecutive_failures')->default(0);

            $table->timestamps();

            $table->unique(['precedence']);
        });

        /*
         | The rates themselves - PF-068.
         |
         | Effective-dated: `as_of_date` is the date the rate APPLIES to, not
         | the date it was fetched. Those differ whenever a feed is late, and
         | booking a Monday invoice at Tuesday's rate because Tuesday is when
         | the job ran is a restatement nobody asked for.
         */
        Schema::create('fx_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('rate_set', 32);
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->date('as_of_date');

            // The rate scale - 12 decimals. See App\Support\Money\Scale.
            $table->decimal('rate', 21, 12);

            $table->string('provider', 32);
            $table->timestamp('fetched_at')->nullable();

            /*
             | What the provider actually returned.
             |
             | Kept because a rate that turns out to be wrong raises exactly
             | one question - "is that what they sent us, or what we did to
             | it" - and without the payload the answer is a guess.
             */
            $table->jsonb('source_payload')->nullable();

            /*
             | A correction is a NEW row that supersedes this one. The rate
             | itself is never edited, because every amount already booked at
             | it would silently change meaning. See the trigger below.
             */
            $table->ulid('superseded_by_id')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawn_reason')->nullable();

            $table->timestamps();

            $table->index(['rate_set', 'from_currency', 'to_currency', 'as_of_date'], 'fx_rates_lookup');
            $table->index(['superseded_by_id']);
        });

        /*
         | What a fetch run did - PF-069.
         |
         | The alert is raised from here: a run that fell back to a stale
         | rate SUCCEEDED from the business's point of view and FAILED from
         | the treasury's, and a single boolean outcome cannot say both.
         */
        Schema::create('fx_rate_fetch_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('rate_set', 32);
            $table->date('as_of_date');

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->integer('pairs_requested')->default(0);
            $table->integer('rates_inserted')->default(0);
            $table->integer('pairs_unresolved')->default(0);
            $table->integer('pairs_from_fallback')->default(0);

            // One entry per provider tried, in order, with what it said.
            $table->jsonb('provider_attempts')->nullable();

            $table->string('outcome', 16)->default('running');
            $table->boolean('alert_raised')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['rate_set', 'as_of_date']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE entity_currencies
                ADD CONSTRAINT entity_currencies_symbol_display_known
                    CHECK (symbol_display IN ('symbol', 'code', 'none')),
                ADD CONSTRAINT entity_currencies_symbol_position_known
                    CHECK (symbol_position IN ('before', 'after')),
                ADD CONSTRAINT entity_currencies_digit_shape_known
                    CHECK (digit_shape IN ('latin', 'arabic_indic', 'eastern_arabic_indic')),
                -- 0 to 4: the ISO exponents in use span 0 to 4, and the
                -- stored amount scale is 4, so presenting more decimals than
                -- are stored would display precision that does not exist.
                ADD CONSTRAINT entity_currencies_presentation_exponent_sane
                    CHECK (presentation_exponent IS NULL
                           OR (presentation_exponent >= 0 AND presentation_exponent <= 4)),
                -- The functional currency cannot be switched off. An entity
                -- with its own books disabled is not a state worth having.
                ADD CONSTRAINT entity_currencies_functional_is_enabled
                    CHECK (is_functional = false OR enabled = true);

            -- Exactly one functional currency per entity.
            CREATE UNIQUE INDEX entity_currencies_one_functional
                ON entity_currencies (entity_id)
                WHERE is_functional;

            ALTER TABLE fx_rates
                -- Direction is real. A row where from = to is either an
                -- inverse someone was about to compute or a 1 someone was
                -- about to fall back to; neither is a rate.
                ADD CONSTRAINT fx_rates_direction_is_real
                    CHECK (from_currency <> to_currency),
                -- A rate of zero converts every amount to nothing and still
                -- balances, which is the most expensive kind of valid.
                ADD CONSTRAINT fx_rates_positive
                    CHECK (rate > 0),
                ADD CONSTRAINT fx_rates_withdrawal_has_a_reason
                    CHECK (withdrawn_at IS NULL OR withdrawn_reason IS NOT NULL),
                ADD CONSTRAINT fx_rates_supersession_is_not_circular
                    CHECK (superseded_by_id IS NULL OR superseded_by_id <> id);

            ALTER TABLE fx_rate_fetch_runs
                ADD CONSTRAINT fx_rate_fetch_runs_outcome_known
                    CHECK (outcome IN ('running', 'ok', 'degraded', 'failed'));

            -- Only one default set, or "the default" is a lottery.
            CREATE UNIQUE INDEX fx_rate_sets_one_default
                ON fx_rate_sets ((is_default))
                WHERE is_default;

            /*
             | Idempotency, per PF-069: re-running a fetch for the same pair,
             | date, set and provider inserts nothing and changes nothing.
             |
             | The provider is part of the key deliberately - two providers
             | disagreeing about Tuesday is normal, both numbers are worth
             | keeping, and precedence decides which one is used.
             |
             | PARTIAL, over rows still in force, and that is not a detail.
             | A total index makes a CORRECTION impossible: the corrected
             | rate has the same pair, date, set and provider as the row it
             | replaces, so the insert is silently ignored and the "new" row
             | handed back is the wrong one it was meant to replace. That was
             | not a theory - the first correction this code attempted
             | superseded a row by itself, and only a separate CHECK against
             | a circular pointer turned it into an error instead of a rate
             | that was withdrawn with nothing left standing in its place.
             */
            CREATE UNIQUE INDEX fx_rates_one_per_source
                ON fx_rates (rate_set, from_currency, to_currency, as_of_date, provider)
                WHERE withdrawn_at IS NULL AND superseded_by_id IS NULL;
        SQL);

        $this->seedRateSets();
        $this->installRateImmutability();
        $this->installFunctionalCurrencyMirror();
        $this->installTranslationInvariant();

        DB::unprepared("SELECT peakflow_apply_entity_rls('entity_currencies');");

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE fx_rates IS
                'peakflow:no-delete peakflow:tenant-scoped - a rate is a fact about the world on a date, not '
                'about one of your companies, and amounts already booked at it must stay explicable. A '
                'correction is a new row that supersedes this one.';

            COMMENT ON TABLE fx_rate_sets IS
                'peakflow:tenant-scoped - a monthly average for October is a fact about October; two entities '
                'in one group using different numbers for it is an unexplainable consolidation.';

            COMMENT ON TABLE fx_providers IS
                'peakflow:tenant-scoped - a feed is configured once for the tenant, not once per company.';

            COMMENT ON TABLE fx_rate_fetch_runs IS
                'peakflow:append-only peakflow:tenant-scoped - a fetch run is a record of what the feeds said '
                'at a moment, which is the evidence for why a document carries the rate it carries.';
        SQL);
    }

    /**
     * The three sets from PF-068, seeded rather than configured.
     *
     * Seeded because all three are needed by the first entity that trades
     * abroad, and an empty table would make the first multi-currency posting
     * fail with a foreign key error rather than do the obvious thing.
     */
    private function seedRateSets(): void
    {
        DB::table('fx_rate_sets')->insert([
            [
                'code' => 'market',
                'name' => 'Daily market rate',
                'purpose' => 'Transactions. The rate on the date a document is issued or a payment settles. '.
                    'This is the set almost everything uses, and the default.',
                'staleness_threshold_days' => 3,
                'is_default' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'monthly_average',
                'name' => 'Monthly average rate',
                'purpose' => 'Translating profit or loss for the period under IAS 21. Using the closing rate '.
                    'for income and expenses overstates whichever month the rate moved in.',
                // A month's average is current for the whole following
                // month, so days are the wrong unit for it; 40 covers the
                // longest gap between the month ending and the average being
                // published without flagging every document in between.
                'staleness_threshold_days' => 40,
                'is_default' => false,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'statutory',
                'name' => 'Statutory rate',
                'purpose' => 'The rate a tax authority or central bank mandates for returns and customs '.
                    'declarations. Frequently different from the market rate, and not optional where it applies.',
                'staleness_threshold_days' => 7,
                'is_default' => false,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        /*
         | `manual` at precedence 0, so a hand-entered rate beats every feed.
         |
         | Seeded rather than special-cased in the lookup, because a provider
         | the resolver treats differently without a row to say so is a rule
         | that exists only in whichever query was written last.
         |
         | It wins because somebody chose it: a statutory rate typed in from a
         | gazette, or a correction after a feed sent a wrong number, must not
         | be overruled the next time the fetch job runs.
         */
        DB::table('fx_providers')->insert([
            'code' => 'manual',
            'name' => 'Entered by hand',
            'precedence' => 0,
            'active' => true,
            'supplies_rate_sets' => null,
            'consecutive_failures' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::unprepared(<<<'SQL'
            ALTER TABLE fx_rates
                ADD CONSTRAINT fx_rates_rate_set_exists
                    FOREIGN KEY (rate_set) REFERENCES fx_rate_sets (code);

            ALTER TABLE entity_currencies
                ADD CONSTRAINT entity_currencies_rate_set_exists
                    FOREIGN KEY (default_rate_set) REFERENCES fx_rate_sets (code);
        SQL);
    }

    /**
     * A stored rate is never edited - PF-068.
     *
     * Every amount in the ledger that was translated at a rate carries both
     * the rate and the result. Editing the rate afterwards does not change
     * those amounts, which is the good news; what it changes is whether they
     * can be explained, because the row that was supposed to justify a
     * number now says something else.
     *
     * A correction is a new row with a later `created_at`, and the old row is
     * marked superseded. Both stay. The ledger keeps pointing at the one it
     * used.
     */
    private function installRateImmutability(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_rate_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION
                        'A stored exchange rate cannot be deleted. Amounts already translated at % (% -> % on % from %) carry it, and deleting the row leaves them unexplainable. Withdraw it with a reason instead.',
                        OLD.rate, OLD.from_currency, OLD.to_currency, OLD.as_of_date, OLD.provider
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.rate IS DISTINCT FROM OLD.rate
                   OR NEW.from_currency IS DISTINCT FROM OLD.from_currency
                   OR NEW.to_currency IS DISTINCT FROM OLD.to_currency
                   OR NEW.as_of_date IS DISTINCT FROM OLD.as_of_date
                   OR NEW.rate_set IS DISTINCT FROM OLD.rate_set
                   OR NEW.provider IS DISTINCT FROM OLD.provider THEN
                    RAISE EXCEPTION
                        'An exchange rate cannot be edited (% -> % on % from %, set %). Record a correcting rate and supersede this one; editing it silently changes what every amount booked at it means.',
                        OLD.from_currency, OLD.to_currency, OLD.as_of_date, OLD.provider, OLD.rate_set
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fx_rates_immutable
                BEFORE UPDATE OR DELETE ON fx_rates
                FOR EACH ROW
                EXECUTE FUNCTION refuse_rate_mutation();
        SQL);
    }

    /**
     * `entity_currencies` always contains the entity's functional currency.
     *
     * Enforced here rather than in the entity service because the row is
     * what every other check in this file reads. An entity whose functional
     * currency is not in its own enabled list cannot post at all, and
     * arranging for that to be impossible is cheaper than explaining it.
     */
    private function installFunctionalCurrencyMirror(): void
    {
        /*
         | A ULID from inside the database.
         |
         | Needed because this row is written by a trigger, and the
         | application's own ULID generator is not reachable from there. The
         | id has to be a real ULID and not a UUID, because every other
         | foreign key in the tenant is char(26) and a 32-character hex
         | string truncated to 26 is not a time-ordered identifier, it is a
         | collision waiting for enough rows.
         |
         | `random()` rather than a cryptographic source deliberately: the
         | randomness in a ULID exists to avoid collisions inside one
         | millisecond, not to be unguessable. Nothing here is a secret, and
         | an id that is expensive to generate would be generated per row.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_ulid()
            RETURNS char(26)
            LANGUAGE plpgsql
            AS $$
            DECLARE
                -- Crockford base32: no I, L, O or U, so a transcribed id
                -- cannot be confused with 1, 0 or a word.
                alphabet constant text := '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
                ms       bigint;
                result   text := '';
                i        int;
            BEGIN
                ms := floor(extract(epoch from clock_timestamp()) * 1000)::bigint;

                -- 10 characters of millisecond timestamp, most significant
                -- first, which is what makes the id sort by creation time.
                FOR i IN 1..10 LOOP
                    result := substr(alphabet, (ms % 32)::int + 1, 1) || result;
                    ms := ms / 32;
                END LOOP;

                FOR i IN 1..16 LOOP
                    result := result || substr(alphabet, floor(random() * 32)::int + 1, 1);
                END LOOP;

                RETURN result;
            END;
            $$;
        SQL);

        /*
         | SECURITY DEFINER, and this one needs its reason recorded.
         |
         | `entity_currencies` is entity-scoped, so its policy demands that
         | the row's entity match the session's current entity. At the moment
         | an entity is CREATED the session cannot be pointing at it - it did
         | not exist a statement ago - so the insert this trigger performs is
         | refused by the policy it is trying to satisfy.
         |
         | Running as the owner is the narrowest fix available. The function
         | reads nothing, takes no argument, and writes exactly one row whose
         | every value comes from the entity being inserted, so there is no
         | input through which it can be pointed at another entity. The
         | search_path is pinned so that `entity_currencies` cannot be
         | shadowed by a table in a schema the caller controls.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mirror_functional_currency()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                INSERT INTO entity_currencies (
                    id, entity_id, currency, is_functional, enabled,
                    default_rate_set, created_at, updated_at
                )
                VALUES (
                    peakflow_ulid(), NEW.id, NEW.functional_currency, true, true,
                    'market', now(), now()
                )
                ON CONFLICT (entity_id, currency) DO UPDATE
                    SET is_functional = true, enabled = true, updated_at = now();

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER entities_mirror_functional_currency
                AFTER INSERT ON entities
                FOR EACH ROW
                EXECUTE FUNCTION mirror_functional_currency();
        SQL);

        // Entities that already exist - the migration runs on a provisioned
        // tenant as well as an empty one.
        DB::unprepared(<<<'SQL'
            INSERT INTO entity_currencies (
                id, entity_id, currency, is_functional, enabled,
                default_rate_set, created_at, updated_at
            )
            SELECT peakflow_ulid(), e.id, e.functional_currency, true, true, 'market', now(), now()
            FROM entities e
            ON CONFLICT (entity_id, currency) DO NOTHING;
        SQL);
    }

    /**
     * The translation invariant - PF-068.
     *
     * This is the trigger that makes a silent fallback to 1 unrepresentable.
     *
     * Three separate claims, each checked:
     *
     *   1. a line in a foreign currency carries a rate
     *   2. its functional amount FOLLOWS from that rate, to the amount scale
     *   3. a line already in the functional currency carries NO rate
     *
     * The third looks pedantic and is not. `fx_rate = 1` on a functional-
     * currency line is how a translated line and an untranslated one become
     * indistinguishable, and the question "was this converted" is asked every
     * single time a balance is queried in two currencies.
     *
     * The tolerance on (2) is one unit at the amount scale, which absorbs the
     * difference between half-up and half-even rounding and nothing else. It
     * is deliberately not a percentage: a tolerance that scales with the
     * amount is a tolerance that hides a wrong rate on a large invoice.
     */
    private function installTranslationInvariant(): void
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

                /*
                 | The currency must be one the entity has enabled - PF-067.
                 |
                 | Checked on the LINE rather than only on the document,
                 | because an import and a correction script both reach the
                 | line directly and neither goes near document validation.
                 */
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

            CREATE TRIGGER journal_lines_translation
                BEFORE INSERT OR UPDATE ON journal_lines
                FOR EACH ROW
                EXECUTE FUNCTION assert_line_translation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_translation ON journal_lines;
            DROP FUNCTION IF EXISTS assert_line_translation();

            DROP TRIGGER IF EXISTS entities_mirror_functional_currency ON entities;
            DROP FUNCTION IF EXISTS mirror_functional_currency();

            DROP TRIGGER IF EXISTS fx_rates_immutable ON fx_rates;
            DROP FUNCTION IF EXISTS refuse_rate_mutation();

            DROP FUNCTION IF EXISTS peakflow_ulid();
        SQL);

        Schema::dropIfExists('fx_rate_fetch_runs');
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('fx_providers');
        Schema::dropIfExists('entity_currencies');
        Schema::dropIfExists('fx_rate_sets');
    }
};
