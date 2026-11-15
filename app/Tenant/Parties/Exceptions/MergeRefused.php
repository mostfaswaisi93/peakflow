<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Exceptions;

use RuntimeException;

/**
 * A party merge that will not be performed - PF-059.
 *
 * A merge is irreversible: un-merging would have to decide which of the
 * documents written since belong to which party, and there is no information
 * to decide it with. So every refusal here is a refusal to start, which is
 * the only cheap moment.
 */
final class MergeRefused extends RuntimeException
{
    public static function intoItself(): self
    {
        return new self('A party cannot be merged into itself.');
    }

    public static function alreadyMerged(string $code, string $survivorId): self
    {
        return new self(sprintf(
            'That party (%s) was already merged into %s. Merging it again would make the chain two deep, and '.
            'every lookup would have to walk it - merge into the surviving party directly.',
            $code === '' ? 'no code' : $code,
            $survivorId,
        ));
    }

    public static function survivorIsMerged(string $survivorId, string $itsSurvivorId): self
    {
        return new self(sprintf(
            'The surviving party %s is itself merged into %s, so it is not a destination - anything merged '.
            'into it would resolve to a party that resolves to another one. Merge into %s instead.',
            $survivorId,
            $itsSurvivorId,
            $itsSurvivorId,
        ));
    }

    /**
     * A merge with no stated reason.
     *
     * Refused because a merge is irreversible and because the question asked
     * afterwards is always "why did we think these were the same customer" -
     * which, with two similar names and no note, nobody can answer.
     */
    public static function reasonTooThin(): self
    {
        return new self(
            'A merge needs a reason somebody can read later. It cannot be undone, and the question asked '.
            'afterwards is always why these were thought to be the same customer - with two similar names '.
            'and no note, nobody can answer it. Say what identified them: a registration number, a tax ID, '.
            'a conversation.',
        );
    }
}
