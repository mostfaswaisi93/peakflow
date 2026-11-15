<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring schedules, and the occurrences they have produced - PF-105, PF-106.
 *
 * @financial
 *
 * ## The idempotency is a unique index, and nothing else
 *
 * `(schedule_id, occurrence_date)` is unique. A scheduler that fires twice, a
 * second node that fires the same minute, a retry after a timeout, somebody
 * running the command by hand while cron runs it - every one of those ends at
 * the same index, and the second writer loses.
 *
 * A lock makes the duplicate work cheap to avoid; the index is what makes
 * double-billing impossible. The difference matters because a lock is a
 * service that can be down, misconfigured or shared with a second deployment,
 * and the guarantee has to survive all three.
 *
 * ## Why the occurrence row is written BEFORE the invoice
 *
 * So the claim exists before the work does. A generator that drafted the
 * invoice first and then recorded the occurrence would, interrupted between
 * the two, produce an invoice nobody has a record of asking for - and the
 * next run would produce another.
 *
 * ## The timezone is the tenant's, and it is on the schedule
 *
 * "Daily at 01:00" means 01:00 where the business is. A tenant in Riyadh and
 * one in Casablanca do not share a moment, and a generator that used the
 * server's clock would bill one of them on the wrong date - for a week each
 * March and October, by one day, which is the hardest kind of wrong to
 * notice.
 */
