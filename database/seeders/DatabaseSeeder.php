<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The platform seed.
 *
 * Deliberately does NOT create a tenant. A tenant is created by
 * `php artisan tenants:provision`, which makes a real database and a real
 * role - and a seeder that quietly did that would leave an orphan behind
 * every time it ran.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            PlatformSettingSeeder::class,
        ]);
    }
}
