<?php

declare(strict_types=1);

namespace App\Support\Iso;

use App\Support\Iso\Data\VendoredRegistry;
use App\Support\Iso\Exceptions\RegistryDataUnusable;
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
 * Provenance: `database/iso/units.tsv`, recorded in `database/iso/provenance.json`
 * - and recorded with a caveat the other registries do not need. ICU knows every
 * ISO 4217 and ISO 3166 code, so those two files have a second source inside the
 * process; Rec 20 has none, so these codes were transcribed and are cross-checked
 * by nothing. The manifest says so rather than implying a verification that did
 * not happen.
 *
 * @see https://unece.org/trade/uncefact/cl-recommendations
 */
final class UnitRegistry
{
    /** The vendored file this registry reads. */
    public const SOURCE = 'units';

    /**
     * What the quantity column can hold - PF-247.
     *
     * A unit may declare fewer places and most do; none may declare more,
     * because a precision the database cannot store is a precision that is
     * silently rounded on the way in. The refusal belongs at load time,
     * where somebody is editing the file, rather than at the first invoice
     * that uses the unit.
     */
    public const MAX_DECIMALS = 6;

    /** @var array<string, array{dimension: UnitDimension, en: string, ar: string}>|null */
    private static ?array $table = null;

    /**
     * @return array<string, array{dimension: UnitDimension, en: string, ar: string}>
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
            foreach (['code', 'dimension', 'decimals', 'en', 'ar'] as $column) {
                if (! isset($row[$column])) {
                    throw RegistryDataUnusable::column(self::SOURCE, $column);
                }
            }

            $dimension = UnitDimension::tryFrom($row['dimension']);

            /*
             | An unknown dimension is refused rather than defaulted.
             |
             | The dimension exists so an addition between incompatible units
             | can be refused; a unit that fell back to a default dimension
             | would be comparable with things it has no business being
             | compared with, which is the one failure this column prevents.
             */
            if ($dimension === null) {
                throw RegistryDataUnusable::value(self::SOURCE, $row['code'], 'dimension', $row['dimension']);
            }

            if ($row['en'] === '' || $row['ar'] === '') {
                throw RegistryDataUnusable::value(self::SOURCE, $row['code'], 'name', $row['en'].'/'.$row['ar']);
            }

            /*
             | A precision that is not a number is refused, not defaulted.
             |
             | Same reasoning as the dimension above: a unit that fell back
             | to "three places" would accept 2.5 pairs, and a line reading
             | 2.5 NPR is a line somebody typed a gram quantity into. The
             | point of declaring it per unit is that it is declared.
             */
            if (! ctype_digit((string) $row['decimals'])) {
                throw RegistryDataUnusable::value(self::SOURCE, $row['code'], 'decimals', (string) $row['decimals']);
            }

            $decimals = (int) $row['decimals'];

            if ($decimals > self::MAX_DECIMALS) {
                throw RegistryDataUnusable::value(
                    self::SOURCE,
                    $row['code'],
                    'decimals',
                    $row['decimals'].' (the quantity column holds '.self::MAX_DECIMALS.')',
                );
            }

            $table[$row['code']] = [
                'dimension' => $dimension,
                'decimals' => $decimals,
                'en' => $row['en'],
                'ar' => $row['ar'],
            ];
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
     * What the unit measures.
     *
     * The enum existed before anything mapped a code to it, which made it a
     * declaration of intent rather than a control. Now a unit has one, is
     * refused at load if it does not, and {@see comparable()} can answer the
     * question the enum was written for.
     */
    /**
     * How many decimal places a quantity in this unit may carry - PF-247.
     *
     * Declared on the unit rather than assumed from its dimension: a
     * minute and an hour are both time, and a quarter of an hour is an
     * ordinary invoice line while half a minute is not a thing anybody
     * bills. Counting units are 0 - there is no half of a pair.
     */
    public static function decimals(string $code): int
    {
        $code = self::assertExists($code);

        return (int) self::all()[$code]['decimals'];
    }

    /**
     * Whether a quantity is expressible in this unit.
     *
     * The question a document line asks before it accepts a number, and
     * the reason the precision is data rather than a constant: "2.5" is a
     * fine quantity of kilograms and a nonsense quantity of pairs, and
     * nothing about the number itself says which.
     */
    public static function permits(string $code, string $quantity): bool
    {
        $allowed = self::decimals($code);
        $point = strpos($quantity, '.');

        if ($point === false) {
            return true;
        }

        $places = rtrim(substr($quantity, $point + 1), '0');

        return \strlen($places) <= $allowed;
    }

    public static function dimension(string $code): UnitDimension
    {
        return self::all()[self::assertExists($code)]['dimension'];
    }

    /**
     * May these two units be added, compared or converted?
     *
     * Three hours plus two kilograms is not a rounding problem, and the place
     * it shows up is a credit note whose unit does not match the invoice line
     * it credits - where the quantities subtract to a number that means
     * nothing and still prints.
     */
    public static function comparable(string $first, string $second): bool
    {
        return self::dimension($first) === self::dimension($second);
    }

    /** @return list<string> */
    public static function ofDimension(UnitDimension $dimension): array
    {
        $codes = [];

        foreach (self::all() as $code => $unit) {
            if ($unit['dimension'] === $dimension) {
                $codes[] = $code;
            }
        }

        return $codes;
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
