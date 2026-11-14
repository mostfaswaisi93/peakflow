<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every model in the operator plane.
 *
 * The connection is hard-coded rather than left to the default, so a platform
 * query can never follow the `tenant` connection after a resolver has pointed
 * it somewhere - which is the mirror of the rule that every tenant model
 * declares `tenant`. Both are enforced by an architecture test, because the
 * failure mode is reading the wrong customer's data and it is silent.
 */
abstract class PlatformModel extends Model
{
    use HasUlids;

    public function getConnectionName(): string
    {
        return 'platform';
    }
}
