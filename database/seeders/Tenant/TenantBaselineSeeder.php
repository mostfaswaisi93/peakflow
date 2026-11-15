<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use Illuminate\Database\Seeder;

/**
 * What every new tenant database opens with.
 *
 * A workspace that opens empty is a workspace whose owner has to invent a
 * permission model before they can issue an invoice. These are the things that
 * are the same for every customer and that nobody should have to configure.
 */
final class TenantBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SodRuleSeeder::class,
        ]);
    }
}
