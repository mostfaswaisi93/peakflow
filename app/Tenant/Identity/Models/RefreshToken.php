<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The long-lived half of a device session - PF-260.
 *
 * See the migration for why there are two credentials and why this one is
 * single-use. The row holds the SHA-256 of the secret; the secret itself is
 * handed to the device once and never stored.
 *
 * @property string $id
 * @property string $family_id
 * @property string $user_id
 * @property string $token
 * @property string $device_name
 * @property string $entity_id
 * @property string|null $access_token_id
 * @property string|null $second_factor
 * @property Carbon $session_started_at
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $replaced_by_id
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RefreshToken extends Model
{
    use HasUlids;

    /** What every refresh token starts with, so a scanner can recognise a leaked one. */
    public const PREFIX = 'pfrt_';

    protected $table = 'api_refresh_tokens';

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    public function getConnectionName(): ?string
    {
        return 'tenant';
    }

    protected function casts(): array
    {
        return [
            'session_started_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Neither spent, revoked nor expired. */
    public function isLive(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
