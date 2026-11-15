<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segregation of duties - PF-033, PF-034.
 *
 * ## A permission and an SoD rule answer different questions
 *
 * A permission answers *may this user do X*. An SoD rule answers *may the same
 * user do X and then Y to the same object*. Conflating them is the classic
 * hole in financial software: a clerk who may both create a supplier and
 * release a payment can pay themselves, and every individual permission check
 * passes.
 *
 * So the rules are DATA, evaluated at the moment of the second act, against
 * the audit trail of the first. There is no way to answer them from a role
 * list, because the answer depends on history.
 *
 * ## A refusal explains itself
 *
 * "Not permitted" is useless here. The message names the conflicting act, who
 * performed it and when, so the user knows who to ask rather than guessing -
 * and so a genuine process problem becomes visible instead of being worked
 * around.
 *
 * @online-safe Creates new tables only.
 */
return new class extends Migration
{
    /*
     | No getConnection() override, deliberately.
     |
     | Returning 'tenant' here would OVERRIDE the --database option and force
     | the application's own connection, which has no DDL privileges at all.
     | `tenants:migrate` runs with a migrator-credentialed clone of the tenant
     | connection and sets it as the default, so the plain facades below reach
     | the right database with the right privileges.
     |
     | That is the division PF-010 exists to create: the application can read
     | and write rows and can never change the schema.
     */
    public function up(): void
    {
        Schema::create('sod_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();

            // The incompatible pair, as permission keys.
            $table->string('duty_a');
            $table->string('duty_b');

            // What the two acts must share before the rule bites:
            //   `object`  the same document - a bill created and approved
            //   `party`   the same counterparty - a supplier created, then paid
            //   `period`  the same accounting period - posting, then closing
            //   `any`     any two acts by the same user at all
            $table->string('scope', 16)->default('object');

            $table->text('rationale_ar');
            $table->text('rationale_en');

            // Whether a tenant with too few staff may record a documented
            // override. The beneficiary-change-against-payment-release pair
            // CANNOT be overridden at any size of business, because it is the
            // highest-value fraud in the product.
            $table->boolean('overridable')->default(true);

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['duty_a', 'duty_b']);
        });

        Schema::create('sod_overrides', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sod_rule_id')->constrained()->cascadeOnDelete();
            $table->ulid('entity_id');
            $table->foreignUlid('user_id')->constrained();

            // A tenant with three staff may genuinely be unable to separate
            // two duties. Silently allowing it and silently blocking it are
            // BOTH wrong: the override is explicit, reasoned, approved by
            // someone else, expiring, and reported.
            $table->text('reason');
            $table->foreignUlid('approved_by_user_id')->constrained('users');
            $table->timestamp('approved_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'user_id', 'expires_at']);
        });

        Schema::create('sod_refusals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sod_rule_id')->constrained()->cascadeOnDelete();
            $table->ulid('entity_id');
            $table->foreignUlid('user_id')->constrained();

            // What they tried, and what blocked it. Logged even though it was
            // refused: a pattern of refusals is either a process that needs
            // fixing or somebody probing, and both are worth seeing.
            $table->string('attempted_duty');
            $table->string('conflicting_duty');
            $table->ulid('conflicting_audit_id')->nullable();
            $table->ulid('subject_id')->nullable();
            $table->string('subject_type', 64)->nullable();

            $table->timestamp('created_at')->index();

            $table->index(['entity_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('step_up_assertions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id');

            // What this assertion authorized. Recorded so that an assertion
            // obtained for one act cannot be silently reused for another -
            // which would make step-up decorative.
            $table->string('authorized_for');
            $table->ulid('subject_id')->nullable();

            $table->string('factor_type', 16);
            $table->timestamp('asserted_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->index(['user_id', 'authorized_for', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('step_up_assertions');
        Schema::dropIfExists('sod_refusals');
        Schema::dropIfExists('sod_overrides');
        Schema::dropIfExists('sod_rules');
    }
};
