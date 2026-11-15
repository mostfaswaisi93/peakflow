<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Services;

use App\Support\Iso\CurrencyRegistry;
use App\Support\Money\Money;
use App\Support\Money\TranslatesMoney;
use App\Support\Money\Rate;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Support\Money\Translation;
use App\Tenant\Catalog\Exceptions\RateNotAvailable;
use App\Tenant\Catalog\Models\FxRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Where a rate comes from, and what happens when there is not one - PF-068.
 *
 * ## The lookup is effective-dated, and that is not the same as exact-dated
 *
 * Asking for Saturday's rate returns Friday's, because a market that is
 * closed has no Saturday rate and a Saturday invoice still has to be
 * translated. The rate that comes back carries its OWN date, so the document
 * records that it was booked at Friday's rate rather than appearing to have
 * a Saturday rate that never existed.
 *
 * What it will not do is reach forward. A rate dated after the document is a
 * rate that did not exist when the transaction happened, and using it is a
 * restatement - the kind that silently improves last month's margin.
 *
 * ## There is no fallback to 1, and no inverse
 *
 * Both temptations produce a plausible wrong number, which is the only kind
 * worth building a schema against:
 *
 *   - a fallback to 1 asserts two currencies are worth the same, and balances
 *   - an inverse of a rounded rate drifts in one direction, permanently, and
 *     surfaces months later as a consolidation difference nobody can place
 *
 * So a missing rate is {@see RateNotAvailable}, and a rate that exists only
 * the other way round is a DIFFERENT refusal, because that is the moment
 * someone reaches for `1 / x` and the message has to say why not.
 *
 * ## Precedence, not a single configured feed
 *
 * Two providers disagreeing about Tuesday is normal; both numbers are kept
 * and precedence decides which is used. A rate entered by hand wins over
 * every feed, because somebody chose it on purpose.
 *
 * @see IAS 21
 */
final class ExchangeRates implements TranslatesMoney
{
    /** Entered by a person rather than fetched. Precedence 0: it wins. */
    public const PROVIDER_MANUAL = 'manual';

    /**
     * The rate to use for a conversion on a date.
     *
     * @throws RateNotAvailable
     */
    public function rateFor(
        string $from,
        string $to,
        Carbon $date,
        string $rateSet = Rate::SET_MARKET,
    ): Rate {
        $from = CurrencyRegistry::assertExists($from);
        $to = CurrencyRegistry::assertExists($to);

        if ($from === $to) {
            /*
             | Refused rather than returning a rate of 1.
             |
             | A same-currency "conversion" is a caller that has not worked
             | out whether it needs to convert, and handing it a 1 lets the
             | confusion through into a journal line where the trigger will
             | refuse it anyway - further from the mistake, with less context.
             */
            throw RateNotAvailable::forPair($from, $to, $date->toDateString(), $rateSet);
        }

        $row = $this->latestOnOrBefore($from, $to, $date, $rateSet);

        if ($row !== null) {
            return $row->toRate();
        }

        // Only now, and only to produce a better refusal: if the opposite
        // direction is on file, say so and say why it was not inverted.
        if ($this->latestOnOrBefore($to, $from, $date, $rateSet) !== null) {
            throw RateNotAvailable::onlyTheOtherWay($from, $to, $date->toDateString(), $rateSet);
        }

        throw RateNotAvailable::forPair($from, $to, $date->toDateString(), $rateSet);
    }

    /**
     * Translate an amount, keeping the rate that did it - PF-068.
     *
     * Returns null when no translation is needed, which is the only way this
     * API expresses "already in the right currency". It never returns a
     * Translation at a rate of 1; see {@see Translation}.
     *
     * @throws RateNotAvailable
     */
    public function translate(
        Money $money,
        string $to,
        Carbon $date,
        string $rateSet = Rate::SET_MARKET,
        ?RoundingPolicy $policy = null,
    ): ?Translation {
        if ($money->currency === CurrencyRegistry::assertExists($to)) {
            return null;
        }

        return Translation::of(
            $money,
            $this->rateFor($money->currency, $to, $date, $rateSet),
            $policy ?? RoundingPolicy::halfUp(Scale::Amount),
        );
    }

