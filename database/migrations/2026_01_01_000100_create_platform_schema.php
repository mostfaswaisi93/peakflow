<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform (operator) plane - PF-018.
 *
 * This is the operator's own database. It holds who the customers are, what
 * they are entitled to, and where their data lives. It holds NO financial
 * records and NO tenant documents: a route is always in exactly one plane, and
 * /ops/* never touches a tenant connection.
 *
 * Primary keys are ULIDs throughout - sortable by creation time, safe to
 * generate client-side for idempotent writes, and they do not leak a row count
 * the way a sequence does.
 *
 * @online-safe Creates new tables only; takes no lock on anything existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('locale', 5)->default('ar');

            // MFA is mandatory for an operator, full stop. An operator can
            // suspend a tenant and can impersonate with consent, so there is
            // no tier of operator for whom a second factor is optional.
            $table->timestamp('mfa_enrolled_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('name_ar');
            $table->string('name_en');

            // What the plan switches on. A disabled feature is refused with an
            // upgrade message rather than a 403, because a 403 reads as a bug.
            $table->json('features');

            // Hard limits. Entities and users are counted; a limit reached is
            // refused at the point of creation with the number stated.
            $table->unsignedInteger('max_entities')->default(1);
            $table->unsignedInteger('max_users')->default(5);
            $table->unsignedInteger('max_documents_per_month')->nullable();

            // The jurisdictions a plan may select from. Enabling a
            // jurisdiction means accepting responsibility for its clearance
            // credentials and archive retention, so it is priced.
            $table->json('jurisdictions')->nullable();

            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('default_locale', 5)->default('ar');

            // Scheduled work runs in the TENANT'S OWN timezone: "daily at
            // 01:00" means 01:00 where the business is, and a tenant in Riyadh
            // and one in Casablanca do not share a moment.
            $table->string('timezone', 64)->default('Asia/Riyadh');

            // Data residency is a per-tenant fact, which is simple when a
            // tenant is a database and awkward when it is a predicate - one of
            // the reasons for ADR-0003.
            $table->string('data_region', 32)->default('default');

            $table->string('status', 24)->index();

            // `create` issued CREATE DATABASE; `link` attached a pre-created
            // one. Recorded because THE RESTORE PROCEDURE DIFFERS between
            // them, and per-tenant point-in-time restore is the requirement
            // that chose this tenancy model.
            $table->string('provision_mode', 16);

            // What schema version this tenant's database is actually at, so a
            // request can detect a tenant that is behind and answer 503 with
            // Retry-After rather than failing on a missing column.
            $table->string('schema_version')->nullable();
            $table->timestamp('migrating_since')->nullable();

            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });

        Schema::create('database_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('host');
            $table->unsignedInteger('port')->default(5432);
            $table->string('database');
            $table->string('username');

            // Encrypted at the COLUMN level, separately from any disk or
            // database encryption, and excluded from the model's serialization.
            // A generated role per tenant means a credential leak is bounded
            // to one customer.
            $table->text('password_encrypted');

            $table->string('sslmode', 16)->default('prefer');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained();

            $table->string('status', 24)->index();
            $table->date('starts_on');

            // A subscription is valid THROUGH its end date, so every
            // comparison against it is strictly `<`. The boundary has its own
            // test, because off-by-one here cuts a paying customer off a day
            // early or lets an expired one trade for a day.
            $table->date('ends_on')->nullable();

            $table->date('trial_ends_on')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('type', 24);
            $table->text('value')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            // A ceiling a tenant may lower and can never raise. "Write off
            // anything under 50" is a policy decision, not a developer's
            // constant, and a generous tolerance quietly absorbs real losses.
            $table->boolean('is_ceiling')->default(false);

            $table->timestamps();
        });

        Schema::create('impersonation_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            // Consent is recorded IN ADVANCE by the tenant. An operator can
            // never read a tenant's document contents without impersonation,
            // so the access is always attributable - and never unilateral.
            $table->string('granted_by_user_id', 26);
            $table->string('reason');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('tenant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('category', 48);
            $table->string('action', 64);
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->index();

            // PostgreSQL uses ONE INDEX PER TABLE REFERENCE. A lone
            // `operator_id` index would let it filter OR sort, never both, and
            // the rest would be a sort of the whole matching set. Leading with
            // the filtered column and trailing with the sort column lets one
            // index serve the whole query; the standalone `created_at` index
            // above is what the unfiltered page uses.
            $table->index(['operator_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('impersonation_grants');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('database_connections');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('operators');
    }
};
