<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Services\BalanceRebuilder;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;

/**
 * `ledger:rebuild-balances` - PF-053.
 *
 * ## `--check` is the one that runs nightly
 *
 * It compares the read model against the journal and REPORTS. It repairs
 * nothing, and the exit code is how a scheduler notices.
 *
 * Repairing on the way past is the tempting behaviour and it is wrong for a
 * reason that only shows up afterwards: a silent repair hides the bug that
 * caused the drift, and the bug is what matters. A figure that was wrong for
 * a week was on a report for a week, in an email, possibly in a filed return
 * - and the repair erases the only evidence of how long.
 *
 * So the rebuild is a separate, deliberate act, run once somebody knows why
 * the drift happened.
 */
final class LedgerRebuildBalances extends Command
{
    protected $signature = 'ledger:rebuild-balances
        {tenant? : A tenant slug or id}
        {--all : Every servable tenant}
        {--entity= : One entity code}
        {--check : Compare against the journal and report, changing nothing}';

    protected $description = 'Rebuild the balance read model from the journal, or check it for drift';

    public function handle(TenantConnectionResolver $resolver, BalanceRebuilder $rebuilder): int
    {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched.');

            return self::SUCCESS;
        }

        $checking = (bool) $this->option('check');
        $drifted = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            $entities = Entity::query()
                ->when($this->option('entity') !== null, fn ($q) => $q->where('code', $this->option('entity')))
                ->orderBy('code')
                ->get();

            foreach ($entities as $entity) {
                app(EntityContext::class)->use($entity);
                $label = sprintf('%s · %s', $tenant->slug, $entity->code);

                if (! $checking) {
                    $result = $rebuilder->rebuild((string) $entity->getKey());

                    $this->components->twoColumnDetail($label, sprintf(
                        '<fg=green>%d row(s)</> (replaced %d)',
                        $result['rows'],
                        $result['replaced'],
                    ));

                    continue;
                }

                $result = $rebuilder->check((string) $entity->getKey());

                if ($result['clean']) {
                    $this->components->twoColumnDetail(
                        $label,
                        sprintf('<fg=green>%d balance(s) agree</>', $result['checked']),
                    );

                    continue;
                }

                $drifted++;
                $this->newLine();
                $this->components->error(sprintf(
                    '%s: %d balance(s) disagree with the journal',
                    $label,
                    count($result['findings']),
                ));

                foreach (array_slice($result['findings'], 0, 20) as $finding) {
                    $this->line(sprintf(
                        '    <fg=red>%-8s</> %s  stored %s, journal %s, out by %s',
                        $finding['kind'],
                        $this->describeKey((string) $finding['key']),
                        $finding['stored'] ?? '—',
                        $finding['rebuilt'] ?? '—',
                        $finding['difference'] ?? '—',
                    ));
                }

                if (count($result['findings']) > 20) {
                    $this->line(sprintf('    … and %d more', count($result['findings']) - 20));
                }
            }

            app(EntityContext::class)->forget();
        }

        $this->newLine();

        if ($drifted > 0) {
            $this->components->error(sprintf(
                '%d entity/entities have drifted balances. NOTHING was repaired, deliberately: a silent '.
                'repair hides the bug that caused the drift, and a figure that was wrong for a week was on '.
                'a report for a week. Find the cause, then run this without --check.',
                $drifted,
            ));

            return self::FAILURE;
        }

        $this->components->info($checking
            ? 'The read model agrees with the journal.'
            : 'Balances rebuilt from the journal.');

        return self::SUCCESS;
    }

    /**
     * An account code rather than a ULID, where it can be resolved.
     *
     * A finding naming four identifiers is a finding nobody reads. The
     * account code and the period label are what somebody recognises.
     */
    private function describeKey(string $key): string
    {
        $parts = explode('|', $key, 4);

        $account = \Illuminate\Support\Facades\DB::connection('tenant')
            ->table('accounts')->where('id', $parts[2] ?? '')->value('code');

        $period = \Illuminate\Support\Facades\DB::connection('tenant')
            ->table('periods')->where('id', $parts[1] ?? '')->value('label');

        return sprintf(
            '%-10s %-9s',
            $account === null ? substr((string) ($parts[2] ?? ''), 0, 10) : (string) $account,
            $period === null ? substr((string) ($parts[1] ?? ''), 0, 9) : (string) $period,
        );
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        if ($this->option('all')) {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->get()->all();
        }

        $given = $this->argument('tenant');

        if ($given === null) {
            $this->components->error('Name a tenant, or pass --all.');

            return [];
        }

        $tenant = Tenant::query()->with('connection')
            ->where('slug', $given)->orWhere('id', $given)->first();

        return $tenant === null ? [] : [$tenant];
    }
}
