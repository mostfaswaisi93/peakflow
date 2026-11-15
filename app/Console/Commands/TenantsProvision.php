<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Exceptions\ProvisioningFailed;
use App\Platform\Services\TenantProvisioner;
use Illuminate\Console\Command;

final class TenantsProvision extends Command
{
    protected $signature = 'tenants:provision
        {name : The customer\'s name}
        {--plan=starter : The plan key}
        {--slug= : Override the generated slug}
        {--link-database= : Attach a pre-created database instead of creating one}
        {--link-username=}
        {--link-password=}
        {--link-host=}
        {--link-port=5432}';

    protected $description = 'Create or attach a tenant database, migrate it, and start a subscription';

    public function handle(TenantProvisioner $provisioner): int
    {
        $name = (string) $this->argument('name');
        $plan = (string) $this->option('plan');
        $slug = $this->option('slug') !== null ? (string) $this->option('slug') : null;

        try {
            if ($this->option('link-database') !== null) {
                $tenant = $provisioner->link($name, $plan, [
                    'host' => (string) ($this->option('link-host') ?? config('database.connections.platform.host')),
                    'port' => (int) $this->option('link-port'),
                    'database' => (string) $this->option('link-database'),
                    'username' => (string) $this->option('link-username'),
                    'password' => (string) $this->option('link-password'),
                ], $slug);
            } else {
                $tenant = $provisioner->create($name, $plan, $slug);
            }
        } catch (ProvisioningFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Provisioned "%s".', $tenant->name));

        $this->table(['', ''], [
            ['Tenant id', (string) $tenant->getKey()],
            ['Slug', $tenant->slug],
            ['Mode', $tenant->provision_mode->value],
            ['Database', (string) $tenant->connection?->database],
            ['Role', (string) $tenant->connection?->username],
            ['Schema version', (string) $tenant->schema_version],
            ['Status', $tenant->status->value],
        ]);

        return self::SUCCESS;
    }
}
