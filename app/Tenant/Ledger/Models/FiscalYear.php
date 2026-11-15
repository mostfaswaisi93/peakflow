<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Ledger\Enums\FiscalCalendar;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fiscal year - PF-049.
 *
 * Belongs to the entity, not the tenant: a subsidiary may run April-to-March
 * while its parent runs the calendar year, and a group that forces one on the
 * other is reporting one of them in the wrong year.
 *
 * @property string $label
 * @property FiscalCalendar $calendar
 */
final class FiscalYear extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'fiscal_years';

    protected $fillable = ['entity_id', 'label', 'starts_on', 'ends_on', 'calendar', 'closed'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'calendar' => FiscalCalendar::class,
        'closed' => 'boolean',
    ];

    /** @return HasMany<Period, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(Period::class)->orderBy('ordinal');
    }
}
