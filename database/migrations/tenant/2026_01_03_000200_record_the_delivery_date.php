<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery date, which is the third date on a document - PF-077.
 *
 * Not tagged `@financial`: that tag is what holds a migration to declaring an
 * `entity_id` and enabling row-level security, and this one creates no table.
 * `invoices` has both already, and the new column inherits the policy that is
 * on the table rather than needing its own.
 *
 * ## Three dates, three questions
 *
 *   BT-2   issue_date      when the document was raised
 *   BT-7   tax_point       which return period the tax falls in
 *   BT-72  delivery_date   when the supply actually happened
 *
 * `invoices` carried the first two and not the third, which made the
 * delivery-date bases in `TaxPointBasis` undeliverable: a jurisdiction whose
 * tax point is the date of the supply had nowhere to read the date of the
 * supply from, so the only answer the issuer could give was the invoice date.
 * That is the failure PF-077 exists to prevent - a supply on the 30th
 * invoiced on the 1st, filed a month late, and nothing on the document
 * saying which date decided.
 *
 * `docs/standards/en16931-mapping.md` has named this column as `BT-72`'s
 * source since the mapping was written. This is the column it named.
 *
 * ## Nullable, because most documents have no separate delivery date
 *
 * A service invoiced on completion is delivered on its invoice date and the
 * column stays empty; the derivation then reads the invoice date, which is
 * the same answer by a route somebody can check. A default of `issue_date`
 * would have been worse than null in exactly the way PF-077 warns about: it
 * is always present, always plausible, and silently answers a question
 * nobody asked it.
 *
 * ## Why no CHECK pairing it with the tax point
 *
 * The tempting constraint is "an issued document's tax point is one of its
 * own dates". It is wrong for the payment-date bases, where the tax point is
 * the date money arrived and is on neither of these columns, and a constraint
 * that is wrong for one regime is a constraint that gets dropped rather than
 * narrowed. What the tax point was derived FROM is frozen in
 * `tax_decision_snapshot.tax_point` instead, where it names the basis, the
 * winning date and the profile.
 *
 * @unsafe-migration-reviewed: `ADD COLUMN ... NULL` with no default takes no
 * table rewrite in PostgreSQL 11+ and holds ACCESS EXCLUSIVE only long enough
 * to update the catalog. Nothing reads the column until the issuer that ships
 * with it, and the `down()` drop is the mirror of that - safe only because
 * nothing has been written to the column by then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            /*
             | Beside the issue date, because they are the same kind of fact:
             | two dates a person reads off the document, as against the tax
             | point, which is derived from them.
             */
            $table->date('delivery_date')->nullable()->after('issue_date');
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN invoices.delivery_date IS
                'EN 16931 BT-72, the actual delivery date. Distinct from BT-2 (issue_date) and from '
                'BT-7 (tax_point), which is DERIVED from these by the jurisdiction profile''s basis '
                'and frozen with its reasoning in tax_decision_snapshot.tax_point - PF-077.';
        SQL);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('delivery_date');
        });
    }
};
