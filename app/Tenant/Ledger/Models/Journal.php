<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * A journal - PF-040.
 *
 * @property string $code
 * @property string $type
 * @property bool $reserved
 */
final class Journal extends TenantModel
{
    protected $table = 'journals';

    use BelongsToEntity;

    protected $fillable = [
        'entity_id', 'code', 'type', 'name_ar', 'name_en',
        'reserved', 'posting_rule', 'allowed_account_paths', 'active',
    ];

    protected $casts = [
        'reserved' => 'boolean',
        'active' => 'boolean',
        'allowed_account_paths' => 'array',
    ];

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    /**
     * May this journal carry a line to this account?
     *
     * Checked by PATH PREFIX rather than against a list of account ids,
     * because a chart grows and a list does not - and a restriction that
     * silently stops covering new accounts is worse than none, since nobody
     * notices it has lapsed.
     */
    public function accepts(Account $account): bool
    {
        $allowed = $this->allowed_account_paths;

        if ($allowed === null || $allowed === []) {
            return true;
        }

        foreach ($allowed as $prefix) {
            if ($account->path === $prefix || str_starts_with($account->path, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }
}
