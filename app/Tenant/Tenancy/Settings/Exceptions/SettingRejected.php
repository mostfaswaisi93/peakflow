<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Settings\Exceptions;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use RuntimeException;

/**
 * A setting value that will not be stored - PF-074.
 *
 * ## Every message names the value AND the rule
 *
 * Whoever reads one of these is on a settings screen with a number they
 * believe is right. "Invalid value" sends them to support; "0.9 is below the
 * minimum of 1 - did you mean 90?" is the whole conversation.
 *
 * The "did you mean" cases are not decoration. A percentage typed as a
 * fraction and a fraction typed as a percentage are the two mistakes that
 * actually happen, they differ by a factor of a hundred, and both look
 * plausible in a text field.
 */
final class SettingRejected extends RuntimeException
{
    public static function unknownKey(string $key, array $known): self
    {
        /*
         | The near misses, because the usual cause is a typo or a renamed
         | key - and an unknown key that is silently CREATED is worse than
         | one refused: the setting appears to be saved, nothing reads it,
         | and the behaviour everyone expected never changes.
         */
        $suggestions = array_values(array_filter(
            $known,
            static fn (string $candidate): bool => levenshtein($candidate, $key) <= 4
                || str_contains($candidate, explode('.', $key)[0] ?? ''),
        ));

        return new self(sprintf(
            'There is no setting called "%s". An unknown key is refused rather than created, because a '.
            'created one looks saved, is read by nothing, and the behaviour somebody wanted never changes.%s',
            $key,
            $suggestions === [] ? '' : ' Did you mean '.implode(', ', array_slice($suggestions, 0, 3)).'?',
        ));
    }

    public static function wrongScope(string $key, string $scope, bool $entityGiven): self
    {
        return new self($scope === 'tenant'
            ? sprintf(
                '"%s" is a tenant-wide setting and was given an entity. One company deciding it and the '.
                'others not is the state this scope exists to prevent.',
                $key,
            )
            : sprintf(
                '"%s" is a per-entity setting and no entity was named%s. A per-entity setting with no entity '.
                'has no row to live in, and defaulting to "all of them" is how one company\'s policy becomes '.
                'everybody\'s.',
                $key,
                $entityGiven ? '' : '',
            ));
    }

    public static function notABoolean(string $key, mixed $value): self
    {
        return new self(sprintf(
            '"%s" is a yes/no setting and %s is neither. Note that the string "false" is TRUE to PHP, which '.
            'is why the type is recorded and the cast happens in one place.',
            $key,
            self::show($value),
        ));
    }

    public static function notAnInteger(string $key, mixed $value): self
    {
        return new self(sprintf(
            '"%s" is a whole number and %s is not one. A value truncated silently - 2.7 becoming 2 - is a '.
            'setting that is off by a day, a retry or a percent, and nobody can see why.',
            $key,
            self::show($value),
        ));
    }

    public static function notADecimal(string $key, mixed $value): self
    {
        return new self(sprintf('"%s" is a decimal amount or ratio and %s is not a number.', $key, self::show($value)));
    }

    public static function notAString(string $key, mixed $value): self
    {
        return new self(sprintf('"%s" is text and %s is not.', $key, self::show($value)));
    }

    public static function notAllowed(string $key, string $value, array $allowed): self
    {
        return new self(sprintf(
            '"%s" accepts only %s, and %s is not one of them. A value outside the list reaches a match '.
            'statement that has no case for it, which fails at the moment the setting is USED rather than '.
            'when it was set.',
            $key,
            implode(', ', $allowed),
            self::show($value),
        ));
    }

    public static function belowMinimum(string $key, string $value, string $min): self
    {
        return new self(sprintf(
            '%s is below the minimum of %s for "%s".%s',
            $value,
            $min,
            $key,
            self::didYouMeanPercent($value, $min),
        ));
    }

    public static function aboveMaximum(string $key, string $value, string $max): self
    {
        return new self(sprintf(
            '%s is above the maximum of %s for "%s".%s',
            $value,
            $max,
            $key,
            self::didYouMeanFraction($value, $max),
        ));
    }

    /**
     * Over the platform's ceiling - PF-074.
     *
     * Refused, not clamped. Clamping leaves the tenant believing they set
     * 500 while the system uses 50, and the difference is found when a
     * write-off is rejected for a reason the screen does not explain.
     */
    public static function overCeiling(string $key, string $requested, string $ceiling, string $direction): self
    {
        return new self(sprintf(
            '%s is %s the limit of %s that the platform sets for "%s", so it was refused rather than quietly '.
            'reduced to %s - a value silently changed is one you would go on believing you had set. You can '.
            'move this setting %s that limit as far as you like.',
            $requested,
            $direction === 'at_most' ? 'above' : 'below',
            $ceiling,
            $key,
            $ceiling,
            $direction === 'at_most' ? 'below' : 'above',
        ));
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"'.$value.'"',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }

    /**
     * 0.9 where 90 was meant.
     *
     * A percentage typed as a fraction. It differs by a factor of a hundred,
     * looks plausible in a text field, and the resulting behaviour is wrong
     * in a direction nobody questions - a 0.9% tolerance simply never fires.
     */
    private static function didYouMeanPercent(string $value, string $min): string
    {
        $decimal = self::decimalOrNull($value);

        if ($decimal === null || ! $decimal->isPositive() || ! $decimal->isLessThan(BigDecimal::one())) {
            return '';
        }

        return sprintf(
            ' Did you mean %s - a percentage rather than a fraction?',
            (string) $decimal->multipliedBy('100')->strippedOfTrailingZeros(),
        );
    }

    /** 90 where 0.9 was meant: the same mistake the other way round. */
    private static function didYouMeanFraction(string $value, string $max): string
    {
        $decimal = self::decimalOrNull($value);
        $ceiling = self::decimalOrNull($max);

        if ($decimal === null || $ceiling === null) {
            return '';
        }

        if ($ceiling->isGreaterThan(BigDecimal::one()) || ! $decimal->isGreaterThan(BigDecimal::one())) {
            return '';
        }

        return sprintf(
            ' Did you mean %s - a fraction rather than a percentage?',
            (string) $decimal->dividedBy('100', 12, RoundingMode::HalfUp)->strippedOfTrailingZeros(),
        );
    }

    /**
     * Parsed as a decimal, or null if it is not a number.
     *
     * BigDecimal and not a float cast, even here in a message formatter.
     * The architecture rule that forbids floats in the money path caught the
     * first version of these hints, and it was right to: an exemption for
     * "it is only a message" is an exemption somebody copies into a place
     * where it is not only a message.
     */
    private static function decimalOrNull(string $value): ?BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException) {
            return null;
        }
    }
}
