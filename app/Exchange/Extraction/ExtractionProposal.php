<?php

declare(strict_types=1);

namespace App\Exchange\Extraction;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A machine's reading of a document - PF-142.
 *
 * Called a proposal because that is what it is. Recording an extracted
 * figure as the document's amount makes a guess indistinguishable from a
 * fact two screens later, and the bill is built from the confirmed values
 * rather than the proposed ones.
 *
 * `source` decides whether a confidence belongs on its fields at all:
 * embedded XML is exact and carries none, OCR is a guess and must carry one.
 * A trigger enforces both.
 *
 * @property string $source
 * @property string $status
 * @property string|null $engine
 */
final class ExtractionProposal extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'extraction_proposals';

    protected $fillable = [
        'entity_id', 'inbound_document_id', 'extraction_template_id',
        'source', 'status', 'engine', 'engine_version',
        'confirmed_at', 'confirmed_by', 'rejected_at', 'rejected_by', 'rejection_reason',
        'bill_id', 'note',
    ];

    protected $casts = [
        'confirmed_at' => 'immutable_datetime',
        'rejected_at' => 'immutable_datetime',
    ];

    /** @return HasMany<ExtractionField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(ExtractionField::class, 'extraction_proposal_id')->orderBy('field');
    }

    /** @return BelongsTo<ExtractionTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ExtractionTemplate::class, 'extraction_template_id');
    }

    public function isExact(): bool
    {
        return $this->source === 'embedded_xml';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isUnavailable(): bool
    {
        return $this->status === 'unavailable';
    }

    /** The values a bill should be built from: a correction where there is one. */
    public function values(): array
    {
        $values = [];

        foreach ($this->fields as $field) {
            $values[(string) $field->field] = $field->corrected_value ?? $field->proposed_value;
        }

        return $values;
    }
}
