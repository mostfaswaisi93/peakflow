<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Services\LedgerFinding;
use App\Tenant\Ledger\Services\LedgerVerifier;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;

/**
 * `ledger:verify` - the global proof - PF-054.
 *
 * Exits non-zero on any critical finding, so it can be scheduled, alerted on,
 * and used as the final assertion of a feature test. A ledger nobody verifies
 * is a ledger whose first verification happens during an audit.
 */
final class LedgerVerify extends Command
{
    protected $signature = 'ledger:verify
        {tenant? : A tenant slug or id}
        {--all : Verify every servable tenant}
        {--totals : Also print each control account total}';

    protected $description = 'Prove the ledger\'s invariants across a whole tenant';

    public function handle(TenantConnectionResolver $resolver, LedgerVerifier $verifier): int
    {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched.');

            return self::SUCCESS;
        }

        $critical = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            // Entities are listed OUTSIDE any entity scope, because the point
            // is to check all of them - and the per-entity checks then run
            // with that entity established.
            $entities = Entity::query()->orderBy('code')->get();

            foreach ($entities as $entity) {
                app(EntityContext::class)->use($entity);

                $findings = $verifier->verify((string) $entity->getKey());

                $label = sprintf('%s · %s', $tenant->slug, $entity->code);

                if ($findings->isEmpty()) {
                    $this->components->twoColumnDetail($label, '<fg=green>verified</>');
                } else {
                    $this->newLine();
                    $this->components->error(sprintf('%s: %d finding(s)', $label, $findings->count()));

                    foreach ($findings as $finding) {
                        /** @var LedgerFinding $finding */
                        $critical += $finding->isCritical() ? 1 : 0;

                        $this->line(sprintf('  <fg=red;options=bold>%s</>', $finding->kind));
                        $this->line(sprintf('    %s', $finding->detail));
                        $this->line(sprintf('    <comment>%s</comment>', $finding->consequence));
                    }
                }

                if ($this->option('totals')) {
                    foreach ($verifier->controlTotals((string) $entity->getKey()) as $total) {
                        $this->line(sprintf('    %-28s %s', $total['role'], $total['total']));
                    }
                }
            }

            app(EntityContext::class)->forget();
        }

        if ($critical > 0) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d critical finding(s). The ledger does not currently prove its own invariants.',
                $critical,
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Every entity verified.');

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
