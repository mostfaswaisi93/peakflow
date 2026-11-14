<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A platform operator.
 *
 * MFA is mandatory for every operator without exception. An operator can
 * suspend a tenant and can impersonate with consent, so there is no tier for
 * whom a second factor is optional - {@see self::mayAct()} is what the
 * middleware checks, and it is deliberately not configurable.
 */
final class Operator extends Authenticatable implements AuthenticatableContract
{
    use HasUlids;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'operators';

    protected $fillable = ['name', 'email', 'password', 'locale'];

    protected $hidden = ['password', 'remember_token'];

    public function getConnectionName(): string
    {
        return 'platform';
    }

    public function activityLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function hasEnrolledSecondFactor(): bool
    {
        return $this->mfa_enrolled_at !== null;
    }

    /**
     * May this operator do anything at all?
     *
     * Refused rather than degraded: an operator without a second factor is
     * sent to enrol one, not given read-only access. Half-access to a
     * customer's workspace is not a safer state, it is an unclear one.
     */
    public function mayAct(): bool
    {
        return $this->hasEnrolledSecondFactor() && $this->deleted_at === null;
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_enrolled_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
