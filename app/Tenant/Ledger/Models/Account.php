<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Ledger\Enums\AccountType;
use App\Tenant\Ledger\Services\AccountTree;
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
        'postable', 'requires_dimension', 'reconcilable', 'blocked', 'monetary',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'depth' => 'integer',
        'postable' => 'boolean',
        'requires_dimension' => 'boolean',
        'reconcilable' => 'boolean',
        'blocked' => 'boolean',
        // Nullable on purpose: null is "not classified", which is reported
        // by fx:revalue rather than treated as non-monetary. See the
        // migration note - a silent default here is wrong in whichever
        // direction it happens to point.
        'monetary' => 'boolean',
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
     * Everything below this account.
     *
     * ## A range, not a LIKE, and the difference is a different plan
     *
     * Both of these read as a prefix match and only one of them is served by
     * the index. Measured on a chart of 5,055 accounts, asking for the 100
     * under `1.1`:
     *
     *   path LIKE '1.1.%'                  no index, 4,955 rows discarded
     *   path >= '1.1.' AND path < '1.1/'   index scan, 0 discarded
     *
     * The LIKE reads every account in the entity. Its cost grows with the
     * size of the chart rather than the size of the subtree, which is fine
     * at five thousand and is paid on every roll-up in every report.
     *
     * The dot is still part of the lower bound, so that `1.1` does not match
     * `1.10` - an off-by-one that would roll an unrelated subtree into a
     * subtotal and still balance.
     *
     * The bounds are built by {@see AccountTree}, which owns the sentinel
     * that makes the upper one exact.
     */
    public function descendants(): \Illuminate\Database\Eloquent\Builder
    {
        return AccountTree::rangeOf(self::query(), (string) $this->path, false);
    }

    /** This account and everything below it, in one range. */
    public function subtree(): \Illuminate\Database\Eloquent\Builder
    {
        return AccountTree::rangeOf(self::query(), (string) $this->path, true);
    }
}
