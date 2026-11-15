<?php

declare(strict_types=1);

namespace App\Support\Iso\Domestic;

use App\Support\Iso\Exceptions\InvalidDomesticIdentifier;
use Stringable;

/**
 * Which domestic identifier a country's banks are addressed by - PF-008.
 *
 * ## Why this exists rather than a nullable IBAN field
 *
 * Most of the world outside the IBAN area still has to be paid, and the form
 * that asks for an IBAN and falls back to a free-text "account number" field
 * has given up: a US routing number typed into free text is nine digits that
 * nobody checked, and the ABA checksum that would have caught the transposition
 * never ran.
 *
 * So a country either has an IBAN, or it has a scheme here, or PeakFlow admits
 * it does not know how to validate that country's identifiers and says so
 * rather than pretending a regular expression is a check.
 *
 * The four implemented are the ones that cover the non-IBAN countries PeakFlow
 * is actually used to pay into. Great Britain is listed although it HAS an
 * IBAN: domestic UK payments are addressed by sort code and account number,
 * and an invoice to a UK customer carries both.
 */
enum DomesticScheme: string
{
    case UkSortCode = 'uk_sort_code';
    case UsRouting = 'us_routing';
    case AuBsb = 'au_bsb';
    case InIfsc = 'in_ifsc';

    /**
     * The scheme a country's domestic payments use, where one is implemented.
     *
     * Four entries and not a data file: this is a list of what PeakFlow has
     * code for, which is a fact about the code and not about the world. A
     * country arrives here in the same commit as its value object.
     */
    public static function forCountry(string $alpha2): ?self
    {
        return match (strtoupper($alpha2)) {
            'GB' => self::UkSortCode,
            'US' => self::UsRouting,
            'AU' => self::AuBsb,
            'IN' => self::InIfsc,
            default => null,
        };
    }

    /** @return list<string> */
    public static function countries(): array
    {
        return ['AU', 'GB', 'IN', 'US'];
    }

    public function label(string $locale = 'en'): string
    {
        return str_starts_with($locale, 'ar')
            ? match ($this) {
                self::UkSortCode => 'رمز الفرز البريطاني',
                self::UsRouting => 'رقم التوجيه الأمريكي',
                self::AuBsb => 'رمز BSB الأسترالي',
                self::InIfsc => 'رمز IFSC الهندي',
            }
            : match ($this) {
                self::UkSortCode => 'UK sort code',
                self::UsRouting => 'US routing number',
                self::AuBsb => 'BSB',
                self::InIfsc => 'IFSC',
            };
    }

    /**
     * Does this scheme carry a check that can detect a typed-in error?
     *
     * Asked rather than assumed, because the answer changes what a form should
     * do. Only the ABA number has one. For the other three, a confirmation
     * step - read it back, or ask for it twice - is the only defence against a
     * transposed digit, and a form that does not know that will not offer one.
     */
    public function hasCheckDigit(): bool
    {
        return $this === self::UsRouting;
    }

    /**
     * Build the matching value object, or refuse.
     *
     * @throws InvalidDomesticIdentifier
     */
    public function parse(string $value): Stringable
    {
        return match ($this) {
            self::UkSortCode => new UkSortCode($value),
            self::UsRouting => new UsRoutingNumber($value),
            self::AuBsb => new AuBsb($value),
            self::InIfsc => new IndiaIfsc($value),
        };
    }

    public function tryParse(string $value): ?Stringable
    {
        try {
            return $this->parse($value);
        } catch (InvalidDomesticIdentifier) {
            return null;
        }
    }
}
