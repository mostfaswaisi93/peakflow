<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Alerts;

use InvalidArgumentException;
use Throwable;

/**
 * The registered invariant watchers - PF-242.
 *
 * A singleton each module registers into, the same shape as `ClosingChecks`,
 * `LedgerChecks` and the posting rules - and for the same reason. "A subledger
 * no longer equals its control account" is a ledger fact, "suspense is above
 * a threshold" is a banking one and "a role holds a privilege it should not"
 * is a platform one; a watch service that asked each module directly would
 * depend on all of them.
 *
 * ## A watcher that THROWS raises an alert of its own
 *
 * This is the part that matters most and it is the easiest to leave out. A
 * watch that failed silently is indistinguishable from a watch that found
 * nothing, and the second is the reassuring reading - so the hour the
 * database refused a query is the hour every invariant reports as healthy.
 *
 * The same reasoning as `LedgerChecks::findings()` and
 * `ClosingChecks::reasonsToRefuse()`, which both turn a throwing check into a
 * finding. Here it turns into `watch_failed`, which has its own runbook entry,
 * because "the watch could not run" is an incident in a product whose alerts
 * are the only thing looking at the invariants.
 *
 * ## Registration refuses an undeclared key
 *
 * At boot, loudly. `bin/verify observability` asserts that every declared key
 * has a runbook entry with a tested, non-destructive first response - so a
 * watcher raising an undeclared key would be an alert nobody can respond to,
 * and it would look exactly like one somebody can.
 */
final class InvariantWatch
{
    /** @var list<InvariantWatcher> */
    private array $watchers = [];

    public function register(InvariantWatcher $watcher): void
    {
        $declared = Alert::declaredKeys();

        if ($watcher->keys() === []) {
            throw new InvalidArgumentException(sprintf(
                '%s declares no alert key. A watcher that raises nothing is a query that runs every '.
                'hour for nothing.',
                $watcher::class,
            ));
        }

        foreach ($watcher->keys() as $key) {
            if (! in_array($key, $declared, true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s raises "%s", which is not declared in config/telemetry.php. Every declared key '.
                    'has a runbook entry with a tested first response; an undeclared one is an alert '.
                    'whose reader has nothing to do next, and it looks exactly like one that has.',
                    $watcher::class,
                    $key,
                ));
            }
        }

        $this->watchers[] = $watcher;
    }

    /** @return list<InvariantWatcher> */
    public function all(): array
    {
        return $this->watchers;
    }

    /** @return list<InvariantWatcher> */
    public function inScope(WatchScope $scope): array
    {
        return array_values(array_filter(
            $this->watchers,
            static fn (InvariantWatcher $watcher): bool => $watcher->scope() === $scope,
        ));
    }

    /**
     * Every key any registered watcher can raise.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->watchers as $watcher) {
            foreach ($watcher->keys() as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Run one watcher, turning a failure into an alert rather than silence.
     *
     * @return list<Alert>
     */
    public function run(InvariantWatcher $watcher, string $tenant, ?string $entityId): array
    {
        try {
            return $watcher->watch($tenant, $entityId);
        } catch (Throwable $e) {
            return [Alert::critical(
                key: 'watch_failed',
                tenant: $tenant,
                entityId: $entityId ?? Alert::NO_ENTITY,
                period: Alert::NO_PERIOD,
                firstDivergence: $watcher::class,
                messageAr: sprintf(
                    'تعذّر تشغيل مراقب الثوابت %s: %s. مراقبة فشلت بصمت لا تختلف عن مراقبة لم تجد شيئاً، '.
                    'والقراءة المطمئنة هي الثانية.',
                    $watcher::class,
                    $e->getMessage(),
                ),
                messageEn: sprintf(
                    'The invariant watcher %s could not run: %s. A watch that failed silently is '.
                    'indistinguishable from a watch that found nothing, and the second is the '.
                    'reassuring reading - so the hour this was broken is the hour every invariant '.
                    'reported as healthy.',
                    $watcher::class,
                    $e->getMessage(),
                ),
            )];
        }
    }
}
