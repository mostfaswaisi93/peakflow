<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Services\TenantDump;
use Illuminate\Console\Command;

/**
 * Restore a tenant dump into a NEW database - PF-244.
 *
 * It creates its target and refuses an existing one. `pg_restore` without
 * `--clean` merges a dump into whatever is already there, and a tenant
 * whose ledger has absorbed another tenant's rows passes every per-row
 * check, because every row is individually valid. The cheapest place to
 * refuse that is here.
 */
final class TenantsRestore extends Command
{
    protected $signature = 'tenants:restore
        {file : Path to a .dump written by tenants:dump}
        {--into= : The database to create and restore into}';

    protected $description = 'Restore a tenant dump into a new database';

    public function handle(TenantDump $dumps): int
    {
        $into = (string) ($this->option('into') ?? '');

        if ($into === '') {
            $this->components->error('Pass --into=<new database>. A restore creates its target.');

            return self::FAILURE;
        }

        try {
            $result = $dumps->restore((string) $this->argument('file'), $into);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Restored %s into %s.', $result['from'], $result['database']));
        $this->components->warn((string) $result['grants']);

        return self::SUCCESS;
    }
}
