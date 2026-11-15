<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Models;

use App\Support\Hashing\Canonicalizer;
use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A return as it was filed - PF-086.
 *
 * @property string $jurisdiction
 * @property string $form
 * @property string $label
 * @property string $currency
 * @property array<string, mixed> $figures
 * @property string $canonical
 * @property string $hash
 * @property string|null $submission_reference
 */
final class ReturnFiling extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'return_filings';

    protected $fillable = [
        'entity_id', 'return_period_id', 'jurisdiction', 'form', 'label', 'currency',
        'figures', 'canonical', 'hash', 'filed_at', 'filed_by', 'submission_reference',
    ];

    protected $casts = [
        'figures' => 'array',
        'filed_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<ReturnPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ReturnPeriod::class, 'return_period_id');
    }

    /**
     * The bytes that were filed, byte-identically.
     *
     * Stored rather than recomputed, which is the only way the promise holds:
     * recomputing relies on the canonicaliser never changing, which is a
     * promise about future code made about a document an authority may query
     * in six years.
     */
    public function reprint(): string
    {
        return $this->canonical;
    }

    /**
     * Does the stored document still hash to what was recorded?
     *
     * Asked of the stored bytes rather than of a rebuilt return. If this is
     * ever false, the row was changed by something that bypassed the trigger -
     * which is worth knowing immediately and separately from any argument
     * about the figures.
     */
    public function isIntact(): bool
    {
        $decoded = json_decode($this->canonical, true);

        if (! \is_array($decoded)) {
            return false;
        }

        return hash('sha256', $this->canonical) === $this->hash
            && Canonicalizer::encode($decoded) === $this->canonical;
    }
}
