<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Services;

use App\Support\Money\Rate;
use App\Tenant\Catalog\Contracts\RateProvider;
use App\Tenant\Catalog\Exceptions\RateProviderFailed;
use App\Tenant\Catalog\Models\FxProvider;
use App\Tenant\Catalog\RateProviders\HttpRateProvider;
use App\Tenant\Catalog\RateProviders\StaticRateProvider;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fetching rates, and what happens when the feeds are down - PF-069.
 *
 * ## The requirement that shapes everything here
 *
 * **A rate feed outage must not stop a business trading.** An invoice that
 * cannot be issued because a third party's API is down is a worse outcome
 * than an invoice issued at yesterday's rate, because the second one is
 * correctable and the first one is a customer not being billed.
 *
 * So this never throws on a feed failure. It walks providers in precedence
 * order, falls back to the last known rate, and raises an alert.
 *
 * ## The alert is the part that is easy to drop
 *
 * Falling back silently is the failure mode. A month of invoices at a
 * three-week-old rate produces a difference that is found at the close, is
 * material, and is explained by nobody - and every individual invoice looked
 * fine at the time.
 *
 * So a degraded run:
 *
 *   - records on `fx_rate_fetch_runs` which providers were tried and what
 *     each said
 *   - writes an audit entry, which is append-only and chained
 *   - logs a warning
 *   - exits the command non-zero, so a scheduler notices
 *
 * The non-zero exit is an alert on the JOB, not a block on trading. Nothing
 * here touches whether an invoice can be issued.
 *
 * ## Which pairs
 *
 * Taken from the entities' own enabled currencies, not from a configured
 * list. Every enabled non-functional currency needs a rate TO that entity's
 * functional currency, and nothing else does. A configured list is a second
 * place to keep the same fact, and the two diverge the first time somebody
 * enables a currency.
 */
