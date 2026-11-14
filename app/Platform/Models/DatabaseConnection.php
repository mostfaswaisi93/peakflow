<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Where a tenant's data actually lives.
 *
 * The password is encrypted at the COLUMN level, separately from any disk or
 * database encryption, and is excluded from every serialization. A generated
 * database role per tenant - rather than one shared application role with
 * access to many databases - is what bounds a credential leak to a single
 * customer.
 */
final class DatabaseConnection extends PlatformModel
{
    protected $table = 'database_connections';

    protected $fillable = [
        'tenant_id', 'host', 'port', 'database', 'username', 'sslmode', 'verified_at',
    ];

    /**
     * Never serialized, never logged, never exported.
     *
     * The log allowlist in config/telemetry.php is the other half of this:
     * a blocklist would fail open the first time a field was added.
     *
     * @var list<string>
     */
    protected $hidden = ['password_encrypted'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function setPassword(string $plain): void
    {
        $this->password_encrypted = Crypt::encryptString($plain);
    }

    /**
     * The decrypted password.
     *
     * Deliberately a method rather than an accessor, so it cannot be reached
     * by `toArray()`, by a JSON cast, or by anything that walks attributes.
     */
    public function password(): string
    {
        return Crypt::decryptString((string) $this->password_encrypted);
    }

    /**
     * The runtime shape of Laravel's `tenant` connection.
     *
     * This is what {@see \App\Platform\Services\TenantConnectionResolver}
     * writes into `config('database.connections.tenant')` before purging the
     * connection, so the next query reaches the right database.
     *
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password(),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => $this->sslmode,
        ];
    }

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'verified_at' => 'datetime',
        ];
    }
}
