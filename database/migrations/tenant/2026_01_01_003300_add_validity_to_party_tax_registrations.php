<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A tax registration is valid for a period, not forever - PF-079.
 *
 * ## Not marked financial, and the reason is the scope
 *
 * The architecture rule holds every migration that declares itself financial
 * to carrying a NOT NULL entity_id and enabling row-level security on it.
 * This table has neither and should not: a party's tax registration is a
 * property of the party, which is tenant-wide, and the per-entity view of a
 * party lives in party_entity_settings. The rows are reachable only through
 * parties, whose own policy scopes them.
 *
 * Writing that down rather than adding the tag is the convention the rule's
 * own documentation sets out - and the rule was once broken by prose
 * explaining an omission, which is why it now matches the tag on its own line.
 *
 * ## Why this is a change rather than something the table had
 *
 * `party_tax_registrations` recorded an identifier and nothing about when it
 * was the customer's identifier. That is enough to print a number on an
 * invoice and not enough to decide anything, and PF-079 has to decide
 * something: a reverse charge may only be issued to a customer that can
 * account for the tax itself, which means a registration that is valid ON THE
 * TAX POINT DATE.
 *
 * Without a validity window, a registration cancelled two years ago is
 * indistinguishable from a current one. The reverse charge issued against it
 * leaves the supplier owing tax it never collected, and it is found when the
 * customer's authority rejects the acquisition rather than when the invoice is
 * raised.
 *
 * ## A half-open `daterange`, the same shape as `tax_rates`
 *
 * `[from,until)`, so "valid on" is one `validity @> date` containment test
 * that the query and the constraint share. PF-076 learned this the hard way:
 * two nullable date columns need a COALESCE in both places, and the day one is
 * written with `<` and the other with `<=` is the day a document resolves two
 * registrations.
 *
 * ## The exclusion constraint is on three columns, not four
 *
 * `(party_id, jurisdiction, scheme)` - deliberately NOT including
 * `identifier`. A business that is renumbered has two identifiers in the same
 * jurisdiction under the same scheme, and those two periods must not overlap:
 * one number stops being valid the day the next starts. Including the
 * identifier would permit exactly the overlap the constraint exists to refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE party_tax_registrations
                ADD COLUMN validity daterange NOT NULL DEFAULT daterange(CURRENT_DATE, NULL, '[)');

            /*
             | The default exists only so this migration can run on a table
             | that already holds rows, and is dropped immediately so it cannot
             | silently answer for a row somebody forgot to date. A
             | registration whose validity nobody stated is a registration
             | nobody checked.
             */
            ALTER TABLE party_tax_registrations ALTER COLUMN validity DROP DEFAULT;

            ALTER TABLE party_tax_registrations
                ADD CONSTRAINT party_tax_registrations_validity_is_not_empty
                    CHECK (NOT isempty(validity)),

                ADD CONSTRAINT party_tax_registrations_validity_has_a_start
                    CHECK (lower(validity) IS NOT NULL),

                ADD CONSTRAINT party_tax_registrations_no_overlap
                    EXCLUDE USING gist (
                        party_id WITH =,
                        jurisdiction WITH =,
                        scheme WITH =,
                        validity WITH &&
                    );

            COMMENT ON COLUMN party_tax_registrations.validity IS
                'Half-open [from,until). PF-079: a reverse charge needs a registration valid on the tax point date.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE party_tax_registrations
                DROP CONSTRAINT IF EXISTS party_tax_registrations_no_overlap,
                DROP CONSTRAINT IF EXISTS party_tax_registrations_validity_has_a_start,
                DROP CONSTRAINT IF EXISTS party_tax_registrations_validity_is_not_empty,
                DROP COLUMN IF EXISTS validity;
        SQL);
    }
};
