<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\QuoteState;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An offer, with no accounting consequences - PF-089.
 *
 * @property QuoteState $status
 * @property string $reference
 */
final class Quote extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'quotes';

    protected $fillable = [
        'entity_id', 'reference', 'version', 'party_id', 'status',
        'issue_date', 'valid_until', 'currency',
        'net_amount', 'tax_amount', 'gross_amount', 'presentation_exponent',
        'profile', 'terms', 'note',
        'outcome_type', 'outcome_id',
        'sent_at', 'accepted_at', 'accepted_by_name', 'accepted_from_ip',
        'declined_at', 'declined_reason', 'expired_at',
        'supersedes_id', 'created_by',
    ];

    protected $casts = [
        'status' => QuoteState::class,
        'issue_date' => 'date',
        'valid_until' => 'date',
        'sent_at' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime',
        'declined_at' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime',
        'version' => 'integer',
        'presentation_exponent' => 'integer',
    ];

    /** @return HasMany<QuoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<self, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasMany<self, $this> */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    public function net(): Money
    {
        return Money::of((string) $this->net_amount, (string) $this->currency);
    }

    public function gross(): Money
    {
        return Money::of((string) $this->gross_amount, (string) $this->currency);
    }

    /**
     * Has its validity run out?
     *
     * Asked of the date rather than of the status, because the status is set
     * by a scheduled job and the answer has to be right between runs: a
     * quote whose last day was yesterday is not acceptable this morning,
     * whatever the column says.
     */
    public function hasLapsed(?\DateTimeInterface $on = null): bool
    {
        $on = $on === null ? now()->startOfDay() : \Illuminate\Support\Carbon::parse($on)->startOfDay();

        return $this->valid_until !== null && $this->valid_until->lt($on);
    }

    /** Can the customer act on it right now? */
    public function isActionable(?\DateTimeInterface $on = null): bool
    {
        return $this->status->isOpen() && ! $this->hasLapsed($on);
    }

    /** `QT-2026-00007 v2`, which is what people say out loud. */
    public function label(): string
    {
        return $this->version > 1
            ? sprintf('%s v%d', (string) $this->reference, (int) $this->version)
            : (string) $this->reference;
    }
}
