<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Banking\Exceptions\MatchRefused;
use App\Tenant\Identity\Services\SodEngine;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A controller accepting an unexplained balance at a close - PF-163.
 *
 * ## Separated from matching, and that is the whole point
 *
 * The person who matched the bank lines decided which ones they could not
 * explain. Letting them also sign off that the leftovers are acceptable
 * makes the control a formality performed by the person it exists to check -
 * and an unexplained credit is the easiest place in an accounting system to
 * hide a receipt somebody took.
 *
 * So `suspense.sign_off` conflicts with `bank.reconcile` over the period.
 * Two people, which is the same principle as maker-checker on an outgoing
 * payment and is here for the same reason.
 *
 * ## It records the figure, not a flag
 *
 * What is being accepted is "17,400 across three items, the oldest 94 days
 * old". A boolean on the period would let a sign-off given against four
 * hundred riyals carry a close over forty thousand, because the thing that
 * changed was not the flag.
 */
final class SuspenseSignOff
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Suspense $suspense,
        private readonly SodEngine $sod,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws MatchRefused
     */
    public function sign(Period $period, string $userId, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 40) {
            throw MatchRefused::because(
                'Signing off an unexplained balance needs a real reason - at least a sentence. It '.
                'is read during an audit of a period that closed with money nobody could explain, '.
                'and "agreed" is the answer that makes that conversation worse.',
            );
        }

        $entityId = (string) $period->entity_id;
        $entity = Entity::query()->findOrFail($entityId);
        $currency = (string) $entity->functional_currency;

        $ageing = $this->suspense->ageing(
            $entityId,
            $currency,
            $period->ends_on->copy()->addDay()->toDateString(),
        );

        if ($ageing['total']->isZero()) {
            throw MatchRefused::because(
                'There is nothing in suspense for this period, so there is nothing to sign off. A '.
                'sign-off recorded against an empty balance would carry a later close over whatever '.
                'lands in suspense before it.',
            );
        }

        /*
         | Separation of duties, asserted here rather than on a route.
         |
         | The same argument as reopening a period: this method is reached
         | from a controller, a console command and whatever calls it next,
         | and a middleware protects only the first of those.
         |
         | Scoped to the period, so signing off March does not license
         | signing off December.
         */
        $this->sod->assert(
            duty: 'suspense.sign_off',
            subjectType: 'period',
            subjectId: (string) $period->getKey(),
            periodId: (string) $period->getKey(),
            subjectLabel: (string) $period->label,
        );

        return DB::connection('tenant')->transaction(function () use (
            $period,
            $entityId,
            $currency,
            $ageing,
            $userId,
            $reason,
        ): array {
            $id = (string) Str::ulid();

            /*
             | One sign-off per period, replaced rather than duplicated.
             |
             | A period signed off twice has been reopened in between, and
             | the second close needs its own decision about whatever is in
             | suspense by then. Two rows with nothing saying which applied
             | is the shape that makes an audit unanswerable.
             */
            DB::connection('tenant')->table('suspense_signoffs')
                ->where('entity_id', $entityId)
                ->where('period_id', (string) $period->getKey())
                ->delete();

            DB::connection('tenant')->table('suspense_signoffs')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'period_id' => (string) $period->getKey(),
                'period_label' => (string) $period->label,
                'balance' => (string) $ageing['total']->amount,
                'currency' => $currency,
                'items' => \count($ageing['items']),
                'reason' => $reason,
                'signed_by' => $userId,
                'signed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'banking',
                action: 'suspense.signed_off',
                subjectType: 'period',
                subjectId: (string) $period->getKey(),
                subjectLabel: (string) $period->label,
                after: [
                    'balance' => (string) $ageing['total']->amount,
                    'currency' => $currency,
                    'items' => \count($ageing['items']),
                    'oldest_days' => $ageing['oldest_days'],
                ],
                reason: $reason,
                entityId: $entityId,
                periodId: (string) $period->getKey(),
            );

            return [
                'id' => $id,
                'balance' => $ageing['total'],
                'items' => \count($ageing['items']),
                'why' => sprintf(
                    '%s across %d item%s signed off for %s. The period can close, and the record '.
                    'says who accepted what - if more lands in suspense before the close, the '.
                    'figures stop agreeing and the close is refused again.',
                    (string) $ageing['total']->toPresentation(),
                    \count($ageing['items']),
                    \count($ageing['items']) === 1 ? '' : 's',
                    (string) $period->label,
                ),
            ];
        });
    }

    /**
     * Every sign-off, for the report PF-163 asks for.
     *
     * @return list<array<string, mixed>>
     */
    public function report(?string $entityId = null): array
    {
        $rows = DB::connection('tenant')->table('suspense_signoffs')
            ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId))
            ->orderByDesc('signed_at')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'entity_id' => (string) $row->entity_id,
            'period' => (string) $row->period_label,
            'balance' => (string) $row->balance,
            'currency' => (string) $row->currency,
            'items' => (int) $row->items,
            'reason' => (string) $row->reason,
            'signed_by' => (string) $row->signed_by,
            'signed_at' => (string) $row->signed_at,
        ])->all();
    }
}
