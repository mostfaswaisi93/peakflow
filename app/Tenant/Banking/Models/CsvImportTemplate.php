<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Banking\Parsers\CsvDialect;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Builder;

/**
 * A bank's CSV layout, saved - PF-154.
 *
 * A bank's export does not change from month to month, and making somebody
 * map twelve columns every month guarantees that one month two of them go
 * the wrong way round. That import is plausible rather than broken: the
 * value date in the booking date column shifts a reconciliation by two days
 * and nothing says so.
 *
 * @property array<string, mixed> $dialect
 * @property array<string, mixed> $column_map
 * @property array<int, string>|null $header_row
 */
final class CsvImportTemplate extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'csv_import_templates';

    protected $fillable = [
        'id', 'entity_id', 'name', 'institution_name', 'bank_account_id',
        'dialect', 'column_map', 'header_row', 'is_shared',
        'last_used_at', 'times_used', 'created_by',
    ];

    protected $casts = [
        'dialect' => 'array',
        'column_map' => 'array',
        'header_row' => 'array',
        'is_shared' => 'boolean',
        'last_used_at' => 'immutable_datetime',
        'times_used' => 'integer',
    ];

    /**
     * The templates offered for an account.
     *
     * Its own first, then the entity's others, then anything shared. A
     * business with two accounts at the same bank uses one template for
     * both, so a template bound to no account is not a worse match - it is
     * the normal one.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAccount(Builder $query, string $accountId): Builder
    {
        return $query->where(static function (Builder $query) use ($accountId): void {
            $query->where('bank_account_id', $accountId)
                ->orWhereNull('bank_account_id')
                ->orWhere('is_shared', true);
        });
    }

    public function dialect(): CsvDialect
    {
        return (new CsvDialect())->with((array) $this->dialect);
    }

    /** @return array<string, int> */
    public function columnMap(): array
    {
        $map = [];

        foreach ((array) $this->column_map as $field => $index) {
            if (is_numeric($index)) {
                $map[(string) $field] = (int) $index;
            }
        }

        return $map;
    }

    /**
     * Whether the file still has the columns this template was built against.
     *
     * A bank that adds a column shifts every index after it, and the import
     * would otherwise read the balance column as the amount - every row
     * plausible, every row wrong. Checked by comparing the header rather
     * than trusting the indices, because the indices are exactly what moved.
     *
     * @param  list<string>  $header
     */
    public function matches(array $header): bool
    {
        $stored = (array) $this->header_row;

        if ($stored === []) {
            return true;
        }

        return array_map(trim(...), $stored) === array_map(trim(...), $header);
    }
}
