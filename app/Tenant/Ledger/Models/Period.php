<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accounting period - PF-049, PF-050, PF-051.
 *
 * @property PeriodState $state
 * @property bool $is_adjusting
 */
final class Period extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'periods';

    protected $fillable = [
        'entity_id', 'fiscal_year_id', 'ordinal', 'label',
        'starts_on', 'ends_on', 'is_adjusting', 'state',
        'closed_at', 'closed_by_user_id', 'locked_at', 'locked_by_user_id',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_adjusting' => 'boolean',
        'state' => PeriodState::class,
        'closed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function acceptsPostings(): bool
    {
        return $this->state->acceptsPostings();
    }

    public function contains(\DateTimeInterface $date): bool
    {
        return ! $this->starts_on->startOfDay()->greaterThan($date)
            && ! $this->ends_on->endOfDay()->lessThan($date);
    }
}
