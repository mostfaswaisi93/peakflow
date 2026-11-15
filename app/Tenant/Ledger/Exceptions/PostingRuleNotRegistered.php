<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use RuntimeException;

/**
 * No module registered a rule for this key.
 *
 * Lists what IS registered, because the commonest cause is a typo in a key
 * and the second commonest is a service provider that did not run - and the
 * list distinguishes the two immediately.
 */
final class PostingRuleNotRegistered extends RuntimeException
{
    /** @param list<string> $available */
    public static function forKey(string $key, array $available): self
    {
        sort($available);

        return new self(sprintf(
            'No posting rule is registered for "%s". %s',
            $key,
            $available === []
                ? 'No rules are registered at all, which usually means the module that owns them did not boot.'
                : 'Registered: '.implode(', ', $available).'.',
        ));
    }
}
