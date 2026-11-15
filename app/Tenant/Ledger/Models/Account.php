<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Ledger\Enums\AccountType;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An account in the chart - PF-037.
 *
 * ## `path` is the tree
 *
 * A materialized path of codes. Rolling a trial balance up to its parents is
 * `WHERE path LIKE '1.1.%'` on an index, rather than a recursive CTE per
 * account - which at five thousand accounts is the difference between a
 * report and a timeout.
 *
 * @property string $code
 * @property AccountType $type
 * @property string $path
 * @property bool $postable
 */
final class Account extends TenantModel
{
    use BelongsToEntity;
    use SoftDeletes;

    protected $table = 'accounts';

    protected $fillable = [
        'entity_id', 'code', 'name_ar', 'name_en', 'parent_id', 'path', 'depth',
        'type', 'statement_line', 'currency',
        'postable', 'requires_dimension', 'reconcilable', 'blocked',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'depth' => 'integer',
        'postable' => 'boolean',
        'requires_dimension' => 'boolean',
        'reconcilable' => 'boolean',
        'blocked' => 'boolean',
    ];

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /** @return HasMany<AccountRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(AccountRole::class);
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    /**
     * Everything at or below this account.
     *
     * The dot is part of the prefix so that `1.1` does not match `1.10` - an
     * off-by-one that would roll an unrelated subtree into a subtotal and
     * still balance.
     */
    public function descendants(): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()->where('path', 'like', $this->path.'.%');
    }

    public function subtree(): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()->where(fn ($q) => $q
            ->where('path', $this->path)
            ->orWhere('path', 'like', $this->path.'.%'));
    }
}
