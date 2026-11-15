<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Settings;

use App\Tenant\Tenancy\Settings\Exceptions\SettingRejected;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * What one setting is - PF-074.
 *
 * ## Why a declaration and not just a row
 *
 * `entity_settings` stores a key, a type and a value. That is enough to read
 * a setting back and not enough to answer any of the questions asked about
 * one:
 *
 *   - what happens if nobody set it?
 *   - is 90 a legal value, or did somebody mean 0.90?
 *   - may a tenant decide this at all, or is it the operator's?
 *   - what does it mean? (asked by whoever is about to change it)
 *
 * A row cannot answer those, so a typo creates a new setting rather than
 * failing, and an unset setting reads as null - which the caller then
 * coalesces to whatever seems sensible at that call site, differently in each
 * one.
 *
 * ## The ceiling is the part that matters
 *
 * A risk-bearing setting carries a platform ceiling the tenant may move
 * TOWARDS safety and never away from it. A write-off tolerance can be
 * lowered to zero and cannot be raised past the operator's maximum, because
 * a generous tolerance quietly absorbs real losses - and the tenant asking
 * for it is sometimes the person creating them.
 *
 * ## Refused, never clamped
 *
 * Silently clamping a request to the ceiling is worse than refusing it. The
 * tenant believes they set 500, the system uses 50, and the difference is
 * found when a write-off is rejected for a reason the screen does not
 * explain. A refusal names the ceiling and who set it.
 */
final readonly class SettingDefinition
{
    /**
     * @param  'bool'|'int'|'decimal'|'string'|'json'  $type
     * @param  'tenant'|'entity'  $scope
     * @param  'at_most'|'at_least'|null  $ceilingDirection
     * @param  list<string>|null  $allowed  for a string setting, the permitted values
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $scope,
        public string|int|bool|null $default,
        public string $descriptionAr,
        public string $descriptionEn,
        public ?string $ceilingKey = null,
        public ?string $ceilingDirection = null,
        public ?array $allowed = null,
        public ?string $min = null,
        public ?string $max = null,
    ) {}

    public function bearsRisk(): bool
    {
        return $this->ceilingKey !== null;
    }

    public function description(string $locale = 'en'): string
    {
        return str_starts_with($locale, 'ar') ? $this->descriptionAr : $this->descriptionEn;
    }

    /**
     * Coerce and validate a value for this setting.
     *
     * Returns the string form to store, because that is what the column
     * holds. A decimal stays a string the whole way: `numeric` read into a
     * float and written back is how a tolerance of 0.1 becomes 0.1000000001
     * and a comparison against it stops meaning what it says.
     *
     * @throws SettingRejected
     */
    public function normalise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            'bool' => $this->normaliseBool($value),
            'int' => $this->normaliseInt($value),
            'decimal' => $this->normaliseDecimal($value),
            'json' => $this->normaliseJson($value),
            default => $this->normaliseString($value),
        };
    }

    /** The value as the application wants it, from the stored string. */
    public function cast(?string $stored): string|int|bool|array|null
    {
        if ($stored === null) {
            return null;
        }

        return match ($this->type) {
            'bool' => $stored === '1' || $stored === 'true',
            'int' => (int) $stored,
            /*
             | A decimal comes back as a STRING, deliberately.
             |
             | A rounding tolerance or a matching threshold is money or a
             | ratio, and the money path forbids a float end to end. The
             | caller hands it to Money::of() or BigDecimal::of().
             */
            'json' => json_decode($stored, true, 512, JSON_THROW_ON_ERROR),
            default => $stored,
        };
    }

    private function normaliseBool(mixed $value): string
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw SettingRejected::notABoolean($this->key, $value);
        }

        return $parsed ? '1' : '0';
    }

    private function normaliseInt(mixed $value): string
    {
        if (! is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
            throw SettingRejected::notAnInteger($this->key, $value);
        }

        $this->assertWithinBounds((string) (int) $value);

        return (string) (int) $value;
    }

    private function normaliseDecimal(mixed $value): string
    {
        try {
            $decimal = BigDecimal::of(is_scalar($value) ? (string) $value : '');
        } catch (MathException) {
            throw SettingRejected::notADecimal($this->key, $value);
        }

        $this->assertWithinBounds((string) $decimal);

        return (string) $decimal;
    }

    private function normaliseString(mixed $value): string
    {
        if (! is_string($value)) {
            throw SettingRejected::notAString($this->key, $value);
        }

        if ($this->allowed !== null && ! in_array($value, $this->allowed, true)) {
            throw SettingRejected::notAllowed($this->key, $value, $this->allowed);
        }

        return $value;
    }

    private function normaliseJson(mixed $value): string
    {
        if (is_string($value)) {
            json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The definition's own bounds, separate from the platform ceiling.
     *
     * Two different things, and conflating them loses the reason for each:
     * bounds say what the setting CAN mean - a percentage above 100 is not a
     * percentage - and the ceiling says what this tenant is ALLOWED to
     * choose within that. One is arithmetic and the other is policy.
     *
     * @throws SettingRejected
     */
    private function assertWithinBounds(string $value): void
    {
        $decimal = BigDecimal::of($value);

        if ($this->min !== null && $decimal->isLessThan(BigDecimal::of($this->min))) {
            throw SettingRejected::belowMinimum($this->key, $value, $this->min);
        }

        if ($this->max !== null && $decimal->isGreaterThan(BigDecimal::of($this->max))) {
            throw SettingRejected::aboveMaximum($this->key, $value, $this->max);
        }
    }
}
