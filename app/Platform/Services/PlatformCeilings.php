<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\Models\PlatformSetting;
use App\Support\Limits\Ceilings;
use Brick\Math\BigDecimal;
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
    /**
     * The fallbacks, as STRINGS.
     *
     * Strings because several of these are money or a ratio, and a decimal
     * default typed as a PHP float here would be the one place in the money
     * path where a float exists. `int()` casts; `decimal()` does not.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'ceiling.sod_override_days' => '30',
        'ceiling.step_up_lifetime_seconds' => '900',
        'ceiling.session_absolute_minutes' => '720',
        'ceiling.recovery_codes' => '10',

        /*
         | The tenant-configurable risk bounds - PF-074.
         |
         | Each is the furthest a customer may go, and the reason is the
         | same in every case: the setting makes a tedious job easier and,
         | past some point, makes a loss invisible. A write-off tolerance of
         | 5.00 absorbs rounding; 5,000.00 absorbs theft one invoice at a
         | time, and every individual write-off looks like housekeeping.
         */
        'ceiling.writeoff_tolerance' => '50.00',
        'ceiling.auto_match_tolerance' => '25.00',

        /*
         | The reconciliation difference tolerances - PF-164.
         |
         | Six, not one, because they absorb different things and a single
         | figure would have to be the largest of them. A rounding
         | tolerance of 0.05 is arithmetic. A short-payment tolerance of
         | 0.05 is a policy that nothing is written off without a
         | decision. Giving rounding the short-payment ceiling would be
         | harmless; giving short payment the rounding one is the whole
         | point, and the arithmetic cases are the ones that can be
         | generous.
         |
         | Short payment and overpayment are deliberately the tightest
         | that are useful. Deciding a customer need not pay is a decision
         | at any amount, and the tolerance exists only so that a
         | sub-unit residual does not demand an approval nobody reads.
         */
        'ceiling.difference_bank_charge' => '250.00',
        'ceiling.difference_fx' => '500.00',
        'ceiling.difference_short_payment' => '25.00',
        'ceiling.difference_overpayment' => '25.00',
        'ceiling.difference_rounding' => '1.00',
        'ceiling.difference_withholding' => '0.00',
        'ceiling.dunning_escalations' => '8',
        'ceiling.fx_fallback_window_days' => '90',
        'ceiling.period_reopen_days' => '365',
    ];

    /** @var ?Collection<string, PlatformSetting> */
    private ?Collection $settings = null;

    public function int(string $key): int
    {
        return (int) $this->raw($key);
    }

    /**
     * An amount or a ratio ceiling, as a decimal string.
     *
     * Never a float: a ceiling of 0.50 read through a float and compared
     * against 0.50 is a comparison that can go either way depending on how
     * each side got there, and the one that matters is the side that admits
     * a value over the limit.
     */
    public function decimal(string $key): string
    {
        return (string) BigDecimal::of($this->raw($key));
    }

    /**
     * The stored value, or the declared fallback.
     *
     * Falling back to the DEFAULT rather than to "unlimited" is the whole
     * point: a missing ceiling that reads as no limit is a ceiling removed
     * by a deployment accident, and the symptom is that nothing stops
     * working - which is exactly why nobody would notice.
     */
    private function raw(string $key): string
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

        $value = $setting->typedValue();

        return is_scalar($value) ? (string) $value : '0';
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
