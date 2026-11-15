<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Enums;

/**
 * UNCL 5305 tax categories - PF-075.
 *
 * ## Why these are an enum and not configuration
 *
 * `config/tax.php` opens by saying tax is data rather than code, and that is
 * right about rates, boxes, place-of-supply rules and rounding profiles. It is
 * wrong about these. A category's meaning is fixed by a published code list:
 * `Z` is zero-rated in every jurisdiction that uses the list, and `E` is
 * exempt in all of them.
 *
 * Putting them in configuration makes them a thing an operator can change, and
 * the change that gets made is "exempt supplies are input-recoverable" -
 * because somebody is reconciling an input tax box that will not balance and
 * that flag is the fastest way to make the number move. The return is then
 * wrong in the input tax boxes, which is the half nobody checks until an audit.
 *
 * So the code list is code, the rates and boxes are rows, and the two meet in
 * `tax_codes`.
 *
 * ## The three that are routinely collapsed
 *
 *   Z  zero-rated   A TAXABLE supply at 0%. Input tax IS recoverable.
 *   E  exempt       NOT a taxable supply. Input recovery is RESTRICTED.
 *   O  out of scope Outside the regime entirely. Not on the return at all.
 *
 * Modelling any of them as "0% with a note" produces a return whose output tax
 * is right and whose input tax and turnover boxes are wrong, which is the
 * shape of error that survives every check a business does on itself.
 *
 * @see UNCL 5305 (UN/EDIFACT code list D.23A), EN 16931 BT-118
 */
enum TaxCategory: string
{
    /** Standard rate. The ordinary case. */
    case Standard = 'S';

    /** Zero-rated: a taxable supply at 0%, with input recovery intact. */
    case ZeroRated = 'Z';

    /** Exempt: not a taxable supply, and input recovery is restricted. */
    case Exempt = 'E';

    /** Reverse charge: the RECIPIENT accounts for the tax, on both sides. */
    case ReverseCharge = 'AE';

    /** Out of scope: outside the regime, and not on the return. */
    case OutOfScope = 'O';

    /** Intra-community supply (EU). Needs both parties' registrations. */
    case IntraCommunity = 'K';

    /** Export of goods. Needs evidence that the goods left. */
    case Export = 'G';

    /**
     * Canary Islands general indirect tax (IGIC).
     *
     * In the list because EN 16931 requires it and a Spanish seller with
     * Canary Islands customers cannot issue a conformant invoice without it.
     * It is a separate regime from mainland Spanish VAT, not a rate of it,
     * which is why it is a category rather than a code.
     */
    case CanaryIslands = 'L';

    /** Ceuta and Melilla (IPSI). Same reasoning as `L`. */
    case CeutaMelilla = 'M';

    /**
     * Is this a taxable supply?
     *
     * The question the TURNOVER boxes ask, and the one `Z` and `E` differ on:
     * a zero-rated supply counts towards taxable turnover at 0%, an exempt one
     * does not count at all. Registration thresholds are computed from taxable
     * turnover, so collapsing the two can make a business appear not to need a
     * registration it does need.
     */
    public function isTaxableSupply(): bool
    {
        return match ($this) {
            self::Standard, self::ZeroRated, self::ReverseCharge,
            self::IntraCommunity, self::Export,
            self::CanaryIslands, self::CeutaMelilla => true,
            self::Exempt, self::OutOfScope => false,
        };
    }

    /**
     * Does making this supply restrict recovery of input tax?
     *
     * Deliberately NOT a boolean called `input_recoverable`. The degree of
     * restriction is a jurisdiction rule - partial exemption methods differ,
     * and some regimes allow recovery attributable to exempt supplies below a
     * de minimis - so a flag saying "recoverable: false" would be a lie in
     * every regime that has a method.
     *
     * What IS universal is which categories trigger the question at all, and
     * that is what this answers. The apportionment itself belongs to PF-084,
     * where a non-recoverable portion posts to expense rather than to the
     * input account - which is what makes the input account reconcilable to
     * the return.
     */
    public function restrictsInputRecovery(): bool
    {
        return match ($this) {
            self::Exempt, self::OutOfScope => true,
            default => false,
        };
    }

