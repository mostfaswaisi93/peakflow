<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Services\Quotes;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Close quotes whose validity has run out - PF-089.
 *
 * ## Why the status is set by a job and the answer is not
 *
 * A quote whose last day was yesterday is not acceptable this morning,
 * whatever the column says - so `Quote::isActionable()` reads the date and
 * the public page refuses on it. This job exists to make the state visible:
 * a list of open quotes that includes fifty expired ones is a list nobody
 * reads, and "why is this still showing as sent" is the question it
 * prevents.
 *
 * It prints what it closed rather than a count, because a job that reports
 * "17 quotes expired" is a job nobody can check.
 */
final class QuotesExpire extends Command
{
    protected $signature = 'quotes:expire {tenant? : the tenant id or slug, or --all} {--all}';

    protected $description = 'Close quotes whose validity date has passed';

    public function handle(TenantConnectionResolver $resolver, EntityContext $entities): int
    {
        $tenants = $this->tenants();

        if ($tenants === []) {
            $this->error('Name a tenant, or pass --all.');

            return self::FAILURE;
        }

        $closed = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            foreach (Entity::query()->where('active', true)->orderBy('code')->get() as $entity) {
                $entities->use($entity);

                try {
                    foreach (app(Quotes::class)->expire() as $quote) {
                        $closed++;

                        $this->line(sprintf(
                            '  <fg=yellow>%s</> %s — valid until %s, %s',
                            (string) $tenant->slug,
                            $quote['reference'],
                            $quote['valid_until'],
                            $quote['gross'],
                        ));
                    }
                } catch (Throwable $e) {
                    $this->error(sprintf('  %s %s: %s', (string) $tenant->slug, (string) $entity->code, $e->getMessage()));

                    return self::FAILURE;
                }
            }
        }

        $this->info($closed === 0
            ? 'No quote has lapsed.'
            : sprintf('%d quote(s) closed.', $closed));

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
