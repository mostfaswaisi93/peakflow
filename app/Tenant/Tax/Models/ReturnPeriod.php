<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Models;

use App\Support\Database\Concerns\HasValidityRange;
use App\Support\Database\TenantModel;
use App\Tenant\Tax\Enums\FilingFrequency;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Carbon\CarbonInterface;

/**
 * One window a return covers, for one registration - PF-085.
 *
 * @property string $jurisdiction
 * @property FilingFrequency $frequency
 * @property string $label
 * @property string $state
 * @property string $period
 * @property string|null $submission_reference
 */
final class ReturnPeriod extends TenantModel
{
    use BelongsToEntity;
    use HasValidityRange;

    protected $table = 'return_periods';

    protected $fillable = [
        'entity_id', 'jurisdiction', 'frequency', 'label', 'state', 'period',
        'due_on', 'filed_at', 'filed_by', 'submission_reference',
    ];

    protected $casts = [
        'frequency' => FilingFrequency::class,
        'due_on' => 'date',
        'filed_at' => 'immutable_datetime',
    ];

    /**
     * The range lives in `period`, not `validity`.
     *
     * A tax code's row is VALID for a period; this row IS a period. Calling
     * the column `validity` would make every query read as though it were
     * asking the first question.
     */
    protected static function validityColumn(): string
    {
        return 'period';
    }

    public function isFiled(): bool
    {
        return $this->state === 'filed';
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }

    /** Is a document with this tax point inside this window? */
    public function covers(CarbonInterface $taxPoint): bool
    {
        return $this->isEffectiveOn($taxPoint);
    }

    public function describe(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return $arabic
            ? sprintf(
                'إقرار %s %s (%s) - %s',
                $this->jurisdiction, $this->label, $this->frequency->describe('ar'),
                $this->isFiled() ? 'مقدَّم' : 'مفتوح',
            )
            : sprintf(
                'the %s %s %s return - %s',
                $this->jurisdiction, $this->label, $this->frequency->describe('en'),
                $this->isFiled() ? 'filed' : 'open',
            );
    }
}
