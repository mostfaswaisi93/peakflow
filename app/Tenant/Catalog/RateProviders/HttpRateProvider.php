<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\RateProviders;

use App\Tenant\Catalog\Contracts\RateProvider;
use App\Tenant\Catalog\Exceptions\RateProviderFailed;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A rate feed over HTTP, described by configuration - PF-069.
 *
 * ## One class, many feeds
 *
 * Every published rate API has the same shape: a URL with the base currency
 * and a date in it, and a JSON object of quote currency to rate somewhere in
 * the response. The differences are the URL template, where the rates live in
 * the body, and how the key is spelled - which are three strings, not three
 * classes.
 *
 * So a feed is added in `config/fx.php` and needs no code. The value of that
 * is not brevity: it is that the part most likely to be wrong - a URL, a
 * path into a JSON body - is visible in a diff next to the other feeds,
 * instead of buried in a class nobody opens.
 *
 * ## One request per base currency, not per pair
 *
 * These APIs quote a base against many currencies in one call. Asking per
 * pair turns a nine-currency tenant into seventy-two requests and gets the
 * feed to rate-limit us, which then looks like an outage.
 *
 * ## It never inverts
 *
 * A feed that publishes `SAR -> EUR` and is asked for `EUR -> SAR` returns
 * nothing for it. The caller moves to the next provider. That is the point of
 * precedence existing.
 */
final class HttpRateProvider implements RateProvider
{
    /** @var array<string, mixed>|null */
    private ?array $lastPayload = null;

    /**
     * @param  array{url: string, rates_path?: string, timeout?: int, headers?: array<string, string>}  $config
     */
    public function __construct(
        private readonly string $code,
        private readonly array $config,
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    /** @return array<string, mixed>|null */
    public function lastPayload(): ?array
    {
        return $this->lastPayload;
    }

    /**
     * @param  list<array{from: string, to: string}>  $directions
     * @return array<string, string>
     */
    public function fetch(array $directions, Carbon $asOf, string $rateSet): array
    {
        $rates = [];
        $payload = [];

        // Grouped by base currency: these APIs quote one base against many
        // quotes in a single call.
        $byBase = [];

        foreach ($directions as $direction) {
            $byBase[$direction['from']][] = $direction['to'];
        }

        foreach ($byBase as $base => $quotes) {
            $body = $this->request((string) $base, $quotes, $asOf);
            $payload[(string) $base] = $body;

            $quoted = Arr::get($body, (string) ($this->config['rates_path'] ?? 'rates'));

            if (! is_array($quoted)) {
                throw RateProviderFailed::unreadable(
                    $this->code,
                    sprintf(
                        'no rates found at "%s" in the response for %s',
                        (string) ($this->config['rates_path'] ?? 'rates'),
                        $base,
                    ),
                );
            }

            foreach ($quotes as $quote) {
                $value = $quoted[$quote] ?? $quoted[strtolower((string) $quote)] ?? null;

                if ($value === null) {
                    // Not a failure: the feed simply does not publish this
                    // one. The caller tries the next provider for it.
                    continue;
                }

                $rates[$base.'/'.$quote] = $this->assertUsable($base.'/'.$quote, $value);
            }
        }

        $this->lastPayload = $payload;

        return $rates;
    }

    /**
     * @param  list<string>  $quotes
     * @return array<string, mixed>
     */
    private function request(string $base, array $quotes, Carbon $asOf): array
    {
        $url = str_replace(
            ['{base}', '{date}', '{symbols}'],
            [$base, $asOf->toDateString(), implode(',', $quotes)],
            (string) $this->config['url'],
        );

        try {
            $response = Http::withHeaders((array) ($this->config['headers'] ?? []))
                ->timeout((int) ($this->config['timeout'] ?? 10))
                /*
                 | No retry here, deliberately.
                 |
                 | Retrying inside one provider delays the move to the NEXT
                 | provider, and the next provider is the actual recovery
                 | strategy. Three retries with backoff against a feed that
                 | is down turns a one-second failover into thirty seconds of
                 | a scheduled job doing nothing.
                 */
                ->get($url);
        } catch (Throwable $e) {
            throw RateProviderFailed::unreachable($this->code, $e);
        }

        if ($response->failed()) {
            throw RateProviderFailed::respondedWith($this->code, $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw RateProviderFailed::unreadable($this->code, 'the body was not a JSON object');
        }

        return $body;
    }

    private function assertUsable(string $pair, mixed $value): string
    {
        try {
            $rate = BigDecimal::of(is_string($value) || is_int($value) || is_float($value) ? $value : '0');
        } catch (MathException) {
            throw RateProviderFailed::unreadable(
                $this->code,
                sprintf('the rate for %s was not a number', $pair),
            );
        }

        /*
         | A zero or negative rate fails the WHOLE response.
         |
         | Skipping the pair would be tempting and wrong: a feed sending a
         | zero has broken in a way that gives no reason to trust the values
         | beside it, and a rate of zero converts every amount to nothing
         | while still balancing.
         */
        if ($rate->isNegativeOrZero()) {
            throw RateProviderFailed::sentAnImpossibleRate($this->code, $pair, (string) $rate);
        }

        return (string) $rate;
    }
}
