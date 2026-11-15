<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use App\Support\Iso\Iban;

/**
 * The second layer: values whose SHAPE must never reach a log - PF-014.
 *
 * ## The allowlist controls fields; it cannot control what is put in one
 *
 * `['reference' => 'SA0380000000608010167519']` passes a field allowlist,
 * because `reference` is a legitimate field and the allowlist has no way to
 * know what a caller put in it. Measured: an IBAN passed under an allowlisted
 * name survived every field check and reached the log line.
 *
 * ## And this is NOT the blocklist that was rejected
 *
 * A blocklist of field names fails open the first time somebody adds a field.
 * This inspects VALUES, so a new field cannot bypass it - the shape is
 * checked wherever it appears, under any name, at any depth.
 *
 * ## Only shapes with CHECK DIGITS, which is what makes it safe to apply
 *
 * An IBAN is mod-97 verified and a card number is Luhn verified, so a false
 * positive needs a string that accidentally satisfies a checksum - roughly
 * one in 97 for an IBAN-shaped string and one in 10 for a Luhn-shaped one,
 * of strings that already look exactly like the thing.
 *
 * A heuristic for "looks like a token" was considered and left out. It would
 * redact account codes, document numbers and correlation ids, and a scrubber
 * that eats the diagnostics is a scrubber somebody turns off. The fields that
 * carry tokens are not on the allowlist, which is the right control for them.
 *
 * ## What replaces it says what was removed
 *
 * `[IBAN …7519 removed]` rather than `[REDACTED]`. The person reading the log
 * needs to know a value was removed, what kind it was, and enough to tie it
 * to a record - and the last four digits are what every bank statement and
 * support call already uses.
 */
final class SecretShapes
{
    /**
     * Scrub a value, whatever shape it has.
     *
     * Non-strings pass through: an integer cannot hold an IBAN, and casting
     * everything to string to check it would stringify objects, which is the
     * leak this is trying to prevent.
     */
    public static function scrub(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $scrubbed = self::scrubIbans($value);

        return self::scrubCardNumbers($scrubbed);
    }

    /**
     * An IBAN anywhere in the string, including inside a sentence.
     *
     * Because the value that leaks is often a message rather than a field:
     * "could not validate SA0380000000608010167519" is a perfectly ordinary
     * exception message, and the exception message IS the log line.
     */
    private static function scrubIbans(string $value): string
    {
        return (string) preg_replace_callback(
            '/\b[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}\b/',
            static function (array $match): string {
                $candidate = $match[0];

                /*
                 | Verified, not merely shaped.
                 |
                 | `Iban::tryFrom()` checks the mod-97 and the country's
                 | declared length, so an account code or a document number
                 | that happens to look like one is left alone - which is the
                 | difference between a scrubber people keep and one they
                 | turn off.
                 */
                if (Iban::tryFrom($candidate) === null) {
                    return $candidate;
                }

                return sprintf('[IBAN …%s removed]', mb_substr($candidate, -4));
            },
            $value,
        );
    }

    /**
     * A card number: 13 to 19 digits that satisfy Luhn.
     *
     * Grouped forms too - `4111 1111 1111 1111` and `4111-1111-1111-1111` -
     * because that is how somebody pastes one into a support note, and a
     * support note is the field most likely to be logged.
     */
    private static function scrubCardNumbers(string $value): string
    {
        return (string) preg_replace_callback(
            '/\b(?:\d[ -]?){12,18}\d\b/',
            static function (array $match): string {
                $digits = preg_replace('/\D/', '', $match[0]) ?? '';

                if (mb_strlen($digits) < 13 || mb_strlen($digits) > 19 || ! self::passesLuhn($digits)) {
                    return $match[0];
                }

                return sprintf('[card …%s removed]', mb_substr($digits, -4));
            },
            $value,
        );
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = mb_strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
