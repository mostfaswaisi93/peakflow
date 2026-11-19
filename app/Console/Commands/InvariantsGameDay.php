<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\InvariantInjection;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Alerts\InvariantWatch;
use App\Support\Telemetry\Alerts\InvariantWatcher;
use App\Support\Telemetry\Sinks\CollectingAlertSink;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * `invariants:game-day` - breaking each invariant on purpose - PF-242.
 *
 * ## "An alert that has never fired is a configuration somebody hopes is
 * right"
 *
 * Which is PF-242's last criterion and the reason this exists. Each condition
 * is injected the way production produces it - a stored balance moved, a line
 * added to a posted entry, a fingerprint that no longer matches, a grant the
 * application role should not hold - and the alert has to fire AND name the
 * entity, the period and the first divergent record.
 *
 * ## Into a sink of its own, so a game day does not page anybody
 *
 * The alerts are collected rather than raised through the configured sink. A
 * release exercise that woke whoever is on call would be a release exercise
 * nobody runs twice.
 *
 * ## Every injection is rolled back, and the exercise refuses production
 *
 * Each one runs inside a savepoint on the tenant connection and the savepoint
 * is rolled back whether the alert fired or not. The one injection that needs
 * a second connection - the privilege grant, which the application role
 * cannot make for itself - hands its own rollback back and it is called in
 * the same `finally`.
 *
 * `InvariantInjection` refuses to run outside a development or staging
 * environment, and there is deliberately no flag to force it.
 *
 * ## "Could not be injected here" is reported, never counted as a pass
 *
 * A tenant with no sealed archive period cannot have an archive checksum
 * mismatch injected into it. That is a fact about the tenant and it is
 * printed as one - because a game day that reported nine of nine when it
 * exercised four would be the most misleading output in the product.
 */
final class InvariantsGameDay extends Command
{
    protected $signature = 'invariants:game-day
        {tenant : A tenant slug or id}
        {--only= : One condition key}';

    protected $description = 'Inject each invariant breach and prove the alert fires and names the thing';

