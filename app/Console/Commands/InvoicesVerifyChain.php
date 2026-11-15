<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Services\ChainVerifier;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;

/**
 * `invoices:verify-chain` - PF-096.
 *
 * Exits non-zero on any divergence, so it can be scheduled and alerted on. A
 * chain nobody walks is a chain whose first walk happens during an audit.
 *
 * Reports the FIRST divergence per series and stops there: after the first
 * one every later link is wrong too, and a report of 860 findings is a report
 * nobody reads to the end while the one fact that matters is "41, and this
 * field".
 */
final class InvoicesVerifyChain extends Command
{
    protected $signature = 'invoices:verify-chain
        {tenant? : A tenant slug or id}
        {--all : Walk every servable tenant}
        {--series= : One series key, e.g. INV}';

    protected $description = 'Re-walk each document series and report the first divergence';

    public function handle(TenantConnectionResolver $resolver, ChainVerifier $verifier): int
    {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched.');

            return self::SUCCESS;
        }

        $divergences = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            foreach (Entity::query()->orderBy('code')->get() as $entity) {
                app(EntityContext::class)->use($entity);

                $entityId = (string) $entity->getKey();
                $only = $this->option('series');
                $series = \is_string($only) && $only !== '' ? [$only] : $verifier->seriesOf($entityId);

                if ($series === []) {
                    $this->components->twoColumnDetail(
                        sprintf('%s · %s', $tenant->slug, $entity->code),
                        '<fg=gray>nothing issued</>',
                    );

                    continue;
                }

                foreach ($series as $key) {
                    $result = $verifier->verify($entityId, $key);
                    $label = sprintf('%s · %s · %s', $tenant->slug, $entity->code, $key);

                    if ($result['verified']) {
                        $this->components->twoColumnDetail(
                            $label,
                            sprintf('<fg=green>%d verified</>', $result['documents']),
                        );

                        continue;
                    }

                    $divergences++;
                    $divergence = $result['divergence'];

                    $this->newLine();
                    $this->components->error(sprintf('%s: diverges at %s', $label, $divergence['number']));
                    $this->line(sprintf('  <fg=red;options=bold>document %s</>', $divergence['number']));
                    $this->line(sprintf('    chain index %d', $divergence['index']));
                    $this->line(sprintf('    %s', $divergence['reason']));
                    $this->line('    <comment>The chain proves internal consistency, not authorship: '.
                        'anyone who can write to this database can recompute a chain from a tampered '.
                        'document onward. A signature from a key the database does not hold is PF-124.</comment>');
                }
            }
        }

        if ($divergences > 0) {
            $this->newLine();
            $this->components->error(sprintf('%d series diverge.', $divergences));

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Every series verified.');

        return self::SUCCESS;
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        $argument = $this->argument('tenant');

        if (\is_string($argument) && $argument !== '') {
            return Tenant::query()
                ->with('connection')
                ->where(fn ($q) => $q->where('slug', $argument)->orWhere('id', $argument))
                ->whereNotNull('provisioned_at')
                ->get()
                ->all();
        }

        if ($this->option('all')) {
            return Tenant::query()->with('connection')->whereNotNull('provisioned_at')->orderBy('slug')->get()->all();
        }

        $this->components->warn('Name a tenant or pass --all.');

        return [];
    }
}
