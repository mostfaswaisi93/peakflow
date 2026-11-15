<?php

declare(strict_types=1);

/*
 | One concurrency worker - PF-094.
 |
 | Spawned by `Tests\Support\ConcurrencyRules` as a separate OS process,
 | because that is the only way to get twenty transactions contending for one
 | row at the same instant. A single-process check with a loop proves that the
 | allocator can count, which nobody doubted.
 |
 |   php tests/Support/Concurrency/issue-worker.php <tenant> <entity> <series> <count> <mode>
 |
 | Modes:
 |   ok                   issue and commit
 |   rollback-after-lock  issue, then roll back the surrounding transaction -
 |                        a failure PAST the allocation, which is the case a
 |                        sequence cannot survive
 |   crash-after-lock     issue, then die without committing, the way a worker
 |                        killed by the OOM killer or a deploy does
 |   recurring <id|date>  generate one occurrence of a recurring schedule -
 |                        two of these at once is the scheduler firing on two
 |                        nodes in the same minute
 |   credit-raw <draft>   issue a prepared credit-note draft with one raw
 |                        UPDATE, bypassing the issuer - the path that is NOT
 |                        serialised by the number series lock
 |   credit-full          draft a FULL credit note against the entity's first
 |                        issued invoice and issue it - two of these at once
 |                        is what proves the over-credit cap holds under
 |                        contention rather than only in one process
 |
 | One JSON object per attempt on stdout. Nothing else: the parent reads it.
 */

$base = \dirname(__DIR__, 3);

require $base.'/vendor/autoload.php';

// tests/ is in autoload-dev, which may not be dumped - the same reason
// bin/verify registers this.
spl_autoload_register(static function (string $class) use ($base): void {
    if (! str_starts_with($class, 'Tests\\')) {
        return;
    }

    $path = $base.'/tests/'.str_replace('\\', '/', substr($class, 6)).'.php';

    if (is_file($path)) {
        require $path;
    }
});

$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$script, $tenantId, $entityId, $series, $count, $mode, $target] = $argv + [null, null, null, null, '1', 'ok', null];

$tenant = App\Platform\Models\Tenant::query()->findOrFail((string) $tenantId);
app(App\Platform\Services\TenantConnectionResolver::class)->point($tenant);

$entity = App\Tenant\Tenancy\Models\Entity::query()->findOrFail((string) $entityId);
app(App\Tenant\Tenancy\Services\EntityContext::class)->use($entity);

$type = App\Tenant\Sales\Enums\DocumentType::from($series === 'CRN' ? '381' : '380');
$date = Illuminate\Support\Carbon::parse('2026-05-18');
$issuer = app(App\Tenant\Sales\Services\Issuer::class);
$connection = Illuminate\Support\Facades\DB::connection('tenant');

$say = static function (array $line): void {
    echo json_encode($line, JSON_THROW_ON_ERROR), "\n";
};

for ($i = 0; $i < (int) $count; $i++) {
    try {
        if ($mode === 'recurring') {
            /*
             | One occurrence of one schedule, from a separate process.
             |
             | `$target` is "<scheduleId>|<date>". Two of these started at
             | once is the scheduler firing on two nodes in the same minute,
             | which is the scenario the unique index exists for.
             */
            [$scheduleId, $occurrence] = explode('|', (string) $target, 2) + ['', ''];

            $schedule = App\Tenant\Sales\Models\RecurringSchedule::query()->findOrFail($scheduleId);

            $outcome = app(App\Tenant\Sales\Services\RecurringGenerator::class)->generate(
                $schedule,
                Carbon\CarbonImmutable::parse($occurrence),
            );

            $say(['ok' => ($outcome['outcome'] ?? '') === 'generated'] + $outcome);

            continue;
        }

        if ($mode === 'credit-raw') {
            /*
             | One raw UPDATE, bypassing the issuer entirely.
             |
             | Every path through the issuer is serialised by the number
             | series lock before it reaches the over-credit trigger, so a
             | check that goes through it cannot tell a trigger that is
             | correct from one that is correct only because something else
             | serialised it. A second crediting path would not be
             | serialised, and this is that path.
             */
            Tests\Support\Concurrency\Burst::issueRawCredit((string) $target, 'CRN-RAW-'.getmypid());

            $say(['ok' => true, 'raw' => true]);

            continue;
        }

        if ($mode === 'credit-full') {
            $invoice = Tests\Support\Concurrency\Burst::uncreditedInvoice((string) $entityId);

            if ($invoice === null) {
                $say(['ok' => false, 'error' => 'no uncredited invoice to credit']);

                continue;
            }

            $credit = $issuer->issue(
                app(App\Tenant\Sales\Services\Crediting::class)->full($invoice, $date),
                $entity,
                str_repeat('0', 26),
                Tests\Support\Concurrency\Burst::decision(),
            );

            $say(['ok' => true, 'number' => (string) $credit->number, 'credited' => (string) $credit->gross_amount]);

            continue;
        }

        if ($mode === 'ok') {
            $invoice = $issuer->issue(
                Tests\Support\Concurrency\Burst::draft((string) $entityId, $type, $date),
                $entity,
                str_repeat('0', 26),
                Tests\Support\Concurrency\Burst::decision(),
            );

            $say(['ok' => true, 'number' => (string) $invoice->number, 'index' => (int) $invoice->chain_index]);

            continue;
        }

        /*
         | Everything else needs a transaction AROUND the issuer, so that its
         | own transaction becomes a savepoint and the number it allocated is
         | still inside an open transaction when this process stops.
         */
        $connection->beginTransaction();

        $invoice = $issuer->issue(
            Tests\Support\Concurrency\Burst::draft((string) $entityId, $type, $date),
            $entity,
            str_repeat('0', 26),
            Tests\Support\Concurrency\Burst::decision(),
        );

        $number = (string) $invoice->number;

        if ($mode === 'crash-after-lock') {
            $say(['ok' => false, 'crashed' => true, 'allocated' => $number]);

            /*
             | SIGKILL, not exit().
             |
             | `exit()` runs PHP's shutdown, and a shutdown handler that
             | commits would make this probe lie. SIGKILL cannot be handled,
             | so PostgreSQL sees the connection drop with a transaction open
             | and rolls it back - which is what a worker killed by the OOM
             | killer, a deploy or a dead pod looks like from the database.
             */
            if (\function_exists('posix_kill')) {
                posix_kill(getmypid(), 9);
            }

            exit(137);
        }

        $connection->rollBack();

        $say(['ok' => false, 'rolled_back' => true, 'allocated' => $number]);
    } catch (Throwable $e) {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $say(['ok' => false, 'error' => $e::class.': '.$e->getMessage()]);
    }
}
