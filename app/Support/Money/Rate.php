<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Iso\CurrencyRegistry;
use App\Support\Money\Exceptions\RateDirectionMismatch;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use DateTimeImmutable;
use JsonSerializable;

/**
 * A foreign exchange rate, with its direction, its date, its source and the
 * rate set it belongs to.
 *
 * ## Direction is stored, never inferred
 *
 * There is deliberately **no `inverse()` method**. If both `USD→EUR` and
 * `EUR→USD` are used, both are stored as separate rows.
 *
 * The reason is small and permanent:
 *
 *     stored:     USD→EUR = 0.920000000000
 *     computed:   EUR→USD = 1 / 0.92 = 1.086956521739...
 *     round trip: 1,000 USD → 920.00 EUR → 1,000.0000000004 USD
 *
 * The drift is tiny and *persistent* - it appears in every consolidation, in
 * the same direction, and someone will eventually ask about it. Storing both
 * directions costs one row.
 *
 * ## Rate sets
 *
 * Three coexist, because they genuinely differ and tax authorities frequently
 * mandate their own: a daily market rate for transactions, a monthly average
 * for profit-or-loss translation, and a statutory rate for tax purposes. A
 * conversion names the set it used, and the rate is recorded on whatever it
 * converted - so "which rate was this booked at" is always answerable.
 *
 * @see IAS 21
 */
final readonly class Rate implements JsonSerializable
{
    public const SET_MARKET = 'market';

    public const SET_MONTHLY_AVERAGE = 'monthly_average';

    public const SET_STATUTORY = 'statutory';

    public BigDecimal $value;

    public function __construct(
        public string $from,
        public string $to,
        BigDecimal|BigNumber|string|int $value,
        public DateTimeImmutable $date,
        public string $provider,
        public string $rateSet = self::SET_MARKET,
    ) {
        $this->value = BigDecimal::of($value)->toScale(Scale::Rate->value);

        CurrencyRegistry::assertExists($from);
        CurrencyRegistry::assertExists($to);
    }

    /**
     * Convert an amount.
     *
     * Refuses an amount whose currency is not this rate's `from` - including
     * the case where it is the `to`, which is the mistake this guards. Reaching
     * for the inverse is how a consolidation acquires a persistent one-cent
     * difference; fetch the rate for the direction you need.
     */
    public function convert(Money $money, RoundingPolicy $policy): Money
    {
        if ($money->currency !== $this->from) {
            throw $money->currency === $this->to
                ? RateDirectionMismatch::inverseRequested($this->from, $this->to)
                : RateDirectionMismatch::wrongCurrency($money->currency, $this->from, $this->to);
        }

        return Money::ofRounded(
            $money->amount->multipliedBy($this->value),
            $this->to,
            $policy,
        );
    }

    public function pair(): string
    {
        return $this->from.'/'.$this->to;
    }

    public function isForDirection(string $from, string $to): bool
    {
        return $this->from === strtoupper($from) && $this->to === strtoupper($to);
    }

    /** Days old relative to a reference date, for the staleness flag. */
    public function ageInDays(?DateTimeImmutable $asOf = null): int
    {
        $asOf ??= new DateTimeImmutable('today');

        return (int) $this->date->diff($asOf)->format('%a');
    }

    public function isStale(int $thresholdDays, ?DateTimeImmutable $asOf = null): bool
    {
        return $this->ageInDays($asOf) > $thresholdDays;
    }

    /**
     * @return array{from: string, to: string, rate: string, date: string, provider: string, rate_set: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'rate' => (string) $this->value,
            'date' => $this->date->format('Y-m-d'),
            'provider' => $this->provider,
            'rate_set' => $this->rateSet,
        ];
    }
}
