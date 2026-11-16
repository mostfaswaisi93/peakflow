<?php

declare(strict_types=1);

namespace App\Exchange\Extraction;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field a machine read, and what a person made of it - PF-142.
 *
 * `needs_review` is stored rather than computed from the confidence, because
 * a threshold that changed would move it: a reviewer who confirmed a field
 * that is now flagged would look careless rather than consistent.
 *
 * @property string $field
 * @property string|null $proposed_value
 * @property string|null $confidence
 * @property bool $needs_review
 * @property string|null $corrected_value
 */
final class ExtractionField extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'extraction_fields';

    protected $fillable = [
        'entity_id', 'extraction_proposal_id', 'field', 'proposed_value',
        'confidence', 'needs_review', 'corrected_value', 'corrected_at', 'corrected_by',
    ];

    protected $casts = [
        'needs_review' => 'boolean',
        'corrected_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<ExtractionProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ExtractionProposal::class, 'extraction_proposal_id');
    }

    public function wasCorrected(): bool
    {
        return $this->corrected_value !== null;
    }

    /** What a bill should be built from: the correction, else the proposal. */
    public function value(): ?string
    {
        return $this->corrected_value ?? $this->proposed_value;
    }
}
