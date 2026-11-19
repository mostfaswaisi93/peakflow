<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Goods received not invoiced, as a control role - PF-132.
 *
 * ## Why the allowlist is rebuilt rather than relaxed
 *
 * `account_roles_known` lists every role by name, and that is the point: a
 * constraint that enumerates them is what stops a typo becoming a role
 * nothing resolves. So a new role means a new list, with the old one
 * dropped - and the list in this migration is the whole list, because a
 * constraint assembled from fragments across four migrations is one nobody
 * can read.
 *
 * ## Why GRNI is a control account and not an accrual somebody posts
 *
 * The thing that has to be reconcilable is "what have we received and not
 * been billed for". That is a balance, not a note in a close checklist. The
 * bill that matches a receipt reverses it, so a balance that does not clear
 * is a receipt nobody was ever billed for - which is exactly what an aged
 * accrual report is looking for, and a journal entry posted by hand each
 * month cannot answer.
 *
 * @financial-not-applicable: this adds a role name to a CHECK constraint on
 * `account_roles`, which was created with its `entity_id` and its RLS policy
 * in PF-021.
  *
 * @online-unsafe Adds CHECK constraints to `account_roles`, which already exists.
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
            ALTER TABLE account_roles DROP CONSTRAINT IF EXISTS account_roles_known;

            ALTER TABLE account_roles
                ADD CONSTRAINT account_roles_known CHECK (role IN (
                    'accounts_receivable', 'accounts_payable', 'vat_input', 'vat_output',
                    'bank', 'payments_in_transit', 'fx_gain_loss', 'fx_revaluation',
                    'rounding_difference', 'suspense', 'deferred_revenue', 'retained_earnings',
                    'inter_entity', 'bad_debt', 'ecl_provision',
                    'withholding_receivable', 'withholding_payable',
                    'goods_received_not_invoiced'
                ));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE account_roles DROP CONSTRAINT IF EXISTS account_roles_known;

            ALTER TABLE account_roles
                ADD CONSTRAINT account_roles_known CHECK (role IN (
                    'accounts_receivable', 'accounts_payable', 'vat_input', 'vat_output',
                    'bank', 'payments_in_transit', 'fx_gain_loss', 'fx_revaluation',
                    'rounding_difference', 'suspense', 'deferred_revenue', 'retained_earnings',
                    'inter_entity', 'bad_debt', 'ecl_provision',
                    'withholding_receivable', 'withholding_payable'
                ));
        SQL);
    }
};
