<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use Illuminate\Support\Facades\Event;

/**
 * Catch the events an act raises, and say so - PF-048.
 *
 * ## Why a listener and not `Event::fake()`
 *
 * Because a fake replaces the dispatcher, and every financial event in this
 * product is raised INSIDE the transaction that caused it, next to a posting
 * that may have its own listener. Faking the dispatcher would silence those
 * too, so a check that asserted "issuing raises `InvoiceIssued`" would be
 * asserting it against a code path where nothing else fires - which is not
 * the path the product runs.
 *
 * A real listener, added and removed around the act, proves the raise point
 * is reached with everything else still wired.
 *
 * ## What it does NOT prove
 *
 * That a listener elsewhere would receive it. That is `Event::listen` in a
 * service provider and it is `WiringRules`' question. This one answers the
 * narrower and more easily broken half: the service actually dispatches,
 * with the payload it claims.
 */
trait CapturesEvents
{
    /**
     * Run `$act` with a listener on `$class`, and return what arrived.
     *
     * `Event::forget` afterwards in a `finally`, because a check that throws
     * and leaves a closure subscribed makes the NEXT check in the same suite
     * see events it did not cause - and the failure lands on the innocent
     * one.
     *
     * @template T
     *
     * @param  class-string  $class
     * @param  callable(): mixed  $act
     * @return list<object>
     */
    protected function captureEvents(string $class, callable $act): array
    {
        $caught = [];

        Event::listen($class, static function (object $event) use (&$caught): void {
            $caught[] = $event;
        });

        try {
            $act();
        } finally {
            Event::forget($class);
        }

        return $caught;
    }

    /**
     * The usual assertion: exactly one of `$class` arrived, carrying `$expected`.
     *
     * Counted rather than merely found, because "at least one" would pass on
     * a service that raises the event twice - once per allocation, say - and
     * a listener that posts would then post twice.
     *
     * @param  class-string  $class
     * @param  callable(): mixed  $act
     * @param  array<string, string>  $expected  property => stringified value
     * @return list<string>
     */
    protected function oneEventCarrying(string $class, callable $act, array $expected): array
    {
        $violations = [];
        $short = class_basename($class);
        $caught = $this->captureEvents($class, $act);

        if (\count($caught) !== 1) {
            return [sprintf(
                'the act raised %s %d times, expected exactly once. An event nobody raises is a '.
                'declaration; one raised twice makes every posting listener post twice',
                $short,
                \count($caught),
            )];
        }

        $event = $caught[0];

        if (! $event instanceof \App\Support\Events\FinancialEvent) {
            $violations[] = sprintf(
                '%s does not implement FinancialEvent, so nothing forces its posting to be '.
                'synchronous and nothing stops a listener queueing it',
                $short,
            );
        } elseif (trim($event->eventKey()) === '' || trim($event->entityId()) === '') {
            $violations[] = sprintf(
                '%s arrived with an empty %s. The audit entry its posting writes reads that',
                $short,
                trim($event->eventKey()) === '' ? 'eventKey' : 'entityId',
            );
        }

        foreach ($expected as $property => $want) {
            $got = $event->{$property} ?? null;
            $got = \is_object($got) ? (string) $got : (string) $got;

            if ($got !== $want) {
                $violations[] = sprintf(
                    '%s->%s is "%s", expected "%s"',
                    $short,
                    $property,
                    $got,
                    $want,
                );
            }
        }

        return $violations;
    }
}
