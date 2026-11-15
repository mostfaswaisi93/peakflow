<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Models\Permission;
use Illuminate\Support\Collection;

/**
 * What permissions exist, and what each one demands of the user's session.
 *
 * Read once per request. The catalogue is seeded and changes only with a
 * deployment, so re-reading it for every one of the dozens of authorization
 * questions a single screen asks would be waste - but it is not cached across
 * requests either, because a permission's `requires_mfa` flag tightening in a
 * deployment must take effect immediately and not when a TTL lapses.
 *
 * ## An unknown ability is not a permission
 *
 * `Gate` is asked about policies, route abilities and permission keys through
 * the same method. The catalogue is how the gate tells them apart: an ability
 * that is not in here is somebody else's business and is passed through
 * untouched. Treating an unknown ability as a denied permission would break
 * every policy in the application; treating it as granted would be a hole.
 */
final class PermissionCatalogue
{
    /** @var ?Collection<string, Permission> */
    private ?Collection $byKey = null;

    public function has(string $key): bool
    {
        return $this->all()->has($key);
    }

    public function get(string $key): ?Permission
    {
        return $this->all()->get($key);
    }

    /** @return Collection<string, Permission> */
    public function all(): Collection
    {
        return $this->byKey ??= Permission::query()->get()->keyBy('key');
    }

    /** @return Collection<int, Permission> */
    public function requiringMfa(): Collection
    {
        return $this->all()->filter(fn (Permission $p): bool => $p->requires_mfa)->values();
    }

    public function flush(): void
    {
        $this->byKey = null;
    }
}
