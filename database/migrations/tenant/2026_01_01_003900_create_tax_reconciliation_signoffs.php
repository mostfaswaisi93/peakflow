<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A controller's sign-off on an unexplained tax difference - PF-088.
 *
 * @financial
 *
 * ## Why a sign-off exists at all
 *
 * Because the alternative is worse. A reconciliation that can only block makes
 * a period uncloseable for a difference of four riyals that nobody will ever
 * explain, and the response to that is somebody disabling the check - at which
 * point the real difference, next quarter, goes through unnoticed.
 *
 * So a controller can accept a difference, and the acceptance is a record: the
 * amount, the reason, who, when. It is evidence during an audit rather than an
 * absence of one.
 *
 * ## The amount is part of the sign-off
 *
 * A sign-off for "whatever the difference turns out to be" is a permanently
 * disabled check. This one covers an amount, and a difference larger than the
 * amount signed off blocks again - so a figure that drifts comes back rather
 * than hiding behind a decision made when it was small.
  *
 * @online-safe Creates `tax_reconciliation_signoffs`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_reconciliation_signoffs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->char('jurisdiction', 2);

            // The ACCOUNTING period, by label, because that is what closes -
            // and the return period it was reconciled against.
            $table->string('period_label', 32);
            $table->string('return_label', 16)->nullable();

            $table->char('currency', 3);
            $table->decimal('accepted_amount', 21, 4);

            $table->string('reason', 500);
            $table->char('signed_by', 26)->nullable();
            $table->timestampTz('signed_at');

            $table->timestamps();

            $table->index(['entity_id', 'jurisdiction', 'period_label']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE tax_reconciliation_signoffs
                ADD CONSTRAINT tax_reconciliation_signoffs_amount_is_not_zero
                    CHECK (accepted_amount <> 0),

                -- The same shape as PF-087's adjustment reason, and for the
                -- same reason: a single word is never an explanation.
                ADD CONSTRAINT tax_reconciliation_signoffs_reason_is_present
                    CHECK (length(btrim(reason)) >= 20 AND position(' ' in btrim(reason)) > 0);

            COMMENT ON COLUMN tax_reconciliation_signoffs.accepted_amount IS
                'The difference this sign-off covers. A larger one blocks again - PF-088.';

            /*
             | A sign-off is a fact, like a filing.
             |
             | Editing the amount afterwards would retroactively widen a
             | decision somebody made about a smaller figure, which is exactly
             | what the amount is here to prevent.
             */
            CREATE OR REPLACE FUNCTION refuse_to_change_a_signoff() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'A tax reconciliation sign-off cannot be % . Editing the amount would retroactively '
                    'widen a decision made about a smaller figure; sign off again if the difference has '
                    'changed, and the trail will show both.',
                    CASE WHEN TG_OP = 'DELETE' THEN 'deleted' ELSE 'changed' END
                    USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tax_reconciliation_signoffs_are_immutable
                BEFORE UPDATE OR DELETE ON tax_reconciliation_signoffs
                FOR EACH ROW
                EXECUTE FUNCTION refuse_to_change_a_signoff();
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('tax_reconciliation_signoffs');");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS tax_reconciliation_signoffs_are_immutable ON tax_reconciliation_signoffs;
            DROP FUNCTION IF EXISTS refuse_to_change_a_signoff();
        SQL);

        Schema::dropIfExists('tax_reconciliation_signoffs');
    }
};
