<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `api_refresh_tokens` had an `entity_id` and no row-level security.
 *
 * ## Found by the check, not by a reader
 *
 * `TenantEnforcementRules::everyEntityScopedTableHasRls()` asserts that a
 * table carrying `entity_id` has forced RLS, and it reported this one on
 * both fixture tenants the moment the table existed. That check is the
 * reason the gap lasted minutes rather than until somebody noticed a token
 * from one company refreshing in another.
 *
 * ## What the gap actually was
 *
 * The application role is subject to RLS - deliberately, because the
 * application scope is not the only line of defence. A table without a
 * policy is a table the application can read across entities, and a refresh
 * token is the one row where that matters most: it mints a new access token,
 * so a row readable from the wrong entity is a session in a company the
 * holder was never in.
 *
 * `peakflow_apply_entity_rls` is the same function every other entity-scoped
 * table uses. Applying it here rather than writing a policy by hand is the
 * point: a hand-written policy is one that can differ, and this one differed
 * by being absent.
 *
 * @online-safe Enables row-level security on one table and adds a policy.
 *              Both are catalog changes; no row is read or rewritten. An
 *              in-flight session keeps working, because the policy admits
 *              exactly the rows its entity scope already admitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("SELECT peakflow_apply_entity_rls('api_refresh_tokens')");
    }

    public function down(): void
    {
        /*
         | Deliberately not a revert.
         |
         | `down()` undoes a change that turned out to be wrong. Taking the
         | scope off this table would restore a refresh token readable from
         | another company, which is not a state to be able to roll back
         | into - and the next `up()` is idempotent.
         */
    }
};