    public function handle(
        InvariantWatch $watch,
        InvariantInjection $injection,
        TenantConnectionResolver $resolver,
    ): int {
        /*
         | The needle is read out BEFORE the closure.
         |
         | `static fn ($q) => $q->where('slug', $this->argument(...))` binds
         | no `$this`, and the failure is "Using $this when not in object
         | context" at the moment the query is built - which is a runtime
         | error in a command nothing was calling, found by running it.
         */
        $needle = (string) $this->argument('tenant');

        $tenant = Tenant::query()
            ->with('connection')
            ->whereHas('connection')
            ->where(static fn ($q) => $q->where('slug', $needle)->orWhere('id', $needle))
            ->first();

        if ($tenant === null) {
            $this->components->error('No servable tenant matched.');

            return self::FAILURE;
        }

        $resolver->point($tenant);

        $entity = Entity::query()->orderBy('code')->first();

        if ($entity === null) {
            $this->components->error(
                'This tenant has no entity, so there are no books to break. A game day against an '.
                'empty tenant proves that nothing is wrong with nothing.',
            );

            return self::FAILURE;
        }

        app(EntityContext::class)->use($entity);

        $only = $this->option('only');
        $conditions = InvariantInjection::conditions();

        if ($only !== null && $only !== '') {
            if (! isset($conditions[$only])) {
                $this->components->error(sprintf(
                    'No injector for "%s". Available: %s.',
                    (string) $only,
                    implode(', ', array_keys($conditions)),
                ));

                return self::FAILURE;
            }

            $conditions = [$only => $conditions[$only]];
        }

        $rows = [];
        $silent = 0;
        $skipped = 0;

        /*
         | ONE transaction around the whole exercise, rolled back always.
         |
         | Each condition then runs in a savepoint inside it, which is what
         | lets one injection fail - a constraint refusing it is a normal
         | outcome here - without aborting the ones after it. PostgreSQL
         | aborts the whole transaction on a failed statement, so a game day
         | sharing one transaction with no savepoints would report one real
         | result and seven "current transaction is aborted".
         |
         | And `SAVEPOINT` outside a transaction block is an error, which is
         | how the first run of this ended: eight conditions, no outer
         | transaction, and a stack trace instead of a table.
         */
        $connection = \Illuminate\Support\Facades\DB::connection('tenant');
        $connection->beginTransaction();

        try {
            foreach ($conditions as $condition => $what) {
                unset($what);

                $outcome = $this->exercise(
                    $watch,
                    $injection,
                    $condition,
                    (string) $tenant->slug,
                    (string) $entity->getKey(),
                );

                if ($outcome['skipped']) {
                    $skipped++;
                } elseif (! $outcome['fired']) {
                    $silent++;
                }

                $rows[] = [
                    $condition,
                    $outcome['skipped'] ? '<fg=gray>not injectable</>' : ($outcome['fired'] ? '<fg=green>fired</>' : '<fg=red>SILENT</>'),
                    $outcome['detail'],
                ];
            }
        } finally {
            /*
             | Rolled back whether every alert fired or none did.
             |
             | A game day that left its damage behind would be worse than no
             | game day: the next thing to read these books would find an
             | unbalanced entry and a corrupted fingerprint, and nothing
             | would say they were deliberate.
             */
            $connection->rollBack();

            app(EntityContext::class)->forget();
        }

        $this->newLine();
        $this->table(['condition', 'alert', 'what happened'], $rows);

        if ($skipped > 0) {
            $this->components->warn(sprintf(
                '%d condition(s) could not be injected against this tenant and were NOT exercised. A '.
                'game day that counted those as passes would be the most misleading output in the '.
                'product.',
                $skipped,
            ));
        }

        if ($silent > 0) {
            $this->components->error(sprintf(
                '%d invariant(s) were broken and no alert fired. An alert that cannot fire looks '.
                'exactly like coverage, costs nothing to run, and is discovered during the incident '.
                'it was built for.',
                $silent,
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d condition(s) injected; every one of them fired and named the entity, the period and '.
            'the first divergent record. Runbooks: docs/operations/runbooks.md',
            count($conditions) - $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{fired: bool, skipped: bool, detail: string}
     */
    private function exercise(
        InvariantWatch $watch,
        InvariantInjection $injection,
        string $condition,
        string $tenant,
        string $entityId,
    ): array {
        $connection = \Illuminate\Support\Facades\DB::connection('tenant');
        $savepoint = 'gameday_'.bin2hex(random_bytes(4));
        $rollback = null;

        $connection->statement('SAVEPOINT '.$savepoint);

        try {
            $result = $injection->inject($condition, $entityId);
            $rollback = $result['rollback'];

            if (! $result['injected']) {
                return ['fired' => false, 'skipped' => true, 'detail' => $result['why']];
            }

            $sink = new CollectingAlertSink();

            /*
             | The watcher the injection names, when it names one.
             |
             | The privilege grant is uncommitted on a second session, so only
             | a watcher bound to that session can see it. Every other
             | condition is visible to the registered watchers.
             */
            $watchers = $result['watcher'] instanceof InvariantWatcher
                ? [$result['watcher']]
                : $watch->all();

            foreach ($watchers as $watcher) {
                if (! in_array($condition, $watcher->keys(), true)) {
                    continue;
                }

                foreach ($watch->run($watcher, $tenant, $entityId) as $alert) {
                    $sink->raise($alert);
                }
            }

            $fired = $sink->of($condition);

            if ($fired === []) {
                return [
                    'fired' => false,
                    'skipped' => false,
                    'detail' => sprintf(
                        '%s and no "%s" alert was raised. Raised instead: %s',
                        $result['why'],
                        $condition,
                        $sink->keys() === [] ? '(nothing)' : implode(', ', $sink->keys()),
                    ),
                ];
            }

            $alert = $fired[0];
            $unnamed = $this->whatIsMissing($alert);

            if ($unnamed !== []) {
                return [
                    'fired' => false,
                    'skipped' => false,
                    'detail' => sprintf(
                        'the alert fired and names no %s, so it sends whoever is woken up to find it',
                        implode(' and no ', $unnamed),
                    ),
                ];
            }

            return [
                'fired' => true,
                'skipped' => false,
                'detail' => sprintf('%s · first divergence: %s', $alert->period, $alert->firstDivergence),
            ];
        } catch (Throwable $e) {
            return [
                'fired' => false,
                'skipped' => false,
                'detail' => 'the injection itself failed: '.$e->getMessage(),
            ];
        } finally {
            if ($rollback !== null) {
                try {
                    $rollback();
                } catch (Throwable) {
                    // The second connection's transaction is already closed.
                }
            }

            $connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }
    }

    /**
     * What an alert fails to name.
     *
     * The sentinels count as named: "this is not period-bound" is a decision
     * somebody took, and it is the thing that distinguishes it from "we did
     * not record which period".
     *
     * @return list<string>
     */
    private function whatIsMissing(Alert $alert): array
    {
        $missing = [];

        if (trim($alert->entityId) === '') {
            $missing[] = 'entity';
        }

        if (trim($alert->period) === '') {
            $missing[] = 'period';
        }

        if (trim($alert->firstDivergence) === '') {
            $missing[] = 'first divergent record';
        }

        return $missing;
    }
}
