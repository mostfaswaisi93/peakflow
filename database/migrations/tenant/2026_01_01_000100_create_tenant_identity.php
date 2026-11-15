<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant identity - PF-024.
 *
 * Runs inside EACH TENANT'S OWN DATABASE. `php artisan migrate` does not apply
 * it; `php artisan tenants:migrate` does, per tenant.
 *
 * The single most consequential shape here: a user's roles are held PER
 * ENTITY, not globally. A group accountant holds roles in four companies and a
 * subsidiary's clerk in one, and a global role table cannot express that
 * without a second table bolted on later.
 *
 * @online-safe Creates new tables only.
 */
return new class extends Migration
{
    /*
     | No getConnection() override, deliberately.
     |
     | Returning 'tenant' here would OVERRIDE the --database option and force
     | the application's own connection, which has no DDL privileges at all.
     | `tenants:migrate` runs with a migrator-credentialed clone of the tenant
     | connection and sets it as the default, so the plain facades below reach
     | the right database with the right privileges.
     |
     | That is the division PF-010 exists to create: the application can read
     | and write rows and can never change the schema.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('locale', 5)->nullable();
            $table->string('timezone', 64)->nullable();

            // Which entity this user was last working in. Restored on sign-in
            // so they land where they left off - and re-authorized on every
            // request regardless, because a stored preference is not a grant.
            $table->ulid('last_entity_id')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('password_changed_at')->nullable();

            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();

            // Soft deletes, so a document created three years ago still
            // resolves its author. A deleted user's name is part of the audit
            // trail, not disposable.
            $table->softDeletes();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Verb-shaped: `invoice.issue`, not `invoices` or `can_invoice`.
            // A permission that does not name an act cannot be reasoned about.
            $table->string('key')->unique();
            $table->string('module', 32)->index();

            // Plain language, bilingual. A role screen that lists keys is
            // unusable by the person who should be configuring it.
            $table->string('label_ar');
            $table->string('label_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            // Marked permissions cannot be GRANTED to a user with no enrolled
            // second factor - refused at the point of granting, with an
            // explanation, rather than at the point of use where it blocks
            // real work. See PF-031.
            $table->boolean('requires_mfa')->default(false);

            // Releasing money and reopening a period need a FRESH factor
            // assertion at the moment of the act. A stolen session must not be
            // enough.
            $table->boolean('requires_step_up')->default(false);

            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            // A system role is seeded and cannot be deleted, because deleting
            // `owner` would leave a tenant with nobody who can grant anything.
            $table->boolean('system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_entity_roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // Not a foreign key yet - `entities` is created by the next
            // migration. The constraint is added there, so that either
            // migration can be read on its own.
            $table->ulid('entity_id');

            $table->foreignUlid('role_id')->constrained();
            $table->ulid('granted_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'entity_id', 'role_id']);
            $table->index(['entity_id', 'user_id']);
        });

        Schema::create('authenticators', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // `passkey` is preferred and `totp` is the fallback. SMS is
            // deliberately absent: SIM swap is the dominant attack on exactly
            // the population this product serves.
            $table->string('type', 16);

            $table->string('name');

            // WebAuthn credential id and public key. Both are public data by
            // design - a passkey's security does not depend on hiding them.
            $table->text('credential_id')->nullable();
            $table->text('public_key')->nullable();
            $table->unsignedBigInteger('sign_count')->default(0);

            // A TOTP secret is NOT public. Encrypted at the column level, like
            // a tenant's database password.
            $table->text('secret_encrypted')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        Schema::create('recovery_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // Hashed, never stored in the clear. Issued once, shown once.
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->ulid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Sessions are bound to a device fingerprint; a binding change
            // forces re-authentication rather than silently continuing.
            $table->string('device_fingerprint', 64)->nullable()->index();

            // When a factor was last asserted. Step-up reads THIS, not the
            // session's age - a session can be hours old and still have a
            // thirty-second-old assertion, or the reverse.
            $table->timestamp('factor_asserted_at')->nullable();

            $table->ulid('entity_id')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('recovery_codes');
        Schema::dropIfExists('authenticators');
        Schema::dropIfExists('user_entity_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('users');
    }
};
