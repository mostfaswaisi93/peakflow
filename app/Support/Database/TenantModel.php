<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The base for everything that lives in a tenant's own database.
 *
 * It lives in Support rather than in a module because it is infrastructure,
 * not domain: every module's models extend it, and putting it in Identity
 * would make Tenancy, Ledger and the rest all depend on Identity for a reason
 * that has nothing to do with identity. The architecture rule forbidding a
 * module from reaching into another module's models caught exactly that.
 *
 * The connection is named, not inherited. `tenant` is rewritten per request by
 * TenantConnectionResolver, so a model that forgets to name it would read
 * whichever connection happened to be default - the platform database - and
 * find a table that does not exist there, or worse, one that does.
 */
abstract class TenantModel extends Model
{
    use HasUlids;

    public function getConnectionName(): ?string
    {
        return 'tenant';
    }
}
