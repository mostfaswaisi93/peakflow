<?php

declare(strict_types=1);

namespace App\Support\Iso;

use App\Support\Iso\Data\VendoredRegistry;
use App\Support\Iso\Exceptions\RegistryDataUnusable;
use App\Support\Iso\Exceptions\UnknownCurrency;
use NumberFormatter;

/**
 * ISO 4217 currencies, and - the part that actually matters - their minor units.
 *
 * Provenance: `database/iso/currencies.tsv`, recorded in `database/iso/provenance.json`
 * with how it was obtained and what it was cross-checked against - PF-007. The table
 * was a packed string constant in this class until the provenance requirement made that
 * untenable: `JOD:400:3` losing its `:3` in a diff is one character, and the consequence
 * is that every Jordanian invoice rounds to two places.
 *
 * ## Why the exponent is the whole point of this class
 *
 * It is not always 2:
 *
 * - **3 decimals**: BHD, IQD, JOD, KWD, LYD, OMR, TND. Four of these are target
 *   market currencies, so this is the normal case and not an edge case. Assuming
 *   2 turns `JOD 1.234` into `1.23` - a **1000x error that looks plausible**,
 *   because `1.23` where `1,234.000` belongs is a number an eye slides over.
 * - **0 decimals**: JPY, KRW, VND, CLP, ISK, XAF, XOF and others. Assuming 2
 *   invents a fractional part that does not exist.
 * - **4 decimals**: CLF, UYW.
 * - **Non-decimal subunits**: MGA (1 ariary = 5 iraimbilanja) and MRU
 *   (1 ouguiya = 5 khoums) are base-5. **No power of ten represents them**,
 *   which is one of the three reasons minor-unit integer storage was rejected
 *   outright - see ADR-0004.
 *
 * Names and symbols are resolved through ICU rather than stored here. ICU
 * already knows every currency in every locale PeakFlow serves, and a
 * hand-maintained Arabic list beside it would be a worse copy of the same data.
 */
final class CurrencyRegistry
{
    /** The vendored file this registry reads. */
    public const SOURCE = 'currencies';

    /** @var array<string, array{numeric: string, exponent: int, subunit_divisor: int}>|null */
    private static ?array $table = null;

    /**
     * @return array<string, array{numeric: string, exponent: int, subunit_divisor: int}>
     *
     * @throws RegistryDataUnusable
     */
    public static function all(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $table = [];

        foreach (VendoredRegistry::rows(self::SOURCE) as $row) {
            foreach (['code', 'numeric', 'exponent', 'subunit_divisor'] as $column) {
                if (! isset($row[$column])) {
                    throw RegistryDataUnusable::column(self::SOURCE, $column);
                }
            }

            $code = $row['code'];

            /*
             | Validated at load, not trusted.
             |
             | An exponent of "" casts to 0 and an exponent of "two" casts to
             | 0 as well, and 0 is a legitimate value - JPY has it. So a bad
             | row would not look bad: it would make a currency render with no
             | decimal places at all, on every document, until a customer
             | asked why their invoice said 1,235 instead of 1,234.57.
             */
            if (preg_match('/\A[A-Z]{3}\z/', $code) !== 1) {
                throw RegistryDataUnusable::value(self::SOURCE, $code, 'code', $code);
            }

            if (preg_match('/\A[0-9]{3}\z/', $row['numeric']) !== 1) {
                throw RegistryDataUnusable::value(self::SOURCE, $code, 'numeric code', $row['numeric']);
            }

            if (preg_match('/\A[0-4]\z/', $row['exponent']) !== 1) {
                throw RegistryDataUnusable::value(self::SOURCE, $code, 'exponent', $row['exponent']);
            }

            if (preg_match('/\A[0-9]{1,5}\z/', $row['subunit_divisor']) !== 1) {
                throw RegistryDataUnusable::value(
                    self::SOURCE, $code, 'subunit divisor', $row['subunit_divisor'],
                );
            }

            $table[$code] = [
                'numeric' => $row['numeric'],
                'exponent' => (int) $row['exponent'],
                'subunit_divisor' => (int) $row['subunit_divisor'],
            ];
        }

        return self::$table = $table;
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[strtoupper($code)]);
    }

    public static function assertExists(string $code): string
    {
        $code = strtoupper($code);

        if (! self::exists($code)) {
            throw UnknownCurrency::code($code);
        }

        return $code;
    }

    /**
     * The number of decimal places the currency is legally expressed in.
     *
     * This is the PRESENTATION exponent. It is not the storage scale: amounts
     * are stored at {@see \App\Support\Money\Scale::Amount} (4 places) and
     * rounded to this only for display and for payment. Storing the display
     * value is how a ledger stops balancing.
     */
    public static function exponent(string $code): int
    {
        return self::all()[self::assertExists($code)]['exponent'];
    }

    public static function numeric(string $code): string
    {
        return self::all()[self::assertExists($code)]['numeric'];
    }

    /**
     * MGA and MRU divide into five, not into tenths or hundredths.
     *
     * Their ISO exponent is 2 by convention, but the subunit is 1/5, so a
     * "minor unit" integer is meaningless for them. Anything that formats or
     * accepts a subunit amount has to ask.
     */
    public static function isBase5(string $code): bool
    {
        return self::subunitDivisor($code) === 5;
    }

    /**
     * How many subunits make one unit of the currency.
     *
     * 10^exponent for every currency but two, and the two are the reason this
     * is a column in the file rather than a calculation: MGA and MRU carry an
     * ISO exponent of 2 by convention while their subunit is a fifth, so
     * `10 ** exponent` is wrong for them and wrong in a way no test of the
     * other 172 currencies would show.
     */
    public static function subunitDivisor(string $code): int
    {
        return self::all()[self::assertExists($code)]['subunit_divisor'];
    }

    /** True where the currency has no subunit at all - JPY, KRW, XAF and the rest. */
    public static function hasNoSubunit(string $code): bool
    {
        return self::exponent($code) === 0;
    }

    public static function name(string $code, ?string $locale = null): string
    {
        $code = self::assertExists($code);

        // ICU exposes the localized currency name through the locale display
        // API, keyed by a `@currency=` keyword on the locale.
        $display = \Locale::getDisplayName('@currency='.$code, $locale ?? self::locale());

        return is_string($display) && $display !== '' ? $display : $code;
    }

    public static function symbol(string $code, ?string $locale = null): string
    {
        $code = self::assertExists($code);

        $formatter = new NumberFormatter($locale ?? self::locale(), NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $code);

        $symbol = $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return is_string($symbol) && $symbol !== '' ? $symbol : $code;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /** Every currency whose exponent is not 2 - the ones that break naive code. */
    /** @return array<string, int> */
    public static function unusualExponents(): array
    {
        $unusual = [];
        foreach (self::all() as $code => $meta) {
            if ($meta['exponent'] !== 2 || $meta['subunit_divisor'] !== 100) {
                $unusual[$code] = $meta['exponent'];
            }
        }

        return $unusual;
    }

    private static function locale(): string
    {
        return function_exists('app') && app()->bound('translator')
            ? app()->getLocale()
            : 'en';
    }
}
