<?php

declare(strict_types=1);

namespace App\Support\Iso;

use App\Support\Iso\Exceptions\UnknownCurrency;
use NumberFormatter;

/**
 * ISO 4217 currencies, and - the part that actually matters - their minor units.
 *
 * Provenance: ISO 4217 Table A.1 (active codes), as published by SIX Financial
 * Information on behalf of the ISO 4217 Maintenance Agency. Vendored as a
 * packed table rather than fetched at runtime.
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
    /**
     * Packed as `CODE:numeric[:exponent[:base5]]`. Exponent defaults to 2, so
     * only the unusual ones carry it - which makes them visible in a diff.
     */
    private const PACKED =
        'AED:784|AFN:971|ALL:008|AMD:051|ANG:532|AOA:973|ARS:032|AUD:036|AWG:533|AZN:944|'.
        'BAM:977|BBD:052|BDT:050|BGN:975|BHD:048:3|BIF:108:0|BMD:060|BND:096|BOB:068|BOV:984|'.
        'BRL:986|BSD:044|BTN:064|BWP:072|BYN:933|BZD:084|CAD:124|CDF:976|CHE:947|CHF:756|'.
        'CHW:948|CLF:990:4|CLP:152:0|CNY:156|COP:170|COU:970|CRC:188|CUP:192|CVE:132|CZK:203|'.
        'DJF:262:0|DKK:208|DOP:214|DZD:012|EGP:818|ERN:232|ETB:230|EUR:978|FJD:242|FKP:238|'.
        'GBP:826|GEL:981|GHS:936|GIP:292|GMD:270|GNF:324:0|GTQ:320|GYD:328|HKD:344|HNL:340|'.
        'HTG:332|HUF:348|IDR:360|ILS:376|INR:356|IQD:368:3|IRR:364|ISK:352:0|JMD:388|JOD:400:3|'.
        'JPY:392:0|KES:404|KGS:417|KHR:116|KMF:174:0|KPW:408|KRW:410:0|KWD:414:3|KYD:136|KZT:398|'.
        'LAK:418|LBP:422|LKR:144|LRD:430|LSL:426|LYD:434:3|MAD:504|MDL:498|MGA:969:2:1|MKD:807|'.
        'MMK:104|MNT:496|MOP:446|MRU:929:2:1|MUR:480|MVR:462|MWK:454|MXN:484|MXV:979|MYR:458|'.
        'MZN:943|NAD:516|NGN:566|NIO:558|NOK:578|NPR:524|NZD:554|OMR:512:3|PAB:590|PEN:604|'.
        'PGK:598|PHP:608|PKR:586|PLN:985|PYG:600:0|QAR:634|RON:946|RSD:941|RUB:643|RWF:646:0|'.
        'SAR:682|SBD:090|SCR:690|SDG:938|SEK:752|SGD:702|SHP:654|SLE:925|SOS:706|SRD:968|'.
        'SSP:728|STN:930|SVC:222|SYP:760|SZL:748|THB:764|TJS:972|TMT:934|TND:788:3|TOP:776|'.
        'TRY:949|TTD:780|TWD:901|TZS:834|UAH:980|UGX:800:0|USD:840|USN:997|UYI:940:0|UYU:858|'.
        'UYW:927:4|UZS:860|VED:926|VES:928|VND:704:0|VUV:548:0|WST:882|XAF:950:0|XCD:951|'.
        'XCG:532|XDR:960:0|XOF:952:0|XPF:953:0|XSU:994:0|XUA:965:0|YER:886|ZAR:710|ZMW:967|'.
        'ZWG:924|XAU:959:0|XAG:961:0|XPT:962:0|XPD:964:0|XXX:999:0';

    /** @var array<string, array{numeric: string, exponent: int, base5: bool}>|null */
    private static ?array $table = null;

    /** @return array<string, array{numeric: string, exponent: int, base5: bool}> */
    public static function all(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $table = [];
        foreach (explode('|', self::PACKED) as $row) {
            $parts = explode(':', $row);

            $table[$parts[0]] = [
                'numeric' => $parts[1],
                'exponent' => isset($parts[2]) ? (int) $parts[2] : 2,
                'base5' => isset($parts[3]),
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
        return self::all()[self::assertExists($code)]['base5'];
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
            if ($meta['exponent'] !== 2 || $meta['base5']) {
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