return new class extends Migration
{
    private const STATES = ['draft', 'active', 'paused', 'ended'];

    private const OCCURRENCE_STATES = ['claimed', 'generated', 'failed', 'skipped'];

    private const PRORATION = ['none', 'by_day', 'by_month'];

    public function up(): void
    {
        Schema::create('recurring_schedules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('reference', 64);
            $table->foreignUlid('party_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');

            // The rule, as text. Parsed on every read rather than exploded
            // into columns: `FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1`
            // in one place is a rule somebody can read and change, and six
            // columns holding its parts is a rule nobody can.
            $table->string('rrule', 255);
            $table->date('starts_on');

            /*
             | The end condition: a date, a count, or neither.
             |
             | Both are nullable and both may be in the rule itself (UNTIL,
             | COUNT). These columns are what the schedule screen edits;
             | `RecurringSchedules` keeps them and the rule consistent, and a
             | CHECK refuses the pair being set at once for the same reason
             | RFC 5545 does.
             */
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('occurrence_limit')->nullable();

            // Where the business is. "Daily at 01:00" means 01:00 here.
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedTinyInteger('generate_at_hour')->default(1);

            $table->char('currency', 3);
            $table->string('proration', 16)->default('none');
            $table->ulid('price_list_id')->nullable();
            $table->string('profile', 32)->nullable();
            $table->text('terms')->nullable();
            $table->text('note')->nullable();

            // How far ahead to draft: a subscription invoiced on the 1st for
            // the month ahead is drafted a few days before, so somebody can
            // look at it.
            $table->unsignedTinyInteger('lead_days')->default(0);

            $table->boolean('issue_automatically')->default(false);

            $table->date('last_occurrence_on')->nullable();
            $table->unsignedSmallInteger('occurrences_generated')->default(0);
            $table->timestampTz('ended_at')->nullable();
            $table->text('ended_reason')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'reference']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('recurring_schedule_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('recurring_schedule_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_no');
            $table->ulid('item_id')->nullable();
            $table->string('item_code', 64)->nullable();
            $table->text('description');
            $table->char('unit_code', 8)->default('C62');
            $table->decimal('quantity', 21, 8);

            /*
             | A price, or null to resolve one at generation time.
             |
             | Null is the interesting case: the schedule then picks up a
             | price change on the item by effective date, which is what a
             | contract tied to a price list means. A fixed price on the
             | schedule is what a contract with a fixed price means. Both
             | exist, and which one this is has to be visible.
             */
            $table->decimal('unit_price', 21, 8)->nullable();

            $table->ulid('revenue_account_id')->nullable();
            $table->ulid('tax_code_id')->nullable();
            $table->string('tax_category', 3)->nullable();
            $table->decimal('tax_rate', 9, 6)->nullable();

            $table->date('service_from')->nullable();
            $table->date('service_until')->nullable();

            $table->timestamps();

            $table->unique(['recurring_schedule_id', 'line_no']);
        });

        Schema::create('recurring_occurrences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('recurring_schedule_id')->constrained()->cascadeOnDelete();

            $table->date('occurrence_date');
            $table->string('status', 16)->default('claimed');

            $table->ulid('invoice_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('failed_at')->nullable();

            $table->timestamps();

            /*
             | THE guarantee - PF-106.
             |
             | One row per schedule per occurrence date, whoever writes it.
             | Every duplicate-billing scenario ends here: a scheduler that
             | fires twice, two nodes in the same minute, a retry after a
             | timeout, a person running the command while cron runs it.
             */
            $table->unique(['recurring_schedule_id', 'occurrence_date']);
            $table->index(['entity_id', 'status']);
        });

        $states = "'".implode("', '", self::STATES)."'";
        $occurrenceStates = "'".implode("', '", self::OCCURRENCE_STATES)."'";
        $proration = "'".implode("', '", self::PRORATION)."'";

        DB::unprepared(<<<SQL
            ALTER TABLE recurring_schedules
                ADD CONSTRAINT recurring_schedules_status_is_known
                    CHECK (status IN ({$states})),

                ADD CONSTRAINT recurring_schedules_proration_is_known
                    CHECK (proration IN ({$proration})),

                -- The same rule RFC 5545 has, for the same reason: the two
                -- answer one question differently and nothing says which wins.
                ADD CONSTRAINT recurring_schedules_one_end_condition
                    CHECK (ends_on IS NULL OR occurrence_limit IS NULL),

                ADD CONSTRAINT recurring_schedules_ends_after_it_starts
                    CHECK (ends_on IS NULL OR ends_on >= starts_on),

                ADD CONSTRAINT recurring_schedules_generate_at_a_real_hour
                    CHECK (generate_at_hour BETWEEN 0 AND 23),

                ADD CONSTRAINT recurring_schedules_ended_is_explained
                    CHECK ((status = 'ended') = (ended_at IS NOT NULL));

            ALTER TABLE recurring_schedule_lines
                ADD CONSTRAINT recurring_schedule_lines_quantity_is_positive
                    CHECK (quantity > 0),

                ADD CONSTRAINT recurring_schedule_lines_description_is_not_blank
                    CHECK (length(btrim(description)) > 0);

            ALTER TABLE recurring_occurrences
                ADD CONSTRAINT recurring_occurrences_status_is_known
                    CHECK (status IN ({$occurrenceStates})),

                /*
                 | A generated occurrence has an invoice, and a claimed one
                 | does not yet.
                 |
                 | Both halves: an occurrence marked generated with no
                 | invoice is a bill nobody can find, and an invoice on a
                 | claimed row is work the next run would do again.
                 */
                ADD CONSTRAINT recurring_occurrences_generated_has_an_invoice
                    CHECK ((status = 'generated') = (invoice_id IS NOT NULL AND generated_at IS NOT NULL)),

                ADD CONSTRAINT recurring_occurrences_failure_is_explained
                    CHECK (status <> 'failed' OR (failed_at IS NOT NULL AND length(btrim(coalesce(last_error, ''))) > 0));

            SELECT peakflow_apply_entity_rls('recurring_schedules');
            SELECT peakflow_apply_entity_rls('recurring_schedule_lines');
            SELECT peakflow_apply_entity_rls('recurring_occurrences');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_occurrences');
        Schema::dropIfExists('recurring_schedule_lines');
        Schema::dropIfExists('recurring_schedules');
    }
};
