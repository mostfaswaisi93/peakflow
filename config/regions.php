<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Where a tenant's data is allowed to be - PF-239
|------------------------------------------------------------------------------
|
| `tenants.data_region` has been on the table since the platform schema was
| created, with a comment saying residency "is a per-tenant fact". Measured:
| **nothing wrote it and nothing read it.** Every tenant carries the default
| and no code has ever compared it with anything - so the product recorded a
| residency claim it could not keep.
|
| This file is the other half: per region, the database hosts and the storage
| disks a tenant declared to be in it may use. The check compares the two and
| refuses a tenant whose database is somewhere its region does not permit.
|
| ## What "does not leave its region" can and cannot mean
|
| It is a claim about STORAGE, and this file is honest about that. A cleared
| invoice is sent to a tax authority and a payment file is sent to a bank, and
| both of those leave the region by law rather than by accident - that is what
| clearance IS. A configuration pretending otherwise would be a claim the
| product breaks on its first working day.
|
| What it does mean, and what is enforced: the tenant's database and the
| objects behind its documents live on hosts the region permits, and a
| provisioning run that would place them elsewhere is refused rather than
| discovered later.
|
| ## `default` is not a region
|
| It is the absence of a declaration, and it permits everything - which is
| correct for a single-region deployment and is NOT a residency guarantee.
| A deployment that sells residency declares real regions here and moves its
| tenants onto them; until then the column says "nobody promised anything",
| which is the truth.
|
*/

return [

    'default' => [
        'label' => 'No declared region',
        'hosts' => ['*'],
        'disks' => ['*'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Declared regions
    |---------------------------------------------------------------------------
    |
    | Exact hostnames, because a wildcard here is a residency promise with a
    | hole in it: `*.example.com` admits a host in any country the DNS owner
    | cares to point it at, and the point of the declaration is that somebody
    | checked.
    |
    | The values are environment-driven so one deployment's hosts are not
    | compiled into another's, and a region with no hosts configured permits
    | NOTHING - which fails loudly rather than admitting everything, because
    | the failure mode of a residency rule must be refusal.
    |
    */

    'sa' => [
        'label' => 'Saudi Arabia',
        'hosts' => array_values(array_filter(explode(',', (string) env('REGION_SA_HOSTS', '')))),
        'disks' => array_values(array_filter(explode(',', (string) env('REGION_SA_DISKS', '')))),
    ],

    'eu' => [
        'label' => 'European Union',
        'hosts' => array_values(array_filter(explode(',', (string) env('REGION_EU_HOSTS', '')))),
        'disks' => array_values(array_filter(explode(',', (string) env('REGION_EU_DISKS', '')))),
    ],

];
