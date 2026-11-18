<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A place for exchange differences that are not profit - PF-185.
 *
 * ## Why an entity needs this account before it needs a foreign operation
 *
 * IAS 21.39(c) accumulates the exchange differences on translating a
 * foreign operation in a separate component of equity, and recycles them
 * into profit or loss only when that operation is disposed of. An entity
 * with nowhere to put them has one of two futures: the differences go
 * through profit, which overstates every year until it sells, or they are
 * not recorded, and the consolidation does not balance.
 *
 * It is added now rather than with consolidation (PF-194) because the
 * other-comprehensive-income section of the statement of profit or loss
 * was, until this account existed, a structure nothing could reach - so the
 * check over it asserted the shape of an empty list. A check that cannot
 * fail is the thing this repository treats as worse than no check.
 *
 * ## Only where the chart does not already have one
 *
 * A customer may have added their own translation reserve under another
 * code. Two accounts on the same statement line would present the reserve
 * twice under one heading, so the condition is the statement line and not
 * the code.
 *
 * @unsafe-migration-reviewed: it inserts one equity account per entity, with
 * no balance and no postings, under a WHERE that skips an entity that
 * already has an account on that line. An account with no lines changes no
 * figure anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO accounts (
                id, entity_id, code, name_ar, name_en, parent_id, path, depth,
                type, statement_line, postable, monetary, created_at, updated_at
            )
            SELECT
                peakflow_ulid(),
                e.id,
                '3.3',
                'احتياطي ترجمة العمليات الأجنبية',
                'Foreign operation translation reserve',
                equity.id,
                equity.path || '.3',
                equity.depth + 1,
                'equity',
                'foreign_operation_translation',
                true,
                false,
                now(),
                now()
              FROM entities e
              JOIN accounts equity
                ON equity.entity_id = e.id
               AND equity.type = 'equity'
               AND equity.parent_id IS NULL
             WHERE NOT EXISTS (
                   SELECT 1 FROM accounts a
                    WHERE a.entity_id = e.id
                      AND a.statement_line = 'foreign_operation_translation'
               )
               AND NOT EXISTS (
                   SELECT 1 FROM accounts a
                    WHERE a.entity_id = e.id
                      AND a.code = '3.3'
               );
        SQL);
    }

    public function down(): void
    {
        /*
         | Only an account that never held a line.
         |
         | Deleting one with postings would orphan them, and the journal is
         | the record - a rollback that destroyed history to tidy a chart
         | would be the opposite of what this system is for.
         */
        DB::unprepared(<<<'SQL'
            DELETE FROM accounts a
             WHERE a.statement_line = 'foreign_operation_translation'
               AND a.code = '3.3'
               AND NOT EXISTS (SELECT 1 FROM journal_lines l WHERE l.account_id = a.id);
        SQL);
    }
};
