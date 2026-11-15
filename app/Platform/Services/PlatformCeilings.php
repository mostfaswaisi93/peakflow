<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\Models\PlatformSetting;
use App\Support\Limits\Ceilings;
use Illuminate\Support\Collection;

/**
 * Reads the platform-wide ceilings - PF-036.
 *
 * ## A ceiling is enforced, or it is a comment
 *
 * Every key here is read by code that refuses when it is exceeded. A console
 * that displays a limit nothing applies is worse than no console: it tells an
 * operator the system is bounded when it is not, and they will say so to a
 * customer.
 *
 * ## Falling back is the dangerous direction
 *
 * If a setting is missing - a fresh database, a failed seed - the fallback is
 * the value in `DEFAULTS`, not "unlimited". A missing ceiling that reads as
 * "no limit" is a ceiling removed by a deployment accident, and the symptom is
 * that nothing stops working, which is exactly why nobody would notice.
 */
final class PlatformCeilings implements Ceilings
{
    /** @var array<string, int> */
    private const DEFAULTS = [
        'ceiling.sod_override_days' => 30,
        'ceiling.step_up_lifetime_seconds' => 900,
        'ceiling.session_absolute_minutes' => 720,
        'ceiling.recovery_codes' => 10,
    ];

    /** @var ?Collection<string, PlatformSetting> */
    private ?Collection $settings = null;

    public function int(string $key): int
    {
        $setting = $this->all()->get($key);

        if ($setting === null) {
            return self::DEFAULTS[$key]
                ?? throw new \LogicException(sprintf(
                    'No ceiling named "%s" is defined. A ceiling with no default is a ceiling that silently '.
                    'becomes "unlimited" the first time a seed fails.',
                    $key,
                ));
        }

        return (int) $setting->typedValue();
    }

    /**
     * Clamp a requested value to the ceiling, and say whether it was clamped.
     *
     * Returns both, because a caller that only gets the clamped number cannot
     * tell the user their request was reduced - and silently giving somebody
     * less than they asked for is how a control becomes a mystery.
     *
     * @return array{value: int, clamped: bool, ceiling: int}
     */
    public function clamp(string $key, int $requested): array
    {
        $ceiling = $this->int($key);

        return [
            'value' => min($requested, $ceiling),
            'clamped' => $requested > $ceiling,
            'ceiling' => $ceiling,
        ];
    }

    /** @return Collection<string, PlatformSetting> */
    public function all(): Collection
    {
        return $this->settings ??= PlatformSetting::query()->get()->keyBy('key');
    }

    /** @return Collection<string, PlatformSetting> */
    public function ceilings(): Collection
    {
        return $this->all()->filter(static fn (PlatformSetting $s): bool => (bool) $s->is_ceiling);
    }

    public function flush(): void
    {
        $this->settings = null;
    }
}
