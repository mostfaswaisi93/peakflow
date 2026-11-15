<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * What an item means to one entity - PF-064.
 *
 * The revenue account, the expense account, the tax code and the default
 * dimensions. Per entity because the chart of accounts differs: a subsidiary
 * in another jurisdiction has different statutory account codes, and a shared
 * default would post the same item into an account that does not exist there.
 *
 * All nullable. A missing default is not an error - it means the posting rule
 * decides, which is the normal case for an item sold by one company out of
 * five.
 */
final class ItemEntityDefault extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'item_entity_defaults';

    protected $fillable = [
        'entity_id', 'item_id', 'revenue_account_id', 'expense_account_id', 'tax_code_id',
        'dimension_1', 'dimension_2', 'dimension_3', 'dimension_4',
        'dimension_5', 'dimension_6', 'dimension_7', 'dimension_8',
    ];

    /**
     * The dimensions, as the posting line wants them.
     *
     * @return array<int, string|null>
     */
    public function dimensions(): array
    {
        $dimensions = [];

        for ($axis = 1; $axis <= 8; $axis++) {
            $value = $this->{'dimension_'.$axis};

            if ($value !== null) {
                $dimensions[$axis] = (string) $value;
            }
        }

        return $dimensions;
    }
}
