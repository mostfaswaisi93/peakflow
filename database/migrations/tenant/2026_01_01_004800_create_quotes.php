<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes, and the versions of them - PF-089.
 *
 * @financial
 *
 * ## Why a quote is not an invoice with a status
 *
 * It has no number from a legal series, no posting, no effect on any
 * balance and no tax point. Modelling it as an invoice in a `quoted` state
 * puts a document with none of an invoice's obligations in the table every
 * report, every return and every chain walk reads - and the first time
 * somebody forgets a status filter, a quote appears in a VAT return.
 *
 * It has its own table, its own reference, and deliberately no row in
 * `invoices`.
 *
 * ## Versioning, and why the old one stays
 *
 * A revised quote is a new row that supersedes the previous one. The
 * previous one is kept because a customer who received it may still be
 * reading it: "the price I was sent" is a question about a document that
 * existed, and a system that edited the quote in place cannot answer it.
 *
 * Superseding is a one-to-one chain enforced by a unique index. Two
 * revisions of one quote is two answers to "what is the current price".
 *
 * ## The acceptance is the fact, not the status
 *
 * `accepted_at`, `accepted_by_name` and `accepted_from_ip` are recorded
 * because what matters afterwards is that somebody accepted it, when, and
 * from where - a status column alone leaves a dispute with nothing in it.
  *
 * @online-safe Creates `quote_lines`, `quotes`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    private const STATES = ['draft', 'sent', 'accepted', 'declined', 'expired', 'superseded'];

    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | A reference, not a legal number.
             |
             | Gaps in it are permitted and mean nothing: it exists so that
             | two people can talk about the same document. The gapless
             | series are for documents a tax authority counts.
             */
            $table->string('reference', 64);
            $table->unsignedSmallInteger('version')->default(1);

            $table->foreignUlid('party_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');

            $table->date('issue_date');
            $table->date('valid_until');

            $table->char('currency', 3);
            $table->decimal('net_amount', 21, 4)->default(0);
            $table->decimal('tax_amount', 21, 4)->default(0);
            $table->decimal('gross_amount', 21, 4)->default(0);
            $table->unsignedTinyInteger('presentation_exponent')->default(2);

            $table->string('profile', 32)->nullable();
            $table->text('terms')->nullable();
            $table->text('note')->nullable();

            // What it became, if anything: an order or an invoice.
            $table->string('outcome_type', 16)->nullable();
            $table->ulid('outcome_id')->nullable();

            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->string('accepted_by_name', 200)->nullable();
            $table->string('accepted_from_ip', 45)->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->text('declined_reason')->nullable();
            $table->timestampTz('expired_at')->nullable();

            // The version this one replaces, if it is a revision.
            $table->ulid('supersedes_id')->nullable();

            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'reference', 'version']);
            $table->index(['entity_id', 'status', 'valid_until']);
            $table->index(['entity_id', 'party_id']);
        });

        Schema::create('quote_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('quote_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_no');
            $table->ulid('item_id')->nullable();
            $table->string('item_code', 64)->nullable();
            $table->text('description');
            $table->char('unit_code', 8)->default('C62');
            $table->decimal('quantity', 21, 8);
            $table->decimal('unit_price', 21, 8);
            $table->decimal('net_amount', 21, 4);

            $table->ulid('revenue_account_id')->nullable();
            $table->ulid('tax_code_id')->nullable();
            $table->string('tax_category', 3)->nullable();
            $table->decimal('tax_rate', 9, 6)->nullable();

            $table->timestamps();

            $table->unique(['quote_id', 'line_no']);
        });

        $states = "'".implode("', '", self::STATES)."'";

        DB::unprepared(<<<SQL
            ALTER TABLE quotes
                ADD CONSTRAINT quotes_status_is_known
                    CHECK (status IN ({$states})),

                /*
                 | Validity cannot end before the quote begins.
                 |
                 | A quote valid until yesterday is a quote that expired
                 | before it was sent, and the expiry job would close it the
                 | moment it was created - which looks like the job
                 | misbehaving.
                 */
                ADD CONSTRAINT quotes_validity_ends_after_it_starts
                    CHECK (valid_until >= issue_date),

                ADD CONSTRAINT quotes_gross_is_net_plus_tax
                    CHECK (gross_amount = net_amount + tax_amount),

                /*
                 | Each terminal state pairs with its timestamp.
                 |
                 | Both halves, because a quote `accepted` with no
                 | `accepted_at` is an acceptance nobody can date, and an
                 | `accepted_at` on a declined quote is two facts that
                 | contradict each other.
                 */
                ADD CONSTRAINT quotes_accepted_is_dated
                    CHECK ((status = 'accepted') = (accepted_at IS NOT NULL)),

                ADD CONSTRAINT quotes_declined_is_dated
                    CHECK ((status = 'declined') = (declined_at IS NOT NULL)),

                ADD CONSTRAINT quotes_expired_is_dated
                    CHECK ((status = 'expired') = (expired_at IS NOT NULL)),

                /*
                 | An acceptance names somebody.
                 |
                 | Not a user: the person accepting is the customer, who has
                 | no login here. What is recorded is the name they gave and
                 | the address they came from, which is what a dispute about
                 | "we never agreed to this" is argued with.
                 */
                ADD CONSTRAINT quotes_acceptance_names_somebody
                    CHECK (accepted_at IS NULL OR length(btrim(coalesce(accepted_by_name, ''))) > 0),

                ADD CONSTRAINT quotes_outcome_is_complete
                    CHECK ((outcome_type IS NULL) = (outcome_id IS NULL)),

                ADD CONSTRAINT quotes_outcome_is_known
                    CHECK (outcome_type IS NULL OR outcome_type IN ('order', 'invoice')),

                ADD CONSTRAINT quotes_only_an_accepted_quote_has_an_outcome
                    CHECK (outcome_type IS NULL OR status = 'accepted');

            ALTER TABLE quote_lines
                ADD CONSTRAINT quote_lines_quantity_is_not_zero
                    CHECK (quantity <> 0),

                ADD CONSTRAINT quote_lines_description_is_not_blank
                    CHECK (length(btrim(description)) > 0);

            /*
             | One revision per version.
             |
             | Two rows superseding the same quote is two answers to "what is
             | the current price", and the customer has both.
             */
            CREATE UNIQUE INDEX quotes_supersede_once
                ON quotes (supersedes_id)
                WHERE supersedes_id IS NOT NULL;

            SELECT peakflow_apply_entity_rls('quotes');
            SELECT peakflow_apply_entity_rls('quote_lines');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
    }
};
