<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Metrics;

use App\Support\Money\Money;
use App\Support\Telemetry\Metrics\AgeBucket;
use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use App\Tenant\Settlement\Services\TransitClearing;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;

/**
 * Money that left and has not appeared on a statement, by age - PF-241.
 *
 * ## What an aged in-transit item actually is
 *
 * A payment the bank was instructed to make, that nothing has cleared. For a
 * day or two that is normal and is what the in-transit account is for. At two
 * weeks it is one of three things, and each is somebody's problem: a file
 * that was generated and never handed to the bank, a payment the bank
 * rejected without telling us, or a statement line nobody matched.
 *
 * All three end the same way - a supplier who has not been paid and a ledger
 * that says they have.
 *
 * ## The PARTY is deliberately not a label
 *
 * `TransitClearing::aged()` returns the beneficiary's legal name, because the
 * screen that lists aged items has to name who is waiting. A metric must not:
 * one series per beneficiary is a supplier list on whoever's dashboard,
 * retained for weeks, and `Metrics::register()` refuses the label outright.
 * The dashboard says how much is aged; the register says to whom.
 */
final class InTransitAgeing implements MetricSource
{
    public function __construct(private readonly TransitClearing $transit) {}

    public function key(): string
    {
        return 'in_transit_ageing';
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        return [Audience::Controller];
    }

    public function scope(): MetricScope
    {
        return MetricScope::Entity;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return ['entity_id', 'age_bucket', 'currency'];
    }

    public function unit(): string
    {
        return 'money';
    }

    public function describe(string $locale): string
    {
        return $locale === 'ar'
            ? 'مبالغ قيد التحويل، بحسب عمرها'
            : 'Payments in transit, by age';
    }

    /** @return list<MetricSample> */
    public function collect(?string $entityId): array
    {
        $entities = Entity::query()
            ->when($entityId !== null, static fn ($query) => $query->whereKey($entityId))
            ->orderBy('code')
            ->get();

        /** @var array<string, Money> $totals */
        $totals = [];

        foreach ($entities as $entity) {
            /*
             | Every open item, not only the aged ones.
             |
             | A null floor is what `aged()` documents for exactly this: an
             | ageing whose first bucket is missing shows a position whose
             | newest items are absent, and "nothing is aged" then reads the
             | same as "nothing is in transit".
             */
            foreach ($this->transit->aged((string) $entity->getKey(), null, Carbon::now()) as $item) {
                $amount = $item['amount'];

                if (! $amount instanceof Money) {
                    continue;
                }

                $key = implode('|', [
                    (string) $entity->getKey(),
                    AgeBucket::of((int) $item['age_in_days']),
                    (string) $amount->currency,
                ]);

                $totals[$key] = isset($totals[$key])
                    ? $totals[$key]->plus($amount)
                    : $amount;
            }
        }

        $samples = [];

        foreach ($totals as $key => $total) {
            [$entity, $bucket, $currency] = explode('|', $key, 3);

            $samples[] = MetricSample::amount([
                'entity_id' => $entity,
                'age_bucket' => $bucket,
                'currency' => $currency,
            ], $total->abs()->amount);
        }

        return $samples;
    }
}
