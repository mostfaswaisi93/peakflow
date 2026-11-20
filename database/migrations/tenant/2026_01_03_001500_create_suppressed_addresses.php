<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses that bounced, and are not tried again - PF-104.
 *
 * ## Why a list rather than a flag on the party
 *
 * A party can have more than one address and only one of them is wrong -
 * a typo in the accounts-payable mailbox while the contact's own address
 * is fine. A flag on the party stops both, which means the business
 * stops being able to reach a customer because somebody mistyped a
 * second address for them.
 *
 * ## Why per tenant
 *
 * An address that bounced for one company may be perfectly good for
 * another: the first one typed it wrong. A shared list would let one
 * tenant's typo stop another tenant's invoice, and the second tenant
 * would have no way to see why.
 *
 * ## Complaints sit here too, and they are not the same thing
 *
 * A bounce says the address does not exist. A complaint says it does and
 * the person there marked the message as spam, which is a stronger
 * signal: continuing to send is how a sending domain is blocklisted for
 * every customer at once. Both stop the sending; `reason` is what tells
 * somebody reading the list which happened, because the remedies differ -
 * a bounce is fixed by correcting the address, a complaint is fixed by
 * asking the customer whether they want these at all.
 *
 * @online-safe Creates one table. No existing table is read or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressed_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->string('channel', 32)->default('email');
            $table->string('address');
            $table->string('reason', 32);              // bounce | complaint | manual
            $table->string('detail', 500)->nullable(); // what the provider said
            $table->char('party_id', 26)->nullable();
            $table->timestamp('suppressed_at');
            $table->timestamps();

            /*
             | Unique on the address within a channel, not within an
             | entity: a bounce is a fact about the mailbox, and two
             | companies in one tenant sending to the same wrong address
             | should both stop. `entity_id` is carried for row-level
             | security and for saying which entity first saw it.
             */
            $table->unique(['channel', 'address']);
            $table->index(['entity_id', 'suppressed_at']);
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE suppressed_addresses IS
                'Addresses that bounced or complained and are no longer sent to - PF-104. A hard bounce retried is how a sending domain loses the reputation that delivers the invoices whose addresses are right.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressed_addresses');
    }
};
