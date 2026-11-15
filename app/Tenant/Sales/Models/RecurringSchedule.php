<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\Proration;
use App\Tenant\Sales\Recurrence\RecurrenceRule;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A template plus a recurrence rule - PF-105.
 *
 * @property Proration $proration
 * @property string $rrule
 * @property string $timezone
 */
final class RecurringSchedule extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'recurring_schedules';

    protected $fillable = [
        'entity_id', 'reference', 'party_id', 'status', 'rrule', 'starts_on',
        'ends_on', 'occurrence_limit', 'timezone', 'generate_at_hour',
        'currency', 'proration', 'price_list_id', 'profile', 'terms', 'note',
        'lead_days', 'issue_automatically',
        'last_occurrence_on', 'occurrences_generated', 'ended_at', 'ended_reason',
    ];

    protected $casts = [
        'proration' => Proration::class,
        'starts_on' => 'immutable_date',
        'ends_on' => 'immutable_date',
        'last_occurrence_on' => 'immutable_date',
        'ended_at' => 'immutable_datetime',
        'occurrence_limit' => 'integer',
        'generate_at_hour' => 'integer',
        'lead_days' => 'integer',
        'occurrences_generated' => 'integer',
        'issue_automatically' => 'boolean',
    ];

    /** @return HasMany<RecurringScheduleLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RecurringScheduleLine::class, 'recurring_schedule_id')->orderBy('line_no');
    }

    /** @return HasMany<RecurringOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringOccurrence::class, 'recurring_schedule_id')->orderBy('occurrence_date');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function rule(): RecurrenceRule
    {
        return RecurrenceRule::parse((string) $this->rrule);
    }

    /**
     * Today, where the business is - PF-106.
     *
     * Not `now()`. A tenant in Riyadh and one in Casablanca do not share a
     * moment, and a generator using the server's clock bills one of them on
     * the wrong date - for a week each March and October, by one day, which
     * is the hardest kind of wrong to notice.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone === '' ? 'UTC' : (string) $this->timezone)->startOfDay();
    }

    /**
     * The next occurrences, on or after a date.
     *
     * @return list<CarbonImmutable>
     */
    public function upcoming(int $limit = 12, ?CarbonImmutable $after = null): array
    {
        $dates = $this->rule()->occurrences(
            CarbonImmutable::parse((string) $this->starts_on?->toDateString()),
            $limit,
            $after,
        );

        return array_values(array_filter(
            $dates,
            fn (CarbonImmutable $date): bool => $this->ends_on === null
                || ! $date->gt(CarbonImmutable::parse((string) $this->ends_on->toDateString())),
        ));
    }

    public function hasRunItsCount(): bool
    {
        return $this->occurrence_limit !== null
            && $this->occurrences_generated >= $this->occurrence_limit;
    }
}
