<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Models;

use App\Support\Money\Money;
use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * What the engine proposed, with its reasoning - PF-156, PF-161.
 *
 * The outcome lives here rather than in a counter per pass, which is what
 * makes the accuracy report real: a counter drifts the first time a
 * proposal is reversed or two tabs decide the same one, and a drifting
 * accuracy report is worse than none - a degrading pass is exactly what it
 * exists to show.
 *
 * @property array<string, mixed> $factors
 * @property string $status
 * @property string $pass
 */
final class MatchProposal extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'match_proposals';

    protected $fillable = [
        'id', 'entity_id', 'bank_transaction_id', 'booking_date',
        'matched_type', 'matched_id', 'amount', 'currency',
        'pass', 'confidence', 'reason', 'factors', 'rank', 'was_ambiguous',
        'status', 'decided_at', 'decided_by', 'match_id', 'matching_rule_id',
    ];

    protected $casts = [
        'factors' => 'array',
        'booking_date' => 'immutable_date',
        'decided_at' => 'immutable_datetime',
        'rank' => 'integer',
        'was_ambiguous' => 'boolean',
    ];

    /**
     * The score as stored, which is a string.
     *
     * Deliberately not cast. `confidence` is `decimal(5,4)` and the rest of
     * this codebase reads decimals as strings, so a float cast here would
     * be the one attribute that behaves differently - and the places that
     * want a number say so at the point they want it.
     */
    public function score(): float  // @arch-allow: a proportion, not money - it never becomes a ledger amount.
    {
        return (float) (string) $this->confidence;  // @arch-allow: as above.
    }

    public function money(): Money
    {
        return Money::of((string) $this->amount, (string) $this->currency);
    }

    public function isDecided(): bool
    {
        return $this->status !== 'proposed';
    }
}