    /**
     * The `TranslatesMoney` seam - PF-062, PF-090.
     *
     * The amount only, for callers outside this module that need a
     * comparable figure and will not store the rate. Anything that posts
     * uses {@see translate()} and records the rate, the set and the provider
     * with it.
     */
    public function to(Money $money, string $to, Carbon $date): ?Money
    {
        return $this->translate($money, $to, $date)?->functional;
    }

    /**
     * The newest rate on or before a date, resolved by provider precedence.
     *
     * One query, because the alternative - fetch the candidate dates then
     * pick a provider - reads the table twice and gets a different answer
     * when a feed lands between the two reads.
     */
    public function latestOnOrBefore(
        string $from,
        string $to,
        Carbon $date,
        string $rateSet = Rate::SET_MARKET,
    ): ?FxRate {
        /*
         | Ordered by date descending, then by precedence ascending.
         |
         | The date comes FIRST deliberately. A higher-precedence provider's
         | older rate must not beat a lower-precedence provider's rate for
         | the actual day: the day is a property of the transaction and the
         | provider is a property of our plumbing.
         |
         | A provider with no row in `fx_providers` sorts last rather than
         | being dropped, because a rate whose feed was later deleted is
         | still the rate that was used, and silently excluding it would make
         | a historical document unexplainable.
         */
        return FxRate::query()
            ->inForce()
            ->where('fx_rates.rate_set', $rateSet)
            ->where('fx_rates.from_currency', $from)
            ->where('fx_rates.to_currency', $to)
            ->where('fx_rates.as_of_date', '<=', $date->toDateString())
            ->leftJoin('fx_providers', 'fx_providers.code', '=', 'fx_rates.provider')
            ->orderByDesc('fx_rates.as_of_date')
            ->orderByRaw('coalesce(fx_providers.precedence, 9999) asc')
            ->orderByDesc('fx_rates.created_at')
            ->select('fx_rates.*')
            ->first();
    }

    /**
     * Store a rate, idempotently per (pair, date, set, provider) - PF-069.
     *
     * Re-running a fetch inserts nothing and changes nothing. That is the
     * requirement, and it is also what makes the fetch safe to retry after a
     * partial failure - which is the normal way a rate job ends.
     *
     * The conflict target is the unique index, so two workers racing on the
     * same pair produce one row rather than a duplicate-key error that fails
     * an otherwise successful run.
     */
    public function record(
        string $from,
        string $to,
        Carbon $asOf,
        string $value,
        string $provider = self::PROVIDER_MANUAL,
        string $rateSet = Rate::SET_MARKET,
        ?array $payload = null,
    ): FxRate {
        $from = CurrencyRegistry::assertExists($from);
        $to = CurrencyRegistry::assertExists($to);

        // Constructing the value object first so the direction and the scale
        // are validated by the same code that will later do the arithmetic -
        // not by a looser check here that could drift away from it.
        $rate = new Rate(
            from: $from,
            to: $to,
            value: $value,
            date: $asOf->toDateTimeImmutable(),
            provider: $provider,
            rateSet: $rateSet,
        );

        DB::connection('tenant')->table('fx_rates')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'rate_set' => $rateSet,
            'from_currency' => $from,
            'to_currency' => $to,
            'as_of_date' => $asOf->toDateString(),
            'rate' => (string) $rate->value,
            'provider' => $provider,
            'fetched_at' => now(),
            'source_payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         | Read back the row IN FORCE, not merely the row matching the key.
         |
         | After a correction there are two rows with the same pair, date,
         | set and provider: the withdrawn one and its replacement. An
         | unfiltered read here would return whichever the planner reached
         | first, which during a second correction is the one already
         | superseded.
         */
        /** @var FxRate $stored */
        $stored = FxRate::query()
            ->inForce()
            ->where('rate_set', $rateSet)
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('as_of_date', $asOf->toDateString())
            ->where('provider', $provider)
            ->orderByDesc('created_at')
            ->firstOrFail();

        return $stored;
    }

