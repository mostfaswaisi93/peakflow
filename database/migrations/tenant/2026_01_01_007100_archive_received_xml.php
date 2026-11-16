<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `received_xml`, an archive kind for documents that arrive - PF-141.
 *
 * ## Why the list needed extending
 *
 * The archive's kinds were written for the outbound direction, and its own
 * docblock says so: "the bytes that were sent". An inbound document is a
 * different artefact answering a different question - not "what did we send
 * them" but "what did they actually send us", as opposed to what our parser
 * made of it.
 *
 * Keeping it apart from `sent_xml` matters because the two are compared. A
 * dispute about an inbound invoice is settled by the bytes that arrived, and
 * a kind that conflated the two would make the archive's uniqueness rule -
 * one artefact of a kind per subject - treat our copy and their original as
 * rival answers to the same question.
 *
 * @financial-not-applicable: this alters a CHECK constraint on
 * `archived_artefacts`. No table is created and no financial row is written;
 * the table's own RLS policy is unchanged.
 */
return new class extends Migration
{
    private const KINDS = ['sent_xml', 'received_xml', 'cleared_xml', 'signature', 'receipt', 'pdf', 'qr'];

    public function up(): void
    {
        $this->rewrite(self::KINDS);
    }

    public function down(): void
    {
        // @unsafe-migration-reviewed: restores the list as it stood before
        // this migration. An artefact already stored as `received_xml` would
        // fail the restored constraint, which is correct - the kind would no
        // longer be one the application recognises.
        $this->rewrite(array_values(array_filter(
            self::KINDS,
            static fn (string $kind): bool => $kind !== 'received_xml',
        )));
    }

    /** @param list<string> $kinds */
    private function rewrite(array $kinds): void
    {
        $list = implode(', ', array_map(
            static fn (string $kind): string => sprintf("'%s'", $kind),
            $kinds,
        ));

        DB::unprepared(sprintf(
            'ALTER TABLE archived_artefacts DROP CONSTRAINT IF EXISTS archived_artefacts_kind_is_known;
             ALTER TABLE archived_artefacts ADD CONSTRAINT archived_artefacts_kind_is_known
                 CHECK (kind IN (%s));',
            $list,
        ));
    }
};
