<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A bank line can be a payment run - PF-156, pass 2.
 *
 * ## Two new things a debit can be
 *
 * `payment_run` - the bank debited the whole batch as one line.
 * `payment_run_instruction` - the bank debited each transfer separately.
 *
 * Both are what the same payment file produces at different banks, so an
 * engine that could only record one would leave every statement from the
 * other bank to be matched by hand.
 *
 * ## Why these are not `payment`
 *
 * `payment` already exists in the list and means a `payments` row - a
 * receipt or a disbursement recorded in Settlement. A payment run is not
 * one of those: it is an instruction that was sent, and the payments it
 * gives rise to are PF-176's business. Folding the two together would
 * make "which payment is this" a question with two kinds of answer and
 * one column to tell them apart by.
 *
 * @financial-not-applicable: widens an existing type vocabulary; no amount, balance or entity scope is involved
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE matches
                DROP CONSTRAINT matches_type_is_known;

            ALTER TABLE matches
                ADD CONSTRAINT matches_type_is_known
                    CHECK (matched_type IN (
                        'invoice',
                        'bill',
                        'payment',
                        'payment_run',
                        'payment_run_instruction',
                        'journal_entry',
                        'write_off',
                        'transfer',
                        'unidentified'
                    ));

            COMMENT ON CONSTRAINT matches_type_is_known ON matches IS
                'What a bank line can be. A payment run and one of its instructions are distinct from a payments row: the run is an instruction that was sent, not cash that was recorded - PF-156.';
        SQL);

        /*
         | And the proposals, which carry the same vocabulary.
         |
         | A pass that could propose a type the proposal table refuses
         | would fail at the moment of writing, in a queued job, with the
         | transaction already consumed - which is the worst place for a
         | vocabulary to disagree with itself.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE match_proposals
                DROP CONSTRAINT IF EXISTS match_proposals_type_is_known;

            ALTER TABLE match_proposals
                ADD CONSTRAINT match_proposals_type_is_known
                    CHECK (matched_type IN (
                        'invoice',
                        'bill',
                        'payment',
                        'payment_run',
                        'payment_run_instruction',
                        'journal_entry',
                        'write_off',
                        'transfer',
                        'unidentified'
                    ));
        SQL);
    }

    public function down(): void
    {
        /*
         | Narrowing back would refuse rows this release can create, so it
         | is left undone. A wider vocabulary is harmless; reverting it
         | breaks at a moment nobody chose.
         */
    }
};
