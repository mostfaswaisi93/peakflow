<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Listeners;

use App\Tenant\Ledger\Services\ChartTemplate;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Tenancy\Events\EntityCreated;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opens a usable ledger for a new entity - PF-039, PF-049.
 *
 * ## Synchronous, and inside the creating transaction
 *
 * Not queued. An entity that exists without a chart is an entity nobody can
 * post to, and the gap between creating it and the queue catching up is a
 * window in which the product is broken for that customer in a way they
 * cannot diagnose.
 *
 * If this fails, the entity creation fails with it - which is correct. A half
 * -created entity is worse than none: the user retries, the slug is taken,
 * and they end up with two.
 *
 * ## Two years, not one
 *
 * The current fiscal year and the next. A tenant blocked from posting on the
 * second of January because nobody created the year is a support call on the
 * worst possible morning, and creating a fiscal year is not something the
 * person on the phone is allowed to do.
 */
final class OpenLedgerForEntity
{
    public function __construct(
        private readonly ChartTemplate $chart,
        private readonly FiscalCalendarGenerator $calendar,
        private readonly EntityContext $entities,
    ) {}

    public function handle(EntityCreated $event): void
    {
        $entityId = (string) $event->entity->getKey();

        /*
         | Inside the new entity's own scope.
         |
         | The chart template queries `accounts`, which carries the entity
         | global scope - and at the moment an entity is created there is no
         | entity established, so the scope refuses rather than returning
         | every entity's rows. That refusal is correct and this is the right
         | answer to it: say which entity we are acting in.
         |
         | `during()` restores whatever was in scope before, so creating an
         | entity from inside a session working in another one does not
         | silently move the user.
         */
        $this->entities->during($event->entity, function () use ($entityId): void {
            /*
             | All or nothing.
             |
             | A chart with half its accounts is worse than none: the missing
             | ones are discovered one failed posting at a time, over weeks.
             |
             | The ENTITY itself is not covered by this transaction - Laravel
             | fires `created` after the insert, so unless the caller wrapped
             | the creation, a failure here leaves an entity with no ledger.
             | Callers that care about that wrap both; the chart template and
             | the calendar generator are both idempotent, so re-running the
             | open on such an entity repairs it.
             */
            DB::connection('tenant')->transaction(function () use ($entityId): void {
                $this->chart->apply($entityId);

        /*
         | Years start on 1 January by default, which is wrong for a great
         | many businesses - and deliberately left wrong rather than guessed.
         |
         | A fiscal year start is a decision with tax consequences, so the
         | entity's own setting governs it once set, and the default exists
         | only so the entity is postable on the day it is created. Changing
         | it afterwards regenerates the years, which is why nothing is
         | posted to them until a period is opened.
         */
                $start = Carbon::now()->startOfYear();

                foreach ([0, 1] as $offset) {
                    $yearStart = $start->copy()->addYears($offset);

                    $this->calendar->generate(
                        $entityId,
                        $yearStart->format('Y'),
                        $yearStart,
                    );
                }
            });
        });
    }
}
