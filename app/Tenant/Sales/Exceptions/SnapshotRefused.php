<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use RuntimeException;

/**
 * A snapshot that would freeze a blank - PF-091.
 *
 * A snapshot with an empty legal name is worse than no snapshot: the document
 * looks complete, the reprint shows nothing where a name should be, and nobody
 * finds out until a dispute.
 */
final class SnapshotRefused extends RuntimeException
{
    public static function withoutALegalName(string $side): self
    {
        return new self(sprintf(
            'The %s has no legal name, so there is nothing to freeze onto the document. An invoice '.
            'states who issued it and who owes it; a blank there is not a formatting problem, it is a '.
            'document that cannot be enforced.',
            $side,
        ));
    }
}
