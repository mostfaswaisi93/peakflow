<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * How much difference is not worth a person's attention - PF-135.
 *
 * ## Both absolute and percentage, because neither alone works
 *
 * Two per cent of a fifty-unit order is one unit, which is a reasonable
 * tolerance. Two per cent of a fifty-thousand-unit order is a thousand
 * units, which is a theft. A flat tolerance of one unit, meanwhile, flags
 * every bulk delivery that arrived a kilogram light.
 *
 * So both are configured and the **larger** of the two is allowed: the
 * percentage handles scale and the absolute handles the small orders where a
 * percentage rounds to nothing.
 *
 * ## The ceiling is in the database, not here
 *
 * A tenant who sets a fifty per cent tolerance has turned the match off, and
 * the thing a three-way match exists for is the invoice that is quietly
 * twenty per cent too high. The constraint
 * `supplier_match_tolerances_within_the_platform_ceiling` refuses it, because
 * a limit enforced only in a service is a limit an import does not have.
 */
final class SupplierMatchTolerance extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'supplier_match_tolerances';

    protected $fillable = [
        'entity_id', 'party_id', 'line_type',
        'quantity_percent', 'quantity_absolute',
        'price_percent', 'price_absolute', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * The quantity difference this tolerance permits on a given order size.
     *
     * The larger of the percentage and the absolute, for the reason in the
     * class docblock: the percentage carries scale and the absolute carries
     * the small orders where a percentage rounds to nothing.
     */
    public function quantityAllowance(BigDecimal $ordered): BigDecimal
    {
        $fromPercent = $ordered->abs()
            ->multipliedBy((string) $this->quantity_percent)
            ->dividedBy('100', 8, RoundingMode::HalfUp);

        $absolute = BigDecimal::of((string) $this->quantity_absolute);

        return $fromPercent->isGreaterThan($absolute) ? $fromPercent : $absolute;
    }

    public function priceAllowance(BigDecimal $orderedPrice): BigDecimal
    {
        $fromPercent = $orderedPrice->abs()
            ->multipliedBy((string) $this->price_percent)
            ->dividedBy('100', 8, RoundingMode::HalfUp);

        $absolute = BigDecimal::of((string) $this->price_absolute);

        return $fromPercent->isGreaterThan($absolute) ? $fromPercent : $absolute;
    }

    /**
     * The tolerance that permits nothing - the default when none is set.
     *
     * Zero rather than a lenient default, because a tolerance nobody
     * configured is a tolerance nobody decided on, and the safe reading of
     * "nobody decided" is "ask a person".
     */
    public static function strict(): self
    {
        return new self([
            'quantity_percent' => '0',
            'quantity_absolute' => '0',
            'price_percent' => '0',
            'price_absolute' => '0',
        ]);
    }
}
