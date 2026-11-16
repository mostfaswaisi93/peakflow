<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents that arrive from outside - PF-140, PF-141.
 *
 * ## An inbox item is not a bill
 *
 * Which is the whole design. A document arrives by email or by Peppol, from
 * somebody who may not be a supplier, in a format that may not be valid, and
 * it becomes a row **here** first. Turning it straight into a bill means a
 * stranger's attachment creates a payable, and the worst version of that is
 * an invoice from a supplier who does not exist for goods nobody ordered.
 *
 * So: received, then validated, then matched to a supplier, and only then
 * converted - with every step recorded and every failure keeping the
 * original.
 *
 * ## Nothing is guessed
 *
 * `supplier_party_id` is null until something matched it, and `match_method`
 * says what did. A name match is deliberately not available: two suppliers
 * called "Gulf Trading" are two suppliers, and a bill paid to the wrong one
 * of them is money gone somewhere it was never owed.
 *
 * ## The original is kept, as received
 *
 * `archive_attachment_id` and `content_hash` hold the bytes that arrived and
 * their hash. An email is frequently the only evidence of what was agreed,
 * and an XML document is the only evidence of what the supplier actually
 * sent - as opposed to what our parser made of it.
 *
 * @financial-not-applicable: an inbox item is a received document, not a
 * posting. It becomes a bill through `Bills::enter()`, which is where the
 * financial row and its entity scope are created. The RLS policy below
 * applies because the rows are tenant data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_senders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | The allowlist, per supplier - PF-140.
             |
             | An address rather than a domain, because a shared mailbox at
             | a large supplier sends from one address and their marketing
             | sends from another at the same domain. A domain allowlist is
             | the broader grant and nobody revisits it.
             */
            $table->string('channel', 16);
            $table->string('address', 320);
            $table->foreignUlid('party_id')->nullable()->constrained('parties')->cascadeOnDelete();

            $table->boolean('active')->default(true);
            $table->text('note')->nullable();
            $table->ulid('added_by')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'channel', 'address']);
        });

        Schema::create('inbound_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('channel', 16);
            $table->timestamp('received_at');
            $table->string('status', 24)->default('received');

            /*
             | Who sent it, as they identified themselves.
             |
             | Kept separately from the supplier we matched, because they
             | disagree more often than anybody expects: an agent sends on a
             | supplier's behalf, a group company sends for a subsidiary, a
             | finance system sends from a no-reply address. Overwriting one
             | with the other loses the only evidence of which it was.
             */
            $table->string('sender_address', 320)->nullable();
            $table->string('sender_electronic_address', 128)->nullable();
            $table->string('sender_scheme', 16)->nullable();
            $table->text('subject')->nullable();

            $table->string('document_number', 64)->nullable();
            $table->string('document_type', 8)->nullable();
            $table->date('document_date')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('gross_amount', 21, 4)->nullable();

            $table->foreignUlid('supplier_party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('match_method', 24)->nullable();
            $table->text('match_detail')->nullable();

            /*
             | The validation report, as a document rather than a verdict.
             |
             | "Rejected" tells the sender nothing. A supplier whose
             | document failed BR-CO-14 needs to know which rule and where,
             | and so does whoever answers their email about it.
             */
            $table->jsonb('validation_report')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('response_code', 8)->nullable();

            $table->ulid('archive_attachment_id')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('content_type', 128)->nullable();
            $table->unsignedBigInteger('content_bytes')->nullable();

            $table->foreignUlid('bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->ulid('converted_by')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'status', 'received_at']);
            $table->index(['entity_id', 'sender_electronic_address']);

            /*
             | The same document twice is one row.
             |
             | A Peppol access point retries, an email is forwarded twice,
             | an import is re-run. The hash of what arrived is what
             | identifies it, and without this the second delivery becomes a
             | second draft bill - which is the duplicate-payment error
             | arriving through a door the duplicate detector does not watch.
             */
            $table->unique(['entity_id', 'content_hash']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE inbound_senders
                ADD CONSTRAINT inbound_senders_channel_is_known
                    CHECK (channel IN ('email', 'peppol', 'upload')),

                ADD CONSTRAINT inbound_senders_address_is_present
                    CHECK (length(btrim(address)) > 2);

            ALTER TABLE inbound_documents
                ADD CONSTRAINT inbound_documents_channel_is_known
                    CHECK (channel IN ('email', 'peppol', 'upload')),

                ADD CONSTRAINT inbound_documents_status_is_known
                    CHECK (status IN ('received', 'quarantined', 'rejected', 'validated',
                                      'awaiting_supplier', 'converted', 'discarded')),

                /*
                 | A rejection says which rule, not that there was one.
                 |
                 | The supplier has to fix something, and "invalid" is not
                 | an instruction. The report is the document; this is the
                 | sentence that goes in the response.
                 */
                ADD CONSTRAINT inbound_documents_rejection_is_explained
                    CHECK (
                        status <> 'rejected'
                        OR (length(btrim(coalesce(rejection_reason, ''))) > 10
                            AND validation_report IS NOT NULL)
                    ),

                /*
                 | A match names its method, and the method is never a name.
                 |
                 | Two suppliers called "Gulf Trading" are two suppliers,
                 | and a bill paid to the wrong one is money gone somewhere
                 | it was never owed. So the column enumerates what IS
                 | allowed, which is how the absence of a name match stays
                 | absent.
                 */
                ADD CONSTRAINT inbound_documents_match_method_is_known
                    CHECK (
                        match_method IS NULL
                        OR match_method IN ('electronic_address', 'tax_registration',
                                            'sender_allowlist', 'human')
                    ),

                ADD CONSTRAINT inbound_documents_a_match_names_a_supplier
                    CHECK ((match_method IS NULL) = (supplier_party_id IS NULL)),

                /*
                 | A converted document names its bill, and had a supplier.
                 |
                 | A bill with no inbox item behind it is ordinary - somebody
                 | typed it - but an inbox item that says it became a bill
                 | and names none is a document nobody can trace, and the
                 | question "did we pay this twice" has no answer.
                 */
                ADD CONSTRAINT inbound_documents_conversion_names_its_bill
                    CHECK (
                        status <> 'converted'
                        OR (bill_id IS NOT NULL AND converted_at IS NOT NULL
                            AND supplier_party_id IS NOT NULL)
                    ),

                ADD CONSTRAINT inbound_documents_keep_what_arrived
                    CHECK (
                        status IN ('received', 'quarantined')
                        OR content_hash IS NOT NULL
                    );
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE inbound_documents IS
                'An inbox item is not a bill. Turning a stranger''s attachment straight into a payable is the error - PF-140, PF-141.';
            COMMENT ON CONSTRAINT inbound_documents_match_method_is_known ON inbound_documents IS
                'A name match is deliberately absent: two suppliers called Gulf Trading are two suppliers.';
            COMMENT ON COLUMN inbound_documents.sender_address IS
                'Who sent it, kept apart from the supplier we matched - they disagree more often than anybody expects.';
        SQL);

        foreach (['inbound_senders', 'inbound_documents'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_documents');
        Schema::dropIfExists('inbound_senders');
    }
};
