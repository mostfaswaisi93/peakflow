<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform infrastructure: sessions.
 *
 * `users` is deliberately NOT here. A tenant's users live in that tenant's own
 * database (Phase 01, PF-024), which is why the tenant resolver has to run
 * ahead of the auth middleware - a guard that ran first would look for them
 * here and find nothing.
 *
 * Sessions are held per plane. Operator sessions live in the platform
 * database; tenant sessions move to the tenant database with PF-029, so that
 * a tenant export carries its own sessions and a tenant delete removes them.
 *
 * @online-safe Creates new tables only; takes no lock on anything existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id', 26)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
