<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Exceptions;

use Throwable;

/**
 * A rate feed could not be used - PF-069.
 *
 * ## Why this is an exception and not a null
 *
 * The caller has three things to do when a feed fails: try the next one, fall
 * back to the last known rate, and raise an alert. It can only do the third
 * if it knows the difference between "this provider has no rate for JPY" and
 * "this provider is down" - and a null return flattens both into the same
 * thing.
 *
 * So a provider with no rate for a pair OMITS it from its result, and a
 * provider that could not answer at all throws this. The reason is carried
 * because it ends up on the fetch run and in the alert, and "connection
 * timed out after 5s" and "returned HTTP 402, the subscription has lapsed"
 * need completely different responses from a human.
 */
final class RateProviderFailed extends CatalogException
{
    public function __construct(
        public readonly string $provider,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('The rate provider "%s" could not be used: %s', $provider, $reason),
            0,
            $previous,
        );
    }

    public static function unreachable(string $provider, Throwable $e): self
    {
        return new self($provider, 'the request failed: '.$e->getMessage(), $e);
    }

    public static function respondedWith(string $provider, int $status): self
    {
        return new self($provider, sprintf('it responded with HTTP %d', $status));
    }

    public static function unreadable(string $provider, string $detail): self
    {
        return new self($provider, 'its response could not be read: '.$detail);
    }

    /**
     * A rate that cannot be a rate.
     *
     * Treated as a provider failure rather than a bad value to be skipped,
     * because a feed sending a zero or a negative number is a feed that has
     * broken in a way the next value cannot be trusted either.
     */
    public static function sentAnImpossibleRate(string $provider, string $pair, string $value): self
    {
        return new self($provider, sprintf(
            'it sent %s for %s. A rate of zero or less converts every amount to nothing, or to its negative, '.
            'and still balances - so the whole response is treated as unusable rather than this one pair.',
            $value,
            $pair,
        ));
    }
}
