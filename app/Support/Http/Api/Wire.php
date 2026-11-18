<?php

declare(strict_types=1);

namespace App\Support\Http\Api;

use App\Support\Money\Money;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How money and dates leave the building - PF-222.
 *
 * ## Money is an object with a string amount, always
 *
 * ```json
 * { "amount": "1150.0000", "currency": "SAR", "presentation_exponent": 2 }
 * ```
 *
 * A bare number is never returned, and the bug this prevents is not
 * theoretical. `JOD 1.234` serialised as the JSON number `1.234` arrives in
 * JavaScript as an IEEE 754 double; the nearest double to 1.234 is
 * 1.2339999999999999857891452847979962825775146484375, and every client that
 * formats it with two decimals - which is every client, because two is the
 * default everywhere - writes 1.23 on an invoice for a three-decimal
 * currency. Nothing errors, the sum of the lines no longer equals the total,
 * and the difference is a third of a fils per line.
 *
 * The amount is therefore a string at the storage scale (four decimals,
 * whatever the currency's own exponent is), and the currency's presentation
 * exponent is a SEPARATE field. The client formats; the server does not
 * pre-format. A server that sent `"1.23"` for a JOD amount would have made
 * the same mistake one layer earlier and hidden the evidence.
 *
 * ## An accounting date is a date
 *
 * `"2026-11-30"`, never `"2026-11-30T00:00:00Z"`. The difference is a period.
 * Midnight on the 30th in UTC is 03:00 on the 30th in Riyadh, and midnight on
 * the 1st in Riyadh is 21:00 on the 30th in UTC - so a timestamp for an
 * accounting date is one timezone conversion away from moving an entry into
 * another month, another quarter and another tax return. Nobody finds it,
 * because both dates are plausible and both are "the same day" to whoever is
 * looking.
 *
 * An event timestamp - when a token was last used, when an entry was posted -
 * IS a moment, and is RFC 3339 in UTC with a `Z`. The two are different
 * kinds, so they have different methods here and no method takes a flag.
 *
 * ## Why a class and not a trait on each model
 *
 * Because `ApiContractRules` walks real responses looking for a JSON number
 * where money belongs, and a single chokepoint is what makes that check
 * meaningful: a controller that formats an amount itself is the thing the
 * check is looking for, and having somewhere obvious to go instead is what
 * stops it being written.
 */
final class Wire
{
    /**
     * Money, as an object.
     *
     * @return array{amount: string, currency: string, presentation_exponent: int}
     */
    public static function money(Money $money): array
    {
        /** @var array{amount: string, currency: string, presentation_exponent: int} $wire */
        $wire = $money->jsonSerialize();

        return $wire;
    }

    /**
     * Money, from the exact decimal string a `numeric` column returns.
     *
     * Typed `string|int` on purpose: a float here would be a float in the
     * money path, which the architecture rule forbids and which
     * `declare(strict_types=1)` turns into a TypeError at the call site
     * rather than a rounding error four layers downstream.
     *
     * @return array{amount: string, currency: string, presentation_exponent: int}
     */
    public static function amount(string|int $amount, string $currency): array
    {
        return self::money(Money::of((string) $amount, $currency));
    }

    /**
     * An accounting date: `YYYY-MM-DD`, and never a timestamp.
     *
     * Formatted from the date parts alone, so a value that arrived carrying a
     * time cannot contribute one. If the stored value is somehow a moment,
     * the date it is on is what this answers with - the alternative is
     * leaking a time into a field a client is entitled to treat as a date.
     */
    public static function accountingDate(DateTimeInterface|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return Carbon::parse($date)->format('Y-m-d');
    }

    /**
     * An event timestamp: RFC 3339, UTC, with a `Z`.
     *
     * UTC and not the tenant's zone. A client that has to know which zone a
     * timestamp is in before it can order two of them has been handed the
     * ordering problem, and the first symptom is a delivery log that appears
     * to go backwards for one hour twice a year.
     */
    public static function timestamp(DateTimeInterface|string|null $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        return Carbon::parse($moment)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
