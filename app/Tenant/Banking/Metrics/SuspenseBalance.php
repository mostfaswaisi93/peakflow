<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Metrics;

use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use App\Tenant\Banking\Services\Suspense;
use App\Tenant\Tenancy\Models\Entity;

/**
 * What is in suspense, per entity - PF-241.
 *
 * Rising suspense means matching is failing, before anybody complains. It is
 * also the figure that blocks a period close, so a controller watching it
 * weekly never meets it for the first time on the day they are trying to
 * close.
 *
 * ## Read from the ledger, not from the parked rows
 *
 * `Suspense::balance()` reads `journal_lines` on the suspense control
 * account, which is the same figure the trial balance shows. Counting the
 * parked items instead would produce a dashboard that agrees with itself and
 * disagrees with the general ledger - and the difference between those two is
 * precisely what `Suspense::reconciles()` exists to find, so a metric built
 * on the wrong one of them would hide it.
 */
final class SuspenseBalance implements MetricSource
{
    public function __construct(private readonly Suspense $suspense) {}

    public function key(): string
    {
        return 'suspense_balance';
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        return [Audience::Controller, Audience::Operator];
    }

    public function scope(): MetricScope
    {
        return MetricScope::Entity;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return ['entity_id', 'currency'];
    }

    public function unit(): string
    {
        return 'money';
    }

    public function describe(string $locale): string
    {
        return $locale === 'ar'
            ? 'رصيد حساب الأمانات لكل منشأة'
            : 'Suspense balance by entity';
    }

    /** @return list<MetricSample> */
    public function collect(?string $entityId): array
    {
        $entities = Entity::query()
            ->when($entityId !== null, static fn ($query) => $query->whereKey($entityId))
            ->orderBy('code')
            ->get();

        $samples = [];

        foreach ($entities as $entity) {
            $currency = (string) $entity->functional_currency;

            $samples[] = MetricSample::amount([
                'entity_id' => (string) $entity->getKey(),
                'currency' => $currency,
            ], $this->suspense->balance((string) $entity->getKey(), $currency)->abs()->amount);
        }

        return $samples;
    }
}
