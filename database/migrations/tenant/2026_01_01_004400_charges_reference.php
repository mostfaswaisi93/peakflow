<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `charges` joins the known reference kinds - PF-098.
 *
 * A debit note references the invoice it charges against, and the kind has to
 * be distinguishable from `credits`: the over-credit cap sums one and not the
 * other, and a debit note recorded as a credit would both reduce the cap and
 * increase the receivable.
  *
 * @online-unsafe Adds CHECK constraints to `invoice_references`, which already exists.
 *                `ADD CONSTRAINT` without `NOT VALID` scans every row under
 *                ACCESS EXCLUSIVE, so on a populated table it blocks reads and
 *                writes for the length of a full scan. It is declared rather
 *                than rewritten because what the marker describes is the
 *                STATEMENT, not the install where the table happened to be
 *                empty - a deployment replaying it onto years of rows would
 *                get the scan. The pattern to copy is
 *                `2026_01_03_000800_a_bill_can_be_cancelled`: NOT VALID, then
 *                VALIDATE CONSTRAINT, which takes only SHARE UPDATE EXCLUSIVE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE invoice_references
                DROP CONSTRAINT IF EXISTS invoice_references_kind_is_known,
                ADD CONSTRAINT invoice_references_kind_is_known
                    CHECK (kind IN ('credits', 'charges', 'corrects', 'prepayment', 'order',
                        'contract', 'despatch', 'receipt', 'project'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE invoice_references
                DROP CONSTRAINT IF EXISTS invoice_references_kind_is_known,
                ADD CONSTRAINT invoice_references_kind_is_known
                    CHECK (kind IN ('credits', 'corrects', 'prepayment', 'order',
                        'contract', 'despatch', 'receipt', 'project'));
        SQL);
    }
};
