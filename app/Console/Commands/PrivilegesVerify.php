<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\PrivilegeMatrix;
use App\Platform\Services\TenantConnectionResolver;
use App\Platform\Services\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compare the live grants against the declared intent - PF-233.
 *
 * ## Why this runs on every deploy and not once at provisioning
 *
 * The criterion is exact about it: not that the migration once ran, but that
 * the grants are as expected NOW. The provisioner is correct and that is not
 * the question. A `GRANT` typed into psql at 3am to get a stuck job moving,
 * and never reverted, is the normal way a privilege matrix regresses - and it
 * leaves no trace in any migration, any log or any pull request. The only
 * place it shows up is the catalog, so the catalog is what this reads.
 *
 * Measured on the first run against a real cluster, before any of this
 * existed: `journal_entry_fingerprints` - the table holding the SHA-256 of
 * each posted entry's substance, the evidence that an entry has not been
 * altered - was DELETE-able by the application role on every tenant. The
 * provisioner's marker query was anchored to the start of the table comment
 * and that one table writes its two markers the other way round, so
 * `harden()` reported success having skipped it. Fourteen tables carry the
 * marker; thirteen were hardened.
 *
 * That is exactly the shape of failure a drift check exists for: nothing was
 * broken, nothing was logged, and the protection was absent.
 *
 * ## It exits non-zero, and `--repair` is a separate decision
 *
 * A check that quietly fixes what it finds is a check whose findings nobody
 * ever reads, and the finding is the valuable half: a grant made by hand is a
 * question about who made it and why, not just a statement to revoke. So the
 * default reports and fails, and repairing is something an operator asks for.
 *
 * `--repair` re-runs {@see TenantProvisioner::harden()}, which is idempotent
 * and is the same code path provisioning uses. It cannot fix everything it
 * finds - a missing row-level security policy needs a migration, and a grant
 * to a stranger needs a decision about what that role was for - so the report
 * is printed again afterwards and the exit code reflects what is left.
 */
final class PrivilegesVerify extends Command
{
    protected $signature = 'privileges:verify
        {--tenant= : One tenant slug or id}
        {--repair : Re-run the hardening on anything that drifted}
        {--platform-only : Check the platform database and no tenant}';

    protected $description = 'Assert the live database grants and row-level security match the declared intent';

    public function handle(
        TenantConnectionResolver $resolver,
        TenantProvisioner $provisioner,
        PrivilegeMatrix $matrix,
    ): int {
        $findings = [];

        $this->line('');
        $this->components->info('The platform database');

        $platform = $matrix->platformDrift(DB::connection('platform'));
        $this->report((string) config('database.connections.platform.database'), $platform);

        if ($platform !== []) {
            $findings['platform'] = $platform;
        }

        if ($this->option('platform-only')) {
            return $this->verdict($findings);
        }

        $this->line('');
        $this->components->info('Each tenant database');

        foreach ($this->targets() as $tenant) {
            $resolver->point($tenant);

            try {
                $drift = [
                    ...$matrix->tenantDrift(DB::connection('tenant')),
                    ...$matrix->rowSecurityDrift(DB::connection('tenant')),
                ];
            } catch (Throwable $e) {
                /*
                 | A tenant whose database cannot be read is a finding, not a
                 | crash. One unreachable tenant must not stop the other nine
                 | hundred being checked - the same failure isolation
                 | `tenants:migrate` has, and for the same reason: a deploy
                 | check that aborts tells you about one tenant.
                 */
                $drift = [sprintf('the database could not be inspected: %s', $e->getMessage())];
            }

            if ($drift !== [] && $this->option('repair')) {
                $this->line(sprintf('  repairing %s …', $tenant->slug));

                try {
                    $provisioner->harden();

                    $drift = [
                        ...$matrix->tenantDrift(DB::connection('tenant')),
                        ...$matrix->rowSecurityDrift(DB::connection('tenant')),
                    ];
                } catch (Throwable $e) {
                    $drift[] = sprintf('the repair itself failed: %s', $e->getMessage());
                }
            }

            $this->report((string) $tenant->slug, $drift);

            if ($drift !== []) {
                $findings[(string) $tenant->slug] = $drift;
            }
        }

        return $this->verdict($findings);
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        /*
         | The connection is eager-loaded because lazy loading is DISABLED.
         |
         | `Model::preventLazyLoading()` is on in this application, so
         | `$tenant->connection` inside the loop throws rather than issuing a
         | query - which is the point of the setting, and which turns a
         | deploy check into a stack trace the first time it runs against
         | more than one tenant.
         */
        $query = Tenant::query()->with('connection')->whereNotNull('provisioned_at')->orderBy('slug');

        if (\is_string($this->option('tenant')) && $this->option('tenant') !== '') {
            $named = (string) $this->option('tenant');

            $query->where(static function ($q) use ($named): void {
                $q->where('slug', $named)->orWhere('id', $named);
            });
        }

        return $query->get()->all();
    }

    /** @param  list<string>  $drift */
    private function report(string $label, array $drift): void
    {
        if ($drift === []) {
            $this->line(sprintf('  <fg=green>ok  </> %s', $label));

            return;
        }

        $this->line(sprintf('  <fg=red>DRIFT</> %s', $label));

        foreach ($drift as $finding) {
            $this->line('        '.$finding);
        }
    }

    /** @param  array<string, list<string>>  $findings */
    private function verdict(array $findings): int
    {
        $this->line('');

        if ($findings === []) {
            $this->components->info('Every grant matches the declared intent.');

            return self::SUCCESS;
        }

        $total = array_sum(array_map('count', $findings));

        $this->components->error(sprintf(
            '%d finding(s) across %d database(s). Each one is a privilege the application holds and was '.
            'not meant to, or a policy that is missing - and a grant nobody can point to a migration for '.
            'is a question about who made it, which is why this reports rather than silently repairing.',
            $total,
            \count($findings),
        ));

        return self::FAILURE;
    }
}
