<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use RuntimeException;

/** Why an inter-entity transaction was not posted - PF-057. */
final class InterEntityRefused extends RuntimeException
{
    public static function sameEntity(string $code): self
    {
        return new self(sprintf(
            'An inter-entity transaction needs two different entities, and both sides name %s. A pair '.
            'within one entity would eliminate against itself and hide whatever it was actually '.
            'recording.',
            $code,
        ));
    }

    public static function zeroSettlement(): self
    {
        return new self(
            'An inter-entity transaction of zero records nothing and leaves two entries nobody can '.
            'explain on both entities\' inter-company accounts.',
        );
    }

    public static function needsADescription(): self
    {
        return new self(
            'An inter-entity transaction needs a description of at least ten characters. It is what the '.
            'other entity\'s accountant reads when they ask what this balance is - "transfer" is not an '.
            'answer, and the question is asked months later by somebody who was not there.',
        );
    }
}
