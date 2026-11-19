<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Http\Controllers\Api;

use App\Support\Http\Api\Wire;
use App\Tenant\Reporting\Overview\ActivityItem;
use App\Tenant\Reporting\Overview\Ageing;
use App\Tenant\Reporting\Overview\OverviewQuery;
use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The Overview, over the wire - PF-261.
 *
 * The same figures as the browser's home page, from the same query - so a
 * phone and a desk can never disagree about how much cash there is. The
 * screen draws them; this hands them over as data, and the app draws them.
 *
 * ## Every amount is Money, including every point of the cash series
 *
 * Thirty objects where thirty strings would do, deliberately: "money is
 * always an object" is a rule a client can rely on only if it has no
 * exceptions, and the chart is exactly where somebody would reach for a float.
 *
 * ## `report.view`, and nothing finer
 *
 * The home page gates its figures on it, and this is the same page.
 */
final class OverviewApiController
{
    public function __construct(
        private readonly OverviewQuery $query,
        private readonly EntityContext $entities,
    ) {}

    public function __invoke(): JsonResponse
    {
        Gate::authorize('report.view');

        $entity = $this->entities->entity();

        if (! $entity instanceof Entity) {
            throw EntityNotInScope::forQueryOn(Entity::class);
        }

        $today = CarbonImmutable::today();
        $overview = $this->query->for($entity, $today);
        $currency = $overview->currency;

        $cash = $overview->cash;
        $points = \count($cash->series);

        return new JsonResponse([
            'resource' => 'overview',
            'entity_id' => $entity->id,
            'currency' => $currency,
            'as_of' => Wire::accountingDate($today),

            'cash' => [
                'balance' => Wire::amount($cash->balance, $currency),
                'balance_then' => $cash->balanceThen === null ? null : Wire::amount($cash->balanceThen, $currency),
                'days' => $cash->days,
                // A ratio, not money, and a string for the same reason money
                // is: a percentage the client re-rounds is a different number.
                'change_percent' => $cash->changePercent(),
                'direction' => $cash->direction(),
                'has_activity' => $cash->hasActivity,
                'series' => array_values(array_map(
                    static fn (string $balance, int $index): array => [
                        'date' => Wire::accountingDate($today->subDays($points - 1 - $index)),
                        'balance' => Wire::amount($balance, $currency),
                    ],
                    $cash->series,
                    array_keys($cash->series),
                )),
            ],

            'receivables' => $this->ageing($overview->receivables, $currency),

            'payables' => [
                'total' => Wire::amount($overview->payables->total, $currency),
                'overdue' => Wire::amount($overview->payables->overdue, $currency),
                'due_this_week' => Wire::amount($overview->payables->dueThisWeek, $currency),
                'documents' => $overview->payables->documents,
                'due_this_week_documents' => $overview->payables->dueThisWeekDocuments,
                'other_currency_documents' => $overview->payables->otherCurrencyDocuments,
            ],

            'reconciliation' => [
                'matched' => $overview->reconciliation->matched,
                'suggested' => $overview->reconciliation->suggested,
                'waiting' => $overview->reconciliation->waiting,
                'days' => $overview->reconciliation->days,
                'percent_matched' => $overview->reconciliation->percentMatched(),
            ],

            'drafts' => [
                'invoices' => $overview->draftInvoices,
                'bills' => $overview->draftBills,
                'journals' => $overview->draftJournals,
            ],

            'tax' => $overview->tax === null ? null : [
                'label' => $overview->tax->label,
                'due_on' => Wire::accountingDate($overview->tax->dueOn),
                'overdue' => $overview->tax->isOverdue(),
                'days_left' => $overview->tax->daysLeft(),
            ],

            'activity' => array_values(array_map(
                static fn (ActivityItem $item): array => [
                    'actor' => $item->actor,
                    'action' => $item->action,
                    'subject' => $item->subject,
                    'at' => Wire::timestamp($item->at),
                ],
                $overview->activity,
            )),
        ]);
    }

    /** @return array<string, mixed> */
    private function ageing(Ageing $ageing, string $currency): array
    {
        $buckets = [];

        foreach ($ageing->buckets as $bucket => $amount) {
            $buckets[] = ['bucket' => (string) $bucket, 'amount' => Wire::amount($amount, $currency)];
        }

        return [
            'total' => Wire::amount($ageing->total(), $currency),
            'overdue' => Wire::amount($ageing->overdue(), $currency),
            'documents' => $ageing->documents,
            'other_currency_documents' => $ageing->otherCurrencyDocuments,
            'buckets' => $buckets,
        ];
    }
}