final class RateFetcher
{
    public function __construct(
        private readonly ExchangeRates $rates,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Fetch every rate the tenant's entities need for a date.
     *
     * @return array{
     *     run_id: string,
     *     outcome: string,
     *     requested: int,
     *     inserted: int,
     *     from_fallback: list<string>,
     *     unresolved: list<string>,
     *     attempts: list<array{provider: string, fetched: int, failure: string|null}>,
     * }
     */
    public function fetch(Carbon $asOf, string $rateSet = Rate::SET_MARKET): array
    {
        $directions = $this->directionsNeeded();
        $runId = (string) Str::ulid();

        DB::connection('tenant')->table('fx_rate_fetch_runs')->insert([
            'id' => $runId,
            'rate_set' => $rateSet,
            'as_of_date' => $asOf->toDateString(),
            'started_at' => now(),
            'pairs_requested' => count($directions),
            'outcome' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outstanding = $directions;
        $inserted = 0;
        $attempts = [];

        foreach ($this->providers($rateSet) as $provider) {
            if ($outstanding === []) {
                break;
            }

            $attempt = ['provider' => $provider->code(), 'fetched' => 0, 'failure' => null];

            try {
                $found = $provider->fetch($outstanding, $asOf, $rateSet);
            } catch (RateProviderFailed $e) {
                $attempt['failure'] = $e->getMessage();
                $attempts[] = $attempt;

                $this->recordProviderFailure($provider->code(), $e->getMessage());

                // On to the next one. This is the whole reason precedence
                // exists, and the reason a failure here is not fatal.
                continue;
            }

            foreach ($found as $pair => $value) {
                [$from, $to] = explode('/', $pair);

                $this->rates->record(
                    from: $from,
                    to: $to,
                    asOf: $asOf,
                    value: $value,
                    provider: $provider->code(),
                    rateSet: $rateSet,
                    payload: $provider->lastPayload(),
                );

                $inserted++;
                $attempt['fetched']++;
            }

            $outstanding = array_values(array_filter(
                $outstanding,
                static fn (array $d): bool => ! isset($found[$d['from'].'/'.$d['to']]),
            ));

            $attempts[] = $attempt;
            $this->recordProviderSuccess($provider->code());
        }

        /*
         | What is left after every provider has been tried.
         |
         | Split in two, because they need different responses: a pair with
         | an older rate on file can still be traded on, and a pair with
         | nothing on file cannot be converted at all and every document in
         | it will be refused.
         */
        $fromFallback = [];
        $unresolved = [];

        foreach ($outstanding as $direction) {
            $pair = $direction['from'].'/'.$direction['to'];

            $last = $this->rates->latestOnOrBefore($direction['from'], $direction['to'], $asOf, $rateSet);

            /*
             | The fallback is BOUNDED.
             |
             | Falling back to a rate from last year is not resilience, it is
             | a wrong number with a long paper trail. Past the window the
             | pair counts as unresolved, which refuses documents in it -
             | and refusing is the right answer once "the last known rate"
             | has stopped meaning anything.
             |
             | Distinct from the staleness threshold on the rate SET, which
             | decides what gets flagged. A rate can be stale enough to flag
             | on every document and still be the best thing available.
             */
            $window = (int) config('fx.fallback_window_days', 30);

            $tooOld = $last !== null
                && $last->as_of_date !== null
                && $last->as_of_date->diffInDays($asOf) > $window;

            if ($last === null || $tooOld) {
                $unresolved[] = $tooOld
                    ? sprintf(
                        '%s (the newest on file is from %s, past the %d-day fallback window)',
                        $pair,
                        (string) $last?->as_of_date?->toDateString(),
                        $window,
                    )
                    : $pair;

                continue;
            }

            /*
             | Nothing is written for a fallback.
             |
             | The older row is already in force and the effective-dated
             | lookup will find it, so copying it forward under today's date
             | would assert that the feed published this rate today - which
             | it did not, and which would make the rate look current on
             | every document that used it.
             |
             | The staleness flag depends on the rate keeping its real date.
             */
            $fromFallback[] = sprintf(
                '%s (using %s from %s)',
                $pair,
                $last->rate,
                (string) $last->as_of_date?->toDateString(),
            );
        }

        $outcome = match (true) {
            $unresolved !== [] => 'failed',
            $fromFallback !== [] => 'degraded',
            default => 'ok',
        };

        $this->finishRun($runId, $outcome, $inserted, $fromFallback, $unresolved, $attempts);

        if ($outcome !== 'ok') {
            $this->raiseAlert($runId, $outcome, $rateSet, $asOf, $fromFallback, $unresolved, $attempts);
        }

        return [
            'run_id' => $runId,
            'outcome' => $outcome,
            'requested' => count($directions),
            'inserted' => $inserted,
            'from_fallback' => $fromFallback,
            'unresolved' => $unresolved,
            'attempts' => $attempts,
        ];
    }

    /**
     * Every direction some entity in this tenant actually needs.
     *
     * @return list<array{from: string, to: string}>
     */
    public function directionsNeeded(): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT DISTINCT ec.currency AS from_currency, e.functional_currency AS to_currency
            FROM entity_currencies ec
            JOIN entities e ON e.id = ec.entity_id
            WHERE ec.enabled
              AND ec.currency <> e.functional_currency
              AND e.active
              AND e.deleted_at IS NULL
            ORDER BY 1, 2
        SQL);

        return array_map(
            static fn (object $row): array => [
                'from' => (string) $row->from_currency,
                'to' => (string) $row->to_currency,
            ],
            $rows,
        );
    }

    /**
     * The configured providers, in precedence order.
     *
     * Reads `fx_providers` for the order and `config('fx.providers')` for how
     * to talk to each one. The split is deliberate: the order is an operator
     * decision that changes without a deploy, and the URL and credentials of
     * a feed are a deployment concern.
     *
     * A provider row with no configuration is skipped rather than failed -
     * `manual` is exactly that: a precedence slot for hand-entered rates,
     * with nothing to fetch from.
     *
     * @return list<RateProvider>
     */
    public function providers(string $rateSet): array
    {
        $configured = (array) config('fx.providers', []);
        $providers = [];

        $rows = FxProvider::query()
            ->where('active', true)
            ->orderBy('precedence')
            ->get();

        foreach ($rows as $row) {
            if (! $row->supplies($rateSet)) {
                continue;
            }

            $config = $configured[$row->code] ?? null;

            if (! is_array($config)) {
                continue;
            }

            $providers[] = match ((string) ($config['driver'] ?? 'http')) {
                'static' => new StaticRateProvider($row->code, (array) ($config['rates'] ?? [])),
                default => new HttpRateProvider($row->code, $config),
            };
        }

        return $providers;
    }

    private function recordProviderSuccess(string $code): void
    {
        FxProvider::query()->whereKey($code)->update([
            'last_success_at' => now(),
            'consecutive_failures' => 0,
            'last_failure_reason' => null,
            'updated_at' => now(),
        ]);
    }

    private function recordProviderFailure(string $code, string $reason): void
    {
        /*
         | Incremented in SQL rather than read-then-written.
         |
         | Two fetch runs overlapping - a scheduled one and one somebody
         | triggered by hand - would otherwise both read 3 and both write 4,
         | and the counter that is supposed to say "this feed has been down
         | for nine runs" says it has been down for five.
         */
        FxProvider::query()->whereKey($code)->update([
            'last_failure_at' => now(),
            'last_failure_reason' => Str::limit($reason, 500),
            'consecutive_failures' => DB::raw('consecutive_failures + 1'),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $fromFallback
     * @param  list<string>  $unresolved
     * @param  list<array{provider: string, fetched: int, failure: string|null}>  $attempts
     */
    private function finishRun(
        string $runId,
        string $outcome,
        int $inserted,
        array $fromFallback,
        array $unresolved,
        array $attempts,
    ): void {
        DB::connection('tenant')->table('fx_rate_fetch_runs')
            ->where('id', $runId)
            ->update([
                'finished_at' => now(),
                'rates_inserted' => $inserted,
                'pairs_from_fallback' => count($fromFallback),
                'pairs_unresolved' => count($unresolved),
                'provider_attempts' => json_encode($attempts, JSON_THROW_ON_ERROR),
                'outcome' => $outcome,
                'alert_raised' => $outcome !== 'ok',
                'notes' => $this->notes($fromFallback, $unresolved),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<string>  $fromFallback
     * @param  list<string>  $unresolved
     */
    private function notes(array $fromFallback, array $unresolved): ?string
    {
        $parts = [];

        if ($fromFallback !== []) {
            $parts[] = 'Trading continues on the last known rate for: '.implode('; ', $fromFallback);
        }

        if ($unresolved !== []) {
            $parts[] = 'No rate on file at all for: '.implode(', ', $unresolved).
                '. Documents in these currencies will be refused until a rate is recorded.';
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  list<string>  $fromFallback
     * @param  list<string>  $unresolved
     * @param  list<array{provider: string, fetched: int, failure: string|null}>  $attempts
     */
    private function raiseAlert(
        string $runId,
        string $outcome,
        string $rateSet,
        Carbon $asOf,
        array $fromFallback,
        array $unresolved,
        array $attempts,
    ): void {
        $failures = array_values(array_filter(
            $attempts,
            static fn (array $a): bool => $a['failure'] !== null,
        ));

        /*
         | The audit log, not only the application log.
         |
         | A log line is rotated, is not chained, and is not what anybody
         | reads when asking in March why February's invoices carry a
         | January rate. The audit entry is append-only and hash-chained, and
         | it sits beside the documents it explains.
         |
         | `record` rather than `refusal`: nothing was refused. The system
         | did the best available thing and is saying so.
         */
        $this->audit->record(
            category: 'fx',
            action: 'fx.rates.'.$outcome,
            subjectType: 'fx_rate_fetch_run',
            subjectId: $runId,
            subjectLabel: sprintf('%s rates for %s', $rateSet, $asOf->toDateString()),
            after: [
                'outcome' => $outcome,
                'from_fallback' => $fromFallback,
                'unresolved' => $unresolved,
                'provider_failures' => $failures,
            ],
            reason: $outcome === 'failed'
                ? 'no rate could be obtained or found on file for at least one pair, so documents in those '.
                  'currencies will be refused until one is recorded'
                : 'the feeds could not supply at least one rate, so trading continues on the last known one - '.
                  'which is deliberate, and which every document using it is flagged for',
        );

        Log::warning('Exchange rate fetch was '.$outcome, [
            'run_id' => $runId,
            'rate_set' => $rateSet,
            'as_of' => $asOf->toDateString(),
            'from_fallback' => $fromFallback,
            'unresolved' => $unresolved,
            'provider_failures' => $failures,
        ]);
    }
}
