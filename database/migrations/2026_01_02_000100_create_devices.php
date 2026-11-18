<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tills, phones and the tokens they hold - PF-255.
 *
 * ## Why devices live in the OPERATOR's database
 *
 * A till presents a token and we have to know which tenant it belongs to
 * before any tenant connection is opened. That is the same reason
 * `tenants` lives here: resolution has to happen in a plane that exists
 * before the answer is known.
 *
 * It is also the only place a revocation can be enforced once. A device
 * row per tenant database would mean a stolen till is revoked in one and
 * still working in another, and nobody would find out until the till
 * turned up.
 *
 * ## A token is stored as a hash and scoped to one tenant
 *
 * The whole risk this task exists for is a till that keeps working after
 * it is stolen. So:
 *
 * - the secret is never stored, only `sha256` of it, so a dump of this
 *   table does not let anybody sell anything;
 * - the token names ONE tenant, in a column, checked on every request.
 *   A token that could reach two tenants is a cross-tenant read waiting
 *   for a bug in a route;
 * - `revoked_at` is checked before the tenant connection is opened, and
 *   a revoked device's requests are refused with a reason that names the
 *   revocation rather than looking like a network fault.
 *
 * @online-safe Creates new tables only; takes no lock on anything existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');

            /*
             | A name a person chose, not a serial number.
             |
             | "Till 2, front counter" is what somebody reads when
             | deciding whether to revoke it at nine on a Saturday
             | evening. A list of device ids is a list nobody can act on,
             | and the device that gets revoked is the wrong one.
             */
            $table->string('name', 120);
            $table->string('kind', 24);

            /*
             | The secret, hashed. Never the secret.
             |
             | A dump of this table has to be useless. The hash is
             | `sha256` of a 32-byte random token - not bcrypt, because
             | this is checked on every request a till makes and a work
             | factor measured in tens of milliseconds would make a shop
             | feel broken. The token has no user-chosen part, so there
             | is nothing to brute force.
             */
            $table->char('token_hash', 64)->unique();

            /*
             | The first characters, in clear, so a person can tell two
             | devices apart without the secret.
             |
             | Which is what makes "revoke the one ending 7f3a" possible
             | over the telephone.
             */
            $table->string('token_hint', 12);

            $table->timestamp('registered_at');
            $table->ulid('registered_by');

            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_ip', 45)->nullable();
            $table->string('last_seen_version', 40)->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->ulid('revoked_by')->nullable();
            $table->text('revoked_reason')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE devices
                ADD CONSTRAINT devices_kind_is_known
                    CHECK (kind IN ('till', 'handheld', 'phone', 'tablet', 'integration')),

                ADD CONSTRAINT devices_are_named
                    CHECK (length(btrim(name)) >= 3),

                /*
                 | A revocation is attributable and explained.
                 |
                 | "Revoked" with no reason is read a month later by
                 | somebody deciding whether to re-register the device,
                 | and the difference between "sold the van" and "the
                 | till was stolen" is the whole decision.
                 */
                ADD CONSTRAINT devices_revocation_is_attributable
                    CHECK (
                        (revoked_at IS NULL AND revoked_by IS NULL AND revoked_reason IS NULL)
                        OR (
                            revoked_at IS NOT NULL
                            AND revoked_by IS NOT NULL
                            AND length(btrim(coalesce(revoked_reason, ''))) >= 10
                        )
                    );

            COMMENT ON TABLE devices IS
                'A till or handheld, named, revocable, and holding a token scoped to ONE tenant - a till that keeps working after it is stolen is the whole risk - PF-255.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
