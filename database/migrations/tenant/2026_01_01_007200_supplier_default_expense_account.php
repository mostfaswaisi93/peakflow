<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier's default expense account - PF-141.
 *
 * ## Why an inbound bill needs one
 *
 * EN 16931 has no concept of an expense account, and it should not: which
 * account a purchase lands in is our decision about our own chart, not
 * something a supplier can state. So a document that arrives with every
 * business term populated still has nothing to say about where it posts.
 *
 * The alternatives are both worse than a configured default. Guessing from
 * the item description is a guess, and PF-141 says nothing is guessed.
 * Leaving every inbound bill without an account means every one of them
 * waits for a person to code it, which is most of the work the automation was
 * supposed to remove - and the person doing it picks the same account they
 * picked last month, from memory, which is a default with no record.
 *
 * So the tenant records it once, per supplier, and the draft carries it.
 * Where it is not set the draft has no account and cannot post, which is the
 * honest state: `BillPostedRule` refuses a line with no account rather than
 * choosing one, because letting a posting rule choose is how a purchase ends
 * up in a suspense account nobody reconciles.
 *
 * @financial-not-applicable: this adds a nullable master-data column to
 * `parties`, which carries no `entity_id` of its own - a party is shared
 * across the tenant's entities by design, and the account it names is
 * entity-scoped through `accounts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->ulid('default_expense_account_id')->nullable();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN parties.default_expense_account_id IS
                'Where this supplier''s purchases usually post. EN 16931 has no term for it - the chart is ours, not theirs - PF-141.';
        SQL);
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a nullable column
            // added in this same migration, so nothing deployed before it
            // reads it.
            $table->dropColumn('default_expense_account_id');
        });
    }
};
