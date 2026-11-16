<?php

declare(strict_types=1);

namespace App\Exchange\Extraction;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A supplier's document format, and how well it reads - PF-142.
 *
 * The counters are counters rather than a rate computed on read, because the
 * fields of a confirmed proposal are frequently pruned - and a derived rate
 * would fall to zero as they went, which reads as an engine that improved.
 *
 * @property string $key
 * @property int $proposals
 * @property int $fields_proposed
 * @property int $fields_corrected
 */
final class ExtractionTemplate extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'extraction_templates';

    protected $fillable = [
        'entity_id', 'party_id', 'key', 'label',
        'proposals', 'fields_proposed', 'fields_corrected', 'last_seen_at',
    ];

    protected $casts = [
        'proposals' => 'integer',
        'fields_proposed' => 'integer',
        'fields_corrected' => 'integer',
        'last_seen_at' => 'immutable_datetime',
    ];

    /**
     * The share of proposed fields a person had to correct.
     *
     * Null rather than zero where nothing has been proposed yet. A format
     * that has never been read is not a format that reads perfectly, and
     * putting it at the top of a list sorted by accuracy would hide the one
     * that does need attention.
     */
    public function correctedShare(): ?string
    {
        if ($this->fields_proposed <= 0) {
            return null;
        }

        return (string) BigDecimal::of((string) $this->fields_corrected)
            ->dividedBy((string) $this->fields_proposed, 4, RoundingMode::HalfUp)
            ->multipliedBy('100');
    }
}
