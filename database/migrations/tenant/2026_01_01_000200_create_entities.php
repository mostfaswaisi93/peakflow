<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entities - PF-025.
 *
 * An entity is a LEGAL COMPANY that files its own return. A tenant is a
 * customer. A group with a holding company, two operating companies and a
 * free-zone subsidiary is one tenant and four entities.
 *
 * What an entity owns exclusively, and why none of it can be shared:
 *
 *   chart of accounts      different statutory account codes per jurisdiction
 *   functional currency    IAS 21 - the currency of the primary economic
 *                          environment, and IMMUTABLE once anything is posted
 *   fiscal calendar        a subsidiary can have a different year end
 *   tax registrations      one registration number per country
 *   numbering series       invoice sequences are legally per issuer
 *   every posting          books are per legal person
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
        Schema::create('entities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 16)->unique();

            // Frozen onto every document this entity issues, because an issued
            // invoice is a historical statement about who issued it.
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('registration_number')->nullable();

            $table->char('country', 2);

            /*
             | The functional currency - IAS 21.
             |
             | IMMUTABLE once the entity has posted anything. Changing it is a
             | re-presentation of the entity's entire history, not an edit, and
             | the application refuses it with that explanation rather than
             | offering a dropdown that quietly invalidates every prior
             | statement. Enforced by a trigger below, not just in PHP.
             */
            $table->char('functional_currency', 3);

            // What statements are PRESENTED in, which may differ and may be
            // changed freely - it is a display choice, not an accounting one.
            $table->char('presentation_currency', 3)->nullable();

            // Which jurisdiction profiles this entity operates under. A
            // document's profile is frozen onto it at issue, so changing this
            // never alters what a past document claims to conform to.
            $table->json('jurisdiction_profiles')->nullable();

            $table->json('address')->nullable();
            $table->string('logo_path')->nullable();

            $table->boolean('active')->default(true);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'code']);
        });

        /*
         | Deferred from the identity migration so each file reads on its own.
         |
         | This is a `Schema::table` on an EXISTING table, which the migration
         | pre-flight flags: adding a foreign key takes SHARE ROW EXCLUSIVE on
         | both tables and validates every existing row, so on a live table it
         | belongs in two steps (ADD CONSTRAINT ... NOT VALID, then VALIDATE).
         |
         | @unsafe-migration-reviewed: this migration only ever runs during
         | provisioning, against a database created seconds earlier, so
         | `user_entity_roles` is guaranteed empty and there is nothing to
         | validate or lock out. If it is ever back-filled into an existing
         | tenant, split it as above - the pre-flight will say so again.
         */
        Schema::table('user_entity_roles', function (Blueprint $table): void {
            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
        });

        Schema::create('tax_registrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('entity_id')->constrained()->cascadeOnDelete();

            $table->char('jurisdiction', 2);

            // The scheme the identifier belongs to - `vat`, `gst`, `trn`. EN
            // 16931 needs the pair, not a bare number, because the same digits
            // mean different things under different schemes.
            $table->string('scheme', 24);
            $table->string('identifier');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            // One registration per entity per jurisdiction per scheme. A
            // second one is a data error, not a legitimate case.
            $table->unique(['entity_id', 'jurisdiction', 'scheme']);
        });

        Schema::create('entity_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('entity_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('type', 24);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'key']);
        });

        /*
        |----------------------------------------------------------------------
        | The functional currency is immutable once anything is posted
        |----------------------------------------------------------------------
        |
        | In the database, not only in PHP. A migration, a console command or a
        | future developer's REPL session can all reach around an application
        | guard, and the consequence here is that every historical statement
        | this entity ever produced becomes unverifiable.
        |
        | The check looks for journal entries rather than a flag, so it stays
        | correct when the ledger arrives in Phase 02 - and it is written to
        | pass harmlessly while that table does not yet exist.
        */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_functional_currency_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                posted_count bigint := 0;
            BEGIN
                IF NEW.functional_currency = OLD.functional_currency THEN
                    RETURN NEW;
                END IF;

                IF to_regclass('public.journal_entries') IS NOT NULL THEN
                    EXECUTE format(
                        'SELECT count(*) FROM journal_entries WHERE entity_id = %L AND status = %L',
                        OLD.id, 'posted'
                    ) INTO posted_count;
                END IF;

                IF posted_count > 0 THEN
                    RAISE EXCEPTION
                        'The functional currency of entity % cannot be changed: % posted journal entries depend on it. Under IAS 21 this is a re-presentation of the entity''s history, not an edit.',
                        OLD.code, posted_count
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER entities_functional_currency_immutable
                BEFORE UPDATE ON entities
                FOR EACH ROW
                EXECUTE FUNCTION assert_functional_currency_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS entities_functional_currency_immutable ON entities;
            DROP FUNCTION IF EXISTS assert_functional_currency_immutable();
        SQL);

        Schema::table('user_entity_roles', function (Blueprint $table): void {
            $table->dropForeign(['entity_id']);
        });

        Schema::dropIfExists('entity_settings');
        Schema::dropIfExists('tax_registrations');
        Schema::dropIfExists('entities');
    }
};
