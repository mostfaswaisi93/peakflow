<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Support\Hashing\ChainLink;
use Illuminate\Support\Facades\DB;

/**
 * Walks the audit chain and reports the FIRST place it diverges - PF-035.
 *
 * The first divergence is the only interesting one. After a record is altered
 * every subsequent link fails, so reporting all of them means reporting the
 * size of the log rather than the location of the problem. An investigator
 * needs "record 4,812, written at 09:14 by farah@…, is not what it claims" -
 * not forty thousand lines saying the same thing.
 */
final class AuditChainVerifier
{
    public function verify(?int $limit = null, string $connectionName = 'tenant'): AuditChainVerdict
    {
        // The connection is a parameter so a check can verify records it wrote
        // inside an uncommitted transaction - another connection would not see
        // them, and the round-trip is exactly what needs checking.
        $connection = DB::connection($connectionName);

        $query = $connection->table('audit_log')
            ->select(array_merge(AuditRecordProjection::FIELDS, ['canonical_hash', 'previous_hash', 'chain_index']))
            ->orderBy('chain_index');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $expectedIndex = 1;
        $previousHash = ChainLink::GENESIS;
        $checked = 0;

        foreach ($query->cursor() as $row) {
            $checked++;

            if ((int) $row->chain_index !== $expectedIndex) {
                return AuditChainVerdict::broken(
                    $checked,
                    (string) $row->id,
                    (int) $row->chain_index,
                    sprintf(
                        'chain index jumped to %d where %d was expected, so %d record(s) are missing',
                        (int) $row->chain_index,
                        $expectedIndex,
                        (int) $row->chain_index - $expectedIndex,
                    ),
                );
            }

            if (! hash_equals($previousHash, (string) $row->previous_hash)) {
                return AuditChainVerdict::broken(
                    $checked,
                    (string) $row->id,
                    (int) $row->chain_index,
                    'this record does not follow the one before it; a record was inserted, removed or reordered',
                );
            }

            $recomputed = ChainLink::link(
                AuditRecordProjection::of($row),
                (int) $row->chain_index === 1
                    ? null
                    : ChainLink::fromStored((string) $row->previous_hash, ChainLink::GENESIS, (int) $row->chain_index - 1),
            );

            if (! hash_equals($recomputed->hash, (string) $row->canonical_hash)) {
                return AuditChainVerdict::broken(
                    $checked,
                    (string) $row->id,
                    (int) $row->chain_index,
                    'the record\'s contents do not match its own hash; it was altered after it was written',
                );
            }

            $previousHash = (string) $row->canonical_hash;
            $expectedIndex++;
        }

        $head = $connection->selectOne('SELECT last_hash, last_index FROM audit_chain_head WHERE id = true');

        // The head must agree with the chain, when the whole chain was walked.
        // A head ahead of the log means records were deleted from the end -
        // which breaks no link, so it is invisible to the walk above and is
        // exactly the omission a truncating attacker would rely on.
        if ($limit === null && $head !== null && (int) $head->last_index !== $checked) {
            return AuditChainVerdict::broken(
                $checked,
                null,
                $checked,
                sprintf(
                    'the chain head records %d entries but only %d exist; %s removed from the END of the log, '.
                    'which breaks no link and is why the head is checked separately',
                    (int) $head->last_index,
                    $checked,
                    (int) $head->last_index - $checked === 1
                        ? '1 record was'
                        : ((int) $head->last_index - $checked).' records were',
                ),
            );
        }

        return AuditChainVerdict::intact($checked);
    }
}
