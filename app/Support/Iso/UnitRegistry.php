<?php

declare(strict_types=1);

namespace App\Support\Iso;

use App\Support\Iso\Exceptions\UnknownUnit;

/**
 * UN/CEFACT Recommendation 20 units of measure.
 *
 * ## Why a code and not a word
 *
 * An e-invoice carries `HUR`, not "hour". EN 16931 makes the unit code
 * mandatory (BT-130) and validates it against Rec 20, so an invoice that says
 * "hours" is rejected by the receiving system - not disputed, rejected, with
 * a schema error a salesperson cannot act on.
 *
 * The code also settles arguments the word does not: `C62` is the dimensionless
 * "one", which is what a line charging for five licences actually uses, and
 * `EA` - the obvious guess - is not a Rec 20 code at all.
 *
 * ## A curated subset, and why that is not a compromise
 *
 * Rec 20 has over 1,500 codes, most of them for commodities nobody invoices
 * through accounting software: `HTZ` hertz, `MTQ` cubic metre, `B45` kilobar.
 * Vendoring all of them makes a dropdown unusable and a validation message
 * meaningless.
 *
 * The ones here are the units that appear on invoices for services, software,
 * utilities, logistics and ordinary goods. An unlisted code is refused with
 * its own message, so adding one is a visible decision in a diff rather than
 * a silent widening.
 *
 * @see https://unece.org/trade/uncefact/cl-recommendations
 */
final class UnitRegistry
{
    /**
     * Packed as `CODE:en:ar`, grouped by what they measure.
     *
     * The names are here rather than in a translation file because a unit
     * code's meaning is fixed by the standard - "HUR is an hour" is not a
     * localisation decision - while the SYMBOL a locale shows for it is, and
     * that stays in the view layer.
     */
    private const PACKED =
        // Count and dimensionless
        'C62:unit:وحدة|'.
        'EA:each:لكل|'.  // Deliberately listed: see the note on EA below.
        'NPR:pair:زوج|'.
        'SET:set:طقم|'.
        'DZN:dozen:دسته|'.
        'BX:box:صندوق|'.
        'CT:carton:كرتون|'.
        'PK:pack:عبوة|'.
        'PF:pallet:منصة|'.
        'RO:roll:لفة|'.
        'BG:bag:كيس|'.
        'BO:bottle:زجاجة|'.
        'CS:case:حافظة|'.
        'DR:drum:برميل|'.
        'TU:tube:أنبوب|'.
        // Time
        'SEC:second:ثانية|'.
        'MIN:minute:دقيقة|'.
        'HUR:hour:ساعة|'.
        'DAY:day:يوم|'.
        'WEE:week:أسبوع|'.
        'MON:month:شهر|'.
        'ANN:year:سنة|'.
        // Mass
        'GRM:gram:غرام|'.
        'KGM:kilogram:كيلوغرام|'.
        'TNE:tonne:طن|'.
        'LBR:pound:رطل|'.
        'ONZ:ounce:أونصة|'.
        // Length
        'MMT:millimetre:مليمتر|'.
        'CMT:centimetre:سنتيمتر|'.
        'MTR:metre:متر|'.
        'KMT:kilometre:كيلومتر|'.
        'INH:inch:بوصة|'.
        'FOT:foot:قدم|'.
        // Area and volume
        'CMK:square centimetre:سنتيمتر مربع|'.
        'MTK:square metre:متر مربع|'.
        'MLT:millilitre:مليلتر|'.
        'LTR:litre:لتر|'.
        'MTQ:cubic metre:متر مكعب|'.
        'GLL:gallon:غالون|'.
        // Energy and data, which is where software and utilities invoice
        'WHR:watt hour:واط ساعة|'.
        'KWH:kilowatt hour:كيلوواط ساعة|'.
        'MWH:megawatt hour:ميغاواط ساعة|'.
        'E34:gigabyte:غيغابايت|'.
        'E35:terabyte:تيرابايت|'.
        '4L:megabyte:ميغابايت|'.
        // Distance-weight, for freight
        'TKM:tonne kilometre:طن كيلومتر|'.
        'KTM:kilometre per hour:كيلومتر في الساعة|'.
        // Percentage and currency-like
        'P1:percent:نسبة مئوية';

    /** @var array<string, array{en: string, ar: string}>|null */
    private static ?array $table = null;

    /** @return array<string, array{en: string, ar: string}> */
    public static function all(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $table = [];

        foreach (explode('|', self::PACKED) as $row) {
            [$code, $en, $ar] = explode(':', $row);
            $table[$code] = ['en' => $en, 'ar' => $ar];
        }

        return self::$table = $table;
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[strtoupper(trim($code))]);
    }

    /**
     * @throws UnknownUnit
     */
    public static function assertExists(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! self::exists($code)) {
            throw UnknownUnit::code($code);
        }

        return $code;
    }

    public static function name(string $code, string $locale = 'en'): string
    {
        $unit = self::all()[strtoupper(trim($code))] ?? null;

        if ($unit === null) {
            return $code;
        }

        return str_starts_with($locale, 'ar') ? $unit['ar'] : $unit['en'];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /**
     * The unit to use when a line is simply a count of things.
     *
     * `C62`, the dimensionless "one". Not `EA`: `EA` is in the list because
     * it is in Rec 20 and in widespread use, and it means "each" in the sense
     * of a retail each-pack - several national profiles reject it on a
     * business-to-business invoice where `C62` is expected. Defaulting to the
     * one that is never rejected costs nothing.
     */
    public static function countable(): string
    {
        return 'C62';
    }
}
