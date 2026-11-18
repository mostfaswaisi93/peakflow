<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Remove idempotency records whose window has closed - PF-220.
 *
 * ## The retention is a promise, so something has to keep it
 *
 * "The response is stored and replayed for 24 hours" says two things, and the
 * second one is the one that needs a job: after 24 hours it is NOT stored. A
 * table nothing prunes keeps every response body for ever - amounts, party
 * names, the whole representation of a document - and the stored response was
 * only ever meant to answer a retry.
 *
 * {@see \App\Support\Http\Api\IdempotencyStore} also clears an expired record
 * for the key it is about to claim, which keeps the hot path correct. That is
 * not a substitute: it only ever touches the one key being used, so a key
 * nobody sends again is kept until this runs.
 *
 * ## Entity by entity, because the table is entity-scoped
 *
 * The rows are behind row-level security, and the application role is subject
 * to it - deliberately, since the application scope is not the only line of
 * defence. So a single `DELETE` with no entity established would match
 * nothing at all and report success, which is the worst kind of pruning job.
 *
 * Iterating entities is also what lets this clear a record one entity cannot
 * see from another: the unique index on `(token, key)` spans entities, so a
 * stale record in one company keeps that key refused in every other until it
 * is gone.
 *
 * It prints what it removed per entity rather than one total, because a job
 * that reports "1,284 records pruned" is a job nobody can check.
 */
final class ApiPruneIdempotency extends Command
{
    protected $signature = 'api:prune-idempotency {tenant? : the tenant id or slug, or --all} {--all}';

    protected $description = 'Remove API idempotency records whose 24-hour replay window has closed';

    public function handle(TenantConnectionResolver $resolver, EntityContext $entities): int
    {
        $tenants = $this->tenants();

        if ($tenants === []) {
            $this->error('Name a tenant, or pass --all.');

            return self::FAILURE;
        }

        $removed = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            /*
             | Every entity, including inactive ones.
             |
             | An entity that has been closed still has rows, and leaving them
             | behind would mean the table is pruned everywhere except in the
             | companies nobody is looking at.
             */
            foreach (Entity::query()->orderBy('code')->get() as $entity) {
                try {
                    $count = $entities->during($entity, static fn (): int => DB::connection('tenant')
                        ->table('api_idempotency_records')
                        ->where('expires_at', '<', now())
                        ->delete());
                } catch (Throwable $e) {
                    $this->error(sprintf(
                        '  %s %s: %s',
                        (string) $tenant->slug,
                        (string) $entity->code,
                        $e->getMessage(),
                    ));

                    return self::FAILURE;
                }

                if ((int) $count === 0) {
                    continue;
                }

                $removed += (int) $count;

                $this->line(sprintf(
                    '  <fg=yellow>%s</> %s — %d record(s) past their window',
                    (string) $tenant->slug,
                    (string) $entity->code,
                    (int) $count,
                ));
            }
        }

        $this->info($removed === 0
            ? 'No idempotency record has outlived its window.'
            : sprintf('%d record(s) removed.', $removed));

        return self::SUCCESS;
    }

    /** @return list<Tenant> */
    private function tenants(): array
    {
        if ((bool) $this->option('all')) {
            return Tenant::query()
                ->with('connection')
                ->whereNotNull('provisioned_at')
                ->orderBy('created_at')
                ->get()
                ->all();
        }

        $named = (string) ($this->argument('tenant') ?? '');

        if ($named === '') {
            return [];
        }

        $tenant = Tenant::query()
            ->with('connection')
            ->where('id', $named)
            ->orWhere('slug', $named)
            ->first();

        return $tenant === null ? [] : [$tenant];
    }
}
