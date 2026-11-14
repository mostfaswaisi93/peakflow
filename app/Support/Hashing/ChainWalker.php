<?php

declare(strict_types=1);

namespace App\Support\Hashing;

/**
 * Walks a hash chain and reports the FIRST divergence.
 *
 * Reporting only that a chain is broken is close to useless on a series with
 * forty thousand documents in it. The first divergence is the actionable fact:
 * everything before it is intact, everything after it is unverifiable, and the
 * document at it is the one to investigate.
 *
 * Deliberately stateless and storage-agnostic - it takes an iterable so a
 * command can stream a series out of the database without loading it.
 */
final class ChainWalker
{
    /**
     * @param  iterable<array{reference: string, link: ChainLink, fields: array<array-key, mixed>}>  $documents
     *   In issue order. `reference` is whatever a human would use to find the
     *   document again - an invoice number, not a surrogate id.
     */
    public function walk(iterable $documents): ChainVerdict
    {
        $previous = null;
        $checked = 0;

        foreach ($documents as $document) {
            $link = $document['link'];
            $reference = $document['reference'];

            if (! $link->matches($document['fields'])) {
                return ChainVerdict::altered($reference, $link->index, $checked);
            }

            if ($previous === null) {
                if (! $link->isGenesis() && $link->index !== 1) {
                    return ChainVerdict::badStart($reference, $link->index, $checked);
                }
            } elseif (! $link->follows($previous)) {
                return ChainVerdict::broken($reference, $link->index, $checked);
            }

            $previous = $link;
            $checked++;
        }

        return ChainVerdict::intact($checked);
    }
}