    /**
     * Correct a rate by superseding it, never by editing it - PF-068.
     *
     * The database refuses an UPDATE to a stored rate, so this is the only
     * path. The old row stays, because every amount already translated at it
     * carries it: editing the row would leave those amounts unchanged and
     * their justification changed, which is worse than either.
     *
     * Note what this does NOT do: it does not restate anything already
     * posted. A posting translated at the superseded rate keeps the rate it
     * used. Reversing and reposting is a decision with period and tax
     * consequences, and it belongs to whoever is doing the close.
     */
    public function correct(FxRate $existing, string $value, string $reason): FxRate
    {
        return DB::connection('tenant')->transaction(function () use ($existing, $value, $reason): FxRate {
            /*
             | WITHDRAW FIRST, then record. The order is load-bearing.
             |
             | The idempotency index covers rows in force, so while the old
             | row still stands the replacement collides with it and the
             | insert is quietly ignored - and `record()` then hands back the
             | row being corrected. The first correction this code ever ran
             | did exactly that, and tried to supersede a rate by itself.
             |
             | Withdrawing first takes the old row out of the index's reach,
             | so the replacement is a genuinely new row.
             */
            DB::connection('tenant')->table('fx_rates')
                ->where('id', $existing->getKey())
                ->update([
                    'withdrawn_at' => now(),
                    'withdrawn_reason' => $reason,
                ]);

            $replacement = $this->record(
                from: $existing->from_currency,
                to: $existing->to_currency,
                asOf: Carbon::parse((string) $existing->as_of_date?->toDateString()),
                value: $value,
                // A correction is a human act even when it corrects a feed,
                // so it is attributed to a person and not to the provider
                // whose number was wrong.
                provider: self::PROVIDER_MANUAL,
                rateSet: $existing->rate_set,
                payload: ['corrects' => (string) $existing->getKey(), 'reason' => $reason],
            );

            /*
             | The guard that would have caught the bug above on its own.
             |
             | A correction that hands back the row it was correcting has
             | done nothing, and the damage is not the no-op: the old row is
             | already withdrawn, so accepting this would leave the pair with
             | NO rate in force and every later conversion refused with
             | "no rate on file" - a failure a long way from its cause.
             */
            if ((string) $replacement->getKey() === (string) $existing->getKey()) {
                throw new LogicException(sprintf(
                    'Correcting the %s rate for %s to %s on %s returned the row it was meant to replace, so '.
                    'nothing was corrected. The idempotency index must exclude withdrawn rows for a correction '.
                    'to be insertable at all.',
                    $existing->rate_set,
                    $existing->from_currency,
                    $existing->to_currency,
                    (string) $existing->as_of_date?->toDateString(),
                ));
            }

            DB::connection('tenant')->table('fx_rates')
                ->where('id', $existing->getKey())
                ->update(['superseded_by_id' => $replacement->getKey()]);

            return $replacement;
        });
    }

    /**
     * How stale a rate is against its own set's threshold - PF-069.
     *
     * Returns null when it is within threshold. A number means "this many
     * days old", and every document that used the rate is expected to show
     * it: the point of the flag is that somebody looking at the invoice can
     * see the rate was old without going and checking.
     */
    public function stalenessInDays(Rate $rate, ?Carbon $asOf = null): ?int
    {
        $asOf ??= Carbon::today();

        $threshold = (int) (DB::connection('tenant')->table('fx_rate_sets')
            ->where('code', $rate->rateSet)
            ->value('staleness_threshold_days') ?? 3);

        $age = $rate->ageInDays($asOf->toDateTimeImmutable());

        return $age > $threshold ? $age : null;
    }
}
