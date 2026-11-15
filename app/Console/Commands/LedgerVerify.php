<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Closing\ClosingChecks;
use App\Tenant\Ledger\Models\Period;
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

                /*
                 | And the per-period closing checks - PF-088.
                 |
                 | The same registry the close consults, run here as an
                 | assertion rather than as a gate: a difference that would
                 | block a close is worth knowing about before the day
                 | somebody tries to close, and `ledger:verify` is what runs
                 | nightly.
                 |
                 | Only periods that are still OPEN. A closed one either had
                 | no reason to refuse or was signed off, and re-reporting it
                 | every night would train the reader to skip the output.
                 */
                $findings = $findings->concat(
                    $this->closingFindings((string) $entity->getKey()),
                );

                $label = sprintf('%s · %s', $tenant->slug, $entity->code);

                if ($findings->isEmpty()) {
                    $this->components->twoColumnDetail($label, '<fg=green>verified</>');
                } else {
                    $this->newLine();

                    /*
                     | Rendered by severity, not all in red.
                     |
                     | Everything used to print as an error and the run then
                     | ended with "Every entity verified", because a warning
                     | does not fail the command. Two statements contradicting
                     | each other in one screen of output is how a reader
                     | learns to skip the output.
                     */
                    $worst = $findings->contains(
                        static fn (LedgerFinding $finding): bool => $finding->isCritical(),
                    );

                    $headline = sprintf('%s: %d finding(s)', $label, $findings->count());

                    $worst
                        ? $this->components->error($headline)
                        : $this->components->warn($headline);

                    foreach ($findings as $finding) {
                        /** @var LedgerFinding $finding */
                        $critical += $finding->isCritical() ? 1 : 0;

                        $colour = $finding->isCritical() ? 'red' : 'yellow';

                        $this->line(sprintf('  <fg=%s;options=bold>%s</>', $colour, $finding->kind));
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
    /**
     * What the registered closing checks say about each open period - PF-088.
     *
     * @return list<LedgerFinding>
     */
    private function closingFindings(string $entityId): array
    {
        $checks = app(ClosingChecks::class);

        if ($checks->names() === []) {
            return [];
        }

        $findings = [];

        $periods = Period::query()
            ->where('entity_id', $entityId)
            ->where('state', 'open')
            ->orderBy('starts_on')
            ->get();

        foreach ($periods as $period) {
            $reasons = $checks->reasonsToRefuse(
                $entityId,
                (string) $period->label,
                $period->starts_on->toDateString(),
                $period->ends_on->copy()->addDay()->toDateString(),
            );

            foreach ($reasons as $reason) {
                $findings[] = LedgerFinding::critical(
                    sprintf('period %s will not close', $period->label),
                    $reason,
                    'The period cannot be closed until this is explained or signed off, and after a '.
                    'close every correction is a restatement rather than an entry.',
                );
            }
        }

        return $findings;
    }

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
