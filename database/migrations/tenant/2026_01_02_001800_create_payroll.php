<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salaries, through the same controls as every other payable - PF-256.
 *
 * ## A salary is a payable and goes out through the payment run
 *
 * Not a separate path. A separate path for payroll is a separate path
 * ROUND the controls: no maker-checker, no beneficiary verification, no
 * duplicate detection, no segregation of duties - and payroll is the
 * largest regular outflow most businesses have.
 *
 * So a payroll run produces an ordinary approved `bill` per employee for
 * the NET pay, and PF-174's run selects it like any other. Nothing in
 * `PaymentRuns` knows payroll exists, which is the point: there is
 * nothing to go round.
 *
 * ## Why the bill is the NET and the run posts the rest
 *
 * The bill is what we owe the employee. The deductions are owed to
 * somebody else - an authority - and the employer's contributions are a
 * cost the employee never sees.
 *
 * A product that made the bill the gross would pay the employee their
 * gross, and one that posted only the net would show a salary cost that
 * moves when a tax rate changes, with a liability for the difference
 * appearing from nowhere. So the payroll run posts the full entry and
 * the bill carries the net.
 *
 * ## Visibility is enforced at the ROW
 *
 * Not hidden on a screen. Every table here carries a second RLS policy
 * requiring `peakflow.payroll_visible`, which the application sets only
 * for a session whose user holds `payroll.view`. Without it the tables
 * return NOTHING - not an error, no rows - so a report, an export, a
 * console query and a bug in a route all see the same empty set.
 *
 * Screen-level hiding fails in a way nobody tests: the figure is still
 * in the JSON the page fetched, in the CSV somebody exports, and in the
 * query a developer runs to answer a question. A salary is the one
 * number in a business that everybody wants and almost nobody should
 * have.
 *
 * ## A recurring salary is a schedule
 *
 * `salary_schedules` and `salary_components`, not forty copies of a row.
 * Changing a salary changes the next payment and leaves the history
 * alone, which is what makes "what did we pay them in March" answerable
 * - and a product that edited the rows would answer it with today's
 * figure.
 *
 * @financial
  *
 * @online-safe Creates `payroll_lines`, `payroll_runs`, `salary_components`, `salary_schedules`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | The visibility gate, as a function so the policies share one
         | definition.
         |
         | `true` as the second argument to `current_setting` means
         | "return NULL if unset" rather than raising - the migrator
         | connects without it, and a migration must not fail because
         | nobody has claimed payroll visibility.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_payroll_visible()
            RETURNS boolean
            LANGUAGE sql
            STABLE
            AS $fn$
                /*
                 | The migrator always sees, and that is not a hole.
                 |
                 | It runs DDL and data migrations and connects without
                 | any session setting, so a restrictive policy without
                 | this locks it out of the tables it just created - and
                 | the next migration that touches payroll fails for a
                 | reason that has nothing to do with payroll. Measured:
                 | an insert as the migrator was refused by this very
                 | policy before this clause existed.
                 |
                 | It is also not a way in for anybody: the migrator's
                 | credentials are not the application's, which is the
                 | separation `config/database.php` exists to keep.
                 |
                 | The entity policy grants the same bypass, for the same
                 | reason.
                 */
                SELECT current_user = 'peakflow_migrator'
                    OR coalesce(nullif(current_setting('peakflow.payroll_visible', true), ''), 'no') = 'yes';
            $fn$;

            COMMENT ON FUNCTION peakflow_payroll_visible() IS
                'Whether this session may see payroll figures at all - set only for a user holding payroll.view, and false for the migrator, a report, an export and a developer with a psql prompt alike - PF-256.';
        SQL);

        Schema::create('salary_schedules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('party_id', 26);

            $table->string('reference', 64);
            $table->char('currency', 3);

            /*
             | The pay day as a day of the month, and the frequency.
             |
             | Not an rrule: payroll is monthly or weekly in every
             | business this release serves, and the full recurrence
             | grammar would be a second implementation of PF-100's
             | schedules with none of its checks.
             */
            $table->string('frequency', 16)->default('monthly');
            $table->smallInteger('pay_day');

            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->string('state', 16)->default('draft');

            $table->ulid('created_by');
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'reference']);
            $table->index(['entity_id', 'party_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE salary_schedules
                ADD CONSTRAINT salary_schedules_state_is_known
                    CHECK (state IN ('draft', 'active', 'suspended', 'ended')),

                ADD CONSTRAINT salary_schedules_frequency_is_known
                    CHECK (frequency IN ('monthly', 'weekly', 'fortnightly')),

                /*
                 | A pay day that exists in every month.
                 |
                 | 1 to 28, or 0 meaning the last day. A schedule set to
                 | the 31st would skip February, and the employee would
                 | be paid eleven times in a year - which is the kind of
                 | error nobody finds until somebody counts.
                 */
                ADD CONSTRAINT salary_schedules_pay_day_exists_every_month
                    CHECK (pay_day BETWEEN 0 AND 28),

                ADD CONSTRAINT salary_schedules_dates_are_ordered
                    CHECK (ends_on IS NULL OR ends_on >= starts_on),

                ADD CONSTRAINT salary_schedules_approval_is_attributable
                    CHECK (
                        (approved_at IS NULL AND approved_by IS NULL)
                        OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)
                    ),

                /*
                 | The person who set the salary is not the person who
                 | approves it.
                 |
                 | The same control as a payment run's, for the same
                 | reason: somebody who can do both can give themselves a
                 | rise and pay it.
                 */
                ADD CONSTRAINT salary_schedules_setting_is_not_approving
                    CHECK (approved_by IS NULL OR approved_by <> created_by),

                ADD CONSTRAINT salary_schedules_active_is_approved
                    CHECK (state <> 'active' OR approved_at IS NOT NULL);
        SQL);

        Schema::create('salary_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('schedule_id', 26);

            $table->string('code', 32);
            $table->string('name_ar', 160);
            $table->string('name_en', 160);

            /*
             | Three kinds, and the kind decides where it posts.
             |
             | An EARNING is gross pay. A DEDUCTION comes off the
             | employee's pay and is owed to somebody else. An EMPLOYER
             | contribution is a cost on top that the employee never
             | sees.
             |
             | Stored rather than inferred from the sign, because a
             | negative earning and a deduction post to different
             | accounts and look identical as numbers.
             */
            $table->string('kind', 24);
            $table->string('liability_role', 40)->nullable();

            $table->decimal('amount', 21, 4);

            $table->timestamps();

            $table->unique(['schedule_id', 'code']);
            $table->index(['entity_id', 'schedule_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE salary_components
                ADD CONSTRAINT salary_components_kind_is_known
                    CHECK (kind IN ('earning', 'deduction', 'employer_contribution')),

                /*
                 | Everything is a positive figure; the KIND carries the
                 | direction.
                 |
                 | A deduction entered as a negative earning posts to the
                 | salary expense account and reduces it, which makes the
                 | salary cost wrong and the liability missing - and the
                 | two errors hide each other in the trial balance.
                 */
                ADD CONSTRAINT salary_components_amounts_are_positive
                    CHECK (amount > 0),

                /*
                 | A deduction and a contribution name the liability they
                 | create; an earning does not.
                 |
                 | Because money taken off somebody's pay is owed to
                 | somebody, and the account it is owed in is the thing
                 | that gets reconciled against a return. A deduction
                 | with nowhere to go is a figure that vanishes.
                 */
                ADD CONSTRAINT salary_components_deductions_name_a_liability
                    CHECK (
                        (kind = 'earning' AND liability_role IS NULL)
                        OR (kind <> 'earning' AND liability_role IN ('payroll_tax_payable', 'social_insurance_payable'))
                    );
        SQL);

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_on');
            $table->char('currency', 3);

            $table->string('state', 16)->default('draft');

            $table->decimal('gross_total', 21, 4)->default(0);
            $table->decimal('deduction_total', 21, 4)->default(0);
            $table->decimal('employer_total', 21, 4)->default(0);
            $table->decimal('net_total', 21, 4)->default(0);

            $table->ulid('prepared_by');
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->char('journal_entry_id', 26)->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payroll_runs
                ADD CONSTRAINT payroll_runs_state_is_known
                    CHECK (state IN ('draft', 'approved', 'posted', 'cancelled')),

                ADD CONSTRAINT payroll_runs_period_is_ordered
                    CHECK (period_end >= period_start),

                ADD CONSTRAINT payroll_runs_totals_are_not_negative
                    CHECK (
                        gross_total >= 0 AND deduction_total >= 0
                        AND employer_total >= 0 AND net_total >= 0
                    ),

                /*
                 | The net is the gross less the deductions, asserted.
                 |
                 | The employer's contributions are NOT in it: they are a
                 | cost on top, not a deduction from pay. A product that
                 | subtracted them would pay every employee too little
                 | by the employer's own share, which is the kind of
                 | error that reaches a labour tribunal.
                 */
                ADD CONSTRAINT payroll_runs_net_is_the_arithmetic
                    CHECK (net_total = gross_total - deduction_total),

                ADD CONSTRAINT payroll_runs_approval_is_attributable
                    CHECK (
                        (approved_at IS NULL AND approved_by IS NULL)
                        OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)
                    ),

                ADD CONSTRAINT payroll_runs_preparing_is_not_approving
                    CHECK (approved_by IS NULL OR approved_by <> prepared_by),

                ADD CONSTRAINT payroll_runs_posted_is_approved
                    CHECK (state <> 'posted' OR (approved_at IS NOT NULL AND journal_entry_id IS NOT NULL));
        SQL);

        Schema::create('payroll_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('run_id', 26);
            $table->char('party_id', 26);
            $table->char('schedule_id', 26)->nullable();

            $table->decimal('gross', 21, 4);
            $table->decimal('deductions', 21, 4)->default(0);
            $table->decimal('employer', 21, 4)->default(0);
            $table->decimal('net', 21, 4);

            /*
             | The components as they were, snapshotted.
             |
             | Because changing a salary changes the NEXT payment and
             | must not change what was paid - and "what did we pay them
             | in March" is answered from this column rather than from a
             | schedule that has moved three times since.
             */
            $table->jsonb('components');

            /*
             | The bill this line became, which is how it reaches the
             | payment run.
             */
            $table->char('bill_id', 26)->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'party_id']);
            $table->index(['entity_id', 'run_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payroll_lines
                ADD CONSTRAINT payroll_lines_amounts_are_not_negative
                    CHECK (gross >= 0 AND deductions >= 0 AND employer >= 0 AND net >= 0),

                ADD CONSTRAINT payroll_lines_net_is_the_arithmetic
                    CHECK (net = gross - deductions),

                /*
                 | Nobody is paid nothing.
                 |
                 | A zero net is either an unpaid month, which is a
                 | decision somebody has to record as one, or a
                 | miscalculation - and a run full of zero lines looks
                 | exactly like a run that worked.
                 */
                ADD CONSTRAINT payroll_lines_somebody_is_paid
                    CHECK (net > 0),

                ADD CONSTRAINT payroll_lines_deductions_fit_the_gross
                    CHECK (deductions <= gross);
        SQL);

        /*
         | Entity scope, then payroll visibility, on all four.
         |
         | Two policies rather than one, because they answer different
         | questions and PostgreSQL ANDs the restrictive ones: which
         | entity's rows are these, and may this session see payroll at
         | all.
         */
        foreach (['salary_schedules', 'salary_components', 'payroll_runs', 'payroll_lines'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));

            DB::unprepared(sprintf(<<<'SQL'
                DROP POLICY IF EXISTS peakflow_payroll_scope ON %1$s;

                CREATE POLICY peakflow_payroll_scope ON %1$s
                    AS RESTRICTIVE
                    USING (peakflow_payroll_visible())
                    WITH CHECK (peakflow_payroll_visible());

                COMMENT ON TABLE %1$s IS
                    'Payroll. Visible only to a session that has claimed payroll visibility, enforced at the row - a report, an export, a console query and a bug in a route all see the same empty set - PF-256.';
            SQL, $table));
        }

        /*
         | The migrator's bypass is in the FUNCTION, not a second policy.
         |
         | The first draft added `peakflow_payroll_migrator` as a
         | RESTRICTIVE policy scoped to that role - which does nothing,
         | because restrictive policies are ANDed and the one scoped to
         | `public` still applied to the migrator. An insert as the
         | migrator was refused by it, which is how this was found.
         |
         | A policy that cannot grant anything is worse than none: it
         | reads as a bypass and is not one.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('salary_schedules');

        DB::unprepared('DROP FUNCTION IF EXISTS peakflow_payroll_visible()');
    }
};
