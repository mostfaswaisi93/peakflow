<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Provisioning mode
    |---------------------------------------------------------------------------
    |
    | 'create' issues CREATE DATABASE / CREATE ROLE / GRANT, which needs those
    | privileges on the server. 'link' attaches a pre-created database, for
    | managed hosting without them.
    |
    | The mode is recorded ON THE TENANT, not just here, because the backup and
    | restore procedure differs between them - and per-tenant point-in-time
    | restore is the requirement that chose this tenancy model in the first
    | place (ADR-0003), so it has to actually work.
    |
    */

    'provision_mode' => env('TENANCY_PROVISION_MODE', 'create'),

    'database_prefix' => env('TENANCY_DATABASE_PREFIX', 'peakflow_t_'),

    /*
    |---------------------------------------------------------------------------
    | Migrating thousands of databases
    |---------------------------------------------------------------------------
    |
    | The honest operational cost of database-per-tenant. Migration time grows
    | linearly with tenant count; bounded concurrency helps and does not change
    | the order of growth.
    |
    | 'failure_isolation' is the part that matters: one tenant's broken
    | migration must not stop the other nine hundred.
    |
    */

    'migrate' => [
        'concurrency' => (int) env('TENANCY_MIGRATE_CONCURRENCY', 4),
        'batch_size' => 50,
        'failure_isolation' => true,

        // A migration that is not safe to run while serving traffic is
        // REFUSED by a pre-flight check rather than attempted (PF-023).
        // Every migration declares its own safety; this is the enforcement.
        'require_online_safety_declaration' => true,
    ],

    /*
    |---------------------------------------------------------------------------
    | Connection pressure
    |---------------------------------------------------------------------------
    |
    | One pool per active tenant does not scale to a hundred thousand tenants.
    | This ceiling is a capacity-planning input, not a solved problem - stated
    | plainly in docs/architecture/multi-tenancy.md under "Known limits".
    |
    */

    'connections' => [
        'max_per_tenant' => 5,
        'idle_timeout_seconds' => 60,
    ],

    /*
    |---------------------------------------------------------------------------
    | Entity scoping
    |---------------------------------------------------------------------------
    |
    | TWO independent mechanisms, because one of them will eventually have a
    | hole: the application's own global scope, and PostgreSQL row-level
    | security keyed on a session variable set per request.
    |
    | Disabling RLS is not a configuration option. It is listed here so that
    | the session variable name has one definition.
    |
    */

    'entity_scope' => [
        'session_variable' => 'peakflow.entity_id',
        'tenant_session_variable' => 'peakflow.tenant_id',
    ],

    /*
    |---------------------------------------------------------------------------
    | Lifecycle
    |---------------------------------------------------------------------------
    |
    | A subscription is valid THROUGH its end date, so the expiry comparison is
    | strictly `<` and the boundary has its own test.
    |
    | A tenant mid-migration returns 503 with Retry-After rather than failing:
    | a maintenance window that is visible is tolerable, one that presents as
    | random errors is not.
    |
    */

    'grace' => [
        'past_due_days' => 14,
        'soft_delete_days' => 30,
    ],

    'migrating_retry_after_seconds' => 30,

];
