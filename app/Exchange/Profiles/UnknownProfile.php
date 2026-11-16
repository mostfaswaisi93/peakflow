<?php

declare(strict_types=1);

namespace App\Exchange\Profiles;

/**
 * A profile key nothing implements - PF-118.
 *
 * The message lists what does exist, because the two ways to reach this are
 * a typo in a configuration file and a document frozen under a profile that
 * was removed, and the reader needs to know which.
 */
final class UnknownProfile extends \RuntimeException
{
    /** @param list<string> $known */
    public static function named(string $key, array $known): self
    {
        return new self(sprintf(
            'No jurisdiction profile is implemented for "%s". Implemented: %s. A document frozen under '.
            'a profile that has been removed cannot be reserialized, and the archive copy is what the '.
            'recipient holds.',
            $key,
            $known === [] ? 'none' : implode(', ', $known),
        ));
    }
}
