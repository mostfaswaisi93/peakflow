<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\RateProviders;

use App\Tenant\Catalog\Contracts\RateProvider;
use App\Tenant\Catalog\Exceptions\RateProviderFailed;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Rates from configuration rather than from a network - PF-069.
 *
 * ## Three real uses, none of them "for tests"
 *
 * **A pegged currency.** SAR has been pegged to USD at 3.75 since 1986. Going
 * to a feed for it every morning adds a dependency and a failure mode to a
 * number that does not move, and the feed's value wobbles in the fourth
 * decimal in ways that then appear in the books.
 *
 * **A statutory rate.** A tax authority publishes a monthly rate in a gazette
 * or a PDF. It is typed in. There is no API, and there will not be one.
 *
 * **An air-gapped or restricted deployment**, where outbound HTTP to a rate
 * vendor is not permitted at all and the rates arrive by another route.
 *
 * ## It is still a provider, with a precedence
 *
 * Which matters: a pegged rate configured here sits at a precedence the
 * operator chooses, so it can either override a feed or back one up. Making
 * it a special case in the fetcher instead would mean the override order was
 * decided by whoever wrote the fetcher.
 */
final class StaticRateProvider implements RateProvider
{
    /**
     * @param  array<string, array<string, string>|string>  $rates
     *     either "FROM/TO" => rate, or rate_set => ["FROM/TO" => rate]
     */
    public function __construct(
        private readonly string $code,
        private readonly array $rates,
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function lastPayload(): ?array
    {
        // There is no upstream response to keep. The configuration IS the
        // payload, and it is already in version control where a reader can
        // see both its value and who changed it.
        return null;
    }

    /**
     * @param  list<array{from: string, to: string}>  $directions
     * @return array<string, string>
     */
    public function fetch(array $directions, Carbon $asOf, string $rateSet): array
    {
        $table = $this->tableFor($rateSet);
        $found = [];

        foreach ($directions as $direction) {
            $pair = $direction['from'].'/'.$direction['to'];

            if (! isset($table[$pair])) {
                // Omitted, not failed. This provider knows a few pairs by
                // design; the caller tries the next one for the rest.
                continue;
            }

            $rate = BigDecimal::of($table[$pair]);

            if ($rate->isNegativeOrZero()) {
                throw RateProviderFailed::sentAnImpossibleRate($this->code, $pair, (string) $rate);
            }

            $found[$pair] = (string) $rate;
        }

        return $found;
    }

    /** @return array<string, string> */
    private function tableFor(string $rateSet): array
    {
        /*
         | Two shapes accepted, because both are natural to write:
         |
         |   'rates' => ['USD/SAR' => '3.75']
         |   'rates' => ['statutory' => ['USD/SAR' => '3.76']]
         |
         | A flat table applies to every set, which is right for a peg. A
         | nested one is per set, which is right for a statutory rate that
         | differs from the market.
         */
        $nested = $this->rates[$rateSet] ?? null;

        if (is_array($nested)) {
            return array_map(static fn (mixed $v): string => (string) $v, $nested);
        }

        $flat = [];

        foreach ($this->rates as $key => $value) {
            if (is_string($value)) {
                $flat[(string) $key] = $value;
            }
        }

        return $flat;
    }
}
