<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Services\AuditChainVerifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-walks a tenant's audit chain and reports the first divergence - PF-035.
 *
 * Exits non-zero on a broken chain so it can be scheduled and alerted on.
 * A chain is only evidence if somebody checks it; one that is verified for the
 * first time during an investigation is a chain nobody has ever verified.
 */
final class AuditVerify extends Command
{
    protected $signature = 'audit:verify
        {tenant? : A tenant slug or id}
        {--all : Verify every servable tenant}
        {--limit= : Stop after this many records, for a quick check of the oldest part of the chain}';

    protected $description = 'Re-walk a tenant audit chain and report the first divergence';

    public function handle(TenantConnectionResolver $resolver, AuditChainVerifier $verifier): int
    {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
        $broken = [];

        foreach ($tenants as $tenant) {
            try {
                $resolver->point($tenant);
                $verdict = $verifier->verify($limit);
            } catch (Throwable $e) {
                // One unreachable tenant must not stop the others: the whole
                // point of running this across a fleet is the one that is
                // wrong, and it may well be wrong because it is unreachable.
                $broken[] = $tenant->slug;
                $this->components->error(sprintf('%s: could not verify - %s', $tenant->slug, $e->getMessage()));

                continue;
            }

            if ($verdict->isIntact) {
                $this->components->info(sprintf('%s: %s', $tenant->slug, $verdict->summary()));

                continue;
            }

            $broken[] = $tenant->slug;
            $this->components->error(sprintf('%s: %s', $tenant->slug, $verdict->summary()));
        }

        if ($broken !== []) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d of %d tenant chain(s) did not verify: %s',
                count($broken),
                count($tenants),
                implode(', ', $broken),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        if ($this->option('all')) {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->get()->all();
        }

        $needle = $this->argument('tenant');

        if ($needle === null) {
            $this->components->error('Name a tenant, or pass --all.');

            return [];
        }

        $tenant = Tenant::query()
            ->with('connection')
            ->whereHas('connection')
            ->where(fn ($q) => $q->where('slug', $needle)->orWhere('id', $needle))
            ->first();

        return $tenant === null ? [] : [$tenant];
    }
}
