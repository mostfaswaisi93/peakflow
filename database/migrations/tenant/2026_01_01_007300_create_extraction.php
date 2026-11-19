<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extraction proposals and their accuracy - PF-142.
 *
 * ## A proposal is not a value
 *
 * The table is called `extraction_proposals` because that is what it holds.
 * An extracted figure is a machine's reading of a picture, and recording it
 * as the document's amount makes a guess indistinguishable from a fact two
 * screens later. So each field carries what was proposed, how confident the
 * engine was, and what a person corrected it to - and the bill is created
 * from the confirmed values, never from the proposed ones.
 *
 * ## Embedded XML carries NO confidence, and that is enforced
 *
 * A Factur-X PDF carries the EN 16931 XML as an embedded file: the figures
 * are *in* the document, exactly. There is nothing to be confident about,
 * and a confidence of 1.0 on an exact value would put "we are quite sure" on
 * something that was never in doubt - which is how a reviewer learns to
 * ignore the column.
 *
 * The constraint below refuses a confidence on an `embedded_xml` field and
 * requires one on an `ocr` field, so the two sources cannot be confused by
 * anything reading the table.
 *
 * ## Accuracy is per supplier template
 *
 * PF-142's fourth criterion: a consistently misread format has to be
 * visible. The counters live on the template rather than being computed from
 * the fields each time, because the question "which supplier's invoices do we
 * keep correcting" is asked of a list and answered from a sum - and the
 * fields of a confirmed proposal are frequently archived away.
 *
 * @financial-not-applicable: a proposal is a reading of a document, not a
 * posting. The bill it becomes is created through `Bills::enter()`, which is
 * where the financial row and its entity scope are made.
  *
 * @online-safe Creates `extraction_fields`, `extraction_proposals`, `extraction_templates`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraction_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('party_id')->nullable()->constrained('parties')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label', 160)->nullable();

            /*
             | Counters, not a computed rate.
             |
             | "Which supplier's invoices do we keep correcting" is asked of
             | a list and answered from a sum, and the fields of a confirmed
             | proposal are frequently archived away. A rate derived on read
             | would fall to zero as the detail is pruned, which reads as an
             | engine that improved.
             */
            $table->unsignedBigInteger('proposals')->default(0);
            $table->unsignedBigInteger('fields_proposed')->default(0);
            $table->unsignedBigInteger('fields_corrected')->default(0);
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'party_id', 'key']);
        });

        Schema::create('extraction_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('inbound_document_id')->constrained('inbound_documents')->cascadeOnDelete();
            $table->foreignUlid('extraction_template_id')->nullable()
                ->constrained('extraction_templates')->nullOnDelete();

            $table->string('source', 24);
            $table->string('status', 16)->default('proposed');

            /*
             | Which engine read it, and which version.
             |
             | An accuracy figure without them is a figure nobody can act
             | on: the engine changed in March and the rate moved, and
             | nothing records which of the two facts caused the other.
             */
            $table->string('engine', 64)->nullable();
            $table->string('engine_version', 32)->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->ulid('confirmed_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->ulid('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignUlid('bill_id')->nullable()->constrained('bills')->nullOnDelete();

            $table->text('note')->nullable();
            $table->timestamps();

            /*
             | One proposal per document.
             |
             | A second reading of the same document is a replacement rather
             | than a rival: two proposals would be two sets of figures with
             | no answer to which a person confirmed.
             */
            $table->unique('inbound_document_id');
            $table->index(['entity_id', 'status']);
        });

        Schema::create('extraction_fields', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('extraction_proposal_id')->constrained()->cascadeOnDelete();

            $table->string('field', 64);
            $table->text('proposed_value')->nullable();

            /*
             | Confidence, where confidence means something.
             |
             | Null for an exact source. A 1.0 on a value that was never in
             | doubt puts "we are quite sure" beside a fact, which is how a
             | reviewer learns to ignore the column.
             */
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('needs_review')->default(false);

            $table->text('corrected_value')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->ulid('corrected_by')->nullable();

            $table->timestamps();

            $table->unique(['extraction_proposal_id', 'field']);
            $table->index(['entity_id', 'field']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE extraction_proposals
                ADD CONSTRAINT extraction_proposals_source_is_known
                    CHECK (source IN ('embedded_xml', 'ocr')),

                ADD CONSTRAINT extraction_proposals_status_is_known
                    CHECK (status IN ('proposed', 'confirmed', 'rejected', 'unavailable')),

                /*
                 | No extracted bill without a person - PF-142.
                 |
                 | A proposal that names a bill has been confirmed by
                 | somebody, and the row says who and when. The point of an
                 | extraction pipeline is to save typing, not to remove the
                 | decision: a machine reading of a picture that posted
                 | itself is a payment nobody authorised.
                 */
                ADD CONSTRAINT extraction_proposals_a_bill_was_confirmed_by_somebody
                    CHECK (
                        bill_id IS NULL
                        OR (status = 'confirmed' AND confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL)
                    ),

                ADD CONSTRAINT extraction_proposals_confirmation_is_attributable
                    CHECK (
                        (confirmed_at IS NULL AND confirmed_by IS NULL)
                        OR (confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL)
                    ),

                ADD CONSTRAINT extraction_proposals_rejection_is_explained
                    CHECK (
                        status <> 'rejected'
                        OR (rejected_at IS NOT NULL AND rejected_by IS NOT NULL
                            AND length(btrim(coalesce(rejection_reason, ''))) > 5)
                    ),

                /*
                 | "Unavailable" says why, because it is not a failure of the
                 | document.
                 |
                 | No OCR engine is installed here, and a proposal recording
                 | that is more honest than one with no fields and no
                 | explanation - which reads as a document nothing could be
                 | read from.
                 */
                ADD CONSTRAINT extraction_proposals_unavailability_is_explained
                    CHECK (status <> 'unavailable' OR length(btrim(coalesce(note, ''))) > 10);

            ALTER TABLE extraction_fields
                ADD CONSTRAINT extraction_fields_confidence_is_a_proportion
                    CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1)),

                ADD CONSTRAINT extraction_fields_a_correction_is_attributable
                    CHECK (
                        (corrected_at IS NULL AND corrected_by IS NULL)
                        OR (corrected_at IS NOT NULL AND corrected_by IS NOT NULL)
                    );
        SQL);

        /*
         | A confidence belongs to a guess, and only to a guess.
         |
         | A trigger rather than a CHECK because the source is on the
         | proposal and the confidence is on the field, and a CHECK cannot
         | see across the two. Without it, an embedded-XML field could carry
         | a confidence and an OCR field could carry none - and anything
         | reading the table would have no way to tell an exact value from a
         | reading of a picture.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_confidence_belongs_to_a_guess()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                proposal RECORD;
            BEGIN
                SELECT source INTO proposal
                FROM extraction_proposals
                WHERE id = NEW.extraction_proposal_id;

                IF proposal IS NULL THEN
                    RETURN NEW;
                END IF;

                IF proposal.source = 'embedded_xml' AND NEW.confidence IS NOT NULL THEN
                    RAISE EXCEPTION
                        'A field read from embedded XML carries no confidence. The figures are in the '
                        'document exactly, and a confidence beside a fact is how a reviewer learns to '
                        'ignore the column.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF proposal.source = 'ocr' AND NEW.confidence IS NULL THEN
                    RAISE EXCEPTION
                        'A field read by OCR needs a confidence. A reading of a picture recorded as a '
                        'plain value is a guess nobody can tell from a fact two screens later.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER extraction_fields_confidence_matches_the_source
                BEFORE INSERT OR UPDATE ON extraction_fields
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_confidence_belongs_to_a_guess();
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE extraction_proposals IS
                'A proposal, not a value. An extracted figure is a machine reading of a picture - PF-142.';
            COMMENT ON CONSTRAINT extraction_proposals_a_bill_was_confirmed_by_somebody ON extraction_proposals IS
                'A machine reading that posted itself is a payment nobody authorised.';
        SQL);

        foreach (['extraction_templates', 'extraction_proposals', 'extraction_fields'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS extraction_fields_confidence_matches_the_source ON extraction_fields;
            DROP FUNCTION IF EXISTS peakflow_confidence_belongs_to_a_guess();
        SQL);

        Schema::dropIfExists('extraction_fields');
        Schema::dropIfExists('extraction_proposals');
        Schema::dropIfExists('extraction_templates');
    }
};
