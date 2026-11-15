<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Contracts;

use App\Tenant\Catalog\Exceptions\RateProviderFailed;
use Illuminate\Support\Carbon;

/**
 * Something that can be asked for exchange rates - PF-069.
 *
 * ## It is asked for a DIRECTION, not a pair
 *
 * `fetch()` receives `['EUR' => 'SAR', ...]`-shaped directions and must
 * return rates that go that way. A provider whose upstream only publishes one
 * direction has to say so by omitting the other, not by inverting it: that is
 * the one thing no implementation may do, because `1 / x` on a rounded rate
 * drifts one way permanently and nobody downstream can tell it happened.
 *
 * ## Failure is expected and must be loud
 *
 * A rate feed is a third party's HTTP endpoint. It will be down, it will be
 * slow, it will return 200 with an error body, and it will one day return a
 * rate of zero. An implementation must throw {@see RateProviderFailed}
 * rather than return something plausible - the caller's whole job is to move
 * to the next provider and raise an alert, and it cannot do that for a
 * failure dressed as a result.
 *
 * Returning a PARTIAL result is allowed and is not failure: a provider that
 * has EUR but not JPY should return EUR. The caller tries the next provider
 * for what is missing.
 */
interface RateProvider
{
    /** The code this provider is configured under in `fx_providers`. */
    public function code(): string;

    /**
     * Rates for the given directions on a date.
     *
     * @param  list<array{from: string, to: string}>  $directions
     * @return array<string, string>  "FROM/TO" => decimal rate, as a string
     *
     * @throws RateProviderFailed when the provider could not be reached or
     *                            answered with something unusable
     */
    public function fetch(array $directions, Carbon $asOf, string $rateSet): array;

    /**
     * What this provider actually returned, for the fetch run's record.
     *
     * Kept because a rate that turns out to be wrong raises exactly one
     * question - "is that what they sent us, or what we did to it" - and
     * without the payload the answer is a guess.
     *
     * @return array<string, mixed>|null
     */
    public function lastPayload(): ?array;
}
