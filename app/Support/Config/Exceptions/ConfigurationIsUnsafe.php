<?php

declare(strict_types=1);

namespace App\Support\Config\Exceptions;

use RuntimeException;

/**
 * A production deployment that will not be started - PF-012.
 *
 * All of the problems at once, deliberately. A deployment with four wrong
 * values should learn about four of them, not about one per restart - the
 * second is four deploys, and somewhere in the middle of them somebody
 * decides the guard is the problem.
 */
final class ConfigurationIsUnsafe extends RuntimeException
{
    /** @param  list<string>  $problems */
    public static function because(array $problems): self
    {
        return new self(sprintf(
            "PeakFlow will not start: %d production setting(s) are unsafe.\n\n%s\n\n".
            "Each of these is a value whose wrong setting is SILENT - it works, and the consequence is \n".
            "discovered by somebody other than you. See docs/operations/configuration.md.",
            \count($problems),
            implode("\n\n", array_map(
                static fn (string $problem, int $i): string => sprintf('  %d. %s', $i + 1, $problem),
                $problems,
                array_keys($problems),
            )),
        ));
    }
}