    /**
     * Must the document carry an exemption reason? EN 16931 `BT-120`/`BT-121`.
     *
     * Every category but the standard rate. The reason is what an auditor
     * reads to decide whether the treatment was right, and an invoice that
     * says 0% with no reason is an invoice that cannot be defended - so the
     * requirement is on the document rather than on the user's diligence.
     *
     * `BT-121` is the coded reason and `BT-120` the text; a jurisdiction
     * profile decides which it requires, and PF-080 enforces the evidence
     * behind it.
     */
    public function requiresExemptionReason(): bool
    {
        return $this !== self::Standard;
    }

    /**
     * Does the recipient account for the tax instead of the supplier?
     *
     * `AE` only. `K` is an intra-community supply, which is zero-rated to the
     * supplier and self-assessed by the acquirer - a similar outcome reached
     * by a different mechanism, and the returns report them in different
     * boxes. Treating them as the same thing is how an EC Sales List comes out
     * empty.
     */
    public function isReverseCharge(): bool
    {
        return $this === self::ReverseCharge;
    }

    /**
     * Does this category need documentary evidence beyond a reason code?
     *
     * An export needs proof the goods left the country; an intra-community
     * supply needs the acquirer's validated registration. A reason code is a
     * claim, and these two are the categories where the authority asks to see
     * the thing itself.
     */
    public function requiresEvidence(): bool
    {
        return match ($this) {
            self::Export, self::IntraCommunity => true,
            default => false,
        };
    }

    /**
     * Must the rate be zero for this category?
     *
     * The invariant that stops the three collapsed categories being
     * un-collapsed wrongly. A `Z` code at 5% is not a zero rate, an `E` code
     * at 5% is a taxable supply wearing an exempt label, and both are refused
     * by a database constraint rather than by a form.
     */
    public function requiresZeroRate(): bool
    {
        return match ($this) {
            self::Standard, self::CanaryIslands, self::CeutaMelilla => false,
            default => true,
        };
    }

    /** The bilingual label, for a dropdown and an invoice's tax panel. */
    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::Standard => $arabic ? 'النسبة الأساسية' : 'Standard rate',
            self::ZeroRated => $arabic ? 'نسبة صفرية' : 'Zero-rated',
            self::Exempt => $arabic ? 'معفى' : 'Exempt',
            self::ReverseCharge => $arabic ? 'الاحتساب العكسي' : 'Reverse charge',
            self::OutOfScope => $arabic ? 'خارج النطاق' : 'Out of scope',
            self::IntraCommunity => $arabic ? 'توريد داخل الاتحاد' : 'Intra-community supply',
            self::Export => $arabic ? 'تصدير' : 'Export',
            self::CanaryIslands => $arabic ? 'جزر الكناري (IGIC)' : 'Canary Islands (IGIC)',
            self::CeutaMelilla => $arabic ? 'سبتة ومليلية (IPSI)' : 'Ceuta and Melilla (IPSI)',
        };
    }

    /**
     * The categories a jurisdiction profile may use.
     *
     * `L` and `M` are Spanish-only regimes and offering them to a Saudi seller
     * is a dropdown entry that can only ever be a mistake. The profile decides,
     * which is the same reason rates are rows.
     *
     * @return list<self>
     */
    public static function forProfile(string $profile): array
    {
        $universal = [
            self::Standard, self::ZeroRated, self::Exempt,
            self::ReverseCharge, self::OutOfScope, self::Export,
        ];

        return match ($profile) {
            'peppol', 'facturx' => array_merge($universal, [
                self::IntraCommunity, self::CanaryIslands, self::CeutaMelilla,
            ]),
            default => $universal,
        };
    }
}
