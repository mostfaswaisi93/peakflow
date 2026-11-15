<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dunning policies, what they did, and who is chasing - PF-108, PF-109.
 *
 * @financial
 *
 * ## Evaluated per invoice, sent per customer
 *
 * Which is the whole shape of the thing. A customer with six overdue
 * invoices gets ONE statement listing six, not six emails - and the person
 * who receives six emails from a supplier in one morning telephones about
 * the emails rather than about the money.
 *
 * So `dunning_events` is per invoice per step (that is where the
 * idempotency lives) and `dunning_notices` is per customer per run (that is
 * what gets sent). The events point at the notice that carried them.
 *
 * ## Idempotent on `(invoice, step, day)`
 *
 * A unique index, for the same reason as the recurring occurrences: cron
 * fires twice, somebody runs it by hand, a retry lands after a timeout. The
 * second writer loses, and a customer does not get the same reminder twice
 * in a morning.
 *
 * The day is part of the key rather than the whole key, because a policy may
 * legitimately have two steps due on one day for different invoices - and
 * because a step that ran yesterday must not be re-sent today, which is what
 * the step half prevents.
 */
return new class extends Migration
{
    private const CHANNELS = ['email', 'statement', 'phone', 'letter', 'none'];

    private const ACTIONS = ['late_fee', 'credit_hold', 'to_collections', 'escalate_owner'];

    public function up(): void
    {
        Schema::create('dunning_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('code', 32);
            $table->string('name_ar', 120);
            $table->string('name_en', 120);

            /*
             | Who it applies to.
             |
             | A policy for one party group - "distributors" - and a tenant
             | default, which is what a party with no policy of its own
             | gets. The per-party assignment is its own table, because a
             | party is tenant-wide and a policy is per entity.
             */
            $table->string('party_group', 64)->nullable();
            $table->boolean('is_default')->default(false);

            $table->boolean('active')->default(true);
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'active']);
        });

        Schema::create('dunning_policy_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('dunning_policy_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');

            /*
             | Days PAST DUE, which may be negative.
             |
             | A step at -3 is the courtesy reminder before the due date,
             | and it is the single most effective step in most policies:
             | the invoice nobody has seen is the invoice nobody has paid.
             */
            $table->smallInteger('days_past_due');

            $table->string('channel', 16)->default('email');
            $table->string('template', 64)->nullable();

            // What it does besides sending: a late fee, a credit hold, a
            // handover to collections.
            $table->jsonb('actions')->default(DB::raw("'[]'::jsonb"));

            $table->decimal('late_fee_percent', 9, 6)->nullable();
            $table->decimal('late_fee_minimum', 21, 4)->nullable();

            $table->timestamps();

            $table->unique(['dunning_policy_id', 'sequence']);
            $table->index(['entity_id', 'days_past_due']);
        });

        Schema::create('party_dunning_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('dunning_policy_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // One policy per party per entity: two would be two answers to
            // "what happens when this customer is late".
            $table->unique(['entity_id', 'party_id']);
        });

        Schema::create('dunning_notices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();

            $table->date('notice_date');
            $table->string('channel', 16);
            $table->unsignedSmallInteger('highest_sequence');
            $table->unsignedSmallInteger('invoice_count');
            $table->char('currency', 3);
            $table->decimal('total_due', 21, 4);

            $table->timestampTz('sent_at')->nullable();
            $table->text('failure')->nullable();

            $table->timestamps();

            /*
             | One notice per customer per day.
             |
             | A customer with six overdue invoices gets one statement, and
             | a second run on the same day finds this row and adds nothing.
             */
            $table->unique(['entity_id', 'party_id', 'notice_date']);
            $table->index(['entity_id', 'notice_date']);
        });

        Schema::create('dunning_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('dunning_policy_step_id')->constrained()->cascadeOnDelete();
            $table->ulid('dunning_notice_id')->nullable();
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();

            $table->date('occurred_on');
            $table->unsignedSmallInteger('days_past_due');
            $table->decimal('amount_due', 21, 4);
            $table->char('currency', 3);

            // What the step did: the actions it took, and anything it
            // produced - a late fee document, a hold.
            $table->jsonb('actions_taken')->default(DB::raw("'[]'::jsonb"));
            $table->ulid('fee_invoice_id')->nullable();

            $table->timestamps();

            /*
             | THE idempotency - PF-108.
             |
             | One event per invoice per step per day. Cron fires twice,
             | somebody runs it by hand, a retry lands after a timeout: the
             | second writer loses and the customer is not chased twice in a
             | morning.
             */
            $table->unique(['invoice_id', 'dunning_policy_step_id', 'occurred_on']);
            $table->index(['entity_id', 'occurred_on']);
            $table->index(['entity_id', 'party_id', 'occurred_on']);
        });

        Schema::create('party_collections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();

            // Somebody owns the conversation. A worklist with no owner is a
            // list nobody works.
            $table->ulid('owner_user_id')->nullable();

            $table->date('promised_on')->nullable();
            $table->decimal('promised_amount', 21, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('promise_note')->nullable();

            $table->timestampTz('in_collections_since')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'party_id']);
            $table->index(['entity_id', 'promised_on']);
        });

        $channels = "'".implode("', '", self::CHANNELS)."'";

        DB::unprepared(<<<SQL
            ALTER TABLE dunning_policy_steps
                ADD CONSTRAINT dunning_policy_steps_channel_is_known
                    CHECK (channel IN ({$channels})),

                /*
                 | A late fee needs a rate or a minimum.
                 |
                 | A step that says "charge a late fee" and carries neither
                 | charges nothing, which is the worst of the three
                 | outcomes: the customer is told a fee applies and no fee
                 | arrives.
                 */
                ADD CONSTRAINT dunning_policy_steps_a_fee_has_a_figure
                    CHECK (
                        NOT (actions @> '["late_fee"]'::jsonb)
                        OR late_fee_percent IS NOT NULL
                        OR late_fee_minimum IS NOT NULL
                    ),

                ADD CONSTRAINT dunning_policy_steps_fee_is_not_negative
                    CHECK (coalesce(late_fee_percent, 0) >= 0 AND coalesce(late_fee_minimum, 0) >= 0);

            ALTER TABLE dunning_notices
                ADD CONSTRAINT dunning_notices_channel_is_known
                    CHECK (channel IN ({$channels})),

                ADD CONSTRAINT dunning_notices_lists_something
                    CHECK (invoice_count > 0);

            /*
             | One default policy per entity.
             |
             | Two defaults is two answers to "what happens to a customer
             | with no policy of their own", and the one that wins would be
             | whichever the query happened to order first.
             */
            CREATE UNIQUE INDEX dunning_policies_one_default
                ON dunning_policies (entity_id)
                WHERE is_default AND active;

            CREATE UNIQUE INDEX dunning_policies_one_per_group
                ON dunning_policies (entity_id, party_group)
                WHERE party_group IS NOT NULL AND active;

            SELECT peakflow_apply_entity_rls('dunning_policies');
            SELECT peakflow_apply_entity_rls('dunning_policy_steps');
            SELECT peakflow_apply_entity_rls('party_dunning_policies');
            SELECT peakflow_apply_entity_rls('dunning_notices');
            SELECT peakflow_apply_entity_rls('dunning_events');
            SELECT peakflow_apply_entity_rls('party_collections');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('party_collections');
        Schema::dropIfExists('dunning_events');
        Schema::dropIfExists('dunning_notices');
        Schema::dropIfExists('party_dunning_policies');
        Schema::dropIfExists('dunning_policy_steps');
        Schema::dropIfExists('dunning_policies');
    }
};
