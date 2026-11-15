<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment terms, and the due dates they produce - PF-066.
 *
 * @financial
 *
 * ## Terms are data, not a number of days
 *
 * "Net 30" is one of five shapes that occur in practice, and the other four
 * cannot be expressed as a day count:
 *
 *   net               30 days from the invoice date
 *   end_of_month      end of the invoice month, plus n days - which is
 *                     different from "30 days" by up to 30 days, depending
 *                     on when in the month the invoice was issued
 *   fixed_day         the 25th of the following month, as many retailers
 *                     and most government buyers require
 *   instalments       several due dates on ONE invoice
 *   immediate         on receipt
 *
 * A `payment_terms.days` integer column covers the first and quietly
 * mis-states the rest, and the mis-statement surfaces as an aging report
 * that disagrees with the customer's own ledger.
 *
 * ## Instalments are several due dates on one document
 *
 * Which is the part that reaches furthest. Each instalment is separately
 * dunnable and separately allocatable, so a payment against an invoice has to
 * know WHICH instalment it settles, and the aging report has to bucket one
 * invoice across several columns. Modelling instalments as separate invoices
 * instead breaks the one thing a customer is certain of: the invoice number
 * they were given.
 *
 * ## Non-business days are a per-entity policy, not a weekend
 *
 * Friday and Saturday are the weekend across much of the Gulf; Sunday to
 * Thursday is the working week. Hard-coding Saturday and Sunday produces due
 * dates on days the bank is shut, in exactly the markets this product is
 * built for - and a due date on a closed day makes an invoice overdue for
 * reasons that are nobody's fault.
 *
 * So the weekend is a per-entity set of weekdays and the holidays are rows.
 *
 * @online-safe Creates new tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Tenant-wide: "Net 30" means the same thing for every company
            // in the group, and duplicating it per entity means two of them
            // drifting apart.
            $table->string('code', 32);
            $table->string('name_ar');
            $table->string('name_en');

            /*
             | UNCL 2475 is the EN 16931 code list for "payment terms
             | reference date", which is what an e-invoice carries - the
             | basis the due date is counted FROM, not the terms themselves.
             | Stored because a receiving system computes its own due date
             | from it, and a mismatch is a dispute.
             */
            $table->string('kind', 16);
            $table->smallInteger('days')->default(0);
            $table->smallInteger('day_of_month')->nullable();
            $table->smallInteger('months_forward')->default(0);
            $table->string('reference_date_code', 8)->default('3');

            /*
             | Early settlement discount - EN 16931's BT-20 in structured
             | form rather than as a sentence of prose.
             |
             | Two numbers and nothing else: the percentage and the days
             | within which it applies. "2/10 net 30" is the whole of it.
             */
            $table->decimal('discount_percent', 21, 12)->nullable();
            $table->smallInteger('discount_days')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['code']);
        });

        /*
         | The instalment schedule, as a proportion of the total.
         |
         | Proportions rather than amounts, because the terms are attached to
         | a party before any invoice exists. The amounts are allocated at
         | issue, with the residual going to the LAST instalment - so the
         | instalments always sum to the invoice exactly, and the rounding
         | difference is in the one place everybody looks.
         */
        Schema::create('payment_term_instalments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_term_id')->constrained()->cascadeOnDelete();

            $table->smallInteger('sequence');
            $table->decimal('proportion', 21, 12);

            $table->string('kind', 16);
            $table->smallInteger('days')->default(0);
            $table->smallInteger('day_of_month')->nullable();
            $table->smallInteger('months_forward')->default(0);

            $table->timestamps();

            $table->unique(['payment_term_id', 'sequence']);
        });

        /*
         | The business-day policy, per entity.
         |
         | `weekend_days` is a list of ISO-8601 weekday numbers (1 = Monday,
         | 7 = Sunday), so Friday and Saturday is [5, 6] and Saturday and
         | Sunday is [6, 7]. A list rather than two columns because some
         | markets have a one-day weekend and some organisations work six
         | days.
         */
        Schema::create('business_day_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            $table->json('weekend_days');

            /*
             | What to do when a due date lands on a non-business day.
             |
             | `forward` is the usual commercial answer and `backward` is
             | required by some contracts - a payment due "by" month end
             | cannot move into the next month. `none` leaves the date alone,
             | which is right where the parties settle electronically.
             */
            $table->string('adjustment', 16)->default('forward');

            $table->timestamps();

            $table->unique(['entity_id']);
        });

        Schema::create('holidays', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            $table->date('date');
            $table->string('name');

            /*
             | Observed dates, not rules.
             |
             | Eid and the Islamic new year move against the Gregorian
             | calendar and their observed dates are announced, sometimes
             | days beforehand. A system that computes them from a rule is
             | wrong in the years the announcement differs, which is most
             | of them.
             */
            $table->boolean('confirmed')->default(true);
            $table->timestamps();

            $table->unique(['entity_id', 'date']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_terms
                ADD CONSTRAINT payment_terms_kind_known
                    CHECK (kind IN ('immediate', 'net', 'end_of_month', 'fixed_day', 'instalments')),

                ADD CONSTRAINT payment_terms_fixed_day_has_a_day
                    CHECK (kind <> 'fixed_day' OR day_of_month BETWEEN 1 AND 31),

                ADD CONSTRAINT payment_terms_days_are_sane
                    CHECK (days BETWEEN 0 AND 3650),

                -- A discount percentage with no window, or a window with no
                -- percentage, is half a term - and the half that is missing
                -- is the one the customer will assume in their favour.
                ADD CONSTRAINT payment_terms_discount_is_complete
                    CHECK ((discount_percent IS NULL AND discount_days IS NULL)
                           OR (discount_percent IS NOT NULL AND discount_days IS NOT NULL)),

                ADD CONSTRAINT payment_terms_discount_is_a_discount
                    CHECK (discount_percent IS NULL
                           OR (discount_percent > 0 AND discount_percent < 100));

            ALTER TABLE payment_term_instalments
                ADD CONSTRAINT payment_term_instalments_kind_known
                    CHECK (kind IN ('immediate', 'net', 'end_of_month', 'fixed_day')),

                ADD CONSTRAINT payment_term_instalments_proportion_is_a_share
                    CHECK (proportion > 0 AND proportion <= 1),

                ADD CONSTRAINT payment_term_instalments_sequence_is_positive
                    CHECK (sequence >= 1);

            ALTER TABLE business_day_policies
                ADD CONSTRAINT business_day_policies_adjustment_known
                    CHECK (adjustment IN ('forward', 'backward', 'none')),

                -- Every day a weekend leaves no business day to move a due
                -- date to, and the adjustment loop would never terminate.
                ADD CONSTRAINT business_day_policies_weekend_is_not_every_day
                    CHECK (jsonb_array_length(weekend_days::jsonb) BETWEEN 0 AND 6);
        SQL);

        /*
         | The instalments must sum to exactly 1.
         |
         | A trigger rather than a CHECK, because the condition spans rows.
         | Deferred to commit, because the schedule is written one row at a
         | time and is transiently incomplete after every row but the last -
         | the same reason the balanced-entry constraint is deferred.
         |
         | Enforced at all because a schedule summing to 0.99 invoices 99% of
         | the total and leaves 1% that is never billed and never chased. It
         | balances, it ages correctly, and nobody notices until the customer
         | closes their account.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_instalments_sum_to_one()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                term_id char(26);
                total   numeric(21,12);
                parts   integer;
            BEGIN
                term_id := coalesce(NEW.payment_term_id, OLD.payment_term_id);

                SELECT sum(proportion), count(*) INTO total, parts
                FROM payment_term_instalments
                WHERE payment_term_id = term_id;

                -- Every instalment deleted is a term with no schedule, which
                -- the terms row's own kind check will catch if it still
                -- claims to be an instalment term.
                IF parts = 0 THEN
                    RETURN NULL;
                END IF;

                IF total <> 1 THEN
                    RAISE EXCEPTION
                        'The instalments for payment term % sum to % across % instalment(s), not 1. A schedule summing to less invoices only part of the total and leaves the rest never billed and never chased - it balances, it ages correctly, and nobody notices until the customer closes the account.',
                        term_id, total, parts
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER payment_term_instalments_sum_to_one
                AFTER INSERT OR UPDATE OR DELETE ON payment_term_instalments
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION assert_instalments_sum_to_one();
        SQL);

        $this->seedCommonTerms();

        foreach (['business_day_policies', 'holidays'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE payment_terms IS
                'peakflow:tenant-scoped - "Net 30" means the same thing for every company in the group, and '
                'duplicating it per entity means two of them drifting apart.';

            COMMENT ON TABLE payment_term_instalments IS
                'peakflow:tenant-scoped - the schedule belongs to the terms, which belong to the tenant.';
        SQL);
    }

    /**
     * The terms almost every tenant needs, so the first invoice has one.
     *
     * Seeded rather than left to configuration because an empty list makes
     * the first invoice fail on a foreign key, and because these five are
     * the shapes the schema exists to distinguish - having them present is
     * what shows the difference between "net 30" and "end of month plus 30".
     */
    private function seedCommonTerms(): void
    {
        $now = now();

        /*
         | Every row carries every column.
         |
         | A multi-row INSERT in PostgreSQL requires identical keys per row,
         | and omitting `day_of_month` on the rows that have no day produced
         | "VALUES lists must all be the same length" - which names the
         | symptom and not the cause, and only on the sixth row.
         */
        $term = static fn (array $values): array => array_merge([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'kind' => 'net',
            'days' => 0,
            'day_of_month' => null,
            'months_forward' => 0,
            'reference_date_code' => '3',
            'discount_percent' => null,
            'discount_days' => null,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $values);

        DB::table('payment_terms')->insert([
            $term([
                'code' => 'IMMEDIATE', 'name_ar' => 'الدفع عند الاستلام', 'name_en' => 'Due on receipt',
                'kind' => 'immediate',
            ]),
            $term([
                'code' => 'NET15', 'name_ar' => 'صافي 15 يوماً', 'name_en' => 'Net 15 days',
                'kind' => 'net', 'days' => 15,
            ]),
            $term([
                'code' => 'NET30', 'name_ar' => 'صافي 30 يوماً', 'name_en' => 'Net 30 days',
                'kind' => 'net', 'days' => 30,
            ]),
            $term([
                'code' => 'EOM30', 'name_ar' => 'نهاية الشهر + 30 يوماً', 'name_en' => 'End of month plus 30 days',
                'kind' => 'end_of_month', 'days' => 30,
            ]),
            $term([
                'code' => 'DAY25NEXT', 'name_ar' => 'اليوم 25 من الشهر التالي',
                'name_en' => '25th of the following month',
                'kind' => 'fixed_day', 'day_of_month' => 25, 'months_forward' => 1,
            ]),
            $term([
                // "2/10 net 30": 2% off if paid within 10 days, otherwise
                // the full amount at 30. The most common discount term in
                // the English-speaking world, and unparseable from prose.
                'code' => 'NET30-2-10', 'name_ar' => 'صافي 30 بخصم 2٪ خلال 10 أيام',
                'name_en' => 'Net 30, 2% discount within 10 days',
                'kind' => 'net', 'days' => 30, 'discount_percent' => '2', 'discount_days' => 10,
            ]),
        ]);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payment_term_instalments_sum_to_one ON payment_term_instalments;
            DROP FUNCTION IF EXISTS assert_instalments_sum_to_one();
        SQL);

        Schema::dropIfExists('holidays');
        Schema::dropIfExists('business_day_policies');
        Schema::dropIfExists('payment_term_instalments');
        Schema::dropIfExists('payment_terms');
    }
};
