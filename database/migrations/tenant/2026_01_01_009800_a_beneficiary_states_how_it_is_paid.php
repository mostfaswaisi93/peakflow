<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a beneficiary wants to be paid - PF-174.
 *
 * ## Grouping is the beneficiary's call, not ours
 *
 * Four approved bills to one supplier normally become one transfer: four
 * transfers cost four fees and arrive as four lines the supplier has to
 * match against four invoices.
 *
 * But some suppliers reconcile per invoice and cannot match a lump sum to
 * anything - a factored receivable, a franchise accounting per branch, a
 * utility whose system keys on the account rather than the payer. For
 * them one transfer is worse than four, and the only party who knows
 * which kind they are is the supplier.
 *
 * So it is a stored preference with grouping as the default, beside
 * `credit_limit` - which is the same shape of fact on the receivable
 * side: what this counterparty's relationship with this entity permits.
 *
 * ## A limit is a fact about the rails, and splitting obeys it
 *
 * A beneficiary's bank, scheme or own mandate may cap a single credit.
 * Above it the transfer is rejected, and a rejected file is worse than
 * two transfers: the bank returns it and the supplier is paid nothing,
 * which is discovered when they chase.
 *
 * Null is the ordinary case - no cap anybody has told us about. A cap is
 * recorded only when somebody knows one, because a guessed limit splits
 * payments for no reason and every split is a line the supplier has to
 * reconcile.
 *
 * @financial-not-applicable: this alters `party_entity_settings`, which already
 * carries `entity_id` and a forced row-level security policy from the migration
 * that created it. The tag's demands - declare the column, call
 * `peakflow_apply_entity_rls()` - would add a second column and a second policy
 * to a table that has both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_entity_settings', function (Blueprint $table): void {
            /*
             | Default true, because grouping is right for most suppliers.
             |
             | Not nullable with null meaning "unknown": a payment run has
             | to decide, and a three-valued preference pushes the decision
             | into whichever caller reads it - which is how two callers
             | come to read it differently.
             */
            $table->boolean('group_payments')->default(true);

            $table->decimal('payment_limit', 21, 4)->nullable();
            $table->text('payment_limit_basis')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE party_entity_settings
                ADD CONSTRAINT party_entity_settings_payment_limit_is_positive
                    CHECK (payment_limit IS NULL OR payment_limit > 0),

                /*
                 | A limit somebody recorded has a reason recorded with it.
                 |
                 | "Their bank caps a single credit at 50,000" is a fact
                 | somebody can confirm with the supplier. A bare number is
                 | a figure whose origin is lost the week after it was
                 | typed, and the person who finds payments being split
                 | has no way to ask whether the cap is still true.
                 */
                ADD CONSTRAINT party_entity_settings_a_limit_states_its_basis
                    CHECK (payment_limit IS NULL
                           OR (payment_limit_basis IS NOT NULL
                               AND length(btrim(payment_limit_basis)) >= 20));

            COMMENT ON COLUMN party_entity_settings.group_payments IS
                'Whether several bills to this supplier may be paid as one transfer - PF-174. Default true: four transfers cost four fees. Suppliers who reconcile per invoice say otherwise.';

            COMMENT ON COLUMN party_entity_settings.payment_limit IS
                'Largest single credit this beneficiary can receive, where somebody knows of one - PF-174. A payment run splits above it, because a rejected file pays the supplier nothing.';
        SQL);
    }

    public function down(): void
    {
        Schema::table('party_entity_settings', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback path of a forward-only migration. These three columns are added by this migration's own up(), so dropping them restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn(['group_payments', 'payment_limit', 'payment_limit_basis']);
        });
    }
};
