<?php

declare(strict_types=1);

/*
 | One API worker - PF-220.
 |
 | Spawned by `Tests\Support\ApiContractRules` as a separate OS process,
 | because that is the only way to get two transactions contending for one
 | unique index at the same instant. A loop in one process proves that the
 | idempotency store can count, which nobody doubted; the failure this exists
 | to catch is two requests arriving together and both posting.
 |
 |   php tests/Support/Concurrency/api-worker.php <tenant> <bearer> <entity> <key> <start-at> <payload-json>
 |
 | `start-at` is a `microtime(true)` value both workers sleep until, so they
 | enter the claim within a millisecond of each other rather than whenever the
 | shell happened to finish booting PHP. Without the barrier the second worker
 | usually starts after the first has committed - which the suite's assertions
 | still accept, and which would mean the race was never actually run.
 |
 | One JSON object on stdout and nothing else: the parent reads it.
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

[$script, $tenantId, $bearer, $entityId, $key, $startAt, $payload] = $argv + array_fill(0, 7, '');

$tenant = App\Platform\Models\Tenant::query()->findOrFail((string) $tenantId);
app(App\Platform\Services\TenantConnectionResolver::class)->point($tenant);

/*
 | The barrier.
 |
 | Busy-waiting on the last few milliseconds rather than sleeping through
 | them: `usleep` rounds, and the point of the barrier is that both workers
 | reach the claim inside the window in which one of them is still
 | uncommitted.
 */
$target = (float) $startAt;

while (microtime(true) < $target - 0.005) {
    usleep(1_000);
}

while (microtime(true) < $target) {
    // Spin.
}

$request = Illuminate\Http\Request::create(
    '/api/v1/journal-entries',
    'POST',
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    (string) $payload,
);

$request->headers->set('Authorization', 'Bearer '.$bearer);
$request->headers->set('X-Tenant', (string) $tenantId);
$request->headers->set('X-Entity', (string) $entityId);
$request->headers->set('Idempotency-Key', (string) $key);

try {
    $response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);

    echo json_encode([
        'status' => $response->getStatusCode(),
        'body' => (string) $response->getContent(),
        'replay' => (string) $response->headers->get('Idempotent-Replay'),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 0,
        'body' => '',
        'error' => $e::class.': '.$e->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
