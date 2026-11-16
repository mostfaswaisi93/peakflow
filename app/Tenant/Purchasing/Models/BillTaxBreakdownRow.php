<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * One rate group of a bill's tax - PF-133.
 *
 * Grouped for the same reason the sales side is: tax summed per line and tax
 * summed per rate group differ by cents on any document with a few lines,
 * and the return is filed from the group.
 *
 * `reverse_charge` is part of the key. The same category and rate under the
 * reverse charge is a different box on the return - both an input and an
 * output - and merging the two would make the return's two sides
 * indistinguishable.
 *
 * @property TaxCategory $category
 * @property string $rate
 * @property string $taxable_amount
 * @property string $tax_amount
 * @property string $recoverable_amount
 * @property bool $reverse_charge
 */
final class BillTaxBreakdownRow extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'bill_tax_breakdown';

    protected $fillable = [
        'entity_id', 'bill_id', 'category', 'rate', 'tax_code_id',
        'taxable_amount', 'tax_amount', 'recoverable_amount', 'reverse_charge', 'report_box',
    ];

    protected $casts = [
        'category' => TaxCategory::class,
        'reverse_charge' => 'boolean',
    ];
}
