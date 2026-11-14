<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |---------------------------------------------------------------------------
    | Default connection
    |---------------------------------------------------------------------------
    |
    | `platform` is the operator's own database. It is the default because a
    | request that has not resolved a tenant yet must not be able to touch
    | tenant data by accident.
    |
    */

    'default' => env('DB_CONNECTION', 'platform'),

    'connections' => [

        /*
        |-----------------------------------------------------------------------
        | Platform - the operator plane
        |-----------------------------------------------------------------------
        |
        | Operators, tenants, plans, subscriptions, connection records, the
        | platform activity log. The application role CANNOT connect to this
        | database at all in production (PF-010); only the operator console's
        | own role can.
        |
        */

        'platform' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'peakflow'),
            'username' => env('DB_USERNAME', 'peakflow_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        |-----------------------------------------------------------------------
        | Tenant - a TEMPLATE, rewritten per request
        |-----------------------------------------------------------------------
        |
        | ⚠️  These settings are placeholders. They are REWRITTEN AT RUNTIME,
        | per request, by App\Platform\Services\TenantConnectionResolver, which
        | reads the resolved tenant's own credentials and then purges the
        | connection so the next query reaches the right database.
        |
        | Do not put real credentials here and do not migrate against it
        | directly: `php artisan migrate` applies only the PLATFORM schema. The
        | tenant schema in database/migrations/tenant is applied per tenant by
        | `php artisan tenants:migrate`.
        |
        | Every model under app/Tenant hard-codes getConnectionName(): 'tenant',
        | which is asserted by an architecture test - so a tenant query follows
        | whichever database was most recently selected and can never read
        | platform data even if a developer forgets a scope.
        |
        */

        'tenant' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => null,
            'username' => null,
            'password' => null,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        |-----------------------------------------------------------------------
        | Migrator - schema changes only
        |-----------------------------------------------------------------------
        |
        | Migrations and tenant provisioning run as this role, from a separate
        | process. It is the only role permitted to ALTER, to DROP, and to
        | DELETE a posted financial row.
        |
        | The application does not connect as the migrator. That separation is
        | what makes "posted history is immutable" a fact rather than an
        | intention, and PF-233 verifies the LIVE grants on every deploy -
        | because a manual grant made during an incident is exactly how it
        | regresses.
        |
        */

        'migrator' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'peakflow'),
            'username' => env('DB_MIGRATOR_USERNAME', 'peakflow_migrator'),
            'password' => env('DB_MIGRATOR_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | Migration repository
    |---------------------------------------------------------------------------
    |
    | Tenant migrations keep their OWN repository table inside each tenant
    | database, so a tenant's schema version is a fact about that tenant. That
    | is what lets a request detect a tenant that is behind and respond with
    | 503 rather than failing on a missing column.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |---------------------------------------------------------------------------
    | Redis
    |---------------------------------------------------------------------------
    |
    | A SHARED store is required in production. The locks keyed on
    | (task, tenant, occurrence) are what stop a two-node scheduler from
    | double-billing a customer; under an array store that degrades to a no-op.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'peakflow'), '_').'_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        // Scheduled-run and payment-release locks. Separated from the cache so
        // that flushing the cache can never drop a lock that is holding a
        // money-moving operation open.
        'locks' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_LOCK_DB', '2'),
        ],

    ],

];
