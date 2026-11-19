<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Alerts;

use App\Support\Money\Money;
use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Alerts\InvariantWatcher;
use App\Support\Telemetry\Alerts\WatchScope;
use App\Tenant\Banking\Services\Suspense;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Tenancy\Models\Entity;

/**
 * Suspense crossing a threshold - PF-242.
 *
 * ## Why a threshold and not "anything in suspense"
 *
 * Because a few hundred in suspense on the day a statement is imported is
 * ordinary - the matching engine has not run, or the remittance advice has
 * not arrived - and an alert that fires every morning is an alert nobody
 * reads. The threshold is the figure a controller would have chosen
 * themselves, which is why it is an amount in the functional currency and not
 * a percentage of anything.
 *
 * ## Compared as decimals, which is not pedantry here
 *
 * The balance comes out of `numeric` and the threshold out of configuration.
 * Through a float the comparison disagrees with the ledger at the fourth
 * decimal, and on a boundary that is the difference between an alert firing
 * and not - which is the one case where the comparison is being asked to
 * decide something.
 *
 * ## The oldest item is the first divergent record
 *
 * Not the biggest. The question a first response asks is how long this has
 * been sitting, because anything in suspense could be a sale - which would
 * mean revenue missing from these accounts and output tax missing from the
 * return - and the oldest one dates the failure.
 */
final class SuspenseThresholdWatcher implements InvariantWatcher
{
    public function __construct(private readonly Suspense $suspense) {}

    /** @return list<string> */
    public function keys(): array
    {
        return ['suspense_above_threshold'];
    }

    public function scope(): WatchScope
    {
        return WatchScope::Entity;
    }

    /** @return list<Alert> */
    public function watch(string $tenant, ?string $entityId): array
    {
        if ($entityId === null) {
            return [];
        }

        $entity = Entity::query()->whereKey($entityId)->first();

        if ($entity === null) {
            return [];
        }

        $currency = (string) $entity->functional_currency;
        $balance = $this->suspense->balance($entityId, $currency)->abs();
        $threshold = Money::of((string) config('telemetry.thresholds.suspense', '5000.0000'), $currency);

        if (! $balance->isGreaterThan($threshold)) {
            return [];
        }

        $ageing = $this->suspense->ageing($entityId, $currency);
        $items = is_array($ageing['items'] ?? null) ? $ageing['items'] : [];
        $oldest = $items[0] ?? null;

        return [Alert::critical(
            key: 'suspense_above_threshold',
            tenant: $tenant,
            entityId: $entityId,
            period: $this->openPeriod($entityId),
            firstDivergence: is_array($oldest)
                ? sprintf(
                    'the oldest parked item %s, booked %s, %s day(s) old',
                    (string) ($oldest['id'] ?? '(no id)'),
                    (string) ($oldest['booking_date'] ?? '(no date)'),
                    (string) ($oldest['age_days'] ?? '?'),
                )
                : 'the suspense control account holds a balance no parked item accounts for',
            messageAr: sprintf(
                'رصيد حساب الأمانات %s تجاوز الحد %s. أي مبلغ هناك قد يكون بيعاً، وهذا يعني إيراداً '.
                'غائباً عن الحسابات وضريبة مخرجات غائبة عن الإقرار. أقدم بند عمره %s يوماً. لا تُسوِّ '.
                'الرصيد: طابِق البنود أو اعتمدها باعتماد مكتوب.',
                (string) $balance->toPresentation(),
                (string) $threshold->toPresentation(),
                (string) ($ageing['oldest_days'] ?? '?'),
            ),
            messageEn: sprintf(
                'Suspense holds %s against a threshold of %s. Anything in there could be a sale, which '.
                'would mean revenue missing from these accounts and output tax missing from the '.
                'return. The oldest item is %s day(s) old. Do NOT clear the balance to make it go '.
                'away: match the items, or have a controller sign the figure off with a reason.',
                (string) $balance->toPresentation(),
                (string) $threshold->toPresentation(),
                (string) ($ageing['oldest_days'] ?? '?'),
            ),
            observed: (string) $balance->amount,
            threshold: (string) $threshold->amount,
        )];
    }

    private function openPeriod(string $entityId): string
    {
        $period = Period::query()
            ->where('entity_id', $entityId)
            ->where('state', 'open')
            ->orderBy('starts_on')
            ->first();

        return $period === null
            ? '(no open period: every correction here is a restatement)'
            : (string) $period->label;
    }
}
