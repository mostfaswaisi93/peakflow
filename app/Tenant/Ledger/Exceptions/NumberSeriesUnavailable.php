<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A numbering series that could neither be found nor created.
 *
 * ## When this can happen
 *
 * First use of a series is a race: two workers find no row and both insert
 * one. The insert is `ON CONFLICT DO NOTHING` and the row is then read back
 * under the lock, so the loser takes the winner's row and neither fails.
 *
 * This is the case where that read still finds nothing - which means the row
 * that was inserted is not the row the lookup describes, so the identity
 * itself disagrees with itself. Refused rather than retried: allocating from
 * a series nobody can find produces a number nobody can audit, and a retry
 * loop around an identity mismatch is an infinite loop.
 */
final class NumberSeriesUnavailable extends RuntimeException
{
    public static function cannotBeCreated(string $scope, string $key, int $year): self
    {
        return new self(sprintf(
            'The numbering series %s/%s for %d could not be found after it was created, so the row that '.
            'exists is not the row this lookup describes. Nothing has been allocated. This is a '.
            'configuration fault rather than contention: a series that cannot be read back cannot '.
            'produce a number anybody could audit.',
            $scope,
            $key,
            $year,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::ServerError)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
