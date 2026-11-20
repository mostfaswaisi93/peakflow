<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `suppressed_addresses` is tenant-scoped, and was not declared so - PF-104, PF-005.
 *
 * ## What was wrong
 *
 * 001500 created the table with an `entity_id` column and no row-level
 * security, which `TenantEnforcementRules::everyScopedTableHasAPolicy()`
 * reported against every one of the sixteen tenant databases: a table with
 * that column is scoped unless it says otherwise, and this one did not say
 * otherwise.
 *
 * ## Why the fix is the declaration and not a policy
 *
 * Because the table's design is deliberately tenant-wide, and 001500 said
 * so twice without drawing the conclusion. Its unique key is
 * `(channel, address)` **across** entities, and the comment beside it reads:
 * "a bounce is a fact about the mailbox, and two companies in one tenant
 * sending to the same wrong address should both stop. `entity_id` is
 * carried for row-level security and for saying which entity first saw it."
 *
 * The first half of that sentence is the design and the second half
 * contradicts it. Enabling the policy would scope reads per entity while
 * the unique key stayed tenant-wide, so entity B sending to an address
 * entity A had already suppressed would hit a unique violation on a row it
 * cannot see - a constraint error with nothing in the interface able to
 * explain it, which is worse than either coherent answer.
 *
 * And the wider reason the shared list is right: the suppression list
 * protects a **sending domain's** reputation, and the sending domain belongs
 * to the tenant rather than to one of its companies. A hard bounce retried
 * by the second company costs the first company's invoices their
 * deliverability.
 *
 * So `entity_id` on this table means "which entity first saw it", which is
 * what the comment now says, and the opt-out carries the reason into
 * `\dt+` for whoever is holding the incident.
 *
 * @financial-not-applicable: changes a table comment. No column is altered,
 * no row is written, no financial figure is touched.
 * @online-safe COMMENT ON TABLE takes a brief catalogue lock and rewrites
 * no data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE suppressed_addresses IS
                'peakflow:tenant-scoped - addresses that bounced or complained and are no longer sent to, PF-104. Deliberately NOT entity-scoped: the unique key is (channel, address) across entities, because a bounce is a fact about the mailbox and the sending domain whose reputation it protects belongs to the tenant rather than to one of its companies. A hard bounce retried by a second company costs the first one the deliverability of invoices whose addresses are right. `entity_id` records which entity first saw it, and scoping reads by it while the unique key stayed tenant-wide would make the second company hit a unique violation on a row it cannot see.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE suppressed_addresses IS
                'Addresses that bounced or complained and are no longer sent to - PF-104. A hard bounce retried is how a sending domain loses the reputation that delivers the invoices whose addresses are right.';
        SQL);
    }
};
