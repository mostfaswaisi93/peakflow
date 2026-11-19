<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Alerts;

/**
 * What a watcher has to be run per - PF-242.
 *
 * `Entity` watchers answer about one company's books, so the connection is
 * pointed and the entity established before they are asked. `Tenant`
 * watchers answer about the database itself - which privileges a role holds,
 * whether row-level security is still on - and running one per entity would
 * raise the same alert once per company in the group, which is how an alert
 * channel becomes something people mute.
 */
enum WatchScope: string
{
    case Entity = 'entity';
    case Tenant = 'tenant';
}
