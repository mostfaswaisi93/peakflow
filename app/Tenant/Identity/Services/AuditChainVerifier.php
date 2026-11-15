<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Support\Audit\AuditChain;
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
final class AuditChainVerifier implements AuditChain
{
    /**
     * The Support-contract view of the chain - PF-054.
     *
     * Flattened to primitives because the caller is the ledger's global
     * proof, which may not depend on this module and so cannot name
     * {@see AuditChainVerdict}.
     *
     * No limit: the global proof walks the whole log. A limit exists on
     * `verify()` for the places that want a fast partial answer, and this is
     * not one of them - a chain checked to its first thousand records says
     * nothing about the record somebody edited last week.
     *
     * @return array{intact: bool, checked: int, detail: ?string}
     */
    public function verdict(): array
    {
        $verdict = $this->verify();

        return [
            'intact' => $verdict->isIntact,
            'checked' => $verdict->recordsChecked,
            'detail' => $verdict->isIntact ? null : $verdict->summary(),
        ];
    }

    /**
     * Verify every chain, and report the first divergence found in any.
     *
     * Both chains must hold. A system where events verify and refusals do not
     * is a system where somebody removed the record of being stopped.
     */
    public function verify(?int $limit = null, string $connectionName = 'tenant'): AuditChainVerdict
    {
        $checked = 0;

        foreach ([AuditLog::CHAIN_EVENTS, AuditLog::CHAIN_REFUSALS] as $chain) {
            $verdict = $this->verifyChain($chain, $limit, $connectionName);

            if (! $verdict->isIntact) {
                return $verdict;
            }

            $checked += $verdict->recordsChecked;
        }

        return AuditChainVerdict::intact($checked);
    }

    public function verifyChain(string $chain, ?int $limit = null, string $connectionName = 'tenant'): AuditChainVerdict
    {
        // The connection is a parameter so a check can verify records it wrote
        // inside an uncommitted transaction - another connection would not see
        // them, and the round-trip is exactly what needs checking.
        $connection = DB::connection($connectionName);

        $query = $connection->table('audit_log')
            ->select(array_merge(AuditRecordProjection::FIELDS, ['canonical_hash', 'previous_hash', 'chain_index']))
            ->where('chain', $chain)
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

        $head = $connection->selectOne(
            'SELECT last_hash, last_index FROM audit_chain_head WHERE chain = ?',
            [$chain],
        );

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
                    'the "%s" chain head records %d entries but only %d exist; %s removed from the END of the log, '.
                    'which breaks no link and is why the head is checked separately',
                    $chain,
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
