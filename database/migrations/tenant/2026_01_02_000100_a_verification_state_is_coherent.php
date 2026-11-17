<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A bank account is verified, or it needs verifying. Never both, never neither.
 *
 * ## Found by trying to break the payment run and failing
 *
 * `PaymentRuns::beneficiaryFor()` was written to exclude an account on two
 * conditions - `requires_reverification` false and `verified_at` not null -
 * and a bite sweep showed that removing either one changed nothing. Two
 * guards where one would do, which is the shape of a condition nobody can
 * ever exercise.
 *
 * The reason is that the two columns are already kept in lockstep by
 * everything that writes them: `BankDetailRegistry::record()` sets the flag
 * and clears the timestamp, `verify()` does the reverse, and the
 * `party_bank_accounts_reverify` trigger clears the timestamp whenever it
 * raises the flag.
 *
 * So the pair is an invariant - and an invariant that holds by the habits
 * of three writers is one a hand-repaired row breaks. Somebody clearing
 * `requires_reverification` to get a payment out of the door leaves an
 * account that claims to need no verification and was never verified, and
 * every reader then has to decide which column to believe.
 *
 * This states it once, at the table, so there is one answer. The payment
 * run's second condition then came out, because a guard against a state
 * the database refuses is a guard nobody can test.
 *
 * @financial-not-applicable: a coherence constraint on two existing flags; no amount, balance or entity scope is involved
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE party_bank_accounts
                ADD CONSTRAINT party_bank_accounts_verification_state_is_coherent
                    CHECK (requires_reverification = (verified_at IS NULL));

            COMMENT ON CONSTRAINT party_bank_accounts_verification_state_is_coherent
                ON party_bank_accounts IS
                'Verified means a timestamp and no flag; needing verification means a flag and no timestamp. Stated here so a hand-repaired row cannot leave two readers believing different columns - PF-174.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE party_bank_accounts
                DROP CONSTRAINT IF EXISTS party_bank_accounts_verification_state_is_coherent;
        SQL);
    }
};
