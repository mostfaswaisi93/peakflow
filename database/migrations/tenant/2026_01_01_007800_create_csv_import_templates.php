<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bank's CSV layout, saved once - PF-154.
 *
 * ## Why this is a stored object and not a form somebody refills
 *
 * A bank's CSV export does not change from month to month. Making somebody
 * map twelve columns every month guarantees that one month they map two of
 * them the wrong way round - and the import that results is plausible, not
 * broken: the value date in the booking date column shifts a month's
 * reconciliation by two days and nothing says so.
 *
 * So the mapping is named, saved and reused, and the next import of the same
 * bank's file is a confirmation rather than a re-entry.
 *
 * ## Shareable, because the next tenant has the same bank
 *
 * A template is per entity by default and can be marked shared, which makes
 * it offered to every entity in the tenant. The mapping contains no
 * transaction data - it is column positions and a date format - so sharing
 * it leaks nothing, and the alternative is each entity rediscovering that
 * the same bank puts its value date in column three.
 *
 * ## The dialect travels with the mapping
 *
 * The decimal separator, the date format and the sign convention are part of
 * reading the file, not part of choosing columns, and a template that saved
 * only the columns would leave the dangerous half to be re-detected every
 * time. The dangerous half is the decimal separator: wrong by a factor of a
 * thousand, and every row still looks right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_import_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            $table->string('name', 120);
            $table->string('institution_name', 160)->nullable();

            /*
             | Optionally bound to one account.
             |
             | A business with two accounts at the same bank uses one
             | template for both, so the binding is optional. Where it is
             | set, the template is offered for that account first - which
             | is the common case and saves a choice.
             */
            $table->ulid('bank_account_id')->nullable();

            /*
             | How to read the file, and which column is what.
             |
             | Both are needed to reproduce an import. Saving the columns
             | alone would leave the decimal separator to be re-detected
             | every month, and that is the setting whose wrong answer is
             | invisible.
             */
            $table->jsonb('dialect');
            $table->jsonb('column_map');

            $table->boolean('is_shared')->default(false);

            /*
             | What it was built from, and when it last worked.
             |
             | The header row it was created against is kept so a changed
             | export can be detected: a bank that adds a column shifts
             | every index after it, and the import would otherwise read the
             | balance column as the amount.
             */
            $table->jsonb('header_row')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->integer('times_used')->default(0);

            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'name']);
            $table->index(['entity_id', 'bank_account_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE csv_import_templates
                ADD CONSTRAINT csv_import_templates_name_says_something
                    CHECK (length(btrim(name)) >= 3),

                /*
                 | A mapping names at least a date and an amount.
                 |
                 | Everything else is optional - plenty of banks give no
                 | counterparty at all - but a row with no date and no
                 | amount is not a transaction, and a template that saved
                 | neither would import nothing and report success.
                 */
                ADD CONSTRAINT csv_import_templates_map_is_usable
                    CHECK (
                        column_map ? 'booking_date'
                        AND (
                            column_map ? 'amount'
                            OR (column_map ? 'debit' AND column_map ? 'credit')
                        )
                    );

            COMMENT ON TABLE csv_import_templates IS
                'peakflow:tenant-scoped - a bank''s CSV layout, saved so the next import is a '
                'confirmation rather than a re-entry - PF-154.';
            COMMENT ON CONSTRAINT csv_import_templates_map_is_usable ON csv_import_templates IS
                'A template with no date or no amount imports nothing and reports success.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('csv_import_templates')");
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_templates');
    }
};
