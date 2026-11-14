<?php

declare(strict_types=1);

namespace App\Support\Iso;

/**
 * Units of measure, UN/CEFACT Recommendation 20 (with Rec 21 extensions).
 *
 * This is the code list EN 16931 `BT-130` requires, so the code - not the
 * label - is what goes on an invoice and into the ledger. A unit stored as the
 * word "kilogram" cannot be serialized to UBL, and a unit stored as a tenant's
 * free text cannot be validated at all.
 *
 * Provenance: UN/CEFACT Recommendation 20, Annex I (common codes), and
 * Recommendation 21 Annex V for the `X`-prefixed package codes. Only the units
 * PeakFlow actually bills in are listed - adding one is a deliberate change
 * with a reviewed code, not an open text field.
 *
 * Labels are bilingual and translated through the catalogs rather than stored
 * here, so a unit reads correctly on an Arabic invoice.
 */
enum UnitOfMeasure: string
{
    // Counting
    case Piece = 'H87';
    case Unit = 'C62';
    case Each = 'EA';
    case Pair = 'PR';
    case Dozen = 'DZN';
    case Set = 'SET';
    case ServiceUnit = 'E48';

    // Mass
    case Gram = 'GRM';
    case Kilogram = 'KGM';
    case Tonne = 'TNE';

    // Volume
    case Millilitre = 'MLT';
    case Litre = 'LTR';
    case CubicMetre = 'MTQ';

    // Length and area
    case Millimetre = 'MMT';
    case Centimetre = 'CMT';
    case Metre = 'MTR';
    case Kilometre = 'KMT';
    case SquareMetre = 'MTK';

    // Time - what a service business actually sells
    case Second = 'SEC';
    case Minute = 'MIN';
    case Hour = 'HUR';
    case Day = 'DAY';
    case Week = 'WEE';
    case Month = 'MON';
    case Year = 'ANN';

    // Energy and telecoms
    case KilowattHour = 'KWH';
    case Megabyte = '4L';
    case Gigabyte = 'E34';

    // Packaging
    case Box = 'XBX';
    case Carton = 'XCT';
    case Pallet = 'XPX';
    case Roll = 'XRO';

    // Proportion
    case Percent = 'P1';

    /**
     * The translated label. Bilingual by going through the catalogs, which is
     * also what makes the missing-translation test cover units.
     */
    public function label(): string
    {
        return match ($this) {
            self::Piece, self::Unit, self::Each => __('حبة'),
            self::Pair => __('زوج'),
            self::Dozen => __('دزينة'),
            self::Set => __('طقم'),
            self::ServiceUnit => __('وحدة خدمة'),
            self::Gram => __('غرام'),
            self::Kilogram => __('كيلوغرام'),
            self::Tonne => __('طن'),
            self::Millilitre => __('مليلتر'),
            self::Litre => __('لتر'),
            self::CubicMetre => __('متر مكعب'),
            self::Millimetre => __('مليمتر'),
            self::Centimetre => __('سنتيمتر'),
            self::Metre => __('متر'),
            self::Kilometre => __('كيلومتر'),
            self::SquareMetre => __('متر مربع'),
            self::Second => __('ثانية'),
            self::Minute => __('دقيقة'),
            self::Hour => __('ساعة'),
            self::Day => __('يوم'),
            self::Week => __('أسبوع'),
            self::Month => __('شهر'),
            self::Year => __('سنة'),
            self::KilowattHour => __('كيلوواط/ساعة'),
            self::Megabyte => __('ميغابايت'),
            self::Gigabyte => __('غيغابايت'),
            self::Box => __('صندوق'),
            self::Carton => __('كرتون'),
            self::Pallet => __('منصة'),
            self::Roll => __('لفة'),
            self::Percent => __('نسبة مئوية'),
        };
    }

    /**
     * Whether a fractional quantity is meaningful.
     *
     * You cannot sell 1.5 of a discrete thing, and a UI that lets someone try
     * produces an invoice line nobody can fulfil. This drives the quantity
     * input's `step` and the decimal places shown.
     */
    public function allowsFractionalQuantity(): bool
    {
        return ! in_array($this, [
            self::Piece, self::Unit, self::Each, self::Pair,
            self::Dozen, self::Set, self::Box, self::Carton,
            self::Pallet, self::Roll,
        ], true);
    }

    public function dimension(): UnitDimension
    {
        return match ($this) {
            self::Piece, self::Unit, self::Each, self::Pair,
            self::Dozen, self::Set, self::ServiceUnit,
            self::Box, self::Carton, self::Pallet, self::Roll => UnitDimension::Count,

            self::Gram, self::Kilogram, self::Tonne => UnitDimension::Mass,
            self::Millilitre, self::Litre, self::CubicMetre => UnitDimension::Volume,

            self::Millimetre, self::Centimetre,
            self::Metre, self::Kilometre => UnitDimension::Length,

            self::SquareMetre => UnitDimension::Area,

            self::Second, self::Minute, self::Hour,
            self::Day, self::Week, self::Month, self::Year => UnitDimension::Time,

            self::KilowattHour => UnitDimension::Energy,
            self::Megabyte, self::Gigabyte => UnitDimension::Data,
            self::Percent => UnitDimension::Proportion,
        };
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function isKnown(string $code): bool
    {
        return self::tryFrom(strtoupper($code)) !== null;
    }
}
